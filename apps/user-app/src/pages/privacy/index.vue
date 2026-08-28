<template>
  <view class="page">
    <view class="heading">
      <text class="eyebrow">PRIVACY & ACCOUNT</text>
      <text class="title">隐私与账号注销</text>
      <text class="subtitle">查询个人数据副本、查看处理记录，并在满足条件时申请注销。</text>
    </view>

    <view v-if="loading && !requests.length && !eligibility" class="state-card loading-state" role="status" aria-live="polite"><text class="state-title">正在加载隐私权益…</text><text class="state-copy">正在同步数据副本和账号状态。</text></view>
    <view v-else-if="loadError && !requests.length && !eligibility" class="state-card error-state" role="alert"><text class="state-title">隐私信息加载失败</text><text class="state-copy">{{ loadError }}</text><button class="retry-button" @click="load">重新加载</button></view>

    <view class="card">
      <view class="card-title-row"><text class="card-title">个人数据副本</text><button class="small-button" :loading="copying" :disabled="copying" @click="createCopy">申请副本</button></view>
      <text class="hint">副本采用私有受控下载，默认 7 天内有效。</text>
      <view v-for="item in requests" :key="item.id" class="record">
        <view><text class="record-title">{{ requestName(item.request_type) }}</text><text class="record-meta">{{ statusName(item.status) }} · {{ formatDate(item.requested_at) }}</text></view>
        <button v-if="item.result_file_id" class="link-button" @click="download(item.result_file_id)">下载</button>
      </view>
      <text v-if="!loading && !loadError && !requests.length" class="empty">暂无隐私请求</text>
    </view>

    <view class="card danger-card">
      <text class="card-title">账号注销</text>
      <text class="hint">申请前会检查未结订单、售后、退款、提现、争议、冻结资产和钱包余额。默认冷静期 7 天，期间登录或主动撤销都会取消申请。</text>
      <view v-if="eligibility" class="eligibility" :class="{ blocked: !eligibility.eligible }">
        <text>{{ eligibility.eligible ? '当前满足申请条件' : '当前暂不能申请' }}</text>
        <text v-for="blocker in eligibility.blockers" :key="blocker" class="blocker">· {{ blockerName(blocker) }}</text>
      </view>
      <view v-if="cancellation?.status === 'cooling_off'" class="cooling">
        <text>注销冷静期进行中</text><text>预计执行：{{ formatDate(cancellation.cooling_off_ends_at) }}</text>
        <button class="secondary" :loading="withdrawing" :disabled="withdrawing" @click="withdraw">撤销注销申请</button>
      </view>
      <button v-else class="danger-button" :loading="cancelling" :disabled="cancelling || !eligibility?.eligible" @click="submitCancellation">申请注销账号</button>
    </view>
    <AppTabBar current-target="account" />
  </view>
</template>

<script setup lang="ts">
import { onShow } from '@dcloudio/uni-app'
import { ref } from 'vue'
import { createPrivacyCopy, createPrivacyIdempotencyKey, downloadPrivacyExport, listPrivacyRequests, loadCancellation, loadCancellationEligibility, requestCancellation, withdrawCancellation, type CancellationEligibility, type CancellationRequest, type PrivacyRequestItem } from '@/api/privacy'
import { ApiError } from '@/api/http'
import AppTabBar from '@/components/AppTabBar.vue'
import { useAuthStore } from '@/stores/auth'
import { redirectToLogin } from '@/utils/authRedirect'

const auth = useAuthStore()
const requests = ref<PrivacyRequestItem[]>([])
const eligibility = ref<CancellationEligibility | null>(null)
const cancellation = ref<CancellationRequest | null>(null)
const copying = ref(false)
const cancelling = ref(false)
const withdrawing = ref(false)
const loading = ref(false)
const loadError = ref('')
const copyIdempotencyKey = ref<string | null>(null)
const cancellationIdempotencyKey = ref<string | null>(null)
const requestNames: Record<string, string> = { access: '查询请求', copy: '个人数据副本', correction: '更正请求', deletion: '删除请求', withdraw_consent: '撤回授权' }
const statusNames: Record<string, string> = { submitted: '已提交', processing: '处理中', completed: '已完成', verifying: '待核验', rejected: '已拒绝', cooling_off: '冷静期' }
const blockerNames: Record<string, string> = { ongoing_order: '存在进行中订单', ongoing_after_sale: '存在进行中售后', ongoing_refund: '存在进行中退款', ongoing_withdrawal: '存在进行中提现', active_dispute: '存在争议或法律冻结', frozen_assets: '存在冻结资产', wallet_balance_not_zero: '钱包余额不为零' }

onShow(load)
async function load() {
  if (!auth.isAuthenticated) { redirectToLogin(); return }
  loading.value = true
  loadError.value = ''
  try {
    const [requestData, eligibilityData, cancellationData] = await Promise.all([listPrivacyRequests(auth.accessToken), loadCancellationEligibility(auth.accessToken), loadCancellation(auth.accessToken)])
    requests.value = requestData.items; eligibility.value = eligibilityData; cancellation.value = cancellationData
  } catch (error) { loadError.value = error instanceof Error ? error.message : '请检查网络后重试'; handleError(error) }
  finally { loading.value = false }
}
async function createCopy() {
  if (copying.value) return
  copying.value = true
  if (!copyIdempotencyKey.value) copyIdempotencyKey.value = createPrivacyIdempotencyKey('privacy-copy')
  const idempotencyKey = copyIdempotencyKey.value
  try {
    await createPrivacyCopy(auth.accessToken, idempotencyKey)
    copyIdempotencyKey.value = null
    uni.showToast({ title: '副本申请已提交', icon: 'success' })
    await load()
  } catch (error) {
    if (isTerminalFailure(error)) copyIdempotencyKey.value = null
    handleError(error)
  } finally { copying.value = false }
}
async function download(fileId: string) {
  try { const path = await downloadPrivacyExport(auth.accessToken, fileId); uni.openDocument({ filePath: path, showMenu: true }) } catch { uni.showToast({ title: '下载失败', icon: 'none' }) }
}
async function submitCancellation() {
  if (cancelling.value || !eligibility.value?.eligible) return
  cancelling.value = true
  try {
    const result = await uni.showModal({ title: '确认申请注销？', content: '冷静期结束后将再次检查资格。注销生效后普通资料会匿名化，剩余积分和优惠券不补偿，历史交易与账务按法定期限保留。', confirmText: '确认申请', confirmColor: '#d92d20' })
    if (!result.confirm) { cancellationIdempotencyKey.value = null; return }
    if (!cancellationIdempotencyKey.value) cancellationIdempotencyKey.value = createPrivacyIdempotencyKey('account-cancel')
    const idempotencyKey = cancellationIdempotencyKey.value
    cancellation.value = await requestCancellation(auth.accessToken, idempotencyKey)
    cancellationIdempotencyKey.value = null
    uni.showToast({ title: '已进入冷静期', icon: 'success' })
  } catch (error) {
    if (isTerminalFailure(error)) cancellationIdempotencyKey.value = null
    handleError(error)
  } finally { cancelling.value = false }
}
async function withdraw() {
  if (withdrawing.value) return
  withdrawing.value = true
  try { await withdrawCancellation(auth.accessToken); cancellation.value = null; uni.showToast({ title: '申请已撤销', icon: 'success' }) } catch (error) { handleError(error) }
  finally { withdrawing.value = false }
}
function handleError(error: unknown) { uni.showToast({ title: error instanceof ApiError ? error.message : '请求失败', icon: 'none' }) }
function formatDate(value: string) { return new Date(value).toLocaleString() }
function requestName(value: string) { return requestNames[value] || '隐私请求' }
function statusName(value: string) { return statusNames[value] || '状态处理中' }
function blockerName(value: string) { return blockerNames[value] || '存在待处理事项' }
function isTerminalFailure(error: unknown) { return error instanceof ApiError && error.statusCode >= 400 && error.statusCode < 500 && ![408, 409, 425, 429].includes(error.statusCode) }
</script>

<style scoped>
.page { min-height: 100vh; padding: 36rpx 28rpx 80rpx; box-sizing: border-box; background: #f5f7fb; }
.heading { padding: 34rpx 10rpx; }.eyebrow,.title,.subtitle,.card-title,.hint,.record-title,.record-meta,.empty,.cooling text { display:block; }.eyebrow{font-size:14px;color:#526fe8;letter-spacing:2rpx}.title{margin-top:14rpx;font-size:24px;font-weight:700}.subtitle{margin-top:12rpx;color:#667085;font-size:15px;line-height:1.6}
.card{margin-top:22rpx;padding:32rpx;border-radius:28rpx;background:#fff;box-shadow:0 10rpx 30rpx rgba(15,23,42,.05)}.card-title-row{display:flex;align-items:center;justify-content:space-between}.card-title{font-size:19px;font-weight:700}.hint{margin-top:12rpx;color:#667085;font-size:15px;line-height:1.65}.small-button,.link-button{margin:0;border:0;background:#eef3ff;color:#3157d5;font-size:15px}.small-button::after,.link-button::after{border:0}.record{display:flex;align-items:center;justify-content:space-between;margin-top:22rpx;padding-top:22rpx;border-top:1rpx solid #edf0f5}.record-title{font-size:17px}.record-meta,.empty{margin-top:6rpx;color:#98a2b3;font-size:14px}.danger-card{border:1rpx solid #fee4e2}.eligibility{margin-top:22rpx;padding:20rpx;border-radius:16rpx;background:#ecfdf3;color:#067647}.eligibility.blocked{background:#fff4ed;color:#b93815}.blocker{display:block;margin-top:6rpx}.danger-button,.secondary{margin-top:24rpx;border:0;border-radius:18rpx}.danger-button{background:#d92d20;color:#fff}.danger-button[disabled]{background:#d0d5dd;color:#fff}.secondary{background:#f2f4f7;color:#344054}.cooling{margin-top:22rpx;padding:22rpx;border-radius:18rpx;background:#fffaeb;color:#b54708}.cooling text+text{margin-top:8rpx}
/* V09 privacy cards: 以浅蓝说明带和清晰的软卡片承载高风险操作。 */
.page{background:linear-gradient(180deg,#edfafa 0,var(--tm-canvas) 470rpx)}
.heading{margin-bottom:20rpx;padding:30rpx 26rpx;border:1rpx solid #cfe8e7;border-radius:28rpx;background:linear-gradient(135deg,#e1f8f5,#eaf5ff);box-shadow:0 12rpx 30rpx rgba(34,107,113,.08)}
.eyebrow,.title{color:#1c4d59}.subtitle{color:#617780;font-weight:550}
.card{border:1rpx solid #d6e6e8;box-shadow:0 12rpx 28rpx rgba(25,72,89,.07)}.card:first-of-type{background:#fff}
.small-button,.link-button,.secondary{border:1rpx solid #bfe1dc;background:#e9f8f3;box-shadow:none;color:#197b72}.danger-card{border:1rpx solid #f0cfc8;background:#fff7f3}
.eligibility,.cooling{border:0}.danger-button{border:0;box-shadow:0 10rpx 20rpx rgba(206,78,71,.15)}
.state-card{margin-top:20rpx;padding:40rpx 28rpx;text-align:center;border:1rpx solid #d6e6e8;border-radius:24rpx;background:#fff;box-shadow:0 10rpx 24rpx rgba(25,72,89,.06)}.state-title,.state-copy{display:block}.state-title{color:#214d58;font-size:15px;font-weight:850}.state-copy{margin-top:8rpx;color:#72858c;font-size:13px;line-height:1.5}.retry-button{height:64rpx;margin:20rpx auto 0;padding:0 24rpx;border:0;border-radius:16rpx;background:#2a9e98;color:#fff;font-size:13px;line-height:64rpx}.retry-button::after{border:0}
</style>
