<template>
  <view class="page" :style="pageStyle">
    <view class="top-safe" />
    <view class="topbar">
      <view class="brand">
        <image v-if="home.brand.logo_url" class="brand-logo" :src="home.brand.logo_url" mode="aspectFit" />
        <view v-else class="brand-mark"><text>{{ home.brand.logo_text }}</text></view>
        <view>
          <text class="brand-name">{{ home.brand.name }}</text>
          <text class="brand-caption">{{ home.brand.caption }}</text>
        </view>
      </view>
      <view class="top-actions">
        <view class="round-action" @click="openNotifications"><image v-if="home.modules.notification_icon_url" class="config-icon top-action-image" :src="home.modules.notification_icon_url" mode="aspectFit" /><text v-else class="line-icon bell-icon" /></view>
        <view class="avatar" @click="openAccount">{{ avatarText }}</view>
      </view>
    </view>

    <view class="content">
      <swiper class="hero-swiper" :autoplay="home.carousel.autoplay" :interval="home.carousel.interval_ms" circular indicator-dots indicator-color="rgba(255,255,255,.45)" indicator-active-color="#fff">
        <swiper-item v-for="slide in home.carousel.items" :key="slide.id">
          <view class="hero" :style="heroStyle(slide)">
            <view class="hero-glow hero-glow-one" />
            <view class="hero-glow hero-glow-two" />
            <text class="hero-kicker">{{ slide.kicker }}</text>
            <text class="hero-title">{{ slide.title }}</text>
            <text class="hero-description">{{ slide.description }}</text>
            <view class="hero-footer">
              <button class="hero-button" @click="navigateHomeLink(slide.link_type)">{{ slide.button_text }}</button>
              <view class="hero-stat">
                <text class="hero-stat-value">{{ auth.isAuthenticated ? '已登录' : '游客' }}</text>
                <text class="hero-stat-label">账户状态</text>
              </view>
            </view>
          </view>
        </swiper-item>
      </swiper>

      <view class="quick-grid" :style="backgroundStyle(home.modules.quick_grid_background_url)">
        <view v-for="item in quickEntries" :key="item.label" class="quick-item" @click="item.action">
          <view :class="['quick-icon', `tone-${item.tone}`]"><image v-if="item.icon_url" class="config-icon quick-icon-image" :src="item.icon_url" mode="aspectFit" /><text v-else>{{ item.mark }}</text></view>
          <text class="quick-label">{{ item.label }}</text>
          <text class="quick-note">{{ item.note }}</text>
        </view>
      </view>

      <view v-if="home.announcement.enabled" class="announcement-bar" @click="navigateHomeLink(home.announcement.link_type)">
        <view class="announcement-icon"><text>{{ home.announcement.icon_text }}</text></view>
        <view class="announcement-main">
          <text class="announcement-title">{{ home.announcement.title }}</text>
          <rich-text class="announcement-content" :nodes="compactRichTextContent(home.announcement.content)" />
        </view>
        <text v-if="home.announcement.link_type !== 'none'" class="announcement-chevron">›</text>
      </view>

      <view class="section-heading">
        <view><text class="section-title">{{ home.modules.recommendation.section_title }}</text><text class="section-subtitle">{{ home.modules.recommendation.section_subtitle }}</text></view>
        <text class="section-link" @click="openTasks">查看全部</text>
      </view>

      <view class="recommend-card" :style="backgroundStyle(home.modules.recommendation.background_url)" @click="navigateHomeLink(home.modules.recommendation.link_type)">
        <view class="recommend-mark"><image v-if="home.modules.recommendation.icon_url" class="config-icon recommend-icon-image" :src="home.modules.recommendation.icon_url" mode="aspectFit" /><text v-else>{{ home.modules.recommendation.icon_text }}</text></view>
        <view class="recommend-main">
          <view class="recommend-title-row"><text class="recommend-title">{{ home.modules.recommendation.title }}</text><text class="recommend-tag">{{ home.modules.recommendation.tag }}</text></view>
          <text class="recommend-copy">{{ home.modules.recommendation.description }}</text>
          <view class="recommend-meta"><text class="reward-pill">{{ home.modules.recommendation.reward_text }}</text><text class="time-pill">{{ home.modules.recommendation.time_text }}</text></view>
        </view>
        <text class="chevron">›</text>
      </view>

      <view class="section-heading mall-heading">
        <view><text class="section-title">{{ home.modules.product_section.section_title }}</text><text class="section-subtitle">{{ home.modules.product_section.section_subtitle }}</text></view>
        <text class="section-link" @click="openProducts">逛商城</text>
      </view>

      <view class="product-strip">
        <view v-for="(card, index) in home.modules.product_section.cards" :key="card.id" :class="['product-card', `product-card-${card.tone}`]" :style="backgroundStyle(card.background_url)" @click="navigateHomeLink(card.link_type)">
          <text class="product-eyebrow">{{ card.eyebrow }}</text>
          <text class="product-title">{{ card.title }}</text>
          <text class="product-price">{{ card.price_text }}</text>
          <image v-if="card.image_url" class="config-icon product-config-image" :src="card.image_url" mode="aspectFit" />
          <view v-else-if="index === 0" class="product-object product-object-cup"><view class="cup-handle" /></view>
          <view v-else class="product-object product-object-box"><view /><view /></view>
        </view>
      </view>

      <view class="trust-card" :style="backgroundStyle(home.modules.trust.background_url)" @click="navigateHomeLink(home.modules.trust.link_type)">
        <view class="trust-mark"><image v-if="home.modules.trust.icon_url" class="config-icon trust-icon-image" :src="home.modules.trust.icon_url" mode="aspectFit" /><text v-else class="line-icon shield-icon" /></view>
        <view><text class="trust-title">{{ home.modules.trust.title }}</text><text class="trust-copy">{{ home.modules.trust.description }}</text></view>
        <text class="chevron">›</text>
      </view>

      <button v-if="!auth.isAuthenticated" class="login-banner" @click="openLogin">
        登录后领取任务与奖励
      </button>
    </view>

    <view v-if="popupVisible" class="popup-mask" @click.self="closePopup">
      <view :class="['home-popup', `popup-${home.popup.mode}`]">
        <view class="popup-close" @click="closePopup">×</view>
        <image v-if="home.popup.image_url" class="popup-poster" :src="home.popup.image_url" mode="widthFix" />
        <view class="popup-body">
          <text class="popup-title">{{ home.popup.title }}</text>
          <text v-if="home.popup.content" class="popup-content">{{ home.popup.content }}</text>
          <button class="popup-button" @click="handlePopupAction">{{ home.popup.button_text }}</button>
        </view>
      </view>
    </view>
    <AppTabBar current-target="home" />
  </view>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue'
import { onShow } from '@dcloudio/uni-app'
import { loadHomeContent, type HomeCarouselItem, type HomeContent, type HomeLinkType } from '@/api/home'
import { loadPendingLegalDocuments } from '@/api/legal'
import { openPrimaryNavigation } from '@/api/navigation'
import AppTabBar from '@/components/AppTabBar.vue'
import { useAuthStore } from '@/stores/auth'
import { compactRichTextContent } from '@/utils/richText'

const auth = useAuthStore()
const avatarText = computed(() => auth.user?.nickname?.slice(0, 1) || '客')
const popupVisible = ref(false)
const home = ref<HomeContent>({
  brand: { logo_url: '', logo_text: 'TM', name: '任务商城', caption: 'TASK · REWARD · LIFE' },
  carousel: { autoplay: true, interval_ms: 4500, items: [
    { id: 'task-welcome', kicker: '今天也有新收获', title: '完成好任务，兑换好生活', description: '做任务赚积分，现金、积分或组合方式都能兑换心仪商品。', button_text: '去任务大厅', link_type: 'tasks', image_url: '' },
  ] },
  announcement: { enabled: true, icon_text: '公', title: '平台公告', content: '欢迎来到任务商城，完成任务即可领取奖励。', link_type: 'notifications' },
  popup: { enabled: false, display_once: true, content_id: 'default', mode: 'announcement', title: '', content: '', image_url: '', button_text: '我知道了', link_type: 'none' },
  modules: {
    page_background_url: '', notification_icon_url: '', quick_grid_background_url: '',
    quick_entries: [
      { id: 'tasks', label: '任务大厅', note: '领取奖励', mark: '任', tone: 'blue', icon_url: '', link_type: 'tasks' },
      { id: 'products', label: '积分商城', note: '兑换好物', mark: '兑', tone: 'orange', icon_url: '', link_type: 'products' },
      { id: 'community', label: '社区成长', note: '分享交流', mark: '聊', tone: 'green', icon_url: '', link_type: 'community' },
      { id: 'orders', label: '我的订单', note: '查看进度', mark: '单', tone: 'violet', icon_url: '', link_type: 'orders' },
    ],
    recommendation: { section_title: '今日推荐', section_subtitle: '从一件简单的小事开始', title: '完成新手任务', description: '完善资料、熟悉流程，开启你的第一份奖励', tag: '简单', reward_text: '最高 +300 积分', time_text: '约 3 分钟', icon_text: '新', icon_url: '', background_url: '', link_type: 'tasks' },
    product_section: { section_title: '精选兑换', section_subtitle: '三种购买方式，价格各端一致', cards: [
      { id: 'points-choice', eyebrow: '积分好物', title: '日常精选', price_text: '积分兑换', tone: 'blue', image_url: '', background_url: '', link_type: 'products' },
      { id: 'life-choice', eyebrow: '限时推荐', title: '生活补给', price_text: '现金 / 积分', tone: 'warm', image_url: '', background_url: '', link_type: 'products' },
    ] },
    trust: { title: '每一笔资产都有记录', description: '积分来源、有效期、退款和余额变动清晰可查', icon_url: '', background_url: '', link_type: 'assets' },
  },
  versions: {},
})

const quickEntries = computed(() => home.value.modules.quick_entries.map((item) => ({ ...item, action: () => navigateHomeLink(item.link_type) })))
const pageStyle = computed(() => backgroundStyle(home.value.modules.page_background_url))

onShow(async () => {
  try {
    home.value = await loadHomeContent()
    showPopupWhenNeeded()
  } catch {
    // 首页装修接口不可用时使用内置默认内容，避免阻塞首页。
  }
  if (!auth.accessToken) return
  await auth.fetchCurrentUser()
  if (!auth.accessToken) return
  try {
    const pending = await loadPendingLegalDocuments(auth.accessToken, 'account')
    if (pending.length > 0) uni.navigateTo({ url: '/pages/legal-consent/index?next=home' })
  } catch {
    // The API still enforces the legal gate if this convenience check fails.
  }
})

function openLogin() { uni.navigateTo({ url: '/pages/login/index' }) }
function openTasks() { openPrimaryNavigation('tasks') }
function openProducts() { openPrimaryNavigation('products') }
function openCommunity() { openPrimaryNavigation('community') }
function openAccount() { auth.isAuthenticated ? openPrimaryNavigation('account') : openLogin() }
function openOrders() { auth.isAuthenticated ? uni.navigateTo({ url: '/pages/orders/index' }) : openLogin() }
function openNotifications() { auth.isAuthenticated ? uni.navigateTo({ url: '/pages/notifications/index' }) : openLogin() }
function openAssets() { auth.isAuthenticated ? uni.navigateTo({ url: '/pages/assets/index' }) : openLogin() }
function heroStyle(slide: HomeCarouselItem) {
  return slide.image_url ? { backgroundImage: `linear-gradient(90deg,rgba(18,46,111,.88),rgba(47,103,223,.52)),url(${slide.image_url})` } : undefined
}
function backgroundStyle(url: string) {
  return url ? { backgroundImage: `url(${url})`, backgroundPosition: 'center', backgroundSize: 'cover' } : undefined
}
function navigateHomeLink(link: HomeLinkType) {
  if (link === 'tasks') return openTasks()
  if (link === 'products') return openProducts()
  if (link === 'community') return openCommunity()
  if (link === 'notifications') return openNotifications()
  if (link === 'orders') return openOrders()
  if (link === 'assets') return openAssets()
}
function popupStorageKey() { return `task_mall_home_popup_seen_${home.value.popup.content_id}` }
function showPopupWhenNeeded() {
  const popup = home.value.popup
  if (!popup.enabled) return
  if (popup.display_once && uni.getStorageSync(popupStorageKey())) return
  popupVisible.value = true
}
function closePopup() {
  if (home.value.popup.display_once) uni.setStorageSync(popupStorageKey(), true)
  popupVisible.value = false
}
function handlePopupAction() {
  const link = home.value.popup.link_type
  closePopup()
  navigateHomeLink(link)
}
</script>

<style scoped>
.page{min-height:100vh;padding-bottom:48rpx;background:linear-gradient(180deg,#f8faff 0,#f6f8fc 380rpx)}
.top-safe{height:calc(var(--status-bar-height) + 12rpx)}
.topbar{display:flex;align-items:center;justify-content:space-between;padding:18rpx 30rpx 24rpx}
.brand,.top-actions{display:flex;align-items:center}.brand{gap:18rpx}.top-actions{gap:14rpx}
.brand-mark,.brand-logo{width:68rpx;height:68rpx;flex:none}.brand-mark{display:flex;align-items:center;justify-content:center;border-radius:22rpx 22rpx 22rpx 8rpx;background:linear-gradient(145deg,var(--tm-brand-600),#6d96ff);box-shadow:0 12rpx 24rpx rgba(47,103,223,.22);color:#fff;font-size:22rpx;font-weight:800;letter-spacing:1rpx}.brand-logo{border-radius:18rpx}
.brand-name,.brand-caption,.hero-kicker,.hero-title,.hero-description,.hero-stat-value,.hero-stat-label,.quick-label,.quick-note,.section-title,.section-subtitle,.recommend-title,.recommend-copy,.product-eyebrow,.product-title,.product-price,.trust-title,.trust-copy{display:block}
.brand-name{font-size:31rpx;font-weight:800;letter-spacing:1rpx}.brand-caption{margin-top:2rpx;color:#9aa4b7;font-size:16rpx;letter-spacing:1.4rpx}
.round-action,.avatar{display:flex;align-items:center;justify-content:center;width:68rpx;height:68rpx;border:1rpx solid rgba(225,230,240,.85);border-radius:22rpx;background:rgba(255,255,255,.86)}
.avatar{background:var(--tm-brand-100);color:var(--tm-brand-700);font-weight:800}
.line-icon{position:relative;display:block;width:28rpx;height:28rpx}.bell-icon{width:24rpx;height:26rpx;border:3rpx solid var(--tm-ink-700);border-top-left-radius:14rpx;border-top-right-radius:14rpx;border-bottom:0}.bell-icon::before{content:'';position:absolute;left:-6rpx;bottom:-3rpx;width:30rpx;height:3rpx;border-radius:3rpx;background:var(--tm-ink-700)}.bell-icon::after{content:'';position:absolute;left:8rpx;bottom:-10rpx;width:8rpx;height:8rpx;border-radius:50%;background:var(--tm-ink-700)}
.content{padding:0 28rpx}.hero-swiper{height:398rpx;border-radius:36rpx}.hero{position:relative;overflow:hidden;height:100%;box-sizing:border-box;padding:42rpx 38rpx 46rpx;border-radius:36rpx;background:linear-gradient(135deg,#173b87 0%,#2f67df 62%,#5688f4 100%);background-position:center;background-size:cover;box-shadow:0 24rpx 55rpx rgba(37,83,185,.22);color:#fff}
.hero-glow{position:absolute;border-radius:50%;background:rgba(255,255,255,.1)}.hero-glow-one{width:280rpx;height:280rpx;right:-80rpx;top:-120rpx}.hero-glow-two{width:170rpx;height:170rpx;right:120rpx;bottom:-120rpx}
.hero-kicker{position:relative;color:#dce8ff;font-size:23rpx;font-weight:650}.hero-title{position:relative;margin-top:18rpx;max-width:560rpx;font-size:46rpx;font-weight:850;line-height:1.28;letter-spacing:1rpx}.hero-description{position:relative;margin-top:16rpx;max-width:570rpx;color:rgba(255,255,255,.78);font-size:24rpx;line-height:1.65}
.hero-footer{position:relative;display:flex;align-items:center;justify-content:space-between;margin-top:34rpx}.hero-button{width:224rpx;height:74rpx;margin:0;border-radius:22rpx;background:#fff;color:var(--tm-brand-700);font-size:25rpx;font-weight:800;line-height:74rpx}.hero-stat{text-align:right}.hero-stat-value{font-size:25rpx;font-weight:800}.hero-stat-label{margin-top:4rpx;color:rgba(255,255,255,.62);font-size:19rpx}
.quick-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12rpx;margin-top:28rpx;padding:26rpx 12rpx 24rpx;border:1rpx solid rgba(231,235,242,.8);border-radius:30rpx;background:rgba(255,255,255,.92);box-shadow:0 12rpx 30rpx rgba(35,49,80,.05)}.quick-item{text-align:center}.quick-icon{display:flex;align-items:center;justify-content:center;width:72rpx;height:72rpx;margin:0 auto 13rpx;border-radius:24rpx;font-size:25rpx;font-weight:850}.tone-blue{background:#e8f0ff;color:#2f67df}.tone-orange{background:#fff0df;color:#d9691e}.tone-green{background:#e3f6ef;color:#158763}.tone-violet{background:#eeeaff;color:#7156c9}.quick-label{font-size:23rpx;font-weight:750}.quick-note{margin-top:4rpx;color:var(--tm-ink-500);font-size:18rpx}
.announcement-bar{display:flex;align-items:center;gap:16rpx;margin:20rpx 2rpx 0;padding:18rpx 20rpx;border-radius:22rpx;background:#fff7e8;border:1rpx solid #ffe4b7}.announcement-icon{display:flex;align-items:center;justify-content:center;width:54rpx;height:54rpx;flex:none;border-radius:17rpx;background:#ffedd2;color:#d9691e;font-size:21rpx;font-weight:850}.announcement-main{min-width:0;flex:1}.announcement-title,.announcement-content{display:block}.announcement-title{font-size:23rpx;font-weight:800}.announcement-content{display:-webkit-box;max-height:58rpx;margin-top:3rpx;overflow:hidden;color:#8a6649;font-size:19rpx;line-height:1.45;-webkit-box-orient:vertical;-webkit-line-clamp:2}.announcement-chevron{color:#c89563;font-size:36rpx}
.section-heading{display:flex;align-items:flex-end;justify-content:space-between;margin:38rpx 4rpx 18rpx}.section-title{font-size:32rpx;font-weight:850}.section-subtitle{margin-top:5rpx;color:var(--tm-ink-500);font-size:21rpx}.section-link{padding:10rpx 0;color:var(--tm-brand-600);font-size:23rpx;font-weight:700}
.recommend-card{display:flex;align-items:center;gap:20rpx;padding:27rpx 24rpx;border:1rpx solid var(--tm-line);border-radius:28rpx;background:var(--tm-surface);box-shadow:0 12rpx 28rpx rgba(35,49,80,.045)}.recommend-mark{display:flex;align-items:center;justify-content:center;width:88rpx;height:88rpx;flex:none;border-radius:27rpx;background:linear-gradient(145deg,#e7efff,#f4f7ff);color:var(--tm-brand-700);font-size:30rpx;font-weight:850}.recommend-main{min-width:0;flex:1}.recommend-title-row{display:flex;align-items:center;gap:12rpx}.recommend-title{font-size:28rpx;font-weight:800}.recommend-tag{padding:4rpx 10rpx;border-radius:9rpx;background:#e7f6ef;color:var(--tm-success);font-size:18rpx}.recommend-copy{margin-top:8rpx;overflow:hidden;color:var(--tm-ink-500);font-size:21rpx;text-overflow:ellipsis;white-space:nowrap}.recommend-meta{display:flex;gap:12rpx;margin-top:13rpx}.reward-pill,.time-pill{font-size:20rpx}.reward-pill{color:var(--tm-reward-600);font-weight:750}.time-pill{color:var(--tm-ink-500)}.chevron{color:#a5afc0;font-size:42rpx;font-weight:300}
.mall-heading{margin-top:40rpx}.product-strip{display:grid;grid-template-columns:1fr 1fr;gap:18rpx}.product-card{position:relative;overflow:hidden;min-height:236rpx;padding:26rpx 24rpx;border-radius:30rpx}.product-card-blue{background:linear-gradient(145deg,#e9f2ff,#dce9ff)}.product-card-warm{background:linear-gradient(145deg,#fff3e5,#ffe8ce)}.product-eyebrow{color:var(--tm-ink-500);font-size:19rpx}.product-title{margin-top:7rpx;font-size:29rpx;font-weight:850}.product-price{margin-top:10rpx;color:var(--tm-brand-700);font-size:21rpx;font-weight:750}.product-card-warm .product-price{color:#c55d18}.product-object{position:absolute;right:26rpx;bottom:22rpx}.product-object-cup{width:70rpx;height:76rpx;border-radius:12rpx 12rpx 22rpx 22rpx;background:linear-gradient(145deg,#fff,#bcd2ff);box-shadow:0 12rpx 20rpx rgba(47,103,223,.14)}.cup-handle{position:absolute;right:-24rpx;top:18rpx;width:28rpx;height:34rpx;border:9rpx solid #c4d7fc;border-left:0;border-radius:0 20rpx 20rpx 0}.product-object-box{width:84rpx;height:70rpx;border-radius:10rpx;background:#efb46f;box-shadow:0 14rpx 24rpx rgba(190,104,38,.17);transform:rotate(-4deg)}.product-object-box view:first-child{position:absolute;left:38rpx;width:10rpx;height:70rpx;background:#ffe0b8}.product-object-box view:last-child{position:absolute;left:0;top:18rpx;width:84rpx;height:7rpx;background:#d9954a}
.trust-card{display:flex;align-items:center;gap:18rpx;margin-top:28rpx;padding:25rpx 24rpx;border-radius:26rpx;background:#eef4ff}.trust-mark{display:flex;align-items:center;justify-content:center;width:70rpx;height:70rpx;flex:none;border-radius:22rpx;background:#fff;color:var(--tm-brand-700)}.shield-icon{width:28rpx;height:32rpx;border:3rpx solid currentColor;border-radius:14rpx 14rpx 18rpx 18rpx}.shield-icon::after{content:'';position:absolute;left:7rpx;top:7rpx;width:10rpx;height:6rpx;border-left:3rpx solid currentColor;border-bottom:3rpx solid currentColor;transform:rotate(-45deg)}.trust-card>view:nth-child(2){min-width:0;flex:1}.trust-title{font-size:25rpx;font-weight:800}.trust-copy{margin-top:6rpx;color:var(--tm-ink-500);font-size:20rpx;line-height:1.5}.login-banner{height:88rpx;margin:28rpx 0 0;border-radius:24rpx;background:var(--tm-brand-600);color:#fff;font-size:26rpx;font-weight:800;line-height:88rpx}
.config-icon{display:block}.top-action-image{width:38rpx;height:38rpx}.quick-icon-image{width:54rpx;height:54rpx}.recommend-icon-image{width:72rpx;height:72rpx}.product-config-image{position:absolute;right:18rpx;bottom:16rpx;width:105rpx;height:105rpx}.trust-icon-image{width:52rpx;height:52rpx}.quick-grid,.recommend-card,.product-card,.trust-card{background-position:center;background-size:cover}.quick-grid{background-color:rgba(255,255,255,.92)}.recommend-card{background-color:var(--tm-surface)}.product-card-blue{background-color:#e4edff}.product-card-warm{background-color:#ffecd6}.trust-card{background-color:#eef4ff}
.popup-mask{position:fixed;z-index:999;inset:0;display:flex;align-items:center;justify-content:center;padding:44rpx;background:rgba(15,23,42,.66)}.home-popup{position:relative;overflow:hidden;width:100%;max-width:620rpx;border-radius:34rpx;background:#fff;box-shadow:0 30rpx 90rpx rgba(0,0,0,.28)}.popup-close{position:absolute;z-index:2;right:18rpx;top:18rpx;display:flex;align-items:center;justify-content:center;width:54rpx;height:54rpx;border-radius:50%;background:rgba(15,23,42,.58);color:#fff;font-size:36rpx}.popup-poster{display:block;width:100%}.popup-body{padding:38rpx 34rpx 34rpx;text-align:center}.popup-title,.popup-content{display:block}.popup-title{font-size:34rpx;font-weight:850}.popup-content{margin-top:16rpx;color:var(--tm-ink-500);font-size:23rpx;line-height:1.7}.popup-button{height:82rpx;margin-top:28rpx;border-radius:22rpx;background:var(--tm-brand-600);color:#fff;font-size:25rpx;font-weight:800;line-height:82rpx}
@media screen and (min-width:768px){.page{width:750rpx;margin:0 auto;box-shadow:0 0 70rpx rgba(24,42,78,.08)}.top-safe{height:24rpx}}
</style>
