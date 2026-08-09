<template>
  <view class="page">
    <view class="heading"><text class="eyebrow">TASK HALL</text><text class="title">发现值得完成的任务</text><text class="subtitle">奖励先预留，按规则完成后自动进入你的资产</text></view>
    <view class="tabs"><view :class="['tab', { active: tab === 'hall' }]" @click="tab = 'hall'; loadHall()">可领取</view><view :class="['tab', { active: tab === 'mine' }]" @click="openMine">我的任务</view></view>
    <view v-if="tab === 'hall' && withdrawalNotices.length" class="withdrawal-ticker">
      <view class="ticker-speaker"><text>⌁</text></view>
      <swiper class="ticker-swiper" vertical autoplay circular :interval="3500" :duration="450">
        <swiper-item v-for="notice in withdrawalNotices" :key="notice.id">
          <view class="ticker-line"><text class="ticker-user">{{ notice.user_masked }}</text><text> 已提现，提现金额：</text><text class="ticker-amount">¥{{ formatMoney(notice.withdrawal_amount) }}</text></view>
        </swiper-item>
      </swiper>
    </view>
    <view v-if="tab === 'hall'" class="filters"><view v-for="item in categories" :key="item.value" :class="['chip', { active: category === item.value }]" @click="category = item.value; loadHall()">{{ item.label }}</view></view>
    <view v-if="tab === 'hall'">
      <view v-for="task in tasks" :key="task.id" class="card task-card" @click="openTaskDetail(task.id)">
        <view class="task-card-top">
          <view class="task-heading-main"><text class="task-title">{{ task.title }}</text><text class="meta">{{ categoryLabel(task.category) }} · {{ taskTypeLabel(task.task_type) }}</text></view>
          <view class="primary-reward"><text>{{ primaryRewardText(task) }}</text><text class="reward-caption">任务奖励</text></view>
        </view>
        <view class="performance-line">
          <text>已有 <text class="metric-strong">{{ task.completed_user_count }}</text> 人完成</text>
          <text>平均用时 <text class="metric-strong">{{ durationText(task.average_completion_minutes) }}</text></text>
          <text>平均审核 <text class="metric-strong">{{ durationText(task.average_review_minutes) }}</text></text>
        </view>
        <text class="description">{{ task.description_text || plainTextFromRich(task.description) }}</text>
        <view class="task-card-bottom">
          <view class="task-tags"><text class="task-tag">{{ categoryLabel(task.category) }}</text><text v-for="tag in displayTags(task)" :key="tag" class="task-tag">{{ tag }}</text></view>
          <view class="traffic-metrics"><text class="traffic-item">◷ {{ task.remaining_reward_slots }}</text><text class="traffic-item">◉ {{ task.view_count }}</text></view>
        </view>
        <view v-if="task.rewards.length > 1" class="rewards"><text v-for="(reward, index) in task.rewards" :key="index" class="reward">{{ rewardText(reward) }}</text></view>
        <view class="deadline">领取截止 {{ formatTime(task.claim_ends_at) }} · 领取后 {{ task.completion_window_hours }} 小时内完成</view>
        <view class="detail-entry"><text>{{ task.can_claim ? '查看详情后领取' : reasonText(task.claim_unavailable_reason) }}</text><text class="detail-arrow">›</text></view>
      </view>
      <text v-if="tasks.length === 0" class="empty">暂无符合条件的公开任务</text>
    </view>
    <view v-else>
      <scroll-view class="mine-status-tabs" scroll-x :show-scrollbar="false"><view class="mine-status-inner"><view v-for="item in claimGroups" :key="item.value" :class="['mine-status-tab', { active: claimGroup === item.value }]" @click="claimGroup = item.value; loadMine()">{{ item.label }}</view></view></scroll-view>
      <view v-for="item in myClaims" :key="item.id" class="mine-card" @click="openClaimDetail(item.id)">
        <view class="mine-task-icon"><text>{{ categoryMark(item.category) }}</text></view>
        <view class="mine-card-main">
          <view class="mine-title-row"><text class="mine-title">{{ item.title }}</text><text class="mine-reward">{{ claimRewardText(item) }}</text></view>
          <view class="mine-tag-row"><text class="mine-tag">{{ categoryLabel(item.category) }}</text><text v-for="tag in visibleClaimTags(item)" :key="tag" class="mine-tag">{{ tag }}</text><text :class="['status-pill', `status-${item.status}`]">{{ claimStatusText(item.status) }}</text></view>
          <text class="claimed-time">领取时间：{{ formatTime(item.created_at) }}</text>
          <text v-if="item.last_review?.decision === 'reject'" class="reject-note">未通过原因：{{ item.last_review.note || '材料不符合任务要求，请修改后重新提交。' }}</text>
          <view class="mine-progress-row"><text :class="['deadline-countdown', { urgent: isUrgent(item.completion_deadline_at) }]">{{ deadlineText(item) }}</text><text class="submission-count">提交 {{ item.submission_count }}/{{ item.submission_limit }} 次</text></view>
          <view class="mine-actions"><button class="outline-action" @click.stop="openClaimDetail(item.id)">{{ primaryActionText(item) }}</button><button v-if="canCancel(item)" class="outline-action muted" @click.stop="cancel(item)">放弃任务</button></view>
        </view>
      </view>
      <text v-if="myClaims.length === 0" class="empty">当前分类下还没有任务</text>
    </view>
    <AppTabBar current-target="tasks" />
  </view>
</template>

<script setup lang="ts">
import { onPullDownRefresh, onShow } from '@dcloudio/uni-app'
import { onMounted, onUnmounted, ref } from 'vue'
import AppTabBar from '@/components/AppTabBar.vue'
import { cancelTaskClaim, listHallTasks, listMyTaskClaims, type HallTask, type TaskClaim, type TaskReward, type WithdrawalNotice } from '@/api/tasks'
import { useAuthStore } from '@/stores/auth'
import { plainTextFromRich } from '@/utils/richText'

const auth = useAuthStore()
const tab = ref<'hall' | 'mine'>('hall')
const category = ref('')
const tasks = ref<HallTask[]>([])
const withdrawalNotices = ref<WithdrawalNotice[]>([])
const myClaims = ref<TaskClaim[]>([])
const claimGroup = ref('')
const nowEpoch = ref(Date.now())
let clock: ReturnType<typeof setInterval> | undefined
const categories = [{ value: '', label: '全部' }, { value: 'onboarding', label: '新手' }, { value: 'daily', label: '日常' }, { value: 'community', label: '社区' }, { value: 'commerce', label: '商城' }, { value: 'campaign', label: '活动' }]
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
async function loadHall() { const data = await listHallTasks({ category: category.value || undefined }); tasks.value = data.items; withdrawalNotices.value = data.withdrawal_notices || [] }
async function openMine() { if (!auth.accessToken) return uni.navigateTo({ url: '/pages/login/index' }); tab.value = 'mine'; await loadMine() }
async function loadMine() { if (auth.accessToken) myClaims.value = (await listMyTaskClaims(auth.accessToken, claimGroup.value)).items }
function openTaskDetail(taskId: string) { uni.navigateTo({ url: `/pages/task-detail/index?id=${encodeURIComponent(taskId)}` }) }
function openClaimDetail(claimId: string) { uni.navigateTo({ url: `/pages/task-claim/index?id=${encodeURIComponent(claimId)}` }) }
async function cancel(item: TaskClaim) {
  if (!auth.accessToken) return
  const confirmed = await uni.showModal({ title: '取消任务', content: '名额和奖励预留会释放，但本周期不能再次领取。' })
  if (!confirmed.confirm) return
  try { await cancelTaskClaim(auth.accessToken, item.id); await loadMine(); uni.showToast({ title: '已取消', icon: 'success' }) }
  catch (error) { uni.showToast({ title: error instanceof Error ? error.message : '取消失败', icon: 'none' }) }
}
function rewardText(reward: TaskReward) { if (reward.type === 'points') return `+${reward.points} 积分`; if (reward.type === 'wallet_balance') return `+¥${((reward.balance_amount || 0) / 100).toFixed(2)} 余额`; return '优惠券奖励' }
function primaryRewardText(task: HallTask) { return task.rewards[0] ? rewardText(task.rewards[0]).replace(' 余额', '') : '任务奖励' }
function categoryLabel(value: string) { return ({ onboarding: '新手', daily: '日常', community: '社区', commerce: '商城', research: '调研', campaign: '活动' } as Record<string, string>)[value] || value }
function taskTypeLabel(value: string) { return ({ one_time: '单次任务', daily: '每日任务', weekly: '每周任务', repeatable: '可重复任务' } as Record<string, string>)[value] || value }
function displayTags(task: HallTask) { const category = categoryLabel(task.category); return [...new Set(task.tags.filter((tag) => tag !== category))].slice(0, 2) }
function durationText(value: number | null) { if (value === null) return '暂无'; if (value < 60) return `${value} 分钟`; return `${(value / 60).toFixed(value % 60 === 0 ? 0 : 1)} 小时` }
function formatMoney(value: number) { return (value / 100).toFixed(2) }
function formatTime(value: string) { return new Date(value).toLocaleString() }
function reasonText(reason: string | null) { return ({ not_started: '尚未开始', ended: '已结束', quota_exhausted: '名额已满', ineligible: '系统自动加入' } as Record<string, string>)[reason || ''] || '暂不可领取' }
function categoryMark(value: string) { return ({ onboarding: '新', daily: '学', community: '享', commerce: '购', research: '研', campaign: '活' } as Record<string, string>)[value] || '任' }
function visibleClaimTags(item: TaskClaim) { const category = categoryLabel(item.category); return [...new Set(item.tags.filter(tag => tag !== category))].slice(0, 1) }
function claimRewardText(item: TaskClaim) { return item.rewards[0] ? rewardText(item.rewards[0]).replace(' 余额', '') : '任务奖励' }
function claimStatusText(status: string) { return ({ claimed: '待提交', withdrawn: '待重新提交', submitted: '已提交', reviewing: '审核中', rejected: '未通过', approved: '已完成', cancelled: '已放弃', expired: '已过期', appealed: '申诉中', appeal_reviewing: '申诉审核中', appeal_rejected: '申诉未通过' } as Record<string, string>)[status] || status }
function primaryActionText(item: TaskClaim) { if (['claimed', 'withdrawn'].includes(item.status)) return '提交任务'; if (item.status === 'rejected' && item.submission_count < item.submission_limit) return '修改并重交'; if (['submitted', 'reviewing', 'appealed', 'appeal_reviewing'].includes(item.status)) return '查看进度'; if (item.status === 'approved') return '查看结果'; return '查看详情' }
function canCancel(item: TaskClaim) { return item.status === 'claimed' && item.cancel_count === 0 && item.submission_count === 0 }
function remainingMilliseconds(value: string) { return new Date(value).getTime() - nowEpoch.value }
function isUrgent(value: string) { const remaining = remainingMilliseconds(value); return remaining > 0 && remaining < 3600000 }
function deadlineText(item: TaskClaim) { if (['approved', 'cancelled', 'appeal_rejected'].includes(item.status)) return claimStatusText(item.status); const remaining = remainingMilliseconds(item.completion_deadline_at); if (remaining <= 0) return '任务已超过提交期限'; const hours = Math.floor(remaining / 3600000); const minutes = Math.floor((remaining % 3600000) / 60000); const seconds = Math.floor((remaining % 60000) / 1000); return `距提交截止 ${hours}时${minutes}分${seconds}秒` }
</script>

<style scoped>
.page{min-height:100vh;padding:36rpx 28rpx 80rpx;box-sizing:border-box;background:#f5f7fb}.heading{margin:12rpx 4rpx 24rpx}.title,.subtitle,.task-title,.meta,.description,.deadline,.empty{display:block}.title{font-size:44rpx;font-weight:750}.subtitle{margin-top:8rpx;color:#667085}.tabs{display:grid;grid-template-columns:1fr 1fr;padding:8rpx;border-radius:18rpx;background:#e9edf5}.tab{padding:18rpx;text-align:center;border-radius:14rpx;color:#667085}.tab.active{background:#fff;color:#3157d5;font-weight:700}.filters{display:flex;gap:12rpx;margin-top:22rpx;overflow-x:auto}.chip{flex:none;padding:12rpx 22rpx;border-radius:30rpx;background:#fff;color:#667085;font-size:23rpx}.chip.active{background:#3157d5;color:#fff}.card{margin-top:22rpx;padding:28rpx;border-radius:24rpx;background:#fff}.card-head{display:flex;justify-content:space-between;gap:20rpx}.task-title{font-size:30rpx;font-weight:700}.meta{margin-top:7rpx;color:#98a2b3;font-size:21rpx}.slots{color:#3157d5;font-weight:650}.description{margin-top:20rpx;color:#475467;line-height:1.6}.rewards{display:flex;gap:10rpx;flex-wrap:wrap;margin-top:18rpx}.reward{padding:8rpx 14rpx;border-radius:12rpx;background:#eef3ff;color:#3157d5;font-size:22rpx}.deadline{margin-top:18rpx;color:#98a2b3;font-size:21rpx}.primary,.secondary{height:76rpx;margin-top:22rpx;border-radius:16rpx;line-height:76rpx}.primary{background:#3157d5;color:#fff}.secondary{border:1rpx solid #d0d5dd;background:#fff;color:#475467}.primary::after,.secondary::after{border:0}.primary[disabled]{background:#d0d5dd;color:#fff}.empty{padding:80rpx 0;text-align:center;color:#98a2b3}
.page{padding:32rpx 28rpx 90rpx;background:var(--tm-canvas)}.heading{position:relative;overflow:hidden;margin:0 0 24rpx;padding:34rpx 32rpx;border-radius:30rpx;background:linear-gradient(135deg,#173b87,var(--tm-brand-600));box-shadow:0 18rpx 42rpx rgba(37,83,185,.18);color:#fff}.heading::after{content:'';position:absolute;right:-80rpx;top:-100rpx;width:250rpx;height:250rpx;border-radius:50%;background:rgba(255,255,255,.09)}.eyebrow{position:relative;display:block;color:#cbdcff;font-size:19rpx;font-weight:750;letter-spacing:2rpx}.heading .title{position:relative;margin-top:12rpx;font-size:39rpx;font-weight:850}.heading .subtitle{position:relative;margin-top:10rpx;color:rgba(255,255,255,.75);font-size:23rpx}.tabs{border:1rpx solid var(--tm-line);background:#edf1f7}.tab.active{color:var(--tm-brand-700);box-shadow:0 7rpx 18rpx rgba(41,59,93,.08);font-weight:800}.chip{border:1rpx solid var(--tm-line)}.chip.active{border-color:var(--tm-brand-600);background:var(--tm-brand-600)}.card{border:1rpx solid var(--tm-line);border-radius:27rpx;box-shadow:0 10rpx 26rpx rgba(35,49,80,.04)}.task-title{font-weight:800}.slots{padding:7rpx 12rpx;border-radius:11rpx;background:var(--tm-brand-100);color:var(--tm-brand-700);font-size:21rpx}.reward{background:var(--tm-reward-100);color:var(--tm-reward-600);font-weight:750}.primary{border-radius:18rpx;background:var(--tm-brand-600);font-weight:750}.secondary{border-color:var(--tm-line);border-radius:18rpx}.submit-box{background:var(--tm-brand-50)}
.withdrawal-ticker{display:flex;align-items:center;gap:14rpx;height:76rpx;margin-top:18rpx;padding:0 20rpx;box-sizing:border-box;border:1rpx solid #d8eaff;border-radius:20rpx;background:#f4f9ff;color:#3b82d0}.ticker-speaker{display:flex;align-items:center;justify-content:center;width:42rpx;height:42rpx;flex:none;border-radius:50%;background:#e0efff;font-size:26rpx;font-weight:850}.ticker-swiper{height:58rpx;min-width:0;flex:1}.ticker-line{display:flex;align-items:center;height:58rpx;overflow:hidden;font-size:22rpx;white-space:nowrap}.ticker-user,.ticker-amount{font-weight:800}.ticker-amount{color:#1f6fc4}.task-card{padding:26rpx 24rpx}.task-card-top{display:flex;align-items:flex-start;justify-content:space-between;gap:18rpx}.task-heading-main{min-width:0;flex:1}.primary-reward{text-align:right;flex:none;color:#f05f22}.primary-reward>text{display:block;font-size:30rpx;font-weight:850}.primary-reward .reward-caption{margin-top:3rpx;color:#98a2b3;font-size:17rpx;font-weight:500}.performance-line{display:flex;gap:12rpx;margin-top:13rpx;overflow:hidden;color:#667085;font-size:19rpx;white-space:nowrap}.metric-strong{color:#f05f22;font-weight:800}.task-card .description{display:-webkit-box;margin-top:18rpx;overflow:hidden;-webkit-box-orient:vertical;-webkit-line-clamp:2;color:#475467;font-size:23rpx;line-height:1.55}.task-card-bottom{display:flex;align-items:center;justify-content:space-between;gap:16rpx;margin-top:18rpx}.task-tags{display:flex;min-width:0;gap:10rpx}.task-tag{max-width:120rpx;padding:7rpx 14rpx;overflow:hidden;border-radius:10rpx;background:#f5f6f8;color:#667085;font-size:19rpx;text-overflow:ellipsis;white-space:nowrap}.traffic-metrics{display:flex;flex:none;gap:16rpx;color:#98a2b3}.traffic-item{font-size:20rpx}.task-card .deadline{margin-top:16rpx}.detail-entry{display:flex;align-items:center;justify-content:space-between;margin-top:18rpx;padding-top:17rpx;border-top:1rpx solid #edf0f5;color:var(--tm-brand-700);font-size:22rpx;font-weight:750}.detail-arrow{font-size:34rpx;font-weight:400}
.mine-status-tabs{margin:22rpx -28rpx 0;padding:0 28rpx;background:#fff;white-space:nowrap}.mine-status-inner{display:inline-flex;min-width:100%;gap:34rpx}.mine-status-tab{position:relative;flex:none;padding:20rpx 0;color:#667085;font-size:23rpx}.mine-status-tab.active{color:var(--tm-brand-700);font-weight:800}.mine-status-tab.active::after{content:'';position:absolute;left:12%;right:12%;bottom:0;height:5rpx;border-radius:5rpx;background:var(--tm-brand-600)}.mine-card{display:flex;gap:22rpx;margin:18rpx -4rpx 0;padding:26rpx 24rpx;border:1rpx solid var(--tm-line);border-radius:26rpx;background:#fff;box-shadow:0 8rpx 22rpx rgba(35,49,80,.04)}.mine-task-icon{display:flex;align-items:center;justify-content:center;width:84rpx;height:84rpx;flex:none;border-radius:24rpx;background:linear-gradient(145deg,#e8f0ff,#f5f8ff);color:var(--tm-brand-700);font-size:30rpx;font-weight:850}.mine-card-main{min-width:0;flex:1}.mine-title-row{display:flex;align-items:flex-start;justify-content:space-between;gap:14rpx}.mine-title{min-width:0;overflow:hidden;font-size:28rpx;font-weight:800;text-overflow:ellipsis;white-space:nowrap}.mine-reward{flex:none;color:#f05f22;font-size:25rpx;font-weight:850}.mine-tag-row{display:flex;align-items:center;gap:8rpx;margin-top:12rpx}.mine-tag{padding:6rpx 12rpx;border-radius:8rpx;background:#f4f6f8;color:#667085;font-size:18rpx}.status-pill{margin-left:auto;padding:6rpx 11rpx;border-radius:9rpx;background:#eef4ff;color:var(--tm-brand-700);font-size:18rpx}.status-rejected,.status-appeal_rejected{background:#fff0ed;color:#d92d20}.status-approved{background:#ecfdf3;color:#067647}.status-cancelled,.status-expired{background:#f2f4f7;color:#98a2b3}.claimed-time{display:block;margin-top:15rpx;color:#98a2b3;font-size:20rpx}.reject-note{display:-webkit-box;margin-top:12rpx;overflow:hidden;-webkit-box-orient:vertical;-webkit-line-clamp:2;color:#d92d20;font-size:20rpx;line-height:1.5}.mine-progress-row{display:flex;align-items:center;justify-content:space-between;gap:14rpx;margin-top:15rpx}.deadline-countdown{color:#f04438;font-size:20rpx;font-weight:700}.deadline-countdown.urgent{animation:pulse 1s infinite}.submission-count{color:#98a2b3;font-size:18rpx}.mine-actions{display:flex;justify-content:flex-end;gap:12rpx;margin-top:17rpx}.outline-action{height:58rpx;margin:0;padding:0 22rpx;border:1rpx solid var(--tm-brand-500);border-radius:29rpx;background:#fff;color:var(--tm-brand-700);font-size:21rpx;line-height:58rpx}.outline-action::after{border:0}.outline-action.muted{border-color:#d0d5dd;color:#667085}@keyframes pulse{50%{opacity:.55}}
@media screen and (min-width:768px){.page{width:750rpx;margin:0 auto}}
</style>
