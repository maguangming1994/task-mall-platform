<template>
  <view class="page">
    <view class="heading">
      <text class="eyebrow">PRIVACY & ACCOUNT</text>
      <text class="title">隐私与账号注销</text>
      <text class="subtitle">查询个人数据副本、查看处理记录，并在满足条件时申请注销。</text>
    </view>

    <view class="card">
      <view class="card-title-row"><text class="card-title">个人数据副本</text><button class="small-button" :loading="copying" @click="createCopy">申请副本</button></view>
      <text class="hint">副本采用私有受控下载，默认 7 天内有效。</text>
      <view v-for="item in requests" :key="item.id" class="record">
        <view><text class="record-title">{{ requestNames[item.request_type] }}</text><text class="record-meta">{{ statusNames[item.status] || item.status }} · {{ formatDate(item.requested_at) }}</text></view>
        <button v-if="item.result_file_id" class="link-button" @click="download(item.result_file_id)">下载</button>
      </view>
      <text v-if="!requests.length" class="empty">暂无隐私请求</text>
    </view>

    <view class="card danger-card">
      <text class="card-title">账号注销</text>
      <text class="hint">申请前会检查未结订单、售后、退款、提现、争议、冻结资产和钱包余额。默认冷静期 7 天，期间登录或主动撤销都会取消申请。</text>
      <view v-if="eligibility" class="eligibility" :class="{ blocked: !eligibility.eligible }">
        <text>{{ eligibility.eligible ? '当前满足申请条件' : '当前暂不能申请' }}</text>
        <text v-for="blocker in eligibility.blockers" :key="blocker" class="blocker">· {{ blockerNames[blocker] || blocker }}</text>
      </view>
      <view v-if="cancellation?.status === 'cooling_off'" class="cooling">
        <text>注销冷静期进行中</text><text>预计执行：{{ formatDate(cancellation.cooling_off_ends_at) }}</text>
        <button class="secondary" @click="withdraw">撤销注销申请</button>
      </view>
      <button v-else class="danger-button" :disabled="!eligibility?.eligible" @click="submitCancellation">申请注销账号</button>
    </view>
  </view>
</template>

<script setup lang="ts">
import { onShow } from '@dcloudio/uni-app'
import { ref } from 'vue'
import { createPrivacyCopy, downloadPrivacyExport, listPrivacyRequests, loadCancellation, loadCancellationEligibility, requestCancellation, withdrawCancellation, type CancellationEligibility, type CancellationRequest, type PrivacyRequestItem } from '@/api/privacy'
import { ApiError } from '@/api/http'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const requests = ref<PrivacyRequestItem[]>([])
const eligibility = ref<CancellationEligibility | null>(null)
const cancellation = ref<CancellationRequest | null>(null)
const copying = ref(false)
const requestNames: Record<string, string> = { access: '查询请求', copy: '个人数据副本', correction: '更正请求', deletion: '删除请求', withdraw_consent: '撤回授权' }
const statusNames: Record<string, string> = { submitted: '已提交', processing: '处理中', completed: '已完成', verifying: '待核验', rejected: '已拒绝', cooling_off: '冷静期' }
const blockerNames: Record<string, string> = { ongoing_order: '存在进行中订单', ongoing_after_sale: '存在进行中售后', ongoing_refund: '存在进行中退款', ongoing_withdrawal: '存在进行中提现', active_dispute: '存在争议或法律冻结', frozen_assets: '存在冻结资产', wallet_balance_not_zero: '钱包余额不为零' }

onShow(load)
async function load() {
  if (!auth.accessToken) { uni.redirectTo({ url: '/pages/login/index' }); return }
  try {
    const [requestData, eligibilityData, cancellationData] = await Promise.all([listPrivacyRequests(auth.accessToken), loadCancellationEligibility(auth.accessToken), loadCancellation(auth.accessToken)])
    requests.value = requestData.items; eligibility.value = eligibilityData; cancellation.value = cancellationData
  } catch (error) { handleError(error) }
}
async function createCopy() {
  copying.value = true
  try { await createPrivacyCopy(auth.accessToken); uni.showToast({ title: '副本已生成', icon: 'success' }); await load() } catch (error) { handleError(error) } finally { copying.value = false }
}
async function download(fileId: string) {
  try { const path = await downloadPrivacyExport(auth.accessToken, fileId); uni.openDocument({ filePath: path, showMenu: true }) } catch { uni.showToast({ title: '下载失败', icon: 'none' }) }
}
async function submitCancellation() {
  const result = await uni.showModal({ title: '确认申请注销？', content: '冷静期结束后将再次检查资格。注销生效后普通资料会匿名化，剩余积分和优惠券不补偿，历史交易与账务按法定期限保留。', confirmText: '确认申请', confirmColor: '#d92d20' })
  if (!result.confirm) return
  try { cancellation.value = await requestCancellation(auth.accessToken); uni.showToast({ title: '已进入冷静期', icon: 'success' }) } catch (error) { handleError(error) }
}
async function withdraw() {
  try { await withdrawCancellation(auth.accessToken); cancellation.value = null; uni.showToast({ title: '申请已撤销', icon: 'success' }) } catch (error) { handleError(error) }
}
function handleError(error: unknown) { uni.showToast({ title: error instanceof ApiError ? error.message : '请求失败', icon: 'none' }) }
function formatDate(value: string) { return new Date(value).toLocaleString() }
</script>

<style scoped>
.page { min-height: 100vh; padding: 36rpx 28rpx 80rpx; box-sizing: border-box; background: #f5f7fb; }
.heading { padding: 34rpx 10rpx; }.eyebrow,.title,.subtitle,.card-title,.hint,.record-title,.record-meta,.empty,.cooling text { display:block; }.eyebrow{font-size:22rpx;color:#526fe8;letter-spacing:2rpx}.title{margin-top:14rpx;font-size:42rpx;font-weight:700}.subtitle{margin-top:12rpx;color:#667085;font-size:26rpx;line-height:1.6}
.card{margin-top:22rpx;padding:32rpx;border-radius:28rpx;background:#fff;box-shadow:0 10rpx 30rpx rgba(15,23,42,.05)}.card-title-row{display:flex;align-items:center;justify-content:space-between}.card-title{font-size:32rpx;font-weight:700}.hint{margin-top:12rpx;color:#667085;font-size:24rpx;line-height:1.65}.small-button,.link-button{margin:0;border:0;background:#eef3ff;color:#3157d5;font-size:24rpx}.small-button::after,.link-button::after{border:0}.record{display:flex;align-items:center;justify-content:space-between;margin-top:22rpx;padding-top:22rpx;border-top:1rpx solid #edf0f5}.record-title{font-size:27rpx}.record-meta,.empty{margin-top:6rpx;color:#98a2b3;font-size:22rpx}.danger-card{border:1rpx solid #fee4e2}.eligibility{margin-top:22rpx;padding:20rpx;border-radius:16rpx;background:#ecfdf3;color:#067647}.eligibility.blocked{background:#fff4ed;color:#b93815}.blocker{display:block;margin-top:6rpx}.danger-button,.secondary{margin-top:24rpx;border:0;border-radius:18rpx}.danger-button{background:#d92d20;color:#fff}.danger-button[disabled]{background:#d0d5dd;color:#fff}.secondary{background:#f2f4f7;color:#344054}.cooling{margin-top:22rpx;padding:22rpx;border-radius:18rpx;background:#fffaeb;color:#b54708}.cooling text+text{margin-top:8rpx}
</style>
