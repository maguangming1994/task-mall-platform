<template>
  <view class="page">
    <view class="account-toolbar">
      <text>账户中心</text>
    </view>

    <view v-if="resolvingAccount" class="account-state account-loading" aria-live="polite">
      <view class="state-mark loading-mark">···</view>
      <text class="state-title">正在确认登录状态</text>
      <text class="state-copy">请稍候</text>
    </view>

    <view v-else-if="!hasCompletedLogin" class="guest-account" role="button" tabindex="0" @click="goLogin">
      <image class="guest-account-art" src="/static/home/hero-campus-reward.png" mode="aspectFill" />
      <view class="guest-account-shade" />
      <view class="guest-account-content">
        <view class="state-mark">未</view>
        <text class="guest-kicker">ACCOUNT ACCESS</text>
        <text class="guest-title">未登录</text>
        <text class="guest-copy">登录后查看任务进度、校园钱包、积分和订单。</text>
        <button class="guest-login-button" @click.stop="goLogin">{{ guestActionText }}</button>
      </view>
    </view>

    <template v-else>
    <view class="account-hero">
      <image class="account-hero-art" src="/static/home/hero-campus-reward.png" mode="aspectFill" />
      <view class="hero-profile">
        <!-- #ifdef MP-WEIXIN -->
        <button class="profile-mark profile-avatar-control" open-type="chooseAvatar" :disabled="uploading" aria-label="更新头像" @click="chooseLegacyAvatar" @chooseavatar="onChooseWechatAvatar">
          <image v-if="displayAvatarUrl" :src="displayAvatarUrl" mode="aspectFill" @error="clearBrokenAvatar" />
          <text v-else>{{ uploading ? '···' : (auth.user?.nickname || '我').slice(0, 1) }}</text>
        </button>
        <!-- #endif -->
        <!-- #ifndef MP-WEIXIN -->
        <button class="profile-mark profile-avatar-control" :disabled="uploading" aria-label="更新头像" @click="chooseFallbackAvatar">
          <image v-if="displayAvatarUrl" :src="displayAvatarUrl" mode="aspectFill" @error="clearBrokenAvatar" />
          <text v-else>{{ uploading ? '···' : (auth.user?.nickname || '我').slice(0, 1) }}</text>
        </button>
        <!-- #endif -->
        <view class="profile-copy">
          <text class="profile-badge">CAMPUS · {{ memberLevel }}</text>
          <!-- #ifdef MP-WEIXIN -->
          <input v-model="nickname" class="profile-nickname" type="nickname" maxlength="80" confirm-type="done" :disabled="savingNickname" placeholder="设置昵称" @change="onNicknameChange" @nicknamereview="onNicknameReview" />
          <!-- #endif -->
          <!-- #ifndef MP-WEIXIN -->
          <input v-model="nickname" class="profile-nickname" type="text" maxlength="80" confirm-type="done" :disabled="savingNickname" placeholder="设置昵称" @change="onNicknameChange" />
          <!-- #endif -->
          <text class="subtitle">管理校园身份与个人权益</text>
        </view>
      </view>
      <view class="hero-meta">
        <view class="completion-block">
          <view class="completion-copy"><strong>{{ profileCompletion }}%</strong><text>资料完整度</text></view>
          <view class="profile-progress"><view :style="{ width: `${profileCompletion}%` }" /></view>
        </view>
        <view class="identity-block"><view class="identity-symbol">★</view><view><strong>{{ memberLevel }}</strong><text>当前身份</text></view></view>
        <view class="identity-block"><view class="identity-symbol status">✓</view><view><strong>正常</strong><text>账户状态</text></view></view>
      </view>
    </view>

    <view v-if="modules.isEnabled('module_wallet')" class="wallet-layout">
      <view class="wallet-main" @click="openAssets">
        <view class="wallet-head"><text>校园钱包</text><text>资产明细 ›</text></view>
        <strong>¥{{ money(assets?.wallet.available_amount ?? 0) }}</strong>
        <text class="wallet-caption">可用余额 · 永久有效</text>
        <view class="wallet-action-row">
          <button v-if="modules.isEnabled('module_wallet_recharge')" @click.stop="openRecharge">余额充值</button>
          <text>支持卡密、线上与线下充值</text>
        </view>
      </view>
      <view class="asset-side">
        <view class="asset-mini points" @click="openAssets"><view><text>积分</text><small>任务与成长奖励</small></view><strong>{{ assets?.points.available ?? 0 }}</strong></view>
        <view class="asset-mini coupons" @click="openAssets"><view><text>优惠券</text><small>下单时可用</small></view><strong>{{ assets?.coupons.available ?? 0 }} 张</strong></view>
      </view>
    </view>

    <view class="growth-card">
      <view class="growth-copy"><text class="section-kicker">THIS WEEK</text><strong>校园成长周报</strong><small>任务、社区与兑换记录持续累计</small></view>
      <view class="growth-target"><strong>{{ completedGoal }}</strong><text>本周目标</text></view>
      <view class="growth-pulse"><view :style="{ width: `${Math.max(12, profileCompletion)}%` }" /></view>
    </view>

    <view v-if="modules.isEnabled('module_mall_trade')" class="order-card">
      <view class="section-row">
        <view><text class="section-kicker">CAMPUS ORDERS</text><text class="section-title">我的订单</text></view>
        <text class="section-note" @click="openOrders()">全部订单 ›</text>
      </view>
      <view class="order-status-grid">
        <view v-for="item in orderStatuses" :key="item.code" class="order-status-item" @click="item.action">
          <view :class="['order-mark', `tone-${item.tone}`]"><image class="mark-icon" :src="item.iconSrc" mode="aspectFit" /></view>
          <strong>{{ item.count }}</strong>
          <text>{{ item.displayLabel }}</text>
        </view>
      </view>
    </view>

    <view v-if="modules.isEnabled('module_community')" class="community-services">
      <view class="section-row">
        <view><text class="section-kicker">CAMPUS COMMUNITY</text><text class="section-title">校园社区</text></view>
        <text class="section-note" @click="openCommunity()">进入社区 ›</text>
      </view>
      <view class="community-grid">
        <view v-for="item in communityServices" :key="item.code" class="community-item" @click="item.action">
          <view :class="['service-icon', `tone-${item.tone}`]"><image class="mark-icon" :src="item.iconSrc" mode="aspectFit" /></view>
          <text class="service-label">{{ item.displayLabel }}</text>
          <text class="service-note">{{ item.note }}</text>
        </view>
      </view>
    </view>

    <view class="quick-services">
      <view class="section-row"><view><text class="section-kicker">QUICK ACCESS</text><text class="section-title">常用服务</text></view><text class="section-note">账户与校园服务</text></view>
      <view class="quick-grid">
        <view v-for="item in services" :key="item.id" class="quick-card" @click="item.action">
          <view :class="['service-icon', `tone-${item.tone}`]"><image :class="['mark-icon', { 'mark-icon-fill': item.icon_url }]" :src="item.iconSrc" mode="aspectFit" /></view>
          <view><text class="service-label">{{ item.displayLabel }}</text><text class="service-note">{{ item.note }}</text></view>
        </view>
      </view>
    </view>

    <button class="logout" @click="logout">退出当前账号</button>
    </template>
    <AppTabBar current-target="account" variant="flat" />
  </view>
</template>

<script setup lang="ts">
import { onShow } from '@dcloudio/uni-app'
import { computed, ref } from 'vue'
import AppTabBar from '@/components/AppTabBar.vue'
import { loadAssetOverview, updateProfile, type AssetOverview } from '@/api/account'
import { loadAppearanceContent, type AccountAppearance } from '@/api/appearance'
import { resolveApiAssetUrl } from '@/api/http'
import { chooseAndUploadAvatar, uploadAvatarFromPath } from '@/api/upload'
import { useAuthStore } from '@/stores/auth'
import { useModulesStore } from '@/stores/modules'
import { useIconConfigStore } from '@/stores/icons'
import type { ModuleCode } from '@/platform/modules'
import { navigateToLogin } from '@/utils/authRedirect'

declare const wx: { canIUse(schema: string): boolean }

const auth = useAuthStore()
const modules = useModulesStore()
const icons = useIconConfigStore()
const nickname = ref(auth.user?.nickname ?? '')
const avatarFileId = ref(auth.user?.avatar_file_id ?? '')
const avatarPreviewUrl = ref('')
const avatarLoadFailed = ref(false)
const assets = ref<AssetOverview | null>(null)
const savingNickname = ref(false)
const uploading = ref(false)
const accountAppearance = ref<AccountAppearance>({ profile_icon_url: '', service_icons: [] })
const resolvingAccount = ref(Boolean(auth.accessToken))
let pendingReviewedNickname: string | null = null
let supportsNicknameReview = false
let supportsChooseAvatar = false
// #ifdef MP-WEIXIN
supportsNicknameReview = wx.canIUse('input.bindnicknamereview')
supportsChooseAvatar = wx.canIUse('button.open-type.chooseAvatar')
// #endif
const hasCompletedLogin = computed(() => Boolean(auth.isAuthenticated && auth.user?.phone_bound))
const guestActionText = computed(() => auth.accessToken && auth.user && !auth.user.phone_bound ? '继续完成登录' : '登录 / 注册')
const displayAvatarUrl = computed(() => resolveApiAssetUrl(avatarPreviewUrl.value || (avatarLoadFailed.value
  ? ''
  : auth.user?.avatar_url || (!avatarFileId.value ? accountAppearance.value.profile_icon_url : ''))))
const profileCompletion = computed(() => Math.min(100, (auth.user?.nickname.trim() ? 35 : 0) + (avatarFileId.value ? 35 : 0) + (auth.user ? 30 : 0)))
const memberLevel = computed(() => (profileCompletion.value >= 100 ? '认证成员' : profileCompletion.value >= 65 ? '成长成员' : '新同学'))
const completedGoal = computed(() => `${Math.min(5, Math.floor((assets.value?.points.available ?? 0) / 500))}/5`)
const baseServices = [
  { id: 'tasks', mark: '任', label: '我的任务', note: '提交与进度', tone: 'blue', action: openTasks, icon: '/static/icons/service-tasks.svg' },
  { id: 'publisher', mark: '发', label: '发布任务', note: '创建与管理', tone: 'violet', action: openPublisher, icon: '/static/icons/module-task-publish.svg' },
  { id: 'orders', mark: '单', label: '我的订单', note: '支付与履约', tone: 'orange', action: () => openOrders(), icon: '/static/icons/service-orders.svg' },
  { id: 'second_hand', mark: '闲', label: '校园闲置', note: '买卖二手好物', tone: 'green', action: openSecondHand, icon: '/static/icons/service-tasks.svg' },
  { id: 'second_hand_orders', mark: '订', label: '闲置订单', note: '买卖交易记录', tone: 'orange', action: openSecondHandOrders, icon: '/static/icons/service-orders.svg' },
  { id: 'chat', mark: '聊', label: '消息聊天', note: '与同学沟通', tone: 'blue', action: openChat, icon: '/static/icons/service-messages.svg' },
  { id: 'after_sales', mark: '售', label: '售后服务', note: '退款与换货', tone: 'red', action: openAfterSales, icon: '/static/icons/service-aftersale.svg' },
  { id: 'referrals', mark: '邀', label: '邀请好友', note: '奖励与记录', tone: 'green', action: openReferrals, icon: '/static/icons/service-invite.svg' },
  { id: 'check_in', mark: '签', label: '每日签到', note: '连续签到领积分', tone: 'orange', action: openCheckIn, icon: '/static/icons/service-checkin.svg' },
  { id: 'notifications', mark: '信', label: '消息中心', note: '通知与提醒', tone: 'violet', action: openNotifications, icon: '/static/icons/service-messages.svg' },
  { id: 'addresses', mark: '址', label: '收货地址', note: '管理配送信息', tone: 'green', action: openAddresses, icon: '/static/icons/service-address.svg' },
  { id: 'legal', mark: '约', label: '协议隐私', note: '授权与版本', tone: 'slate', action: openLegal, icon: '/static/icons/service-privacy.svg' },
  { id: 'security', mark: '安', label: '账号安全', note: '换绑与注销', tone: 'blue', action: openPrivacy, icon: '/static/icons/service-security.svg' },
  { id: 'identity', mark: '证', label: '实名认证', note: '身份二要素核验', tone: 'green', action: openIdentityVerification, icon: '/static/icons/service-verify.svg' },
  { id: 'support', mark: '服', label: '联系客服', note: '扫码进入小程序', tone: 'green', action: openSupport, icon: '/static/icons/service-support.svg' },
]
const serviceModuleMap: Record<string, ModuleCode> = {
  tasks: 'module_tasks',
  publisher: 'module_task_publish',
  orders: 'module_mall_trade',
  after_sales: 'module_after_sale',
  referrals: 'module_referrals',
  check_in: 'module_checkin',
}
const services = computed(() => baseServices
  .filter((item) => item.id !== 'publisher' || auth.user?.can_publish_tasks === true)
  .filter((item) => {
    const code = serviceModuleMap[item.id]
    return !code || modules.isEnabled(code)
  })
  .map((item) => {
    const configured = accountAppearance.value.service_icons.find((icon) => icon.id === item.id)
    return {
      ...item,
      mark: configured?.mark || item.mark,
      icon_url: configured?.icon_url || '',
      iconSrc: configured?.icon_url || icons.resolve(item.icon),
      displayLabel: icons.label(item.label, item.icon),
    }
  }))
const orderStatuses = computed(() => [
  { code: 'order-pending', mark: '待', label: '待完成', count: 0, tone: 'blue', action: () => openOrders('pending'), icon: '/static/icons/order-pending.svg' },
  { code: 'order-review', mark: '审', label: '待审核', count: 0, tone: 'orange', action: () => openOrders('reviewing'), icon: '/static/icons/order-review.svg' },
  { code: 'order-completed', mark: '成', label: '已完成', count: 0, tone: 'green', action: () => openOrders('completed'), icon: '/static/icons/order-completed.svg' },
  { code: 'order-refund', mark: '售', label: '售后退款', count: 0, tone: 'red', action: openAfterSales, icon: '/static/icons/order-refund.svg' },
].map((item) => ({ ...item, iconSrc: icons.resolve(item.icon), displayLabel: icons.label(item.label, item.icon) })))
const communityServices = computed(() => [
  { code: 'community-post', mark: '发', label: '发布帖子', note: '分享校园生活', tone: 'green', action: () => openCommunityCompose('experience'), needPost: true, icon: '/static/icons/community-post.svg' },
  { code: 'community-group', mark: '团', label: '校园组团', note: '自习运动拼车', tone: 'orange', action: openActivities, needPost: false, icon: '/static/icons/community-group.svg' },
  { code: 'community-help', mark: '助', label: '有偿求助', note: '发布校园需求', tone: 'red', action: () => openCommunityCompose('question'), needPost: true, icon: '/static/icons/community-help.svg' },
  { code: 'community-study', mark: '学', label: '学习交流', note: '经验与攻略', tone: 'blue', action: () => openCommunity('guide'), needPost: false, icon: '/static/icons/community-study.svg' },
  { code: 'community-showcase', mark: '展', label: '成果展示', note: '作品与成长', tone: 'violet', action: () => openCommunity('showcase'), needPost: false, icon: '/static/icons/community-showcase.svg' },
  { code: 'community-qa', mark: '问', label: '互助问答', note: '同学来解答', tone: 'slate', action: () => openCommunity('question'), needPost: false, icon: '/static/icons/community-qa.svg' },
]
  .filter((item) => !item.needPost || modules.isEnabled('module_community_post'))
  .map((item) => ({ ...item, iconSrc: icons.resolve(item.icon), displayLabel: icons.label(item.label, item.icon) })))

onShow(load)

async function load() {
  void modules.ensureReady()
  resolvingAccount.value = Boolean(auth.accessToken)
  assets.value = null
  if (!auth.accessToken) return

  try {
    await auth.fetchCurrentUser()
    if (!hasCompletedLogin.value || !auth.accessToken) return
    const appearanceResult = await loadAppearanceContent()
    accountAppearance.value = appearanceResult.account
    nickname.value = auth.user?.nickname ?? ''
    avatarFileId.value = auth.user?.avatar_file_id ?? ''
    avatarPreviewUrl.value = ''
    avatarLoadFailed.value = false
    assets.value = await loadAssetOverview(auth.accessToken)
  } finally {
    resolvingAccount.value = false
  }
}

async function onChooseWechatAvatar(event: { detail?: { avatarUrl?: string } }) {
  const path = event.detail?.avatarUrl
  if (path) await uploadAndSaveAvatar(path)
}

async function chooseLegacyAvatar() {
  if (supportsChooseAvatar || uploading.value) return
  await chooseFallbackAvatar()
}

async function chooseFallbackAvatar() {
  uploading.value = true
  try {
    const fileId = await chooseAndUploadAvatar(auth.accessToken)
    await saveAvatarFile(fileId)
  } catch (error) {
    if (!isSelectionCancelled(error)) showError(error, '头像更新失败')
  } finally { uploading.value = false }
}

async function uploadAndSaveAvatar(path: string) {
  const previousPreview = avatarPreviewUrl.value
  const previousLoadFailed = avatarLoadFailed.value
  avatarPreviewUrl.value = path
  avatarLoadFailed.value = false
  uploading.value = true
  try {
    await saveAvatarFile(await uploadAvatarFromPath(auth.accessToken, path))
  } catch (error) {
    avatarPreviewUrl.value = previousPreview
    avatarLoadFailed.value = previousLoadFailed
    showError(error, '头像更新失败')
  } finally { uploading.value = false }
}

async function saveAvatarFile(fileId: string) {
  applyUpdatedUser(await updateProfile(auth.accessToken, { avatar_file_id: fileId }))
  avatarFileId.value = auth.user?.avatar_file_id ?? ''
  avatarPreviewUrl.value = ''
  avatarLoadFailed.value = false
  await refreshAssets()
  uni.showToast({ title: '头像已更新', icon: 'success' })
}

async function onNicknameChange(event: unknown) {
  const inputEvent = event as { detail?: { value?: string }; target?: { value?: string } } | undefined
  const nextNickname = (inputEvent?.detail?.value ?? inputEvent?.target?.value ?? nickname.value).trim()
  nickname.value = nextNickname
  if (supportsNicknameReview) {
    pendingReviewedNickname = nextNickname
    return
  }

  await saveNickname(nextNickname)
}

async function onNicknameReview(event: unknown) {
  if (!supportsNicknameReview) return
  const detail = (event as { detail?: { pass?: boolean; timeout?: boolean } })?.detail
  const nextNickname = pendingReviewedNickname ?? nickname.value.trim()
  pendingReviewedNickname = null
  if (detail?.pass && !detail.timeout) {
    await saveNickname(nextNickname)
    return
  }

  nickname.value = auth.user?.nickname ?? ''
  uni.showToast({ title: detail?.timeout ? '昵称审核超时，请重试' : '昵称未通过安全审核', icon: 'none' })
}

async function saveNickname(nextNickname: string) {
  if (savingNickname.value) return
  const previousNickname = auth.user?.nickname ?? ''
  if (!nextNickname) {
    nickname.value = previousNickname
    uni.showToast({ title: '昵称不能为空', icon: 'none' })
    return
  }
  nickname.value = nextNickname
  if (nextNickname === previousNickname) return

  savingNickname.value = true
  try {
    applyUpdatedUser(await updateProfile(auth.accessToken, { nickname: nextNickname }))
    nickname.value = auth.user?.nickname ?? nextNickname
    await refreshAssets()
    uni.showToast({ title: '昵称已更新', icon: 'success' })
  } catch (error) {
    nickname.value = previousNickname
    showError(error, '昵称更新失败')
  } finally { savingNickname.value = false }
}

function applyUpdatedUser(user: NonNullable<typeof auth.user>) {
  auth.user = user
  auth.persist()
}

async function refreshAssets() {
  try { assets.value = await loadAssetOverview(auth.accessToken) }
  catch { /* 资料已保存，资产摘要可在下次进入页面时刷新。 */ }
}
function clearBrokenAvatar() {
  if (avatarPreviewUrl.value) {
    avatarPreviewUrl.value = ''
    return
  }
  avatarLoadFailed.value = true
}
function isSelectionCancelled(error: unknown) {
  return typeof error === 'object' && error !== null && 'errMsg' in error && String(error.errMsg).includes('cancel')
}
function showError(error: unknown, fallback: string) {
  const message = error instanceof Error
    ? error.message
    : typeof error === 'object' && error !== null && 'errMsg' in error ? String(error.errMsg) : fallback
  uni.showToast({ title: message || fallback, icon: 'none' })
}

function money(cents: number) { return (cents / 100).toFixed(2) }
function goLogin() { navigateToLogin('/pages/account/index') }
function openAssets() { uni.navigateTo({ url: '/pages/assets/index' }) }
function openRecharge() { uni.navigateTo({ url: '/pages/wallet-recharge/index' }) }
function openTasks() { uni.setStorageSync('task_mall_open_my_tasks', true); uni.reLaunch({ url: '/pages/tasks/index' }) }
function openPublisher() { uni.navigateTo({ url: '/pages/publisher/index' }) }
function openOrders(status?: string) { uni.navigateTo({ url: `/pages/orders/index${status ? `?status=${status}` : ''}` }) }
function openAfterSales() { uni.navigateTo({ url: '/pages/after-sales/index' }) }
function openReferrals() { uni.navigateTo({ url: '/pages/referrals/index' }) }
function openCheckIn() { uni.navigateTo({ url: '/pages/check-in/index' }) }
function openNotifications() { uni.navigateTo({ url: '/pages/notifications/index' }) }
function openSecondHand() { uni.navigateTo({ url: '/pages/second-hand/index' }) }
function openSecondHandOrders() { uni.navigateTo({ url: '/pages/second-hand-orders/index' }) }
function openChat() { uni.navigateTo({ url: '/pages/chat/index' }) }
function openPrivacy() { uni.navigateTo({ url: '/pages/privacy/index' }) }
function openIdentityVerification() { uni.navigateTo({ url: '/pages/identity-verification/index' }) }
function openAddresses() { uni.navigateTo({ url: '/pages/addresses/index' }) }
function openLegal() { uni.navigateTo({ url: '/pages/legal/index' }) }
function openSupport() { uni.navigateTo({ url: '/pages/support/index' }) }
function openCommunity(category?: string) { uni.reLaunch({ url: `/pages/community/index${category ? `?category=${category}` : ''}` }) }
function openCommunityCompose(category: string) { uni.navigateTo({ url: `/pages/community-compose/index?category=${category}` }) }
function openActivities() { uni.navigateTo({ url: '/pages/community-activities/index' }) }
async function logout() {
  const result = await uni.showModal({ title: '退出账号', content: '确认退出当前登录账号？' })
  if (!result.confirm) return
  await auth.logout()
  uni.reLaunch({ url: '/pages/index/index' })
}
</script>

<style scoped>
.profile-copy{min-width:0;flex:1}.profile-badge{display:inline-block;margin-bottom:7rpx;padding:3rpx 9rpx;border:2rpx solid var(--tm-ink-900);border-radius:999rpx;background:#fff;font-size:10px;font-weight:950;letter-spacing:.08em}.profile-score{display:flex;width:78rpx;height:78rpx;align-items:center;justify-content:center;flex-direction:column;flex:none;border:3rpx solid var(--tm-ink-900);border-radius:22rpx;background:#fff;box-shadow:3rpx 4rpx 0 rgba(7,26,51,.18)}.profile-score strong{font-size:15px;font-weight:950}.profile-score text{font-size:10px;font-weight:750}.profile-progress{height:9rpx;margin-top:12rpx;overflow:hidden;border:2rpx solid var(--tm-ink-900);border-radius:999rpx;background:#fff}.profile-progress view{height:100%;background:linear-gradient(90deg,var(--tm-campus-coral),var(--tm-campus-purple))}.account-brief{margin-top:22rpx;padding:23rpx;background:linear-gradient(135deg,#fff 0%,var(--tm-campus-cyan-soft) 100%)}.account-brief>view:first-child strong,.account-brief>view:first-child small{display:block}.account-brief>view:first-child strong{margin-top:9rpx;font-size:17px;font-weight:950}.account-brief>view:first-child small{margin-top:4rpx;color:var(--tm-ink-700);font-size:12px}.brief-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:9rpx;margin-top:18rpx}.brief-grid view{padding:13rpx 8rpx;border:2rpx solid var(--tm-ink-900);border-radius:15rpx;background:#fff}.brief-grid view:nth-child(2){background:var(--tm-campus-yellow-soft)}.brief-grid view:nth-child(3){background:var(--tm-campus-lime-soft)}.brief-grid strong,.brief-grid text{display:block}.brief-grid strong{font-size:14px;font-weight:950}.brief-grid text{margin-top:3rpx;color:var(--tm-ink-700);font-size:10px}
.page{min-height:100vh;padding:36rpx 28rpx 80rpx;box-sizing:border-box;background:#f5f7fb}.heading{margin:12rpx 4rpx 28rpx}.title,.subtitle,.section-title,.hint,.asset-value,.asset text,.address-name,.address-detail,.empty{display:block}.title{font-size:26px;font-weight:750}.subtitle{margin-top:8rpx;color:#667085}.card{margin-top:24rpx;padding:28rpx;border-radius:24rpx;background:#fff}.section-title{font-size:19px;font-weight:700}.section-row{display:flex;justify-content:space-between;align-items:center}.input{height:76rpx;margin-top:18rpx;padding:0 20rpx;border:1rpx solid #dfe3eb;border-radius:14rpx;box-sizing:border-box;background:#fafbfc}.triple{display:grid;grid-template-columns:repeat(3,1fr);gap:12rpx}.button-row{display:flex;gap:16rpx;margin-top:22rpx}.primary,.secondary{flex:1;height:78rpx;border-radius:16rpx;font-size:15px;line-height:78rpx}.primary{background:#3157d5;color:#fff}.secondary{border:1rpx solid #cfd5e2;background:#fff;color:#475467}.primary::after,.secondary::after,.delete::after{border:0}.hint{margin-top:16rpx;color:#98a2b3;font-size:14px;line-height:1.5}.asset-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:14rpx;margin-top:24rpx}.asset{padding:24rpx 10rpx;text-align:center;border-radius:20rpx;background:#fff;color:#667085;font-size:14px}.asset-value{margin-bottom:8rpx;color:#1d2939;font-size:19px;font-weight:750}.address-item{display:flex;align-items:center;gap:12rpx;padding:22rpx 0;border-bottom:1rpx solid #eef0f4}.address-main{flex:1;position:relative}.address-name{font-weight:650}.address-detail{margin-top:8rpx;color:#667085;font-size:15px;line-height:1.5}.default-tag{display:inline-block;margin-top:8rpx;padding:4rpx 12rpx;border-radius:10rpx;background:#eef3ff;color:#3157d5;font-size:13px}.delete{width:110rpx;background:#fff;color:#d92d20;font-size:14px}.empty{padding:34rpx 0;text-align:center;color:#98a2b3}.checkbox{display:flex;align-items:center;margin-top:20rpx;color:#475467;font-size:15px}
.page{padding:32rpx 28rpx 90rpx;background:var(--tm-canvas)}.heading{display:flex;align-items:center;gap:20rpx;margin:0;padding:30rpx;border:1rpx solid var(--tm-line);border-radius:26rpx;background:var(--tm-brand-50);box-shadow:var(--tm-shadow-card);color:var(--tm-ink-900)}.profile-mark{display:flex;align-items:center;justify-content:center;width:92rpx;height:92rpx;flex:none;border-radius:26rpx;background:var(--tm-brand-600);color:#fff;font-size:21px;font-weight:850}.heading .title{font-size:21px;font-weight:850}.heading .subtitle{color:var(--tm-ink-500);font-size:14px}.card{border:1rpx solid var(--tm-line);border-radius:24rpx;box-shadow:var(--tm-shadow-card)}.section-title{font-size:17px;font-weight:800}.section-note{color:var(--tm-ink-500);font-size:14px}.input{border-color:var(--tm-line);border-radius:15rpx;background:#fafcfc}.primary,.secondary{border-radius:17rpx;font-weight:750}.primary{background:var(--tm-brand-600)}.secondary{border-color:var(--tm-line);color:var(--tm-ink-700)}.hint{color:var(--tm-ink-500)}.asset-grid{grid-template-columns:repeat(3,minmax(0,1fr));gap:12rpx}.asset{border:1rpx solid var(--tm-line);border-radius:20rpx;box-shadow:var(--tm-shadow-card);color:var(--tm-ink-500)}.asset:first-child{border-color:#f2dec4;background:var(--tm-reward-100)}.asset:first-child .asset-value{color:var(--tm-reward-600)}.service-card{padding-bottom:18rpx}.service-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10rpx;margin-top:22rpx}.service-item{display:flex;min-width:0;align-items:center;flex-direction:column;padding:18rpx 6rpx;border-radius:20rpx;transition:transform var(--tm-motion-fast) var(--tm-ease),background-color var(--tm-motion-fast) ease}.service-item:active{transform:scale(.96);background:var(--tm-surface-soft)}.service-icon{display:flex;align-items:center;justify-content:center;width:62rpx;height:62rpx;border-radius:18rpx;background:var(--tm-brand-100);color:var(--tm-brand-700);font-size:14px;font-weight:850}.service-icon.tone-orange{background:var(--tm-reward-100);color:var(--tm-reward-600)}.service-icon.tone-red{background:#fff0ef;color:#d14343}.service-icon.tone-green{background:#e9f8f2;color:#14835f}.service-icon.tone-violet{background:#f1edff;color:#7654c6}.service-icon.tone-slate{background:#eef1f5;color:#526079}.service-label{margin-top:10rpx;color:var(--tm-ink-900);font-size:14px;font-weight:750}.service-note{margin-top:4rpx;overflow:hidden;color:var(--tm-ink-500);font-size:13px;text-overflow:ellipsis;white-space:nowrap}.logout{height:76rpx;margin-top:24rpx;border:1rpx solid #f1caca;border-radius:17rpx;background:#fff;color:var(--tm-danger);font-size:15px;line-height:74rpx}
/* 校园身份卡视觉：高饱和色块、清晰的黑色边框，业务字段与权限不变。 */
.heading{border:3rpx solid var(--tm-ink-900);background:linear-gradient(135deg,#fff0a3 0%,var(--tm-campus-yellow) 56%,#ffe175 100%);box-shadow:7rpx 8rpx 0 rgba(22,22,22,.18)}.profile-mark{border:2rpx solid var(--tm-ink-900);background:linear-gradient(145deg,var(--tm-campus-purple),var(--tm-brand-600));box-shadow:3rpx 4rpx 0 rgba(22,22,22,.18)}.heading .title{color:var(--tm-ink-900);font-weight:900}.heading .subtitle{color:#665529;font-weight:650}
.card{border:2rpx solid var(--tm-ink-900);box-shadow:5rpx 6rpx 0 rgba(22,22,22,.10)}.section-title{color:var(--tm-ink-900);font-weight:900}.section-note{color:#767785}.input{border:2rpx solid #d9d2bc;background:#fffdf5}.primary{border:2rpx solid var(--tm-ink-900);background:var(--tm-brand-600);box-shadow:0 5rpx 0 #073d9f;color:#fff;font-weight:900}.secondary{border:2rpx solid var(--tm-ink-900);background:var(--tm-campus-yellow);box-shadow:0 5rpx 0 #c49b10;color:var(--tm-ink-900);font-weight:900}
.asset{border:2rpx solid var(--tm-ink-900);box-shadow:3rpx 4rpx 0 rgba(22,22,22,.10);color:#5f6070}.asset:first-child{border-color:var(--tm-ink-900);background:var(--tm-campus-yellow-soft)}.asset:nth-child(2){background:var(--tm-campus-cyan-soft)}.asset:nth-child(3){background:var(--tm-campus-purple-soft)}.asset-value{color:var(--tm-ink-900);font-weight:900}.asset:first-child .asset-value{color:#df4e11}
.service-item{border:2rpx solid var(--tm-ink-900);background:#fff;box-shadow:3rpx 4rpx 0 rgba(22,22,22,.10)}.service-item:nth-child(3n+2){background:#f2ffe8}.service-item:nth-child(3n+3){background:#eff3ff}.service-icon{border:2rpx solid rgba(22,22,22,.12);background:var(--tm-campus-cyan-soft);color:#087884}.service-icon.tone-orange{background:var(--tm-campus-yellow-soft);color:#b66a00}.service-icon.tone-red{background:var(--tm-campus-coral-soft);color:#d63843}.service-icon.tone-green{background:var(--tm-campus-lime-soft);color:#257c1c}.service-icon.tone-violet{background:var(--tm-campus-purple-soft);color:#6333d7}.service-label{color:var(--tm-ink-900);font-weight:850}.service-note{color:#727581}.logout{border:2rpx solid var(--tm-danger);box-shadow:0 4rpx 0 #f7c4c8;font-weight:850;line-height:72rpx}
/* 账户中心 V2：用不同形态承载身份、资产、成长和服务，避免整页同构宫格。 */
.page{padding:28rpx 24rpx 96rpx;background:linear-gradient(180deg,#edf8fb 0,#f7f9fb 460rpx,#f4f6f8 100%)}
.account-hero{position:relative;overflow:hidden;padding:30rpx 28rpx 24rpx;border:1rpx solid rgba(255,255,255,.48);border-radius:32rpx;background:linear-gradient(135deg,#0b5570 0%,#157f96 56%,#31b8b0 100%);box-shadow:0 18rpx 42rpx rgba(15,86,112,.22);color:#fff}
.account-hero::after{content:'';position:absolute;right:-72rpx;top:-88rpx;width:230rpx;height:230rpx;border:36rpx solid rgba(255,255,255,.09);border-radius:50%}
.hero-profile{position:relative;z-index:1;display:flex;align-items:center;gap:18rpx}
.account-hero .profile-mark{width:86rpx;height:86rpx;border:2rpx solid rgba(255,255,255,.9);border-radius:28rpx;background:linear-gradient(145deg,#fff17a,#ffd74b);box-shadow:0 8rpx 20rpx rgba(4,45,63,.2);color:#163247}
.account-hero .profile-badge{margin:0 0 5rpx;padding:2rpx 10rpx;border:1rpx solid rgba(255,255,255,.38);background:rgba(255,255,255,.12);color:#e9fdff;font-size:10px}
.account-hero .title{color:#fff;font-size:21px;font-weight:900}
.account-hero .subtitle{margin-top:3rpx;color:rgba(255,255,255,.74);font-size:13px}
.profile-edit{position:relative;z-index:2;width:auto;height:56rpx;flex:none;margin:0;padding:0 18rpx;border:1rpx solid rgba(255,255,255,.42);border-radius:999rpx;background:rgba(255,255,255,.14);color:#fff;font-size:13px;font-weight:800;line-height:54rpx}
.profile-edit::after{border:0}
.account-hero .profile-progress{position:relative;z-index:1;height:8rpx;margin-top:22rpx;border:0;background:rgba(255,255,255,.22)}
.account-hero .profile-progress view{border-radius:999rpx;background:linear-gradient(90deg,#fff17a,#b8ffda)}
.hero-meta{position:relative;z-index:1;display:grid;grid-template-columns:repeat(3,1fr);gap:1rpx;margin-top:18rpx;overflow:hidden;border-radius:20rpx;background:rgba(255,255,255,.16)}
.hero-meta view{padding:15rpx 10rpx;background:rgba(2,41,57,.12);text-align:center}
.hero-meta strong,.hero-meta text{display:block}.hero-meta strong{font-size:14px;font-weight:900}.hero-meta text{margin-top:3rpx;color:rgba(255,255,255,.68);font-size:10px}
.profile-editor{margin-top:18rpx;padding:24rpx;border:1rpx solid #dce8ec;border-radius:26rpx;background:#fff;box-shadow:0 12rpx 28rpx rgba(26,71,91,.08)}
.profile-editor .input{border:1rpx solid #d9e5e8;background:#f8fbfc}.profile-editor .primary{border:0;background:#147f96;box-shadow:none}.profile-editor .secondary{border:1rpx solid #cbdadd;background:#fff;box-shadow:none}
.profile-avatar-control{position:relative;margin:0;padding:0;line-height:1}.profile-avatar-control::after{border:0}.profile-avatar-control::before{content:'+';position:absolute;z-index:2;right:-3rpx;bottom:-3rpx;display:flex;width:30rpx;height:30rpx;align-items:center;justify-content:center;border:2rpx solid #fff;border-radius:50%;background:#147f96;color:#fff;font-size:13px;font-weight:900;line-height:1}.profile-avatar-control[disabled]{opacity:.72}.profile-avatar-control:active{transform:scale(.96)}
.profile-nickname{display:block;width:100%;height:54rpx;min-width:0;padding:0 34rpx 2rpx 0;border:0;border-bottom:2rpx solid rgba(21,129,92,.22);box-sizing:border-box;background:transparent;color:#15354a;font-size:21px;font-weight:900;line-height:52rpx;text-overflow:ellipsis}.profile-nickname:focus{border-bottom-color:#15815c}.profile-nickname::placeholder{color:#64808b;font-weight:750}
.section-kicker{display:block;color:#16839b;font-size:10px;font-weight:900;letter-spacing:.14em}
.wallet-layout{display:grid;grid-template-columns:1.22fr .78fr;gap:14rpx;margin-top:20rpx}
.wallet-main{position:relative;min-height:240rpx;padding:24rpx;overflow:hidden;border-radius:28rpx;background:linear-gradient(145deg,#182c46,#234c67);box-shadow:0 16rpx 34rpx rgba(20,47,70,.18);box-sizing:border-box;color:#fff}
.wallet-main::after{content:'¥';position:absolute;right:-4rpx;bottom:-36rpx;color:rgba(255,255,255,.07);font-size:90px;font-weight:900}
.wallet-head{position:relative;z-index:1;display:flex;justify-content:space-between;align-items:center}.wallet-head text:first-child{font-size:14px;font-weight:850}.wallet-head text:last-child{color:#b9d5df;font-size:12px}
.wallet-main>strong{position:relative;z-index:1;display:block;margin-top:27rpx;font-size:24px;font-weight:900;letter-spacing:-1rpx}.wallet-caption{position:relative;z-index:1;display:block;margin-top:4rpx;color:#a9c4cf;font-size:12px}
.wallet-main button{position:absolute;z-index:2;left:24rpx;bottom:22rpx;width:auto;height:57rpx;margin:0;padding:0 23rpx;border:0;border-radius:999rpx;background:#fff17a;color:#173044;font-size:13px;font-weight:900;line-height:57rpx}.wallet-main button::after{border:0}
.asset-side{display:grid;grid-template-rows:1fr 1fr;gap:12rpx}.asset-mini{display:flex;min-height:112rpx;align-items:center;justify-content:space-between;gap:8rpx;padding:18rpx;border:1rpx solid #dce7ea;border-radius:24rpx;background:#fff;box-shadow:0 10rpx 22rpx rgba(26,71,91,.07);box-sizing:border-box}.asset-mini.points{background:linear-gradient(145deg,#fff9d9,#fff)}.asset-mini.coupons{background:linear-gradient(145deg,#f1edff,#fff)}.asset-mini text,.asset-mini small{display:block}.asset-mini text{font-size:13px;font-weight:850}.asset-mini small{margin-top:4rpx;color:#788691;font-size:10px;line-height:1.35}.asset-mini strong{flex:none;color:#183246;font-size:14px;font-weight:900}
.growth-card{position:relative;margin-top:18rpx;padding:23rpx 112rpx 23rpx 24rpx;overflow:hidden;border:1rpx solid #d9e7ea;border-radius:27rpx;background:#fff;box-shadow:0 11rpx 26rpx rgba(26,71,91,.07)}.growth-copy strong,.growth-copy small{display:block}.growth-copy strong{margin-top:6rpx;color:#172c3f;font-size:17px;font-weight:900}.growth-copy small{margin-top:4rpx;color:#75838e;font-size:12px}.growth-target{position:absolute;right:22rpx;top:22rpx;display:flex;width:72rpx;height:72rpx;align-items:center;justify-content:center;flex-direction:column;border-radius:22rpx;background:#e5f7f8;color:#176c80}.growth-target strong{font-size:14px;font-weight:900}.growth-target text{font-size:10px}.growth-pulse{height:7rpx;margin-top:16rpx;overflow:hidden;border-radius:999rpx;background:#eaf0f2}.growth-pulse view{height:100%;border-radius:999rpx;background:linear-gradient(90deg,#2eb7ad,#7ad977)}
.section-heading{display:flex;align-items:flex-end;justify-content:space-between;gap:16rpx;margin:28rpx 4rpx 12rpx}.section-heading>text{color:#788691;font-size:12px}.section-title{margin-top:4rpx;color:#172c3f;font-size:17px;font-weight:900}
.quick-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12rpx}.quick-card{display:flex;min-width:0;align-items:center;gap:12rpx;padding:18rpx;border:1rpx solid #dce7ea;border-radius:23rpx;background:#fff;box-shadow:0 10rpx 22rpx rgba(26,71,91,.07)}.quick-card:nth-child(2){background:#fff9e7}.quick-card:nth-child(3){background:#fff3f1}.quick-card:nth-child(4){background:#eefaf5}.quick-card>view:nth-child(2){min-width:0;flex:1}.quick-arrow{color:#9aa8b2;font-size:21px;line-height:1}.quick-card .service-icon{width:52rpx;height:52rpx;flex:none;border:0;border-radius:17rpx}.quick-card .service-label{margin:0;font-size:14px}.quick-card .service-note{font-size:10px}
.more-services{margin-top:18rpx;padding:24rpx;border:1rpx solid #dce7ea;border-radius:28rpx;background:#fff;box-shadow:0 12rpx 28rpx rgba(26,71,91,.07)}.more-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10rpx;margin-top:19rpx}.more-service-item{display:flex;min-width:0;align-items:center;gap:11rpx;padding:15rpx 12rpx;border-radius:18rpx;background:#f6f9fa}.more-service-item:active{transform:scale(.98);background:#edf4f6}.more-service-item>view:nth-child(2){min-width:0;flex:1}.more-service-item .service-icon{width:48rpx;height:48rpx;flex:none;border:0;border-radius:15rpx;font-size:13px}.more-service-item .service-label{margin:0;font-size:13px}.more-service-item .service-note{font-size:10px}
.service-icon{box-shadow:none!important}.service-icon image,.profile-mark image{width:100%;height:100%;border-radius:inherit}.service-icon.tone-blue{background:#e5f3ff;color:#1474bc}.service-icon.tone-orange{background:#fff0d6;color:#b86800}.service-icon.tone-red{background:#ffebea;color:#cc3e45}.service-icon.tone-green{background:#e6f8ef;color:#18825e}.service-icon.tone-violet{background:#f0ebff;color:#6b4fc7}.service-icon.tone-slate{background:#ebf0f3;color:#516473}
.logout{height:68rpx;margin:20rpx 0 0;border:1rpx solid #e3d7d7;border-radius:20rpx;background:rgba(255,255,255,.78);box-shadow:none;color:#b44a4a;font-size:14px;line-height:66rpx}.logout::after{border:0}
/* V3：操作就近归组，弱化重复卡片边框，让账户页更像一个完整控制台。 */
.profile-field{display:grid;grid-template-columns:76rpx minmax(0,1fr);align-items:center;margin-top:18rpx;padding:5rpx 8rpx 5rpx 18rpx;border:1rpx solid #d9e5e8;border-radius:18rpx;background:#f6fafb}.profile-field>text{color:#4d6570;font-size:13px;font-weight:850}.profile-field .input{height:64rpx;margin:0;border:0;background:transparent}
.profile-editor .button-row{margin-top:14rpx}.profile-editor .primary,.profile-editor .secondary{height:66rpx;font-size:14px;line-height:64rpx}.profile-editor .hint{margin-top:12rpx;font-size:13px}
.wallet-main{min-height:250rpx}.wallet-action-row{position:absolute;z-index:2;right:22rpx;bottom:20rpx;left:22rpx;display:flex;align-items:center;gap:14rpx}.wallet-action-row button{position:static;width:auto;height:56rpx;flex:none;padding:0 22rpx;line-height:56rpx}.wallet-action-row>text{min-width:0;color:#a9c4cf;font-size:10px;line-height:1.35}
.quick-grid{grid-template-columns:repeat(4,minmax(0,1fr));gap:0;padding:16rpx 8rpx;border:1rpx solid #dce7ea;border-radius:27rpx;background:#fff;box-shadow:0 11rpx 25rpx rgba(26,71,91,.07)}.quick-card{position:relative;align-items:center;justify-content:flex-start;flex-direction:column;gap:7rpx;padding:10rpx 3rpx;border:0;border-radius:18rpx;background:transparent!important;box-shadow:none}.quick-card:not(:last-child)::after{content:'';position:absolute;right:0;top:16rpx;bottom:16rpx;width:1rpx;background:#e5edef}.quick-card>view:nth-child(2){width:100%;text-align:center}.quick-card .service-icon{width:56rpx;height:56rpx;border-radius:18rpx}.quick-card .service-label{font-size:13px;white-space:nowrap}.quick-card .service-note{display:none}.quick-card:active{transform:translateY(2rpx);background:#f2f7f8!important}
/* V09 对齐：轻校园插画感、白色功能面板与多层级服务结构。 */
.page{padding:calc(18rpx + env(safe-area-inset-top)) 24rpx calc(132rpx + env(safe-area-inset-bottom));background:linear-gradient(180deg,#f3fbf7 0,#f7fbff 430rpx,#f5f8fa 100%)}
.account-toolbar{display:grid;grid-template-columns:88rpx 1fr 88rpx;align-items:center;margin-bottom:18rpx}.account-toolbar>text{text-align:center;color:#142c3d;font-size:17px;font-weight:900}.toolbar-actions{display:flex;justify-content:flex-end;gap:10rpx}.toolbar-actions view{display:flex;width:38rpx;height:38rpx;align-items:center;justify-content:center;border:1rpx solid #dce9e5;border-radius:14rpx;background:#fff;color:#31546a;font-size:12px;font-weight:850;box-shadow:0 6rpx 16rpx rgba(35,74,85,.07)}
.page .account-hero{padding:28rpx;border:1rpx solid #d7ebe4;border-radius:30rpx;background:linear-gradient(135deg,#e6fff2 0%,#eaf9ff 60%,#fff4d8 100%);box-shadow:0 14rpx 34rpx rgba(35,94,86,.11);color:#16364a}.page .account-hero::before{content:'';position:absolute;right:20rpx;bottom:-22rpx;width:170rpx;height:118rpx;border-radius:80rpx 80rpx 0 0;background:linear-gradient(145deg,rgba(38,188,142,.22),rgba(37,158,222,.16));transform:rotate(-6deg)}.page .account-hero::after{right:-54rpx;top:-72rpx;width:210rpx;height:210rpx;border:32rpx solid rgba(36,173,145,.09)}
.page .account-hero .profile-mark{width:84rpx;height:84rpx;border:0;border-radius:26rpx;background:linear-gradient(145deg,#19bc85,#09a86f);box-shadow:0 9rpx 20rpx rgba(14,140,99,.2);color:#fff}.page .account-hero .profile-badge{border:0;background:#fff6a7;color:#27735a}.page .account-hero .title{color:#15354a;font-size:21px}.page .account-hero .subtitle{color:#607985}.page .profile-edit{border:1rpx solid #bcded2;background:rgba(255,255,255,.78);color:#187456}.page .account-hero .profile-progress{background:rgba(30,125,103,.12)}.page .account-hero .profile-progress view{background:linear-gradient(90deg,#1ebc86,#6ed8c3)}.page .hero-meta{background:rgba(255,255,255,.68)}.page .hero-meta view{background:transparent}.page .hero-meta strong{color:#18384c}.page .hero-meta text{color:#728691}
.wallet-layout{gap:14rpx}.wallet-main{min-height:232rpx;border:0;border-radius:28rpx;background:linear-gradient(145deg,#174261,#215e78);box-shadow:0 14rpx 32rpx rgba(27,69,91,.16)}.asset-mini{border-color:#e1ebe8;box-shadow:0 9rpx 22rpx rgba(36,74,84,.07)}
.growth-card,.order-card,.community-services,.quick-services{margin-top:18rpx;padding:24rpx;border:1rpx solid #dceae6;border-radius:28rpx;background:#fff;box-shadow:0 12rpx 28rpx rgba(34,78,82,.07)}.growth-card{padding-right:112rpx}.section-title{color:#17364a;font-size:17px}.section-note{color:#6c8490;font-size:12px}.section-kicker{color:#16956f}
.order-status-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8rpx;margin-top:20rpx}.order-status-item{min-width:0;padding:14rpx 4rpx;text-align:center;border-radius:20rpx;background:#f7faf9}.order-mark{display:flex;width:54rpx;height:54rpx;align-items:center;justify-content:center;margin:0 auto;border-radius:18rpx;background:#e5f3ff;color:#1474bc;font-size:14px;font-weight:900}.order-mark.tone-orange{background:#fff2d8;color:#b56a00}.order-mark.tone-green{background:#e4f8ed;color:#16815c}.order-mark.tone-red{background:#ffeded;color:#c9474d}.order-status-item strong,.order-status-item text{display:block}.order-status-item strong{margin-top:8rpx;color:#18384c;font-size:13px}.order-status-item text{margin-top:2rpx;color:#667e8a;font-size:10px}
.community-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:11rpx;margin-top:19rpx}.community-item{min-width:0;padding:17rpx 8rpx;text-align:center;border-radius:20rpx;background:#f5faf8}.community-item:nth-child(2),.community-item:nth-child(5){background:#fff9e8}.community-item:nth-child(3),.community-item:nth-child(6){background:#f2f0ff}.community-item .service-icon{width:56rpx;height:56rpx;margin:0 auto;border:0}.community-item .service-label,.community-item .service-note{display:block}.community-item .service-label{margin-top:8rpx;font-size:13px}.community-item .service-note{margin-top:3rpx;font-size:10px}
.quick-grid{grid-template-columns:repeat(4,minmax(0,1fr));gap:8rpx;margin-top:18rpx;padding:0;border:0;background:transparent;box-shadow:none}.quick-card{min-height:116rpx;justify-content:flex-start;padding:12rpx 3rpx;border-radius:19rpx;background:#f6faf9!important}.quick-card:not(:last-child)::after{display:none}.quick-card .service-icon{width:52rpx;height:52rpx}.quick-card .service-label{font-size:12px}.quick-card:active{background:#edf7f3!important}.logout{margin-top:18rpx;border-color:#e6dede;background:rgba(255,255,255,.86)}
@media screen and (max-width:360px){.wallet-layout{grid-template-columns:1fr}.asset-side{grid-template-columns:1fr 1fr;grid-template-rows:auto}.quick-grid{grid-template-columns:repeat(2,1fr)}.quick-card:nth-child(2)::after{display:none}.quick-card:nth-child(-n+2){border-bottom:1rpx solid #e5edef}.more-grid{grid-template-columns:1fr}}
@media screen and (min-width:768px){.page{width:750rpx;margin:0 auto}}
.page{padding-top:calc(env(safe-area-inset-top) + 18rpx)}
/* V09 像素级校准：身份卡、订单、社区与常用服务按评审稿的比例和层级呈现。 */
.account-toolbar{grid-template-columns:92rpx 1fr 92rpx;margin-bottom:20rpx}.account-toolbar>text{font-size:19px}.toolbar-actions{gap:13rpx}.toolbar-actions view{width:48rpx;height:48rpx;border-radius:17rpx;font-size:13px}
.page .account-hero{min-height:392rpx;padding:34rpx 30rpx 28rpx;border-radius:34rpx;background:linear-gradient(116deg,rgba(223,255,235,.97) 0%,rgba(209,250,238,.94) 42%,rgba(203,242,255,.93) 72%,rgba(255,246,216,.94) 100%);box-shadow:0 18rpx 38rpx rgba(39,105,94,.12);box-sizing:border-box}
.page .account-hero::before{right:-18rpx;bottom:6rpx;width:300rpx;height:205rpx;border-radius:54% 0 0 0;background:radial-gradient(circle at 78% 90%,rgba(255,255,255,.88) 0 14%,transparent 15%),linear-gradient(150deg,transparent 8%,rgba(39,172,131,.13) 9% 54%,rgba(35,150,212,.12) 55%);transform:none}.page .account-hero::after{right:-64rpx;top:-74rpx;width:270rpx;height:270rpx;border:38rpx solid rgba(62,188,158,.08)}
.hero-profile{align-items:center;gap:22rpx}.page .account-hero .profile-mark{width:102rpx;height:102rpx;border:4rpx solid rgba(255,255,255,.92);border-radius:50%;background:linear-gradient(145deg,#57d879,#16af66);box-shadow:0 10rpx 24rpx rgba(22,140,92,.22);font-size:21px}.page .account-hero .profile-badge{display:inline-flex;margin-bottom:7rpx;padding:4rpx 12rpx;border-radius:999rpx;background:#e8fff3;color:#1f8c60;font-size:10px;letter-spacing:.04em}.page .account-hero .title{font-size:22px}.page .account-hero .subtitle{margin-top:6rpx;color:#587684;font-size:14px}.page .profile-edit{height:64rpx;padding:0 24rpx;border-color:#b8ddd3;background:rgba(255,255,255,.88);color:#15815c;font-size:14px;line-height:62rpx}
.page .hero-meta{position:absolute;right:30rpx;bottom:28rpx;left:30rpx;display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:0;margin:0;padding:20rpx 22rpx 17rpx;border:1rpx solid rgba(205,228,223,.88);border-radius:25rpx;background:rgba(255,255,255,.88);box-shadow:0 12rpx 30rpx rgba(49,95,91,.09);backdrop-filter:blur(10px)}
.page .hero-meta>view{padding:0;background:transparent;text-align:left}.completion-block{grid-column:1/-1;display:grid;grid-template-columns:112rpx minmax(0,1fr);align-items:center;gap:16rpx;padding-bottom:17rpx!important;border-bottom:1rpx solid #e3eeeb}.completion-copy strong,.completion-copy text{display:block}.page .completion-copy strong{color:#17384d;font-size:17px}.page .completion-copy text{margin-top:3rpx;color:#708791;font-size:12px}.completion-block .profile-progress{height:10rpx;margin:0;border:0;background:#e5efec}.completion-block .profile-progress view{background:linear-gradient(90deg,#16b673,#5ed386)}
.identity-block{display:flex!important;align-items:center;justify-content:center;gap:13rpx;margin-top:16rpx}.identity-block+.identity-block{border-left:1rpx solid #e2ece9}.identity-symbol{display:flex;width:46rpx;height:46rpx;align-items:center;justify-content:center;border-radius:16rpx;background:#54cf73;color:#fff;font-size:13px;font-weight:900}.identity-symbol.status{background:#41ca73}.page .hero-meta .identity-block strong{color:#17384d;font-size:14px}.page .hero-meta .identity-block text{margin-top:2rpx;color:#718691;font-size:10px}
.community-grid{grid-template-columns:repeat(6,minmax(0,1fr));gap:2rpx;padding-top:4rpx}.community-item,.community-item:nth-child(2),.community-item:nth-child(3),.community-item:nth-child(5),.community-item:nth-child(6){padding:15rpx 2rpx;background:transparent}.community-item .service-icon{width:58rpx;height:58rpx}.community-item .service-label{font-size:12px;white-space:nowrap}.community-item .service-note{display:none}
.quick-services .quick-grid{grid-template-columns:repeat(4,minmax(0,1fr));gap:8rpx}.quick-services .quick-card{min-height:112rpx;padding:12rpx 2rpx}.quick-services .quick-card .service-label{font-size:12px}
/* V09 最终校准：避免通用 view 规则把身份面板撑高，并补足校园插画层。 */
.page .account-hero .account-hero-art{position:absolute;z-index:0;right:-72rpx;top:0;width:72%;height:100%;object-fit:cover;object-position:74% center;opacity:.3;mix-blend-mode:multiply}
.page .account-hero::before{z-index:1;background:linear-gradient(90deg,rgba(225,255,238,.98) 0%,rgba(225,255,238,.78) 45%,rgba(223,248,255,.18) 100%)}
.page .account-hero::after{z-index:1}.page .account-hero .hero-profile,.page .account-hero .hero-meta{z-index:2}
.page .hero-meta view{padding:0}.page .hero-meta{padding:18rpx 22rpx 15rpx}.page .completion-block{padding-bottom:13rpx!important}.page .identity-block{margin-top:12rpx}.page .identity-symbol{width:42rpx;height:42rpx;border-radius:14rpx}.page .hero-meta .identity-block strong{font-size:14px}.page .hero-meta .identity-block text{font-size:10px}
/* V09 账户资产色彩校准：资产卡采用轻量校园色，不再使用旧版深色大卡。 */
.page .wallet-main{background:linear-gradient(145deg,#dff1ff 0%,#cce9ff 62%,#eaf6ff 100%);box-shadow:0 14rpx 32rpx rgba(42,119,176,.12);color:#123c65}
.page .wallet-main::after{color:rgba(40,120,184,.08)}
.page .wallet-head text:last-child{color:#1b6fae}
.page .wallet-caption{color:#52758f}
.page .wallet-action-row>text{color:#607d91}
.page .wallet-main button{background:#fff;color:#1774bd;box-shadow:0 8rpx 20rpx rgba(33,119,180,.12)}
.page .asset-mini.points{background:linear-gradient(145deg,#fff8cf,#fffdf1)}
.page .asset-mini.coupons{background:linear-gradient(145deg,#e7fbef,#f7fff9)}
.account-state,.guest-account{position:relative;overflow:hidden;min-height:650rpx;border:1rpx solid #d7e9e4;border-radius:32rpx;background:#fff;box-shadow:0 16rpx 38rpx rgba(34,85,80,.10)}
.account-state{display:flex;align-items:center;justify-content:center;flex-direction:column;padding:54rpx;text-align:center;box-sizing:border-box}.state-mark{display:flex;width:92rpx;height:92rpx;align-items:center;justify-content:center;border-radius:30rpx;background:#0daf72;box-shadow:0 12rpx 26rpx rgba(13,175,114,.22);color:#fff;font-size:21px;font-weight:900}.loading-mark{background:#dff5ec;box-shadow:none;color:#18805d}.state-title{display:block;margin-top:25rpx;color:#17384d;font-size:19px;font-weight:900}.state-copy{display:block;margin-top:9rpx;color:#7a8d97;font-size:13px}
.guest-account{min-height:720rpx;background:linear-gradient(150deg,#e6fff1 0%,#eaf8ff 64%,#fff6d9 100%)}.guest-account:active{transform:scale(.99)}.guest-account-art{position:absolute;right:-120rpx;bottom:-10rpx;width:710rpx;height:520rpx;opacity:.34}.guest-account-shade{position:absolute;inset:0;background:linear-gradient(180deg,rgba(235,255,244,.98) 0%,rgba(235,255,244,.91) 42%,rgba(238,250,255,.52) 100%)}.guest-account-content{position:relative;z-index:1;display:flex;align-items:center;flex-direction:column;padding:82rpx 50rpx 58rpx;text-align:center}.guest-kicker,.guest-title,.guest-copy{display:block}.guest-kicker{margin-top:28rpx;color:#16805f;font-size:12px;font-weight:900;letter-spacing:2rpx}.guest-title{margin-top:12rpx;color:#17384d;font-size:24px;font-weight:950}.guest-copy{max-width:520rpx;margin-top:14rpx;color:#5f7782;font-size:14px;line-height:1.65}.guest-login-button{width:100%;height:84rpx;margin-top:42rpx;border:0;border-radius:22rpx;background:#0daf72;box-shadow:0 12rpx 26rpx rgba(13,175,114,.20);color:#fff;font-size:15px;font-weight:900;line-height:84rpx}.guest-login-button::after{border:0}

/* Stable phone typography: text stays readable while grids adapt by viewport. */
.page{padding-top:calc(var(--status-bar-height) + 10px);padding-bottom:calc(68px + env(safe-area-inset-bottom))}
.account-toolbar{display:flex;min-height:44px;align-items:center;margin-bottom:12px;padding:0 104px 0 4px}
.account-toolbar>text{overflow:hidden;font-size:20px;line-height:1.2;text-align:left;text-overflow:ellipsis;white-space:nowrap}
.toolbar-spacer,.toolbar-actions{display:none}
.page .account-hero{min-height:250px;padding:20px 16px 16px;border-radius:22px}
.hero-profile{gap:12px}.profile-copy{min-width:0;flex:1}.page .account-hero .profile-mark{width:56px;height:56px;flex:none;font-size:20px}
.page .account-hero .profile-badge{max-width:100%;margin-bottom:4px;padding:3px 8px;font-size:12px;letter-spacing:0}
.page .account-hero .title{display:block;overflow:hidden;font-size:23px;line-height:1.2;text-overflow:ellipsis;white-space:nowrap}
.page .account-hero .subtitle{margin-top:4px;font-size:14px;line-height:1.4}.page .profile-edit{min-width:82px;height:40px;padding:0 12px;font-size:14px;line-height:38px}
.page .hero-meta{right:16px;bottom:16px;left:16px;padding:12px 14px 10px;border-radius:16px}
.completion-block{grid-template-columns:70px minmax(0,1fr);gap:10px;padding-bottom:9px!important}.page .completion-copy strong{font-size:20px}.page .completion-copy text{font-size:12px}
.completion-block .profile-progress{height:6px}.identity-block{gap:8px;margin-top:9px}.page .identity-symbol{width:28px;height:28px;border-radius:9px;font-size:13px}
.page .hero-meta .identity-block strong{font-size:15px}.page .hero-meta .identity-block text{font-size:12px}
.wallet-layout{gap:10px;margin-top:12px}.wallet-main{min-height:145px;padding:16px;border-radius:18px}.wallet-head text:first-child{font-size:15px}.wallet-head text:last-child{font-size:12px}
.wallet-main>strong{margin-top:16px;font-size:27px;letter-spacing:0}.wallet-caption{font-size:13px}.wallet-action-row{right:16px;bottom:14px;left:16px;gap:10px}
.wallet-action-row button{min-height:44px;height:44px;padding:0 16px;font-size:14px;line-height:44px}.wallet-action-row>text{font-size:12px}
.asset-side{gap:8px}.asset-mini{min-height:68px;padding:12px;border-radius:16px}.asset-mini text{font-size:14px}.asset-mini small{font-size:12px}.asset-mini strong{font-size:16px}
.growth-card,.order-card,.community-services,.quick-services{margin-top:12px;padding:16px;border-radius:18px}.growth-card{padding-right:82px}.section-kicker{font-size:11px;letter-spacing:0}.section-title{font-size:18px}.section-note{font-size:12px}
.growth-copy strong{font-size:17px}.growth-copy small{font-size:13px}.growth-target{top:15px;right:15px;width:56px;height:56px;border-radius:16px}.growth-target strong{font-size:15px}.growth-target text{font-size:11px}
.order-status-grid{gap:6px;margin-top:14px}.order-status-item{padding:10px 3px;border-radius:14px}.order-mark{width:36px;height:36px;border-radius:12px;font-size:15px}.order-status-item strong{margin-top:5px;font-size:15px}.order-status-item text{font-size:12px}
.community-grid{grid-template-columns:repeat(3,minmax(0,1fr));gap:6px;margin-top:12px}.community-item,.community-item:nth-child(2),.community-item:nth-child(3),.community-item:nth-child(5),.community-item:nth-child(6){min-height:82px;padding:10px 4px;border-radius:14px;background:#f6faf8}
.community-item:nth-child(2),.community-item:nth-child(5){background:#fff9e8}.community-item:nth-child(3),.community-item:nth-child(6){background:#f2f0ff}.community-item .service-icon{width:38px;height:38px}.community-item .service-label{margin-top:6px;font-size:14px}
.quick-services .quick-grid{grid-template-columns:repeat(4,minmax(0,1fr));gap:6px;margin-top:12px}.quick-services .quick-card{min-height:82px;padding:8px 2px;border-radius:13px}.quick-services .quick-card .service-icon{width:38px;height:38px;border-radius:12px}.quick-services .quick-card .service-label{font-size:13px}
.logout{min-height:44px;height:44px;margin-top:12px;font-size:14px;line-height:42px}
.account-state,.guest-account{min-height:420px;border-radius:22px}.state-mark{width:58px;height:58px;border-radius:18px;font-size:20px}.state-title{font-size:19px}.state-copy{font-size:13px}
.guest-account{min-height:440px}.guest-account-content{padding:50px 28px 34px}.guest-kicker{font-size:11px;letter-spacing:0}.guest-title{font-size:25px}.guest-copy{font-size:14px}.guest-login-button{min-height:44px;height:44px;margin-top:24px;border-radius:14px;font-size:15px;line-height:44px}
@media screen and (max-width:360px){.page{padding-right:16px;padding-left:16px}.page .account-hero{min-height:300px}.hero-profile{display:grid;grid-template-columns:56px minmax(0,1fr);align-items:center;gap:8px 12px}.page .account-hero .profile-mark{grid-row:1}.profile-copy{grid-column:2;grid-row:1}.page .account-hero .profile-badge{white-space:nowrap}.page .profile-edit{grid-column:2;grid-row:2;width:96px;margin:0}.wallet-layout{grid-template-columns:1fr}.asset-side{grid-template-columns:repeat(2,minmax(0,1fr));grid-template-rows:auto}.wallet-action-row>text{display:none}.quick-services .quick-grid{grid-template-columns:repeat(3,minmax(0,1fr))}}
.order-mark .mark-icon{display:block;width:56%;height:56%}
.service-icon .mark-icon{display:block;width:54%;height:54%}
.service-icon .mark-icon.mark-icon-fill{width:100%;height:100%}
</style>
