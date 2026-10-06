<template>
  <view class="page tm-page-shell">
    <ModulePlaceholder v-if="modules.resolved && !moduleEnabled" code="module_after_sale" />
    <view v-else-if="!modules.resolved" class="mp-skeleton">
      <view class="tm-skeleton" style="height: 200rpx; border-radius: 24rpx; margin: 24rpx 28rpx;" />
      <view class="tm-skeleton" style="height: 320rpx; border-radius: 24rpx; margin: 20rpx 28rpx;" />
    </view>
    <template v-else>
    <view v-if="loading" class="state-card tm-panel"><text class="state-title">正在加载售后进度</text><text class="state-copy">请稍候，正在获取最新处理结果。</text></view>
    <view v-if="loadError" class="state-card error-card tm-panel">
      <text class="state-title">售后进度加载失败</text>
      <text class="state-copy">{{ loadError }}</text>
      <view class="state-actions">
        <button class="tm-primary-button" :loading="loading" :disabled="loading" @click="load">重新加载</button>
        <button class="tm-secondary-button" @click="backToAfterSales">返回售后列表</button>
        <button class="state-home-button" @click="backHome">返回首页</button>
      </view>
    </view>
    <view v-if="record" class="status-hero">
      <view class="status-mark">售</view>
      <text class="status-title">{{ statusTitle(record.status) }}</text>
      <text class="status-copy">{{ recordNo }}{{ deadlineText ? ` · ${deadlineText}` : '' }}</text>
    </view>

    <view v-if="record" class="timeline tm-panel">
      <text class="section-title">处理进度</text>
      <view v-for="(step, index) in steps" :key="step.title" class="tl-li" :class="[step.state, { last: index === steps.length - 1 }]">
        <view class="tl-rail"><i class="dot" /><i v-if="index < steps.length - 1" class="line" /></view>
        <view class="tl-body">
          <text class="tl-title">{{ step.title }}</text>
          <text class="tl-desc">{{ step.desc }}</text>
        </view>
      </view>
    </view>

    <view v-if="record" class="summary tm-panel">
      <view><text>售后类型</text><strong>{{ kind === 'exchange' ? '同 SKU 换货' : '退款 / 退货' }}</strong></view>
      <view><text>售后原因</text><strong>{{ record.reason_name }}</strong></view>
      <view><text>责任归属</text><strong>{{ partyText(record.responsibility_party) }}</strong></view>
      <view><text>寄回方式</text><strong>{{ methodText(record.return_method) }}</strong></view>
      <view><text>寄回运费</text><strong>{{ partyText(record.return_shipping_payer) }}承担</strong></view>
      <view v-if="kind === 'refund'"><text>预计退回</text><strong class="tm-money">{{ amountText(refund!.refund_cash_amount, refund!.refund_points) }}</strong></view>
      <view v-if="record.review_note"><text>处理说明</text><strong>{{ record.review_note }}</strong></view>
    </view>

    <view v-if="replacement" class="shipment tm-panel">
      <text class="section-title">替换商品跟踪</text>
      <text class="shipment-line">{{ replacement.method === 'logistics' ? `${replacement.carrier_code || '承运商'} · ${replacement.tracking_no}` : replacement.no_logistics_note }}</text>
      <text v-if="exchange?.replacement_auto_complete_at" class="shipment-tip">请在期限内确认收到替换商品，超时后系统会自动完成。</text>
      <button v-if="exchange?.status === 'replacement_shipped'" class="tm-primary-button" :loading="submitting" :disabled="submitting" @click="confirmExchange">确认收到替换商品</button>
    </view>

    <view v-if="canSubmitReturn" class="shipment tm-panel">
      <text class="section-title">填写寄回物流</text>
      <input v-model="carrierCode" class="input" maxlength="64" placeholder="承运商编码，例如 SF / ZTO" />
      <input v-model="trackingNo" class="input" maxlength="100" placeholder="寄回物流单号" />
      <input v-model.number="paidShippingYuan" class="input" type="digit" placeholder="实际支付退货运费（元，可填 0）" />
      <view v-if="Number(paidShippingYuan) > 0" class="proof" :class="{ 'is-uploading': uploading }" @click="uploadProof">
        <image v-if="proof?.previewUrl" :src="proof.previewUrl" mode="aspectFill" />
        <view v-else-if="uploading"><text class="upload-spinner">···</text><small>正在上传付款凭证</small></view>
        <view v-else><text>＋</text><small>上传运费付款凭证</small></view>
      </view>
      <text class="shipment-tip">申请退货运费补偿时，物流单号和付款凭证缺一不可；审核通过后优先发放至账户余额。</text>
      <button class="tm-primary-button" :loading="submitting" :disabled="submitting" @click="submitShipment">提交寄回物流</button>
    </view>

    <view v-if="compensation" class="shipment tm-panel">
      <text class="section-title">退货运费补偿</text>
      <text class="shipment-line">申请 ¥{{ money(compensation.requested_amount) }} · {{ compensationText(compensation.status) }}</text>
      <text v-if="compensation.status === 'paid'" class="shipment-tip">已发放 ¥{{ money(compensation.approved_amount) }} 至账户余额，可在资产明细中核对独立流水。</text>
      <text v-if="compensation.review_note" class="shipment-tip">审核说明：{{ compensation.review_note }}</text>
    </view>

    <view v-if="record" class="service-note">责任方、寄回方式和运费承担均按本次售后个案裁决，所有操作会保留审计记录。</view>
    <view v-if="record" class="detail-action-bar">
      <button class="ab-secondary" @click="contactSupport">联系客服</button>
      <button v-if="canCancel" class="ab-primary" :loading="cancelling" :disabled="cancelling" @click="requestCancel">撤销申请</button>
    </view>
    </template>
  </view>
</template>

<script setup lang="ts">
import { onLoad } from '@dcloudio/uni-app'
import { computed, ref } from 'vue'
import { completeExchange, cancelExchange, cancelRefund, createAfterSaleIdempotencyKey, getExchange, listRefunds, submitExchangeReturn, submitRefundReturn, type Exchange, type Refund } from '@/api/after-sales'
import { ApiError } from '@/api/http'
import { chooseAndUploadPrivateImage } from '@/api/upload'
import { useAuthStore } from '@/stores/auth'
import { navigateToLogin } from '@/utils/authRedirect'
import ModulePlaceholder from '@/components/ModulePlaceholder.vue'
import { useModulesStore } from '@/stores/modules'

const modules = useModulesStore()
const moduleEnabled = computed(() => modules.isEnabled('module_after_sale'))

const auth = useAuthStore()
const kind = ref<'refund' | 'exchange'>('refund')
const recordId = ref('')
const refund = ref<Refund | null>(null)
const exchange = ref<Exchange | null>(null)
const carrierCode = ref('')
const trackingNo = ref('')
const paidShippingYuan = ref<number | string>(0)
const proof = ref<{ fileId: string; previewUrl: string } | null>(null)
const submitting = ref(false)
const cancelling = ref(false)
const loading = ref(false)
const loadError = ref('')
const uploading = ref(false)
const shipmentIdempotencyKey = ref<string | null>(null)
const shipmentFingerprint = ref('')
const record = computed(() => kind.value === 'exchange' ? exchange.value : refund.value)
const recordNo = computed(() => kind.value === 'exchange' ? `换货单 ${exchange.value?.exchange_no || ''}` : `售后单 ${refund.value?.refund_no || ''}`)
const replacement = computed(() => exchange.value?.shipments?.find(item => item.direction === 'replacement') || null)
const compensation = computed(() => kind.value === 'exchange'
  ? exchange.value?.shipments?.find(item => item.direction === 'return')?.compensation || null
  : refund.value?.compensation || null)
const canSubmitReturn = computed(() => kind.value === 'exchange' ? exchange.value?.status === 'waiting_return' : refund.value?.status === 'awaiting_return')
const deadlineText = computed(() => {
  const raw = kind.value === 'exchange'
    ? (exchange.value?.status === 'replacement_shipped' ? exchange.value.replacement_auto_complete_at : exchange.value?.return_deadline_at)
    : refund.value?.user_action_deadline_at
  if (!raw) return ''
  const hours = Math.max(0, Math.ceil((new Date(raw).getTime() - Date.now()) / 3600000))
  return hours > 0 ? `剩余 ${hours} 小时操作` : '操作期限已到'
})
type StepState = 'done' | 'active' | 'rejected' | 'cancelled' | 'todo'
interface TimelineStep { title: string; desc: string; state: StepState }
const steps = computed<TimelineStep[]>(() => {
  const status = record.value?.status
  if (!status) return []
  const noLogistics = kind.value === 'exchange' && exchange.value?.return_method === 'no_logistics'
  const defs = kind.value === 'exchange'
    ? noLogistics
      ? { titles: ['提交申请', '平台审核', '补发商品', '确认完成'], descs: ['换货申请已提交', '平台核对换货原因与凭证', '审核通过后仓库发出替换商品', '收到替换商品后确认，换货完成'], activeMap: { submitted: 1, replacement_pending: 2, replacement_shipped: 3, completed: 4 } as Record<string, number> }
      : { titles: ['提交申请', '平台审核', '寄回商品', '仓库验收', '补发商品', '确认完成'], descs: ['换货申请已提交', '平台核对换货原因与凭证', '按审核结果寄回问题商品', '仓库验收寄回商品', '验收通过后发出替换商品', '收到替换商品后确认，换货完成'], activeMap: { submitted: 1, waiting_return: 2, return_in_transit: 3, replacement_pending: 4, replacement_shipped: 5, completed: 6 } as Record<string, number> }
    : refund.value?.service_type === 'return_and_refund'
      ? { titles: ['提交申请', '平台审核', '寄回商品', '仓库验收', '退款到账'], descs: ['售后申请已提交', '平台核对原因与凭证', '按审核结果寄回商品', '仓库验收寄回商品', '退款原路退回，1-3 个工作日到账'], activeMap: { submitted: 1, awaiting_return: 2, return_in_transit: 3, processing: 4, succeeded: 5 } as Record<string, number> }
      : { titles: ['提交申请', '平台审核', '退款到账'], descs: ['退款申请已提交', '平台将在 24 小时内完成审核', '审核通过后原路退回，1-3 个工作日到账'], activeMap: { submitted: 1, processing: 2, succeeded: 3 } as Record<string, number> }
  const activeIndex = defs.activeMap[status] ?? 0
  const stopped: StepState = status === 'rejected' ? 'rejected' : ['cancelled', 'closed'].includes(status) ? 'cancelled' : 'active'
  return defs.titles.map((title, index) => {
    let state: StepState = 'todo'
    if (status === 'succeeded' || status === 'completed' || index < activeIndex) state = 'done'
    else if (index === activeIndex) state = stopped
    return { title, desc: defs.descs[index], state }
  })
})
const canCancel = computed(() => {
  const status = record.value?.status
  if (!status) return false
  return kind.value === 'exchange'
    ? ['submitted', 'waiting_return'].includes(status)
    : ['submitted', 'awaiting_return'].includes(status)
})

onLoad(async query => {
  if (!auth.isAuthenticated) return navigateToLogin()
  await modules.ensureReady()
  if (!moduleEnabled.value) return
  recordId.value = typeof query?.id === 'string' ? query.id : ''
  kind.value = query?.type === 'exchange' ? 'exchange' : 'refund'
  await load()
})
async function load() {
  if (!auth.accessToken) return
  loading.value = true
  loadError.value = ''
  try {
    if (kind.value === 'exchange') {
      const result = await getExchange(auth.accessToken, recordId.value)
      exchange.value = result
      if (!result) loadError.value = '未找到对应的换货记录。'
    } else {
      const result = (await listRefunds(auth.accessToken)).items.find(item => item.id === recordId.value) || null
      refund.value = result
      if (!result) loadError.value = '未找到对应的售后记录。'
    }
  } catch (error) {
    loadError.value = error instanceof Error && error.message ? error.message : '暂时无法加载售后进度，请重试。'
    toast(error, '售后进度加载失败')
  } finally { loading.value = false }
}
async function uploadProof() {
  if (uploading.value) return
  uploading.value = true
  try { proof.value = await chooseAndUploadPrivateImage(auth.accessToken, 'return_shipping') }
  catch (error) { toast(error, '付款凭证上传失败') }
  finally { uploading.value = false }
}
function backToAfterSales() { uni.redirectTo({ url: '/pages/after-sales/index' }) }
function backHome() { uni.reLaunch({ url: '/pages/index/index' }) }
async function submitShipment() {
  if (submitting.value) return
  if (!record.value || !carrierCode.value || !trackingNo.value) return uni.showToast({ title: '请填写承运商和物流单号', icon: 'none' })
  const paid = Math.max(0, Math.round(Number(paidShippingYuan.value || 0) * 100))
  if (paid > 0 && !proof.value) return uni.showToast({ title: '申请运费补偿必须上传付款凭证', icon: 'none' })
  const input = { carrier_code: carrierCode.value, tracking_no: trackingNo.value, paid_shipping_amount: paid, ...(proof.value ? { payment_proof_file_id: proof.value.fileId } : {}) }
  const fingerprint = JSON.stringify({ kind: kind.value, recordId: recordId.value, ...input })
  if (!shipmentIdempotencyKey.value || shipmentFingerprint.value !== fingerprint) {
    shipmentIdempotencyKey.value = createAfterSaleIdempotencyKey(`after-sale-return-${kind.value}`)
    shipmentFingerprint.value = fingerprint
  }
  const idempotencyKey = shipmentIdempotencyKey.value || createAfterSaleIdempotencyKey(`after-sale-return-${kind.value}`)
  shipmentIdempotencyKey.value = idempotencyKey
  submitting.value = true
  try {
    if (kind.value === 'exchange') exchange.value = await submitExchangeReturn(auth.accessToken, recordId.value, input, idempotencyKey)
    else refund.value = await submitRefundReturn(auth.accessToken, recordId.value, input, idempotencyKey)
    // The server response is authoritative. Clear the key only after the operation succeeded;
    // a retryable failure must reuse the same key to avoid creating a second shipment/claim.
    shipmentIdempotencyKey.value = null
    shipmentFingerprint.value = ''
    uni.showToast({ title: '寄回物流已提交', icon: 'success' })
    await load()
  } catch (error) {
    if (isTerminalFailure(error)) { shipmentIdempotencyKey.value = null; shipmentFingerprint.value = '' }
    toast(error, '物流提交失败')
  }
  finally { submitting.value = false }
}
async function confirmExchange() {
  if (submitting.value || !exchange.value) return
  submitting.value = true
  try { exchange.value = await completeExchange(auth.accessToken, exchange.value.id); uni.showToast({ title: '换货已完成', icon: 'success' }) }
  catch (error) { toast(error, '确认失败') }
  finally { submitting.value = false }
}
function contactSupport() { uni.navigateTo({ url: '/pages/support/index' }) }
function requestCancel() {
  uni.showModal({
    title: '撤销售后申请',
    content: '撤销后本次售后将关闭，占用的可退/可换数量会立即释放。确定撤销吗？',
    confirmText: '确定撤销',
    confirmColor: '#d14343',
    success: async (res) => { if (res.confirm) await cancelRecord() },
  })
}
async function cancelRecord() {
  if (cancelling.value || !auth.accessToken || !record.value) return
  cancelling.value = true
  try {
    if (kind.value === 'exchange') exchange.value = await cancelExchange(auth.accessToken, recordId.value)
    else refund.value = await cancelRefund(auth.accessToken, recordId.value)
    uni.showToast({ title: '售后申请已撤销', icon: 'success' })
    await load()
  } catch (error) { toast(error, '撤销失败') }
  finally { cancelling.value = false }
}
function money(cents: number) { return (cents / 100).toFixed(2) }
function statusTitle(status: string) { return ({ submitted: '等待平台审核', awaiting_return: '请寄回商品', waiting_return: '请寄回商品', return_in_transit: '寄回运输中', replacement_pending: '等待替换履约', replacement_shipped: '替换商品已发出', processing: '退款处理中', succeeded: '售后已完成', completed: '换货已完成', rejected: '申请未通过', cancelled: '申请已撤销', closed: '申请已关闭' } as Record<string, string>)[status] || '售后处理中' }
function partyText(value: string) { return ({ buyer: '买家', seller: '卖家', carrier: '物流方', pending: '待裁决', negotiated: '协商确定' } as Record<string, string>)[value] || '待平台确认' }
function methodText(value: string | null | undefined) { return ({ logistics: '物流寄回', no_logistics: '无需物流', pickup: '上门取件' } as Record<string, string>)[value || ''] || '待确定' }
function compensationText(value: string) { return ({ submitted: '待平台审核', paid: '已发放至余额', rejected: '审核未通过' } as Record<string, string>)[value] || '补偿处理中' }
function amountText(cash: number, points: number) { return [cash ? `¥${money(cash)}` : '', points ? `${points} 积分` : ''].filter(Boolean).join(' + ') || '无需退款' }
function toast(error: unknown, fallback: string) { uni.showToast({ title: error instanceof Error ? error.message : fallback, icon: 'none' }) }
function isTerminalFailure(error: unknown) { return error instanceof ApiError && error.statusCode >= 400 && error.statusCode < 500 && ![408, 409, 425, 429].includes(error.statusCode) }
</script>

<style scoped>
.state-card{margin-bottom:22rpx;padding:30rpx;border:1rpx solid var(--tm-line);border-radius:24rpx;background:#fff;text-align:center}.state-title,.state-copy{display:block}.state-title{font-size:17px;font-weight:900}.state-copy{margin-top:10rpx;color:var(--tm-ink-500);font-size:13px;line-height:1.55}.state-card .tm-primary-button{margin-top:18rpx}.error-card{border-color:#f0c7c3;background:#fff5f4}.error-card .state-title{color:#b42318}
.status-hero{padding:44rpx 28rpx;text-align:center;border-radius:28rpx;background:linear-gradient(145deg,var(--tm-brand-700),var(--tm-brand-500));color:#fff}.status-mark{display:flex;align-items:center;justify-content:center;width:76rpx;height:76rpx;margin:0 auto;border-radius:50%;background:rgba(255,255,255,.16);font-size:19px;font-weight:900}.status-title,.status-copy,.section-title,.shipment-tip,.shipment-line{display:block}.status-title{margin-top:20rpx;font-size:21px;font-weight:950}.status-copy{margin-top:9rpx;color:rgba(255,255,255,.76);font-size:13px}.summary{margin-top:22rpx;padding:10rpx 24rpx}.summary>view{display:flex;justify-content:space-between;gap:20rpx;padding:20rpx 0;border-bottom:1rpx solid var(--tm-line);font-size:14px}.summary>view:last-child{border-bottom:0}.summary>view text{color:var(--tm-ink-500)}.summary strong{max-width:65%;text-align:right}.shipment{margin-top:22rpx;padding:26rpx}.section-title{font-size:17px;font-weight:950}.shipment-line{margin-top:16rpx;font-size:14px;font-weight:750}.input{width:100%;height:74rpx;margin-top:14rpx;padding:0 18rpx;border:1rpx solid #EDEFEF;border-radius:14rpx;background:#fff;box-sizing:border-box;font-size:14px;color:#191B1F}.proof{display:flex;align-items:center;justify-content:center;height:190rpx;margin-top:16rpx;overflow:hidden;border:1rpx dashed #9FD8B6;border-radius:15rpx;background:#F4FBF7;color:#08A152;text-align:center}.proof image{width:100%;height:100%}.proof text,.proof small{display:block}.proof text{font-size:22px}.proof small{font-size:13px}.shipment-tip{margin:12rpx 0 18rpx;color:var(--tm-ink-500);font-size:13px;line-height:1.5}.service-note{margin-top:20rpx;padding:19rpx;border-radius:14rpx;background:#FFF2E5;color:#B45309;font-size:13px;line-height:1.55}
.state-actions{display:grid;grid-template-columns:1fr 1fr;gap:14rpx;margin-top:20rpx}.state-actions button{height:68rpx;margin:0;font-size:14px}.state-actions .tm-primary-button{grid-column:1/-1;margin-top:0}.tm-secondary-button,.state-home-button{border:1rpx solid var(--tm-line);border-radius:14rpx;background:#fff;color:var(--tm-ink-900)}
.proof.is-uploading{pointer-events:none;opacity:.72}.upload-spinner{letter-spacing:8rpx;animation:proof-pulse 1s ease-in-out infinite}@keyframes proof-pulse{50%{opacity:.35}}
/* V13 green after-sales surface */
.page{background:#F4F6F4;color:#191B1F}
.status-hero{border:1rpx solid transparent;background:linear-gradient(145deg,#12D978,#08A152);box-shadow:0 14rpx 34rpx rgba(11,191,99,.26)}
.status-mark{border:0;background:rgba(255,255,255,.18);color:#fff}
.summary,.shipment,.state-card{border:1rpx solid var(--tm-line);box-shadow:var(--tm-shadow-sm);background:var(--tm-surface)}
.summary{background:var(--tm-surface)}.shipment:nth-of-type(odd){background:var(--tm-surface-soft)}
.input{border:1rpx solid #EDEFEF;background:#fff}.proof{border:1rpx dashed #9FD8B6;background:#F4FBF7}
.service-note{border:1rpx solid rgba(242,98,10,.18);background:#FFF2E5}
.tm-primary-button{border:1rpx solid transparent;border-radius:999rpx;background:linear-gradient(135deg,#12D978,#08A152);box-shadow:0 12rpx 28rpx rgba(11,191,99,.22);color:#fff}.tm-primary-button::after{border:0}.tm-primary-button[disabled]{opacity:.46;filter:saturate(.55)}
.error-card{border-color:rgba(255,59,48,.2);background:#FFEEEA}.error-card .state-title{color:#FF3B30}
.page{padding-bottom:180rpx}
.timeline{margin-top:22rpx;padding:26rpx}.section-title{position:relative;padding-left:20rpx}.section-title::before{content:'';position:absolute;left:0;top:50%;transform:translateY(-50%);width:8rpx;height:30rpx;border-radius:4rpx;background:#0BBF63}.tl-li{display:flex;gap:18rpx}.tl-rail{display:flex;flex-direction:column;align-items:center;width:28rpx;flex:none}.tl-rail .dot{width:20rpx;height:20rpx;margin-top:6rpx;border-radius:50%;background:#DDE3DE;border:4rpx solid #EEF1EE}.tl-rail .line{width:4rpx;flex:1;min-height:48rpx;margin:4rpx 0;background:#E7EBE7}.tl-body{padding-bottom:28rpx}.tl-li.last .tl-body{padding-bottom:0}.tl-title,.tl-desc{display:block}.tl-title{font-size:14px;font-weight:900;color:#AEB3BB}.tl-desc{margin-top:6rpx;color:#AEB3BB;font-size:12px;line-height:1.5}
.tl-li.done .dot{background:#0BBF63;border-color:#E7F9EF}.tl-li.done .line{background:#CDE8D2}.tl-li.done .tl-title{color:#191B1F}
.tl-li.active .dot{background:#fff;border-color:#0BBF63;box-shadow:0 0 0 6rpx #E7F9EF}.tl-li.active .tl-title{color:#08A152}
.tl-li.rejected .dot{background:#FF3B30;border-color:#FFE3E1}.tl-li.rejected .tl-title{color:#FF3B30}
.tl-li.cancelled .dot{background:#AEB3BB;border-color:#EEF1EE}.tl-li.cancelled .tl-title{color:#767C85}
.detail-action-bar{position:fixed;left:0;right:0;bottom:0;z-index:20;display:flex;gap:18rpx;padding:16rpx 24rpx calc(16rpx + env(safe-area-inset-bottom));border-top:1rpx solid #EDEFEF;background:rgba(255,255,255,.96);box-shadow:0 -6rpx 24rpx rgba(23,26,23,.05)}.detail-action-bar button{margin:0;height:84rpx;border-radius:999rpx;font-size:15px;font-weight:800;line-height:84rpx}.detail-action-bar .ab-secondary{flex:0 0 220rpx;border:1rpx solid transparent;background:#F2F4F3;color:#767C85}.detail-action-bar .ab-secondary::after{border:0}.detail-action-bar .ab-primary{flex:1;border:1rpx solid transparent;background:linear-gradient(135deg,#12D978,#08A152);box-shadow:0 12rpx 28rpx rgba(11,191,99,.22);color:#fff}.detail-action-bar .ab-primary::after{border:0}
</style>
