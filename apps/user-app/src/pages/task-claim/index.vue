<template>
  <view class="page">
    <view v-if="loading" class="state-text">正在加载任务…</view>
    <view v-else-if="!claim" class="state-text">任务记录不存在</view>
    <template v-else>
      <view class="claim-summary">
        <view class="summary-icon">{{ categoryMark(claim.category) }}</view>
        <view class="summary-main"><view class="summary-title-row"><text class="summary-title">{{ claim.title }}</text><text class="summary-reward">{{ rewardText(claim.rewards[0]) }}</text></view><view class="summary-tags"><text>{{ categoryLabel(claim.category) }}</text><text>{{ taskTypeLabel(claim.task_type) }}</text><text :class="['status-text', `status-${claim.status}`]">{{ statusText(claim.status) }}</text></view></view>
      </view>

      <view class="metric-strip">
        <view><text class="metric-value">{{ completionHours }}</text><text class="metric-label">小时内完成</text></view>
        <view><text class="metric-value">{{ reviewHours }}</text><text class="metric-label">小时内审核</text></view>
        <view><text class="metric-value">{{ claim.submission_count }}/{{ claim.submission_limit }}</text><text class="metric-label">已用提交次数</text></view>
      </view>

      <view :class="['countdown-card', { urgent: remainingMs > 0 && remainingMs < 3600000 }]">
        <text class="countdown-label">{{ editable ? '距离提交截止' : '当前任务状态' }}</text><text class="countdown-value">{{ editable ? countdownText : statusText(claim.status) }}</text>
      </view>

      <view v-if="claim.last_review?.decision === 'reject'" class="review-alert"><text class="review-title">上次提交未通过</text><text class="review-copy">{{ claim.last_review.note || '材料不符合任务要求，请根据步骤修改后重新提交。' }}</text></view>

      <view class="section-card">
        <text class="section-title">任务说明</text><rich-text class="section-copy rich-content" :nodes="richTextContent(claim.description)" />
      </view>

      <view v-if="editable" class="section-card steps-card">
        <text class="section-title">任务步骤 / 提交材料</text>
        <view v-for="(step, index) in normalizedSteps" :key="step.id" class="submit-step">
          <view class="step-rail"><text>{{ index + 1 }}</text><view v-if="index < normalizedSteps.length - 1" /></view>
          <view class="step-content">
            <view class="step-title-row"><text class="step-title">{{ step.title }}</text><text v-if="step.required" class="required-badge">必填</text></view>
            <text v-if="step.description" class="step-description">{{ step.description }}</text>
            <image v-if="step.example_image_url" class="step-guide-image" :src="step.example_image_url" mode="widthFix" @click="previewStepImage(step.example_image_url)" />
            <view v-if="step.link_url || step.copy_text" class="link-actions"><button v-if="step.link_url" class="mini-action primary" @click="openStepLink(step.link_url)">{{ step.button_text || '打开链接' }}</button><button class="mini-action" @click="copyStepContent(step.copy_text || step.link_url || '')">复制{{ step.copy_text ? '内容' : '链接' }}</button></view>
            <textarea v-if="['text', 'text_image'].includes(step.input_type)" v-model="stepFields[step.id]" class="step-textarea" maxlength="5000" :placeholder="step.placeholder || `填写“${step.title}”的完成结果`" />
            <view v-if="['image', 'text_image'].includes(step.input_type)" class="upload-box" @click="uploadStepImage(step)"><text class="camera-mark">＋</text><text class="upload-title">上传截图凭证</text><text class="upload-note">已上传 {{ (stepFiles[step.id] || []).length }} 张，要求 {{ step.min_files || 1 }}–{{ step.max_files || 3 }} 张</text></view>
            <view v-if="['none', 'link'].includes(step.input_type)" class="check-row" @click="stepChecks[step.id] = !stepChecks[step.id]"><view :class="['fake-check', { checked: stepChecks[step.id] }]">✓</view><text>我已完成此步骤</text></view>
          </view>
        </view>
      </view>

      <view v-else class="section-card">
        <text class="section-title">提交与审核进度</text>
        <view v-for="submission in submissions" :key="submission.id" class="submission-row"><view><text class="submission-title">第 {{ submission.submission_no }} 次提交</text><text class="submission-time">{{ formatTime(submission.submitted_at) }}</text></view><text class="submission-status">{{ statusText(submission.status) }}</text></view>
        <text v-if="submissions.length === 0" class="section-copy">尚未提交任务材料。</text>
      </view>

      <view class="action-links"><text v-if="canCancel" @click="cancelCurrent">放弃任务</text><text v-if="canWithdraw" @click="withdrawCurrent">撤回本次提交</text><text v-if="canAppeal" @click="appealCurrent">对审核结果申诉</text></view>
      <view class="bottom-space" />

      <view v-if="editable" class="fixed-submit"><view><text class="submit-caption">剩余时间</text><text class="submit-countdown">{{ countdownText }}</text></view><button class="submit-button" :loading="submitting" @click="submitCurrent">提交审核</button></view>
    </template>
  </view>
</template>

<script setup lang="ts">
import { onLoad } from '@dcloudio/uni-app'
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { appealTaskClaim, cancelTaskClaim, getTaskClaim, listTaskSubmissions, submitTaskClaim, withdrawTaskSubmission, type TaskClaim, type TaskStep, type TaskSubmission } from '@/api/tasks'
import { chooseAndUploadTaskImage } from '@/api/upload'
import { useAuthStore } from '@/stores/auth'
import { richTextContent } from '@/utils/richText'

const auth = useAuthStore()
const claim = ref<TaskClaim | null>(null)
const submissions = ref<TaskSubmission[]>([])
const loading = ref(true)
const submitting = ref(false)
const uploadingStep = ref('')
const stepFields = ref<Record<string, string>>({})
const stepFiles = ref<Record<string, string[]>>({})
const stepChecks = ref<Record<string, boolean>>({})
const nowEpoch = ref(Date.now())
let claimId = ''
let clock: ReturnType<typeof setInterval> | undefined

const normalizedSteps = computed<TaskStep[]>(() => claim.value?.steps.length ? claim.value.steps : [{ id: 'result', title: '提交任务结果', description: '按照任务说明填写完成情况，并上传必要截图。', input_type: 'text_image', required: true }])
const editable = computed(() => Boolean(claim.value && ['claimed', 'withdrawn', 'rejected'].includes(claim.value.status) && claim.value.submission_count < claim.value.submission_limit && remainingMs.value > 0))
const remainingMs = computed(() => claim.value ? new Date(claim.value.completion_deadline_at).getTime() - nowEpoch.value : 0)
const countdownText = computed(() => { const left = Math.max(0, remainingMs.value); const hours = Math.floor(left / 3600000); const minutes = Math.floor((left % 3600000) / 60000); const seconds = Math.floor((left % 60000) / 1000); return `${hours}时${minutes}分${seconds}秒` })
const canCancel = computed(() => Boolean(claim.value?.status === 'claimed' && claim.value.cancel_count === 0 && claim.value.submission_count === 0))
const canWithdraw = computed(() => Boolean(claim.value?.status === 'submitted' && claim.value.current_submission && !claim.value.current_submission.review_started_at))
const canAppeal = computed(() => Boolean(claim.value?.status === 'rejected' && claim.value.submission_count >= claim.value.submission_limit && claim.value.appeal_count < claim.value.appeal_limit))
const completionHours = computed(() => claim.value ? Math.max(1, Math.ceil((new Date(claim.value.completion_deadline_at).getTime() - new Date(claim.value.created_at).getTime()) / 3600000)) : 0)
const reviewHours = computed(() => claim.value?.current_submission?.review_due_at && claim.value.current_submission.submitted_at ? Math.max(1, Math.ceil((new Date(claim.value.current_submission.review_due_at).getTime() - new Date(claim.value.current_submission.submitted_at).getTime()) / 3600000)) : 48)

onLoad(async (options) => { claimId = typeof options?.id === 'string' ? options.id : ''; await load() })
onMounted(() => { clock = setInterval(() => { nowEpoch.value = Date.now() }, 1000) })
onUnmounted(() => { if (clock) clearInterval(clock) })

async function load() {
  if (!auth.accessToken || !claimId) { loading.value = false; return }
  loading.value = true
  try { claim.value = await getTaskClaim(auth.accessToken, claimId); submissions.value = (await listTaskSubmissions(auth.accessToken, claimId)).items }
  catch { claim.value = null }
  finally { loading.value = false }
}

async function uploadStepImage(step: TaskStep) {
  const stepId = step.id
  if (!auth.accessToken || uploadingStep.value) return
  if ((stepFiles.value[stepId] || []).length >= (step.max_files || 3)) return uni.showToast({ title: `最多上传 ${step.max_files || 3} 张`, icon: 'none' })
  uploadingStep.value = stepId
  try { const fileId = await chooseAndUploadTaskImage(auth.accessToken); stepFiles.value[stepId] = [...(stepFiles.value[stepId] || []), fileId]; uni.showToast({ title: '截图已添加', icon: 'success' }) }
  catch (error) { uni.showToast({ title: error instanceof Error ? error.message : '图片上传失败', icon: 'none' }) }
  finally { uploadingStep.value = '' }
}

async function submitCurrent() {
  if (!claim.value || !auth.accessToken) return
  for (const step of normalizedSteps.value) {
    if (!step.required) continue
    if (['text', 'text_image'].includes(step.input_type) && !(stepFields.value[step.id] || '').trim()) return uni.showToast({ title: `请完成：${step.title}`, icon: 'none' })
    if (['image', 'text_image'].includes(step.input_type) && (stepFiles.value[step.id] || []).length < (step.min_files || 1)) return uni.showToast({ title: `请上传：${step.title}`, icon: 'none' })
    if (['none', 'link'].includes(step.input_type) && !stepChecks.value[step.id]) return uni.showToast({ title: `请确认：${step.title}`, icon: 'none' })
  }
  const confirmed = await uni.showModal({ title: '确认提交审核', content: `这是第 ${claim.value.submission_count + 1} 次提交，撤回也不会返还提交次数。` })
  if (!confirmed.confirm) return
  submitting.value = true
  try {
    const files = Object.values(stepFiles.value).flat()
    const text = normalizedSteps.value.map((step) => stepFields.value[step.id] ? `${step.title}：${stepFields.value[step.id]}` : '').filter(Boolean).join('\n')
    await submitTaskClaim(auth.accessToken, claim.value.id, text, files, { steps: stepFields.value, completed_steps: stepChecks.value, step_files: stepFiles.value })
    uni.showToast({ title: '已提交审核', icon: 'success' }); await load()
  } catch (error) { uni.showToast({ title: error instanceof Error ? error.message : '提交失败', icon: 'none' }) }
  finally { submitting.value = false }
}

async function cancelCurrent() { if (!claim.value || !auth.accessToken) return; const result = await uni.showModal({ title: '放弃任务', content: '放弃后将释放奖励名额，本周期不能再次领取。' }); if (!result.confirm) return; try { await cancelTaskClaim(auth.accessToken, claim.value.id); await load() } catch (error) { uni.showToast({ title: error instanceof Error ? error.message : '操作失败', icon: 'none' }) } }
async function withdrawCurrent() { if (!claim.value?.current_submission || !auth.accessToken) return; const result = await uni.showModal({ title: '撤回提交', content: '撤回不会返还本次提交次数，确认继续？' }); if (!result.confirm) return; try { await withdrawTaskSubmission(auth.accessToken, claim.value.current_submission.id); await load() } catch (error) { uni.showToast({ title: error instanceof Error ? error.message : '撤回失败', icon: 'none' }) } }
async function appealCurrent() { if (!claim.value || !auth.accessToken) return; const result = await uni.showModal({ title: '提交申诉', editable: true, placeholderText: '说明为什么需要重新复核' }); if (!result.confirm || !result.content?.trim()) return; try { await appealTaskClaim(auth.accessToken, claim.value.id, result.content.trim()); await load() } catch (error) { uni.showToast({ title: error instanceof Error ? error.message : '申诉失败', icon: 'none' }) } }
function copyStepContent(value: string) { uni.setClipboardData({ data: value, success: () => uni.showToast({ title: '已复制', icon: 'success' }) }) }
function openStepLink(value: string) {
  // #ifdef H5
  window.open(value, '_blank', 'noopener,noreferrer')
  // #endif
  // #ifndef H5
  copyStepContent(value)
  // #endif
}
function previewStepImage(value: string) { uni.previewImage({ urls: [value], current: value }) }
function rewardText(reward?: { type: string; points?: number; balance_amount?: number }) { if (!reward) return '任务奖励'; if (reward.type === 'points') return `+${reward.points} 积分`; if (reward.type === 'wallet_balance') return `+¥${((reward.balance_amount || 0) / 100).toFixed(2)}`; return '优惠券奖励' }
function categoryLabel(value: string) { return ({ onboarding: '新手', daily: '日常', community: '社区', commerce: '商城', research: '调研', campaign: '活动' } as Record<string, string>)[value] || value }
function categoryMark(value: string) { return ({ onboarding: '新', daily: '学', community: '享', commerce: '购', research: '研', campaign: '活' } as Record<string, string>)[value] || '任' }
function taskTypeLabel(value: string) { return ({ one_time: '单次任务', daily: '每日任务', weekly: '每周任务', repeatable: '可重复任务' } as Record<string, string>)[value] || value }
function statusText(value: string) { return ({ claimed: '待提交', withdrawn: '待重新提交', submitted: '已提交', reviewing: '审核中', rejected: '未通过', approved: '已完成', cancelled: '已放弃', expired: '已过期', appealed: '申诉中', appeal_reviewing: '申诉审核中', appeal_rejected: '申诉未通过' } as Record<string, string>)[value] || value }
function formatTime(value: string) { return new Date(value).toLocaleString() }
</script>

<style scoped>
.page{min-height:100vh;padding:26rpx 24rpx 0;box-sizing:border-box;background:var(--tm-canvas)}.state-text{padding:180rpx 0;text-align:center;color:#98a2b3}.claim-summary{display:flex;gap:20rpx;padding:26rpx;border-radius:28rpx;background:#fff;border:1rpx solid var(--tm-line)}.summary-icon{display:flex;align-items:center;justify-content:center;width:92rpx;height:92rpx;flex:none;border-radius:26rpx;background:linear-gradient(145deg,#e8f0ff,#f5f8ff);color:var(--tm-brand-700);font-size:34rpx;font-weight:850}.summary-main{min-width:0;flex:1}.summary-title-row{display:flex;justify-content:space-between;gap:12rpx}.summary-title{font-size:29rpx;font-weight:850}.summary-reward{flex:none;color:#f05f22;font-size:25rpx;font-weight:850}.summary-tags{display:flex;align-items:center;gap:10rpx;margin-top:14rpx}.summary-tags>text{padding:6rpx 11rpx;border-radius:8rpx;background:#f4f6f8;color:#667085;font-size:18rpx}.summary-tags .status-text{margin-left:auto;background:#eef4ff;color:var(--tm-brand-700)}.metric-strip{display:grid;grid-template-columns:repeat(3,1fr);margin-top:18rpx;padding:22rpx 6rpx;border-radius:25rpx;background:#fff;border:1rpx solid var(--tm-line)}.metric-strip>view{text-align:center;border-right:1rpx solid var(--tm-line)}.metric-strip>view:last-child{border-right:0}.metric-value,.metric-label{display:block}.metric-value{font-size:27rpx;font-weight:850}.metric-label{margin-top:6rpx;color:#98a2b3;font-size:18rpx}.countdown-card{display:flex;align-items:center;justify-content:space-between;margin-top:18rpx;padding:19rpx 24rpx;border-radius:20rpx;background:#edf4ff;color:var(--tm-brand-700)}.countdown-card.urgent{background:#fff0ed;color:#d92d20}.countdown-label{font-size:21rpx}.countdown-value{font-size:24rpx;font-weight:850}.review-alert{margin-top:18rpx;padding:22rpx 24rpx;border:1rpx solid #fecdca;border-radius:20rpx;background:#fff5f4}.review-title,.review-copy,.section-title,.section-copy,.step-description,.upload-title,.upload-note,.submission-title,.submission-time,.submit-caption,.submit-countdown{display:block}.review-title{color:#b42318;font-size:23rpx;font-weight:800}.review-copy{margin-top:8rpx;color:#d92d20;font-size:21rpx;line-height:1.55}.section-card{margin-top:18rpx;padding:26rpx;border:1rpx solid var(--tm-line);border-radius:27rpx;background:#fff}.section-title{font-size:29rpx;font-weight:850}.section-copy{margin-top:16rpx;color:#475467;font-size:23rpx;line-height:1.7}.submit-step{display:flex;gap:18rpx;padding-top:26rpx}.step-rail{display:flex;width:52rpx;flex:none;align-items:center;flex-direction:column}.step-rail>text{display:flex;align-items:center;justify-content:center;width:52rpx;height:52rpx;border-radius:14rpx;background:var(--tm-brand-600);color:#fff;font-size:22rpx;font-weight:800}.step-rail>view{width:2rpx;min-height:160rpx;flex:1;background:#d8e5ff}.step-content{min-width:0;flex:1;padding-bottom:8rpx}.step-title-row{display:flex;align-items:center;gap:10rpx}.step-title{font-size:26rpx;font-weight:850}.required-badge{padding:3rpx 8rpx;border-radius:7rpx;background:#fff0ed;color:#d92d20;font-size:16rpx}.step-description{margin-top:8rpx;color:#667085;font-size:21rpx;line-height:1.55}.link-actions{margin-top:14rpx}.mini-action{height:58rpx;margin:0;padding:0 20rpx;border:1rpx solid var(--tm-brand-500);border-radius:14rpx;background:#fff;color:var(--tm-brand-700);font-size:20rpx;line-height:58rpx}.mini-action::after{border:0}.step-textarea{width:100%;height:150rpx;margin-top:15rpx;padding:16rpx;box-sizing:border-box;border:1rpx solid #d0d5dd;border-radius:15rpx;background:#fbfcfe;font-size:23rpx}.upload-box{display:flex;align-items:center;flex-direction:column;margin-top:15rpx;padding:25rpx 16rpx;border:2rpx dashed #b6cdfa;border-radius:17rpx;background:#f7faff;text-align:center}.camera-mark{display:flex;align-items:center;justify-content:center;width:54rpx;height:54rpx;border-radius:16rpx;background:var(--tm-brand-600);color:#fff;font-size:34rpx}.upload-title{margin-top:10rpx;color:var(--tm-brand-700);font-size:22rpx;font-weight:800}.upload-note{margin-top:5rpx;color:#98a2b3;font-size:17rpx}.check-row{display:flex;align-items:center;gap:12rpx;margin-top:15rpx;color:#475467;font-size:21rpx}.fake-check{display:flex;align-items:center;justify-content:center;width:36rpx;height:36rpx;border:2rpx solid #d0d5dd;border-radius:9rpx;color:transparent}.fake-check.checked{border-color:var(--tm-brand-600);background:var(--tm-brand-600);color:#fff}.submission-row{display:flex;align-items:center;justify-content:space-between;padding:20rpx 0;border-bottom:1rpx solid var(--tm-line)}.submission-title{font-size:22rpx;font-weight:750}.submission-time{margin-top:5rpx;color:#98a2b3;font-size:18rpx}.submission-status{color:var(--tm-brand-700);font-size:21rpx}.action-links{display:flex;justify-content:center;gap:30rpx;padding:26rpx;color:#667085;font-size:21rpx}.bottom-space{height:145rpx}.fixed-submit{position:fixed;z-index:20;left:0;right:0;bottom:0;display:flex;align-items:center;gap:20rpx;padding:17rpx 24rpx calc(17rpx + env(safe-area-inset-bottom));border-top:1rpx solid var(--tm-line);background:rgba(255,255,255,.97)}.fixed-submit>view{min-width:170rpx}.submit-caption{color:#98a2b3;font-size:17rpx}.submit-countdown{margin-top:3rpx;color:#f04438;font-size:20rpx;font-weight:800}.submit-button{height:80rpx;flex:1;margin:0;border-radius:19rpx;background:var(--tm-brand-600);color:#fff;font-size:26rpx;font-weight:850;line-height:80rpx}.submit-button::after{border:0}@media screen and (min-width:768px){.page{width:750rpx;margin:0 auto}.fixed-submit{left:50%;width:750rpx;box-sizing:border-box;transform:translateX(-50%)}}
.step-guide-image{display:block;width:100%;max-height:520rpx;margin-top:15rpx;border-radius:17rpx;background:#f2f4f7}.link-actions{display:flex;gap:12rpx;flex-wrap:wrap}.mini-action.primary{border-color:var(--tm-brand-600);background:var(--tm-brand-600);color:#fff}
</style>
