<template>
  <view class="page tm-campus-page">
    <ModulePlaceholder v-if="modules.resolved && !moduleEnabled" code="module_checkin" />
    <view v-else-if="!modules.resolved" class="mp-skeleton">
      <view class="tm-skeleton" style="height: 200rpx; border-radius: 24rpx; margin: 24rpx 28rpx;" />
      <view class="tm-skeleton" style="height: 320rpx; border-radius: 24rpx; margin: 20rpx 28rpx;" />
    </view>
    <template v-else>
    <view class="hero tm-card-strong tm-campus-grid">
      <view class="hero-copy">
        <text class="tm-section-kicker">CAMPUS CHECK-IN</text>
        <text class="title">把每一天的努力<br />存成校园积分</text>
        <text class="subtitle">连续签到 {{ status?.current_streak ?? 0 }} 天 · 今日可得 {{ status?.today_reward_points ?? 0 }} 积分</text>
      </view>
      <view class="streak-seal"><strong>{{ status?.current_streak ?? 0 }}</strong><text>连续天数</text></view>
    </view>

    <view v-if="loading" class="state-card checkin-state"><view class="state-spinner" /><text>正在同步今日签到状态…</text></view>
    <view v-else-if="loadError" class="state-card checkin-state error-state"><text class="state-icon">!</text><text class="state-title">签到状态加载失败</text><text class="state-copy">{{ loadError }}</text><button class="retry-button" @click="load">重试</button></view>
    <view v-else-if="!status" class="state-card checkin-state empty-state"><text class="state-icon">签</text><text class="state-title">暂无签到状态</text><text class="state-copy">当前没有可用的签到周期，请稍后重试。</text><button class="retry-button" @click="load">重新同步</button></view>

    <template v-if="!loading && status">
    <view class="week-card tm-card">
      <view class="section-head"><view><text class="tm-section-kicker">THIS WEEK</text><text class="section-title">本周签到轨迹</text></view><text class="state-pill">{{ status?.workflow.status_label ?? '加载中' }}</text></view>
      <view class="week-grid">
        <view v-for="day in status?.week ?? placeholders" :key="day.date" :class="['day', { done: day.checked_in, today: day.is_today }]">
          <text>{{ day.weekday }}</text><view class="day-dot">{{ day.checked_in ? '✓' : day.is_today ? '今' : '·' }}</view><small>{{ day.checked_in ? `+${day.reward_points}` : '待签' }}</small>
        </view>
      </view>
    </view>

    <view class="milestone-card tm-card">
      <view class="section-head"><text class="section-title">连续奖励预览</text><text class="validity">连续签到，奖励升级</text></view>
      <view class="milestone-row">
        <view v-for="milestone in rewardMilestones" :key="milestone.days" :class="['milestone', { reached: (status?.current_streak ?? 0) >= milestone.days }]">
          <text>{{ milestone.days }} 天</text><view class="medal">★</view><strong>+{{ milestone.points }}</strong><small>积分</small>
        </view>
      </view>
      <view class="milestone-note"><text>{{ milestoneNote }}</text><text v-if="nextMilestone">再签 {{ Math.max(0, nextMilestone.days - (status?.current_streak ?? 0)) }} 天可得 +{{ nextMilestone.points }} 积分</text><text v-else>已完成全部连续签到里程碑</text></view>
    </view>

    <view class="action-card tm-card">
      <view class="reward-icon">积</view>
      <view class="action-copy"><strong>{{ status?.checked_in_today ? '今天已经完成签到' : `签到领取 ${status?.today_reward_points ?? 0} 积分` }}</strong><text>{{ status?.workflow.next_action ?? '正在同步签到状态…' }}</text></view>
      <button class="check-button" :disabled="checking || !status.workflow.can_check_in" :loading="checking" @click="checkIn">{{ status.checked_in_today ? '今日已签到' : status.workflow.can_check_in ? '立即签到' : status.workflow.status_label || '暂不可签到' }}</button>
    </view>

    <view class="rule-card tm-card">
      <view class="section-head"><text class="section-title">签到规则</text><text class="validity">有效期 {{ status?.rules.validity_days ?? '-' }} 天</text></view>
      <text class="rule-text">{{ status?.rules.description ?? '奖励规则由平台后台统一配置。' }}</text>
      <view class="links"><button @click="openAssets">查看积分记录</button><button @click="openTasks">去任务大厅</button></view>
    </view>
    </template>
    </template>
  </view>
</template>

<script setup lang="ts">
import { onShow } from '@dcloudio/uni-app'
import { computed, ref } from 'vue'
import { getDailyCheckIn, submitDailyCheckIn, type CheckInDay, type DailyCheckInStatus } from '@/api/growth'
import { useAuthStore } from '@/stores/auth'
import { navigateToLogin } from '@/utils/authRedirect'
import ModulePlaceholder from '@/components/ModulePlaceholder.vue'
import { useModulesStore } from '@/stores/modules'

const modules = useModulesStore()
const moduleEnabled = computed(() => modules.isEnabled('module_checkin'))

const auth = useAuthStore()
const status = ref<DailyCheckInStatus | null>(null)
const loading = ref(false)
const loadError = ref('')
const checking = ref(false)
const placeholders: CheckInDay[] = ['周一', '周二', '周三', '周四', '周五', '周六', '周日'].map((weekday, index) => ({ date: `placeholder-${index}`, weekday, is_today: false, checked_in: false, reward_points: 0 }))
const rewardMilestones = [
  { days: 1, points: 5 },
  { days: 3, points: 15 },
  { days: 7, points: 35 },
  { days: 15, points: 80 },
  { days: 30, points: 200 },
]
const nextMilestone = computed(() => rewardMilestones.find((milestone) => (status.value?.current_streak ?? 0) < milestone.days))
const milestoneNote = computed(() => status.value?.workflow.next_action || '同步完成后可领取今天的签到积分')

onShow(load)

async function load() {
  await modules.ensureReady()
  if (!moduleEnabled.value) return
  if (!auth.isAuthenticated) return navigateToLogin()
  loading.value = true
  loadError.value = ''
  try { status.value = await getDailyCheckIn(auth.accessToken) }
  catch (error) { status.value = null; loadError.value = error instanceof Error ? error.message : '请检查网络后重试'; uni.showToast({ title: error instanceof Error ? error.message : '签到状态加载失败', icon: 'none' }) }
  finally { loading.value = false }
}

async function checkIn() {
  if (!auth.accessToken || !status.value?.workflow.can_check_in) return
  checking.value = true
  try {
    status.value = await submitDailyCheckIn(auth.accessToken)
    uni.showToast({ title: `签到成功 +${status.value.today_reward_points}积分`, icon: 'success' })
  } catch (error) { uni.showToast({ title: error instanceof Error ? error.message : '签到失败，请稍后重试', icon: 'none' }) }
  finally { checking.value = false }
}

function openAssets() { uni.navigateTo({ url: '/pages/assets/index' }) }
function openTasks() { uni.reLaunch({ url: '/pages/tasks/index' }) }
</script>

<style scoped>
.page{min-height:100vh;padding:28rpx 26rpx 70rpx;background:var(--tm-canvas)}.hero{display:flex;align-items:center;justify-content:space-between;padding:30rpx;border:3rpx solid var(--tm-ink-900);border-radius:28rpx;background:linear-gradient(135deg,#fff4a9,var(--tm-campus-yellow) 55%,#ffd876);box-shadow:7rpx 8rpx 0 rgba(22,22,22,.16)}.hero-copy{min-width:0}.title,.subtitle,.section-title,.rule-text,.action-copy strong,.action-copy text{display:block}.title{margin-top:9rpx;color:var(--tm-ink-900);font-size:22px;font-weight:950;line-height:1.25}.subtitle{margin-top:12rpx;color:#685625;font-size:13px;font-weight:750}.streak-seal{display:flex;width:108rpx;height:108rpx;align-items:center;justify-content:center;flex:none;flex-direction:column;border:3rpx solid var(--tm-ink-900);border-radius:50%;background:var(--tm-campus-cyan);box-shadow:4rpx 5rpx 0 rgba(22,22,22,.18)}.streak-seal strong{font-size:22px;font-weight:950}.streak-seal text{font-size:10px;font-weight:850}.tm-card{margin-top:22rpx;padding:24rpx;border:2rpx solid var(--tm-ink-900);border-radius:24rpx;background:#fff;box-shadow:5rpx 6rpx 0 rgba(22,22,22,.09)}.section-head{display:flex;align-items:center;justify-content:space-between}.section-title{margin-top:5rpx;font-size:17px;font-weight:950}.state-pill,.validity{padding:6rpx 12rpx;border-radius:999rpx;background:var(--tm-campus-lime-soft);font-size:13px;font-weight:850}.week-grid{display:grid;grid-template-columns:repeat(7,1fr);gap:7rpx;margin-top:22rpx}.day{display:flex;align-items:center;flex-direction:column;gap:8rpx;color:#7c7f8c;font-size:12px}.day-dot{display:flex;width:55rpx;height:55rpx;align-items:center;justify-content:center;border:2rpx solid var(--tm-ink-900);border-radius:17rpx;background:#f2f4f7;color:var(--tm-ink-900);font-size:14px;font-weight:950}.day.done .day-dot{background:var(--tm-campus-lime)}.day.today .day-dot{box-shadow:0 0 0 5rpx rgba(42,190,223,.2)}.day small{font-size:10px}.action-card{display:flex;align-items:center;gap:15rpx;background:linear-gradient(135deg,#fff,var(--tm-campus-cyan-soft))}.reward-icon{display:flex;width:64rpx;height:64rpx;align-items:center;justify-content:center;flex:none;border:2rpx solid var(--tm-ink-900);border-radius:18rpx;background:var(--tm-campus-yellow);font-size:15px;font-weight:950}.action-copy{min-width:0;flex:1}.action-copy strong{font-size:15px;font-weight:950}.action-copy text{margin-top:5rpx;color:#727581;font-size:12px;line-height:1.35}.check-button{width:150rpx;height:66rpx;margin:0;border:2rpx solid var(--tm-ink-900);border-radius:18rpx;background:var(--tm-brand-600);box-shadow:0 5rpx 0 #073d9f;color:#fff;font-size:14px;font-weight:900;line-height:62rpx}.check-button[disabled]{background:#d7dbe3;box-shadow:none;color:#737783}.rule-text{margin-top:18rpx;color:#656978;font-size:14px;line-height:1.6}.links{display:flex;gap:12rpx;margin-top:18rpx}.links button{flex:1;height:68rpx;margin:0;border:2rpx solid var(--tm-ink-900);border-radius:16rpx;background:#fff;font-size:14px;font-weight:850;line-height:64rpx}.links button:last-child{background:var(--tm-campus-purple-soft)}button::after{border:0}@media screen and (min-width:768px){.page{width:750rpx;margin:0 auto}}
.milestone-card{background:linear-gradient(135deg,#fffef6,var(--tm-campus-cyan-soft))}.milestone-row{display:flex;gap:8rpx;margin-top:20rpx;overflow-x:auto}.milestone{min-width:104rpx;flex:1;padding:7rpx 5rpx;text-align:center;color:#5f6877}.milestone>text,.milestone>strong,.milestone>small{display:block}.milestone>text{font-size:12px;font-weight:800}.medal{display:flex;align-items:center;justify-content:center;width:52rpx;height:52rpx;margin:9rpx auto 7rpx;border:2rpx solid #c4cad2;border-radius:50%;background:#f7f8fa;color:#aab2bf;font-size:15px}.milestone>strong{font-size:13px}.milestone>small{margin-top:2rpx;font-size:10px}.milestone.reached{color:var(--tm-ink-900)}.milestone.reached .medal{border-color:#c89600;background:var(--tm-campus-yellow);color:#fff;border-radius:50%;box-shadow:inset 0 -5rpx 0 rgba(211,150,0,.25)}.milestone-note{display:flex;align-items:center;justify-content:space-between;gap:12rpx;margin-top:18rpx;padding:16rpx;border:1rpx solid var(--tm-ink-900);border-radius:15rpx;background:#fff;font-size:12px}.milestone-note text:first-child{min-width:0;flex:1;color:#5e6876}.milestone-note text:last-child{color:var(--tm-brand-700);font-weight:900;text-align:right}
.page{background:radial-gradient(circle at 92% 0,rgba(54,207,214,.18),transparent 28%),linear-gradient(180deg,#eefaff 0,#f7fbfd 44%,#f4f8fa 100%)!important}.hero{border:1rpx solid #d7e8ef!important;background:linear-gradient(135deg,#1268c9,#19abc4)!important;box-shadow:0 16rpx 34rpx rgba(29,104,145,.16)!important}.title,.subtitle{color:#fff!important}.subtitle{opacity:.82}.streak-seal{border:1rpx solid rgba(255,255,255,.45)!important;background:rgba(255,255,255,.16)!important;box-shadow:none!important;color:#fff}.tm-card{border:1rpx solid #dce9ef!important;box-shadow:0 12rpx 30rpx rgba(34,78,103,.075)!important;background:rgba(255,255,255,.97)!important}.week-card{background:linear-gradient(145deg,#fff,#f1fbfd)!important}.state-pill,.validity{border:0;background:#e7f8ef;color:#087a55}.day-dot{border:1rpx solid #d6e6ec!important;background:#f5fafc!important;color:#6e8794}.day.done .day-dot{border-color:#a9dec6!important;background:#dcf8e9!important;color:#087a55}.day.today .day-dot{box-shadow:0 0 0 5rpx rgba(42,190,223,.16)}.milestone-card{background:linear-gradient(145deg,#fff,#f2fbfd)!important}.medal{border:1rpx solid #d4e3e9!important;background:#f5fafc!important;color:#a8b8c0}.milestone.reached .medal{border-color:#f0c979!important;background:#fff3c8!important;color:#ce8a00;box-shadow:none}.milestone-note{border:1rpx solid #dce9ef!important;background:#f5fafc!important}.action-card{background:linear-gradient(145deg,#fff,#f1fbfd)!important}.reward-icon{border:0!important;background:#fff3c8!important;color:#b77600}.check-button{border:0!important;background:linear-gradient(135deg,#08a967,#0abca1)!important;box-shadow:0 8rpx 18rpx rgba(8,169,103,.16)!important}.check-button[disabled]{border:1rpx solid #d6e4ea!important;background:#edf3f5!important;box-shadow:none!important;color:#8b9ba4!important;opacity:1}.rule-card{background:#fff!important}.links button{border:1rpx solid #cfe1e9!important;box-shadow:none!important;background:#f5fafc!important;color:#28708a}.links button:last-child{background:#eaf8fb!important}.state-card{display:flex;align-items:center;justify-content:center;flex-direction:column;margin-top:20rpx;padding:38rpx 24rpx;border:1rpx solid #dce9ef;border-radius:26rpx;background:rgba(255,255,255,.97);box-shadow:0 12rpx 30rpx rgba(34,78,103,.075);color:#6f8794;font-size:14px}.checkin-state .state-spinner{width:42rpx;height:42rpx;border:6rpx solid #d3e8ee;border-top-color:#167bca;border-radius:50%;animation:checkin-spin .8s linear infinite}.checkin-state .state-spinner+text{margin-top:16rpx}.error-state .state-icon{display:flex;align-items:center;justify-content:center;width:62rpx;height:62rpx;border-radius:20rpx;background:#fff0ed;color:#c53c31;font-size:15px;font-weight:900}.error-state .state-title{margin-top:14rpx;color:#9d332b;font-size:15px;font-weight:850}.error-state .state-copy{margin-top:7rpx;color:#ad756e;font-size:13px}.retry-button{height:60rpx;margin-top:18rpx;padding:0 25rpx;border:1rpx solid #c0dce5;border-radius:999rpx;background:#fff;color:#1475c5;font-size:14px;line-height:58rpx}.retry-button::after{border:0}@keyframes checkin-spin{to{transform:rotate(360deg)}}
</style>
