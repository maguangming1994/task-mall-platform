<template>
  <view class="page">
    <view class="account-toolbar">
      <view class="toolbar-spacer" />
      <text>账户中心</text>
      <view class="toolbar-actions">
        <view @click="openPrivacy">⚙</view>
        <view @click="openNotifications">信</view>
      </view>
    </view>

    <view class="account-hero">
      <image class="account-hero-art" src="/static/home/hero-campus-reward.png" mode="aspectFill" />
      <view class="hero-profile">
        <view class="profile-mark"><image v-if="accountAppearance.profile_icon_url && !avatarFileId" :src="accountAppearance.profile_icon_url" mode="aspectFill" /><text v-else>{{ (auth.user?.nickname || '我').slice(0, 1) }}</text></view>
        <view class="profile-copy">
          <text class="profile-badge">CAMPUS · {{ memberLevel }}</text>
          <text class="title">{{ auth.user?.nickname || '我的账户' }}</text>
          <text class="subtitle">管理校园身份与个人权益</text>
        </view>
        <button class="profile-edit" @click="showProfileEditor = !showProfileEditor">{{ showProfileEditor ? '收起' : '编辑资料' }}</button>
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

    <view v-if="showProfileEditor" class="profile-editor">
      <view class="section-row"><view><text class="section-kicker">PROFILE</text><text class="section-title">完善个人资料</text></view><text class="section-note">完善后可领取对应奖励</text></view>
      <view class="profile-field">
        <text>昵称</text>
        <input v-model="nickname" class="input" maxlength="80" placeholder="请输入昵称" />
      </view>
      <view class="button-row">
        <button class="secondary" :loading="uploading" @click="uploadAvatar">{{ avatarFileId ? '更换头像' : '上传头像' }}</button>
        <button class="primary" :loading="saving" @click="saveProfile">保存资料</button>
      </view>
      <text class="hint">昵称、头像和手机号完成验证后，即可计入资料完善进度。</text>
    </view>

    <view class="wallet-layout">
      <view class="wallet-main" @click="openAssets">
        <view class="wallet-head"><text>校园钱包</text><text>资产明细 ›</text></view>
        <strong>¥{{ money(assets?.wallet.available_amount ?? 0) }}</strong>
        <text class="wallet-caption">可用余额 · 永久有效</text>
        <view class="wallet-action-row">
          <button @click.stop="openRecharge">余额充值</button>
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

    <view class="order-card">
      <view class="section-row">
        <view><text class="section-kicker">CAMPUS ORDERS</text><text class="section-title">我的订单</text></view>
        <text class="section-note" @click="openOrders()">全部订单 ›</text>
      </view>
      <view class="order-status-grid">
        <view v-for="item in orderStatuses" :key="item.label" class="order-status-item" @click="item.action">
          <view :class="['order-mark', `tone-${item.tone}`]">{{ item.mark }}</view>
          <strong>{{ item.count }}</strong>
          <text>{{ item.label }}</text>
        </view>
      </view>
    </view>

    <view class="community-services">
      <view class="section-row">
        <view><text class="section-kicker">CAMPUS COMMUNITY</text><text class="section-title">校园社区</text></view>
        <text class="section-note" @click="openCommunity()">进入社区 ›</text>
      </view>
      <view class="community-grid">
        <view v-for="item in communityServices" :key="item.label" class="community-item" @click="item.action">
          <view :class="['service-icon', `tone-${item.tone}`]">{{ item.mark }}</view>
          <text class="service-label">{{ item.label }}</text>
          <text class="service-note">{{ item.note }}</text>
        </view>
      </view>
    </view>

    <view class="quick-services">
      <view class="section-row"><view><text class="section-kicker">QUICK ACCESS</text><text class="section-title">常用服务</text></view><text class="section-note">账户与校园服务</text></view>
      <view class="quick-grid">
        <view v-for="item in services" :key="item.label" class="quick-card" @click="item.action">
          <view :class="['service-icon', `tone-${item.tone}`]"><image v-if="item.icon_url" :src="item.icon_url" mode="aspectFit" /><text v-else>{{ item.mark }}</text></view>
          <view><text class="service-label">{{ item.label }}</text><text class="service-note">{{ item.note }}</text></view>
        </view>
      </view>
    </view>

    <button class="logout" @click="logout">退出当前账号</button>
    <AppTabBar current-target="account" />
  </view>
</template>

<script setup lang="ts">
import { onShow } from '@dcloudio/uni-app'
import { computed, ref } from 'vue'
import AppTabBar from '@/components/AppTabBar.vue'
import { loadAssetOverview, updateProfile, type AssetOverview } from '@/api/account'
import { loadAppearanceContent, type AccountAppearance } from '@/api/appearance'
import { chooseAndUploadAvatar } from '@/api/upload'
import { useAuthStore } from '@/stores/auth'
import { navigateToLogin } from '@/utils/authRedirect'

const auth = useAuthStore()
const nickname = ref(auth.user?.nickname ?? '')
const avatarFileId = ref(auth.user?.avatar_file_id ?? '')
const assets = ref<AssetOverview | null>(null)
const saving = ref(false)
const uploading = ref(false)
const showProfileEditor = ref(false)
const accountAppearance = ref<AccountAppearance>({ profile_icon_url: '', service_icons: [] })
const profileCompletion = computed(() => Math.min(100, (nickname.value.trim() ? 35 : 0) + (avatarFileId.value ? 35 : 0) + (auth.user ? 30 : 0)))
const memberLevel = computed(() => (profileCompletion.value >= 100 ? '认证成员' : profileCompletion.value >= 65 ? '成长成员' : '新同学'))
const completedGoal = computed(() => `${Math.min(5, Math.floor((assets.value?.points.available ?? 0) / 500))}/5`)
const baseServices = [
  { id: 'tasks', mark: '任', label: '我的任务', note: '提交与进度', tone: 'blue', action: openTasks },
  { id: 'orders', mark: '单', label: '我的订单', note: '支付与履约', tone: 'orange', action: () => openOrders() },
  { id: 'after_sales', mark: '售', label: '售后服务', note: '退款与换货', tone: 'red', action: openAfterSales },
  { id: 'referrals', mark: '邀', label: '邀请好友', note: '奖励与记录', tone: 'green', action: openReferrals },
  { id: 'check_in', mark: '签', label: '每日签到', note: '连续签到领积分', tone: 'orange', action: openCheckIn },
  { id: 'notifications', mark: '信', label: '消息中心', note: '通知与提醒', tone: 'violet', action: openNotifications },
  { id: 'addresses', mark: '址', label: '收货地址', note: '管理配送信息', tone: 'green', action: openAddresses },
  { id: 'legal', mark: '约', label: '协议隐私', note: '授权与版本', tone: 'slate', action: openLegal },
  { id: 'security', mark: '安', label: '账号安全', note: '换绑与注销', tone: 'blue', action: openPrivacy },
  { id: 'identity', mark: '证', label: '实名认证', note: '身份二要素核验', tone: 'green', action: openIdentityVerification },
  { id: 'support', mark: '服', label: '联系客服', note: '扫码进入小程序', tone: 'green', action: openSupport },
]
const services = computed(() => baseServices.map((item) => {
  const configured = accountAppearance.value.service_icons.find((icon) => icon.id === item.id)
  return { ...item, mark: configured?.mark || item.mark, icon_url: configured?.icon_url || '' }
}))
const orderStatuses = computed(() => [
  { mark: '待', label: '待完成', count: 0, tone: 'blue', action: () => openOrders('pending') },
  { mark: '审', label: '待审核', count: 0, tone: 'orange', action: () => openOrders('reviewing') },
  { mark: '成', label: '已完成', count: 0, tone: 'green', action: () => openOrders('completed') },
  { mark: '售', label: '售后退款', count: 0, tone: 'red', action: openAfterSales },
])
const communityServices = [
  { mark: '发', label: '发布帖子', note: '分享校园生活', tone: 'green', action: () => openCommunityCompose('experience') },
  { mark: '团', label: '校园组团', note: '自习运动拼车', tone: 'orange', action: openActivities },
  { mark: '助', label: '有偿求助', note: '发布校园需求', tone: 'red', action: () => openCommunityCompose('question') },
  { mark: '学', label: '学习交流', note: '经验与攻略', tone: 'blue', action: () => openCommunity('guide') },
  { mark: '展', label: '成果展示', note: '作品与成长', tone: 'violet', action: () => openCommunity('showcase') },
  { mark: '问', label: '互助问答', note: '同学来解答', tone: 'slate', action: () => openCommunity('question') },
]

onShow(load)

async function load() {
  if (!auth.accessToken) return navigateToLogin()
  const [, appearanceResult] = await Promise.all([auth.fetchCurrentUser(), loadAppearanceContent()])
  accountAppearance.value = appearanceResult.account
  nickname.value = auth.user?.nickname ?? ''
  avatarFileId.value = auth.user?.avatar_file_id ?? ''
  assets.value = await loadAssetOverview(auth.accessToken)
}

async function uploadAvatar() {
  uploading.value = true
  try {
    avatarFileId.value = await chooseAndUploadAvatar(auth.accessToken)
    uni.showToast({ title: '头像检查通过', icon: 'success' })
  } catch (error) {
    uni.showToast({ title: error instanceof Error ? error.message : '上传失败', icon: 'none' })
  } finally { uploading.value = false }
}

async function saveProfile() {
  saving.value = true
  try {
    auth.user = await updateProfile(auth.accessToken, { nickname: nickname.value, avatar_file_id: avatarFileId.value || null })
    auth.persist()
    assets.value = await loadAssetOverview(auth.accessToken)
    showProfileEditor.value = false
    uni.showToast({ title: '资料已保存', icon: 'success' })
  } catch (error) { uni.showToast({ title: error instanceof Error ? error.message : '保存失败', icon: 'none' }) }
  finally { saving.value = false }
}

function money(cents: number) { return (cents / 100).toFixed(2) }
function openAssets() { uni.navigateTo({ url: '/pages/assets/index' }) }
function openRecharge() { uni.navigateTo({ url: '/pages/wallet-recharge/index' }) }
function openTasks() { uni.setStorageSync('task_mall_open_my_tasks', true); uni.reLaunch({ url: '/pages/tasks/index' }) }
function openOrders(status?: string) { uni.navigateTo({ url: `/pages/orders/index${status ? `?status=${status}` : ''}` }) }
function openAfterSales() { uni.navigateTo({ url: '/pages/after-sales/index' }) }
function openReferrals() { uni.navigateTo({ url: '/pages/referrals/index' }) }
function openCheckIn() { uni.navigateTo({ url: '/pages/check-in/index' }) }
function openNotifications() { uni.navigateTo({ url: '/pages/notifications/index' }) }
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
.profile-copy{min-width:0;flex:1}.profile-badge{display:inline-block;margin-bottom:7rpx;padding:3rpx 9rpx;border:2rpx solid var(--tm-ink-900);border-radius:999rpx;background:#fff;font-size:13rpx;font-weight:950;letter-spacing:.08em}.profile-score{display:flex;width:78rpx;height:78rpx;align-items:center;justify-content:center;flex-direction:column;flex:none;border:3rpx solid var(--tm-ink-900);border-radius:22rpx;background:#fff;box-shadow:3rpx 4rpx 0 rgba(7,26,51,.18)}.profile-score strong{font-size:24rpx;font-weight:950}.profile-score text{font-size:13rpx;font-weight:750}.profile-progress{height:9rpx;margin-top:12rpx;overflow:hidden;border:2rpx solid var(--tm-ink-900);border-radius:999rpx;background:#fff}.profile-progress view{height:100%;background:linear-gradient(90deg,var(--tm-campus-coral),var(--tm-campus-purple))}.account-brief{margin-top:22rpx;padding:23rpx;background:linear-gradient(135deg,#fff 0%,var(--tm-campus-cyan-soft) 100%)}.account-brief>view:first-child strong,.account-brief>view:first-child small{display:block}.account-brief>view:first-child strong{margin-top:9rpx;font-size:28rpx;font-weight:950}.account-brief>view:first-child small{margin-top:4rpx;color:var(--tm-ink-700);font-size:17rpx}.brief-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:9rpx;margin-top:18rpx}.brief-grid view{padding:13rpx 8rpx;border:2rpx solid var(--tm-ink-900);border-radius:15rpx;background:#fff}.brief-grid view:nth-child(2){background:var(--tm-campus-yellow-soft)}.brief-grid view:nth-child(3){background:var(--tm-campus-lime-soft)}.brief-grid strong,.brief-grid text{display:block}.brief-grid strong{font-size:22rpx;font-weight:950}.brief-grid text{margin-top:3rpx;color:var(--tm-ink-700);font-size:14rpx}
.page{min-height:100vh;padding:36rpx 28rpx 80rpx;box-sizing:border-box;background:#f5f7fb}.heading{margin:12rpx 4rpx 28rpx}.title,.subtitle,.section-title,.hint,.asset-value,.asset text,.address-name,.address-detail,.empty{display:block}.title{font-size:44rpx;font-weight:750}.subtitle{margin-top:8rpx;color:#667085}.card{margin-top:24rpx;padding:28rpx;border-radius:24rpx;background:#fff}.section-title{font-size:30rpx;font-weight:700}.section-row{display:flex;justify-content:space-between;align-items:center}.input{height:76rpx;margin-top:18rpx;padding:0 20rpx;border:1rpx solid #dfe3eb;border-radius:14rpx;box-sizing:border-box;background:#fafbfc}.triple{display:grid;grid-template-columns:repeat(3,1fr);gap:12rpx}.button-row{display:flex;gap:16rpx;margin-top:22rpx}.primary,.secondary{flex:1;height:78rpx;border-radius:16rpx;font-size:26rpx;line-height:78rpx}.primary{background:#3157d5;color:#fff}.secondary{border:1rpx solid #cfd5e2;background:#fff;color:#475467}.primary::after,.secondary::after,.delete::after{border:0}.hint{margin-top:16rpx;color:#98a2b3;font-size:23rpx;line-height:1.5}.asset-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:14rpx;margin-top:24rpx}.asset{padding:24rpx 10rpx;text-align:center;border-radius:20rpx;background:#fff;color:#667085;font-size:22rpx}.asset-value{margin-bottom:8rpx;color:#1d2939;font-size:32rpx;font-weight:750}.address-item{display:flex;align-items:center;gap:12rpx;padding:22rpx 0;border-bottom:1rpx solid #eef0f4}.address-main{flex:1;position:relative}.address-name{font-weight:650}.address-detail{margin-top:8rpx;color:#667085;font-size:24rpx;line-height:1.5}.default-tag{display:inline-block;margin-top:8rpx;padding:4rpx 12rpx;border-radius:10rpx;background:#eef3ff;color:#3157d5;font-size:20rpx}.delete{width:110rpx;background:#fff;color:#d92d20;font-size:23rpx}.empty{padding:34rpx 0;text-align:center;color:#98a2b3}.checkbox{display:flex;align-items:center;margin-top:20rpx;color:#475467;font-size:25rpx}
.page{padding:32rpx 28rpx 90rpx;background:var(--tm-canvas)}.heading{display:flex;align-items:center;gap:20rpx;margin:0;padding:30rpx;border:1rpx solid var(--tm-line);border-radius:26rpx;background:var(--tm-brand-50);box-shadow:var(--tm-shadow-card);color:var(--tm-ink-900)}.profile-mark{display:flex;align-items:center;justify-content:center;width:92rpx;height:92rpx;flex:none;border-radius:26rpx;background:var(--tm-brand-600);color:#fff;font-size:34rpx;font-weight:850}.heading .title{font-size:35rpx;font-weight:850}.heading .subtitle{color:var(--tm-ink-500);font-size:23rpx}.card{border:1rpx solid var(--tm-line);border-radius:24rpx;box-shadow:var(--tm-shadow-card)}.section-title{font-size:28rpx;font-weight:800}.section-note{color:var(--tm-ink-500);font-size:21rpx}.input{border-color:var(--tm-line);border-radius:15rpx;background:#fafcfc}.primary,.secondary{border-radius:17rpx;font-weight:750}.primary{background:var(--tm-brand-600)}.secondary{border-color:var(--tm-line);color:var(--tm-ink-700)}.hint{color:var(--tm-ink-500)}.asset-grid{grid-template-columns:repeat(3,minmax(0,1fr));gap:12rpx}.asset{border:1rpx solid var(--tm-line);border-radius:20rpx;box-shadow:var(--tm-shadow-card);color:var(--tm-ink-500)}.asset:first-child{border-color:#f2dec4;background:var(--tm-reward-100)}.asset:first-child .asset-value{color:var(--tm-reward-600)}.service-card{padding-bottom:18rpx}.service-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10rpx;margin-top:22rpx}.service-item{display:flex;min-width:0;align-items:center;flex-direction:column;padding:18rpx 6rpx;border-radius:20rpx;transition:transform var(--tm-motion-fast) var(--tm-ease),background-color var(--tm-motion-fast) ease}.service-item:active{transform:scale(.96);background:var(--tm-surface-soft)}.service-icon{display:flex;align-items:center;justify-content:center;width:62rpx;height:62rpx;border-radius:18rpx;background:var(--tm-brand-100);color:var(--tm-brand-700);font-size:23rpx;font-weight:850}.service-icon.tone-orange{background:var(--tm-reward-100);color:var(--tm-reward-600)}.service-icon.tone-red{background:#fff0ef;color:#d14343}.service-icon.tone-green{background:#e9f8f2;color:#14835f}.service-icon.tone-violet{background:#f1edff;color:#7654c6}.service-icon.tone-slate{background:#eef1f5;color:#526079}.service-label{margin-top:10rpx;color:var(--tm-ink-900);font-size:22rpx;font-weight:750}.service-note{margin-top:4rpx;overflow:hidden;color:var(--tm-ink-500);font-size:18rpx;text-overflow:ellipsis;white-space:nowrap}.logout{height:76rpx;margin-top:24rpx;border:1rpx solid #f1caca;border-radius:17rpx;background:#fff;color:var(--tm-danger);font-size:24rpx;line-height:74rpx}
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
.account-hero .profile-badge{margin:0 0 5rpx;padding:2rpx 10rpx;border:1rpx solid rgba(255,255,255,.38);background:rgba(255,255,255,.12);color:#e9fdff;font-size:12rpx}
.account-hero .title{color:#fff;font-size:34rpx;font-weight:900}
.account-hero .subtitle{margin-top:3rpx;color:rgba(255,255,255,.74);font-size:20rpx}
.profile-edit{position:relative;z-index:2;width:auto;height:56rpx;flex:none;margin:0;padding:0 18rpx;border:1rpx solid rgba(255,255,255,.42);border-radius:999rpx;background:rgba(255,255,255,.14);color:#fff;font-size:19rpx;font-weight:800;line-height:54rpx}
.profile-edit::after{border:0}
.account-hero .profile-progress{position:relative;z-index:1;height:8rpx;margin-top:22rpx;border:0;background:rgba(255,255,255,.22)}
.account-hero .profile-progress view{border-radius:999rpx;background:linear-gradient(90deg,#fff17a,#b8ffda)}
.hero-meta{position:relative;z-index:1;display:grid;grid-template-columns:repeat(3,1fr);gap:1rpx;margin-top:18rpx;overflow:hidden;border-radius:20rpx;background:rgba(255,255,255,.16)}
.hero-meta view{padding:15rpx 10rpx;background:rgba(2,41,57,.12);text-align:center}
.hero-meta strong,.hero-meta text{display:block}.hero-meta strong{font-size:21rpx;font-weight:900}.hero-meta text{margin-top:3rpx;color:rgba(255,255,255,.68);font-size:14rpx}
.profile-editor{margin-top:18rpx;padding:24rpx;border:1rpx solid #dce8ec;border-radius:26rpx;background:#fff;box-shadow:0 12rpx 28rpx rgba(26,71,91,.08)}
.profile-editor .input{border:1rpx solid #d9e5e8;background:#f8fbfc}.profile-editor .primary{border:0;background:#147f96;box-shadow:none}.profile-editor .secondary{border:1rpx solid #cbdadd;background:#fff;box-shadow:none}
.section-kicker{display:block;color:#16839b;font-size:13rpx;font-weight:900;letter-spacing:.14em}
.wallet-layout{display:grid;grid-template-columns:1.22fr .78fr;gap:14rpx;margin-top:20rpx}
.wallet-main{position:relative;min-height:240rpx;padding:24rpx;overflow:hidden;border-radius:28rpx;background:linear-gradient(145deg,#182c46,#234c67);box-shadow:0 16rpx 34rpx rgba(20,47,70,.18);box-sizing:border-box;color:#fff}
.wallet-main::after{content:'¥';position:absolute;right:-4rpx;bottom:-36rpx;color:rgba(255,255,255,.07);font-size:180rpx;font-weight:900}
.wallet-head{position:relative;z-index:1;display:flex;justify-content:space-between;align-items:center}.wallet-head text:first-child{font-size:22rpx;font-weight:850}.wallet-head text:last-child{color:#b9d5df;font-size:16rpx}
.wallet-main>strong{position:relative;z-index:1;display:block;margin-top:27rpx;font-size:43rpx;font-weight:900;letter-spacing:-1rpx}.wallet-caption{position:relative;z-index:1;display:block;margin-top:4rpx;color:#a9c4cf;font-size:17rpx}
.wallet-main button{position:absolute;z-index:2;left:24rpx;bottom:22rpx;width:auto;height:57rpx;margin:0;padding:0 23rpx;border:0;border-radius:999rpx;background:#fff17a;color:#173044;font-size:19rpx;font-weight:900;line-height:57rpx}.wallet-main button::after{border:0}
.asset-side{display:grid;grid-template-rows:1fr 1fr;gap:12rpx}.asset-mini{display:flex;min-height:112rpx;align-items:center;justify-content:space-between;gap:8rpx;padding:18rpx;border:1rpx solid #dce7ea;border-radius:24rpx;background:#fff;box-shadow:0 10rpx 22rpx rgba(26,71,91,.07);box-sizing:border-box}.asset-mini.points{background:linear-gradient(145deg,#fff9d9,#fff)}.asset-mini.coupons{background:linear-gradient(145deg,#f1edff,#fff)}.asset-mini text,.asset-mini small{display:block}.asset-mini text{font-size:20rpx;font-weight:850}.asset-mini small{margin-top:4rpx;color:#788691;font-size:13rpx;line-height:1.35}.asset-mini strong{flex:none;color:#183246;font-size:23rpx;font-weight:900}
.growth-card{position:relative;margin-top:18rpx;padding:23rpx 112rpx 23rpx 24rpx;overflow:hidden;border:1rpx solid #d9e7ea;border-radius:27rpx;background:#fff;box-shadow:0 11rpx 26rpx rgba(26,71,91,.07)}.growth-copy strong,.growth-copy small{display:block}.growth-copy strong{margin-top:6rpx;color:#172c3f;font-size:27rpx;font-weight:900}.growth-copy small{margin-top:4rpx;color:#75838e;font-size:16rpx}.growth-target{position:absolute;right:22rpx;top:22rpx;display:flex;width:72rpx;height:72rpx;align-items:center;justify-content:center;flex-direction:column;border-radius:22rpx;background:#e5f7f8;color:#176c80}.growth-target strong{font-size:23rpx;font-weight:900}.growth-target text{font-size:12rpx}.growth-pulse{height:7rpx;margin-top:16rpx;overflow:hidden;border-radius:999rpx;background:#eaf0f2}.growth-pulse view{height:100%;border-radius:999rpx;background:linear-gradient(90deg,#2eb7ad,#7ad977)}
.section-heading{display:flex;align-items:flex-end;justify-content:space-between;gap:16rpx;margin:28rpx 4rpx 12rpx}.section-heading>text{color:#788691;font-size:16rpx}.section-title{margin-top:4rpx;color:#172c3f;font-size:29rpx;font-weight:900}
.quick-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12rpx}.quick-card{display:flex;min-width:0;align-items:center;gap:12rpx;padding:18rpx;border:1rpx solid #dce7ea;border-radius:23rpx;background:#fff;box-shadow:0 10rpx 22rpx rgba(26,71,91,.07)}.quick-card:nth-child(2){background:#fff9e7}.quick-card:nth-child(3){background:#fff3f1}.quick-card:nth-child(4){background:#eefaf5}.quick-card>view:nth-child(2){min-width:0;flex:1}.quick-arrow{color:#9aa8b2;font-size:34rpx;line-height:1}.quick-card .service-icon{width:52rpx;height:52rpx;flex:none;border:0;border-radius:17rpx}.quick-card .service-label{margin:0;font-size:21rpx}.quick-card .service-note{font-size:15rpx}
.more-services{margin-top:18rpx;padding:24rpx;border:1rpx solid #dce7ea;border-radius:28rpx;background:#fff;box-shadow:0 12rpx 28rpx rgba(26,71,91,.07)}.more-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10rpx;margin-top:19rpx}.more-service-item{display:flex;min-width:0;align-items:center;gap:11rpx;padding:15rpx 12rpx;border-radius:18rpx;background:#f6f9fa}.more-service-item:active{transform:scale(.98);background:#edf4f6}.more-service-item>view:nth-child(2){min-width:0;flex:1}.more-service-item .service-icon{width:48rpx;height:48rpx;flex:none;border:0;border-radius:15rpx;font-size:19rpx}.more-service-item .service-label{margin:0;font-size:19rpx}.more-service-item .service-note{font-size:14rpx}
.service-icon{box-shadow:none!important}.service-icon image,.profile-mark image{width:100%;height:100%;border-radius:inherit}.service-icon.tone-blue{background:#e5f3ff;color:#1474bc}.service-icon.tone-orange{background:#fff0d6;color:#b86800}.service-icon.tone-red{background:#ffebea;color:#cc3e45}.service-icon.tone-green{background:#e6f8ef;color:#18825e}.service-icon.tone-violet{background:#f0ebff;color:#6b4fc7}.service-icon.tone-slate{background:#ebf0f3;color:#516473}
.logout{height:68rpx;margin:20rpx 0 0;border:1rpx solid #e3d7d7;border-radius:20rpx;background:rgba(255,255,255,.78);box-shadow:none;color:#b44a4a;font-size:21rpx;line-height:66rpx}.logout::after{border:0}
/* V3：操作就近归组，弱化重复卡片边框，让账户页更像一个完整控制台。 */
.profile-field{display:grid;grid-template-columns:76rpx minmax(0,1fr);align-items:center;margin-top:18rpx;padding:5rpx 8rpx 5rpx 18rpx;border:1rpx solid #d9e5e8;border-radius:18rpx;background:#f6fafb}.profile-field>text{color:#4d6570;font-size:19rpx;font-weight:850}.profile-field .input{height:64rpx;margin:0;border:0;background:transparent}
.profile-editor .button-row{margin-top:14rpx}.profile-editor .primary,.profile-editor .secondary{height:66rpx;font-size:21rpx;line-height:64rpx}.profile-editor .hint{margin-top:12rpx;font-size:18rpx}
.wallet-main{min-height:250rpx}.wallet-action-row{position:absolute;z-index:2;right:22rpx;bottom:20rpx;left:22rpx;display:flex;align-items:center;gap:14rpx}.wallet-action-row button{position:static;width:auto;height:56rpx;flex:none;padding:0 22rpx;line-height:56rpx}.wallet-action-row>text{min-width:0;color:#a9c4cf;font-size:14rpx;line-height:1.35}
.quick-grid{grid-template-columns:repeat(4,minmax(0,1fr));gap:0;padding:16rpx 8rpx;border:1rpx solid #dce7ea;border-radius:27rpx;background:#fff;box-shadow:0 11rpx 25rpx rgba(26,71,91,.07)}.quick-card{position:relative;align-items:center;justify-content:flex-start;flex-direction:column;gap:7rpx;padding:10rpx 3rpx;border:0;border-radius:18rpx;background:transparent!important;box-shadow:none}.quick-card:not(:last-child)::after{content:'';position:absolute;right:0;top:16rpx;bottom:16rpx;width:1rpx;background:#e5edef}.quick-card>view:nth-child(2){width:100%;text-align:center}.quick-card .service-icon{width:56rpx;height:56rpx;border-radius:18rpx}.quick-card .service-label{font-size:19rpx;white-space:nowrap}.quick-card .service-note{display:none}.quick-card:active{transform:translateY(2rpx);background:#f2f7f8!important}
/* V09 对齐：轻校园插画感、白色功能面板与多层级服务结构。 */
.page{padding:calc(18rpx + env(safe-area-inset-top)) 24rpx calc(132rpx + env(safe-area-inset-bottom));background:linear-gradient(180deg,#f3fbf7 0,#f7fbff 430rpx,#f5f8fa 100%)}
.account-toolbar{display:grid;grid-template-columns:88rpx 1fr 88rpx;align-items:center;margin-bottom:18rpx}.account-toolbar>text{text-align:center;color:#142c3d;font-size:28rpx;font-weight:900}.toolbar-actions{display:flex;justify-content:flex-end;gap:10rpx}.toolbar-actions view{display:flex;width:38rpx;height:38rpx;align-items:center;justify-content:center;border:1rpx solid #dce9e5;border-radius:14rpx;background:#fff;color:#31546a;font-size:17rpx;font-weight:850;box-shadow:0 6rpx 16rpx rgba(35,74,85,.07)}
.page .account-hero{padding:28rpx;border:1rpx solid #d7ebe4;border-radius:30rpx;background:linear-gradient(135deg,#e6fff2 0%,#eaf9ff 60%,#fff4d8 100%);box-shadow:0 14rpx 34rpx rgba(35,94,86,.11);color:#16364a}.page .account-hero::before{content:'';position:absolute;right:20rpx;bottom:-22rpx;width:170rpx;height:118rpx;border-radius:80rpx 80rpx 0 0;background:linear-gradient(145deg,rgba(38,188,142,.22),rgba(37,158,222,.16));transform:rotate(-6deg)}.page .account-hero::after{right:-54rpx;top:-72rpx;width:210rpx;height:210rpx;border:32rpx solid rgba(36,173,145,.09)}
.page .account-hero .profile-mark{width:84rpx;height:84rpx;border:0;border-radius:26rpx;background:linear-gradient(145deg,#19bc85,#09a86f);box-shadow:0 9rpx 20rpx rgba(14,140,99,.2);color:#fff}.page .account-hero .profile-badge{border:0;background:#fff6a7;color:#27735a}.page .account-hero .title{color:#15354a;font-size:34rpx}.page .account-hero .subtitle{color:#607985}.page .profile-edit{border:1rpx solid #bcded2;background:rgba(255,255,255,.78);color:#187456}.page .account-hero .profile-progress{background:rgba(30,125,103,.12)}.page .account-hero .profile-progress view{background:linear-gradient(90deg,#1ebc86,#6ed8c3)}.page .hero-meta{background:rgba(255,255,255,.68)}.page .hero-meta view{background:transparent}.page .hero-meta strong{color:#18384c}.page .hero-meta text{color:#728691}
.wallet-layout{gap:14rpx}.wallet-main{min-height:232rpx;border:0;border-radius:28rpx;background:linear-gradient(145deg,#174261,#215e78);box-shadow:0 14rpx 32rpx rgba(27,69,91,.16)}.asset-mini{border-color:#e1ebe8;box-shadow:0 9rpx 22rpx rgba(36,74,84,.07)}
.growth-card,.order-card,.community-services,.quick-services{margin-top:18rpx;padding:24rpx;border:1rpx solid #dceae6;border-radius:28rpx;background:#fff;box-shadow:0 12rpx 28rpx rgba(34,78,82,.07)}.growth-card{padding-right:112rpx}.section-title{color:#17364a;font-size:27rpx}.section-note{color:#6c8490;font-size:17rpx}.section-kicker{color:#16956f}
.order-status-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8rpx;margin-top:20rpx}.order-status-item{min-width:0;padding:14rpx 4rpx;text-align:center;border-radius:20rpx;background:#f7faf9}.order-mark{display:flex;width:54rpx;height:54rpx;align-items:center;justify-content:center;margin:0 auto;border-radius:18rpx;background:#e5f3ff;color:#1474bc;font-size:21rpx;font-weight:900}.order-mark.tone-orange{background:#fff2d8;color:#b56a00}.order-mark.tone-green{background:#e4f8ed;color:#16815c}.order-mark.tone-red{background:#ffeded;color:#c9474d}.order-status-item strong,.order-status-item text{display:block}.order-status-item strong{margin-top:8rpx;color:#18384c;font-size:20rpx}.order-status-item text{margin-top:2rpx;color:#667e8a;font-size:15rpx}
.community-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:11rpx;margin-top:19rpx}.community-item{min-width:0;padding:17rpx 8rpx;text-align:center;border-radius:20rpx;background:#f5faf8}.community-item:nth-child(2),.community-item:nth-child(5){background:#fff9e8}.community-item:nth-child(3),.community-item:nth-child(6){background:#f2f0ff}.community-item .service-icon{width:56rpx;height:56rpx;margin:0 auto;border:0}.community-item .service-label,.community-item .service-note{display:block}.community-item .service-label{margin-top:8rpx;font-size:19rpx}.community-item .service-note{margin-top:3rpx;font-size:13rpx}
.quick-grid{grid-template-columns:repeat(4,minmax(0,1fr));gap:8rpx;margin-top:18rpx;padding:0;border:0;background:transparent;box-shadow:none}.quick-card{min-height:116rpx;justify-content:flex-start;padding:12rpx 3rpx;border-radius:19rpx;background:#f6faf9!important}.quick-card:not(:last-child)::after{display:none}.quick-card .service-icon{width:52rpx;height:52rpx}.quick-card .service-label{font-size:17rpx}.quick-card:active{background:#edf7f3!important}.logout{margin-top:18rpx;border-color:#e6dede;background:rgba(255,255,255,.86)}
@media screen and (max-width:360px){.wallet-layout{grid-template-columns:1fr}.asset-side{grid-template-columns:1fr 1fr;grid-template-rows:auto}.quick-grid{grid-template-columns:repeat(2,1fr)}.quick-card:nth-child(2)::after{display:none}.quick-card:nth-child(-n+2){border-bottom:1rpx solid #e5edef}.more-grid{grid-template-columns:1fr}}
@media screen and (min-width:768px){.page{width:750rpx;margin:0 auto}}
.page{padding-top:calc(env(safe-area-inset-top) + 18rpx)}
/* V09 像素级校准：身份卡、订单、社区与常用服务按评审稿的比例和层级呈现。 */
.account-toolbar{grid-template-columns:92rpx 1fr 92rpx;margin-bottom:20rpx}.account-toolbar>text{font-size:31rpx}.toolbar-actions{gap:13rpx}.toolbar-actions view{width:48rpx;height:48rpx;border-radius:17rpx;font-size:19rpx}
.page .account-hero{min-height:392rpx;padding:34rpx 30rpx 28rpx;border-radius:34rpx;background:linear-gradient(116deg,rgba(223,255,235,.97) 0%,rgba(209,250,238,.94) 42%,rgba(203,242,255,.93) 72%,rgba(255,246,216,.94) 100%);box-shadow:0 18rpx 38rpx rgba(39,105,94,.12);box-sizing:border-box}
.page .account-hero::before{right:-18rpx;bottom:6rpx;width:300rpx;height:205rpx;border-radius:54% 0 0 0;background:radial-gradient(circle at 78% 90%,rgba(255,255,255,.88) 0 14%,transparent 15%),linear-gradient(150deg,transparent 8%,rgba(39,172,131,.13) 9% 54%,rgba(35,150,212,.12) 55%);transform:none}.page .account-hero::after{right:-64rpx;top:-74rpx;width:270rpx;height:270rpx;border:38rpx solid rgba(62,188,158,.08)}
.hero-profile{align-items:center;gap:22rpx}.page .account-hero .profile-mark{width:102rpx;height:102rpx;border:4rpx solid rgba(255,255,255,.92);border-radius:50%;background:linear-gradient(145deg,#57d879,#16af66);box-shadow:0 10rpx 24rpx rgba(22,140,92,.22);font-size:34rpx}.page .account-hero .profile-badge{display:inline-flex;margin-bottom:7rpx;padding:4rpx 12rpx;border-radius:999rpx;background:#e8fff3;color:#1f8c60;font-size:14rpx;letter-spacing:.04em}.page .account-hero .title{font-size:38rpx}.page .account-hero .subtitle{margin-top:6rpx;color:#587684;font-size:21rpx}.page .profile-edit{height:64rpx;padding:0 24rpx;border-color:#b8ddd3;background:rgba(255,255,255,.88);color:#15815c;font-size:21rpx;line-height:62rpx}
.page .hero-meta{position:absolute;right:30rpx;bottom:28rpx;left:30rpx;display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:0;margin:0;padding:20rpx 22rpx 17rpx;border:1rpx solid rgba(205,228,223,.88);border-radius:25rpx;background:rgba(255,255,255,.88);box-shadow:0 12rpx 30rpx rgba(49,95,91,.09);backdrop-filter:blur(10px)}
.page .hero-meta>view{padding:0;background:transparent;text-align:left}.completion-block{grid-column:1/-1;display:grid;grid-template-columns:112rpx minmax(0,1fr);align-items:center;gap:16rpx;padding-bottom:17rpx!important;border-bottom:1rpx solid #e3eeeb}.completion-copy strong,.completion-copy text{display:block}.page .completion-copy strong{color:#17384d;font-size:29rpx}.page .completion-copy text{margin-top:3rpx;color:#708791;font-size:16rpx}.completion-block .profile-progress{height:10rpx;margin:0;border:0;background:#e5efec}.completion-block .profile-progress view{background:linear-gradient(90deg,#16b673,#5ed386)}
.identity-block{display:flex!important;align-items:center;justify-content:center;gap:13rpx;margin-top:16rpx}.identity-block+.identity-block{border-left:1rpx solid #e2ece9}.identity-symbol{display:flex;width:46rpx;height:46rpx;align-items:center;justify-content:center;border-radius:16rpx;background:#54cf73;color:#fff;font-size:20rpx;font-weight:900}.identity-symbol.status{background:#41ca73}.page .hero-meta .identity-block strong{color:#17384d;font-size:23rpx}.page .hero-meta .identity-block text{margin-top:2rpx;color:#718691;font-size:14rpx}
.community-grid{grid-template-columns:repeat(6,minmax(0,1fr));gap:2rpx;padding-top:4rpx}.community-item,.community-item:nth-child(2),.community-item:nth-child(3),.community-item:nth-child(5),.community-item:nth-child(6){padding:15rpx 2rpx;background:transparent}.community-item .service-icon{width:58rpx;height:58rpx}.community-item .service-label{font-size:17rpx;white-space:nowrap}.community-item .service-note{display:none}
.quick-services .quick-grid{grid-template-columns:repeat(4,minmax(0,1fr));gap:8rpx}.quick-services .quick-card{min-height:112rpx;padding:12rpx 2rpx}.quick-services .quick-card .service-label{font-size:16rpx}
/* V09 最终校准：避免通用 view 规则把身份面板撑高，并补足校园插画层。 */
.page .account-hero .account-hero-art{position:absolute;z-index:0;right:-72rpx;top:0;width:72%;height:100%;object-fit:cover;object-position:74% center;opacity:.3;mix-blend-mode:multiply}
.page .account-hero::before{z-index:1;background:linear-gradient(90deg,rgba(225,255,238,.98) 0%,rgba(225,255,238,.78) 45%,rgba(223,248,255,.18) 100%)}
.page .account-hero::after{z-index:1}.page .account-hero .hero-profile,.page .account-hero .hero-meta{z-index:2}
.page .hero-meta view{padding:0}.page .hero-meta{padding:18rpx 22rpx 15rpx}.page .completion-block{padding-bottom:13rpx!important}.page .identity-block{margin-top:12rpx}.page .identity-symbol{width:42rpx;height:42rpx;border-radius:14rpx}.page .hero-meta .identity-block strong{font-size:21rpx}.page .hero-meta .identity-block text{font-size:13rpx}
/* V09 账户资产色彩校准：资产卡采用轻量校园色，不再使用旧版深色大卡。 */
.page .wallet-main{background:linear-gradient(145deg,#dff1ff 0%,#cce9ff 62%,#eaf6ff 100%);box-shadow:0 14rpx 32rpx rgba(42,119,176,.12);color:#123c65}
.page .wallet-main::after{color:rgba(40,120,184,.08)}
.page .wallet-head text:last-child{color:#1b6fae}
.page .wallet-caption{color:#52758f}
.page .wallet-action-row>text{color:#607d91}
.page .wallet-main button{background:#fff;color:#1774bd;box-shadow:0 8rpx 20rpx rgba(33,119,180,.12)}
.page .asset-mini.points{background:linear-gradient(145deg,#fff8cf,#fffdf1)}
.page .asset-mini.coupons{background:linear-gradient(145deg,#e7fbef,#f7fff9)}
</style>
