<template>
  <view class="page">
    <view class="task-topbar">
      <view class="task-topbar-title"><text class="task-topbar-kicker">CAMPUS TASKS</text><text class="task-topbar-name">任务大厅</text></view>
      <view v-if="tab === 'hall'" class="task-topbar-actions"><view class="task-topbar-action" @click="openMine"><text class="task-topbar-action-icon">▣</text><text>任务记录</text></view><view class="task-topbar-action" @click="showFilterHint"><text class="task-topbar-action-icon">⌕</text><text>筛选</text></view></view>
      <view v-else class="task-topbar-actions"><view class="task-topbar-action" @click="openNotifications"><text class="task-topbar-action-icon">♧</text><text>消息</text></view></view>
    </view>
    <view v-if="tab === 'mine'" :class="['heading', { 'member-heading': auth.isAuthenticated }]">
      <text class="eyebrow">MY TASKS</text><text class="title">我的任务</text><text class="subtitle">完成任务赚取奖励，提升校园影响力</text>
      <image class="heading-art" src="/static/home/hero-campus-reward.png" mode="aspectFill" />
    </view>
    <template v-if="tab === 'hall'">
      <view class="task-search">
        <text class="task-search-icon">⌕</text>
        <input v-model.trim="searchKeyword" class="task-search-input" placeholder="搜索任务关键词" confirm-type="search" @confirm="loadHall" />
        <text class="task-search-filter" @click="showFilterHint">筛选</text>
      </view>
      <text v-if="!auth.isAuthenticated" class="guest-task-note">登录后可领取任务，并在“任务记录”中查看执行进度</text>
      <view class="filters"><view v-for="item in categories" :key="item.value" :class="['chip', { active: category === item.value }]" @click="category = item.value; loadHall()">{{ item.label }}</view></view>
    </template>
    <view class="task-dashboard tm-card-strong">
      <view class="dashboard-copy"><text class="tm-section-kicker">MY CAMPUS PLAN</text><text class="dashboard-title">本周成长进度</text><text class="dashboard-note">从小任务开始，完成记录会沉淀到你的校园成长档案</text></view>
      <view class="dashboard-ring"><strong>{{ taskProgress }}</strong><text>%</text></view>
      <view class="dashboard-grid"><view><strong>{{ tasks.length }}</strong><text>可领取</text></view><view><strong>{{ totalSlots }}</strong><text>剩余名额</text></view><view><strong>{{ completedPeople }}</strong><text>同学完成</text></view></view>
    </view>
    <view class="tabs"><view :class="['tab', { active: tab === 'hall' }]" @click="tab = 'hall'; loadHall()">可领取</view><view :class="['tab', { active: tab === 'mine' }]" @click="openMine">我的任务</view></view>
    <view v-if="tab === 'hall'" class="hall-sort-row">
      <text class="hall-sort-label">任务列表</text>
      <view v-for="item in sortOptions" :key="item.value" :class="['hall-sort-chip', { active: sortMode === item.value }]" @click="selectSort(item.value)">{{ item.label.replace('排序', '') }}</view>
      <view class="hall-sort-chip filter-chip" @click="showFilterHint">筛选⌄</view>
    </view>
    <view v-if="filterVisible" class="filter-mask" @click.self="filterVisible = false">
      <view class="filter-sheet"><view class="filter-sheet-head"><text class="filter-sheet-title">任务排序与筛选</text><text class="filter-sheet-close" @click="filterVisible = false">×</text></view><view class="filter-options"><view v-for="item in sortOptions" :key="item.value" :class="['filter-option', { active: sortMode === item.value }]" @click="selectSort(item.value)"><text>{{ item.label }}</text><text v-if="sortMode === item.value">✓</text></view></view></view>
    </view>
    <view v-if="tab === 'hall' && withdrawalNotices.length" class="withdrawal-ticker">
      <view class="ticker-speaker"><text>⌁</text></view>
      <swiper class="ticker-swiper" vertical autoplay circular :interval="3500" :duration="450">
        <swiper-item v-for="notice in withdrawalNotices" :key="notice.id">
          <view class="ticker-line"><text class="ticker-user">{{ notice.user_masked }}</text><text> 已提现，提现金额：</text><text class="ticker-amount">¥{{ formatMoney(notice.withdrawal_amount) }}</text></view>
        </swiper-item>
      </swiper>
    </view>
    <view v-if="tab === 'hall'">
      <view v-if="loadError" class="task-error"><text class="task-error-icon">!</text><view><text class="task-error-title">任务暂时加载失败</text><text class="task-error-copy">{{ loadError }}</text></view><button class="task-error-action" @click="loadHall">重试</button></view>
      <view v-if="loading" class="skeleton-list">
        <view v-for="index in 3" :key="index" class="card skeleton-card">
          <view class="tm-skeleton skeleton-title" />
          <view class="tm-skeleton skeleton-meta" />
          <view class="tm-skeleton skeleton-copy" />
          <view class="tm-skeleton skeleton-action" />
        </view>
      </view>
      <view v-for="task in visibleTasks" :key="task.id" class="card task-card" @click="openTaskDetail(task.id)">
        <view class="task-card-top">
          <view class="task-card-icon"><text>{{ categoryMark(task.category) }}</text></view>
          <view class="task-heading-main"><text class="task-title">{{ task.title }}</text><text class="meta">{{ categoryLabel(task.category) }} · {{ taskTypeLabel(task.task_type) }}</text></view>
          <view class="primary-reward"><text>{{ primaryRewardText(task) }}</text><text :class="['task-availability', { disabled: !task.can_claim }]">{{ task.can_claim ? '可领取' : '暂不可领' }}</text></view>
        </view>
        <view class="task-metric-strip">
          <view><strong>{{ task.remaining_reward_slots }}</strong><text>剩余名额</text></view>
          <view><strong>{{ task.completed_user_count }}</strong><text>同学完成</text></view>
          <view><strong>{{ reviewDurationText(task) }}</strong><text>平均审核</text></view>
        </view>
        <text class="task-summary">{{ task.description_text || plainTextFromRich(task.description) }}</text>
        <view class="task-card-bottom">
          <view class="task-tags"><text class="task-tag">{{ categoryLabel(task.category) }}</text><text v-for="tag in displayTags(task)" :key="tag" class="task-tag">{{ tag }}</text></view>
          <view class="traffic-metrics"><text class="traffic-item">{{ completionDurationText(task) }}</text><text class="traffic-item">◉ {{ task.view_count }}</text></view>
        </view>
        <view v-if="task.rewards.length > 1" class="rewards"><text v-for="(reward, index) in task.rewards" :key="index" class="reward">{{ rewardText(reward) }}</text></view>
        <view class="deadline">领取截止 {{ formatTime(task.claim_ends_at) }} · 领取后 {{ task.completion_window_hours }} 小时内完成</view>
        <view class="detail-entry"><text>{{ task.can_claim ? '查看详情后领取' : reasonText(task.claim_unavailable_reason) }}</text><text class="detail-arrow">›</text></view>
      </view>
      <view v-if="!loading && visibleTasks.length === 0" class="task-empty">
        <view class="task-empty-mark">✦</view>
        <text class="task-empty-title">暂时没有符合条件的任务</text>
        <text class="task-empty-copy">换个分类看看，或刷新后获取最新任务。</text>
        <button class="task-empty-action" @click="category = ''; loadHall()">查看全部任务</button>
      </view>
    </view>
    <view v-else>
      <scroll-view class="mine-status-tabs" scroll-x :show-scrollbar="false"><view class="mine-status-inner"><view v-for="item in claimGroups" :key="item.value" :class="['mine-status-tab', { active: claimGroup === item.value }]" @click="claimGroup = item.value; loadMine()">{{ item.label }}</view></view></scroll-view>
      <view v-if="claimGroup === 'reviewing' && myClaims.length" class="mine-review-notice"><text class="mine-review-dot">●</text><text>正在审核 {{ myClaims.length }} 项任务，审核通过后奖励会自动发放</text></view>
      <view v-if="loadError" class="task-error"><text class="task-error-icon">!</text><view><text class="task-error-title">我的任务加载失败</text><text class="task-error-copy">{{ loadError }}</text></view><button class="task-error-action" @click="loadMine">重试</button></view>
      <view v-if="loading" class="skeleton-list">
        <view v-for="index in 3" :key="index" class="card skeleton-card">
          <view class="tm-skeleton skeleton-title" />
          <view class="tm-skeleton skeleton-meta" />
          <view class="tm-skeleton skeleton-copy" />
        </view>
      </view>
      <view v-for="item in myClaims" :key="item.id" class="mine-card" @click="openClaimDetail(item.id)">
        <view class="mine-task-icon"><text>{{ categoryMark(item.category) }}</text></view>
        <view class="mine-card-main">
          <view class="mine-title-row"><text class="mine-title">{{ item.title }}</text><text class="mine-reward">{{ claimRewardText(item) }}</text></view>
          <view class="mine-tag-row"><text class="mine-tag">{{ categoryLabel(item.category) }}</text><text v-for="tag in visibleClaimTags(item)" :key="tag" class="mine-tag">{{ tag }}</text><text :class="['status-pill', `status-${item.status}`]">{{ item.execution_status_label || claimStatusText(item.status) }}</text><text v-if="item.review_status_label" class="review-status-pill">{{ item.review_status_label }}</text></view>
          <text class="claimed-time">领取时间：{{ formatTime(item.created_at) }}</text>
          <text v-if="item.last_review?.decision === 'reject'" class="reject-note">未通过原因：{{ item.last_review.note || '材料不符合任务要求，请修改后重新提交。' }}</text>
          <view class="mine-progress-row"><text :class="['deadline-countdown', { urgent: isUrgent(item.completion_deadline_at) }]">{{ deadlineText(item) }}</text><text class="submission-count">提交 {{ item.submission_count }}/{{ item.submission_limit }} 次</text></view>
          <view class="mine-progress-track"><view :style="{ width: claimProgressWidth(item) }" /></view>
          <view class="mine-actions"><button class="outline-action" @click.stop="openClaimDetail(item.id)">{{ primaryActionText(item) }}</button><button v-if="canCancel(item)" class="outline-action muted" @click.stop="cancel(item)">放弃任务</button></view>
        </view>
      </view>
      <view v-if="!loading && myClaims.length === 0" class="task-empty task-empty-mine">
        <view class="task-empty-mark">▣</view>
        <text class="task-empty-title">当前分类下还没有任务</text>
        <text class="task-empty-copy">领取任务后，进度和审核状态会显示在这里。</text>
        <button class="task-empty-action" @click="claimGroup = ''; loadMine()">查看全部状态</button>
      </view>
    </view>
    <AppTabBar current-target="tasks" />
  </view>
</template>

<script setup lang="ts">
import { onPullDownRefresh, onShow } from '@dcloudio/uni-app'
import { computed, onMounted, onUnmounted, ref } from 'vue'
import AppTabBar from '@/components/AppTabBar.vue'
import { cancelTaskClaim, listHallTasks, listMyTaskClaims, type HallTask, type TaskClaim, type TaskReward, type WithdrawalNotice } from '@/api/tasks'
import { useAuthStore } from '@/stores/auth'
import { navigateToLogin } from '@/utils/authRedirect'
import { plainTextFromRich } from '@/utils/richText'

const auth = useAuthStore()
const tab = ref<'hall' | 'mine'>('hall')
const category = ref('')
const tasks = ref<HallTask[]>([])
const withdrawalNotices = ref<WithdrawalNotice[]>([])
const myClaims = ref<TaskClaim[]>([])
const claimGroup = ref('')
const searchKeyword = ref('')
const filterVisible = ref(false)
const sortMode = ref<'default' | 'latest' | 'reward' | 'easy'>('default')
const loading = ref(false)
const loadError = ref('')
const totalSlots = computed(() => tasks.value.reduce((sum, task) => sum + task.remaining_reward_slots, 0))
const completedPeople = computed(() => tasks.value.reduce((sum, task) => sum + task.completed_user_count, 0))
const taskProgress = computed(() => Math.min(100, Math.max(18, myClaims.value.length * 18 + 36)))
const sortOptions = [
  { value: 'default' as const, label: '综合排序' },
  { value: 'latest' as const, label: '最新发布' },
  { value: 'reward' as const, label: '奖励优先' },
  { value: 'easy' as const, label: '容易完成' },
]
const visibleTasks = computed(() => {
  const keyword = searchKeyword.value.trim().toLocaleLowerCase()
  const result = (keyword ? tasks.value.filter((task) => {
    const haystack = [task.title, task.description_text, plainTextFromRich(task.description), ...task.tags].filter(Boolean).join(' ').toLocaleLowerCase()
    return haystack.includes(keyword)
  }) : [...tasks.value])
  if (sortMode.value === 'latest') return result.sort((left, right) => new Date(right.claim_ends_at).getTime() - new Date(left.claim_ends_at).getTime())
  if (sortMode.value === 'reward') return result.sort((left, right) => rewardSortValue(right) - rewardSortValue(left))
  if (sortMode.value === 'easy') return result.sort((left, right) => (left.average_completion_minutes ?? left.completion_window_hours * 60) - (right.average_completion_minutes ?? right.completion_window_hours * 60))
  return result
})
const nowEpoch = ref(Date.now())
let clock: ReturnType<typeof setInterval> | undefined
const categories = [{ value: '', label: '全部' }, { value: 'onboarding', label: '线下' }, { value: 'daily', label: '日常' }, { value: 'community', label: '社区' }, { value: 'commerce', label: '商城' }, { value: 'research', label: '调研' }, { value: 'campaign', label: '活动' }]
const claimGroups = [{ value: '', label: '全部' }, { value: 'to_submit', label: '待提交' }, { value: 'reviewing', label: '审核中' }, { value: 'rejected', label: '未通过' }, { value: 'completed', label: '已完成' }, { value: 'ended', label: '已结束' }]

onMounted(() => { clock = setInterval(() => { nowEpoch.value = Date.now() }, 1000) })
onUnmounted(() => { if (clock) clearInterval(clock) })

onShow(() => {
  if (uni.getStorageSync('task_mall_open_my_tasks') && auth.accessToken) {
    uni.removeStorageSync('task_mall_open_my_tasks')
    tab.value = 'mine'
    void loadMine()
    return
  }
  if (tab.value === 'hall') void loadHall(); else void loadMine()
})
onPullDownRefresh(async () => { try { if (tab.value === 'hall') await loadHall(); else await loadMine() } finally { uni.stopPullDownRefresh() } })
async function loadHall() {
  loading.value = true
  loadError.value = ''
  try {
    const data = await listHallTasks({ category: category.value || undefined })
    tasks.value = data.items
    withdrawalNotices.value = data.withdrawal_notices || []
  } catch (error) {
    loadError.value = error instanceof Error ? error.message : '请检查网络后重试。'
    uni.showToast({ title: error instanceof Error ? error.message : '任务加载失败', icon: 'none' })
  } finally { loading.value = false }
}
async function openMine() { if (!auth.accessToken) return navigateToLogin(); tab.value = 'mine'; await loadMine() }
function openNotifications() { uni.navigateTo({ url: '/pages/notifications/index' }) }
async function loadMine() {
  if (!auth.accessToken) return
  loading.value = true
  loadError.value = ''
  try { myClaims.value = (await listMyTaskClaims(auth.accessToken, claimGroup.value)).items }
  catch (error) { loadError.value = error instanceof Error ? error.message : '请检查网络后重试。'; uni.showToast({ title: error instanceof Error ? error.message : '任务进度加载失败', icon: 'none' }) }
  finally { loading.value = false }
}
function openTaskDetail(taskId: string) { uni.navigateTo({ url: `/pages/task-detail/index?id=${encodeURIComponent(taskId)}` }) }
function openClaimDetail(claimId: string) { uni.navigateTo({ url: `/pages/task-claim/index?id=${encodeURIComponent(claimId)}` }) }
function rewardSortValue(task: HallTask) { const reward = task.rewards[0]; if (!reward) return 0; if (reward.type === 'points') return reward.points || 0; if (reward.type === 'wallet_balance') return reward.balance_amount || 0; return (reward.quantity || 1) * 100 }
function showFilterHint() { filterVisible.value = true }
function selectSort(value: 'default' | 'latest' | 'reward' | 'easy') { sortMode.value = value; filterVisible.value = false }
async function cancel(item: TaskClaim) {
  if (!auth.accessToken) return
  const confirmed = await uni.showModal({ title: '取消任务', content: '名额和奖励预留会释放，但本周期不能再次领取。' })
  if (!confirmed.confirm) return
  try { await cancelTaskClaim(auth.accessToken, item.id); await loadMine(); uni.showToast({ title: '已取消', icon: 'success' }) }
  catch (error) { uni.showToast({ title: error instanceof Error ? error.message : '取消失败', icon: 'none' }) }
}
function rewardText(reward: TaskReward) { if (reward.type === 'points') return `+${reward.points} 积分`; if (reward.type === 'wallet_balance') return `+¥${((reward.balance_amount || 0) / 100).toFixed(2)} 余额`; return '优惠券奖励' }
function primaryRewardText(task: HallTask) { return task.rewards[0] ? rewardText(task.rewards[0]).replace(' 余额', '') : '任务奖励' }
function categoryLabel(value: string) { return ({ onboarding: '线下', daily: '日常', community: '社区', commerce: '商城', research: '调研', campaign: '活动' } as Record<string, string>)[value] || '其他' }
function taskTypeLabel(value: string) { return ({ one_time: '单次任务', daily: '每日任务', weekly: '每周任务', monthly: '每月任务', milestone: '里程碑任务', repeatable: '可重复任务' } as Record<string, string>)[value] || '常规任务' }
function displayTags(task: HallTask) { const category = categoryLabel(task.category); return [...new Set(task.tags.filter((tag) => tag !== category))].slice(0, 2) }
function durationText(value: number | null) { if (value === null) return '暂无'; if (value < 60) return `${value} 分钟`; return `${(value / 60).toFixed(value % 60 === 0 ? 0 : 1)} 小时` }
function reviewDurationText(task: HallTask) { return task.average_review_minutes === null ? `限时 ${task.review_deadline_hours}h` : durationText(task.average_review_minutes) }
function completionDurationText(task: HallTask) { return task.average_completion_minutes === null ? `领取后 ${task.completion_window_hours}h` : `约 ${durationText(task.average_completion_minutes)}` }
function formatMoney(value: number) { return (value / 100).toFixed(2) }
function formatTime(value: string) { return new Date(value).toLocaleString() }
function reasonText(reason: string | null) { return ({ not_started: '尚未开始', ended: '已结束', quota_exhausted: '名额已满', ineligible: '系统自动加入' } as Record<string, string>)[reason || ''] || '暂不可领取' }
function categoryMark(value: string) { return ({ onboarding: '新', daily: '学', community: '享', commerce: '购', research: '研', campaign: '活' } as Record<string, string>)[value] || '任' }
function visibleClaimTags(item: TaskClaim) { const category = categoryLabel(item.category); return [...new Set(item.tags.filter(tag => tag !== category))].slice(0, 1) }
function claimRewardText(item: TaskClaim) { return item.rewards[0] ? rewardText(item.rewards[0]).replace(' 余额', '') : '任务奖励' }
function claimStatusText(status: string) { return ({ claimed: '待提交', withdrawn: '待重新提交', submitted: '已提交', reviewing: '审核中', rejected: '未通过', approved: '已完成', cancelled: '已放弃', expired: '已过期', appealed: '申诉中', appeal_reviewing: '申诉审核中', appeal_rejected: '申诉未通过' } as Record<string, string>)[status] || '状态待同步' }
function primaryActionText(item: TaskClaim) { if (['claimed', 'withdrawn'].includes(item.status)) return '提交任务'; if (item.status === 'rejected' && item.submission_count < item.submission_limit) return '修改并重交'; if (['submitted', 'reviewing', 'appealed', 'appeal_reviewing'].includes(item.status)) return '查看进度'; if (item.status === 'approved') return '查看结果'; return '查看详情' }
function claimProgressWidth(item: TaskClaim) { const progress = item.progress?.percent ?? (item.status === 'approved' ? 100 : item.submission_count > 0 ? 52 : 0); return `${Math.max(0, Math.min(100, progress))}%` }
function canCancel(item: TaskClaim) { return item.status === 'claimed' && item.cancel_count === 0 && item.submission_count === 0 }
function remainingMilliseconds(value: string) { return new Date(value).getTime() - nowEpoch.value }
function isUrgent(value: string) { const remaining = remainingMilliseconds(value); return remaining > 0 && remaining < 3600000 }
function deadlineText(item: TaskClaim) { if (['approved', 'cancelled', 'appeal_rejected'].includes(item.status)) return claimStatusText(item.status); const remaining = remainingMilliseconds(item.completion_deadline_at); if (remaining <= 0) return '任务已超过提交期限'; const hours = Math.floor(remaining / 3600000); const minutes = Math.floor((remaining % 3600000) / 60000); const seconds = Math.floor((remaining % 60000) / 1000); return `距提交截止 ${hours}时${minutes}分${seconds}秒` }
</script>

<style scoped>
.task-dashboard{position:relative;margin:18rpx 0 24rpx;padding:24rpx;overflow:hidden;background:linear-gradient(135deg,#dffbff 0%,#fff 54%,#fff2b8 100%)}.task-dashboard::after{content:'';position:absolute;right:-44rpx;top:-56rpx;width:160rpx;height:160rpx;border:3rpx solid var(--tm-ink-900);border-radius:46rpx;background:var(--tm-campus-coral);transform:rotate(18deg);opacity:.14}.dashboard-copy{padding-right:140rpx}.dashboard-title{display:block;margin-top:10rpx;font-size:31rpx;font-weight:950}.dashboard-note{display:block;margin-top:7rpx;color:var(--tm-ink-700);font-size:18rpx;line-height:1.5}.dashboard-ring{position:absolute;right:24rpx;top:27rpx;display:flex;align-items:baseline;justify-content:center;width:92rpx;height:92rpx;border:7rpx solid var(--tm-campus-cyan);border-right-color:var(--tm-brand-600);border-radius:50%;background:#fff;box-shadow:0 4rpx 0 var(--tm-ink-900);font-weight:950}.dashboard-ring strong{font-size:28rpx;line-height:86rpx}.dashboard-ring text{font-size:14rpx}.dashboard-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:10rpx;margin-top:22rpx}.dashboard-grid view{padding:14rpx 10rpx;border:2rpx solid var(--tm-ink-900);border-radius:16rpx;background:#fff}.dashboard-grid view:nth-child(2){background:var(--tm-campus-lime-soft)}.dashboard-grid view:nth-child(3){background:var(--tm-campus-purple-soft)}.dashboard-grid strong,.dashboard-grid text{display:block}.dashboard-grid strong{font-size:25rpx;font-weight:950}.dashboard-grid text{margin-top:3rpx;color:var(--tm-ink-700);font-size:16rpx;font-weight:750}
.page{min-height:100vh;padding:36rpx 28rpx 80rpx;box-sizing:border-box;background:#f5f7fb}.heading{margin:12rpx 4rpx 24rpx}.title,.subtitle,.task-title,.meta,.description,.deadline,.empty{display:block}.title{font-size:44rpx;font-weight:750}.subtitle{margin-top:8rpx;color:#667085}.tabs{display:grid;grid-template-columns:1fr 1fr;padding:8rpx;border-radius:18rpx;background:#e9edf5}.tab{padding:18rpx;text-align:center;border-radius:14rpx;color:#667085}.tab.active{background:#fff;color:#3157d5;font-weight:700}.filters{display:flex;gap:12rpx;margin-top:22rpx;overflow-x:auto}.chip{flex:none;padding:12rpx 22rpx;border-radius:30rpx;background:#fff;color:#667085;font-size:23rpx}.chip.active{background:#3157d5;color:#fff}.card{margin-top:22rpx;padding:28rpx;border-radius:24rpx;background:#fff}.card-head{display:flex;justify-content:space-between;gap:20rpx}.task-title{font-size:30rpx;font-weight:700}.meta{margin-top:7rpx;color:#98a2b3;font-size:21rpx}.slots{color:#3157d5;font-weight:650}.description{margin-top:20rpx;color:#475467;line-height:1.6}.rewards{display:flex;gap:10rpx;flex-wrap:wrap;margin-top:18rpx}.reward{padding:8rpx 14rpx;border-radius:12rpx;background:#eef3ff;color:#3157d5;font-size:22rpx}.deadline{margin-top:18rpx;color:#98a2b3;font-size:21rpx}.primary,.secondary{height:76rpx;margin-top:22rpx;border-radius:16rpx;line-height:76rpx}.primary{background:#3157d5;color:#fff}.secondary{border:1rpx solid #d0d5dd;background:#fff;color:#475467}.primary::after,.secondary::after{border:0}.primary[disabled]{background:#d0d5dd;color:#fff}.empty{padding:80rpx 0;text-align:center;color:#98a2b3}
.page{padding:32rpx 28rpx 90rpx;background:var(--tm-canvas)}.heading{position:relative;overflow:hidden;margin:0 0 24rpx;padding:34rpx 32rpx;border-radius:30rpx;background:linear-gradient(135deg,#173b87,var(--tm-brand-600));box-shadow:0 18rpx 42rpx rgba(37,83,185,.18);color:#fff}.heading::after{content:'';position:absolute;right:-80rpx;top:-100rpx;width:250rpx;height:250rpx;border-radius:50%;background:rgba(255,255,255,.09)}.eyebrow{position:relative;display:block;color:#cbdcff;font-size:19rpx;font-weight:750;letter-spacing:2rpx}.heading .title{position:relative;margin-top:12rpx;font-size:39rpx;font-weight:850}.heading .subtitle{position:relative;margin-top:10rpx;color:rgba(255,255,255,.75);font-size:23rpx}.tabs{border:1rpx solid var(--tm-line);background:#edf1f7}.tab.active{color:var(--tm-brand-700);box-shadow:0 7rpx 18rpx rgba(41,59,93,.08);font-weight:800}.chip{border:1rpx solid var(--tm-line)}.chip.active{border-color:var(--tm-brand-600);background:var(--tm-brand-600)}.card{border:1rpx solid var(--tm-line);border-radius:27rpx;box-shadow:0 10rpx 26rpx rgba(35,49,80,.04)}.task-title{font-weight:800}.slots{padding:7rpx 12rpx;border-radius:11rpx;background:var(--tm-brand-100);color:var(--tm-brand-700);font-size:21rpx}.reward{background:var(--tm-reward-100);color:var(--tm-reward-600);font-weight:750}.primary{border-radius:18rpx;background:var(--tm-brand-600);font-weight:750}.secondary{border-color:var(--tm-line);border-radius:18rpx}.submit-box{background:var(--tm-brand-50)}
.withdrawal-ticker{display:flex;align-items:center;gap:14rpx;height:76rpx;margin-top:18rpx;padding:0 20rpx;box-sizing:border-box;border:1rpx solid #d8eaff;border-radius:20rpx;background:#f4f9ff;color:#3b82d0}.ticker-speaker{display:flex;align-items:center;justify-content:center;width:42rpx;height:42rpx;flex:none;border-radius:50%;background:#e0efff;font-size:26rpx;font-weight:850}.ticker-swiper{height:58rpx;min-width:0;flex:1}.ticker-line{display:flex;align-items:center;height:58rpx;overflow:hidden;font-size:22rpx;white-space:nowrap}.ticker-user,.ticker-amount{font-weight:800}.ticker-amount{color:#1f6fc4}.task-card{padding:26rpx 24rpx}.task-card-top{display:flex;align-items:flex-start;justify-content:space-between;gap:18rpx}.task-heading-main{min-width:0;flex:1}.primary-reward{text-align:right;flex:none;color:#f05f22}.primary-reward>text{display:block;font-size:30rpx;font-weight:850}.primary-reward .reward-caption{margin-top:3rpx;color:#98a2b3;font-size:17rpx;font-weight:500}.performance-line{display:flex;gap:12rpx;margin-top:13rpx;overflow:hidden;color:#667085;font-size:19rpx;white-space:nowrap}.metric-strong{color:#f05f22;font-weight:800}.task-card .description{display:-webkit-box;margin-top:18rpx;overflow:hidden;-webkit-box-orient:vertical;-webkit-line-clamp:2;color:#475467;font-size:23rpx;line-height:1.55}.task-card-bottom{display:flex;align-items:center;justify-content:space-between;gap:16rpx;margin-top:18rpx}.task-tags{display:flex;min-width:0;gap:10rpx}.task-tag{max-width:120rpx;padding:7rpx 14rpx;overflow:hidden;border-radius:10rpx;background:#f5f6f8;color:#667085;font-size:19rpx;text-overflow:ellipsis;white-space:nowrap}.traffic-metrics{display:flex;flex:none;gap:16rpx;color:#98a2b3}.traffic-item{font-size:20rpx}.task-card .deadline{margin-top:16rpx}.detail-entry{display:flex;align-items:center;justify-content:space-between;margin-top:18rpx;padding-top:17rpx;border-top:1rpx solid #edf0f5;color:var(--tm-brand-700);font-size:22rpx;font-weight:750}.detail-arrow{font-size:34rpx;font-weight:400}
.mine-status-tabs{margin:22rpx -28rpx 0;padding:0 28rpx;background:#fff;white-space:nowrap}.mine-status-inner{display:inline-flex;min-width:100%;gap:34rpx}.mine-status-tab{position:relative;flex:none;padding:20rpx 0;color:#667085;font-size:23rpx}.mine-status-tab.active{color:var(--tm-brand-700);font-weight:800}.mine-status-tab.active::after{content:'';position:absolute;left:12%;right:12%;bottom:0;height:5rpx;border-radius:5rpx;background:var(--tm-brand-600)}.mine-card{display:flex;gap:22rpx;margin:18rpx -4rpx 0;padding:26rpx 24rpx;border:1rpx solid var(--tm-line);border-radius:26rpx;background:#fff;box-shadow:0 8rpx 22rpx rgba(35,49,80,.04)}.mine-task-icon{display:flex;align-items:center;justify-content:center;width:84rpx;height:84rpx;flex:none;border-radius:24rpx;background:linear-gradient(145deg,#e8f0ff,#f5f8ff);color:var(--tm-brand-700);font-size:30rpx;font-weight:850}.mine-card-main{min-width:0;flex:1}.mine-title-row{display:flex;align-items:flex-start;justify-content:space-between;gap:14rpx}.mine-title{min-width:0;overflow:hidden;font-size:28rpx;font-weight:800;text-overflow:ellipsis;white-space:nowrap}.mine-reward{flex:none;color:#f05f22;font-size:25rpx;font-weight:850}.mine-tag-row{display:flex;align-items:center;gap:8rpx;margin-top:12rpx}.mine-tag{padding:6rpx 12rpx;border-radius:8rpx;background:#f4f6f8;color:#667085;font-size:18rpx}.status-pill{margin-left:auto;padding:6rpx 11rpx;border-radius:9rpx;background:#eef4ff;color:var(--tm-brand-700);font-size:18rpx}.status-rejected,.status-appeal_rejected{background:#fff0ed;color:#d92d20}.status-approved{background:#ecfdf3;color:#067647}.status-cancelled,.status-expired{background:#f2f4f7;color:#98a2b3}.claimed-time{display:block;margin-top:15rpx;color:#98a2b3;font-size:20rpx}.reject-note{display:-webkit-box;margin-top:12rpx;overflow:hidden;-webkit-box-orient:vertical;-webkit-line-clamp:2;color:#d92d20;font-size:20rpx;line-height:1.5}.mine-progress-row{display:flex;align-items:center;justify-content:space-between;gap:14rpx;margin-top:15rpx}.deadline-countdown{color:#f04438;font-size:20rpx;font-weight:700}.deadline-countdown.urgent{animation:pulse 1s infinite}.submission-count{color:#98a2b3;font-size:18rpx}.mine-actions{display:flex;justify-content:flex-end;gap:12rpx;margin-top:17rpx}.outline-action{height:58rpx;margin:0;padding:0 22rpx;border:1rpx solid var(--tm-brand-500);border-radius:29rpx;background:#fff;color:var(--tm-brand-700);font-size:21rpx;line-height:58rpx}.outline-action::after{border:0}.outline-action.muted{border-color:#d0d5dd;color:#667085}.skeleton-list{padding-top:4rpx}.skeleton-card{padding:26rpx 24rpx}.skeleton-title{width:52%;height:30rpx;border-radius:10rpx}.skeleton-meta{width:72%;height:18rpx;margin-top:18rpx;border-radius:8rpx}.skeleton-copy{width:100%;height:74rpx;margin-top:24rpx;border-radius:14rpx}.skeleton-action{width:34%;height:28rpx;margin-top:22rpx;margin-left:auto;border-radius:10rpx}.heading{border:1rpx solid #dce4fb;background:linear-gradient(135deg,#f8faff,#edf2ff);box-shadow:var(--tm-shadow-card);color:var(--tm-ink-900)}.heading::after{display:none}.heading .eyebrow{color:var(--tm-brand-600)}.heading .subtitle{color:var(--tm-ink-500)}@keyframes pulse{50%{opacity:.55}}
.heading{border:3rpx solid var(--tm-ink-900);background:linear-gradient(135deg,var(--tm-campus-yellow) 0%,#ffe982 64%,#fff7cd 100%);box-shadow:7rpx 8rpx 0 rgba(22,22,22,.18);color:var(--tm-ink-900)}.heading .eyebrow{color:#8a5800;font-weight:900}.heading .title{color:var(--tm-ink-900);font-weight:900}.heading .subtitle{color:#584a24;font-weight:650}.withdrawal-ticker{border:2rpx solid var(--tm-ink-900);background:var(--tm-campus-cyan-soft);box-shadow:4rpx 5rpx 0 rgba(22,22,22,.10);color:var(--tm-ink-900)}.ticker-speaker{border:2rpx solid var(--tm-ink-900);background:var(--tm-campus-cyan);color:var(--tm-ink-900)}.ticker-amount{color:#dc421c}.mine-task-icon{border:2rpx solid var(--tm-ink-900);background:var(--tm-campus-yellow-soft);color:var(--tm-ink-900)}
/* 任务大厅采用校园社团海报般的硬边卡片，不改变任务领取与审核逻辑。 */
.tabs{border:2rpx solid var(--tm-ink-900);background:#fff}.tab{color:#6e7180;font-weight:800}.tab.active{background:var(--tm-brand-600);box-shadow:none;color:#fff}
.filters{padding:2rpx 0}.chip{border:2rpx solid var(--tm-ink-900);background:#fff;color:var(--tm-ink-900);font-weight:800;box-shadow:0 3rpx 0 rgba(22,22,22,.13)}.chip:nth-child(2){background:var(--tm-campus-yellow-soft)}.chip:nth-child(3){background:var(--tm-campus-lime-soft)}.chip:nth-child(4){background:var(--tm-campus-cyan-soft)}.chip:nth-child(5){background:var(--tm-campus-purple-soft)}.chip:nth-child(6){background:var(--tm-campus-coral-soft)}.chip.active{border-color:var(--tm-ink-900);background:var(--tm-brand-600);color:#fff}
.card,.mine-card{border:2rpx solid var(--tm-ink-900);box-shadow:5rpx 6rpx 0 rgba(22,22,22,.12)}.task-card{background:#fffefa}.task-title,.mine-title{color:var(--tm-ink-900);font-weight:900}.meta{color:#70727f}.primary-reward{color:var(--tm-campus-coral)}.metric-strong,.mine-reward{color:#e44b15}.task-tag{border:1rpx solid rgba(22,22,22,.1);background:var(--tm-campus-yellow-soft);color:#5e4a13;font-weight:700}.task-card-bottom .task-tag:nth-child(2){background:var(--tm-campus-cyan-soft);color:#11747d}.detail-entry{border-top-color:#e7dfc9;color:var(--tm-brand-700);font-weight:900}.traffic-metrics{color:#6f7280}.mine-status-tabs{border-top:2rpx solid var(--tm-ink-900);border-bottom:2rpx solid var(--tm-ink-900)}.mine-status-tab.active{color:var(--tm-ink-900)}.mine-status-tab.active::after{height:7rpx;background:var(--tm-campus-coral)}.mine-tag{background:var(--tm-campus-purple-soft);color:#6231ca;font-weight:700}.status-pill{background:var(--tm-campus-cyan-soft);color:#087a86;font-weight:800}.status-rejected,.status-appeal_rejected{background:var(--tm-campus-coral-soft);color:#cc3139}.status-approved{background:var(--tm-campus-lime-soft);color:#237a1d}.outline-action{border:2rpx solid var(--tm-ink-900);background:var(--tm-campus-yellow);box-shadow:0 4rpx 0 rgba(22,22,22,.18);color:var(--tm-ink-900);font-weight:850}.outline-action.muted{border-color:#c6c1b2;background:#fff;color:#767985;box-shadow:none}
.review-status-pill{padding:6rpx 11rpx;border-radius:9rpx;background:var(--tm-campus-yellow-soft);color:#7b5700;font-size:18rpx;font-weight:800}.mine-tag-row .status-pill{margin-left:auto}
/* 吸收推广型任务平台的高效信息层级，但保留校园品牌色和二级页领取规则。 */
.page{background:radial-gradient(circle at 92% 1%,rgba(65,219,220,.25),transparent 28%),linear-gradient(180deg,#eafaff 0,#f6fbff 30%,#f5f7fb 100%)}
.heading{border:0;background:linear-gradient(125deg,#105fda 0%,#168fe5 56%,#25c8c7 100%);box-shadow:0 20rpx 46rpx rgba(20,105,181,.20);color:#fff}.heading .eyebrow{color:#d7f8ff}.heading .title{color:#fff}.heading .subtitle{color:rgba(255,255,255,.82)}
.task-dashboard{border:1rpx solid #d8eaf1;background:linear-gradient(135deg,#fff 0%,#e8fbff 60%,#fff4bf 100%);box-shadow:0 14rpx 34rpx rgba(34,93,126,.09)}.dashboard-grid view{border:1rpx solid #dbe8ee;box-shadow:0 6rpx 14rpx rgba(25,83,115,.05)}.dashboard-ring{border-width:6rpx;box-shadow:0 7rpx 18rpx rgba(23,109,158,.13)}
.tabs{border:1rpx solid #d9e8ef;background:rgba(255,255,255,.72);box-shadow:0 8rpx 22rpx rgba(31,87,118,.07)}.tab{color:#677685}.tab.active{background:linear-gradient(135deg,#1268dc,#149fd7);box-shadow:0 8rpx 18rpx rgba(18,104,220,.18);color:#fff}
.chip,.chip:nth-child(n){border:1rpx solid #d9e7ed;background:#fff;box-shadow:0 6rpx 14rpx rgba(34,84,111,.05);color:#586775}.chip.active{border-color:#1578d6;background:#1578d6;box-shadow:0 8rpx 18rpx rgba(21,120,214,.18);color:#fff}
.withdrawal-ticker{border:1rpx solid #bfe2f2;background:#effaff;box-shadow:0 8rpx 20rpx rgba(31,106,145,.07)}.ticker-speaker{border:0;background:#bfeeff}
.card,.mine-card{border:1rpx solid #dfe9ee;box-shadow:0 13rpx 30rpx rgba(34,75,99,.08)}.task-card{padding:25rpx;background:#fff}.primary-reward{padding-left:12rpx}.primary-reward>text:first-child{color:#f05a32;font-size:31rpx}.task-availability{display:inline-block!important;margin-top:7rpx;padding:5rpx 11rpx;border-radius:999rpx;background:#dcf8e9;color:#0a7d4b!important;font-size:16rpx!important;font-weight:850!important}.task-availability.disabled{background:#f0f2f4;color:#8a949e!important}
.task-metric-strip{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:9rpx;margin-top:18rpx}.task-metric-strip view{min-width:0;padding:14rpx 10rpx;border-radius:16rpx;background:#f3f9fc;text-align:center}.task-metric-strip view:nth-child(2){background:#fff7d8}.task-metric-strip view:nth-child(3){background:#ebf8f1}.task-metric-strip strong,.task-metric-strip text{display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.task-metric-strip strong{color:#123b5a;font-size:22rpx;font-weight:900}.task-metric-strip text{margin-top:4rpx;color:#74808b;font-size:16rpx;font-weight:700}
.task-tag{border:0;background:#edf7fc;color:#2c718f}.task-card-bottom .task-tag:nth-child(2){background:#fff3ca;color:#826000}.detail-entry{border-top-color:#e7eef2;color:#1268c9}.mine-status-tabs{border-top:1rpx solid #dfe8ed;border-bottom:1rpx solid #dfe8ed}.mine-task-icon{border:0;background:#e5f7ff;color:#1270c5}.outline-action{border:1rpx solid #1876ce;background:#fff;box-shadow:none;color:#1268c9}
.task-empty{
  margin-top:18rpx;
  padding:38rpx 28rpx 32rpx;
  display:flex;
  flex-direction:column;
  align-items:center;
  text-align:center;
  border:1rpx solid #dfe9ee;
  border-radius:28rpx;
  background:linear-gradient(145deg,#ffffff 0%,#f3fbff 100%);
  box-shadow:0 12rpx 30rpx rgba(31,75,96,.08);
}
.task-empty-mine{background:linear-gradient(145deg,#ffffff 0%,#f8f5ff 100%)}
.task-empty-mark{
  width:72rpx;
  height:72rpx;
  display:flex;
  align-items:center;
  justify-content:center;
  border-radius:22rpx;
  background:linear-gradient(145deg,#e2f6ff,#e8fbf1);
  color:#1578d6;
  font-size:28rpx;
  font-weight:900;
  box-shadow:inset 0 0 0 1rpx rgba(21,120,214,.08);
}
.task-empty-title{margin-top:20rpx;color:#142033;font-size:29rpx;font-weight:900}
.task-empty-copy{margin-top:9rpx;color:#7c8b9d;font-size:23rpx;line-height:1.55}
.task-empty-action{
  width:auto;
  min-width:196rpx;
  height:60rpx;
  margin:22rpx 0 0;
  padding:0 28rpx;
  display:flex;
  align-items:center;
  justify-content:center;
  border:1rpx solid #cfe0ea;
  border-radius:999rpx;
  background:#fff;
  color:#1477d4;
  font-size:24rpx;
  font-weight:800;
  line-height:60rpx;
  box-shadow:none;
}
.task-empty-action::after{border:0}
/* Task content V4: 与首页统一的柔和蓝青信息卡，避免摘要呈现为输入框。 */
.task-card{position:relative;overflow:hidden;margin-top:20rpx;padding:26rpx 26rpx 22rpx;border:1rpx solid #dce9ef;border-radius:28rpx;background:linear-gradient(155deg,#fff 0%,#fff 72%,#f4fbff 100%);box-shadow:0 14rpx 34rpx rgba(32,78,103,.075)}
.task-card-top{gap:20rpx}.task-title{color:#102b45;font-size:29rpx;line-height:1.35;font-weight:900}.task-card .meta{margin-top:6rpx;color:#7890a1;font-size:19rpx}.primary-reward>text:first-child{color:#f15b35;font-size:29rpx;line-height:1.25}
.task-metric-strip{gap:10rpx;margin-top:17rpx}.task-metric-strip view{padding:14rpx 8rpx;border:1rpx solid rgba(160,195,210,.2);border-radius:17rpx;box-shadow:inset 0 1rpx 0 rgba(255,255,255,.75)}
.task-summary{display:-webkit-box;margin-top:17rpx;padding:0;overflow:hidden;border:0;border-radius:0;background:transparent;box-shadow:none;color:#53697a;font-size:22rpx;line-height:1.62;-webkit-box-orient:vertical;-webkit-line-clamp:2}
.task-card-bottom{margin-top:16rpx;padding-top:1rpx}.task-tags{flex-wrap:wrap;gap:8rpx}.task-tag{max-width:132rpx;padding:6rpx 12rpx;border:0;border-radius:999rpx;background:#edf8fc;color:#28728c;font-size:18rpx;font-weight:750}.task-card-bottom .task-tag:nth-child(2){background:#fff5d7;color:#846517}.traffic-metrics{gap:12rpx;color:#8495a3}.task-card .deadline{margin-top:13rpx;color:#91a0ac;font-size:19rpx}.detail-entry{margin-top:16rpx;padding-top:15rpx;border-top:1rpx solid #e7eff3;color:#1474c8;font-size:21rpx;font-weight:850}.detail-arrow{color:#30b9c5}
/* Compact controls: 降低一级切换和分类筛选的视觉重量。 */
.tabs{padding:5rpx;border-radius:17rpx}
.tab{display:flex;align-items:center;justify-content:center;min-height:66rpx;padding:0 10rpx;border-radius:13rpx;font-size:22rpx;line-height:1;font-weight:750}
.filters{gap:8rpx;margin-top:16rpx;padding:0 1rpx 4rpx;scrollbar-width:none}
.filters::-webkit-scrollbar{display:none;width:0;height:0}
.chip,.chip:nth-child(n){padding:9rpx 17rpx;border-radius:999rpx;font-size:20rpx;line-height:1.2;font-weight:750;box-shadow:0 4rpx 10rpx rgba(34,84,111,.045)}
.chip.active{box-shadow:0 6rpx 14rpx rgba(21,120,214,.15)}
.heading-art{position:absolute;z-index:0;right:-34rpx;bottom:-44rpx;width:390rpx;height:230rpx;opacity:.42;mix-blend-mode:multiply;pointer-events:none}.heading .eyebrow,.heading .title,.heading .subtitle{position:relative;z-index:1}.member-heading .heading-art{opacity:.14}.member-heading{background:linear-gradient(135deg,#f8fcff,#edf7ff)!important;color:#12314b!important}.member-heading .eyebrow{color:#1876ca!important}.member-heading .title{color:#12314b!important}.member-heading .subtitle{color:#637b8c!important}
.task-search{display:flex;align-items:center;gap:13rpx;margin-top:18rpx;padding:0 18rpx;height:76rpx;border:1rpx solid #cfe0e9;border-radius:999rpx;background:rgba(255,255,255,.94);box-shadow:0 8rpx 20rpx rgba(30,78,102,.06)}.task-search-icon{color:#1a80d2;font-size:39rpx;line-height:1}.task-search-input{min-width:0;height:74rpx;flex:1;color:#18344b;font-size:23rpx}.task-search-filter{padding:8rpx 5rpx;color:#1a78c9;font-size:21rpx;font-weight:850}.task-error{display:flex;align-items:center;gap:14rpx;margin-top:18rpx;padding:20rpx;border:1rpx solid #f0c8c2;border-radius:22rpx;background:#fff7f5}.task-error-icon{display:flex;width:48rpx;height:48rpx;flex:none;align-items:center;justify-content:center;border-radius:50%;background:#fff0ed;color:#d92d20;font-size:25rpx;font-weight:900}.task-error>view{min-width:0;flex:1}.task-error-title,.task-error-copy{display:block}.task-error-title{color:#9b2c25;font-size:22rpx;font-weight:850}.task-error-copy{margin-top:4rpx;overflow:hidden;color:#a96f68;font-size:18rpx;text-overflow:ellipsis;white-space:nowrap}.task-error-action{height:54rpx;margin:0;padding:0 18rpx;border:1rpx solid #e4a9a0;border-radius:999rpx;background:#fff;color:#b43d32;font-size:19rpx;line-height:52rpx}.task-error-action::after{border:0}
.filter-mask{position:fixed;z-index:100;inset:0;display:flex;align-items:flex-end;justify-content:center;background:rgba(16,42,59,.38);backdrop-filter:blur(6rpx)}.filter-sheet{width:100%;padding:28rpx 26rpx calc(26rpx + env(safe-area-inset-bottom));box-sizing:border-box;border-radius:32rpx 32rpx 0 0;background:#fff;box-shadow:0 -18rpx 50rpx rgba(20,61,81,.18)}.filter-sheet-head{display:flex;align-items:center;justify-content:space-between}.filter-sheet-title{color:#14354c;font-size:28rpx;font-weight:900}.filter-sheet-close{padding:0 8rpx;color:#7d909c;font-size:38rpx}.filter-options{display:grid;gap:10rpx;margin-top:22rpx}.filter-option{display:flex;align-items:center;justify-content:space-between;padding:20rpx 18rpx;border:1rpx solid #dce9ef;border-radius:18rpx;background:#fbfdfe;color:#496679;font-size:23rpx}.filter-option.active{border-color:#9bdcbe;background:#effbf5;color:#07885a;font-weight:850}
@media screen and (min-width:768px){.page{width:750rpx;margin:0 auto}}
.task-topbar{padding-top:calc(env(safe-area-inset-top) + 4rpx)}
.task-topbar{display:flex;align-items:center;justify-content:space-between;gap:18rpx;margin-bottom:18rpx;padding:4rpx 2rpx}.task-topbar-title{display:flex;flex-direction:column}.task-topbar-kicker{color:#6e96ad;font-size:16rpx;letter-spacing:2rpx;font-weight:850}.task-topbar-name{margin-top:4rpx;color:#163852;font-size:34rpx;font-weight:950}.task-topbar-actions{display:flex;gap:10rpx}.task-topbar-action{display:flex;flex-direction:column;align-items:center;justify-content:center;min-width:92rpx;padding:10rpx 12rpx;border:1rpx solid #d7e8ef;border-radius:18rpx;background:#fff;color:#3d718b;font-size:17rpx;box-shadow:0 6rpx 14rpx rgba(34,84,111,.05)}.task-topbar-action-icon{color:#1678cf;font-size:27rpx;line-height:1;margin-bottom:4rpx}.guest-task-note{display:block;margin:10rpx 8rpx 0;color:#7a92a2;font-size:19rpx}
.task-dashboard::after{border:1rpx solid rgba(36,105,126,.16);box-shadow:none}
/* V09 任务链路对齐：大厅先筛选、再成长数据和任务列表；保持接口与状态不变。 */
.page{padding:calc(env(safe-area-inset-top) + 18rpx) 28rpx calc(124rpx + env(safe-area-inset-bottom));background:linear-gradient(180deg,#edf8ff 0%,#f7fbfd 43%,#f2f7fa 100%)}
.task-topbar{margin:0 -2rpx 16rpx;padding:0 2rpx 2rpx;align-items:center}.task-topbar-title{align-items:flex-start}.task-topbar-kicker{display:none}.task-topbar-name{margin:0;color:#12324c;font-size:33rpx;line-height:1.2;font-weight:900}.task-topbar-actions{gap:12rpx}.task-topbar-action{width:68rpx;min-width:68rpx;height:68rpx;padding:0;border-radius:22rpx;border:1rpx solid #d5e5ed;background:rgba(255,255,255,.94);box-shadow:0 8rpx 18rpx rgba(31,85,110,.06);font-size:0}.task-topbar-action-icon{margin:0;color:#176ec4;font-size:31rpx}.task-topbar-action:first-child .task-topbar-action-icon{font-size:25rpx}
.task-search{margin-top:0;height:72rpx;padding:0 20rpx;border-color:#c9e0ea;background:#fff;box-shadow:0 8rpx 18rpx rgba(31,86,111,.06)}.task-search-input{font-size:22rpx}.task-search-filter{padding-left:12rpx;border-left:1rpx solid #e1edf2;font-size:20rpx}.guest-task-note{margin:10rpx 6rpx 0;font-size:18rpx}
.filters{margin-top:14rpx;gap:10rpx;overflow-x:auto;white-space:nowrap}.chip,.chip:nth-child(n){padding:9rpx 18rpx;border:1rpx solid #d5e5ed;border-radius:999rpx;background:#fff;box-shadow:0 4rpx 10rpx rgba(34,84,111,.045);color:#5d7282;font-size:20rpx;font-weight:750}.chip.active{border-color:#147bd3;background:#147bd3;box-shadow:0 6rpx 14rpx rgba(20,123,211,.18);color:#fff}
.heading{margin:0 0 18rpx;padding:28rpx 28rpx;min-height:214rpx;border-radius:28rpx;background:linear-gradient(135deg,#eef9ff,#e2f4ff 55%,#f4faff);box-shadow:0 12rpx 30rpx rgba(31,93,122,.08)}.heading .title{font-size:37rpx}.heading .subtitle{font-size:21rpx}.member-heading .heading-art{right:-24rpx;bottom:-20rpx;width:350rpx;height:220rpx;opacity:.26}
.task-dashboard{margin:18rpx 0 16rpx;padding:24rpx;border-radius:27rpx}.dashboard-copy{padding-right:132rpx}.dashboard-title{font-size:30rpx}.dashboard-note{font-size:17rpx}.dashboard-grid{gap:9rpx;margin-top:18rpx}.dashboard-grid view{padding:13rpx 8rpx;border:1rpx solid #dce9ef;border-radius:15rpx}.dashboard-grid strong{font-size:24rpx}.dashboard-grid text{font-size:16rpx}.dashboard-ring{right:22rpx;top:24rpx;width:86rpx;height:86rpx}.dashboard-ring strong{font-size:26rpx;line-height:80rpx}
.tabs{margin-top:14rpx;padding:4rpx;border:1rpx solid #cde0ea;border-radius:18rpx;background:#fff}.tab{min-height:64rpx;border-radius:14rpx;font-size:23rpx}.tab.active{background:#fff;border:1rpx solid #157bd5;color:#1472c8;box-shadow:none}
.hall-sort-row{display:flex;align-items:center;gap:10rpx;margin-top:14rpx;overflow-x:auto;white-space:nowrap}.hall-sort-label{flex:none;margin-right:2rpx;color:#173b56;font-size:22rpx;font-weight:850}.hall-sort-chip{flex:none;padding:8rpx 15rpx;border:1rpx solid #d7e6ed;border-radius:999rpx;background:#fff;color:#617989;font-size:19rpx}.hall-sort-chip.active{border-color:#9cd5ef;background:#ecf8ff;color:#1476ca;font-weight:850}.hall-sort-chip.filter-chip{margin-left:auto;color:#1476ca}
.withdrawal-ticker{height:66rpx;margin-top:14rpx;padding:0 16rpx;border-radius:18rpx;box-shadow:0 6rpx 16rpx rgba(31,106,145,.05)}.ticker-line{height:52rpx;font-size:19rpx}.ticker-swiper{height:52rpx}.ticker-speaker{width:34rpx;height:34rpx;font-size:20rpx}
.mine-status-tabs{margin:16rpx -28rpx 0;padding:0 28rpx;background:transparent;border:0}.mine-status-inner{gap:24rpx}.mine-status-tab{padding:15rpx 2rpx;font-size:20rpx}.mine-status-tab.active::after{height:4rpx}.mine-card{margin-top:16rpx;padding:22rpx 20rpx;border-radius:24rpx}.mine-task-icon{width:70rpx;height:70rpx;border-radius:20rpx;font-size:26rpx}.mine-title{font-size:26rpx}.mine-reward{font-size:23rpx}.mine-tag-row{margin-top:9rpx}.claimed-time{margin-top:11rpx;font-size:18rpx}.mine-progress-row{margin-top:11rpx}.mine-actions{margin-top:13rpx}.outline-action{height:52rpx;padding:0 18rpx;border-radius:26rpx;font-size:19rpx;line-height:52rpx}
.task-empty{margin-top:14rpx;min-height:190rpx;justify-content:center;padding:26rpx 20rpx;border-radius:25rpx;border-style:dashed;background:rgba(255,255,255,.64);box-shadow:none}.task-empty-mark{display:none}.task-empty-title{margin-top:0;color:#8294a2;font-size:23rpx;font-weight:500}.task-empty-copy,.task-empty-action{display:none}
.dashboard-ring{border-width:5rpx;box-shadow:0 8rpx 18rpx rgba(23,109,158,.13)}
.task-card-top{align-items:flex-start;gap:14rpx}.task-card-icon{display:flex;align-items:center;justify-content:center;width:58rpx;height:58rpx;flex:none;border-radius:17rpx;background:linear-gradient(145deg,#dff6ff,#e9fbf3);color:#1578d6;font-size:23rpx;font-weight:900}.task-heading-main{padding-top:2rpx}.task-title{line-height:1.28}.task-metric-strip{margin-top:14rpx}.task-card .task-summary{margin-top:14rpx;font-size:20rpx;line-height:1.48}.task-card-bottom{margin-top:13rpx}.task-card .deadline{margin-top:12rpx;font-size:17rpx}.detail-entry{margin-top:13rpx;padding-top:12rpx;font-size:19rpx}
.mine-review-notice{display:flex;align-items:center;gap:8rpx;margin:12rpx 0 0;padding:12rpx 16rpx;border:1rpx solid #cce4ef;border-radius:15rpx;background:#effaff;color:#28718c;font-size:18rpx;line-height:1.45}.mine-review-dot{color:#19a77b;font-size:16rpx}.mine-progress-track{height:8rpx;margin-top:10rpx;overflow:hidden;border-radius:999rpx;background:#e8f0f3}.mine-progress-track view{height:100%;border-radius:999rpx;background:linear-gradient(90deg,#16b886,#2ac3cb)}
.task-empty{min-height:168rpx;padding:25rpx 20rpx 22rpx;margin-top:14rpx;border-style:dashed;background:rgba(255,255,255,.74);box-shadow:none}.task-empty-mark{width:58rpx;height:58rpx;border-radius:18rpx;font-size:23rpx}.task-empty-title{margin-top:13rpx;font-size:24rpx}.task-empty-copy{margin-top:6rpx;font-size:18rpx;line-height:1.45}.task-empty-action{min-width:174rpx;height:48rpx;margin-top:15rpx;font-size:19rpx;line-height:48rpx}
</style>
