<template>
  <view class="page tm-page-shell">
    <ModulePlaceholder v-if="modules.resolved && !moduleEnabled" code="module_after_sale" />
    <view v-else-if="!modules.resolved" class="mp-skeleton">
      <view class="tm-skeleton" style="height: 200rpx; border-radius: 24rpx; margin: 24rpx 28rpx;" />
      <view class="tm-skeleton" style="height: 320rpx; border-radius: 24rpx; margin: 20rpx 28rpx;" />
    </view>
    <template v-else>
    <view v-if="loading" class="state-card">
      <view class="state-spinner tm-skeleton" />
      <text class="state-title">正在准备售后申请</text>
      <text class="state-copy">正在校验订单项、可申请数量和售后规则。</text>
    </view>
    <view v-else-if="loadError" class="state-card error-card">
      <text class="state-title">售后申请暂不可用</text>
      <text class="state-copy">{{ loadError }}</text>
      <button class="retry-main" :loading="loading" :disabled="loading" @click="load">重新加载</button>
    </view>

    <template v-if="!loading && !loadError && item">
      <!-- 商品摘要 -->
      <view class="card item-card">
        <view class="item-mark"><text>{{ item.product_name.slice(0, 1) }}</text></view>
        <view class="item-main">
          <text class="item-name">{{ item.product_name }}</text>
          <text class="item-meta">{{ item.sku_name }} · 订单 {{ order?.order_no || '—' }}</text>
          <text class="item-money">{{ refundPreview }}</text>
        </view>
      </view>

      <!-- 申请表单 -->
      <view class="card form-card">
        <view class="field">
          <text class="label">售后类型</text>
          <view class="type-tabs">
            <view
              v-for="option in availableTypes"
              :key="option.value"
              class="type-tab"
              :class="{ active: serviceType === option.value }"
              @click="changeType(option.value)"
            >{{ option.label }}</view>
          </view>
        </view>

        <view class="field">
          <text class="label">申请数量</text>
          <view class="quantity-row">
            <view class="quantity">
              <button class="qty-btn" :disabled="quantity <= 1" @click="quantity = Math.max(1, quantity - 1)">−</button>
              <text class="qty-num">{{ quantity }}</text>
              <button class="qty-btn" :disabled="quantity >= maxQuantity" @click="quantity = Math.min(maxQuantity, quantity + 1)">＋</button>
            </view>
            <text class="quantity-note">{{ serviceType === 'exchange' ? '剩余可换' : '剩余可退' }} {{ maxQuantity }} 件 · 已处理 {{ item.refunded_quantity || 0 }} 件</text>
          </view>
        </view>

        <view class="field reason-field">
          <text class="label">申请原因</text>
          <picker v-if="!reasonError" :range="reasonLabels" :disabled="reasonsLoading || !reasons.length" @change="selectReason">
            <view class="picker-value" :class="{ placeholder: !selectedReason }">
              <text>{{ reasonsLoading ? '原因加载中…' : selectedReason?.name || (reasons.length ? '请选择申请原因 ›' : '暂无可用原因') }}</text>
            </view>
          </picker>
          <view v-else class="reason-error">
            <text class="re-title">退换原因加载失败</text>
            <text class="re-detail">{{ reasonError }}</text>
            <button class="retry-button" :loading="reasonsLoading" :disabled="reasonsLoading" @click="retryReasons">重新加载</button>
          </view>
        </view>

        <view v-if="selectedReason" class="responsibility">
          <view class="resp-item"><text>责任预判</text><strong>{{ partyText(selectedReason.default_responsibility) }}</strong></view>
          <view class="resp-item"><text>退货运费</text><strong>{{ payerText(selectedReason.return_shipping_payer) }}</strong></view>
          <view class="resp-item"><text>凭证要求</text><strong>{{ selectedReason.evidence_required ? '必须上传' : '选填' }}</strong></view>
        </view>

        <view class="field">
          <text class="label">补充描述<text class="label-sub">（选填）</text></text>
          <textarea v-model="description" class="desc-input" maxlength="500" placeholder="补充描述问题情况，有助于加快审核" placeholder-class="ph" />
          <text class="counter">{{ description.length }} / 500</text>
        </view>

        <view class="field">
          <text class="label">上传凭证<text class="label-sub">（最多 6 张）</text></text>
          <view class="evidence-list">
            <view v-for="(evidence, index) in evidenceFiles" :key="evidence.fileId" class="evidence">
              <image :src="evidence.previewUrl" mode="aspectFill" />
              <text class="evidence-del" @click="evidenceFiles.splice(index, 1)">×</text>
            </view>
            <view v-if="evidenceFiles.length < 6" class="evidence add" @click="uploadEvidence">
              <text class="add-plus">＋</text>
              <small>添加照片 {{ evidenceFiles.length }}/6</small>
            </view>
          </view>
        </view>
      </view>

      <view class="notice">
        <text class="notice-icon">!</text>
        <text class="notice-copy">原运费不退；退货运费承担将按所选原因和审核结果确定。多次部分退款累计不会超过实际支付金额和积分。</text>
      </view>

      <view class="action-bar">
        <button class="submit-btn" :loading="submitting" :disabled="submitting || !canSubmit" @click="submit">提交申请</button>
      </view>
    </template>
    </template>
  </view>
</template>

<script setup lang="ts">
import { onLoad } from '@dcloudio/uni-app'
import { computed, ref } from 'vue'
import { createAfterSaleIdempotencyKey, createExchange, createRefund, listAfterSaleReasons, type AfterSaleReason } from '@/api/after-sales'
import { ApiError } from '@/api/http'
import { getOrder, type Order, type OrderItem } from '@/api/orders'
import { chooseAndUploadPrivateImage } from '@/api/upload'
import { useAuthStore } from '@/stores/auth'
import { navigateToLogin } from '@/utils/authRedirect'
import ModulePlaceholder from '@/components/ModulePlaceholder.vue'
import { useModulesStore } from '@/stores/modules'

type ServiceType = 'refund_only' | 'return_and_refund' | 'exchange'
const modules = useModulesStore()
const moduleEnabled = computed(() => modules.isEnabled('module_after_sale'))
const auth = useAuthStore()
const order = ref<Order | null>(null)
const item = ref<OrderItem | null>(null)
const serviceType = ref<ServiceType>('refund_only')
const reasons = ref<AfterSaleReason[]>([])
const selectedReason = ref<AfterSaleReason | null>(null)
const quantity = ref(1)
const description = ref('')
const evidenceFiles = ref<Array<{ fileId: string; previewUrl: string }>>([])
const loading = ref(true)
const loadError = ref('')
const requestOrderId = ref('')
const requestItemId = ref('')
const requestType = ref<ServiceType>('refund_only')
const submitting = ref(false)
const uploading = ref(false)
const reasonsLoading = ref(false)
const reasonError = ref('')
const submitIdempotencyKey = ref<string | null>(null)
const submitFingerprint = ref('')
const reasonLabels = computed(() => reasons.value.map((reason) => `${reason.name}${reason.evidence_required ? '（需凭证）' : ''}`))
const availableTypes = computed(() => {
  const labels: Record<ServiceType, string> = { refund_only: '仅退款', return_and_refund: '退货退款', exchange: '换货' }
  return (item.value?.after_sale_workflow.available_service_types ?? []).map((value) => ({ value, label: labels[value] || '其他售后服务' }))
})
const maxQuantity = computed(() => item.value ? (serviceType.value === 'exchange' ? item.value.remaining_exchangeable_quantity : item.value.remaining_refundable_quantity) : 0)
const canSubmit = computed(() => !!order.value && !!item.value && maxQuantity.value > 0 && quantity.value >= 1 && quantity.value <= maxQuantity.value && !!selectedReason.value && (!selectedReason.value.evidence_required || evidenceFiles.value.length > 0))
const refundPreview = computed(() => {
  if (!item.value) return ''
  if (serviceType.value === 'exchange') return `换货 ${quantity.value} 件（不产生退款）`
  const isAllRemaining = quantity.value === item.value.remaining_refundable_quantity
  const cash = isAllRemaining ? item.value.refundable_cash_amount : Math.min(item.value.refundable_cash_amount, Math.floor(item.value.paid_cash_amount * quantity.value / item.value.quantity))
  const points = isAllRemaining ? item.value.refundable_points : Math.min(item.value.refundable_points, Math.floor(item.value.paid_points * quantity.value / item.value.quantity))
  return `预计退 ${amountText(cash, points)}`
})

onLoad(async (query) => {
  if (!auth.isAuthenticated) return navigateToLogin()
  await modules.ensureReady()
  if (!moduleEnabled.value) return
  requestOrderId.value = typeof query?.orderId === 'string' ? query.orderId : ''
  requestItemId.value = typeof query?.itemId === 'string' ? query.itemId : ''
  requestType.value = typeof query?.type === 'string' && ['refund_only', 'return_and_refund', 'exchange'].includes(query.type) ? query.type as ServiceType : 'refund_only'
  await load()
})

async function load() {
  if (!auth.accessToken) return
  loading.value = true
  loadError.value = ''
  try {
    order.value = await getOrder(auth.accessToken, requestOrderId.value)
    item.value = order.value?.items.find((entry) => entry.id === requestItemId.value) || null
    const available = item.value?.after_sale_workflow.available_service_types ?? []
    serviceType.value = available.includes(requestType.value) ? requestType.value : (item.value?.after_sale_workflow.recommended_service_type ?? 'refund_only')
    if (!item.value || !item.value.after_sale_workflow.allowed || !available.includes(serviceType.value)) {
      loadError.value = item.value?.after_sale_workflow.summary || '该商品当前不可申请售后'
      return
    }
    quantity.value = 1
    await loadReasons()
  } catch (error) { loadError.value = error instanceof Error ? error.message : '售后信息加载失败，请稍后重试'; toast(error, '售后信息加载失败') }
  finally { loading.value = false }
}

async function changeType(type: ServiceType) {
  if (reasonsLoading.value) return
  serviceType.value = type
  quantity.value = Math.min(Math.max(1, quantity.value), maxQuantity.value)
  selectedReason.value = null
  evidenceFiles.value = []
  await loadReasons()
}
async function loadReasons() {
  reasonsLoading.value = true
  reasonError.value = ''
  // Do not leave a previous service type's choices available after a failed request.
  reasons.value = []
  selectedReason.value = null
  try {
    const response = await listAfterSaleReasons(serviceType.value)
    reasons.value = response.items
  } catch (error) {
    reasons.value = []
    selectedReason.value = null
    reasonError.value = error instanceof Error && error.message ? error.message : '暂时无法加载退换原因，请重试。'
  } finally {
    reasonsLoading.value = false
  }
}
async function retryReasons() { if (!reasonsLoading.value) await loadReasons() }
function selectReason(event: { detail: { value: number } }) { selectedReason.value = reasons.value[Number(event.detail.value)] || null }
async function uploadEvidence() {
  if (uploading.value) return
  uploading.value = true
  try { evidenceFiles.value.push(await chooseAndUploadPrivateImage(auth.accessToken, 'refund_evidence')) }
  catch (error) { toast(error, '凭证上传失败') }
  finally { uploading.value = false }
}
async function submit() {
  if (submitting.value || !auth.accessToken || !order.value || !item.value || !selectedReason.value || !canSubmit.value) return
  submitting.value = true
  const common = { quantity: quantity.value, reason_code: selectedReason.value.code, description: description.value || undefined, evidence_file_ids: evidenceFiles.value.map((file) => file.fileId), return_method: item.value.after_sale_workflow.return_method }
  const fingerprint = JSON.stringify({ serviceType: serviceType.value, orderId: order.value.id, itemId: item.value.id, ...common })
  if (!submitIdempotencyKey.value || submitFingerprint.value !== fingerprint) {
    submitIdempotencyKey.value = createAfterSaleIdempotencyKey(`after-sale-apply-${serviceType.value}`)
    submitFingerprint.value = fingerprint
  }
  let redirected = false
  try {
    let id = ''
    if (serviceType.value === 'exchange') id = (await createExchange(auth.accessToken, { order_item_id: item.value.id, ...common }, submitIdempotencyKey.value)).id
    else id = (await createRefund(auth.accessToken, { order_id: order.value.id, service_type: serviceType.value, reason_code: common.reason_code, description: common.description, evidence_file_ids: common.evidence_file_ids, ...(serviceType.value === 'return_and_refund' ? { return_method: common.return_method } : {}), items: [{ order_item_id: item.value.id, quantity: quantity.value }] }, submitIdempotencyKey.value)).id
    submitIdempotencyKey.value = null
    submitFingerprint.value = ''
    redirected = true
    uni.showToast({ title: '售后申请已提交', icon: 'success' })
    setTimeout(() => serviceType.value === 'exchange' ? uni.redirectTo({ url: '/pages/after-sales/index' }) : uni.redirectTo({ url: `/pages/after-sale-detail/index?id=${encodeURIComponent(id)}` }), 450)
  } catch (error) {
    if (isTerminalFailure(error)) { submitIdempotencyKey.value = null; submitFingerprint.value = '' }
    toast(error, '提交失败')
  } finally {
    // Keep the button disabled until the successful navigation is dispatched.
    if (!redirected) submitting.value = false
  }
}
function partyText(value: string) { return ({ buyer: '买家责任', seller: '卖家责任', carrier: '物流责任', pending: '待平台判定', negotiated: '协商确定' } as Record<string, string>)[value] || '待平台判定' }
function payerText(value: string) { return ({ buyer: '买家承担', seller: '卖家承担', carrier: '物流方承担', pending: '待平台判定', negotiated: '协商确定' } as Record<string, string>)[value] || '待平台判定' }
function amountText(cash: number, points: number) { return [cash ? `¥${(cash / 100).toFixed(2)}` : '', points ? `${points} 积分` : ''].filter(Boolean).join(' + ') || '无需退款' }
function toast(error: unknown, fallback: string) { uni.showToast({ title: error instanceof Error ? error.message : fallback, icon: 'none' }) }
function isTerminalFailure(error: unknown) { return error instanceof ApiError && error.statusCode >= 400 && error.statusCode < 500 && ![408, 409, 425, 429].includes(error.statusCode) }
</script>

<style scoped>
.page { min-height: 100vh; background: #F4F6F4; padding-bottom: calc(160rpx + env(safe-area-inset-bottom)); }

.card { margin: 20rpx 24rpx 0; background: #fff; border-radius: 24rpx; box-shadow: 0 4rpx 16rpx rgba(25, 27, 31, 0.05); }
.item-card { display: flex; gap: 22rpx; padding: 28rpx; }
.item-mark { width: 110rpx; height: 110rpx; flex: none; border-radius: 20rpx; background: #E7F9EF; display: flex; align-items: center; justify-content: center; }
.item-mark text { font-size: 40rpx; font-weight: 800; color: #08A152; }
.item-main { flex: 1; min-width: 0; display: flex; flex-direction: column; }
.item-name { font-size: 28rpx; font-weight: 700; color: #191B1F; line-height: 1.45; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
.item-meta { margin-top: 10rpx; font-size: 22rpx; color: #AEB3BB; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.item-money { margin-top: 8rpx; font-size: 24rpx; font-weight: 700; color: #FF2442; }

.form-card { padding: 10rpx 28rpx 28rpx; }
.field { padding: 26rpx 0 0; }
.label { display: block; font-size: 27rpx; font-weight: 700; color: #191B1F; margin-bottom: 18rpx; }
.label-sub { font-weight: 400; color: #AEB3BB; font-size: 22rpx; }

.type-tabs { display: flex; gap: 14rpx; }
.type-tab { flex: 1; text-align: center; padding: 18rpx 0; border-radius: 16rpx; background: #F2F4F3; color: #767C85; font-size: 25rpx; }
.type-tab.active { background: #E7F9EF; color: #08A152; font-weight: 700; box-shadow: inset 0 0 0 2rpx #0BBF63; }

.quantity-row { display: flex; align-items: center; gap: 20rpx; }
.quantity { display: flex; align-items: center; background: #F2F4F3; border-radius: 14rpx; }
.qty-btn { width: 64rpx; height: 60rpx; line-height: 56rpx; padding: 0; margin: 0; background: transparent; font-size: 32rpx; color: #191B1F; }
.qty-btn::after { border: 0; }
.qty-btn[disabled] { color: #C7CCC8; }
.qty-num { width: 72rpx; text-align: center; font-size: 28rpx; font-weight: 700; }
.quantity-note { font-size: 22rpx; color: #AEB3BB; }

.picker-value { padding: 24rpx 22rpx; border-radius: 16rpx; background: #F2F4F3; font-size: 26rpx; color: #191B1F; }
.picker-value.placeholder { color: #AEB3BB; }

.responsibility { display: flex; gap: 14rpx; margin-top: 24rpx; }
.resp-item { flex: 1; padding: 18rpx 8rpx; text-align: center; border-radius: 16rpx; background: #F7FAF8; }
.resp-item text { display: block; font-size: 21rpx; color: #767C85; }
.resp-item strong { display: block; margin-top: 8rpx; font-size: 23rpx; color: #08A152; }

.desc-input { width: 100%; height: 180rpx; padding: 22rpx; box-sizing: border-box; border-radius: 16rpx; background: #F7FAF8; font-size: 26rpx; line-height: 1.5; }
.ph { color: #AEB3BB; }
.counter { display: block; margin-top: 10rpx; text-align: right; font-size: 21rpx; color: #AEB3BB; }

.evidence-list { display: grid; grid-template-columns: repeat(4, 1fr); gap: 14rpx; }
.evidence { position: relative; height: 150rpx; border-radius: 16rpx; overflow: hidden; }
.evidence image { width: 100%; height: 100%; }
.evidence-del { position: absolute; right: 6rpx; top: 6rpx; width: 36rpx; height: 36rpx; line-height: 32rpx; text-align: center; border-radius: 50%; background: rgba(25, 27, 31, 0.62); color: #fff; font-size: 26rpx; }
.evidence.add { display: flex; flex-direction: column; align-items: center; justify-content: center; border: 2rpx dashed #B9C6BE; background: #F7FAF8; color: #08A152; }
.add-plus { font-size: 40rpx; line-height: 1; }
.evidence.add small { margin-top: 8rpx; font-size: 20rpx; color: #767C85; }

.reason-error { padding: 24rpx; border-radius: 16rpx; background: #FFF4F2; }
.re-title { display: block; font-size: 26rpx; font-weight: 700; color: #D9402E; }
.re-detail { display: block; margin-top: 8rpx; font-size: 22rpx; color: #B54708; }
.retry-button { margin-top: 16rpx; height: 64rpx; line-height: 64rpx; border-radius: 12rpx; background: #fff; color: #D9402E; font-size: 24rpx; border: 2rpx solid rgba(217, 64, 46, 0.35); }
.retry-button::after { border: 0; }

.notice { display: flex; align-items: flex-start; gap: 14rpx; margin: 22rpx 24rpx 0; padding: 22rpx 26rpx; background: #FFF8EC; border-radius: 20rpx; }
.notice-icon { width: 32rpx; height: 32rpx; flex: none; border-radius: 50%; background: #E6A808; color: #fff; font-size: 22rpx; text-align: center; line-height: 32rpx; font-weight: 800; }
.notice-copy { font-size: 22rpx; color: #8A6D2F; line-height: 1.6; }

.action-bar { position: fixed; left: 0; right: 0; bottom: 0; padding: 16rpx 24rpx calc(16rpx + env(safe-area-inset-bottom)); background: #fff; box-shadow: 0 -4rpx 20rpx rgba(25, 27, 31, 0.06); }
.submit-btn { width: 100%; height: 88rpx; line-height: 88rpx; border-radius: 44rpx; background: linear-gradient(135deg, #12D978, #08A152); color: #fff; font-size: 30rpx; font-weight: 700; box-shadow: 0 10rpx 22rpx rgba(11, 191, 99, 0.25); }
.submit-btn::after { border: 0; }
.submit-btn[disabled] { opacity: 0.55; box-shadow: none; }

.state-card { margin: 24rpx; padding: 70rpx 40rpx; text-align: center; background: #fff; border-radius: 24rpx; }
.state-spinner { width: 80rpx; height: 80rpx; margin: 0 auto; border-radius: 24rpx; }
.state-title { display: block; margin-top: 22rpx; font-size: 30rpx; font-weight: 800; color: #191B1F; }
.state-copy { display: block; margin-top: 12rpx; font-size: 24rpx; color: #767C85; line-height: 1.6; }
.error-card { background: #FFF4F2; }
.retry-main { margin-top: 32rpx; width: 320rpx; height: 80rpx; line-height: 80rpx; border-radius: 40rpx; background: linear-gradient(135deg, #12D978, #08A152); color: #fff; font-size: 27rpx; font-weight: 700; }
.retry-main::after { border: 0; }
</style>
