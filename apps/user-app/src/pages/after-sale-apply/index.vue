<template>
  <view class="page tm-page-shell">
    <ModulePlaceholder v-if="modules.resolved && !moduleEnabled" code="module_after_sale" />
    <view v-else-if="!modules.resolved" class="mp-skeleton">
      <view class="tm-skeleton" style="height: 200rpx; border-radius: 24rpx; margin: 24rpx 28rpx;" />
      <view class="tm-skeleton" style="height: 320rpx; border-radius: 24rpx; margin: 20rpx 28rpx;" />
    </view>
    <template v-else>
    <view class="commerce-hero" aria-label="售后申请">
      <image class="commerce-hero-art" src="/static/home/hero-campus-reward.png" mode="aspectFill" />
      <view class="commerce-hero-copy">
        <text class="commerce-hero-kicker">AFTER SALES</text>
        <text class="commerce-hero-title">申请售后</text>
        <text class="commerce-hero-sub">先确认原因与数量，提交后按订单规则审核</text>
      </view>
    </view>
    <view class="rule-grid"><view><text>未发货</text><small>无需退货物流</small></view><view><text>已发货</text><small>按原因判断责任</small></view><view><text>7 天内</text><small>超时自动关闭</small></view></view>

    <view v-if="loading" class="state-card tm-panel"><view class="state-spinner tm-skeleton" /><text class="state-title">正在准备售后申请</text><text class="state-copy">正在校验订单项、可申请数量和售后规则。</text></view>
    <view v-else-if="loadError" class="state-card error-card tm-panel"><text class="state-title">售后申请暂不可用</text><text class="state-copy">{{ loadError }}</text><button class="tm-primary-button" :loading="loading" :disabled="loading" @click="load">重新加载</button></view>

    <view v-if="!loading && !loadError && item" class="item-card tm-panel"><view class="item-mark">{{ item.product_name.slice(0, 1) }}</view><view><text class="item-name">{{ item.product_name }}</text><text class="item-meta">{{ item.sku_name }} · {{ serviceType === 'exchange' ? '剩余可换' : '剩余可退' }} {{ maxQuantity }} 件</text><text class="tm-money">{{ refundPreview }}</text></view></view>

    <view v-if="!loading && !loadError" class="form-card tm-panel">
      <view class="field"><text class="label">售后类型</text><view class="type-tabs"><view v-for="option in availableTypes" :key="option.value" :class="{ active: serviceType === option.value }" @click="changeType(option.value)">{{ option.label }}</view></view></view>
      <view class="field"><text class="label">申请数量</text><view class="quantity"><button :disabled="quantity <= 1" @click="quantity = Math.max(1, quantity - 1)">−</button><text>{{ quantity }}</text><button :disabled="quantity >= maxQuantity" @click="quantity = Math.min(maxQuantity, quantity + 1)">＋</button></view><text class="quantity-note">已退款 {{ item?.refunded_quantity || 0 }} 件，审核中占用 {{ item?.pending_refund_quantity || 0 }} 件</text></view>
      <view class="field reason-field">
        <text class="label">退换原因</text>
        <picker v-if="!reasonError" :range="reasonLabels" :disabled="reasonsLoading || !reasons.length" @change="selectReason"><view class="field row"><text class="field-value">{{ reasonsLoading ? '原因加载中…' : selectedReason?.name || (reasons.length ? '请选择 ›' : '暂无可用原因') }}</text></view></picker>
        <view v-else class="reason-error"><text>退换原因加载失败</text><text>{{ reasonError }}</text><button class="retry-button" :loading="reasonsLoading" :disabled="reasonsLoading" @click="retryReasons">重新加载</button></view>
      </view>
      <view v-if="selectedReason" class="responsibility">
        <view><text>责任预判</text><strong>{{ partyText(selectedReason.default_responsibility) }}</strong></view>
        <view><text>退货运费</text><strong>{{ payerText(selectedReason.return_shipping_payer) }}</strong></view>
        <view><text>凭证要求</text><strong>{{ selectedReason.evidence_required ? '必须上传' : '选填' }}</strong></view>
      </view>
      <view class="field"><text class="label">问题说明与凭证</text><textarea v-model="description" maxlength="500" placeholder="请描述商品问题、期望处理方式和必要细节" /><text class="counter">{{ description.length }} / 500</text></view>
      <view class="evidence-list">
        <view v-for="(evidence, index) in evidenceFiles" :key="evidence.fileId" class="evidence"><image :src="evidence.previewUrl" mode="aspectFill" /><text @click="evidenceFiles.splice(index, 1)">删除</text></view>
        <view v-if="evidenceFiles.length < 6" class="evidence add" @click="uploadEvidence"><text>＋</text><small>上传凭证</small></view>
      </view>
    </view>

    <view v-if="!loading && !loadError" class="notice">原运费不退；退货运费承担将按所选原因和审核结果确定。多次部分退款累计不会超过实际支付金额和积分。</view>
    <button v-if="!loading && !loadError" class="tm-primary-button" :loading="submitting" :disabled="submitting || !canSubmit" @click="submit">提交售后申请</button>
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
.rule-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10rpx}.rule-grid view{padding:20rpx 8rpx;text-align:center;border-radius:16rpx;background:var(--tm-brand-50)}.rule-grid text,.rule-grid small,.item-name,.item-meta{display:block}.rule-grid text{color:var(--tm-brand-700);font-size:13px;font-weight:900}.rule-grid small{margin-top:5rpx;color:var(--tm-ink-500);font-size:12px}.item-card{display:flex;align-items:center;gap:20rpx;margin-top:20rpx;padding:24rpx}.item-mark{display:flex;align-items:center;justify-content:center;width:96rpx;height:96rpx;flex:none;border-radius:18rpx;background:linear-gradient(145deg,#dff3f1,#fff0dc);color:var(--tm-brand-700);font-size:21px;font-weight:950}.item-name{font-size:15px;font-weight:900}.item-meta{margin:7rpx 0;color:var(--tm-ink-500);font-size:13px}.form-card{margin-top:20rpx;padding:26rpx}.field{margin-top:24rpx}.field:first-child{margin-top:0}.field.row{display:flex;align-items:center;justify-content:space-between}.label{display:block;margin-bottom:12rpx;font-size:14px;font-weight:900}.type-tabs{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10rpx}.type-tabs view{padding:15rpx 8rpx;text-align:center;border:1rpx solid var(--tm-line);border-radius:13rpx;color:var(--tm-ink-700);font-size:13px}.type-tabs .active{border-color:var(--tm-brand-600);background:var(--tm-brand-50);color:var(--tm-brand-700);font-weight:900}.quantity{display:flex;align-items:center}.quantity button{width:60rpx;height:56rpx;margin:0;padding:0;border-radius:12rpx;background:#f0f4f4;line-height:56rpx}.quantity text{width:82rpx;text-align:center;font-weight:900}.field-value{color:var(--tm-ink-500);font-size:14px}.responsibility{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8rpx;margin-top:22rpx}.responsibility view{padding:16rpx 8rpx;text-align:center;border-radius:12rpx;background:var(--tm-surface-soft)}.responsibility text,.responsibility strong{display:block}.responsibility text{color:var(--tm-ink-500);font-size:12px}.responsibility strong{margin-top:6rpx;font-size:13px}.field textarea{width:100%;height:160rpx;padding:18rpx;border:1rpx solid var(--tm-line);border-radius:14rpx;background:#fafcfc;box-sizing:border-box;font-size:14px}.counter{display:block;margin-top:7rpx;color:var(--tm-ink-500);text-align:right;font-size:12px}.evidence-list{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10rpx;margin-top:16rpx}.evidence{position:relative;height:150rpx;overflow:hidden;border:1rpx dashed #b9c8c9;border-radius:14rpx}.evidence image{width:100%;height:100%}.evidence>text{position:absolute;right:6rpx;top:6rpx;padding:4rpx 8rpx;border-radius:7rpx;background:rgba(7,26,51,.72);color:#fff;font-size:12px}.evidence.add{display:flex;align-items:center;justify-content:center;flex-direction:column;color:var(--tm-brand-700)}.evidence.add text{position:static;padding:0;background:transparent;color:inherit;font-size:21px}.evidence.add small{margin-top:6rpx;font-size:13px}.notice{margin:20rpx 0;padding:19rpx;border-radius:14rpx;background:var(--tm-reward-100);color:#89500c;font-size:13px;line-height:1.55}
.quantity button[disabled]{opacity:.38}.quantity-note{display:block;margin-top:9rpx;color:var(--tm-ink-500);font-size:13px}
.reason-error{padding:18rpx;border:1rpx solid #f0c7c3;border-radius:14rpx;background:#fff5f4;color:#b42318;font-size:13px;line-height:1.5}.reason-error text{display:block}.reason-error text+text{margin-top:5rpx;color:#b54708;font-size:13px}.retry-button{height:58rpx;margin:14rpx 0 0;border:1rpx solid #b42318;border-radius:12rpx;background:#fff;color:#b42318;font-size:13px;line-height:56rpx}.retry-button::after{border:0}
/* V09 campus after-sales surface */
.page{background:linear-gradient(180deg,var(--tm-campus-cyan-soft) 0,var(--tm-canvas) 520rpx);color:var(--tm-ink-900)}
.rule-grid view,.item-card,.form-card,.state-card{border:1rpx solid var(--tm-line);box-shadow:var(--tm-shadow-sm);background:var(--tm-surface)}
.rule-grid view:nth-child(1){background:var(--tm-growth-100)}.rule-grid view:nth-child(2){background:var(--tm-reward-100)}.rule-grid view:nth-child(3){background:var(--tm-campus-coral-soft)}
.rule-grid text{color:var(--tm-ink-900)}.item-card{background:var(--tm-surface)}.item-mark{border:0;background:var(--tm-brand-100);color:var(--tm-brand-700)}
.form-card{background:var(--tm-surface)}.type-tabs view{border:1rpx solid var(--tm-line);box-shadow:none}
.type-tabs .active{border-color:var(--tm-brand-500);background:var(--tm-brand-50);color:var(--tm-brand-700);box-shadow:none}
.quantity button,.field textarea,.evidence{border:1rpx solid var(--tm-line);background:var(--tm-surface)}
.responsibility view{border:0}.responsibility view:nth-child(1){background:var(--tm-reward-100)}.responsibility view:nth-child(2){background:var(--tm-campus-cyan-soft)}.responsibility view:nth-child(3){background:var(--tm-growth-100)}
.notice{border:1rpx solid rgba(183,123,18,.18);background:var(--tm-reward-100)}
.tm-primary-button{border:1rpx solid transparent;box-shadow:none}.tm-primary-button[disabled]{opacity:.46;filter:saturate(.55)}
.reason-error{border:1rpx solid rgba(209,67,67,.22);background:var(--tm-campus-coral-soft);color:var(--tm-danger)}.reason-error text+text{color:var(--tm-ink-700)}.retry-button{border:1rpx solid rgba(209,67,67,.3);box-shadow:none;background:var(--tm-surface);color:var(--tm-danger)}
.state-card{margin-top:22rpx;padding:44rpx 28rpx;text-align:center}.state-spinner{width:72rpx;height:72rpx;margin:0 auto;border-radius:24rpx}.state-title,.state-copy{display:block}.state-title{margin-top:18rpx;font-size:17px;font-weight:900}.state-copy{margin-top:9rpx;color:var(--tm-ink-500);font-size:13px;line-height:1.5}.error-card{border-color:rgba(209,67,67,.22);background:var(--tm-campus-coral-soft)}
.commerce-hero{position:relative;min-height:250rpx;overflow:hidden;margin-bottom:18rpx;border:1rpx solid var(--tm-line);border-radius:var(--tm-radius-lg);background:linear-gradient(135deg,#eaffef 0,#e3f7ff 58%,#fff4e8 100%);box-shadow:var(--tm-shadow-sm)}.commerce-hero-art{position:absolute;right:-16rpx;top:0;width:68%;height:100%;opacity:.84;object-position:right center}.commerce-hero::after{content:'';position:absolute;inset:0;background:linear-gradient(90deg,rgba(234,255,239,.98) 0,rgba(234,255,239,.9) 34%,rgba(234,255,239,.12) 78%,rgba(234,255,239,0) 100%)}.commerce-hero-copy{position:relative;z-index:1;width:57%;padding:28rpx 24rpx}.commerce-hero-kicker,.commerce-hero-title,.commerce-hero-sub{display:block}.commerce-hero-kicker{color:var(--tm-brand-700);font-size:13px;font-weight:850;letter-spacing:2.4rpx}.commerce-hero-title{margin-top:10rpx;color:var(--tm-ink-900);font-size:22px;font-weight:950;line-height:1.2}.commerce-hero-sub{margin-top:10rpx;color:var(--tm-ink-500);font-size:13px;line-height:1.55}
</style>
