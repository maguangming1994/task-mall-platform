<template>
  <view class="page">
    <view class="heading">
      <text class="eyebrow">MESSAGE CENTER</text>
      <text class="title">消息中心</text>
      <text class="sub">任务、订单、售后和资产变动统一留档，点击消息可前往对应业务页面</text>
    </view>

    <view class="summary-card">
      <view class="summary-main">
        <view class="summary-count">{{ unreadTotal }}</view>
        <view><text class="summary-title">条未读消息</text><text class="summary-copy">共 {{ total }} 条{{ activeCategory ? categoryText(activeCategory) : '' }}消息</text></view>
      </view>
      <view class="summary-actions">
        <button class="summary-button" @click="showSettings = !showSettings">{{ showSettings ? '收起设置' : '通知设置' }}</button>
        <button class="summary-button primary" :disabled="unreadTotal === 0 || markingAll" :loading="markingAll" @click="readAll">全部已读</button>
      </view>
    </view>

    <view v-if="showSettings && preference" class="settings-card">
      <view class="settings-head"><view class="settings-icon">信</view><view><text>通知偏好</text><text>外部渠道可关闭，站内关键记录始终保留</text></view></view>
      <view class="setting-row"><view class="setting-copy"><text>微信通知</text><text>接收任务、订单与资产提醒</text></view><switch color="#16839b" :checked="preference.wechat_enabled" :disabled="savingPreference" @change="changePreference('wechat_enabled', $event)" /></view>
      <!-- #ifdef MP-WEIXIN -->
      <view v-if="wechatSubscriptions?.configured" class="subscription-section">
        <view class="subscription-heading"><text>小程序订阅提醒</text><text>微信一次授权对应一次后续服务通知</text></view>
        <view class="subscription-actions">
          <button v-for="group in wechatSubscriptions.groups" :key="group.context" class="subscription-button" :loading="subscribingContext === group.context" :disabled="Boolean(subscribingContext)" @click="subscribeWechat(group)">
            <text>{{ group.label }}</text><text>{{ availableGrantCount(group) > 0 ? `可用 ${availableGrantCount(group)} 次` : '点击授权' }}</text>
          </button>
        </view>
      </view>
      <!-- #endif -->
      <view class="setting-row"><view class="setting-copy"><text>短信通知</text><text>用于重要状态及安全验证</text></view><switch color="#16839b" :checked="preference.sms_enabled" :disabled="savingPreference" @change="changePreference('sms_enabled', $event)" /></view>
      <view class="setting-row"><view class="setting-copy"><text>营销订阅</text><text>接收校园活动与优惠推荐</text></view><switch color="#16839b" :checked="preference.marketing_subscribed" :disabled="savingPreference" @change="changePreference('marketing_subscribed', $event)" /></view>
      <view class="quiet-row">
        <view class="setting-copy"><text>免打扰时间</text><text>非关键消息将在此时段静默</text></view>
        <view class="quiet-pickers">
          <picker mode="time" :value="preference.quiet_hours_start" @change="changeQuietHour('quiet_hours_start', $event)"><view class="time-value">{{ preference.quiet_hours_start }}</view></picker>
          <text>至</text>
          <picker mode="time" :value="preference.quiet_hours_end" @change="changeQuietHour('quiet_hours_end', $event)"><view class="time-value">{{ preference.quiet_hours_end }}</view></picker>
        </view>
      </view>
      <text class="settings-note">支付、退款、提现和安全类关键消息不受免打扰限制。</text>
    </view>

    <scroll-view class="category-scroll" scroll-x :show-scrollbar="false">
      <view class="category-list">
        <view v-for="category in categories" :key="category.value || 'all'" :class="['category-chip', { active: activeCategory === category.value }]" @click="selectCategory(category.value)">
          <text>{{ category.label }}</text><text v-if="unreadFor(category.value)" class="chip-count">{{ unreadFor(category.value) }}</text>
        </view>
      </view>
    </scroll-view>

    <view v-if="activeCategory === 'community'" class="community-shortcuts">
      <view
        v-for="shortcut in communityShortcuts"
        :key="shortcut.value"
        :class="['community-shortcut', `shortcut-${shortcut.value}`, { active: communityFilter === shortcut.value }]"
        @click="communityFilter = communityFilter === shortcut.value ? 'all' : shortcut.value"
      >
        <view class="shortcut-top"><text class="shortcut-icon">{{ shortcut.icon }}</text><text class="shortcut-count">{{ shortcut.count }}</text></view>
        <text class="shortcut-label">{{ shortcut.label }}</text>
        <text class="shortcut-hint">{{ shortcut.hint }}</text>
      </view>
    </view>

    <view class="list-toolbar">
      <text>{{ listTitle }}</text>
      <view :class="['unread-filter', { active: unreadOnly }]" @click="toggleUnreadOnly"><view class="filter-dot" /><text>仅看未读</text></view>
    </view>

    <view v-if="errorMessage && !items.length" class="state-card error-card">
      <view class="state-icon">!</view><text class="state-title">消息加载失败</text><text class="state-copy">{{ errorMessage }}</text><button class="retry-button" @click="reload">重新加载</button>
    </view>
    <view v-else-if="loading && !items.length" class="skeleton-list"><view v-for="index in 3" :key="index" class="skeleton-card tm-skeleton" /></view>

    <view v-for="item in visibleItems" :key="item.id" class="notice-card" :class="{ unread: !item.read_at }" @click="openNotification(item)">
      <view :class="['category-mark', `tone-${item.category}`]">{{ categoryMark(item.category) }}</view>
      <view class="notice-main">
        <view class="notice-head"><text class="notice-title">{{ item.title }}</text><text class="category-tag">{{ categoryText(item.category) }}</text></view>
        <text class="body">{{ item.body }}</text>
        <view class="notice-foot"><text>{{ formatTime(item.created_at) }}</text><text v-if="notificationTarget(item)" class="detail-link">查看详情 ›</text></view>
      </view>
    </view>

    <view v-if="!loading && !visibleItems.length && !errorMessage" class="state-card">
      <view class="state-icon">信</view><text class="state-title">{{ unreadOnly ? '当前没有未读消息' : '暂无消息' }}</text><text class="state-copy">{{ activeCategory ? `有新的${categoryText(activeCategory)}消息时会在这里提醒你` : '有新的任务、订单或资产变化时会在这里提醒你' }}</text>
    </view>

    <button v-if="items.length && hasMore" class="load-more" :loading="loadingMore" :disabled="loadingMore" @click="loadMore">加载更多</button>
    <text v-else-if="items.length && !hasMore" class="list-end">已展示全部消息</text>
    <AppTabBar current-target="account" />
  </view>
</template>

<script setup lang="ts">
import { formatDateTime as formatTime } from '@/utils/dateTime'
import { onLoad, onReachBottom, onShow } from '@dcloudio/uni-app'
import { computed, ref } from 'vue'
import {
  getNotificationPreference,
  getWechatSubscriptionOptions,
  listNotifications,
  markAllNotificationsRead,
  markNotificationRead,
  updateNotificationPreference,
  recordWechatSubscriptionResults,
  type AppNotification,
  type NotificationCategory,
  type NotificationPreference,
  type NotificationSubscriptionContext,
  type WechatSubscriptionGroup,
  type WechatSubscriptionOptions,
  type WechatSubscriptionResult,
} from '@/api/notifications'
import { ApiError } from '@/api/http'
import AppTabBar from '@/components/AppTabBar.vue'
import { useAuthStore } from '@/stores/auth'
import { navigateToLogin } from '@/utils/authRedirect'

type CategoryFilter = '' | NotificationCategory
type CommunityFilter = 'all' | 'comments' | 'likes' | 'reviews'
type TogglePreferenceKey = 'wechat_enabled' | 'sms_enabled' | 'marketing_subscribed'
type QuietHourKey = 'quiet_hours_start' | 'quiet_hours_end'
interface PendingWechatSubscriptionResults { request_id: string; results: WechatSubscriptionResult[] }

const auth = useAuthStore()
const items = ref<AppNotification[]>([])
const preference = ref<NotificationPreference | null>(null)
const wechatSubscriptions = ref<WechatSubscriptionOptions | null>(null)
const activeCategory = ref<CategoryFilter>('')
const communityFilter = ref<CommunityFilter>('all')
const unreadOnly = ref(false)
const unreadTotal = ref(0)
const categoryUnreadCounts = ref<Partial<Record<NotificationCategory, number>>>({})
const total = ref(0)
const page = ref(1)
const pageSize = 10
const hasMore = ref(false)
const loading = ref(false)
const loadingMore = ref(false)
const markingAll = ref(false)
const savingPreference = ref(false)
const subscribingContext = ref<NotificationSubscriptionContext | ''>('')
const loadingWechatSubscriptions = ref(false)
const subscriptionCursors = ref<Partial<Record<NotificationSubscriptionContext, number>>>({})
const showSettings = ref(false)
const errorMessage = ref('')
let requestVersion = 0

const categories: Array<{ value: CategoryFilter; label: string }> = [
  { value: '', label: '全部' },
  { value: 'task', label: '任务' },
  { value: 'order', label: '订单' },
  { value: 'after_sale', label: '售后' },
  { value: 'asset', label: '资产' },
  { value: 'community', label: '社区' },
  { value: 'system', label: '系统' },
]

const communityFilterMeta: Array<{ value: CommunityFilter; label: string; hint: string; icon: string }> = [
  { value: 'comments', label: '评论与回复', hint: '同学正在回应你', icon: '评' },
  { value: 'likes', label: '收到的赞', hint: '看看谁为你点赞', icon: '赞' },
  { value: 'reviews', label: '审核与组团', hint: '内容与活动进度', icon: '审' },
]

const communityShortcuts = computed(() => communityFilterMeta.map((item) => ({
  ...item,
  count: items.value.filter((notification) => communitySceneGroup(notification.scene_code) === item.value).length,
})))
const visibleItems = computed(() => activeCategory.value !== 'community' || communityFilter.value === 'all'
  ? items.value
  : items.value.filter((notification) => communitySceneGroup(notification.scene_code) === communityFilter.value))
const listTitle = computed(() => {
  if (activeCategory.value !== 'community') return activeCategory.value ? categoryText(activeCategory.value) : '全部消息'
  return communityFilterMeta.find((item) => item.value === communityFilter.value)?.label || '社区消息'
})

onLoad((options?: Record<string, string | undefined>) => {
  const category = options?.category
  if (category && categories.some((item) => item.value === category)) activeCategory.value = category as NotificationCategory
})
onShow(() => { void initialize() })
onReachBottom(() => { if (hasMore.value && !loadingMore.value) void loadMore() })

async function initialize() {
  if (!auth.isAuthenticated) { navigateToLogin(); return }
  await Promise.all([loadNotifications(true), loadPreference(), loadWechatSubscriptions()])
}

async function loadPreference() {
  if (!auth.accessToken || preference.value) return
  try { preference.value = await getNotificationPreference(auth.accessToken) }
  catch (error) { toast(error, '通知偏好加载失败') }
}

async function loadWechatSubscriptions() {
  // #ifndef MP-WEIXIN
  return
  // #endif
  if (!auth.accessToken || loadingWechatSubscriptions.value) return
  loadingWechatSubscriptions.value = true
  try {
    const pendingStatus = await syncPendingWechatSubscriptionResults()
    if (pendingStatus === 'synced') return
    if (pendingStatus === 'discarded') uni.showToast({ title: '订阅模板已更新，请重新授权', icon: 'none' })
    wechatSubscriptions.value = await getWechatSubscriptionOptions(auth.accessToken)
  } catch (error) {
    wechatSubscriptions.value = null
    toast(error, '订阅配置加载失败')
  } finally {
    loadingWechatSubscriptions.value = false
  }
}

async function subscribeWechat(group: WechatSubscriptionGroup) {
  if (!auth.accessToken || subscribingContext.value || !group.template_ids.length) return
  subscribingContext.value = group.context
  try {
    // #ifdef MP-WEIXIN
    const pendingStatus = await syncPendingWechatSubscriptionResults()
    if (pendingStatus === 'pending') {
      uni.showToast({ title: '上次微信授权结果仍待同步', icon: 'none' })
      return
    }
    if (pendingStatus === 'discarded') {
      wechatSubscriptions.value = await getWechatSubscriptionOptions(auth.accessToken)
      uni.showToast({ title: '订阅模板已更新，请重新点击授权', icon: 'none' })
    }

    const currentGroup = wechatSubscriptions.value?.groups.find((item) => item.context === group.context) ?? group
    const templateIds = selectableWechatTemplateIds(currentGroup)
    if (!templateIds.length) {
      uni.showToast({ title: '当前提醒已有可用授权', icon: 'none' })
      return
    }

    let result: Awaited<ReturnType<typeof uni.requestSubscribeMessage>>
    try {
      result = await uni.requestSubscribeMessage({ tmplIds: templateIds })
    } catch (error) {
      toast(error, '微信订阅授权未完成')
      return
    }

    const statuses: WechatSubscriptionResult[] = templateIds.map((templateId) => ({ template_id: templateId, status: String((result as unknown as Record<string, unknown>)[templateId] || 'reject') }))
    const requestId = `wechat-subscription-${Date.now()}-${Math.random().toString(36).slice(2, 12)}`
    uni.setStorageSync(pendingWechatSubscriptionStorageKey(), { request_id: requestId, results: statuses } satisfies PendingWechatSubscriptionResults)
    const syncStatus = await syncPendingWechatSubscriptionResults()
    if (syncStatus === 'pending') {
      uni.showToast({ title: '微信授权已完成，结果稍后自动同步', icon: 'none' })
      return
    }
    if (syncStatus === 'discarded') {
      wechatSubscriptions.value = await getWechatSubscriptionOptions(auth.accessToken)
      uni.showToast({ title: '订阅模板已更新，请重新授权', icon: 'none' })
      return
    }
    const accepted = statuses.filter((item) => item.status === 'accept' || item.status === 'acceptWithAudio').length
    uni.showToast({ title: accepted > 0 ? '订阅授权成功' : '未开启订阅提醒', icon: accepted > 0 ? 'success' : 'none' })
    // #endif
    // #ifndef MP-WEIXIN
    uni.showToast({ title: '请在微信小程序中完成订阅授权', icon: 'none' })
    // #endif
  } catch (error) { toast(error, '订阅操作失败') }
  finally { subscribingContext.value = '' }
}

function availableGrantCount(group: WechatSubscriptionGroup) { return group.grants.reduce((sum, grant) => sum + grant.available_count, 0) }

function pendingWechatSubscriptionStorageKey() { return `task_mall_wechat_subscription_results:${auth.user?.id ?? 'anonymous'}` }
function wechatSubscriptionCursorStorageKey(context: NotificationSubscriptionContext) { return `task_mall_wechat_subscription_cursor:${auth.user?.id ?? 'anonymous'}:${context}` }

function pendingWechatSubscriptionResults(): PendingWechatSubscriptionResults | null {
  const stored = uni.getStorageSync(pendingWechatSubscriptionStorageKey()) as unknown
  if (!stored || typeof stored !== 'object' || Array.isArray(stored)) return null
  const candidate = stored as Record<string, unknown>
  if (typeof candidate.request_id !== 'string' || !candidate.request_id.trim() || !Array.isArray(candidate.results)) return null
  const results = candidate.results.filter((item): item is WechatSubscriptionResult => {
    if (!item || typeof item !== 'object' || Array.isArray(item)) return false
    const result = item as Record<string, unknown>
    return typeof result.template_id === 'string' && Boolean(result.template_id.trim()) && typeof result.status === 'string' && Boolean(result.status.trim())
  })
  return results.length === candidate.results.length && results.length ? { request_id: candidate.request_id, results } : null
}

async function syncPendingWechatSubscriptionResults(): Promise<'none' | 'synced' | 'discarded' | 'pending'> {
  const pending = pendingWechatSubscriptionResults()
  if (!pending || !auth.accessToken) return 'none'
  try {
    wechatSubscriptions.value = await recordWechatSubscriptionResults(auth.accessToken, pending.results, pending.request_id)
    uni.removeStorageSync(pendingWechatSubscriptionStorageKey())
    return 'synced'
  } catch (error) {
    if (!isTerminalWechatSubscriptionSyncFailure(error)) return 'pending'
    uni.removeStorageSync(pendingWechatSubscriptionStorageKey())
    return 'discarded'
  }
}

function isTerminalWechatSubscriptionSyncFailure(error: unknown) {
  return error instanceof ApiError
    && error.statusCode >= 400
    && error.statusCode < 500
    && ![401, 408, 425, 429].includes(error.statusCode)
}

function selectableWechatTemplateIds(group: WechatSubscriptionGroup) {
  const grants = new Map(group.grants.map((grant) => [grant.template_id, grant]))
  const templateIds = [...new Set(group.template_ids)]
  const neverRequested = templateIds.filter((templateId) => {
    const grant = grants.get(templateId)
    return !grant || grant.status === 'not_requested'
  })
  const exhausted = templateIds.filter((templateId) => {
    const grant = grants.get(templateId)
    return grant && grant.status !== 'not_requested' && grant.status !== 'ban' && grant.available_count <= 0
  })
  const selected = neverRequested.slice(0, 3)
  if (selected.length === 3 || !exhausted.length) return selected

  const storedCursor = Number(uni.getStorageSync(wechatSubscriptionCursorStorageKey(group.context)))
  const cursor = (subscriptionCursors.value[group.context] ?? (Number.isInteger(storedCursor) && storedCursor >= 0 ? storedCursor : 0)) % exhausted.length
  const rotated = [...exhausted.slice(cursor), ...exhausted.slice(0, cursor)]
  const exhaustedSelection = rotated.slice(0, 3 - selected.length)
  subscriptionCursors.value[group.context] = (cursor + exhaustedSelection.length) % exhausted.length
  uni.setStorageSync(wechatSubscriptionCursorStorageKey(group.context), subscriptionCursors.value[group.context])
  return [...selected, ...exhaustedSelection]
}

async function loadNotifications(reset: boolean) {
  if (!auth.accessToken) return
  const version = reset ? ++requestVersion : requestVersion
  errorMessage.value = ''
  if (reset) {
    loading.value = true
    page.value = 1
  } else {
    loadingMore.value = true
  }
  try {
    const response = await listNotifications(auth.accessToken, {
      page: page.value,
      page_size: pageSize,
      ...(activeCategory.value ? { category: activeCategory.value } : {}),
      unread_only: unreadOnly.value,
    })
    if (version !== requestVersion) return
    items.value = reset ? response.items : uniqueNotifications([...items.value, ...response.items])
    total.value = response.pagination.total
    hasMore.value = response.pagination.has_more
    unreadTotal.value = response.unread_total
    categoryUnreadCounts.value = response.category_unread_counts
  } catch (error) {
    if (version !== requestVersion) return
    errorMessage.value = error instanceof Error ? error.message : '请检查网络后重试'
    if (!reset) toast(error, '加载更多失败')
  } finally {
    if (version === requestVersion) {
      loading.value = false
      loadingMore.value = false
    }
  }
}

function reload() { void loadNotifications(true) }

async function loadMore() {
  if (!hasMore.value || loadingMore.value) return
  page.value += 1
  await loadNotifications(false)
  if (errorMessage.value) page.value = Math.max(1, page.value - 1)
}

function selectCategory(value: CategoryFilter) {
  if (activeCategory.value === value || loading.value) return
  activeCategory.value = value
  communityFilter.value = 'all'
  void loadNotifications(true)
}

function toggleUnreadOnly() {
  if (loading.value) return
  unreadOnly.value = !unreadOnly.value
  void loadNotifications(true)
}

async function readAll() {
  if (!auth.accessToken || unreadTotal.value === 0) return
  const confirmed = await uni.showModal({ title: '全部标记为已读', content: `将 ${unreadTotal.value} 条未读消息全部标记为已读，是否继续？`, confirmText: '全部已读' })
  if (!confirmed.confirm) return
  markingAll.value = true
  try {
    const response = await markAllNotificationsRead(auth.accessToken)
    unreadTotal.value = response.unread_total
    categoryUnreadCounts.value = {}
    if (unreadOnly.value) {
      items.value = []
      total.value = 0
      hasMore.value = false
    } else {
      const readAt = new Date().toISOString()
      items.value.forEach((item) => { item.read_at ||= readAt })
    }
    uni.showToast({ title: '已全部标记为已读', icon: 'success' })
  } catch (error) { toast(error, '操作失败') }
  finally { markingAll.value = false }
}

async function openNotification(item: AppNotification) {
  if (!auth.accessToken) return
  if (!item.read_at) {
    try {
      const updated = await markNotificationRead(auth.accessToken, item.id)
      item.read_at = updated.read_at || new Date().toISOString()
      unreadTotal.value = Math.max(0, unreadTotal.value - 1)
      categoryUnreadCounts.value[item.category] = Math.max(0, unreadFor(item.category) - 1)
      if (unreadOnly.value) {
        items.value = items.value.filter((entry) => entry.id !== item.id)
        total.value = Math.max(0, total.value - 1)
      }
    } catch (error) { toast(error, '消息状态更新失败'); return }
  }
  const target = notificationTarget(item)
  if (target) uni.navigateTo({ url: target })
}

async function changePreference(key: TogglePreferenceKey, event: Event) {
  if (!auth.accessToken || !preference.value || savingPreference.value) return
  const previous = preference.value[key]
  preference.value[key] = Boolean((event as CustomEvent<{ value: boolean }>).detail.value)
  await persistPreference(() => { if (preference.value) preference.value[key] = previous })
}

async function changeQuietHour(key: QuietHourKey, event: Event) {
  if (!preference.value || savingPreference.value) return
  const value = String((event as CustomEvent<{ value: string }>).detail.value || '')
  if (!/^\d{2}:\d{2}$/.test(value)) return
  const previous = preference.value[key]
  preference.value[key] = value
  await persistPreference(() => { if (preference.value) preference.value[key] = previous })
}

async function persistPreference(rollback: () => void) {
  if (!auth.accessToken || !preference.value) return
  savingPreference.value = true
  try {
    preference.value = await updateNotificationPreference(auth.accessToken, preference.value)
    uni.showToast({ title: '通知设置已保存', icon: 'success' })
  } catch (error) { rollback(); toast(error, '保存失败') }
  finally { savingPreference.value = false }
}

function unreadFor(category: CategoryFilter) { return category ? Number(categoryUnreadCounts.value[category] || 0) : unreadTotal.value }
function categoryText(value: string) { return ({ task: '任务', asset: '资产', order: '订单', after_sale: '售后', community: '社区', system: '系统' } as Record<string, string>)[value] || '系统' }
function categoryMark(value: string) { return ({ task: '任', asset: '资', order: '单', after_sale: '售', community: '聊', system: '系' } as Record<string, string>)[value] || '信' }
function uniqueNotifications(values: AppNotification[]) { return Array.from(new Map(values.map((item) => [item.id, item])).values()) }

function communitySceneGroup(sceneCode: string): Exclude<CommunityFilter, 'all'> {
  if (sceneCode === 'community_post_liked') return 'likes'
  if (sceneCode === 'community_post_commented' || sceneCode === 'community_comment_replied') return 'comments'
  return 'reviews'
}

function notificationTarget(item: AppNotification): string {
  const data = item.data || {}
  if (item.category === 'task') {
    const claimId = firstString(data, ['task_claim_id', 'claim_id'])
    if (claimId) return `/pages/task-claim/index?id=${encodeURIComponent(claimId)}`
    const taskId = firstString(data, ['task_id', 'task_public_id'])
    if (taskId) return `/pages/task-detail/index?id=${encodeURIComponent(taskId)}`
    return '/pages/tasks/index'
  }
  if (item.category === 'order') {
    const orderId = firstString(data, ['order_id', 'order_public_id'])
    return orderId ? `/pages/order-detail/index?id=${encodeURIComponent(orderId)}` : '/pages/orders/index'
  }
  if (item.category === 'after_sale') {
    const refundId = firstString(data, ['refund_id', 'after_sale_id', 'refund_public_id'])
    return refundId ? `/pages/after-sale-detail/index?id=${encodeURIComponent(refundId)}` : '/pages/after-sales/index'
  }
  if (item.category === 'asset') return '/pages/assets/index'
  if (item.category === 'community') {
    if (['post_review_pending', 'post_review_rejected', 'post_hidden', 'post_deleted', 'post_appeal_submitted', 'post_appeal_rejected'].includes(item.scene_code)) {
      return '/pages/community/index?view=mine'
    }
    const postId = firstString(data, ['post_id', 'post_public_id'])
    if (postId) return `/pages/community-detail/index?id=${encodeURIComponent(postId)}`
    const activityId = firstString(data, ['activity_id', 'activity_public_id'])
    if (activityId) return `/pages/community-activity-detail/index?id=${encodeURIComponent(activityId)}`
    return '/pages/community/index'
  }
  return ''
}

function firstString(data: Record<string, unknown>, keys: string[]) {
  for (const key of keys) {
    const value = data[key]
    if (typeof value === 'string' && value.trim()) return value.trim()
  }
  return ''
}

function toast(error: unknown, fallback: string) { uni.showToast({ title: error instanceof Error ? error.message : fallback, icon: 'none' }) }
</script>

<style scoped>
.page{min-height:100vh;padding:32rpx 28rpx 90rpx;background:var(--tm-canvas);box-sizing:border-box}.heading{padding:22rpx 8rpx 16rpx}.eyebrow,.title,.sub,.summary-title,.summary-copy,.settings-head text,.settings-note,.body,.state-title,.state-copy,.list-end{display:block}.eyebrow{color:var(--tm-brand-600);font-size:13px;font-weight:800;letter-spacing:2.5rpx}.title{margin-top:10rpx;font-size:24px;font-weight:850}.sub{margin-top:10rpx;color:var(--tm-ink-500);font-size:14px;line-height:1.6}.summary-card{margin-top:18rpx;padding:28rpx;border:1rpx solid #d6e9e7;border-radius:26rpx;background:#f2f9f8;box-shadow:var(--tm-shadow-sm)}.summary-main{display:flex;align-items:center;gap:18rpx}.summary-count{display:flex;align-items:center;justify-content:center;width:76rpx;height:76rpx;flex:none;border-radius:22rpx;background:var(--tm-brand-600);color:#fff;font-size:21px;font-weight:900}.summary-title{font-size:17px;font-weight:850}.summary-copy{margin-top:4rpx;color:var(--tm-ink-500);font-size:13px}.summary-actions{display:grid;grid-template-columns:1fr 1fr;gap:14rpx;margin-top:22rpx}.summary-button{height:68rpx;margin:0;border:1rpx solid var(--tm-line);border-radius:16rpx;background:#fff;color:var(--tm-ink-700);font-size:14px;line-height:66rpx}.summary-button::after,.load-more::after,.retry-button::after{border:0}.summary-button.primary{border-color:var(--tm-brand-600);background:var(--tm-brand-600);color:#fff}.summary-button[disabled]{opacity:.45}.settings-card{margin-top:18rpx;padding:26rpx;border:1rpx solid var(--tm-line);border-radius:24rpx;background:#fff}.settings-head{display:flex;align-items:center;gap:16rpx;margin-bottom:10rpx}.settings-icon,.category-mark,.state-icon{display:flex;align-items:center;justify-content:center;flex:none;font-weight:850}.settings-icon{width:58rpx;height:58rpx;border-radius:18rpx;background:var(--tm-brand-100);color:var(--tm-brand-700)}.settings-head text:first-child{font-size:15px;font-weight:850}.settings-head text:last-child{margin-top:4rpx;color:var(--tm-ink-500);font-size:13px}.setting-row,.quiet-row{display:flex;align-items:center;justify-content:space-between;gap:16rpx;padding:16rpx 0;border-top:1rpx solid var(--tm-line);font-size:14px}.quiet-row{align-items:flex-start}.quiet-pickers{display:flex;align-items:center;gap:10rpx}.time-value{padding:10rpx 15rpx;border:1rpx solid var(--tm-line);border-radius:12rpx;background:#f8fafa;color:var(--tm-brand-700);font-size:13px}.settings-note{padding-top:13rpx;color:var(--tm-ink-500);font-size:13px;line-height:1.5}.category-scroll{width:100%;margin-top:26rpx;white-space:nowrap}.category-list{display:inline-flex;gap:12rpx;padding:2rpx}.category-chip{display:flex;align-items:center;gap:8rpx;padding:14rpx 21rpx;border:1rpx solid var(--tm-line);border-radius:999rpx;background:#fff;color:var(--tm-ink-500);font-size:14px}.category-chip.active{border-color:var(--tm-brand-600);background:var(--tm-brand-600);color:#fff}.chip-count{display:flex;align-items:center;justify-content:center;min-width:28rpx;height:28rpx;padding:0 6rpx;border-radius:999rpx;background:#e9efef;color:var(--tm-ink-700);font-size:12px}.category-chip.active .chip-count{background:rgba(255,255,255,.2);color:#fff}.list-toolbar{display:flex;align-items:center;justify-content:space-between;margin:30rpx 4rpx 2rpx;font-size:17px;font-weight:850}.unread-filter{display:flex;align-items:center;gap:8rpx;color:var(--tm-ink-500);font-size:13px;font-weight:500}.filter-dot{width:24rpx;height:24rpx;border:2rpx solid #aebcbc;border-radius:50%;box-sizing:border-box}.unread-filter.active{color:var(--tm-brand-700)}.unread-filter.active .filter-dot{border:7rpx solid var(--tm-brand-600)}.notice-card{position:relative;display:flex;gap:18rpx;margin-top:18rpx;padding:26rpx;border:1rpx solid var(--tm-line);border-radius:24rpx;background:#fff;box-shadow:var(--tm-shadow-sm)}.notice-card.unread{border-left:6rpx solid var(--tm-brand-600)}.notice-card.unread::after{content:'';position:absolute;right:17rpx;top:17rpx;width:11rpx;height:11rpx;border-radius:50%;background:var(--tm-brand-600)}.category-mark{width:60rpx;height:60rpx;border-radius:18rpx;background:var(--tm-brand-100);color:var(--tm-brand-700)}.tone-asset{background:#fff1e5;color:#c85b16}.tone-order{background:#edf4f4;color:#214b52}.tone-after_sale{background:#fff0f1;color:#c84455}.tone-community{background:#e8f8f3;color:#16866c}.tone-system{background:#eef1f2;color:#53656b}.notice-main{min-width:0;flex:1}.notice-head{display:flex;align-items:center;justify-content:space-between;gap:14rpx;padding-right:12rpx}.notice-title{overflow:hidden;color:var(--tm-ink-900);font-size:15px;font-weight:850;text-overflow:ellipsis;white-space:nowrap}.category-tag{padding:4rpx 10rpx;flex:none;border-radius:9rpx;background:#f2f4f4;color:var(--tm-ink-500);font-size:12px}.body{margin:12rpx 0;color:var(--tm-ink-700);font-size:14px;line-height:1.6}.notice-foot{display:flex;align-items:center;justify-content:space-between;gap:14rpx;color:var(--tm-ink-500);font-size:13px}.detail-link{color:var(--tm-brand-700);font-weight:750}.state-card{margin-top:20rpx;padding:64rpx 30rpx;text-align:center;border:1rpx solid var(--tm-line);border-radius:26rpx;background:#fff}.state-icon{width:82rpx;height:82rpx;margin:0 auto;border-radius:25rpx;background:var(--tm-brand-100);color:var(--tm-brand-700);font-size:17px}.state-title{margin-top:18rpx;font-size:17px;font-weight:850}.state-copy{margin-top:8rpx;color:var(--tm-ink-500);font-size:13px;line-height:1.55}.error-card .state-icon{background:#fff0f1;color:#c84455}.retry-button{width:230rpx;height:66rpx;margin-top:22rpx;border-radius:16rpx;background:var(--tm-brand-600);color:#fff;font-size:14px;line-height:66rpx}.skeleton-card{height:190rpx;margin-top:18rpx;border-radius:24rpx}.load-more{width:260rpx;height:68rpx;margin:28rpx auto 0;border:1rpx solid var(--tm-line);border-radius:16rpx;background:#fff;color:var(--tm-brand-700);font-size:14px;line-height:66rpx}.list-end{padding:30rpx 0 4rpx;text-align:center;color:var(--tm-ink-500);font-size:13px}
/* V09 消息中心：公告板色块保持轻盈，消息卡片使用细边框与柔和阴影。 */
.page{background:radial-gradient(circle at 92% 2%,rgba(63,203,211,.16),transparent 27%),linear-gradient(180deg,#eefbfb 0,var(--tm-canvas) 430rpx)}
.heading{padding:28rpx 26rpx;border:1rpx solid #cfe8e7;border-radius:28rpx;background:linear-gradient(135deg,#e3f7f7,#f2fae9);box-shadow:0 12rpx 30rpx rgba(34,107,113,.08)}
.eyebrow{color:#177f84;font-weight:850}.title{color:#183b4c;font-weight:900}.sub{color:#60767c;font-weight:550}
.summary-card{border:1rpx solid #d4e8e8;background:#f5fbfa;box-shadow:0 12rpx 28rpx rgba(25,72,89,.07)}.summary-count{background:#2a9e98;box-shadow:0 8rpx 16rpx rgba(42,158,152,.18)}.summary-button{border:1rpx solid #d4e3e5;background:#fff;color:#27515b}.summary-button.primary{border-color:#2a9e98;background:#2a9e98;box-shadow:0 7rpx 14rpx rgba(42,158,152,.18);color:#fff}
.settings-card,.notice-card,.state-card{border:1rpx solid #d7e5e8;box-shadow:0 12rpx 28rpx rgba(25,72,89,.07)}.settings-icon{background:#e9e4ff;color:#694cc8}.time-value{border:1rpx solid #bed2d7;background:#fff;color:#176f82;font-weight:750}.category-chip{border:1rpx solid #d5e3e5;background:#fff;color:#547079;box-shadow:none}.category-chip.active{border-color:#2a9e98;background:#2a9e98;color:#fff}.chip-count{background:#e7f4f1;color:#26756f;font-weight:750}
.community-shortcuts{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12rpx;margin-top:22rpx}.community-shortcut{min-width:0;padding:18rpx 14rpx;border:1rpx solid #d4e6e7;border-radius:22rpx;background:#fff;box-shadow:0 10rpx 22rpx rgba(25,72,89,.06);box-sizing:border-box;transition:transform .16s ease}.community-shortcut.active{transform:translateY(-3rpx);box-shadow:0 14rpx 26rpx rgba(25,72,89,.10)}.shortcut-comments{background:#eefbf2}.shortcut-likes{background:#fff4ef}.shortcut-reviews{background:#fff9e4}.shortcut-top{display:flex;align-items:center;justify-content:space-between;gap:8rpx}.shortcut-icon{display:flex;align-items:center;justify-content:center;width:46rpx;height:46rpx;border-radius:14rpx;background:#fff;color:#26756f;font-size:13px;font-weight:900}.shortcut-count{color:#1d4e5a;font-size:19px;font-weight:900}.shortcut-label,.shortcut-hint{display:block}.shortcut-label{margin-top:13rpx;color:#264a54;font-size:14px;font-weight:850}.shortcut-hint{margin-top:5rpx;overflow:hidden;color:#71838a;font-size:12px;font-weight:600;text-overflow:ellipsis;white-space:nowrap}
.notice-card{background:#fff}.notice-card.unread{border-left:6rpx solid #ef9587}.notice-card.unread::after{background:#ef9587}.category-mark{border:0;background:#e5f6f3;color:#167d76}.tone-asset{background:#fff3df;color:#b46600}.tone-order{background:#eeeaff;color:#694cc8}.tone-after_sale{background:#fff0ef;color:#ce4d55}.tone-community{background:#e9f8ec;color:#33823e}.notice-title{color:#1d4150;font-weight:850}.category-tag{background:#f2f6f5;color:#61747b;font-weight:700}.detail-link{color:#177f84;font-weight:850}.retry-button{border:0;background:#2a9e98;box-shadow:0 8rpx 16rpx rgba(42,158,152,.18);color:#fff;font-weight:850}
.settings-card{padding:22rpx;border-radius:27rpx}.settings-head{margin-bottom:16rpx}.setting-row,.quiet-row{min-height:74rpx;margin-top:10rpx;padding:12rpx 15rpx;border:0;border-radius:17rpx;background:#f5f9fa;box-sizing:border-box}.setting-copy{min-width:0;flex:1}.setting-copy>text{display:block}.setting-copy>text:first-child{color:#183246;font-size:14px;font-weight:850}.setting-copy>text:last-child{margin-top:4rpx;color:#7a8992;font-size:10px;line-height:1.35}.setting-row switch{flex:none;transform:scale(.82);transform-origin:right center}.quiet-row{align-items:center}.quiet-pickers{flex:none;gap:7rpx}.settings-note{padding:14rpx 4rpx 2rpx;font-size:12px}
.subscription-section{margin-top:10rpx;padding:16rpx 15rpx;border-radius:17rpx;background:#f2f9f8}.subscription-heading>text{display:block}.subscription-heading>text:first-child{color:#183246;font-size:14px;font-weight:850}.subscription-heading>text:last-child{margin-top:4rpx;color:#71838a;font-size:10px}.subscription-actions{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10rpx;margin-top:14rpx}.subscription-button{min-width:0;height:92rpx;margin:0;padding:12rpx 8rpx;border:1rpx solid #cfe3e2;border-radius:14rpx;background:#fff;color:#1f5f61;font-size:12px;line-height:1.3}.subscription-button::after{border:0}.subscription-button>text{display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.subscription-button>text:first-child{font-weight:850}.subscription-button>text:last-child{margin-top:5rpx;color:#6f8588;font-size:10px}
@media screen and (max-width:360px){.quiet-row{align-items:flex-start;flex-direction:column}.quiet-pickers{align-self:stretch;justify-content:flex-end}}
</style>
