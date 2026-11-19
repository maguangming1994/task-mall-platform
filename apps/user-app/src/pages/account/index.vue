<template>
  <view class="page">
    <view v-if="resolvingAccount" class="state-page" aria-live="polite">
      <view class="state-card">
        <view class="state-mark loading-mark">···</view>
        <text class="state-title">正在确认登录状态</text>
        <text class="state-copy">请稍候</text>
      </view>
    </view>

    <view v-else-if="!hasCompletedLogin" class="state-page">
      <view class="guest-account" role="button" tabindex="0" @click="goLogin">
        <view class="state-mark">未</view>
        <text class="guest-title">未登录</text>
        <text class="guest-copy">登录后查看任务进度、校园钱包、积分和订单。</text>
        <button class="guest-login-button" @click.stop="goLogin">{{ guestActionText }}</button>
      </view>
      <text class="guest-admin-entry" @click="openAdminWorkbench">🛡️ 审核管理员入口</text>
    </view>

    <template v-else>
    <view class="acc-head" :style="headStyle">
      <view class="acc-top" :style="topBarStyle">
        <button class="acc-top-btn" aria-label="消息中心" @click="openNotifications">🔔</button>
        <button class="acc-top-btn" aria-label="设置" @click="openPrivacy">⚙️</button>
      </view>
      <view class="acc-user">
        <!-- #ifdef MP-WEIXIN -->
        <button class="acc-avatar" open-type="chooseAvatar" :disabled="uploading" aria-label="更新头像" @click="chooseLegacyAvatar" @chooseavatar="onChooseWechatAvatar">
          <image v-if="displayAvatarUrl" :src="displayAvatarUrl" mode="aspectFill" @error="clearBrokenAvatar" />
          <text v-else>{{ uploading ? '···' : (auth.user?.nickname || '我').slice(0, 1) }}</text>
        </button>
        <!-- #endif -->
        <!-- #ifndef MP-WEIXIN -->
        <button class="acc-avatar" :disabled="uploading" aria-label="更新头像" @click="chooseFallbackAvatar">
          <image v-if="displayAvatarUrl" :src="displayAvatarUrl" mode="aspectFill" @error="clearBrokenAvatar" />
          <text v-else>{{ uploading ? '···' : (auth.user?.nickname || '我').slice(0, 1) }}</text>
        </button>
        <!-- #endif -->
        <view class="acc-user-main">
          <view class="acc-name-row">
            <!-- #ifdef MP-WEIXIN -->
            <input v-model="nickname" class="acc-nickname" type="nickname" maxlength="80" confirm-type="done" :disabled="savingNickname" placeholder="设置昵称" placeholder-style="color:rgba(255,255,255,.72)" @change="onNicknameChange" @nicknamereview="onNicknameReview" />
            <!-- #endif -->
            <!-- #ifndef MP-WEIXIN -->
            <input v-model="nickname" class="acc-nickname" type="text" maxlength="80" confirm-type="done" :disabled="savingNickname" placeholder="设置昵称" placeholder-style="color:rgba(255,255,255,.72)" @change="onNicknameChange" />
            <!-- #endif -->
            <text class="acc-badge">{{ memberLevel }}</text>
          </view>
          <text class="acc-id">{{ accountSubline }}</text>
        </view>
      </view>
      <view class="acc-stats">
        <view class="st"><b>{{ followingCount }}</b><span>关注</span></view>
        <view class="st"><b>{{ followerCount }}</b><span>粉丝</span></view>
        <view class="st"><b>0</b><span>信用分</span></view>
        <view class="st"><b>{{ assets?.points.available ?? 0 }}</b><span>总积分</span></view>
      </view>
    </view>

    <view v-if="modules.isLaunched('module_membership')" class="vip-card" @click="openVip">
      <view class="vip-main">
        <text class="vip-title">👑 校园 PLUS 会员</text>
        <text class="vip-sub">兑换 9 折起 · 每月领 50 积分券包</text>
      </view>
      <button class="vip-btn" @click.stop="openVip">立即开通</button>
    </view>

    <view v-if="modules.isEnabled('module_wallet')" class="data-row">
      <view class="d" @click="openAssets"><b>¥{{ money(assets?.wallet.available_amount ?? 0) }}</b><span>钱包</span></view>
      <view class="d" @click="openCoupons"><b>{{ assets?.coupons.available ?? 0 }}<em> 张</em></b><span>优惠券</span></view>
      <view class="d" @click="openAssets"><b>0</b><span>收藏</span></view>
      <view class="d" @click="openAssets"><b>0</b><span>足迹</span></view>
    </view>

    <view v-if="modules.isEnabled('module_second_hand')" class="my-task">
      <view class="sec-title"><text class="sec-h">我的闲置</text><text class="sec-more" @click="openSecondHand">闲置市场 ›</text></view>
      <view class="mt-row">
        <view class="mt-chip" @click="openMySecondHand"><b>0</b><span>我发布的</span></view>
        <view class="mt-chip" @click="openSecondHandOrders('seller')"><b>0</b><span>我卖出的</span></view>
        <view class="mt-chip" @click="openSecondHandOrders('buyer')"><b>0</b><span>我买到的</span></view>
      </view>
    </view>

    <view v-if="modules.isEnabled('module_tasks')" class="my-task">
      <view class="sec-title"><text class="sec-h">我的任务</text><text class="sec-more" @click="openTasks">全部记录 ›</text></view>
      <view class="mt-row">
        <view v-if="auth.user?.can_publish_tasks" class="mt-chip" @click="openPublisher"><b>0</b><span>我发布的</span></view>
        <view class="mt-chip" @click="openTasks"><b>0</b><span>我接的</span></view>
        <view class="mt-chip" @click="openTasks"><b>0</b><span>进行中</span></view>
        <view class="mt-chip" @click="openTasks"><b>0</b><span>待评价</span></view>
      </view>
    </view>

    <view v-if="modules.isEnabled('module_mall_trade')" class="my-task">
      <view class="sec-title"><text class="sec-h">我的订单</text><text class="sec-more" @click="openOrders()">全部订单 ›</text></view>
      <view class="mt-row">
        <view v-for="item in orderStatuses" :key="item.code" class="mt-chip" @click="item.action"><b>{{ item.count }}</b><span>{{ item.displayLabel }}</span></view>
      </view>
    </view>

    <view v-if="modules.isEnabled('module_community')" class="my-task">
      <view class="sec-title"><text class="sec-h">校园社区</text><text class="sec-more" @click="openCommunity()">进入社区 ›</text></view>
      <view class="cm-grid">
        <view v-for="item in communityServices" :key="item.code" class="cm-item" @click="item.action">
          <view :class="['cm-ic', `tone-${item.tone}`]"><image class="cm-ic-img" :src="item.iconSrc" mode="aspectFit" /></view>
          <text class="cm-label">{{ item.displayLabel }}</text>
        </view>
      </view>
    </view>

    <view class="fn-list">
      <view class="fn" @click="openAddresses"><view class="fn-ic" style="background:#E7F9EF">📍</view><text class="fn-label">收货地址</text><view class="sp" /><text class="fn-arrow">›</text></view>
      <view v-if="modules.isEnabled('module_wallet')" class="fn" @click="openAssets"><view class="fn-ic" style="background:#FFF2E5">🎫</view><text class="fn-label">积分明细</text><view class="sp" /><text class="fn-arrow">›</text></view>
      <view v-if="modules.isEnabled('module_wallet_recharge')" class="fn" @click="openRecharge"><view class="fn-ic" style="background:#FFF6DE">💳</view><text class="fn-label">钱包充值</text><view class="sp" /><text class="fn-arrow">›</text></view>
      <view v-if="auth.user?.phone_bound" class="fn" @click="openPhoneChange"><view class="fn-ic" style="background:#E7F9EF">📱</view><text class="fn-label">手机号</text><view class="sp" /><text class="fn-note">{{ auth.user?.phone_masked || '已绑定' }}</text><text class="fn-arrow">›</text></view>
      <view class="fn" @click="openSupport"><view class="fn-ic" style="background:#EAF2FF">🎧</view><text class="fn-label">客服中心</text><view class="sp" /><text class="fn-arrow">›</text></view>
      <view v-if="modules.isLaunched('module_feedback')" class="fn" @click="openSupport"><view class="fn-ic" style="background:#F1EAFF">💬</view><text class="fn-label">意见反馈</text><view class="sp" /><text class="fn-arrow">›</text></view>
      <view class="fn" @click="openPrivacy"><view class="fn-ic" style="background:#EEF1F5">⚙️</view><text class="fn-label">设置</text><view class="sp" /><text class="fn-arrow">›</text></view>
      <view class="fn" @click="openAdminWorkbench"><view class="fn-ic" style="background:#E9F7EF">🛡️</view><text class="fn-label">审核工作台</text><view class="sp" /><text v-if="admin.isLoggedIn" class="fn-note">{{ admin.displayName }}</text><text class="fn-arrow">›</text></view>
    </view>

    <view v-if="moreServices.length" class="fn-list">
      <view v-for="item in moreServices" :key="item.id" class="fn" @click="item.action">
        <view :class="['fn-ic', `tone-${item.tone}`]"><image class="fn-ic-img" :src="item.iconSrc" mode="aspectFit" /></view>
        <text class="fn-label">{{ item.displayLabel }}</text>
        <view class="sp" />
        <text class="fn-note">{{ item.note }}</text>
        <text class="fn-arrow">›</text>
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
import { getCommunitySocialStats } from '@/api/community'
import { loadAppearanceContent, type AccountAppearance } from '@/api/appearance'
import { resolveApiAssetUrl } from '@/api/http'
import { chooseAndUploadAvatar, uploadAvatarFromPath } from '@/api/upload'
import { useAuthStore } from '@/stores/auth'
import { useAdminStore } from '@/stores/admin'
import { useModulesStore } from '@/stores/modules'
import { useIconConfigStore } from '@/stores/icons'
import type { ModuleCode } from '@/platform/modules'
import { navigateToLogin } from '@/utils/authRedirect'

declare const wx: { canIUse(schema: string): boolean }

const auth = useAuthStore()
const admin = useAdminStore()
const modules = useModulesStore()
const icons = useIconConfigStore()
const nickname = ref(auth.user?.nickname ?? '')
const avatarFileId = ref(auth.user?.avatar_file_id ?? '')
const avatarPreviewUrl = ref('')
const avatarLoadFailed = ref(false)
const assets = ref<AssetOverview | null>(null)
const followingCount = ref(0)
const followerCount = ref(0)
const savingNickname = ref(false)
const uploading = ref(false)
const accountAppearance = ref<AccountAppearance>({ profile_icon_url: '', service_icons: [] })
const resolvingAccount = ref(Boolean(auth.accessToken))
// 微信小程序原生胶囊（右上角 ••• ⊙）不可遮挡、不可覆盖触摸；
// 用胶囊矩形把自定义图标行定位到胶囊左侧，并让头部内容下移避开。
const headStyle = ref<Record<string, string>>({})
const topBarStyle = ref<Record<string, string>>({})
function initCapsuleInset() {
  // #ifdef MP-WEIXIN
  try {
    const menu = uni.getMenuButtonBoundingClientRect()
    const system = uni.getSystemInfoSync()
    if (!menu || !menu.left || !menu.width || !system.windowWidth) return
    topBarStyle.value = {
      position: 'absolute',
      top: `${menu.top}px`,
      right: `${system.windowWidth - menu.left + 8}px`,
      height: `${menu.height}px`,
    }
    headStyle.value = { paddingTop: `${menu.bottom + 10}px` }
  } catch {
    // 旧基础库拿不到胶囊信息时保持默认流式布局
  }
  // #endif
}
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
const accountSubline = computed(() => auth.user?.campus?.name || '校园用户')
const baseServices = [
  { id: 'tasks', mark: '任', label: '我的任务', note: '提交与进度', tone: 'blue', action: openTasks, icon: '/static/icons/service-tasks.svg' },
  { id: 'publisher', mark: '发', label: '发布任务', note: '创建与管理', tone: 'violet', action: openPublisher, icon: '/static/icons/module-task-publish.svg' },
  { id: 'orders', mark: '单', label: '我的订单', note: '支付与履约', tone: 'orange', action: () => openOrders(), icon: '/static/icons/service-orders.svg' },
  { id: 'second_hand', mark: '闲', label: '校园闲置', note: '买卖二手好物', tone: 'green', action: openSecondHand, icon: '/static/icons/service-tasks.svg' },
  { id: 'material', mark: '资', label: '资料共享', note: '真题 · 笔记 · 网盘', tone: 'blue', action: openMaterial, icon: '/static/icons/service-tasks.svg' },
  { id: 'second_hand_orders', mark: '订', label: '闲置订单', note: '买卖交易记录', tone: 'orange', action: () => openSecondHandOrders(), icon: '/static/icons/service-orders.svg' },
  { id: 'chat', mark: '聊', label: '消息聊天', note: '与同学沟通', tone: 'blue', action: openChat, icon: '/static/icons/service-messages.svg' },
  { id: 'after_sales', mark: '售', label: '售后服务', note: '退款与换货', tone: 'red', action: openAfterSales, icon: '/static/icons/service-aftersale.svg' },
  { id: 'referrals', mark: '邀', label: '邀请好友', note: '奖励与记录', tone: 'green', action: openReferrals, icon: '/static/icons/service-invite.svg' },
  { id: 'push_team', mark: '推', label: '地推中心', note: '团队 · 推广位 · 佣金', tone: 'green', action: openPushCenter, icon: '/static/icons/service-tasks.svg' },
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
  second_hand: 'module_second_hand',
  second_hand_orders: 'module_second_hand',
  chat: 'module_second_hand',
  after_sales: 'module_after_sale',
  referrals: 'module_referrals',
  check_in: 'module_checkin',
}
const launchedServiceModuleMap: Record<string, ModuleCode> = {
  identity: 'module_identity_verification',
  // 资料共享为新增模块：未明确开启时不展示入口（fail-close）
  material: 'module_material',
}
const services = computed(() => baseServices
  .filter((item) => item.id !== 'publisher' || auth.user?.can_publish_tasks === true)
  .filter((item) => {
    const code = serviceModuleMap[item.id]
    return !code || modules.isEnabled(code)
  })
  .filter((item) => {
    const code = launchedServiceModuleMap[item.id]
    return !code || modules.isLaunched(code)
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
// 已在 v13 专属区块（头部、我的任务、我的闲置、我的订单、主功能列表）承载的服务不在“更多服务”中重复出现。
const surfacedServiceIds = ['tasks', 'publisher', 'orders', 'second_hand', 'second_hand_orders', 'notifications', 'addresses', 'support', 'security']
const moreServices = computed(() => services.value.filter((item) => !surfacedServiceIds.includes(item.id)))
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

onShow(() => {
  initCapsuleInset()
  void load()
})

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
    try {
      const stats = await getCommunitySocialStats(auth.accessToken)
      followingCount.value = stats.following_count
      followerCount.value = stats.follower_count
    } catch {
      followingCount.value = 0
      followerCount.value = 0
    }
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
function openCoupons() { uni.navigateTo({ url: '/pages/coupons/index' }) }
function openRecharge() { uni.navigateTo({ url: '/pages/wallet-recharge/index' }) }
function openVip() { uni.showToast({ title: '校园 PLUS 会员即将上线', icon: 'none' }) }
function openTasks() { uni.setStorageSync('task_mall_open_my_tasks', true); uni.reLaunch({ url: '/pages/tasks/index' }) }
function openPublisher() { uni.navigateTo({ url: '/pages/publisher/index' }) }
function openOrders(status?: string) { uni.navigateTo({ url: `/pages/orders/index${status ? `?status=${status}` : ''}` }) }
function openAfterSales() { uni.navigateTo({ url: '/pages/after-sales/index' }) }
function openReferrals() { uni.navigateTo({ url: '/pages/referrals/index' }) }
function openPushCenter() { uni.navigateTo({ url: '/pages/push-center/index' }) }
function openCheckIn() { uni.navigateTo({ url: '/pages/check-in/index' }) }
function openNotifications() { uni.navigateTo({ url: '/pages/notifications/index' }) }
function openSecondHand() { uni.navigateTo({ url: '/pages/second-hand/index' }) }
function openMaterial() { uni.navigateTo({ url: '/pages/material/index' }) }
function openMySecondHand() { uni.navigateTo({ url: '/pages/second-hand-my/index' }) }
function openSecondHandOrders(role?: 'buyer' | 'seller') { uni.navigateTo({ url: `/pages/second-hand-orders/index${role ? `?role=${role}` : ''}` }) }
function openChat() { uni.navigateTo({ url: '/pages/chat/index' }) }
function openPrivacy() { uni.navigateTo({ url: '/pages/privacy/index' }) }
function openAdminWorkbench() {
  // 管理员会话与普通用户会话独立：已登录直接进工作台，否则进管理员登录页
  const url = admin.isLoggedIn ? '/pages/admin/review/index' : '/pages/admin/login/index'
  uni.navigateTo({ url })
}
function openIdentityVerification() { uni.navigateTo({ url: '/pages/identity-verification/index' }) }
function openAddresses() { uni.navigateTo({ url: '/pages/addresses/index' }) }
function openLegal() { uni.navigateTo({ url: '/pages/legal/index' }) }
function openSupport() { uni.navigateTo({ url: '/pages/support/index' }) }
function openPhoneChange() { uni.navigateTo({ url: '/pages/phone-change/index' }) }
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
/* 校圈 v13 个人中心：绿渐变头部 + 黑金会员卡 + 白色数据卡，390 设计稿数值 ×2 = rpx。 */
.page{--green:#0BBF63;--green-deep:#08A152;--green-soft:#E7F9EF;--orange-deep:#F2620A;--t1:#191B1F;--t2:#767C85;--t3:#AEB3BB;min-height:100vh;padding-bottom:24rpx;box-sizing:border-box;background:#F4F6F4;color:var(--t1)}
.acc-head{position:relative;overflow:hidden;padding:calc(var(--status-bar-height) + 20rpx) 32rpx 128rpx;background:linear-gradient(135deg,#12D978,#08A152)}
.acc-head::before{content:'';position:absolute;top:-220rpx;right:-120rpx;width:440rpx;height:440rpx;border-radius:50%;background:rgba(255,255,255,.09)}
.acc-top{position:relative;z-index:2;display:flex;align-items:center;justify-content:flex-end;gap:20rpx}
.acc-top-btn{display:flex;align-items:center;justify-content:center;width:64rpx;height:64rpx;margin:0;padding:0;border-radius:50%;background:rgba(255,255,255,.16);color:#fff;font-size:30rpx;line-height:1}
.acc-top-btn::after{border:0}
.acc-user{position:relative;display:flex;align-items:center;gap:26rpx;margin-top:20rpx}
.acc-avatar{display:flex;align-items:center;justify-content:center;width:124rpx;height:124rpx;flex:none;margin:0;padding:0;overflow:hidden;border:5rpx solid rgba(255,255,255,.7);border-radius:50%;background:rgba(255,255,255,.24);color:#fff;font-size:42rpx;font-weight:800;line-height:1}
.acc-avatar::after{border:0}
.acc-avatar image{width:100%;height:100%;border-radius:50%}
.acc-user-main{min-width:0;flex:1}
.acc-name-row{display:flex;align-items:center;gap:14rpx}
.acc-nickname{min-width:0;flex:1;height:56rpx;padding:0;background:transparent;color:#fff;font-size:38rpx;font-weight:800;line-height:56rpx}
.acc-badge{display:flex;align-items:center;flex:none;height:36rpx;padding:0 14rpx;border-radius:18rpx;background:rgba(255,255,255,.22);color:#fff;font-size:20rpx}
.acc-id{display:block;margin-top:10rpx;color:rgba(255,255,255,.8);font-size:22rpx}
.acc-stats{position:relative;display:flex;margin-top:28rpx;color:#fff}
.acc-stats .st{flex:1}
.acc-stats b{display:block;font-size:34rpx;font-weight:800;font-variant-numeric:tabular-nums}
.acc-stats span{font-size:22rpx;opacity:.85}
.vip-card{position:relative;z-index:5;display:flex;align-items:center;margin:-92rpx 24rpx 0;padding:30rpx 32rpx;overflow:hidden;border-radius:32rpx;background:linear-gradient(120deg,#2B2E33,#454A52 60%,#2B2E33);box-shadow:0 20rpx 48rpx rgba(30,33,38,.25);color:#F5C76A}
.vip-card::after{content:'';position:absolute;top:-160rpx;right:-60rpx;width:300rpx;height:300rpx;border-radius:50%;background:rgba(245,199,106,.08)}
.vip-main{position:relative;flex:1}
.vip-title{display:block;font-size:30rpx;font-weight:800}
.vip-sub{display:block;margin-top:10rpx;color:rgba(245,199,106,.75);font-size:20rpx}
.vip-btn{position:relative;display:flex;align-items:center;height:56rpx;margin:0;padding:0 28rpx;border-radius:28rpx;background:linear-gradient(135deg,#F8D98A,#E8B84B);color:#5A3D00;font-size:22rpx;font-weight:800;line-height:56rpx}
.vip-btn::after{border:0}
.data-row{display:flex;margin:20rpx 24rpx 0;padding:30rpx 0;border-radius:32rpx;background:#fff;box-shadow:0 2rpx 4rpx rgba(23,26,23,.03),0 12rpx 40rpx rgba(23,26,23,.05)}
.data-row .d{position:relative;display:flex;flex:1;align-items:center;flex-direction:column;gap:8rpx}
.data-row .d+.d::before{content:'';position:absolute;top:16rpx;bottom:16rpx;left:0;width:1rpx;background:#EDEFEF}
.data-row b{font-size:34rpx;font-weight:800;font-variant-numeric:tabular-nums}
.data-row b em{font-style:normal;color:#FF3B30;font-size:20rpx;font-weight:600}
.data-row span{color:var(--t2);font-size:22rpx}
.my-task{margin:20rpx 24rpx 0;padding-bottom:12rpx;border-radius:32rpx;background:#fff;box-shadow:0 2rpx 4rpx rgba(23,26,23,.03),0 12rpx 40rpx rgba(23,26,23,.05)}
.sec-title{display:flex;align-items:center;justify-content:space-between;padding:28rpx 28rpx 20rpx}
.sec-h{display:flex;align-items:center;gap:14rpx;font-size:32rpx;font-weight:700}
.sec-h::before{content:'';width:8rpx;height:28rpx;border-radius:4rpx;background:var(--green)}
.sec-more{display:flex;align-items:center;color:var(--t3);font-size:24rpx}
.mt-row{display:flex;padding:4rpx 20rpx 20rpx}
.mt-chip{display:flex;flex:1;align-items:center;flex-direction:column;gap:10rpx;margin:0 8rpx;padding:18rpx 4rpx;border-radius:22rpx;background:#F7F9F8}
.mt-chip b{font-size:32rpx;font-weight:800}
.mt-chip span{color:var(--t2);font-size:20rpx;white-space:nowrap}
.cm-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:8rpx;padding:0 20rpx 20rpx}
.cm-item{display:flex;min-width:0;align-items:center;flex-direction:column;gap:10rpx;padding:18rpx 6rpx;border-radius:22rpx;background:#F7F9F8}
.cm-ic{display:flex;align-items:center;justify-content:center;width:64rpx;height:64rpx;border-radius:20rpx}
.cm-ic-img{display:block;width:60%;height:60%}
.cm-label{overflow:hidden;max-width:100%;color:var(--t1);font-size:22rpx;text-overflow:ellipsis;white-space:nowrap}
.fn-list{margin:20rpx 24rpx 0;padding:8rpx 0;border-radius:32rpx;background:#fff;box-shadow:0 2rpx 4rpx rgba(23,26,23,.03),0 12rpx 40rpx rgba(23,26,23,.05)}
.fn{display:flex;align-items:center;gap:22rpx;padding:26rpx 30rpx;font-size:26rpx;font-weight:500}
.fn+.fn{border-top:1rpx solid #F5F6F6}
.fn-ic{display:flex;align-items:center;justify-content:center;width:64rpx;height:64rpx;flex:none;border-radius:20rpx;font-size:30rpx}
.fn-ic-img{display:block;width:60%;height:60%}
.fn-label{color:var(--t1)}
.fn .sp{flex:1}
.fn-note{overflow:hidden;max-width:40%;color:var(--t3);font-size:22rpx;text-overflow:ellipsis;white-space:nowrap}
.fn-arrow{color:var(--t3);font-size:34rpx;font-weight:300;line-height:1}
.tone-blue{background:#EAF2FF;color:#3B82F6}
.tone-orange{background:#FFF2E5;color:#F2620A}
.tone-red{background:#FFEAEA;color:#D14343}
.tone-green{background:#E7F9EF;color:#0BBF63}
.tone-violet{background:#F1EAFF;color:#8B5CF6}
.tone-slate{background:#EEF1F5;color:#64748B}
.logout{height:88rpx;margin:24rpx 24rpx 0;border-radius:24rpx;background:#fff;color:#D14343;font-size:28rpx;line-height:88rpx}
.logout::after{border:0}
.state-page{padding:calc(var(--status-bar-height) + 20rpx) 24rpx 0}
.state-card{display:flex;align-items:center;flex-direction:column;padding:120rpx 48rpx;border-radius:32rpx;background:#fff;box-shadow:0 2rpx 4rpx rgba(23,26,23,.03),0 12rpx 40rpx rgba(23,26,23,.05);text-align:center}
.state-mark{display:flex;align-items:center;justify-content:center;width:104rpx;height:104rpx;border-radius:32rpx;background:var(--green);color:#fff;font-size:40rpx;font-weight:800}
.loading-mark{background:var(--green-soft);color:var(--green-deep)}
.state-title{display:block;margin-top:32rpx;color:var(--t1);font-size:34rpx;font-weight:700}
.state-copy{display:block;margin-top:12rpx;color:var(--t2);font-size:26rpx}
.guest-account{position:relative;display:flex;align-items:center;flex-direction:column;padding:140rpx 48rpx 100rpx;overflow:hidden;border-radius:32rpx;background:linear-gradient(135deg,#12D978,#08A152);box-shadow:0 20rpx 48rpx rgba(8,161,82,.25);text-align:center}
.guest-account::before{content:'';position:absolute;top:-220rpx;right:-120rpx;width:440rpx;height:440rpx;border-radius:50%;background:rgba(255,255,255,.09)}
.guest-account .state-mark{position:relative;background:rgba(255,255,255,.2)}
.guest-title{display:block;margin-top:32rpx;color:#fff;font-size:44rpx;font-weight:800}
.guest-copy{display:block;max-width:520rpx;margin-top:16rpx;color:rgba(255,255,255,.85);font-size:26rpx;line-height:1.6}
.guest-login-button{position:relative;width:100%;height:88rpx;margin-top:56rpx;border-radius:44rpx;background:#fff;color:var(--green-deep);font-size:30rpx;font-weight:800;line-height:88rpx}
.guest-login-button::after{border:0}
.guest-admin-entry{display:block;margin-top:32rpx;color:#767C85;font-size:24rpx;text-align:center}
</style>
