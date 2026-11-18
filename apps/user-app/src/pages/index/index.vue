<template>
  <view
    class="home-v13"
    :class="{ 'is-authenticated': auth.isAuthenticated, 'is-guest': !auth.isAuthenticated }"
    :style="pageStyle"
  >
    <view class="home-v13-safe" />

    <!-- 顶部：校区 + 搜索 + 消息 -->
    <view class="home-v13-head">
      <view class="home-v13-campus-wrap">
        <CampusSelector
          ref="campusSelectorRef"
          :access-token="auth.accessToken"
          :account-campus="auth.user?.campus"
          @change="handleCampusChange"
        />
      </view>
      <view class="home-v13-search" role="button" aria-label="搜索任务" @click="openSearch">
        <view class="home-v13-search-icon" />
        <text class="home-v13-search-placeholder">搜任务 / 搜商品</text>
      </view>
      <view
        class="home-v13-bell"
        role="button"
        aria-label="打开消息中心"
        @click="openNotifications"
      >
        <view class="home-v13-bell-icon" />
        <view v-if="auth.isAuthenticated" class="home-v13-bell-dot" />
      </view>
    </view>

    <!-- 积分卡片 -->
    <view class="home-v13-point-card">
      <view class="home-v13-pc-top">
        <view class="home-v13-pc-left">
          <text class="home-v13-pc-label">我的积分 ›</text>
          <text class="home-v13-pc-num">
            {{ userPoints }}
            <text class="home-v13-pc-sub">今日已赚 +{{ todayEarned }}</text>
          </text>
        </view>
        <button class="home-v13-pc-sign" :class="{ signed: checkedInToday }" @click="goCheckIn">
          <text class="home-v13-pc-sign-icon">✓</text>
          {{ checkedInToday ? '今日已签' : '每日签到' }}
        </button>
      </view>
      <view class="home-v13-sign-strip">
        <view
          v-for="(day, index) in signDays"
          :key="index"
          class="home-v13-sign-day"
          :class="{ done: day.done, today: day.today }"
        >
          <view class="home-v13-sign-day-badge">
            <text v-if="day.done" class="home-v13-sign-day-check">✓</text>
            <text v-else class="home-v13-sign-day-coin">{{ day.reward ?? '¥' }}</text>
          </view>
          <text class="home-v13-sign-day-label">{{ day.label }}</text>
        </view>
      </view>
    </view>

    <!-- 金刚区 -->
    <view class="home-v13-king">
      <view
        v-for="item in quickEntries"
        :key="`king-${item.id}`"
        class="home-v13-king-item"
        @click="item.action"
      >
        <view class="home-v13-king-icon" :style="kingIconStyle(item.tone)">
          <image v-if="item.iconSrc" class="home-v13-king-icon-img" :src="item.iconSrc" mode="aspectFit" />
          <text v-else>{{ item.mark || item.displayLabel.slice(0, 1) }}</text>
        </view>
        <text class="home-v13-king-label">{{ item.displayLabel }}</text>
      </view>
    </view>

    <!-- 橙色推广横幅 -->
    <view
      v-if="activeHeroSlide"
      class="home-v13-promo"
      @click="navigateHomeLink(activeHeroSlide.link_type)"
    >
      <view class="home-v13-promo-text">
        <text class="home-v13-promo-title">{{ activeHeroSlide.kicker || activeHeroSlide.title }}</text>
        <text class="home-v13-promo-desc">{{ activeHeroSlide.description }}</text>
        <text class="home-v13-promo-btn">{{ activeHeroSlide.button_text || '立即查看' }}</text>
      </view>
      <image
        v-if="activeHeroSlide.image_url && !failedHeroImages[activeHeroSlide.id]"
        class="home-v13-promo-img"
        :src="heroImageSource(activeHeroSlide)"
        mode="aspectFill"
        @error="handleHeroImageError(activeHeroSlide)"
      />
    </view>

    <!-- 任务推荐流 -->
    <view class="home-v13-feed-tabs">
      <text
        v-for="tab in feedTabOptions"
        :key="tab.id"
        class="home-v13-feed-tab"
        :class="{ active: activeFeedTab === tab.id }"
        @click="selectFeedTab(tab.id)"
      >{{ tab.label }}</text>
      <text class="home-v13-feed-more" @click="openTasks">附近 ›</text>
    </view>

    <view v-if="homeError" class="home-v13-error" @click="loadHome">
      首页内容使用默认配置，点击重新加载
    </view>

    <!-- 社交式任务卡片列表 -->
    <view
      v-for="task in feedTasks"
      :key="`feed-${task.id}`"
      class="home-v13-post"
      @click="openTaskDetail(task.id)"
    >
      <view class="home-v13-post-head">
        <image
          v-if="taskAvatarSrc(task)"
          class="home-v13-post-avatar"
          :src="taskAvatarSrc(task)"
          mode="aspectFill"
        />
        <view v-else class="home-v13-post-avatar home-v13-post-avatar-fallback">
          {{ publisherInitial(task) }}
        </view>
        <view class="home-v13-post-head-main">
          <view class="home-v13-post-name-row">
            <text class="home-v13-post-name">{{ task.publisher?.display_name || '同学' }}</text>
            <text v-if="task.publisher?.publisher_badge" class="home-v13-post-badge">{{ task.publisher.publisher_badge }}</text>
          </view>
          <text class="home-v13-post-meta">{{ postMetaText(task) }}</text>
        </view>
        <view v-if="modules.isLaunched('module_follow')" class="home-v13-post-follow" @click.stop="followPublisher(task)">+关注</view>
      </view>

      <text class="home-v13-post-body">{{ postBodyText(task) }}</text>

      <view class="home-v13-post-tags">
        <text v-if="task.bounty" class="home-v13-tag tag-red">悬赏</text>
        <text v-if="task.urgent" class="home-v13-tag tag-orange">加急</text>
        <text v-for="tag in postTags(task)" :key="tag" class="home-v13-tag tag-gray">{{ tag }}</text>
      </view>

      <view v-if="task.gallery?.length" class="home-v13-post-gallery" :class="`g-${Math.min(task.gallery.length, 3)}`">
        <image
          v-for="(image, index) in task.gallery.slice(0, 3)"
          :key="`gallery-${index}`"
          class="home-v13-post-gallery-img"
          :src="resolveApiAssetUrl(image)"
          mode="aspectFill"
        />
      </view>

      <view class="home-v13-post-foot">
        <text class="home-v13-post-pts">{{ taskRewardText(task) }}</text>
        <text v-if="task.location_text" class="home-v13-post-loc">@ {{ task.location_text }}</text>
        <button class="home-v13-post-btn" @click.stop="openTaskDetail(task.id)">{{ isTeamTask(task) ? '报名' : '去接单' }}</button>
      </view>
    </view>

    <!-- 无任务时回退 -->
    <view v-if="!feedTasks.length && !homeLoading" class="home-v13-empty" @click="openTasks">
      <text class="home-v13-empty-text">新的校园任务正在准备中</text>
      <text class="home-v13-empty-link">去任务大厅看看 ›</text>
    </view>

    <!-- 欢迎弹窗 -->
    <view v-if="popupVisible" class="home-v13-popup-mask" role="presentation" @click.self="closePopup">
      <view class="home-v13-popup">
        <view class="home-v13-popup-close" role="button" aria-label="关闭欢迎提示" @click="closePopup">×</view>
        <image v-if="home.popup.image_url" class="home-v13-popup-image" :src="home.popup.image_url" mode="widthFix" />
        <view class="home-v13-popup-body">
          <text class="home-v13-popup-title">{{ home.popup.title }}</text>
          <text v-if="home.popup.content" class="home-v13-popup-content">{{ home.popup.content }}</text>
          <button class="home-v13-popup-button" @click="handlePopupAction">{{ home.popup.button_text }}</button>
          <text class="home-v13-popup-muted" role="button" @click="disablePopup">不再提醒</text>
        </view>
      </view>
    </view>

    <AppTabBar id="home-tab-bar" current-target="home" variant="flat" />
  </view>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { onLoad, onShareAppMessage, onShareTimeline, onShow } from '@dcloudio/uni-app'
import { loadHomeContent, type HomeCarouselItem, type HomeContent, type HomeLinkType } from '@/api/home'
import { loadPendingLegalDocuments } from '@/api/legal'
import { openPrimaryNavigation } from '@/api/navigation'
import { listProducts, type MallProduct } from '@/api/products'
import { listHallTasks, listMyTaskClaims, type HallTask, type TaskClaim, type TaskReward } from '@/api/tasks'
import { getPointAccount } from '@/api/assets'
import { getDailyCheckIn, type DailyCheckInStatus } from '@/api/growth'
import { resolveApiAssetUrl } from '@/api/http'
import { enableWechatShare } from '@/platform/wechatJssdk'
import { formatRelativeTime } from '@/utils/dateTime'
import { ensureCampusBinding } from '@/utils/campusBindingGuard'
import AppTabBar from '@/components/AppTabBar.vue'
import CampusSelector from '@/components/CampusSelector.vue'
import type { Campus } from '@/api/campuses'
import { useAuthStore } from '@/stores/auth'
import { useCampusStore } from '@/stores/campus'
import { useModulesStore } from '@/stores/modules'
import { useIconConfigStore } from '@/stores/icons'
import { HOME_LINK_MODULE } from '@/platform/modules'
import { navigateToLogin } from '@/utils/authRedirect'
import { compactRichTextContent } from '@/utils/richText'
import { createTopSafeAreaStyle } from '@/utils/safeArea'

type InfoTab = 'announcement' | 'messages' | 'assets'
type RegisteredProductCard = { id: string; name: string; image_url: string; price_text: string; live: boolean }

const fallbackHeroImage = '/static/home/hero-campus-reward.png'
const auth = useAuthStore()
const campus = useCampusStore()
const modules = useModulesStore()
const icons = useIconConfigStore()
let promotionCampusReady: Promise<unknown> | null = null
const campusSelectorRef = ref<{ open: () => void } | null>(null)
let campusPromptShown = false
const popupVisible = ref(false)
const homeLoading = ref(false)
const homeLoaded = ref(false)
const homeError = ref('')
const activeHeroIndex = ref(0)
const activeInfoTab = ref<InfoTab>('announcement')
const failedHeroImages = ref<Record<string, boolean>>({})
const hallTasks = ref<HallTask[]>([])
const myClaims = ref<TaskClaim[]>([])
const mallProducts = ref<MallProduct[]>([])
const pointAccount = ref<{ available_points: number; points_earned_today: number } | null>(null)
const checkInStatus = ref<DailyCheckInStatus | null>(null)
const infoTabs: Array<{ id: InfoTab; label: string }> = [
  { id: 'announcement', label: '公告' },
  { id: 'messages', label: '个人消息' },
  { id: 'assets', label: '资产动态' },
]

function createDefaultHome(): HomeContent {
  return {
    brand: { logo_url: '', logo_text: 'TM', name: '任务商城', caption: 'TASK · REWARD · LIFE' },
    carousel: {
      autoplay: true,
      interval_ms: 4500,
      items: [{
        id: 'mall-flexible-pay',
        kicker: '积分与现金灵活选',
        title: '三种购买方式，价格各端一致',
        description: '积分兑换、现金购买或现金加积分，按商品配置自由选择。',
        button_text: '去积分商城',
        link_type: 'products',
        image_url: '',
      }],
    },
    announcement: {
      enabled: true,
      icon_text: '公',
      icon_url: '',
      title: '平台公告',
      content: '欢迎来到任务商城，完成任务即可领取奖励。',
      link_type: 'notifications',
    },
    popup: {
      enabled: true,
      display_once: true,
      content_id: 'default',
      mode: 'announcement',
      title: '欢迎来到任务商城',
      content: '新用户可以先完成新手任务，熟悉流程并领取第一份积分奖励。',
      image_url: '',
      button_text: '我知道了',
      link_type: 'none',
    },
    floating_support: {
      enabled: true,
      show_label: true,
      icon_text: '服',
      icon_url: '',
      label: '客服',
      subtitle: '扫码联系校园客服',
      qr_code_url: '',
      qr_code_tip: '长按识别客服二维码',
      position: 'right',
      bottom_offset_rpx: 180,
    },
    modules: {
      page_background_url: '',
      notification_icon_url: '',
      quick_grid_background_url: '',
      quick_entries: [
        { id: 'cat-errand', label: '跑腿代办', note: '取件带饭', mark: '跑', tone: 'green', icon_url: '/static/icons/cat-errand.svg', link_type: 'tasks:errand' },
        { id: 'cat-study', label: '学习助力', note: '答疑打印', mark: '学', tone: 'blue', icon_url: '/static/icons/cat-study.svg', link_type: 'tasks:study' },
        { id: 'cat-secondhand', label: '二手闲置', note: '淘好物', mark: '闲', tone: 'orange', icon_url: '/static/icons/cat-secondhand.svg', link_type: 'secondhand' },
        { id: 'cat-skill', label: '技能服务', note: '修电脑修车', mark: '技', tone: 'violet', icon_url: '/static/icons/cat-skill.svg', link_type: 'tasks:skill' },
        { id: 'cat-lost', label: '失物招领', note: '寻物启事', mark: '失', tone: 'pink', icon_url: '/static/icons/cat-lost.svg', link_type: 'tasks:lost_found' },
        { id: 'cat-teamup', label: '组队拼单', note: '搭子拼团', mark: '队', tone: 'cyan', icon_url: '/static/icons/cat-teamup.svg', link_type: 'tasks:teamup' },
        { id: 'cat-research', label: '问卷调研', note: '填问卷', mark: '研', tone: 'yellow', icon_url: '/static/icons/cat-research.svg', link_type: 'tasks:research' },
        { id: 'pt-center', label: '地推中心', note: '校园推广', mark: '推', tone: 'green', icon_url: '', link_type: 'push' },
      ],
      priority_entries: [
        { id: 'assets', mark: '资', icon_url: '/static/icons/module-wallet.svg' },
        { id: 'referrals', mark: '邀', icon_url: '/static/icons/module-referrals.svg' },
      ],
      recommendation: {
        section_title: '今日推荐',
        section_subtitle: '从一件简单的小事开始',
        title: '完成新手任务',
        description: '完善资料、熟悉流程，开启你的第一份奖励',
        tag: '简单',
        reward_text: '最高 +300 积分',
        time_text: '约 3 分钟',
        icon_text: '新',
        icon_url: '',
        background_url: '',
        link_type: 'tasks',
      },
      product_section: {
        section_title: '精选兑换',
        section_subtitle: '三种购买方式，价格各端一致',
        cards: [
          { id: 'points-choice', eyebrow: '积分好物', title: '日常精选', price_text: '积分兑换', tone: 'blue', image_url: '', background_url: '', link_type: 'products' },
          { id: 'life-choice', eyebrow: '限时推荐', title: '生活补给', price_text: '现金 / 积分', tone: 'warm', image_url: '', background_url: '', link_type: 'products' },
        ],
      },
      trust: {
        title: '每一笔资产都有记录',
        description: '积分来源、有效期、退款和余额变动清晰可查',
        icon_url: '',
        background_url: '',
        link_type: 'assets',
      },
    },
    versions: {},
  }
}

const home = ref<HomeContent>(createDefaultHome())
const topSafeAreaStyle = createTopSafeAreaStyle()
const activeHeroSlide = computed(() => home.value.carousel.items[activeHeroIndex.value] || home.value.carousel.items[0])
const categoryIconMap: Record<string, string> = {
  'cat-errand': '/static/icons/cat-errand.svg',
  'cat-study': '/static/icons/cat-study.svg',
  'cat-secondhand': '/static/icons/cat-secondhand.svg',
  'cat-skill': '/static/icons/cat-skill.svg',
  'cat-lost': '/static/icons/cat-lost.svg',
  'cat-teamup': '/static/icons/cat-teamup.svg',
  'cat-research': '/static/icons/cat-research.svg',
  'cat-all': '/static/icons/cat-all.svg',
}
const quickEntries = computed(() => home.value.modules.quick_entries
  .filter((item) => {
    const code = HOME_LINK_MODULE[item.link_type as HomeLinkType]
    return !code || modules.isEnabled(code)
  })
  .slice(0, 8)
  .map((item) => ({
    ...item,
    action: () => handleKingLink(item.link_type, item.id),
    iconSrc: icons.resolve(categoryIconMap[item.id] || item.icon_url),
    displayLabel: icons.label(item.label, categoryIconMap[item.id] || item.icon_url),
  })))
const pageStyle = computed(() => ({
  ...backgroundStyle(home.value.modules.page_background_url),
  ...topSafeAreaStyle,
}))
const supportShortLabel = computed(() => {
  const value = String(home.value.floating_support.label || '客服').trim()
  if (value.includes('客服')) return '客服'
  return Array.from(value).slice(0, 2).join('') || '客服'
})
const recommendedTask = computed(() => hallTasks.value.find((task) => task.can_claim) || hallTasks.value[0] || null)
const activeClaim = computed(() => myClaims.value.find((claim) => !['approved', 'cancelled', 'expired', 'closed'].includes(claim.status)) || null)
const activeClaimPercent = computed(() => {
  const claim = activeClaim.value
  if (!claim) return 0
  if (typeof claim.progress?.percent === 'number') return Math.max(8, Math.min(100, claim.progress.percent))
  if (['submitted', 'reviewing', 'appealed', 'appeal_reviewing'].includes(claim.status)) return 76
  return Math.max(18, Math.min(62, (claim.submission_count || 0) * 28 + 22))
})
const recommendedRewardText = computed(() => {
  const rewards = recommendedTask.value?.rewards || []
  return rewards.length ? rewards.map(rewardLabel).join(' + ') : home.value.modules.recommendation.reward_text
})
const recommendedTimeText = computed(() => {
  const minutes = recommendedTask.value?.average_completion_minutes
  return minutes && minutes > 0 ? `约 ${Math.max(1, Math.round(minutes))} 分钟` : home.value.modules.recommendation.time_text
})
const recommendedReviewTimeText = computed(() => {
  const hours = recommendedTask.value?.review_deadline_hours
  return hours && hours > 0 ? `${Math.max(1, Math.round(hours))}h` : '24h'
})
const announcementSummary = computed(() => plainText(home.value.announcement.content) || '暂无新的校园公告')
const recommendedDescriptionText = computed(() => plainText(
  recommendedTask.value?.description_text
    || recommendedTask.value?.description
    || home.value.modules.recommendation.description,
))
const activeClaimDescriptionText = computed(() => plainText(
  activeClaim.value?.description_text
    || activeClaim.value?.description
    || activeClaim.value?.execution_status_label
    || '已领取，等待继续完成',
))
const activeClaimRewardText = computed(() => {
  const rewards = activeClaim.value?.rewards || []
  return rewards.length ? rewards.map(rewardLabel).join(' + ') : '任务奖励'
})
const registeredProducts = computed<RegisteredProductCard[]>(() => {
  const live = mallProducts.value.slice(0, 4).map((product) => ({
    id: product.id,
    name: product.name,
    image_url: product.gallery_images?.[0]?.url || '',
    price_text: mallProductPrice(product),
    live: true,
  }))
  if (live.length >= 4) return live
  const fallbacks = home.value.modules.product_section.cards.map((card, index) => ({
    id: `fallback-${card.id || index}`,
    name: card.title,
    image_url: card.image_url || '',
    price_text: card.price_text,
    live: false,
  }))
  const presets: RegisteredProductCard[] = [
    { id: 'fallback-campus-kit', name: '校园成长礼包', image_url: '', price_text: '800 积分', live: false },
    { id: 'fallback-study-card', name: '学习权益卡', image_url: '', price_text: '1,200 积分', live: false },
  ]
  return [...live, ...fallbacks, ...presets].slice(0, 4)
})

// v13 新增计算属性
const userPoints = computed(() => pointAccount.value?.available_points?.toLocaleString() || '0')
const todayEarned = computed(() => pointAccount.value?.points_earned_today ?? 0)
const checkedInToday = computed(() => checkInStatus.value?.checked_in_today ?? false)
const signDays = computed(() => {
  const status = checkInStatus.value
  const week = status?.week
  if (status && week?.length) {
    const dailyReward = status.rules?.daily_reward_points || status.today_reward_points || 0
    return week.map((day) => {
      // 已签到显示对勾；今天/未来日期显示当日可得积分；已过期未签到显示占位币符。
      let reward: number | null = null
      if (!day.checked_in && day.date >= status.today) reward = day.is_today ? (status.today_reward_points || dailyReward) : dailyReward
      return {
        label: day.is_today ? '今天' : day.weekday,
        done: day.checked_in,
        today: day.is_today,
        reward: reward || null,
      }
    })
  }
  const labels = ['周一', '周二', '周三', '周四', '周五', '周六', '周日']
  const todayIndex = (new Date().getDay() + 6) % 7
  return labels.map((label, index) => ({
    label: index === todayIndex ? '今天' : label,
    done: false,
    today: index === todayIndex,
    reward: null as number | null,
  }))
})
type FeedTab = 'recommend' | 'latest' | 'reward'
const activeFeedTab = ref<FeedTab>('recommend')
const feedTasks = computed(() => {
  const list = hallTasks.value.slice(0, 10)
  if (activeFeedTab.value === 'reward') {
    return [...list].sort((a, b) => maxReward(b.rewards) - maxReward(a.rewards)).slice(0, 5)
  }
  // 推荐与最新均按发布时间倒序（后端默认即最新优先）。
  return list.slice(0, 5)
})
function selectFeedTab(tab: FeedTab) { activeFeedTab.value = tab }
function maxReward(rewards: TaskReward[] | undefined) {
  return (rewards || []).reduce((max, reward) => Math.max(max, reward.points || 0), 0)
}

onLoad((options?: Record<string, string | undefined>) => {
  const hint = promotionCampusHint(options)
  if (hint) promotionCampusReady = campus.selectPromotionCampus(hint, auth.accessToken).catch(() => null)
})

// 微信运营规范 3.3：游客无需登录即可浏览。未选校时内容无法按校展示，
// 冷启动自动展开一次选校半屏（选校仅本地保存、不要求登录），可关闭且本次启动不再打扰。
onMounted(() => {
  void (async () => {
    if (campus.selected) return
    if (promotionCampusReady) {
      try { await promotionCampusReady } catch { /* 扫码选校失败时仍引导手动选择 */ }
    }
    setTimeout(() => {
      if (campusPromptShown || campus.selected) return
      campusPromptShown = true
      campusSelectorRef.value?.open()
    }, 350)
  })()
})

onShow(async () => {
  // 登录用户未绑校 / 档案不完整：强制进入三步绑校向导（匿名浏览不拦截）
  void ensureCampusBinding()
  void modules.ensureReady()
  if (promotionCampusReady) await promotionCampusReady
  await loadHome()
  if (auth.accessToken) await auth.fetchCurrentUser()
  await loadRegisteredHomeData()
  if (!auth.isAuthenticated) return
  try {
    const pending = await loadPendingLegalDocuments(auth.accessToken, 'account')
    if (pending.length > 0) uni.navigateTo({ url: '/pages/legal-consent/index?next=home' })
  } catch {
    // 服务端仍会执行协议校验，此处仅用于提前提示。
  }
})

async function loadRegisteredHomeData() {
  const tasksEnabled = modules.isEnabled('module_tasks')
  const mallEnabled = modules.isEnabled('module_mall')
  const [tasksResult, claimsResult, productsResult] = await Promise.allSettled([
    tasksEnabled ? listHallTasks({ claimable_only: false }) : Promise.resolve({ items: [] as HallTask[] }),
    auth.isAuthenticated && tasksEnabled ? listMyTaskClaims(auth.accessToken) : Promise.resolve({ items: [] as TaskClaim[] }),
    mallEnabled ? listProducts() : Promise.resolve({ items: [] as MallProduct[] }),
  ])
  if (tasksResult.status === 'fulfilled') hallTasks.value = tasksResult.value.items
  if (claimsResult.status === 'fulfilled') myClaims.value = claimsResult.value.items
  if (productsResult.status === 'fulfilled') mallProducts.value = productsResult.value.items
  // v13: 拉取积分账户与签到状态
  if (auth.accessToken) {
    const [accountResult, checkInResult] = await Promise.allSettled([
      getPointAccount(auth.accessToken),
      getDailyCheckIn(auth.accessToken),
    ])
    if (accountResult.status === 'fulfilled') {
      pointAccount.value = {
        available_points: accountResult.value.available_points,
        points_earned_today: accountResult.value.points_earned_today ?? 0,
      }
    } else {
      pointAccount.value = null
    }
    if (checkInResult.status === 'fulfilled') checkInStatus.value = checkInResult.value
  }
}

async function handleCampusChange(_campus: Campus) {
  await loadRegisteredHomeData()
}

function promotionCampusHint(options?: Record<string, string | undefined>) {
  if (options?.campus) return options.campus
  if (!options?.scene) return ''
  let scene = options.scene
  try { scene = decodeURIComponent(scene) } catch { /* 保留微信传入的原始场景值。 */ }
  const pairs = scene.split(/[&,;]/).map(part => part.split('='))
  const campusPair = pairs.find(([key]) => key === 'campus' || key === 'school')
  return campusPair?.slice(1).join('=') || (scene.includes('=') ? '' : scene)
}

function homeShareTitle() {
  return home.value.brand.name || '任务商城'
}
function homeShareImage() {
  const logo = home.value.brand.logo_url
  return logo ? resolveApiAssetUrl(logo) : undefined
}

onShareAppMessage(() => ({ title: homeShareTitle(), path: '/pages/index/index', imageUrl: homeShareImage() }))
onShareTimeline(() => ({ title: homeShareTitle(), imageUrl: homeShareImage() }))

async function loadHome() {
  if (homeLoading.value) return
  homeLoading.value = true
  homeError.value = ''
  failedHeroImages.value = {}
  try {
    home.value = normalizeHomeContent(await loadHomeContent())
    const preferredHeroIndex = home.value.carousel.items.findIndex((item) => item.image_url)
    activeHeroIndex.value = preferredHeroIndex >= 0 ? preferredHeroIndex : 0
    void enableWechatShare({ title: homeShareTitle(), desc: home.value.brand.caption || '校园任务互助 · 积分换好物', link: '/pages/index/index', imageUrl: homeShareImage() })
    showPopupWhenNeeded()
  } catch {
    homeError.value = '首页配置暂时不可用'
    showPopupWhenNeeded()
  } finally {
    homeLoaded.value = true
    homeLoading.value = false
  }
}

function normalizeHomeContent(remote: HomeContent): HomeContent {
  const fallback = createDefaultHome()
  const modules = remote?.modules || fallback.modules
  return {
    ...fallback,
    ...remote,
    brand: { ...fallback.brand, ...(remote?.brand || {}) },
    carousel: {
      ...fallback.carousel,
      ...(remote?.carousel || {}),
      items: remote?.carousel?.items?.length ? remote.carousel.items : fallback.carousel.items,
    },
    announcement: { ...fallback.announcement, ...(remote?.announcement || {}) },
    popup: { ...fallback.popup, ...(remote?.popup || {}) },
    floating_support: { ...fallback.floating_support, ...(remote?.floating_support || {}) },
    modules: {
      ...fallback.modules,
      ...modules,
      quick_entries: modules.quick_entries?.length ? modules.quick_entries : fallback.modules.quick_entries,
      priority_entries: modules.priority_entries?.length ? modules.priority_entries : fallback.modules.priority_entries,
      recommendation: { ...fallback.modules.recommendation, ...(modules.recommendation || {}) },
      product_section: {
        ...fallback.modules.product_section,
        ...(modules.product_section || {}),
        cards: modules.product_section?.cards?.length
          ? modules.product_section.cards
          : fallback.modules.product_section.cards,
      },
      trust: { ...fallback.modules.trust, ...(modules.trust || {}) },
    },
    versions: remote?.versions || {},
  }
}

function titleParts(title: string) {
  const raw = String(title || '').trim()
  const match = raw.match(/^(.+?[，,])\s*(.+)$/)
  if (!match) return { primary: raw, secondary: '', accent: '' }

  const accentMatch = match[2].match(/^(.*?)(一致)$/)
  return {
    primary: match[1],
    secondary: accentMatch ? accentMatch[1] : match[2],
    accent: accentMatch ? accentMatch[2] : '',
  }
}

function plainText(value: string | null | undefined) {
  return String(value || '')
    .replace(/<[^>]+>/g, ' ')
    .replace(/&nbsp;|&#160;/gi, ' ')
    .replace(/&amp;/gi, '&')
    .replace(/&lt;/gi, '<')
    .replace(/&gt;/gi, '>')
    .replace(/\s+/g, ' ')
    .trim()
}

function quickArtType(id: string, linkType: HomeLinkType) {
  const value = id || linkType
  if (value === 'products') return 'products'
  if (value === 'community') return 'community'
  if (value === 'orders') return 'orders'
  if (value === 'second_hand') return 'second_hand'
  return 'tasks'
}

function quickArtSymbol(id: string, linkType: HomeLinkType) {
  const type = quickArtType(id, linkType)
  if (type === 'products') return '★'
  if (type === 'community') return '•••'
  if (type === 'orders') return '≡'
  if (type === 'second_hand') return '↻'
  return '✓'
}

function quickArtIcon(id: string, linkType: HomeLinkType) {
  const type = quickArtType(id, linkType)
  if (type === 'products') return icons.resolve('/static/icons/module-mall.svg')
  if (type === 'community') return icons.resolve('/static/icons/module-community.svg')
  if (type === 'orders') return icons.resolve('/static/icons/service-orders.svg')
  if (type === 'second_hand') return icons.resolve('/static/icons/module-mall-trade.svg')
  return icons.resolve('/static/icons/module-tasks.svg')
}

function heroImageSource(slide: HomeCarouselItem) {
  if (slide.image_url && !failedHeroImages.value[slide.id]) return resolveApiAssetUrl(slide.image_url)
  return fallbackHeroImage
}

function handleHeroImageError(slide: HomeCarouselItem) {
  if (!slide.image_url || failedHeroImages.value[slide.id]) return
  failedHeroImages.value = { ...failedHeroImages.value, [slide.id]: true }
}

function handleHeroChange(event: { detail?: { current?: number } }) {
  activeHeroIndex.value = Number(event.detail?.current || 0)
}

function priorityIcon(id: 'assets' | 'referrals') {
  return home.value.modules.priority_entries?.find((entry) => entry.id === id)?.icon_url || ''
}

function rewardLabel(reward: TaskReward) {
  if (reward.type === 'wallet_balance') return `+¥${Number(reward.balance_amount || 0).toFixed(2)}`
  if (reward.type === 'coupon') return `+${reward.quantity || 1}张券`
  return `+${Number(reward.points || 0).toLocaleString()} 积分`
}

function taskDifficultyLabel(task: HallTask | null) {
  if (!task) return home.value.modules.recommendation.tag || '简单'
  if (task.average_completion_minutes && task.average_completion_minutes > 30) return '进阶'
  if (task.steps.length >= 4) return '进阶'
  return '简单'
}

function mallProductPrice(product: MallProduct) {
  const options = product.skus.flatMap((sku) => sku.purchase_options.filter((option) => option.enabled))
  const points = options.filter((option) => option.purchase_mode === 'points').sort((a, b) => a.points - b.points)[0]
  const cash = options.filter((option) => option.purchase_mode === 'cash').sort((a, b) => a.cash_amount - b.cash_amount)[0]
  const mixed = options.filter((option) => option.purchase_mode === 'cash_points').sort((a, b) => a.cash_amount - b.cash_amount)[0]
  if (points) return `${points.points.toLocaleString()} 积分`
  if (cash) return `¥${(cash.cash_amount / 100).toFixed(2)}`
  if (mixed) return `¥${(mixed.cash_amount / 100).toFixed(2)} + 积分`
  return '查看详情'
}

function productImage(product: RegisteredProductCard) { return product.image_url }
function productPriceText(product: RegisteredProductCard) { return product.price_text }
function openRecommendedTask() {
  if (recommendedTask.value) return uni.navigateTo({ url: `/pages/task-detail/index?id=${recommendedTask.value.id}` })
  openTasks()
}
function openActiveClaim() {
  if (activeClaim.value) return uni.navigateTo({ url: `/pages/task-claim/index?id=${activeClaim.value.id}` })
  openTasks()
}
function openProduct(id: string) {
  if (id.startsWith('fallback-')) return openProducts()
  uni.navigateTo({ url: `/pages/product-detail/index?id=${id}` })
}

function openLogin() { navigateToLogin() }
function openSearch() { uni.navigateTo({ url: '/pages/search/index' }) }
function openTasks() { openPrimaryNavigation('tasks') }
function openProducts() { openPrimaryNavigation('products') }
function openCommunity() { openPrimaryNavigation('community') }
function openOrders() { auth.isAuthenticated ? uni.navigateTo({ url: '/pages/orders/index' }) : openLogin() }
function openSecondHand() { uni.navigateTo({ url: '/pages/second-hand/index' }) }
function openNotifications() { auth.isAuthenticated ? uni.navigateTo({ url: '/pages/notifications/index' }) : openLogin() }
function openAssets() { auth.isAuthenticated ? uni.navigateTo({ url: '/pages/assets/index' }) : openLogin() }
function openPushCenter() { auth.isAuthenticated ? uni.navigateTo({ url: '/pages/push-center/index' }) : openLogin() }
function openReferrals() { auth.isAuthenticated ? uni.navigateTo({ url: '/pages/referrals/index' }) : openLogin() }
function openFloatingSupport() { uni.navigateTo({ url: '/pages/support/index' }) }
function goCheckIn() { uni.navigateTo({ url: '/pages/check-in/index' }) }
function openTaskDetail(id: string) { uni.navigateTo({ url: `/pages/task-detail/index?id=${id}` }) }

function navigateHomeLink(link: HomeLinkType) {
  const moduleCode = HOME_LINK_MODULE[link]
  if (moduleCode && !modules.isEnabled(moduleCode)) {
    uni.showToast({ title: '功能开发中，敬请期待', icon: 'none' })
    return
  }
  if (link === 'tasks') return openTasks()
  if (link === 'products') return openProducts()
  if (link === 'community') return openCommunity()
  if (link === 'notifications') return openNotifications()
  if (link === 'orders') return openOrders()
  if (link === 'assets') return openAssets()
  if (link === 'push') return openPushCenter()
  if (link === 'second_hand' || link === 'secondhand') return openSecondHand()
}

// 金刚区跳转：支持 tasks:<category> 直达大厅指定分类。
function handleKingLink(link: string, id: string) {
  if (link === 'secondhand' || id === 'cat-secondhand') return openSecondHand()
  if (link === 'push' || id === 'pt-center') return openPushCenter()
  if (link.startsWith('tasks:')) {
    const categoryValue = link.slice('tasks:'.length)
    if (categoryValue) uni.setStorageSync('task_mall_hall_category', categoryValue)
    return openTasks()
  }
  navigateHomeLink(link as HomeLinkType)
}

function backgroundStyle(url: string) {
  return url ? { backgroundImage: `url(${url})`, backgroundPosition: 'center', backgroundSize: 'cover' } : undefined
}

function popupStorageKey() { return `task_mall_home_popup_seen_${home.value.popup.content_id}` }
function popupMutedStorageKey() { return `task_mall_home_popup_muted_${home.value.popup.content_id}` }
function showPopupWhenNeeded() {
  const popup = home.value.popup
  if (auth.isAuthenticated) {
    popupVisible.value = false
    return
  }
  if (!popup.enabled) return
  if (uni.getStorageSync(popupMutedStorageKey())) return
  if (popup.display_once && uni.getStorageSync(popupStorageKey())) return
  popupVisible.value = true
}
function closePopup() {
  if (home.value.popup.display_once) uni.setStorageSync(popupStorageKey(), true)
  popupVisible.value = false
}
function disablePopup() {
  uni.setStorageSync(popupMutedStorageKey(), true)
  uni.setStorageSync(popupStorageKey(), true)
  popupVisible.value = false
}
function handlePopupAction() {
  const link = home.value.popup.link_type
  closePopup()
  navigateHomeLink(link)
}

// v13 新增工具函数
function taskRewardText(task: HallTask) {
  const rewards = task.rewards || []
  if (!rewards.length) return '+0 积分'
  return rewards.map(rewardLabel).join(' + ')
}

const feedTabOptions: Array<{ id: FeedTab; label: string }> = [
  { id: 'recommend', label: '推荐' },
  { id: 'latest', label: '最新' },
  { id: 'reward', label: '高价' },
]

function taskAvatarSrc(task: HallTask) {
  return resolveApiAssetUrl(task.publisher?.avatar_url) || ''
}

function publisherInitial(task: HallTask) {
  return (task.publisher?.display_name || '同').slice(0, 1)
}

function postMetaText(task: HallTask) {
  const parts: string[] = []
  if (task.published_at) parts.push(formatRelativeTime(task.published_at))
  if (task.publisher?.credit_score) parts.push(`信用分${task.publisher.credit_score}`)
  return parts.join(' · ')
}

function postBodyText(task: HallTask) {
  return plainText(task.description_text || task.description) || task.title
}

function isTeamTask(task: HallTask) {
  return task.category === 'teamup' || (task.tags || []).some(tag => tag.includes('组队'))
}

function postTags(task: HallTask) {
  return (task.tags || []).filter(tag => tag !== '悬赏' && tag !== '加急').slice(0, 3)
}

// 关注功能暂无 C2C 后端链路，先做占位，不伪造交互结果。
function followPublisher(_task: HallTask) {
  if (!auth.isAuthenticated) return openLogin()
  uni.showToast({ title: '关注功能即将上线', icon: 'none' })
}

function kingIconStyle(tone: string) {
  const map: Record<string, { bg: string; color: string }> = {
    green: { bg: '#E7F9EF', color: '#0BBF63' },
    blue: { bg: '#EAF2FF', color: '#3B82F6' },
    orange: { bg: '#FFF2E5', color: '#F2620A' },
    violet: { bg: '#F1EAFF', color: '#8B5CF6' },
    pink: { bg: '#FFEAF1', color: '#F43F7F' },
    cyan: { bg: '#E3F7F5', color: '#12A59B' },
    yellow: { bg: '#FFF6DC', color: '#D99400' },
    gray: { bg: '#F0F2F5', color: '#8A919C' },
    warm: { bg: '#FFF6DE', color: '#D99400' },
  }
  const style = map[tone] || map.green
  return { background: style.bg, color: style.color }
}
</script>

<style scoped>
.home-v13{min-height:100vh;background:#F4F6F4;color:#191B1F;padding-bottom:106rpx}
.home-v13-safe{height:var(--tm-custom-header-top,calc(env(safe-area-inset-top) + 8px))}

/* ============ 顶部 ============ */
.home-v13-head{display:flex;align-items:center;gap:16rpx;padding:4rpx 32rpx 0}
.home-v13-campus-wrap{flex:none;min-width:0}
.home-v13-search{flex:1;height:68rpx;border-radius:34rpx;background:#fff;border:2rpx solid #EDEFEF;display:flex;align-items:center;gap:10rpx;padding:0 24rpx;color:#AEB3BB;font-size:24rpx}
.home-v13-search-icon{width:28rpx;height:28rpx;border:3rpx solid #AEB3BB;border-radius:50%;position:relative}
.home-v13-search-icon::after{content:'';position:absolute;bottom:-8rpx;right:-6rpx;width:14rpx;height:3rpx;background:#AEB3BB;border-radius:2rpx;transform:rotate(45deg)}
.home-v13-bell{position:relative;width:68rpx;height:68rpx;border-radius:50%;background:#fff;border:2rpx solid #EDEFEF;display:flex;align-items:center;justify-content:center;flex:none;color:#191B1F}
.home-v13-bell-icon{width:34rpx;height:38rpx;border:3rpx solid #191B1F;border-bottom:0;border-radius:16rpx 16rpx 4rpx 4rpx;position:relative}
.home-v13-bell-icon::before{content:'';position:absolute;right:-6rpx;bottom:-4rpx;left:-6rpx;height:4rpx;border-radius:4rpx;background:#191B1F}
.home-v13-bell-icon::after{content:'';position:absolute;bottom:-10rpx;left:10rpx;width:12rpx;height:6rpx;border-radius:0 0 8rpx 8rpx;background:#191B1F}
.home-v13-bell-dot{position:absolute;top:14rpx;right:16rpx;width:14rpx;height:14rpx;border-radius:50%;background:#FF3B30;border:3rpx solid #fff}

/* ============ 积分卡片 ============ */
.home-v13-point-card{margin:24rpx 24rpx 0;border-radius:36rpx;background:linear-gradient(135deg,#12D978,#08A152);color:#fff;padding:32rpx 32rpx 28rpx;position:relative;overflow:hidden;box-shadow:0 20rpx 48rpx rgba(11,191,99,.28)}
.home-v13-point-card::before{content:'';position:absolute;width:380rpx;height:380rpx;border-radius:50%;background:rgba(255,255,255,.10);top:-180rpx;right:-100rpx}
.home-v13-point-card::after{content:'';position:absolute;width:240rpx;height:240rpx;border-radius:50%;background:rgba(255,255,255,.08);bottom:-140rpx;right:140rpx}
.home-v13-pc-top{position:relative;display:flex;align-items:flex-end;justify-content:space-between}
.home-v13-pc-left{display:flex;flex-direction:column}
.home-v13-pc-label{font-size:24rpx;opacity:.85;display:flex;align-items:center;gap:10rpx}
.home-v13-pc-num{font-size:68rpx;font-weight:800;line-height:1.15;letter-spacing:1rpx;font-variant-numeric:tabular-nums}
.home-v13-pc-sub{font-size:24rpx;font-weight:500;opacity:.85;margin-left:8rpx}
.home-v13-pc-sign{height:64rpx;padding:0 28rpx;border-radius:32rpx;background:#fff;color:#08A152;font-size:24rpx;font-weight:700;display:inline-flex;align-items:center;gap:8rpx;box-shadow:0 8rpx 20rpx rgba(0,0,0,.12);border:0}
.home-v13-pc-sign::after{display:none}
.home-v13-pc-sign.signed{background:rgba(255,255,255,.25);color:#fff;box-shadow:none}
.home-v13-pc-sign-icon{font-size:22rpx;font-weight:900}
.home-v13-sign-strip{position:relative;display:flex;margin-top:28rpx;background:rgba(255,255,255,.14);border-radius:24rpx;padding:18rpx 12rpx}
.home-v13-sign-day{flex:1;display:flex;flex-direction:column;align-items:center;gap:8rpx;font-size:20rpx;color:rgba(255,255,255,.85);background:transparent}
.home-v13-sign-day-badge{width:48rpx;height:48rpx;border:3rpx solid transparent;border-radius:50%;background:rgba(255,255,255,.18);display:flex;align-items:center;justify-content:center;font-size:20rpx}
.home-v13-sign-day-check{font-size:24rpx;font-weight:800;line-height:1}
.home-v13-sign-day.done .home-v13-sign-day-badge{background:#fff;color:#08A152}
.home-v13-sign-day.today .home-v13-sign-day-badge{background:#FFB020;color:#7A4A00;border-color:rgba(255,255,255,.85)}
.home-v13-sign-day.done.today .home-v13-sign-day-badge{width:54rpx;height:54rpx;background:#fff;color:#08A152;border-color:rgba(255,255,255,.9)}
.home-v13-sign-day.today{color:#fff;font-weight:700}

/* ============ 金刚区 ============ */
.home-v13-king{background:#fff;border-radius:32rpx;margin:20rpx 24rpx 0;padding:32rpx 20rpx 24rpx;display:grid;grid-template-columns:repeat(4,1fr);row-gap:28rpx;box-shadow:0 2rpx 4rpx rgba(23,26,23,.03),0 12rpx 40rpx rgba(23,26,23,.05)}
.home-v13-king-item{display:flex;flex-direction:column;align-items:center;gap:10rpx;font-size:22rpx;color:#3A3D43}
.home-v13-king-icon{width:88rpx;height:88rpx;border-radius:28rpx;display:flex;align-items:center;justify-content:center;font-size:28rpx;font-weight:800}
.home-v13-king-icon-img{width:48rpx;height:48rpx}
.home-v13-king-label{font-size:22rpx;color:#3A3D43}

/* ============ 橙色推广横幅 ============ */
.home-v13-promo{margin:20rpx 24rpx 0;border-radius:32rpx;background:linear-gradient(135deg,#FF9A3D,#F2620A);height:168rpx;display:flex;align-items:center;overflow:hidden;position:relative;color:#fff;box-shadow:0 16rpx 36rpx rgba(242,98,10,.22)}
.home-v13-promo-text{flex:1;padding:0 32rpx;position:relative;z-index:2;display:flex;flex-direction:column;justify-content:center}
.home-v13-promo-title{font-size:34rpx;font-weight:800;display:flex;align-items:center;gap:10rpx}
.home-v13-promo-desc{font-size:22rpx;opacity:.92;margin-top:6rpx}
.home-v13-promo-btn{display:inline-flex;align-items:center;height:48rpx;padding:0 20rpx;border-radius:24rpx;background:#fff;color:#F2620A;font-size:22rpx;font-weight:700;margin-top:12rpx;align-self:flex-start}
.home-v13-promo-img{width:300rpx;height:100%;flex:none;mask-image:linear-gradient(to right,transparent,#000 38%);-webkit-mask-image:linear-gradient(to right,transparent,#000 38%)}

/* ============ Feed Tabs ============ */
.home-v13-feed-tabs{display:flex;align-items:center;gap:36rpx;padding:28rpx 32rpx 4rpx;font-size:26rpx;color:#767C85}
.home-v13-feed-tab{padding-bottom:8rpx}
.home-v13-feed-tab.active{color:#191B1F;font-size:30rpx;font-weight:700;position:relative}
.home-v13-feed-tab.active::after{content:'';position:absolute;left:50%;bottom:0;transform:translateX(-50%);width:36rpx;height:6rpx;border-radius:4rpx;background:#0BBF63}
.home-v13-feed-more{margin-left:auto;font-size:24rpx;color:#9AA0A8;padding-bottom:8rpx}

/* ============ 社交式任务卡片 post ============ */
.home-v13-post{background:#fff;border-radius:28rpx;margin:20rpx 24rpx 0;padding:28rpx 24rpx 22rpx;box-shadow:0 2rpx 4rpx rgba(23,26,23,.03),0 12rpx 40rpx rgba(23,26,23,.05)}
.home-v13-post-head{display:flex;align-items:center;gap:16rpx}
.home-v13-post-avatar{width:76rpx;height:76rpx;border-radius:50%;flex:none;background:#F0F2F5}
.home-v13-post-avatar-fallback{display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,#D9F2E3,#B4EDD1);color:#08A152;font-size:30rpx;font-weight:800}
.home-v13-post-head-main{flex:1;min-width:0;display:flex;flex-direction:column;gap:6rpx}
.home-v13-post-name-row{display:flex;align-items:center;gap:10rpx}
.home-v13-post-name{font-size:27rpx;font-weight:700;color:#191B1F;max-width:260rpx;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.home-v13-post-badge{height:32rpx;line-height:32rpx;padding:0 10rpx;border-radius:8rpx;background:#FFF2E5;color:#F2620A;font-size:18rpx;font-weight:600}
.home-v13-post-meta{font-size:21rpx;color:#AEB3BB}
.home-v13-post-follow{height:52rpx;line-height:52rpx;padding:0 24rpx;border-radius:26rpx;background:#FFF6EC;color:#F2620A;font-size:22rpx;font-weight:600;flex:none}
.home-v13-post-body{display:block;margin-top:18rpx;font-size:27rpx;line-height:1.55;color:#2A2D33}
.home-v13-post-tags{margin-top:14rpx;display:flex;flex-wrap:wrap;gap:10rpx}
.home-v13-tag{display:inline-flex;align-items:center;height:36rpx;padding:0 12rpx;border-radius:10rpx;font-size:20rpx;font-weight:500;background:#F2F4F3;color:#767C85}
.home-v13-tag.tag-red{background:#FFEEEA;color:#FF3B30}
.home-v13-tag.tag-orange{background:#FFF2E5;color:#F2620A}
.home-v13-tag.tag-green{background:#E7F9EF;color:#08A152}
.home-v13-post-gallery{margin-top:16rpx;display:grid;gap:10rpx}
.home-v13-post-gallery.g-1{grid-template-columns:1fr}
.home-v13-post-gallery.g-2{grid-template-columns:1fr 1fr}
.home-v13-post-gallery.g-3{grid-template-columns:1fr 1fr 1fr}
.home-v13-post-gallery-img{width:100%;aspect-ratio:1/1;border-radius:16rpx;background:#F4F6F4}
.home-v13-post-gallery.g-1 .home-v13-post-gallery-img{aspect-ratio:16/10}
.home-v13-post-foot{display:flex;align-items:center;margin-top:18rpx;gap:14rpx}
.home-v13-post-pts{font-size:34rpx;font-weight:800;color:#08A152;line-height:1}
.home-v13-post-loc{font-size:21rpx;color:#9AA0A8;flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.home-v13-post-btn{margin-left:auto;height:60rpx;padding:0 34rpx;border-radius:30rpx;background:linear-gradient(135deg,#12D978,#08A152);color:#fff;font-size:24rpx;font-weight:600;box-shadow:0 8rpx 20rpx rgba(11,191,99,.28);border:0}
.home-v13-post-btn::after{display:none}

/* ============ 空态 ============ */
.home-v13-empty{margin:20rpx 24rpx 0;padding:40rpx;background:#fff;border-radius:32rpx;display:flex;flex-direction:column;align-items:center;gap:10rpx;box-shadow:0 2rpx 4rpx rgba(23,26,23,.03),0 12rpx 40rpx rgba(23,26,23,.05)}
.home-v13-empty-text{font-size:26rpx;color:#767C85}
.home-v13-empty-link{font-size:24rpx;color:#08A152;font-weight:700}

/* ============ 错误提示 ============ */
.home-v13-error{margin:20rpx 24rpx 0;padding:20rpx 24rpx;border-radius:16rpx;background:#FFF5DF;color:#A56C08;font-size:24rpx;text-align:center}

/* ============ 弹窗 ============ */
.home-v13-popup-mask{position:fixed;z-index:999;inset:0;display:flex;align-items:center;justify-content:center;padding:44rpx;background:rgba(12,31,40,.68)}
.home-v13-popup{position:relative;overflow:hidden;width:100%;max-width:620rpx;border-radius:32rpx;background:#fff;box-shadow:0 30rpx 90rpx rgba(0,0,0,.25)}
.home-v13-popup-close{position:absolute;z-index:2;top:18rpx;right:18rpx;display:flex;width:53rpx;height:53rpx;align-items:center;justify-content:center;border-radius:50%;background:#edf1f2;color:#536474;font-size:21px}
.home-v13-popup-image{display:block;width:100%}
.home-v13-popup-body{padding:44rpx 38rpx 36rpx;text-align:center}
.home-v13-popup-title,.home-v13-popup-content{display:block}
.home-v13-popup-title{color:#10283a;font-size:21px;font-weight:950}
.home-v13-popup-content{margin-top:13rpx;color:#5f7184;font-size:14px;line-height:1.65}
.home-v13-popup-button{height:82rpx;margin-top:26rpx;border:0;border-radius:999rpx;background:linear-gradient(110deg,#08ac69,#0ac383);color:#fff;font-size:15px;font-weight:900;line-height:82rpx;box-shadow:0 12rpx 26rpx rgba(7,170,105,.2)}
.home-v13-popup-button::after{display:none}
.home-v13-popup-muted{display:block;margin-top:20rpx;color:#71818c;font-size:13px;line-height:1.4;text-align:center;text-decoration:underline;text-underline-offset:4rpx}
</style>
