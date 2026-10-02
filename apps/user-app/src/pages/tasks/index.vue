<template>
  <view class="page" :style="topSafeAreaStyle">
    <ModulePlaceholder v-if="modules.resolved && !moduleEnabled" code="module_tasks" />
    <view v-else-if="!modules.resolved" class="mp-skeleton">
      <view class="tm-skeleton" style="height: 200rpx; border-radius: 32rpx; margin: 24rpx;" />
      <view class="tm-skeleton" style="height: 320rpx; border-radius: 32rpx; margin: 20rpx 24rpx;" />
    </view>
    <template v-else>
    <view class="page-head">
      <text class="page-head-title">{{ tab === 'hall' ? '任务大厅' : '我的任务' }}</text>
      <view class="bell" @click="openNotifications">
        <image class="bell-icon" src="/static/icons/service-messages.svg" mode="aspectFit" />
        <view class="bell-dot" />
      </view>
    </view>
    <view class="seg">
      <view :class="['seg-item', { on: tab === 'hall' }]" @click="tab = 'hall'; loadHall()">任务大厅</view>
      <view :class="['seg-item', { on: tab === 'mine' }]" @click="openMine">我的任务</view>
    </view>
    <template v-if="tab === 'hall'">
      <view class="search-full">
        <image class="search-icon" src="/static/icons/icon-search.svg" mode="aspectFit" />
        <input v-model.trim="searchKeyword" class="search-input" placeholder="搜索任务，如「取快递」「带饭」" placeholder-class="search-ph" confirm-type="search" @confirm="loadHall" />
      </view>
      <text v-if="!auth.isAuthenticated" class="guest-task-note">登录后可领取任务，并在「我的任务」中查看执行进度</text>
      <scroll-view scroll-x class="cats" :show-scrollbar="false">
        <view v-for="item in categories" :key="item.value" :class="['cat-chip', { on: category === item.value }]" @click="category = item.value; loadHall()">{{ item.label }}</view>
      </scroll-view>
      <view class="filter-row">
        <view v-for="item in sortOptions.slice(0, 3)" :key="item.value" :class="['sort-item', { on: sortMode === item.value }]" @click="selectSort(item.value)">{{ item.value === 'reward' ? '积分最高' : item.label }}</view>
        <view class="sp" />
        <view class="filter-btn" @click="showFilterHint">筛选<text class="filter-btn-ic">⌄</text></view>
      </view>
      <view v-if="withdrawalNotices.length && modules.isEnabled('module_withdraw')" class="notice">
        <text class="notice-ic">讯</text>
        <swiper class="notice-swiper" vertical autoplay circular :interval="3500" :duration="450">
          <swiper-item v-for="notice in withdrawalNotices" :key="notice.id">
            <view class="notice-line"><text class="notice-user">{{ notice.user_masked }}</text><text> 已提现，提现金额：</text><text class="notice-amount">¥{{ formatMoney(notice.withdrawal_amount) }}</text></view>
          </swiper-item>
        </swiper>
      </view>
      <view v-if="loadError" class="state-card state-error">
        <view class="state-error-icon">!</view>
        <view class="state-error-body"><text class="state-error-title">任务暂时加载失败</text><text class="state-error-copy">{{ loadError }}</text></view>
        <button class="state-error-action" @click="loadHall">重试</button>
      </view>
      <view v-if="loading" class="skeleton-list">
        <view v-for="index in 3" :key="index" class="tcard skeleton-tcard">
          <view class="tm-skeleton skeleton-img" />
          <view class="skeleton-main">
            <view class="tm-skeleton skeleton-line w80" />
            <view class="tm-skeleton skeleton-line w50" />
            <view class="tm-skeleton skeleton-line w65" />
          </view>
        </view>
      </view>
      <view v-for="task in visibleTasks" :key="task.id" class="tcard" @click="openTaskDetail(task.id)">
        <image v-if="task.cover_image_url" class="tcard-img" :src="task.cover_image_url" mode="aspectFill" />
        <view v-else class="tcard-img tcard-fallback" :class="`fb-${task.category}`"><text>{{ categoryLabel(task.category) }}</text></view>
        <view class="t-main">
          <view class="t-title">{{ task.title }}</view>
          <view class="t-tags">
            <text class="tag tag-green">{{ categoryLabel(task.category) }}</text>
            <text v-for="(tag, tagIndex) in displayTags(task)" :key="tag" class="tag" :class="tagVariant(tagIndex)">{{ tag }}</text>
          </view>
          <view class="t-meta">
            <text class="t-meta-name">{{ task.publisher?.display_name || '校园任务官' }}</text>
            <view class="dot" />
            <text>剩余 {{ task.remaining_reward_slots }} 名额</text>
            <view class="dot" />
            <text>{{ task.view_count }} 人看过</text>
          </view>
          <view class="t-foot">
            <view :class="['t-pts', { plain: !rewardParts(task).matched }]">{{ rewardParts(task).main }}<text v-if="rewardParts(task).sub" class="t-pts-sub"> {{ rewardParts(task).sub }}</text></view>
            <view :class="['btn-go', { disabled: !task.can_claim }]">{{ task.can_claim ? '接单' : reasonText(task.claim_unavailable_reason) }}</view>
          </view>
        </view>
      </view>
      <view v-if="!loading && visibleTasks.length === 0" class="state-card task-empty">
        <view class="task-empty-mark">✦</view>
        <text class="task-empty-title">暂时没有符合条件的任务</text>
        <text class="task-empty-copy">换个分类看看，或刷新后获取最新任务。</text>
        <button class="task-empty-action" @click="category = ''; loadHall()">查看全部任务</button>
      </view>
    </template>
    <template v-else>
      <scroll-view scroll-x class="cats mine-cats" :show-scrollbar="false">
        <view v-for="item in claimGroups" :key="item.value" :class="['cat-chip', { on: claimGroup === item.value }]" @click="claimGroup = item.value; loadMine()">{{ item.label }}</view>
      </scroll-view>
      <view v-if="claimGroup === 'reviewing' && myClaims.length" class="mine-review-notice"><text class="mine-review-dot">●</text><text>正在审核 {{ myClaims.length }} 项任务，审核通过后奖励会自动发放</text></view>
      <view v-if="loadError" class="state-card state-error">
        <view class="state-error-icon">!</view>
        <view class="state-error-body"><text class="state-error-title">我的任务加载失败</text><text class="state-error-copy">{{ loadError }}</text></view>
        <button class="state-error-action" @click="loadMine">重试</button>
      </view>
      <view v-if="loading" class="skeleton-list">
        <view v-for="index in 3" :key="index" class="tcard skeleton-tcard">
          <view class="tm-skeleton skeleton-img" />
          <view class="skeleton-main">
            <view class="tm-skeleton skeleton-line w80" />
            <view class="tm-skeleton skeleton-line w50" />
          </view>
        </view>
      </view>
      <view v-for="item in myClaims" :key="item.id" class="mine-card" @click="openClaimDetail(item.id)">
        <view class="mine-task-icon" :class="`fb-${item.category}`"><text>{{ categoryMark(item.category) }}</text></view>
        <view class="mine-card-main">
          <view class="mine-title-row"><text class="mine-title">{{ item.title }}</text><text class="mine-reward">{{ claimRewardText(item) }}</text></view>
          <view class="mine-tag-row"><text class="tag">{{ categoryLabel(item.category) }}</text><text v-for="tag in visibleClaimTags(item)" :key="tag" class="tag">{{ tag }}</text><text :class="['status-pill', `status-${item.status}`]">{{ item.execution_status_label || claimStatusText(item.status) }}</text><text v-if="item.review_status_label" class="review-status-pill">{{ item.review_status_label }}</text></view>
          <text class="claimed-time">领取时间：{{ formatTime(item.created_at) }}</text>
          <text v-if="item.last_review?.decision === 'reject'" class="reject-note">未通过原因：{{ item.last_review.note || '材料不符合任务要求，请修改后重新提交。' }}</text>
          <view class="mine-progress-row"><text :class="['deadline-countdown', { urgent: isUrgent(item.completion_deadline_at) }]">{{ deadlineText(item) }}</text><text class="submission-count">提交 {{ item.submission_count }}/{{ item.submission_limit }} 次</text></view>
          <view class="mine-progress-track"><view :style="{ width: claimProgressWidth(item) }" /></view>
          <view class="mine-actions"><button class="outline-action" @click.stop="openClaimDetail(item.id)">{{ primaryActionText(item) }}</button><button v-if="canCancel(item)" class="outline-action muted" @click.stop="cancel(item)">放弃任务</button></view>
        </view>
      </view>
      <view v-if="!loading && myClaims.length === 0" class="state-card task-empty">
        <view class="task-empty-mark">▣</view>
        <text class="task-empty-title">当前分类下还没有任务</text>
        <text class="task-empty-copy">领取任务后，进度和审核状态会显示在这里。</text>
        <button class="task-empty-action" @click="claimGroup = ''; loadMine()">查看全部状态</button>
      </view>
    </template>
    </template>
    <view v-if="filterVisible" class="filter-mask" @click.self="filterVisible = false">
      <view class="filter-sheet">
        <view class="filter-sheet-head"><text class="filter-sheet-title">任务排序与筛选</text><text class="filter-sheet-close" @click="filterVisible = false">×</text></view>
        <view class="filter-options"><view v-for="item in sortOptions" :key="item.value" :class="['filter-option', { active: sortMode === item.value }]" @click="selectSort(item.value)"><text>{{ item.label }}</text><text v-if="sortMode === item.value">✓</text></view></view>
      </view>
    </view>
    <AppTabBar current-target="tasks" />
  </view>
</template>

<script setup lang="ts">
import { formatDateTime as formatTime } from '@/utils/dateTime'
import { onPullDownRefresh, onShow } from '@dcloudio/uni-app'
import { computed, onMounted, onUnmounted, ref } from 'vue'
import AppTabBar from '@/components/AppTabBar.vue'
import { cancelTaskClaim, listHallTasks, listMyTaskClaims, type HallTask, type TaskClaim, type TaskReward, type WithdrawalNotice } from '@/api/tasks'
import { useAuthStore } from '@/stores/auth'
import { navigateToLogin } from '@/utils/authRedirect'
import { plainTextFromRich } from '@/utils/richText'
import { createTopSafeAreaStyle } from '@/utils/safeArea'
import ModulePlaceholder from '@/components/ModulePlaceholder.vue'
import { useModulesStore } from '@/stores/modules'

const topSafeAreaStyle = createTopSafeAreaStyle()

const modules = useModulesStore()
const moduleEnabled = computed(() => modules.isEnabled('module_tasks'))

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

onShow(async () => {
  await modules.ensureReady()
  if (!moduleEnabled.value) return
  if (uni.getStorageSync('task_mall_open_my_tasks') && auth.accessToken) {
    uni.removeStorageSync('task_mall_open_my_tasks')
    tab.value = 'mine'
    void loadMine()
    return
  }
  if (tab.value === 'hall') void loadHall(); else void loadMine()
})
onPullDownRefresh(async () => { try { await modules.ensureReady(); if (!moduleEnabled.value) return; if (tab.value === 'hall') await loadHall(); else await loadMine() } finally { uni.stopPullDownRefresh() } })
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
async function openMine() { if (!auth.isAuthenticated) return navigateToLogin(); tab.value = 'mine'; await loadMine() }
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
function rewardParts(task: HallTask) {
  const text = primaryRewardText(task)
  const match = text.match(/^([+¥]*[\d.]+)\s*(.*)$/)
  return match ? { main: match[1], sub: match[2], matched: true } : { main: text, sub: '', matched: false }
}
function tagVariant(index: number) { return ['tag-red', 'tag-orange', 'tag-green', 'tag-blue'][index % 4] }
function categoryLabel(value: string) { return ({ onboarding: '线下', daily: '日常', community: '社区', commerce: '商城', research: '调研', campaign: '活动' } as Record<string, string>)[value] || '其他' }
function taskTypeLabel(value: string) { return ({ one_time: '单次任务', daily: '每日任务', weekly: '每周任务', monthly: '每月任务', milestone: '里程碑任务', repeatable: '可重复任务' } as Record<string, string>)[value] || '常规任务' }
function displayTags(task: HallTask) { const category = categoryLabel(task.category); return [...new Set(task.tags.filter((tag) => tag !== category))].slice(0, 2) }
function durationText(value: number | null) { if (value === null) return '暂无'; if (value < 60) return `${value} 分钟`; return `${(value / 60).toFixed(value % 60 === 0 ? 0 : 1)} 小时` }
function reviewDurationText(task: HallTask) { return task.average_review_minutes === null ? `限时 ${task.review_deadline_hours} 小时` : durationText(task.average_review_minutes) }
function completionDurationText(task: HallTask) { return task.average_completion_minutes === null ? `领取后 ${task.completion_window_hours} 小时` : `约 ${durationText(task.average_completion_minutes)}` }
function formatMoney(value: number) { return (value / 100).toFixed(2) }
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
.page{
  --green:#0BBF63;
  --green-deep:#08A152;
  --green-grad:linear-gradient(135deg,#12D978,#08A152);
  --green-soft:#E7F9EF;
  --orange-soft:#FFF2E5;
  --orange-deep:#F2620A;
  --price:#FF3B30;
  --t1:#191B1F;
  --t2:#767C85;
  --t3:#AEB3BB;
  --line:#EDEFEF;
  min-height:100vh;
  box-sizing:border-box;
  background:#F4F6F4;
  padding:var(--tm-custom-header-top,calc(env(safe-area-inset-top) + 8px)) 24rpx calc(124rpx + env(safe-area-inset-bottom));
}
/* ============ 页头 ============ */
.page-head{display:flex;align-items:center;justify-content:space-between;padding:8rpx 8rpx 0}
.page-head-title{color:var(--t1);font-size:40rpx;font-weight:800;line-height:1.25}
.bell{position:relative;display:flex;align-items:center;justify-content:center;width:68rpx;height:68rpx;flex:none;border:1rpx solid var(--line);border-radius:50%;background:#fff}
.bell-icon{width:36rpx;height:36rpx}
.bell-dot{position:absolute;top:13rpx;right:15rpx;width:14rpx;height:14rpx;border:3rpx solid #fff;border-radius:50%;background:var(--price)}
.seg{display:flex;gap:8rpx;margin-top:20rpx;padding:6rpx;border-radius:24rpx;background:#EAEDEA}
.seg-item{display:flex;flex:1;align-items:center;justify-content:center;height:64rpx;border-radius:18rpx;color:var(--t2);font-size:26rpx;font-weight:600}
.seg-item.on{background:#fff;box-shadow:0 4rpx 12rpx rgba(23,26,23,.06);color:var(--green-deep);font-weight:700}
/* ============ 搜索 ============ */
.search-full{display:flex;align-items:center;gap:12rpx;height:72rpx;margin-top:20rpx;padding:0 28rpx;border:1rpx solid var(--line);border-radius:36rpx;background:#fff}
.search-icon{width:30rpx;height:30rpx;flex:none}
.search-input{min-width:0;height:70rpx;flex:1;color:var(--t1);font-size:26rpx}
.search-ph{color:var(--t3)}
.guest-task-note{display:block;margin:14rpx 8rpx 0;color:var(--t2);font-size:24rpx}
/* ============ 分类 chips ============ */
.cats{margin-top:24rpx;white-space:nowrap}
.cats::-webkit-scrollbar{display:none;width:0;height:0}
.cat-chip{display:inline-flex;align-items:center;height:60rpx;margin-right:16rpx;padding:0 28rpx;border:1rpx solid var(--line);border-radius:30rpx;background:#fff;color:var(--t2);font-size:24rpx;vertical-align:top}
.cat-chip.on{border-color:var(--green);background:var(--green);color:#fff;font-weight:600}
/* ============ 排序与筛选行 ============ */
.filter-row{display:flex;align-items:center;gap:32rpx;padding:22rpx 8rpx 0;color:var(--t2);font-size:24rpx}
.sort-item.on{color:var(--t1);font-weight:700}
.filter-row .sp{flex:1}
.filter-btn{display:flex;align-items:center;gap:6rpx;color:var(--t1);font-weight:700}
.filter-btn-ic{color:var(--t3);font-size:22rpx}
/* ============ 提现跑马灯 ============ */
.notice{display:flex;align-items:center;gap:14rpx;height:64rpx;margin-top:20rpx;padding:0 22rpx;box-sizing:border-box;border-radius:18rpx;background:var(--green-soft);color:var(--green-deep);font-size:22rpx}
.notice-ic{display:flex;align-items:center;justify-content:center;width:32rpx;height:32rpx;flex:none;border-radius:50%;background:var(--green);color:#fff;font-size:18rpx;font-weight:700}
.notice-swiper{min-width:0;height:64rpx;flex:1}
.notice-line{display:flex;align-items:center;height:64rpx;overflow:hidden;white-space:nowrap}
.notice-user,.notice-amount{font-weight:700}
/* ============ 任务横卡 ============ */
.tcard{display:flex;gap:22rpx;align-items:center;margin-top:20rpx;padding:24rpx;box-sizing:border-box;border-radius:32rpx;background:#fff;box-shadow:0 2rpx 4rpx rgba(23,26,23,.03),0 12rpx 40rpx rgba(23,26,23,.05)}
.tcard-img{width:184rpx;height:184rpx;flex:none;border-radius:20rpx;background:#F2F4F3}
.tcard-fallback{display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,#12D978,#08A152);color:#fff;font-size:32rpx;font-weight:700}
.fb-onboarding{background:linear-gradient(135deg,#4C8DFF,#2F6BDE)}
.fb-daily{background:linear-gradient(135deg,#12D978,#08A152)}
.fb-community{background:linear-gradient(135deg,#9B7BFF,#6D4BD8)}
.fb-commerce{background:linear-gradient(135deg,#FF9A3D,#F2620A)}
.fb-research{background:linear-gradient(135deg,#33C6DD,#1493AD)}
.fb-campaign{background:linear-gradient(135deg,#FF6E6E,#E63B3B)}
.t-main{min-width:0;flex:1}
.t-title{display:-webkit-box;overflow:hidden;-webkit-box-orient:vertical;-webkit-line-clamp:2;color:var(--t1);font-size:28rpx;font-weight:600;line-height:1.4}
.t-tags{display:flex;flex-wrap:wrap;gap:8rpx;margin:12rpx 0}
.tag{display:inline-flex;align-items:center;height:36rpx;padding:0 12rpx;border-radius:10rpx;background:#F2F4F3;color:var(--t2);font-size:20rpx;font-weight:500}
.tag-red{background:#FFEEEA;color:var(--price)}
.tag-orange{background:var(--orange-soft);color:var(--orange-deep)}
.tag-green{background:var(--green-soft);color:var(--green-deep)}
.tag-blue{background:#EAF2FF;color:#3B82F6}
.t-meta{display:flex;align-items:center;gap:8rpx;overflow:hidden;color:var(--t3);font-size:22rpx;white-space:nowrap}
.t-meta-name{max-width:170rpx;overflow:hidden;text-overflow:ellipsis}
.t-meta .dot{width:4rpx;height:4rpx;flex:none;border-radius:50%;background:var(--t3)}
.t-foot{display:flex;align-items:center;justify-content:space-between;gap:16rpx;margin-top:16rpx}
.t-pts{color:var(--green-deep);font-size:38rpx;font-weight:800;line-height:1}
.t-pts.plain{font-size:24rpx;line-height:1.2}
.t-pts-sub{margin-left:2rpx;color:var(--green);font-size:20rpx;font-weight:500}
.btn-go{display:flex;align-items:center;justify-content:center;height:60rpx;padding:0 32rpx;flex:none;border-radius:30rpx;background:var(--green-grad);box-shadow:0 8rpx 20rpx rgba(11,191,99,.28);color:#fff;font-size:24rpx;font-weight:600}
.btn-go.disabled{background:#DFE3E0;box-shadow:none;color:#fff}
/* ============ 状态卡（错误 / 空态） ============ */
.state-card{display:flex;align-items:center;gap:20rpx;margin-top:20rpx;padding:28rpx;box-sizing:border-box;border-radius:32rpx;background:#fff;box-shadow:0 2rpx 4rpx rgba(23,26,23,.03),0 12rpx 40rpx rgba(23,26,23,.05)}
.state-error-icon{display:flex;align-items:center;justify-content:center;width:56rpx;height:56rpx;flex:none;border-radius:50%;background:#FFEEEA;color:#D92D20;font-size:26rpx;font-weight:900}
.state-error-body{min-width:0;flex:1}
.state-error-title{display:block;color:#9B2C25;font-size:26rpx;font-weight:700}
.state-error-copy{display:block;margin-top:6rpx;overflow:hidden;color:#B07A72;font-size:22rpx;text-overflow:ellipsis;white-space:nowrap}
.state-error-action{height:56rpx;margin:0;padding:0 28rpx;border:1rpx solid #F0C8C2;border-radius:28rpx;background:#fff;color:#B43D32;font-size:24rpx;line-height:54rpx}
.state-error-action::after{border:0}
.task-empty{flex-direction:column;padding:64rpx 40rpx;text-align:center}
.task-empty-mark{display:flex;align-items:center;justify-content:center;width:96rpx;height:96rpx;border-radius:28rpx;background:var(--green-soft);color:var(--green-deep);font-size:36rpx}
.task-empty-title{margin-top:24rpx;color:var(--t1);font-size:30rpx;font-weight:700}
.task-empty-copy{margin-top:12rpx;color:var(--t2);font-size:24rpx;line-height:1.55}
.task-empty-action{display:flex;align-items:center;justify-content:center;width:auto;min-width:220rpx;height:64rpx;margin:32rpx 0 0;padding:0 40rpx;border-radius:32rpx;background:var(--green-grad);box-shadow:0 8rpx 20rpx rgba(11,191,99,.28);color:#fff;font-size:26rpx;font-weight:600;line-height:64rpx}
.task-empty-action::after{border:0}
/* ============ 骨架屏 ============ */
.skeleton-tcard{align-items:flex-start}
.skeleton-img{width:184rpx;height:184rpx;flex:none;border-radius:20rpx}
.skeleton-main{min-width:0;flex:1;padding-top:8rpx}
.skeleton-line{height:26rpx;margin-bottom:18rpx;border-radius:8rpx}
.skeleton-line.w80{width:80%}
.skeleton-line.w50{width:50%}
.skeleton-line.w65{width:65%}
/* ============ 筛选弹层 ============ */
.filter-mask{position:fixed;z-index:100;inset:0;display:flex;align-items:flex-end;justify-content:center;background:rgba(23,26,23,.4)}
.filter-sheet{width:100%;padding:32rpx 32rpx calc(32rpx + env(safe-area-inset-bottom));box-sizing:border-box;border-radius:40rpx 40rpx 0 0;background:#fff}
.filter-sheet-head{display:flex;align-items:center;justify-content:space-between}
.filter-sheet-title{color:var(--t1);font-size:32rpx;font-weight:800}
.filter-sheet-close{padding:0 12rpx;color:var(--t3);font-size:44rpx;line-height:1}
.filter-options{display:grid;gap:16rpx;margin-top:28rpx}
.filter-option{display:flex;align-items:center;justify-content:space-between;padding:26rpx 28rpx;border:1rpx solid var(--line);border-radius:24rpx;background:#FAFBFA;color:var(--t2);font-size:26rpx}
.filter-option.active{border-color:var(--green);background:var(--green-soft);color:var(--green-deep);font-weight:700}
/* ============ 我的任务 ============ */
.mine-cats{margin-top:24rpx}
.mine-review-notice{display:flex;align-items:center;gap:12rpx;margin-top:20rpx;padding:18rpx 24rpx;border-radius:18rpx;background:var(--green-soft);color:var(--green-deep);font-size:24rpx;line-height:1.45}
.mine-review-dot{color:var(--green);font-size:20rpx}
.mine-card{display:flex;gap:22rpx;margin-top:20rpx;padding:24rpx;box-sizing:border-box;border-radius:32rpx;background:#fff;box-shadow:0 2rpx 4rpx rgba(23,26,23,.03),0 12rpx 40rpx rgba(23,26,23,.05)}
.mine-task-icon{display:flex;align-items:center;justify-content:center;width:96rpx;height:96rpx;flex:none;border-radius:24rpx;background:linear-gradient(135deg,#12D978,#08A152);color:#fff;font-size:32rpx;font-weight:800}
.mine-card-main{min-width:0;flex:1}
.mine-title-row{display:flex;align-items:flex-start;justify-content:space-between;gap:16rpx}
.mine-title{min-width:0;overflow:hidden;color:var(--t1);font-size:28rpx;font-weight:700;text-overflow:ellipsis;white-space:nowrap}
.mine-reward{flex:none;color:var(--green-deep);font-size:26rpx;font-weight:800}
.mine-tag-row{display:flex;flex-wrap:wrap;align-items:center;gap:10rpx;margin-top:14rpx}
.status-pill{margin-left:auto;padding:8rpx 14rpx;border-radius:10rpx;background:var(--green-soft);color:var(--green-deep);font-size:22rpx;font-weight:600}
.status-rejected,.status-appeal_rejected{background:#FFEEEA;color:#D92D20}
.status-approved{background:var(--green-soft);color:var(--green-deep)}
.status-cancelled,.status-expired{background:#F2F4F3;color:var(--t3)}
.review-status-pill{padding:8rpx 14rpx;border-radius:10rpx;background:var(--orange-soft);color:var(--orange-deep);font-size:22rpx;font-weight:600}
.claimed-time{display:block;margin-top:16rpx;color:var(--t3);font-size:22rpx}
.reject-note{display:-webkit-box;margin-top:12rpx;overflow:hidden;-webkit-box-orient:vertical;-webkit-line-clamp:2;color:#D92D20;font-size:24rpx;line-height:1.5}
.mine-progress-row{display:flex;align-items:center;justify-content:space-between;gap:16rpx;margin-top:14rpx}
.deadline-countdown{color:#F04438;font-size:22rpx;font-weight:700}
.deadline-countdown.urgent{animation:pulse 1s infinite}
.submission-count{color:var(--t3);font-size:22rpx}
.mine-progress-track{height:10rpx;margin-top:12rpx;overflow:hidden;border-radius:999rpx;background:#EFF1EF}
.mine-progress-track view{height:100%;border-radius:999rpx;background:linear-gradient(90deg,#12D978,#08A152)}
.mine-actions{display:flex;justify-content:flex-end;gap:16rpx;margin-top:20rpx}
.outline-action{height:60rpx;margin:0;padding:0 28rpx;border:1rpx solid var(--green);border-radius:30rpx;background:#fff;color:var(--green-deep);font-size:24rpx;font-weight:600;line-height:58rpx}
.outline-action::after{border:0}
.outline-action.muted{border-color:#D9DDD9;color:var(--t2)}
@keyframes pulse{50%{opacity:.55}}
@media screen and (min-width:768px){.page{width:750rpx;margin:0 auto}}
</style>
