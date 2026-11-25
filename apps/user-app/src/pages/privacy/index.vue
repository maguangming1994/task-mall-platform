<template>
  <view class="page">
    <view class="settings-card">
      <view class="row" @click="openAccountSecurity">
        <text class="row-label">账号与安全</text>
        <view class="row-extra">
          <text class="row-value">{{ auth.user?.phone_masked || '未绑定手机号' }}</text>
          <text class="chevron">›</text>
        </view>
      </view>
      <view class="row" @click="openPersonalInfo">
        <text class="row-label">个人信息管理</text>
        <text class="chevron">›</text>
      </view>
      <view class="row" @click="openSystemPermission">
        <text class="row-label">权限管理</text>
        <text class="chevron">›</text>
      </view>
      <view class="row" @click="clearCache">
        <text class="row-label">清除缓存</text>
        <view class="row-extra">
          <text class="row-value">{{ cacheSizeText }}</text>
          <text class="chevron">›</text>
        </view>
      </view>
      <view class="row row-last" @click="showAbout">
        <text class="row-label">关于校圈</text>
        <view class="row-extra">
          <text class="row-value">{{ versionText }}</text>
          <text class="chevron">›</text>
        </view>
      </view>
    </view>

    <view class="logout-card" @click="confirmLogout">
      <text class="logout-text">退出当前账号</text>
    </view>

    <view v-if="loading && !requests.length && !eligibility" class="state-card" role="status" aria-live="polite">
      <text class="state-title">正在加载隐私权益…</text>
      <text class="state-copy">正在同步数据副本和账号状态。</text>
    </view>
    <view v-else-if="loadError && !requests.length && !eligibility" class="state-card" role="alert">
      <text class="state-title">隐私信息加载失败</text>
      <text class="state-copy">{{ loadError }}</text>
      <button class="retry-button" @click="load">重新加载</button>
    </view>

    <!-- 个人数据副本 -->
    <view class="card">
      <view class="card-title-row">
        <text class="card-title">个人数据副本</text>
        <button class="small-button" :disabled="copying" @click="createCopy">申请副本</button>
      </view>
      <text class="hint">副本采用私有受控下载，默认 7 天内有效。</text>
      <view v-for="item in requests" :key="item.id" class="record">
        <view class="record-main">
          <text class="record-title">{{ requestName(item.request_type) }}</text>
          <text class="record-meta">{{ statusName(item.status) }} · {{ formatDate(item.requested_at) }}</text>
        </view>
        <button v-if="item.result_file_id" class="link-button" @click="download(item.result_file_id)">下载</button>
      </view>
      <text v-if="!loading && !loadError && !requests.length" class="empty">暂无隐私请求</text>
    </view>

    <!-- 账号注销 -->
    <view class="card danger-card">
      <text class="card-title danger-title">注销账号</text>
      <text class="hint">注销后将清空账号支付、回收、消息等数据。申请前会检查未结订单、售后、退款、提现、争议、冻结资产和账户余额；默认冷静期 7 天，期间登录或主动撤销都会取消申请。</text>
      <view v-if="eligibility" class="eligibility" :class="{ blocked: !eligibility.eligible }">
        <text>{{ eligibility.eligible ? '当前满足申请条件' : '当前暂不能申请' }}</text>
        <text v-for="blocker in eligibility.blockers" :key="blocker" class="blocker">· {{ blockerName(blocker) }}</text>
      </view>
      <view v-if="cancellation?.status === 'cooling_off'" class="cooling">
        <text>注销冷静期进行中</text>
        <text>预计执行：{{ formatDate(cancellation.cooling_off_ends_at) }}</text>
        <button class="secondary" :disabled="withdrawing" @click="withdraw">撤销注销申请</button>
      </view>
      <button v-else class="danger-button" :disabled="cancelling || !eligibility?.eligible" @click="submitCancellation">申请注销账号</button>
    </view>

    <AppTabBar current-target="account" />
  </view>
</template>

<script setup lang="ts">
import { formatDateTime as formatDate } from '@/utils/dateTime'
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
const blockerNames: Record<string, string> = { ongoing_order: '存在进行中订单', ongoing_after_sale: '存在进行中售后', ongoing_refund: '存在进行中退款', ongoing_withdrawal: '存在进行中提现', active_dispute: '存在争议或法律冻结', frozen_assets: '存在冻结资产', wallet_balance_not_zero: '账户余额不为零' }

/* 清缓存时必须保留的键：登录会话、设备指纹、管理员会话、校区选择 */
const CACHE_KEEP_KEYS = ['task_mall_auth', 'task_mall_device_id', 'task_mall_admin_session', 'task_mall_selected_campus']
const cacheSizeText = ref('计算中…')
const versionText = getVersionText()

onShow(() => {
  void load()
  refreshCacheSize()
})

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

/* 账号与安全：当前仅开放实名认证入口 */
function openAccountSecurity() {
  uni.navigateTo({ url: '/pages/identity-verification/index' })
}

/* 个人信息管理：实名、数据副本 */
async function openPersonalInfo() {
  const action = await uni.showActionSheet({
    itemList: ['实名认证', '申请个人数据副本'],
  }).catch(() => null)
  if (!action) return
  if (action.tapIndex === 0) {
    uni.navigateTo({ url: '/pages/identity-verification/index' })
  } else {
    await createCopy()
  }
}

/* 跳系统设置管理相机/相册/通知等授权 */
function openSystemPermission() {
  // #ifdef MP-WEIXIN
  uni.openSetting({
    success: () => undefined,
    fail: () => uni.showToast({ title: '无法打开系统设置', icon: 'none' }),
  })
  // #endif
  // #ifndef MP-WEIXIN
  uni.showModal({
    title: '权限管理',
    content: '请在手机「设置 - 应用 - 校圈 - 权限」中管理相机、相册、通知等授权。',
    showCancel: false,
    confirmText: '知道了',
  })
  // #endif
}

function refreshCacheSize() {
  try {
    const info = uni.getStorageInfoSync()
    const kb = Number(info.currentSize || 0)
    cacheSizeText.value = kb >= 1024 ? `${(kb / 1024).toFixed(1)}MB` : `${kb}KB`
  } catch {
    cacheSizeText.value = '—'
  }
}

async function clearCache() {
  const confirmed = await uni.showModal({
    title: '清除缓存',
    content: '将清除本地图片缓存与临时数据，登录状态不会退出。',
    confirmText: '清除',
    confirmColor: '#08A152',
  }).catch(() => null)
  if (!confirmed?.confirm) return
  try {
    const info = uni.getStorageInfoSync()
    info.keys.forEach((key) => {
      if (!CACHE_KEEP_KEYS.includes(key)) {
        try { uni.removeStorageSync(key) } catch { /* 忽略单项失败 */ }
      }
    })
    refreshCacheSize()
    uni.showToast({ title: '缓存已清除', icon: 'success' })
  } catch {
    uni.showToast({ title: '清除失败，请稍后重试', icon: 'none' })
  }
}

/* 退出当前账号：便于切换账号或结束演示体验 */
async function confirmLogout() {
  const result = await uni.showModal({
    title: '退出账号',
    content: '确认退出当前登录账号？退出后需重新登录才能查看个人数据。',
    confirmText: '退出',
    confirmColor: '#E54D42',
  })
  if (!result.confirm) return
  await auth.logout()
  uni.reLaunch({ url: '/pages/index/index' })
}

function showAbout() {  uni.showModal({
    title: '关于校圈',
    content: `校圈 ${versionText}\n校园任务与闲置交易平台。`,
    showCancel: false,
    confirmText: '知道了',
  })
}

function getVersionText(): string {
  let version = 'v1.0.0'
  try {
    const system = uni.getSystemInfoSync()
    // #ifdef APP-PLUS
    if (system.appVersion) version = `v${system.appVersion}`
    // #endif
    // #ifdef MP-WEIXIN
    const accountInfo = uni.getAccountInfoSync?.()
    const mini = accountInfo?.miniProgram
    if (mini?.version) {
      version = `v${mini.version}`
    } else if (mini?.envVersion) {
      version = ({ develop: '开发版', trial: '体验版', release: '正式版' } as Record<string, string>)[mini.envVersion] || '正式版'
    }
    // #endif
  } catch { /* 使用默认值 */ }
  return version
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
function requestName(value: string) { return requestNames[value] || '隐私请求' }
function statusName(value: string) { return statusNames[value] || '状态处理中' }
function blockerName(value: string) { return blockerNames[value] || '存在待处理事项' }
function isTerminalFailure(error: unknown) { return error instanceof ApiError && error.statusCode >= 400 && error.statusCode < 500 && ![408, 409, 425, 429].includes(error.statusCode) }
</script>

<style scoped>
.page { min-height: 100vh; padding: 24rpx 24rpx 80rpx; box-sizing: border-box; background: #F4F6F4; }

.settings-card { border-radius: 24rpx; background: #fff; box-shadow: 0 2rpx 4rpx rgba(23,26,23,.03), 0 12rpx 40rpx rgba(23,26,23,.05); overflow: hidden; }
.logout-card { margin-top: 20rpx; border-radius: 24rpx; background: #fff; box-shadow: 0 2rpx 4rpx rgba(23,26,23,.03), 0 12rpx 40rpx rgba(23,26,23,.05); }
.logout-text { display: block; padding: 32rpx; text-align: center; color: #E54D42; font-size: 28rpx; font-weight: 600; }.row { display: flex; align-items: center; justify-content: space-between; padding: 32rpx 28rpx; border-bottom: 1rpx solid #F2F4F3; }
.row-last { border-bottom: 0; }
.row-label { color: #191B1F; font-size: 28rpx; font-weight: 600; }
.row-extra { display: flex; align-items: center; gap: 8rpx; }
.row-value { color: #AEB3BB; font-size: 24rpx; }
.chevron { color: #C6CBc6; font-size: 36rpx; font-weight: 300; line-height: 1; }

.card { margin-top: 20rpx; padding: 32rpx 28rpx; border-radius: 24rpx; background: #fff; box-shadow: 0 2rpx 4rpx rgba(23,26,23,.03), 0 12rpx 40rpx rgba(23,26,23,.05); }
.card-title-row { display: flex; align-items: center; justify-content: space-between; }
.card-title { font-size: 30rpx; font-weight: 700; color: #191B1F; }
.hint { display: block; margin-top: 12rpx; color: #767C85; font-size: 24rpx; line-height: 1.65; }
.small-button, .link-button { margin: 0; border: 0; border-radius: 999rpx; padding: 0 24rpx; background: #E7F9EF; color: #08A152; font-size: 24rpx; line-height: 60rpx; }
.small-button::after, .link-button::after { border: 0 }
.record { display: flex; align-items: center; justify-content: space-between; margin-top: 22rpx; padding-top: 22rpx; border-top: 1rpx solid #F2F4F3; }
.record-main { flex: 1; min-width: 0; }
.record-title { display: block; font-size: 26rpx; color: #191B1F; }
.record-meta, .empty { display: block; margin-top: 6rpx; color: #AEB3BB; font-size: 22rpx; }
.danger-card { border: 1rpx solid rgba(255,59,48,.16); }
.danger-title { color: #E54D42; }
.eligibility { margin-top: 22rpx; padding: 20rpx; border-radius: 16rpx; background: #E7F9EF; color: #08A152; font-size: 24rpx; }
.eligibility.blocked { background: #FFF2E5; color: #B45309; }
.blocker { display: block; margin-top: 6rpx; }
.danger-button, .secondary { margin-top: 24rpx; border: 0; border-radius: 999rpx; font-size: 28rpx; }
.danger-button { background: #FF3B30; color: #fff; }
.danger-button[disabled] { background: #DDE3DE; color: #fff; }
.secondary { background: #F2F4F3; color: #4B515A; }
.cooling { margin-top: 22rpx; padding: 22rpx; border-radius: 18rpx; background: #FFF2E5; color: #B45309; font-size: 24rpx; }
.cooling text { display: block; margin-top: 6rpx; }

.state-card { margin-top: 20rpx; padding: 40rpx 28rpx; text-align: center; border: 1rpx solid #EDEFEF; border-radius: 24rpx; background: #fff; box-shadow: 0 12rpx 40rpx rgba(23,26,23,.05); }
.state-title, .state-copy { display: block; }
.state-title { color: #191B1F; font-size: 26rpx; font-weight: 800; }
.state-copy { margin-top: 8rpx; color: #767C85; font-size: 22rpx; line-height: 1.5; }
.retry-button { height: 64rpx; margin: 20rpx auto 0; padding: 0 24rpx; border: 0; border-radius: 999rpx; background: linear-gradient(135deg,#12D978,#08A152); color: #fff; font-size: 24rpx; line-height: 64rpx; }
.retry-button::after { border: 0; }
</style>
