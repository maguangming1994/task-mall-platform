<template>
  <view class="page">
    <view v-if="loading" class="state-text">正在加载任务详情…</view>
    <view v-else-if="!task" class="state-text">任务不存在或已经下线</view>
    <template v-else>
      <image v-if="task.cover_image_url" class="task-cover" :src="task.cover_image_url" mode="aspectFill" />
      <view class="detail-hero">
        <view class="share-button" @click="copyTaskLink">分享</view>
        <view class="hero-badges"><text class="hero-badge">{{ categoryLabel(task.category) }}</text><text class="hero-badge">{{ taskTypeLabel(task.task_type) }}</text></view>
        <text class="detail-title">{{ task.title }}</text>
        <view class="publisher-row"><text>{{ task.publisher.display_name }}</text><text class="verified-badge">✓ {{ task.publisher.publisher_badge }}</text></view>
      </view>

      <view v-if="task.steps.length" class="content-card">
        <text class="section-title">任务步骤 / 流程</text>
        <view v-for="(step, index) in task.steps" :key="step.id" class="step-row">
          <view class="step-index">{{ index + 1 }}</view>
          <view class="step-main">
            <view class="step-title-row"><text class="step-title">{{ step.title }}</text><text v-if="step.required" class="required-badge">必做</text></view>
            <text v-if="step.description" class="step-description">{{ step.description }}</text>
            <image v-if="step.example_image_url" class="step-guide-image" :src="step.example_image_url" mode="widthFix" @click="previewStepImage(step.example_image_url)" />
            <view v-if="step.link_url || step.copy_text" class="step-actions"><button v-if="step.link_url" class="step-action" @click="openStepLink(step.link_url)">{{ step.button_text || '打开链接' }}</button><button class="step-action secondary" @click="copyStepValue(step.copy_text || step.link_url || '')">复制{{ step.copy_text ? '内容' : '链接' }}</button></view>
            <text class="step-input-tip">{{ stepInputText(step.input_type) }}</text>
          </view>
        </view>
      </view>

      <view class="reward-card">
        <view><text class="section-eyebrow">完成任务可获得</text><text class="reward-main">{{ primaryRewardText(task) }}</text></view>
        <view class="slot-box"><text class="slot-value">{{ task.remaining_reward_slots }}</text><text class="slot-label">剩余名额</text></view>
      </view>
      <view v-if="task.rewards.length > 1" class="reward-list"><text v-for="(reward, index) in task.rewards" :key="index" class="reward-pill">{{ rewardText(reward) }}</text></view>

      <view class="stats-card">
        <view class="stat"><text class="stat-value">{{ task.completion_window_hours }}小时</text><text class="stat-label">限时完成</text></view>
        <view class="stat"><text class="stat-value">{{ task.review_deadline_hours }}小时</text><text class="stat-label">限时审核</text></view>
        <view class="stat"><text class="stat-value">{{ task.completed_user_count }}</text><text class="stat-label">已完成数</text></view>
        <view class="stat"><text class="stat-value">{{ passRateText(task.pass_rate_percent) }}</text><text class="stat-label">通过率</text></view>
      </view>

      <view class="content-card">
        <view class="section-head"><text class="section-title">任务说明</text><text class="view-count">◉ {{ task.view_count }} 次浏览</text></view>
        <rich-text class="description rich-content" :nodes="richTextContent(task.description)" />
        <view class="tag-row"><text v-for="tag in task.tags" :key="tag" class="task-tag">{{ tag }}</text></view>
      </view>

      <view class="content-card">
        <text class="section-title">领取与审核规则</text>
        <view class="rule-row"><text class="rule-label">领取截止</text><text class="rule-value">{{ formatTime(task.claim_ends_at) }}</text></view>
        <view class="rule-row"><text class="rule-label">完成时限</text><text class="rule-value">领取后 {{ task.completion_window_hours }} 小时</text></view>
        <view class="rule-row"><text class="rule-label">提交机会</text><text class="rule-value">最多 {{ task.submission_limit }} 次</text></view>
        <view class="rule-row"><text class="rule-label">审核期限</text><text class="rule-value">提交后 {{ task.review_deadline_hours }} 小时</text></view>
        <view class="rule-row"><text class="rule-label">申诉期限</text><text class="rule-value">审核结果后 {{ task.appeal_deadline_days }} 天</text></view>
      </view>

      <view v-if="task.rules.length" class="content-card">
        <text class="section-title">补充要求</text>
        <text v-for="(rule, index) in task.rules" :key="index" class="rule-copy">{{ ruleText(rule, index) }}</text>
      </view>

      <view class="safety-card"><text class="safety-title">奖励预留保障</text><text class="safety-copy">领取成功后系统会按任务规则预留全部奖励；审核通过后发放至资产账户。</text></view>
      <text class="report-link" @click="reportCurrentTask">发现任务有问题？举报此任务</text>
      <view class="bottom-space" />

      <view class="fixed-action">
        <view class="fixed-reward"><text class="fixed-caption">任务奖励</text><text class="fixed-value">{{ primaryRewardText(task) }}</text></view>
        <button class="claim-button" :disabled="!task.can_claim" :loading="claiming" @click="claimCurrentTask">{{ task.can_claim ? '立即领取' : reasonText(task.claim_unavailable_reason) }}</button>
      </view>
    </template>
  </view>
</template>

<script setup lang="ts">
import { onLoad } from '@dcloudio/uni-app'
import { ref } from 'vue'
import { claimTask, getHallTask, reportTask, type HallTask, type TaskReward } from '@/api/tasks'
import { useAuthStore } from '@/stores/auth'
import { richTextContent } from '@/utils/richText'

const auth = useAuthStore()
const task = ref<HallTask | null>(null)
const loading = ref(true)
const claiming = ref(false)
let taskId = ''

onLoad(async (options) => {
  taskId = typeof options?.id === 'string' ? options.id : ''
  if (!taskId) { loading.value = false; return }
  await loadTask()
})

async function loadTask() {
  loading.value = true
  try { task.value = await getHallTask(taskId) }
  catch { task.value = null }
  finally { loading.value = false }
}

async function claimCurrentTask() {
  if (!task.value) return
  if (!auth.accessToken) return uni.navigateTo({ url: '/pages/login/index' })
  const confirmed = await uni.showModal({ title: '确认领取任务', content: `领取后需在 ${task.value.completion_window_hours} 小时内完成，系统会立即预留全部奖励。` })
  if (!confirmed.confirm) return
  claiming.value = true
  try {
    await claimTask(auth.accessToken, task.value.id)
    const result = await uni.showModal({ title: '领取成功', content: '任务已加入“我的任务”，请在规定时间内提交完成材料。', confirmText: '查看我的任务', cancelText: '继续浏览' })
    if (result.confirm) {
      uni.setStorageSync('task_mall_open_my_tasks', true)
      uni.reLaunch({ url: '/pages/tasks/index' })
    } else {
      await loadTask()
    }
  } catch (error) { uni.showToast({ title: error instanceof Error ? error.message : '领取失败', icon: 'none' }) }
  finally { claiming.value = false }
}

async function reportCurrentTask() {
  if (!task.value) return
  if (!auth.accessToken) return uni.navigateTo({ url: '/pages/login/index' })
  const result = await uni.showModal({ title: '举报任务', editable: true, placeholderText: '请描述风险或违规情况' })
  if (!result.confirm || !result.content?.trim()) return
  try { await reportTask(auth.accessToken, task.value.id, 'other', result.content.trim()); uni.showToast({ title: '举报已提交', icon: 'success' }) }
  catch (error) { uni.showToast({ title: error instanceof Error ? error.message : '举报失败', icon: 'none' }) }
}

function rewardText(reward: TaskReward) { if (reward.type === 'points') return `+${reward.points} 积分`; if (reward.type === 'wallet_balance') return `+¥${((reward.balance_amount || 0) / 100).toFixed(2)} 余额`; return '优惠券奖励' }
function primaryRewardText(value: HallTask) { return value.rewards[0] ? rewardText(value.rewards[0]).replace(' 余额', '') : '任务奖励' }
function categoryLabel(value: string) { return ({ onboarding: '新手', daily: '日常', community: '社区', commerce: '商城', research: '调研', campaign: '活动' } as Record<string, string>)[value] || value }
function taskTypeLabel(value: string) { return ({ one_time: '单次任务', daily: '每日任务', weekly: '每周任务', repeatable: '可重复任务' } as Record<string, string>)[value] || value }
function durationText(value: number | null) { if (value === null) return '暂无'; if (value < 60) return `${value} 分钟`; return `${(value / 60).toFixed(value % 60 === 0 ? 0 : 1)} 小时` }
function passRateText(value: number | null) { return value === null ? '暂无' : `${value}%` }
function formatTime(value: string) { return new Date(value).toLocaleString() }
function reasonText(reason: string | null) { return ({ not_started: '尚未开始', ended: '已结束', quota_exhausted: '名额已满', ineligible: '系统自动加入' } as Record<string, string>)[reason || ''] || '暂不可领取' }
function ruleText(rule: string | Record<string, unknown>, index: number) { if (typeof rule === 'string') return `${index + 1}. ${rule}`; return `${index + 1}. ${String(rule.title || rule.description || JSON.stringify(rule))}` }
function stepInputText(value: string) { return ({ none: '按说明完成，无需单独填写', text: '需要填写文字结果', image: '需要上传截图凭证', text_image: '需要填写文字并上传截图', link: '需要访问或复制指定链接' } as Record<string, string>)[value] || '' }
function copyStepValue(value: string) { uni.setClipboardData({ data: value, success: () => uni.showToast({ title: '已复制', icon: 'success' }) }) }
function openStepLink(value: string) {
  // #ifdef H5
  window.open(value, '_blank', 'noopener,noreferrer')
  // #endif
  // #ifndef H5
  copyStepValue(value)
  // #endif
}
function previewStepImage(value: string) { uni.previewImage({ urls: [value], current: value }) }
function copyTaskLink() { if (!task.value) return; uni.setClipboardData({ data: `/pages/task-detail/index?id=${task.value.id}`, success: () => uni.showToast({ title: '任务链接已复制', icon: 'success' }) }) }
</script>

<style scoped>
.page{min-height:100vh;padding:28rpx 28rpx 0;box-sizing:border-box;background:var(--tm-canvas)}.state-text{padding:180rpx 0;text-align:center;color:var(--tm-ink-500)}.detail-hero{position:relative;overflow:hidden;padding:38rpx 32rpx 42rpx;border-radius:32rpx;background:linear-gradient(135deg,#173b87,var(--tm-brand-600));color:#fff;box-shadow:0 18rpx 44rpx rgba(37,83,185,.18)}.detail-hero::after{content:'';position:absolute;right:-80rpx;top:-90rpx;width:260rpx;height:260rpx;border-radius:50%;background:rgba(255,255,255,.1)}.share-button{position:absolute;z-index:2;right:24rpx;top:24rpx;padding:8rpx 16rpx;border-radius:18rpx;background:rgba(255,255,255,.16);font-size:20rpx}.hero-badges{position:relative;display:flex;gap:10rpx;padding-right:90rpx}.hero-badge{padding:7rpx 14rpx;border-radius:10rpx;background:rgba(255,255,255,.15);font-size:19rpx}.detail-title{position:relative;display:block;margin-top:24rpx;font-size:38rpx;font-weight:850;line-height:1.35}.publisher-row{position:relative;display:flex;align-items:center;gap:12rpx;margin-top:22rpx;color:rgba(255,255,255,.76);font-size:21rpx}.verified-badge{padding:4rpx 10rpx;border-radius:8rpx;background:rgba(255,255,255,.13);color:#fff}.reward-card{display:flex;align-items:center;justify-content:space-between;margin-top:20rpx;padding:28rpx;border:1rpx solid #ffe0bd;border-radius:28rpx;background:linear-gradient(135deg,#fffaf3,#fff2df)}.section-eyebrow,.reward-main,.slot-value,.slot-label,.section-title,.description,.rule-copy,.safety-title,.safety-copy,.fixed-caption,.fixed-value{display:block}.section-eyebrow{color:#9a6b43;font-size:20rpx}.reward-main{margin-top:7rpx;color:#f05f22;font-size:38rpx;font-weight:850}.slot-box{text-align:right}.slot-value{color:var(--tm-brand-700);font-size:34rpx;font-weight:850}.slot-label{margin-top:3rpx;color:#98a2b3;font-size:18rpx}.reward-list{display:flex;gap:10rpx;margin-top:12rpx}.reward-pill{padding:8rpx 14rpx;border-radius:12rpx;background:var(--tm-reward-100);color:var(--tm-reward-600);font-size:21rpx;font-weight:750}.stats-card{display:grid;grid-template-columns:repeat(4,1fr);margin-top:20rpx;padding:24rpx 8rpx;border:1rpx solid var(--tm-line);border-radius:26rpx;background:#fff}.stat{text-align:center;border-right:1rpx solid var(--tm-line)}.stat:last-child{border-right:0}.stat-value,.stat-label{display:block}.stat-value{font-size:22rpx;font-weight:850}.stat-label{margin-top:7rpx;color:#98a2b3;font-size:17rpx}.content-card{margin-top:20rpx;padding:28rpx;border:1rpx solid var(--tm-line);border-radius:28rpx;background:#fff}.section-head{display:flex;align-items:center;justify-content:space-between}.section-title{font-size:29rpx;font-weight:850}.view-count{color:#98a2b3;font-size:20rpx}.description{margin-top:20rpx;color:#475467;font-size:24rpx;line-height:1.75}.tag-row{display:flex;gap:10rpx;flex-wrap:wrap;margin-top:20rpx}.task-tag{padding:8rpx 14rpx;border-radius:10rpx;background:#f4f6f9;color:#667085;font-size:20rpx}.step-row{display:flex;gap:18rpx;padding:24rpx 0;border-bottom:1rpx solid #f0f2f5}.step-row:last-child{padding-bottom:0;border-bottom:0}.step-index{display:flex;align-items:center;justify-content:center;width:50rpx;height:50rpx;flex:none;border-radius:15rpx;background:var(--tm-brand-600);color:#fff;font-size:22rpx;font-weight:800}.step-main{min-width:0;flex:1}.step-title-row{display:flex;align-items:center;gap:10rpx}.step-title{font-size:25rpx;font-weight:800}.required-badge{padding:3rpx 8rpx;border-radius:7rpx;background:#fff0ed;color:#d92d20;font-size:16rpx}.step-description{display:block;margin-top:8rpx;color:#667085;font-size:21rpx;line-height:1.55}.step-input-tip{display:block;margin-top:9rpx;color:var(--tm-brand-600);font-size:19rpx}.rule-row{display:flex;align-items:flex-start;justify-content:space-between;gap:24rpx;padding:19rpx 0;border-bottom:1rpx solid #f0f2f5}.rule-row:last-child{padding-bottom:0;border-bottom:0}.rule-label{color:#667085;font-size:22rpx}.rule-value{text-align:right;font-size:22rpx;font-weight:700}.rule-copy{margin-top:16rpx;color:#475467;font-size:23rpx;line-height:1.65}.safety-card{margin-top:20rpx;padding:24rpx 26rpx;border-radius:24rpx;background:#edf4ff}.safety-title{color:var(--tm-brand-700);font-size:24rpx;font-weight:800}.safety-copy{margin-top:8rpx;color:#5c6f91;font-size:21rpx;line-height:1.6}.report-link{display:block;padding:28rpx 0;text-align:center;color:#98a2b3;font-size:21rpx}.bottom-space{height:150rpx}.fixed-action{position:fixed;z-index:20;left:0;right:0;bottom:0;display:flex;align-items:center;gap:24rpx;padding:18rpx 28rpx calc(18rpx + env(safe-area-inset-bottom));border-top:1rpx solid var(--tm-line);background:rgba(255,255,255,.96);box-shadow:0 -10rpx 30rpx rgba(29,43,73,.08)}.fixed-reward{min-width:180rpx}.fixed-caption{color:#98a2b3;font-size:18rpx}.fixed-value{margin-top:3rpx;color:#f05f22;font-size:27rpx;font-weight:850}.claim-button{height:82rpx;flex:1;margin:0;border-radius:20rpx;background:var(--tm-brand-600);color:#fff;font-size:26rpx;font-weight:800;line-height:82rpx}.claim-button::after{border:0}.claim-button[disabled]{background:#d0d5dd;color:#fff}@media screen and (min-width:768px){.page{width:750rpx;margin:0 auto}.fixed-action{left:50%;width:750rpx;box-sizing:border-box;transform:translateX(-50%)}}
.task-cover{display:block;width:100%;height:310rpx;margin-bottom:18rpx;border-radius:30rpx;background:#eaf0fa}.step-guide-image{display:block;width:100%;max-height:520rpx;margin-top:16rpx;border-radius:18rpx;background:#f2f4f7}.step-actions{display:flex;gap:12rpx;margin-top:14rpx}.step-action{height:58rpx;margin:0;padding:0 20rpx;border:0;border-radius:14rpx;background:var(--tm-brand-600);color:#fff;font-size:20rpx;line-height:58rpx}.step-action.secondary{border:1rpx solid var(--tm-brand-500);background:#fff;color:var(--tm-brand-700)}.step-action::after{border:0}
</style>
