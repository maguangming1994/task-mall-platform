<template>
  <view class="page">
    <ModulePlaceholder v-if="modules.resolved && !moduleEnabled" code="module_checkin" />
    <view v-else-if="!modules.resolved" class="mp-skeleton">
      <view class="tm-skeleton" style="height: 200rpx; border-radius: 24rpx; margin: 24rpx 28rpx;" />
      <view class="tm-skeleton" style="height: 320rpx; border-radius: 24rpx; margin: 20rpx 28rpx;" />
    </view>
    <template v-else>
    <view class="hero-band">
      <view class="sign-hero">
        <view class="hero-row">
          <view>
            <text class="hero-lab">已连续签到</text>
            <text class="hero-num">{{ status?.current_streak ?? 0 }}<text class="hero-unit"> 天</text></text>
          </view>
          <view class="hero-right">
            <text class="hero-lab">今日可领</text>
            <text class="hero-num small">+{{ status?.today_reward_points ?? 0 }}<text class="hero-unit"> 积分</text></text>
          </view>
        </view>
        <view class="sign-streak"><text class="streak-ic">★</text><text v-if="nextMilestone">再签 {{ Math.max(0, nextMilestone.days - (status?.current_streak ?? 0)) }} 天可得 +{{ nextMilestone.points }} 积分里程碑奖励</text><text v-else>{{ milestoneNote }}</text></view>
        <button class="sign-btn" :disabled="checking || !status?.workflow.can_check_in" :loading="checking" @click="checkIn">{{ status?.checked_in_today ? '今日已签到 ✓' : status?.workflow.can_check_in ? `立即签到 · 领 ${status?.today_reward_points ?? 0} 积分` : status?.workflow.status_label || '暂不可签到' }}</button>
      </view>
    </view>

    <view v-if="loading" class="state-card"><view class="state-spinner" /><text>正在同步今日签到状态…</text></view>
    <view v-else-if="loadError" class="state-card error-state"><text class="state-icon">!</text><text class="state-title">签到状态加载失败</text><text class="state-copy">{{ loadError }}</text><button class="retry-button" @click="load">重试</button></view>
    <view v-else-if="!status" class="state-card empty-state"><text class="state-icon">签</text><text class="state-title">暂无签到状态</text><text class="state-copy">当前没有可用的签到周期，请稍后重试。</text><button class="retry-button" @click="load">重新同步</button></view>

    <template v-if="!loading && status">
    <view class="card">
      <view class="sec-title"><text class="sec-h">本周签到日历</text><text class="sec-more">{{ status?.workflow.status_label ?? '加载中' }}</text></view>
      <view class="cal-week"><text v-for="day in status?.week ?? placeholders" :key="`w-${day.date}`">{{ day.weekday?.replace('周', '') || day.weekday }}</text></view>
      <view class="cal-grid">
        <view v-for="day in status?.week ?? placeholders" :key="day.date" :class="['cal-d', { done: day.checked_in, today: day.is_today }]">
          <text>{{ day.checked_in ? '✓' : day.is_today ? '今' : '·' }}</text>
          <text class="cal-sub">{{ day.checked_in ? `+${day.reward_points}` : day.is_today ? '今日' : '待签' }}</text>
        </view>
      </view>
    </view>

    <view class="card">
      <view class="sec-title"><text class="sec-h">连续签到奖励</text><text class="sec-more">连续签到，奖励升级</text></view>
      <view class="reward-grid">
        <view v-for="milestone in rewardMilestones" :key="milestone.days" :class="['r7', { done: (status?.current_streak ?? 0) >= milestone.days, today: nextMilestone?.days === milestone.days && (status?.current_streak ?? 0) < milestone.days }]">
          <text v-if="(status?.current_streak ?? 0) >= milestone.days" class="r7-ic">✓</text>
          <text v-else class="r7-ic star">★</text>
          <text class="r7-b">+{{ milestone.points }}</text>
          <text class="r7-l">{{ nextMilestone?.days === milestone.days && (status?.current_streak ?? 0) < milestone.days ? '进行中' : `${milestone.days} 天` }}</text>
        </view>
      </view>
      <view class="milestone-note"><text>{{ milestoneNote }}</text></view>
    </view>

    <view class="card rule-card">
      <view class="sec-title"><text class="sec-h">签到规则</text><text class="sec-more">有效期 {{ status?.rules.validity_days ?? '-' }} 天</text></view>
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
.page{min-height:100vh;padding:0 0 48rpx;background:#F4F6F4;color:#191B1F}
/* 顶部绿色渐变带 + 签到英雄卡 */
.hero-band{background:linear-gradient(135deg,#12D978,#08A152);padding:20rpx 0 28rpx}
.sign-hero{position:relative;overflow:hidden;margin:0 24rpx;padding:40rpx 36rpx 36rpx;border-radius:36rpx;color:#fff}
.sign-hero::before{content:"";position:absolute;top:-180rpx;right:-80rpx;width:360rpx;height:360rpx;border-radius:50%;background:rgba(255,255,255,.10)}
.hero-row{position:relative;display:flex;align-items:flex-end;justify-content:space-between}
.hero-right{text-align:right}
.hero-lab{display:block;font-size:24rpx;opacity:.85;margin-bottom:10rpx}
.hero-num{display:block;font-size:72rpx;font-weight:800;line-height:1}
.hero-num.small{font-size:56rpx}
.hero-unit{font-size:26rpx;font-weight:600}
.sign-streak{position:relative;display:flex;align-items:center;gap:12rpx;margin-top:28rpx;padding:16rpx 22rpx;border-radius:20rpx;background:rgba(255,255,255,.16);font-size:24rpx}
.streak-ic{color:#FFE08A;font-size:26rpx}
.sign-btn{position:relative;width:100%;height:88rpx;margin:28rpx 0 0;border:0;border-radius:44rpx;background:#fff;color:#08A152;font-size:32rpx;font-weight:800;line-height:88rpx;box-shadow:0 12rpx 28rpx rgba(0,0,0,.12)}
.sign-btn[disabled]{background:rgba(255,255,255,.75);color:#5aad86;box-shadow:none;opacity:1}
.sign-btn::after{border:0}
/* 通用卡片 */
.card{margin:20rpx 24rpx 0;padding-bottom:12rpx;border-radius:32rpx;background:#fff;overflow:hidden;box-shadow:0 2rpx 4rpx rgba(23,26,23,.03),0 12rpx 40rpx rgba(23,26,23,.05)}
.sec-title{display:flex;align-items:center;justify-content:space-between;padding:28rpx 28rpx 20rpx}
.sec-h{display:flex;align-items:center;font-size:30rpx;font-weight:700}
.sec-h::before{content:"";width:8rpx;height:28rpx;margin-right:14rpx;border-radius:4rpx;background:#0BBF63}
.sec-more{font-size:24rpx;color:#AEB3BB}
/* 本周签到日历 */
.cal-week{display:grid;grid-template-columns:repeat(7,1fr);padding:12rpx 32rpx 0;text-align:center;font-size:22rpx;color:#AEB3BB}
.cal-grid{display:grid;grid-template-columns:repeat(7,1fr);padding:8rpx 32rpx 20rpx}
.cal-d{display:flex;flex-direction:column;align-items:center;justify-content:center;height:84rpx;border-radius:22rpx;font-size:28rpx;font-weight:600;color:#191B1F}
.cal-d .cal-sub{font-size:16rpx;color:#AEB3BB;margin-top:4rpx}
.cal-d.done{background:#E7F9EF;color:#08A152}
.cal-d.done .cal-sub{color:#0BBF63}
.cal-d.today{background:linear-gradient(135deg,#12D978,#08A152);color:#fff;box-shadow:0 8rpx 20rpx rgba(11,191,99,.35)}
.cal-d.today .cal-sub{color:rgba(255,255,255,.9)}
/* 连续签到奖励 */
.reward-grid{display:grid;grid-template-columns:repeat(5,1fr);gap:12rpx;padding:8rpx 28rpx 24rpx}
.r7{display:flex;flex-direction:column;align-items:center;gap:8rpx;padding:18rpx 0;border-radius:20rpx;background:#F7F9F8;font-size:20rpx;color:#767C85}
.r7-ic{font-size:28rpx;color:#AEB3BB}
.r7-ic.star{color:#D99400}
.r7-b{font-size:26rpx;font-weight:800}
.r7-l{font-size:20rpx}
.r7.done{background:#E7F9EF;color:#08A152}
.r7.done .r7-b{color:#08A152}
.r7.today{background:linear-gradient(135deg,#FF9A3D,#F2620A);color:#fff;box-shadow:0 8rpx 20rpx rgba(242,98,10,.3)}
.r7.today .r7-b{color:#fff}
.r7.today .r7-ic{color:#fff}
.milestone-note{margin:0 28rpx 20rpx;padding:18rpx 22rpx;border-radius:18rpx;background:#F7F9F8;font-size:24rpx;color:#767C85;line-height:1.5}
/* 规则卡 */
.rule-card{padding-bottom:28rpx}
.rule-text{display:block;padding:0 28rpx;font-size:26rpx;line-height:1.7;color:#3A3D43}
.links{display:flex;gap:16rpx;padding:24rpx 28rpx 0}
.links button{flex:1;height:72rpx;margin:0;border:2rpx solid #0BBF63;border-radius:36rpx;background:#fff;color:#08A152;font-size:26rpx;font-weight:700;line-height:68rpx}
.links button:last-child{background:linear-gradient(135deg,#12D978,#08A152);color:#fff;border:0;box-shadow:0 8rpx 18rpx rgba(11,191,99,.28)}
.links button::after{border:0}
/* 状态卡 */
.state-card{display:flex;flex-direction:column;align-items:center;justify-content:center;margin:20rpx 24rpx 0;padding:60rpx 40rpx;border-radius:32rpx;background:#fff;box-shadow:0 2rpx 4rpx rgba(23,26,23,.03),0 12rpx 40rpx rgba(23,26,23,.05);color:#767C85;font-size:26rpx}
.state-spinner{width:44rpx;height:44rpx;margin-bottom:20rpx;border:6rpx solid #E7F9EF;border-top-color:#0BBF63;border-radius:50%;animation:checkin-spin .8s linear infinite}
.error-state .state-icon{display:flex;align-items:center;justify-content:center;width:64rpx;height:64rpx;border-radius:20rpx;background:#FFEEEA;color:#FF2442;font-size:30rpx;font-weight:900}
.state-title{margin-top:16rpx;color:#191B1F;font-size:30rpx;font-weight:700}
.state-copy{margin-top:10rpx;color:#767C85;font-size:26rpx;line-height:1.5}
.retry-button{height:64rpx;margin-top:24rpx;padding:0 32rpx;border:2rpx solid #0BBF63;border-radius:32rpx;background:#fff;color:#08A152;font-size:26rpx;font-weight:700;line-height:60rpx}
.retry-button::after{border:0}
@keyframes checkin-spin{to{transform:rotate(360deg)}}
</style>
