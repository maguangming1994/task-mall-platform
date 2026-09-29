<template>
  <view class="page">
    <ModulePlaceholder v-if="modules.resolved && !moduleEnabled" code="module_tasks" />
    <view v-else-if="!modules.resolved" class="mp-skeleton">
      <view class="tm-skeleton" style="height: 200rpx; border-radius: 24rpx; margin: 24rpx 28rpx;" />
      <view class="tm-skeleton" style="height: 320rpx; border-radius: 24rpx; margin: 20rpx 28rpx;" />
    </view>
    <template v-else>
    <view v-if="loading" class="claim-loading"><view class="claim-skeleton claim-skeleton-summary" /><view class="claim-skeleton claim-skeleton-card" /><view class="claim-skeleton claim-skeleton-card short" /></view>
    <view v-else-if="loadError" class="state-error"><text class="state-error-icon">!</text><text class="state-error-title">任务状态加载失败</text><text class="state-error-copy">{{ loadError }}</text><button class="state-error-action" @click="load">重试</button></view>
    <view v-else-if="!auth.accessToken" class="state-error login-required"><text class="state-error-icon">登</text><text class="state-error-title">登录后查看任务</text><text class="state-error-copy">登录后才能查看执行进度、提交材料和审核结果。</text><button class="state-error-action" @click="goLogin">去登录</button></view>
    <view v-else-if="!claim" class="state-text">任务记录不存在</view>
    <template v-else>
      <view class="claim-pagebar">
        <text class="claim-pagebar-back" @click="goBack">‹</text>
        <text class="claim-pagebar-title">任务提交</text>
        <text class="claim-pagebar-placeholder"> </text>
      </view>
      <view class="claim-summary">
        <view class="summary-icon">{{ categoryMark(claim.category) }}</view>
        <view class="summary-main"><view class="summary-title-row"><text class="summary-title">{{ claim.title }}</text><text class="summary-reward">{{ rewardText(claim.rewards[0]) }}</text></view><view class="summary-tags"><text>{{ categoryLabel(claim.category) }}</text><text>{{ taskTypeLabel(claim.task_type) }}</text><text :class="['status-text', `status-${claim.status}`]">{{ claim.execution_status_label || statusText(claim.status) }}</text><text v-if="claim.review_status_label" class="review-state-text">{{ claim.review_status_label }}</text></view></view>
      </view>

      <view class="metric-strip">
        <view><text class="metric-value">{{ completionHours }}</text><text class="metric-label">小时内完成</text></view>
        <view><text class="metric-value">{{ reviewHours }}</text><text class="metric-label">小时内审核</text></view>
        <view><text class="metric-value">{{ claim.submission_count }}/{{ claim.submission_limit }}</text><text class="metric-label">已用提交次数</text></view>
      </view>

      <view class="dual-state-card"><view><text>执行状态</text><strong>{{ claim.execution_status_label || statusText(claim.status) }}</strong></view><view><text>审核状态</text><strong>{{ claim.review_status_label || '尚未提交审核' }}</strong></view></view>

      <view class="workflow-guide">
        <view><text class="workflow-eyebrow">下一步</text><text class="workflow-title">{{ primaryActionLabel }}</text></view>
        <text class="workflow-copy">{{ claim.workflow?.message || fallbackWorkflowMessage }}</text>
      </view>

      <view v-if="claim.progress && claim.progress.target > 1" class="section-card cycle-progress-card"><view class="reward-section-head"><view><text class="section-title">本周期成长进度</text><text class="reward-overview">{{ cycleWindowText }}</text></view><text class="progress-percent">{{ claim.progress.percent }}%</text></view><view class="cycle-progress-track"><view :style="{ width: `${claim.progress.percent}%` }" /></view><view class="row"><text>当前 {{ claim.progress.current }} / {{ claim.progress.target }}</text><text>{{ claim.progress.percent >= 100 ? '目标已达成，等待结算' : `还差 ${Math.max(0, claim.progress.target - claim.progress.current)}` }}</text></view></view>

      <view :class="['countdown-card', { urgent: remainingMs > 0 && remainingMs < 3600000 }]">
        <text class="countdown-label">{{ editable ? '距离提交截止' : '当前任务状态' }}</text><text class="countdown-value">{{ editable ? countdownText : statusText(claim.status) }}</text>
      </view>

      <view v-if="claim.last_review?.decision === 'reject'" class="review-alert">
        <text class="review-title">上次提交未通过</text><text class="review-copy">{{ claim.last_review.note || '材料不符合任务要求，请根据步骤修改后重新提交。' }}</text>
        <view v-if="failedStepReviews.length" class="failed-step-list"><view v-for="result in failedStepReviews" :key="result.step_id"><strong>{{ result.step_title }}</strong><text>{{ result.note || reviewReasonLabel(result.reason_code) }}</text></view></view>
      </view>

      <view class="section-card">
        <text class="section-title">任务说明</text><rich-text class="section-copy rich-content" :nodes="richTextContent(claim.description)" />
      </view>

      <view v-if="editable" class="material-guide-card">
        <view class="section-head"><text class="section-title">任务材料指引</text><text class="section-note">按顺序完成</text></view>
        <view class="material-guide-grid">
          <view class="material-guide-item"><text class="material-guide-number">1</text><text class="material-guide-title">上传现场照片</text><text class="material-guide-copy">清晰、真实、能核验</text></view>
          <view class="material-guide-item"><text class="material-guide-number">2</text><text class="material-guide-title">填写地点与建议</text><text class="material-guide-copy">补充现场文字结果</text></view>
          <view class="material-guide-item"><text class="material-guide-number">3</text><text class="material-guide-title">核对并提交</text><text class="material-guide-copy">确认材料完整后提交</text></view>
          <view class="material-guide-item"><text class="material-guide-number">4</text><text class="material-guide-title">审核与发放</text><text class="material-guide-copy">通过后奖励自动到账</text></view>
        </view>
        <view class="material-tip"><text class="material-tip-icon">ⓘ</text><text>请按任务步骤逐项提交材料；图片需完整可读，文字结果需与实际完成情况一致。</text></view>
      </view>

      <view v-if="editable" class="section-card steps-card">
        <text class="section-title">任务步骤 / 提交材料</text>
        <text class="submission-hint">按步骤提交截图、文字结果或完成确认；已通过步骤会自动沿用，提交次数用尽前请确认凭证完整。</text>
        <view v-for="(step, index) in normalizedSteps" :key="step.id" :class="['submit-step', { 'step-locked': !isStepEditable(step.id) }]">
          <view class="step-rail"><text>{{ index + 1 }}</text><view v-if="index < normalizedSteps.length - 1" /></view>
          <view class="step-content">
            <view class="step-title-row"><text class="step-title">{{ step.title }}</text><text v-if="isStepEditable(step.id) && step.required" class="required-badge">必填</text><text v-if="!isStepEditable(step.id)" class="passed-badge">已通过并沿用</text></view>
            <text v-if="step.description" class="step-description">{{ step.description }}</text>
            <image v-if="step.example_image_url" class="step-guide-image" :src="step.example_image_url" mode="widthFix" @click="previewStepImage(step.example_image_url)" />
            <view v-if="isStepEditable(step.id) && (step.link_url || step.copy_text)" class="link-actions"><button v-if="step.link_url" class="mini-action primary" @click="openStepLink(step.link_url)">{{ step.button_text || '打开链接' }}</button><button class="mini-action" @click="copyStepContent(step.copy_text || step.link_url || '')">复制{{ step.copy_text ? '内容' : '链接' }}</button></view>
            <textarea v-if="isStepEditable(step.id) && ['text', 'text_image'].includes(step.input_type)" v-model="stepFields[step.id]" class="step-textarea" maxlength="5000" :placeholder="step.placeholder || `填写“${step.title}”的完成结果`" />
            <view v-if="isStepEditable(step.id) && ['image', 'text_image'].includes(step.input_type)" class="preview-grid">
              <view v-for="(fileId, fileIndex) in stepFiles[step.id] || []" :key="fileId" class="preview-item" @click="previewUploaded(step.id, fileIndex)">
                <image v-if="stepPreviews[step.id]?.[fileIndex]" :src="stepPreviews[step.id][fileIndex]" mode="aspectFill" />
                <view v-else class="preview-placeholder">已上传</view>
                <text @click.stop="removeStepImage(step.id, fileIndex)">删除</text>
              </view>
              <view v-if="(stepFiles[step.id] || []).length < (step.max_files || 3)" class="upload-box" @click="uploadStepImage(step)"><text class="camera-mark">＋</text><text class="upload-title">上传截图凭证</text><text class="upload-note">{{ (stepFiles[step.id] || []).length }}/{{ step.max_files || 3 }} 张</text></view>
            </view>
            <view v-if="isStepEditable(step.id) && ['none', 'link'].includes(step.input_type)" class="check-row" @click="stepChecks[step.id] = !stepChecks[step.id]"><view :class="['fake-check', { checked: stepChecks[step.id] }]">✓</view><text>我已完成此步骤</text></view>
            <view v-if="!isStepEditable(step.id)" class="locked-step-note">上次审核已通过，本次补交会自动沿用原材料。</view>
          </view>
        </view>
      </view>

      <view v-else class="section-card">
        <text class="section-title">提交与审核进度</text>
        <view v-for="submission in submissions" :key="submission.id" class="submission-row"><view><text class="submission-title">第 {{ submission.submission_no }} 次提交</text><text class="submission-time">{{ formatTime(submission.submitted_at) }}</text></view><text class="submission-status">{{ statusText(submission.status) }}</text></view>
        <text v-if="submissions.length === 0" class="section-copy">尚未提交任务材料。</text>
      </view>

      <view class="section-card reward-delivery-card">
        <view class="reward-section-head"><view><text class="section-title">奖励到账进度</text><text class="reward-overview">{{ rewardOverviewText }}</text></view><text :class="['reward-overall-status', `reward-overall-${claimRewards?.reward_status || claim.reward_status}`]">{{ rewardOverallStatusText(claimRewards?.reward_status || claim.reward_status) }}</text></view>
        <view class="reward-component-grid">
          <view v-for="component in displayedRewardComponents" :key="component.key" :class="['reward-component', `reward-component-${component.status}`]">
            <view class="reward-component-icon">{{ rewardAssetIcon(component.assetType) }}</view>
            <view class="reward-component-main"><text class="reward-component-name">{{ rewardAssetName(component.assetType) }}</text><text class="reward-component-amount">{{ rewardAmountText(component.assetType, component.amount) }}</text><text class="reward-component-copy">{{ rewardDeliveryStatusText(component.status) }}</text></view>
            <view :class="['reward-status-mark', `reward-status-${component.status}`]">{{ rewardStatusMark(component.status) }}</view>
          </view>
        </view>
        <view v-if="rewardHasPending" class="reward-notice processing"><text>奖励正在发放</text><text>各类奖励独立到账，请稍后刷新本页面；处理中不会重复发放。</text></view>
        <view v-if="rewardHasFailed" class="reward-notice failed"><text>部分奖励暂未到账</text><text>系统已进入自动重试队列，任务完成状态不会撤销；多次失败后将由平台人工处理。</text></view>
      </view>

      <view class="section-card review-rules-card">
        <view class="section-head"><text class="section-title">审核规则</text><text class="section-note">提交前必读</text></view>
        <view class="review-timeline">
          <view><text class="review-timeline-no">1</text><text>提交后进入审核队列</text></view>
          <view><text class="review-timeline-no">2</text><text>平台按步骤逐项核验</text></view>
          <view><text class="review-timeline-no">3</text><text>审核通过后奖励自动发放</text></view>
        </view>
        <text class="section-copy">请保证材料真实、完整且清晰可核验；审核不通过时，可按原因修改后重新提交。</text>
      </view>

      <view class="action-links"><text v-if="canCancel" @click="cancelCurrent">放弃任务</text><text v-if="canWithdraw" @click="withdrawCurrent">撤回本次提交</text><text v-if="canAppeal" @click="appealCurrent">对审核结果申诉</text></view>
      <view class="bottom-space" />

      <view v-if="appealVisible" class="appeal-mask" @click.self="appealVisible=false">
        <view class="appeal-sheet">
          <view class="appeal-head"><view><text class="section-title">提交任务申诉</text><text class="appeal-subtitle">说明审核结果存在的问题，并上传能支持申诉的截图。</text></view><text class="appeal-close" @click="appealVisible=false">×</text></view>
          <textarea v-model="appealReason" class="appeal-textarea" maxlength="1000" placeholder="请具体说明申诉原因、任务完成过程和希望重新核查的内容" />
          <view class="appeal-evidence"><view v-for="(url,index) in appealPreviews" :key="url" class="appeal-image"><image :src="url" mode="aspectFill" @click="previewAppealImage(url)"/><text @click="removeAppealImage(index)">删除</text></view><view v-if="appealFiles.length<6" class="appeal-upload" @click="uploadAppealImage"><text>＋</text><small>{{ appealUploading ? '上传中…' : '添加申诉凭证' }}</small></view></view>
          <text class="appeal-note">最多 6 张；提交后由其他审核人员复核，原任务材料和本次凭证都会保留。</text>
          <button class="appeal-submit" :loading="appealSubmitting" @click="submitAppeal">提交申诉</button>
        </view>
      </view>

      <view v-if="editable" class="fixed-submit"><view><text class="submit-caption">剩余时间</text><text class="submit-countdown">{{ countdownText }}</text></view><button class="submit-button" :loading="submitting" @click="submitCurrent">{{ primaryActionLabel }}</button></view>
    </template>
    </template>
  </view>
</template>

<script setup lang="ts">
import { formatDate, formatDateTime as formatTime } from '@/utils/dateTime'
import { onLoad } from '@dcloudio/uni-app'
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { appealTaskClaim, cancelTaskClaim, getTaskClaim, getTaskClaimRewards, listTaskSubmissions, submitTaskClaim, withdrawTaskSubmission, type TaskClaim, type TaskClaimRewardSummary, type TaskReward, type TaskStep, type TaskSubmission } from '@/api/tasks'
import { chooseAndUploadPrivateImage } from '@/api/upload'
import { useAuthStore } from '@/stores/auth'
import { navigateToLogin } from '@/utils/authRedirect'
import { richTextContent } from '@/utils/richText'
import ModulePlaceholder from '@/components/ModulePlaceholder.vue'
import { useModulesStore } from '@/stores/modules'

const modules = useModulesStore()
const moduleEnabled = computed(() => modules.isEnabled('module_tasks'))

const auth = useAuthStore()
const claim = ref<TaskClaim | null>(null)
const submissions = ref<TaskSubmission[]>([])
const claimRewards = ref<TaskClaimRewardSummary | null>(null)
const loading = ref(true)
const loadError = ref('')
const submitting = ref(false)
const uploadingStep = ref('')
const appealVisible = ref(false)
const appealReason = ref('')
const appealFiles = ref<string[]>([])
const appealPreviews = ref<string[]>([])
const appealUploading = ref(false)
const appealSubmitting = ref(false)
const stepFields = ref<Record<string, string>>({})
const stepFiles = ref<Record<string, string[]>>({})
const stepPreviews = ref<Record<string, string[]>>({})
const stepChecks = ref<Record<string, boolean>>({})
const nowEpoch = ref(Date.now())
let claimId = ''
let clock: ReturnType<typeof setInterval> | undefined

const normalizedSteps = computed<TaskStep[]>(() => claim.value?.steps.length ? claim.value.steps : [{ id: 'result', title: '提交任务结果', description: '按照任务说明填写完成情况，并上传必要截图。', input_type: 'text_image', required: true }])
const editable = computed(() => Boolean(claim.value?.workflow?.allowed_actions.some(action => ['submit', 'resubmit_failed_steps'].includes(action)) ?? (claim.value && ['claimed', 'withdrawn', 'rejected'].includes(claim.value.status) && claim.value.submission_count < claim.value.submission_limit && remainingMs.value > 0)))
const editableStepIds = computed(() => claim.value?.workflow?.editable_step_ids?.length ? claim.value.workflow.editable_step_ids : normalizedSteps.value.map(step => step.id))
const failedStepReviews = computed(() => claim.value?.last_review?.step_results?.filter(result => result.decision === 'reject') ?? [])
const primaryActionLabel = computed(() => claim.value?.workflow?.primary_action.label || (claim.value?.status === 'rejected' ? '重新提交审核' : '提交审核'))
const fallbackWorkflowMessage = computed(() => claim.value?.status === 'rejected' ? '请按照驳回原因修改材料后重新提交。' : '请按步骤完成材料并在截止前提交审核。')
const remainingMs = computed(() => claim.value ? new Date(claim.value.completion_deadline_at).getTime() - nowEpoch.value : 0)
const countdownText = computed(() => { const left = Math.max(0, remainingMs.value); const hours = Math.floor(left / 3600000); const minutes = Math.floor((left % 3600000) / 60000); const seconds = Math.floor((left % 60000) / 1000); return `${hours}时${minutes}分${seconds}秒` })
const canCancel = computed(() => Boolean(claim.value?.workflow?.allowed_actions.includes('cancel') ?? (claim.value?.status === 'claimed' && claim.value.cancel_count === 0 && claim.value.submission_count === 0)))
const canWithdraw = computed(() => Boolean(claim.value?.workflow?.allowed_actions.includes('withdraw') ?? (claim.value?.status === 'submitted' && claim.value.current_submission && !claim.value.current_submission.review_started_at)))
const canAppeal = computed(() => Boolean(claim.value?.workflow?.allowed_actions.includes('appeal') ?? (claim.value?.status === 'rejected' && claim.value.submission_count >= claim.value.submission_limit && claim.value.appeal_count < claim.value.appeal_limit)))
const completionHours = computed(() => claim.value ? Math.max(1, Math.ceil((new Date(claim.value.completion_deadline_at).getTime() - new Date(claim.value.created_at).getTime()) / 3600000)) : 0)
const reviewHours = computed(() => claim.value?.current_submission?.review_due_at && claim.value.current_submission.submitted_at ? Math.max(1, Math.ceil((new Date(claim.value.current_submission.review_due_at).getTime() - new Date(claim.value.current_submission.submitted_at).getTime()) / 3600000)) : 48)
const cycleWindowText = computed(() => {
  if (!claim.value?.cycle_starts_at || !claim.value?.cycle_ends_at) return claim.value?.cycle_key === 'milestone' ? '长期里程碑，不自动重置' : '当前周期'
  return `${formatDate(claim.value.cycle_starts_at)} 至 ${formatDate(claim.value.cycle_ends_at)}`
})
const displayedRewardComponents = computed(() => {
  if (claimRewards.value?.items.length) return claimRewards.value.items.map((item) => ({ key: item.id, assetType: item.asset_type, amount: item.amount, status: item.status }))
  return (claim.value?.rewards ?? []).map((reward, index) => ({ key: `expected-${index}`, assetType: reward.type === 'wallet_balance' ? 'wallet_cash' : reward.type, amount: rewardAmount(reward), status: claim.value?.status === 'approved' ? 'pending' : 'locked' }))
})
const rewardHasPending = computed(() => displayedRewardComponents.value.some((item) => item.status === 'pending'))
const rewardHasFailed = computed(() => displayedRewardComponents.value.some((item) => item.status === 'failed'))
const rewardOverviewText = computed(() => {
  const delivered = displayedRewardComponents.value.filter((item) => item.status === 'delivered').length
  return displayedRewardComponents.value.length ? `${delivered}/${displayedRewardComponents.value.length} 项已到账` : '暂无奖励组件'
})

onLoad(async (options) => {
  await modules.ensureReady()
  if (!moduleEnabled.value) return
  claimId = typeof options?.id === 'string' ? options.id : ''; restoreDraft(); await load()
})
onMounted(() => { clock = setInterval(() => { nowEpoch.value = Date.now() }, 1000) })
onUnmounted(() => { if (clock) clearInterval(clock) })
watch([stepFields, stepFiles, stepChecks], persistDraft, { deep: true })

async function load() {
  if (!auth.accessToken || !claimId) { loading.value = false; return }
  loading.value = true
  loadError.value = ''
  try {
    const [claimResult, submissionResult, rewardResult] = await Promise.all([
      getTaskClaim(auth.accessToken, claimId),
      listTaskSubmissions(auth.accessToken, claimId),
      getTaskClaimRewards(auth.accessToken, claimId),
    ])
    claim.value = claimResult
    submissions.value = submissionResult.items
    claimRewards.value = rewardResult
  }
  catch (error) { claim.value = null; loadError.value = error instanceof Error ? error.message : '请检查网络后重试。' }
  finally { loading.value = false }
}

function goLogin() {
  navigateToLogin()
}

async function uploadStepImage(step: TaskStep) {
  const stepId = step.id
  if (!auth.accessToken || uploadingStep.value) return
  if ((stepFiles.value[stepId] || []).length >= (step.max_files || 3)) return uni.showToast({ title: `最多上传 ${step.max_files || 3} 张`, icon: 'none' })
  uploadingStep.value = stepId
  try { const file = await chooseAndUploadPrivateImage(auth.accessToken, 'task_submission'); stepFiles.value[stepId] = [...(stepFiles.value[stepId] || []), file.fileId]; stepPreviews.value[stepId] = [...(stepPreviews.value[stepId] || []), file.previewUrl]; uni.showToast({ title: '截图已添加', icon: 'success' }) }
  catch (error) { uni.showToast({ title: error instanceof Error ? error.message : '图片上传失败', icon: 'none' }) }
  finally { uploadingStep.value = '' }
}

async function submitCurrent() {
  if (!claim.value || !auth.accessToken) return
  const stepsToSubmit = normalizedSteps.value.filter(step => isStepEditable(step.id))
  for (const step of stepsToSubmit) {
    if (!step.required) continue
    if (['text', 'text_image'].includes(step.input_type) && !(stepFields.value[step.id] || '').trim()) return uni.showToast({ title: `请完成：${step.title}`, icon: 'none' })
    if (['image', 'text_image'].includes(step.input_type) && (stepFiles.value[step.id] || []).length < (step.min_files || 1)) return uni.showToast({ title: `请上传：${step.title}`, icon: 'none' })
    if (['none', 'link'].includes(step.input_type) && !stepChecks.value[step.id]) return uni.showToast({ title: `请确认：${step.title}`, icon: 'none' })
  }
  const confirmed = await uni.showModal({ title: '确认提交审核', content: `这是第 ${claim.value.submission_count + 1} 次提交，撤回也不会返还提交次数。` })
  if (!confirmed.confirm) return
  submitting.value = true
  try {
    const submittedIds = new Set(stepsToSubmit.map(step => step.id))
    const submittedFields = Object.fromEntries(Object.entries(stepFields.value).filter(([stepId]) => submittedIds.has(stepId)))
    const submittedChecks = Object.fromEntries(Object.entries(stepChecks.value).filter(([stepId]) => submittedIds.has(stepId)))
    const submittedFiles = Object.fromEntries(Object.entries(stepFiles.value).filter(([stepId]) => submittedIds.has(stepId)))
    const files = Object.values(submittedFiles).flat()
    const text = stepsToSubmit.map((step) => submittedFields[step.id] ? `${step.title}：${submittedFields[step.id]}` : '').filter(Boolean).join('\n')
    await submitTaskClaim(auth.accessToken, claim.value.id, text, files, { steps: submittedFields, completed_steps: submittedChecks, step_files: submittedFiles })
    clearDraft(); uni.showToast({ title: '已提交审核', icon: 'success' }); await load()
  } catch (error) { uni.showToast({ title: error instanceof Error ? error.message : '提交失败', icon: 'none' }) }
  finally { submitting.value = false }
}

function isStepEditable(stepId: string) { return editableStepIds.value.includes(stepId) }
function reviewReasonLabel(value: string | null) { return ({ evidence_unclear: '证明材料不清晰', incomplete: '材料不完整', wrong_content: '提交内容不符合要求', duplicate: '材料重复或已使用', other: '其他原因' } as Record<string, string>)[value || ''] || '请按审核意见补交' }

async function cancelCurrent() { if (!claim.value || !auth.accessToken) return; const result = await uni.showModal({ title: '放弃任务', content: '放弃后将释放奖励名额，本周期不能再次领取。' }); if (!result.confirm) return; try { await cancelTaskClaim(auth.accessToken, claim.value.id); await load() } catch (error) { uni.showToast({ title: error instanceof Error ? error.message : '操作失败', icon: 'none' }) } }
async function withdrawCurrent() { if (!claim.value?.current_submission || !auth.accessToken) return; const result = await uni.showModal({ title: '撤回提交', content: '撤回不会返还本次提交次数，确认继续？' }); if (!result.confirm) return; try { await withdrawTaskSubmission(auth.accessToken, claim.value.current_submission.id); await load() } catch (error) { uni.showToast({ title: error instanceof Error ? error.message : '撤回失败', icon: 'none' }) } }
function appealCurrent(){appealVisible.value=true}
async function uploadAppealImage(){if(!auth.accessToken||appealUploading.value||appealFiles.value.length>=6)return;appealUploading.value=true;try{const file=await chooseAndUploadPrivateImage(auth.accessToken,'task_report');appealFiles.value.push(file.fileId);appealPreviews.value.push(file.previewUrl)}catch(error){uni.showToast({title:error instanceof Error?error.message:'凭证上传失败',icon:'none'})}finally{appealUploading.value=false}}
function removeAppealImage(index:number){appealFiles.value.splice(index,1);appealPreviews.value.splice(index,1)}
function previewAppealImage(url:string){uni.previewImage({urls:appealPreviews.value,current:url})}
async function submitAppeal(){if(!claim.value||!auth.accessToken)return;if(appealReason.value.trim().length<10)return uni.showToast({title:'请至少填写 10 个字的申诉说明',icon:'none'});appealSubmitting.value=true;try{await appealTaskClaim(auth.accessToken,claim.value.id,appealReason.value.trim(),appealFiles.value);appealVisible.value=false;appealReason.value='';appealFiles.value=[];appealPreviews.value=[];uni.showToast({title:'申诉已提交',icon:'success'});await load()}catch(error){uni.showToast({title:error instanceof Error?error.message:'申诉失败',icon:'none'})}finally{appealSubmitting.value=false}}
function copyStepContent(value: string) { uni.setClipboardData({ data: value, success: () => uni.showToast({ title: '已复制', icon: 'success' }) }) }
function goBack() { uni.navigateBack({ delta: 1, fail: () => uni.reLaunch({ url: '/pages/tasks/index' }) }) }
function openStepLink(value: string) {
  // #ifdef H5
  window.open(value, '_blank', 'noopener,noreferrer')
  // #endif
  // #ifndef H5
  copyStepContent(value)
  // #endif
}
function previewStepImage(value: string) { uni.previewImage({ urls: [value], current: value }) }
function previewUploaded(stepId: string, index: number) { const urls = (stepPreviews.value[stepId] || []).filter(Boolean); const current = stepPreviews.value[stepId]?.[index]; if (current && urls.length) uni.previewImage({ urls, current }) }
function removeStepImage(stepId: string, index: number) { stepFiles.value[stepId] = (stepFiles.value[stepId] || []).filter((_, itemIndex) => itemIndex !== index); stepPreviews.value[stepId] = (stepPreviews.value[stepId] || []).filter((_, itemIndex) => itemIndex !== index) }
function draftKey() { return `task_claim_draft_${claimId}` }
function persistDraft() { if (!claimId) return; uni.setStorageSync(draftKey(), { stepFields: stepFields.value, stepFiles: stepFiles.value, stepChecks: stepChecks.value, savedAt: Date.now() }) }
function restoreDraft() { if (!claimId) return; const draft = uni.getStorageSync(draftKey()); if (!draft || typeof draft !== 'object') return; stepFields.value = draft.stepFields || {}; stepFiles.value = draft.stepFiles || {}; stepChecks.value = draft.stepChecks || {} }
function clearDraft() { if (claimId) uni.removeStorageSync(draftKey()) }
function rewardText(reward?: { type: string; points?: number; balance_amount?: number }) { if (!reward) return '任务奖励'; if (reward.type === 'points') return `+${reward.points} 积分`; if (reward.type === 'wallet_balance') return `+¥${((reward.balance_amount || 0) / 100).toFixed(2)}`; return '优惠券奖励' }
function rewardAmount(reward: TaskReward) { if (reward.type === 'points') return reward.points || 0; if (reward.type === 'wallet_balance') return reward.balance_amount || 0; return reward.quantity || 1 }
function rewardAssetName(value: string) { return ({ points: '积分', wallet_cash: '账户余额', coupon: '优惠券' } as Record<string, string>)[value] || '任务奖励' }
function rewardAssetIcon(value: string) { return ({ points: '积', wallet_cash: '¥', coupon: '券' } as Record<string, string>)[value] || '奖' }
function rewardAmountText(type: string, amount: number) { if (type === 'wallet_cash') return `¥${(amount / 100).toFixed(2)}`; if (type === 'coupon') return `${amount} 张`; return `${amount} 积分` }
function rewardDeliveryStatusText(value: string) { return ({ locked: '审核通过后发放', pending: '发放处理中', delivered: '已到账', failed: '发放失败，系统重试中' } as Record<string, string>)[value] || '奖励状态待同步' }
function rewardStatusMark(value: string) { return ({ locked: '待', pending: '…', delivered: '✓', failed: '!' } as Record<string, string>)[value] || '·' }
function rewardOverallStatusText(value: string) { return ({ reserved: '待完成', not_started: '待完成', pending: '发放中', delivering: '发放中', partially_delivered: '部分到账', delivered: '全部到账', delivery_failed: '待重试' } as Record<string, string>)[value] || (claim.value?.status === 'approved' ? '发放中' : '待完成') }
function categoryLabel(value: string) { return ({ onboarding: '新手', daily: '日常', community: '社区', commerce: '商城', research: '调研', campaign: '活动' } as Record<string, string>)[value] || '其他' }
function categoryMark(value: string) { return ({ onboarding: '新', daily: '学', community: '享', commerce: '购', research: '研', campaign: '活' } as Record<string, string>)[value] || '任' }
function taskTypeLabel(value: string) { return ({ one_time: '单次任务', daily: '每日任务', weekly: '每周任务', repeatable: '可重复任务' } as Record<string, string>)[value] || '常规任务' }
function statusText(value: string) { return ({ claimed: '待提交', withdrawn: '待重新提交', submitted: '已提交', reviewing: '审核中', rejected: '未通过', approved: '已完成', cancelled: '已放弃', expired: '已过期', appealed: '申诉中', appeal_reviewing: '申诉审核中', appeal_rejected: '申诉未通过' } as Record<string, string>)[value] || '状态待同步' }
</script>

<style scoped>
.page{min-height:100vh;padding:26rpx 24rpx 0;box-sizing:border-box;background:var(--tm-canvas)}.state-text{padding:180rpx 0;text-align:center;color:#98a2b3}.claim-summary{display:flex;gap:20rpx;padding:26rpx;border-radius:28rpx;background:#fff;border:1rpx solid var(--tm-line)}.summary-icon{display:flex;align-items:center;justify-content:center;width:92rpx;height:92rpx;flex:none;border-radius:26rpx;background:linear-gradient(145deg,#e8f0ff,#f5f8ff);color:var(--tm-brand-700);font-size:21px;font-weight:850}.summary-main{min-width:0;flex:1}.summary-title-row{display:flex;justify-content:space-between;gap:12rpx}.summary-title{font-size:17px;font-weight:850}.summary-reward{flex:none;color:#f05f22;font-size:15px;font-weight:850}.summary-tags{display:flex;align-items:center;gap:10rpx;margin-top:14rpx}.summary-tags>text{padding:6rpx 11rpx;border-radius:8rpx;background:#f4f6f8;color:#667085;font-size:13px}.summary-tags .status-text{margin-left:auto;background:#eef4ff;color:var(--tm-brand-700)}.metric-strip{display:grid;grid-template-columns:repeat(3,1fr);margin-top:18rpx;padding:22rpx 6rpx;border-radius:25rpx;background:#fff;border:1rpx solid var(--tm-line)}.metric-strip>view{text-align:center;border-right:1rpx solid var(--tm-line)}.metric-strip>view:last-child{border-right:0}.metric-value,.metric-label{display:block}.metric-value{font-size:17px;font-weight:850}.metric-label{margin-top:6rpx;color:#98a2b3;font-size:13px}.countdown-card{display:flex;align-items:center;justify-content:space-between;margin-top:18rpx;padding:19rpx 24rpx;border-radius:20rpx;background:#edf4ff;color:var(--tm-brand-700)}.countdown-card.urgent{background:#fff0ed;color:#d92d20}.countdown-label{font-size:14px}.countdown-value{font-size:15px;font-weight:850}.review-alert{margin-top:18rpx;padding:22rpx 24rpx;border:1rpx solid #fecdca;border-radius:20rpx;background:#fff5f4}.review-title,.review-copy,.section-title,.section-copy,.step-description,.upload-title,.upload-note,.submission-title,.submission-time,.submit-caption,.submit-countdown{display:block}.review-title{color:#b42318;font-size:14px;font-weight:800}.review-copy{margin-top:8rpx;color:#d92d20;font-size:14px;line-height:1.55}.section-card{margin-top:18rpx;padding:26rpx;border:1rpx solid var(--tm-line);border-radius:27rpx;background:#fff}.section-title{font-size:17px;font-weight:850}.section-copy{margin-top:16rpx;color:#475467;font-size:14px;line-height:1.7}.submit-step{display:flex;gap:18rpx;padding-top:26rpx}.step-rail{display:flex;width:52rpx;flex:none;align-items:center;flex-direction:column}.step-rail>text{display:flex;align-items:center;justify-content:center;width:52rpx;height:52rpx;border-radius:14rpx;background:var(--tm-brand-600);color:#fff;font-size:14px;font-weight:800}.step-rail>view{width:2rpx;min-height:160rpx;flex:1;background:#d8e5ff}.step-content{min-width:0;flex:1;padding-bottom:8rpx}.step-title-row{display:flex;align-items:center;gap:10rpx}.step-title{font-size:15px;font-weight:850}.required-badge{padding:3rpx 8rpx;border-radius:7rpx;background:#fff0ed;color:#d92d20;font-size:12px}.step-description{margin-top:8rpx;color:#667085;font-size:14px;line-height:1.55}.link-actions{margin-top:14rpx}.mini-action{height:58rpx;margin:0;padding:0 20rpx;border:1rpx solid var(--tm-brand-500);border-radius:14rpx;background:#fff;color:var(--tm-brand-700);font-size:13px;line-height:58rpx}.mini-action::after{border:0}.step-textarea{width:100%;height:150rpx;margin-top:15rpx;padding:16rpx;box-sizing:border-box;border:1rpx solid #d0d5dd;border-radius:15rpx;background:#fbfcfe;font-size:14px}.upload-box{display:flex;align-items:center;flex-direction:column;margin-top:15rpx;padding:25rpx 16rpx;border:2rpx dashed #b6cdfa;border-radius:17rpx;background:#f7faff;text-align:center}.camera-mark{display:flex;align-items:center;justify-content:center;width:54rpx;height:54rpx;border-radius:16rpx;background:var(--tm-brand-600);color:#fff;font-size:21px}.upload-title{margin-top:10rpx;color:var(--tm-brand-700);font-size:14px;font-weight:800}.upload-note{margin-top:5rpx;color:#98a2b3;font-size:12px}.check-row{display:flex;align-items:center;gap:12rpx;margin-top:15rpx;color:#475467;font-size:14px}.fake-check{display:flex;align-items:center;justify-content:center;width:36rpx;height:36rpx;border:2rpx solid #d0d5dd;border-radius:9rpx;color:transparent}.fake-check.checked{border-color:var(--tm-brand-600);background:var(--tm-brand-600);color:#fff}.submission-row{display:flex;align-items:center;justify-content:space-between;padding:20rpx 0;border-bottom:1rpx solid var(--tm-line)}.submission-title{font-size:14px;font-weight:750}.submission-time{margin-top:5rpx;color:#98a2b3;font-size:13px}.submission-status{color:var(--tm-brand-700);font-size:14px}.action-links{display:flex;justify-content:center;gap:30rpx;padding:26rpx;color:#667085;font-size:14px}.bottom-space{height:145rpx}.fixed-submit{position:fixed;z-index:20;left:0;right:0;bottom:0;display:flex;align-items:center;gap:20rpx;padding:17rpx 24rpx calc(17rpx + env(safe-area-inset-bottom));border-top:1rpx solid var(--tm-line);background:rgba(255,255,255,.97)}.fixed-submit>view{min-width:170rpx}.submit-caption{color:#98a2b3;font-size:12px}.submit-countdown{margin-top:3rpx;color:#f04438;font-size:13px;font-weight:800}.submit-button{height:80rpx;flex:1;margin:0;border-radius:19rpx;background:var(--tm-brand-600);color:#fff;font-size:15px;font-weight:850;line-height:80rpx}.submit-button::after{border:0}@media screen and (min-width:768px){.page{width:750rpx;margin:0 auto}.fixed-submit{left:50%;width:750rpx;box-sizing:border-box;transform:translateX(-50%)}}
.step-guide-image{display:block;width:100%;max-height:520rpx;margin-top:15rpx;border-radius:17rpx;background:#f2f4f7}.link-actions{display:flex;gap:12rpx;flex-wrap:wrap}.mini-action.primary{border-color:var(--tm-brand-600);background:var(--tm-brand-600);color:#fff}
.preview-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10rpx;margin-top:15rpx}.preview-item,.preview-grid .upload-box{position:relative;height:156rpx;min-height:0;margin:0;padding:0;overflow:hidden}.preview-item{border:1rpx solid var(--tm-line);border-radius:15rpx;background:var(--tm-surface-soft)}.preview-item image{width:100%;height:100%}.preview-placeholder{display:flex;align-items:center;justify-content:center;width:100%;height:100%;color:var(--tm-brand-700);font-size:13px}.preview-item>text{position:absolute;top:6rpx;right:6rpx;padding:4rpx 8rpx;border-radius:7rpx;background:rgba(7,26,51,.72);color:#fff;font-size:12px}.preview-grid .upload-box{justify-content:center}.preview-grid .camera-mark{width:46rpx;height:46rpx;font-size:17px}.preview-grid .upload-title{font-size:13px}.preview-grid .upload-note{font-size:12px}
.reward-section-head{display:flex;align-items:flex-start;justify-content:space-between;gap:18rpx}.reward-overview{display:block;margin-top:7rpx;color:#98a2b3;font-size:13px}.reward-overall-status{flex:none;padding:7rpx 13rpx;border-radius:999rpx;background:#f2f4f7;color:#475467;font-size:13px;font-weight:800}.reward-overall-delivered{background:#ecfdf3;color:#027a48}.reward-overall-partially_delivered,.reward-overall-delivering,.reward-overall-pending{background:#eff6ff;color:#175cd3}.reward-overall-delivery_failed{background:#fff1f0;color:#b42318}.reward-component-grid{display:grid;gap:12rpx;margin-top:20rpx}.reward-component{display:flex;align-items:center;gap:15rpx;padding:18rpx;border:1rpx solid var(--tm-line);border-radius:19rpx;background:#fbfcfe}.reward-component-icon{display:flex;align-items:center;justify-content:center;width:62rpx;height:62rpx;flex:none;border-radius:18rpx;background:#edf4ff;color:var(--tm-brand-700);font-size:14px;font-weight:900}.reward-component-main{min-width:0;flex:1}.reward-component-name,.reward-component-amount,.reward-component-copy{display:block}.reward-component-name{font-size:13px;font-weight:800}.reward-component-amount{margin-top:3rpx;color:#f05f22;font-size:15px;font-weight:900}.reward-component-copy{margin-top:4rpx;color:#98a2b3;font-size:12px}.reward-status-mark{display:flex;align-items:center;justify-content:center;width:42rpx;height:42rpx;flex:none;border-radius:50%;background:#f2f4f7;color:#667085;font-size:14px;font-weight:900}.reward-status-delivered{background:#dcfae6;color:#079455}.reward-status-pending{background:#eaf2ff;color:#175cd3}.reward-status-failed{background:#fee4e2;color:#d92d20}.reward-notice{margin-top:14rpx;padding:17rpx 19rpx;border-radius:16rpx}.reward-notice text{display:block;font-size:13px;line-height:1.55}.reward-notice text:first-child{font-size:13px;font-weight:900}.reward-notice.processing{background:#eff6ff;color:#175cd3}.reward-notice.failed{background:#fff4ed;color:#b93815}
.appeal-mask{position:fixed;z-index:80;inset:0;display:flex;align-items:flex-end;background:rgba(12,27,43,.42)}.appeal-sheet{width:100%;padding:28rpx 26rpx calc(28rpx + env(safe-area-inset-bottom));box-sizing:border-box;border-radius:34rpx 34rpx 0 0;background:#fff;box-shadow:0 -18rpx 48rpx rgba(16,33,58,.16)}.appeal-head{display:flex;align-items:flex-start;justify-content:space-between;gap:20rpx}.appeal-subtitle{display:block;margin-top:7rpx;color:#7c8798;font-size:13px;line-height:1.5}.appeal-close{padding:0 8rpx;color:#7c8798;font-size:24px}.appeal-textarea{width:100%;height:190rpx;margin-top:22rpx;padding:20rpx;box-sizing:border-box;border:1rpx solid var(--tm-line);border-radius:20rpx;background:#f8fafb;font-size:14px;line-height:1.55}.appeal-evidence{display:grid;grid-template-columns:repeat(3,1fr);gap:12rpx;margin-top:18rpx}.appeal-image,.appeal-upload{position:relative;height:154rpx;overflow:hidden;border:1rpx solid var(--tm-line);border-radius:18rpx;background:#f4f8f7}.appeal-image image{width:100%;height:100%}.appeal-image text{position:absolute;right:8rpx;top:8rpx;padding:5rpx 9rpx;border-radius:10rpx;background:rgba(12,27,43,.72);color:#fff;font-size:12px}.appeal-upload{display:flex;align-items:center;justify-content:center;flex-direction:column;color:var(--tm-brand-700)}.appeal-upload>text{font-size:24px}.appeal-upload small{font-size:12px}.appeal-note{display:block;margin-top:14rpx;color:#8b95a5;font-size:13px;line-height:1.45}.appeal-submit{height:78rpx;margin-top:22rpx;border-radius:20rpx;background:var(--tm-brand-600);color:#fff;font-size:15px;font-weight:850;line-height:78rpx}.appeal-submit::after{border:0}@media screen and (min-width:768px){.appeal-sheet{width:750rpx;margin:0 auto}}
/* Campus V3 */
.page{background:linear-gradient(180deg,var(--tm-campus-lime-soft) 0,var(--tm-canvas) 650rpx)}
.claim-summary,.metric-strip,.section-card{border:2rpx solid var(--tm-ink-900);box-shadow:0 7rpx 0 rgba(22,22,22,.13)}
.claim-summary{background:linear-gradient(135deg,var(--tm-campus-yellow),#ffad6a)}.summary-icon{border:2rpx solid var(--tm-ink-900);background:var(--tm-campus-cyan);color:var(--tm-ink-900)}
.summary-tags>text,.summary-tags .status-text{border:1rpx solid var(--tm-ink-900);background:#fff;color:var(--tm-ink-900)}
.metric-strip{background:#fffef6}.countdown-card,.review-alert{border:2rpx solid var(--tm-ink-900);box-shadow:0 5rpx 0 rgba(22,22,22,.12)}.countdown-card{background:var(--tm-campus-cyan-soft);color:var(--tm-ink-900)}.countdown-card.urgent,.review-alert{background:var(--tm-campus-coral-soft);color:var(--tm-danger)}
.section-card:nth-of-type(even){background:#fffef6}.step-rail>text{border:1rpx solid var(--tm-ink-900);box-shadow:0 3rpx 0 var(--tm-ink-900)}.step-rail>view{background:var(--tm-ink-900)}
.step-textarea,.upload-box,.preview-item{border:2rpx solid var(--tm-ink-900);background:#fff}.upload-box{box-shadow:0 4rpx 0 rgba(22,22,22,.12)}
.mini-action,.fake-check{border:2rpx solid var(--tm-ink-900)}.mini-action{box-shadow:0 4rpx 0 var(--tm-ink-900)}.fake-check.checked{border-color:var(--tm-ink-900);background:var(--tm-campus-lime);color:var(--tm-ink-900)}
.reward-component{border:2rpx solid var(--tm-ink-900);background:#fff}.reward-component-icon{border:1rpx solid var(--tm-ink-900);background:var(--tm-campus-yellow);color:var(--tm-ink-900)}
.fixed-submit{border-top:2rpx solid var(--tm-ink-900);background:#fffdf5}.submit-button{border:2rpx solid var(--tm-ink-900);box-shadow:0 5rpx 0 var(--tm-ink-900)}
.dual-state-card{display:grid;grid-template-columns:1fr 1fr;gap:12rpx;margin-top:18rpx}.dual-state-card view{padding:18rpx;border:2rpx solid var(--tm-ink-900);border-radius:18rpx;background:#fff}.dual-state-card view:last-child{background:var(--tm-campus-yellow-soft)}.dual-state-card text,.dual-state-card strong{display:block}.dual-state-card text{color:var(--tm-ink-500);font-size:13px}.dual-state-card strong{margin-top:5rpx;color:var(--tm-ink-900);font-size:14px}.review-state-text{border:1rpx solid #c89200!important;background:var(--tm-campus-yellow-soft)!important;color:#725000!important;font-weight:800}.cycle-progress-card{margin-top:18rpx}.progress-percent{color:var(--tm-brand-700);font-size:17px;font-weight:900}.cycle-progress-track{height:14rpx;margin:20rpx 0 12rpx;overflow:hidden;border-radius:999rpx;background:#e7ecf2}.cycle-progress-track view{height:100%;border-radius:999rpx;background:linear-gradient(90deg,var(--tm-campus-lime),var(--tm-campus-cyan))}.cycle-progress-card>.row{display:flex;justify-content:space-between;color:var(--tm-ink-500);font-size:13px}
.workflow-guide{display:flex;align-items:center;justify-content:space-between;gap:24rpx;margin-top:18rpx;padding:22rpx 24rpx;border:2rpx solid var(--tm-ink-900);border-radius:22rpx;background:linear-gradient(135deg,var(--tm-campus-cyan-soft),#fff);box-shadow:0 5rpx 0 rgba(22,22,22,.12)}.workflow-guide>view{flex:none}.workflow-eyebrow,.workflow-title,.workflow-copy{display:block}.workflow-eyebrow{color:var(--tm-ink-500);font-size:12px}.workflow-title{margin-top:5rpx;color:var(--tm-ink-900);font-size:15px;font-weight:900}.workflow-copy{max-width:430rpx;color:var(--tm-ink-700);font-size:13px;line-height:1.5}.failed-step-list{display:grid;gap:10rpx;margin-top:16rpx}.failed-step-list view{padding:14rpx 16rpx;border:1rpx solid #fda29b;border-radius:14rpx;background:#fff}.failed-step-list strong,.failed-step-list text{display:block}.failed-step-list strong{font-size:13px}.failed-step-list text{margin-top:5rpx;color:#b42318;font-size:13px}.step-locked{opacity:.78}.step-locked .step-rail>text{background:#dcfae6;color:#05603a}.passed-badge{margin-left:auto;padding:4rpx 9rpx;border:1rpx solid #75e0a7;border-radius:8rpx;background:#ecfdf3;color:#067647;font-size:12px;font-weight:800}.locked-step-note{margin-top:13rpx;padding:14rpx 16rpx;border-radius:14rpx;background:#ecfdf3;color:#067647;font-size:13px;line-height:1.5}
.page{background:radial-gradient(circle at 93% 2%,rgba(44,194,205,.16),transparent 25%),linear-gradient(180deg,#eefbff 0,#f7fbfd 42%,#f4f8fa 100%)!important}.claim-summary,.metric-strip,.section-card,.workflow-guide,.reward-component{border:1rpx solid #dce9ef!important;box-shadow:0 12rpx 30rpx rgba(34,78,103,.075)!important}.claim-summary{background:linear-gradient(135deg,#fff,#edfaff)!important}.summary-icon{border:0!important;background:linear-gradient(145deg,#dff4ff,#e8fbf1)!important;color:#1577ca}.summary-tags>text,.summary-tags .status-text{border:0!important;background:#eef7fb!important;color:#367087!important}.summary-tags .status-text{background:#e6f8ef!important;color:#07885a!important}.metric-strip{background:rgba(255,255,255,.96)!important}.dual-state-card view{border:1rpx solid #dce9ef;background:#fff}.dual-state-card view:last-child{background:#fff8e8}.workflow-guide{background:linear-gradient(135deg,#eefaff,#f4fff9)!important}.countdown-card,.review-alert{border:1rpx solid #dce9ef!important;box-shadow:0 8rpx 22rpx rgba(34,78,103,.06)!important}.countdown-card{background:#eff8ff;color:#176fca}.countdown-card.urgent{background:#fff3f0;color:#c73d30}.section-card{background:rgba(255,255,255,.97)!important}.step-rail>text{border:0!important;box-shadow:0 7rpx 16rpx rgba(18,104,220,.17)!important;background:linear-gradient(145deg,#1268dc,#20b9c8)!important}.step-rail>view{background:#cfe2eb!important}.step-textarea,.upload-box,.preview-item{border:1rpx solid #c9dde7!important;background:#fbfdfe!important}.upload-box{border-style:dashed!important;box-shadow:none!important}.mini-action,.fake-check{border:1rpx solid #b8d7e8!important}.mini-action{box-shadow:none!important}.fake-check.checked{border-color:#08a967!important;background:#08a967!important;color:#fff}.fixed-submit{border-top:1rpx solid #dce9ef!important;background:rgba(255,255,255,.95)!important;box-shadow:0 -12rpx 32rpx rgba(31,76,101,.09)!important}.submit-button{border:0!important;box-shadow:0 10rpx 22rpx rgba(7,166,101,.18)!important;background:linear-gradient(110deg,#08a967,#0ac180)!important}.reward-component{background:#fbfdfe!important}.reward-component-icon{border:0!important;background:#eaf6ff!important;color:#1577ca}.review-state-text{border:0!important;background:#fff5d5!important;color:#8b6508!important}.state-error{display:flex;align-items:center;flex-direction:column;margin:120rpx 12rpx 0;padding:42rpx 28rpx;border:1rpx solid #f0d1cc;border-radius:28rpx;background:#fff8f6;box-shadow:0 14rpx 30rpx rgba(113,63,55,.08);text-align:center}.state-error-icon{display:flex;width:64rpx;height:64rpx;align-items:center;justify-content:center;border-radius:22rpx;background:#fff0ed;color:#d92d20;font-size:19px;font-weight:900}.state-error-title{margin-top:18rpx;color:#8f2e26;font-size:17px;font-weight:900}.state-error-copy{margin-top:8rpx;color:#a96f68;font-size:13px;line-height:1.5}.state-error-action{height:62rpx;margin-top:24rpx;padding:0 28rpx;border:1rpx solid #e4aaa1;border-radius:999rpx;background:#fff;color:#b53b30;font-size:14px;font-weight:800;line-height:60rpx}.state-error-action::after{border:0}
.claim-loading{padding-top:8rpx}.claim-skeleton{border-radius:28rpx;background:linear-gradient(100deg,#e6f1f5 30%,#f8fcfd 45%,#e6f1f5 60%);background-size:220% 100%;animation:claim-skeleton-shimmer 1.25s ease-in-out infinite}.claim-skeleton-summary{height:150rpx}.claim-skeleton-card{height:220rpx;margin-top:18rpx}.claim-skeleton-card.short{height:140rpx}@keyframes claim-skeleton-shimmer{0%{background-position:100% 0}100%{background-position:-100% 0}}.login-required{margin-top:120rpx}.submission-hint{display:block;margin-top:10rpx;padding:14rpx 16rpx;border-radius:16rpx;background:#eef9fc;color:#668594;font-size:13px;line-height:1.55}
/* V09 任务提交：与 05 领取确认、09 材料指引保持同一套校园卡片节奏。 */
.page{min-height:100vh;padding:0 16px 116px;box-sizing:border-box;background:radial-gradient(circle at 92% 2%,rgba(44,194,205,.16),transparent 28%),linear-gradient(180deg,#eefbff 0,#f7fbfd 42%,#f4f8fa 100%)!important}
.claim-pagebar{position:relative;display:flex;align-items:center;justify-content:space-between;height:52px;margin:0 -16px;padding:0 18px;background:rgba(255,255,255,.96);border-bottom:1px solid #dce9ef;box-sizing:border-box}
.claim-pagebar-back{width:32px;color:#15324a;font-size:32px;line-height:1;text-align:left}
.claim-pagebar-title{color:#15324a;font-size:18px;font-weight:800}
.claim-pagebar-placeholder{width:32px}
.claim-summary,.metric-strip,.section-card,.countdown-card,.review-alert,.workflow-guide,.reward-component{border:1px solid #dce9ef!important;box-shadow:0 12px 30px rgba(34,78,103,.075)!important}
.claim-summary{margin-top:14px;border-radius:22px;background:linear-gradient(135deg,#fff,#edfaff)!important;padding:18px;gap:13px}
.summary-icon{width:54px;height:54px;border:0!important;border-radius:17px;background:linear-gradient(145deg,#dff4ff,#e8fbf1)!important;color:#1577ca;font-size:21px}
.summary-title{font-size:19px}.summary-reward{font-size:17px}.summary-tags{margin-top:9px;gap:6px}.summary-tags>text,.summary-tags .status-text{padding:4px 8px;border:0!important;border-radius:8px;background:#eef7fb!important;color:#367087!important;font-size:12px}.summary-tags .status-text{background:#e6f8ef!important;color:#07885a!important}
.metric-strip{margin-top:12px;padding:14px 4px;border-radius:19px;background:rgba(255,255,255,.96)!important}.metric-value{font-size:18px}.metric-label{font-size:12px;margin-top:3px}
.countdown-card,.review-alert{margin-top:12px;padding:13px 16px;border-radius:16px;box-shadow:0 8px 22px rgba(34,78,103,.06)!important}.countdown-label{font-size:14px}.countdown-value{font-size:16px}.review-title{font-size:15px}.review-copy{font-size:13px}
.section-card{margin-top:12px;padding:17px;border-radius:21px;background:rgba(255,255,255,.97)!important}.section-title{font-size:19px}.section-copy{margin-top:9px;font-size:14px;line-height:1.6}.submit-step{gap:12px;padding-top:18px}.step-rail{width:34px}.step-rail>text{width:34px;height:34px;border:0!important;box-shadow:0 7px 16px rgba(18,104,220,.17)!important;background:linear-gradient(145deg,#1268dc,#20b9c8)!important;font-size:14px}.step-rail>view{background:#cfe2eb!important}.step-title{font-size:16px}.required-badge{font-size:11px;padding:2px 5px}.step-description{font-size:13px}.link-actions{margin-top:9px;gap:6px}.mini-action{height:34px;padding:0 11px;border:1px solid #b8d7e8!important;border-radius:10px;background:#fff;color:#176fca;font-size:12px;line-height:34px;box-shadow:none!important}.step-textarea{height:104px;margin-top:10px;padding:11px;border:1px solid #c9dde7!important;border-radius:12px;background:#fbfdfe!important;font-size:14px}.upload-box{margin-top:10px;padding:17px 11px;border:1px dashed #9bc5d7!important;border-radius:13px;background:#fbfdfe!important;box-shadow:none!important}.camera-mark{width:38px;height:38px;border-radius:12px;font-size:24px}.upload-title{margin-top:7px;font-size:14px}.upload-note{font-size:11px}.check-row{gap:7px;margin-top:10px;font-size:13px}.fake-check{width:22px;height:22px;border:1px solid #b8d7e8!important;border-radius:6px}.fake-check.checked{border-color:#08a967!important;background:#08a967!important;color:#fff}
.preview-grid{gap:7px;margin-top:10px}.preview-item,.preview-grid .upload-box{height:104px;border:1px solid #c9dde7!important;border-radius:12px}.preview-grid .camera-mark{width:32px;height:32px;font-size:20px}.preview-grid .upload-title{font-size:12px}.preview-grid .upload-note{font-size:10px}
.submission-row{padding:13px 0}.submission-title{font-size:14px}.submission-time{font-size:11px}.submission-status{font-size:13px}.action-links{gap:20px;padding:17px;font-size:13px}.bottom-space{height:0}
.fixed-submit{left:0;right:0;bottom:0;padding:11px 16px calc(11px + env(safe-area-inset-bottom));border-top:1px solid #dce9ef!important;background:rgba(255,255,255,.96)!important;box-shadow:0 -12px 32px rgba(31,76,101,.09)!important}.fixed-submit>view{min-width:115px}.submit-caption{font-size:11px}.submit-countdown{font-size:13px}.submit-button{height:48px;border:0!important;border-radius:15px;background:linear-gradient(110deg,#08a967,#0ac180)!important;box-shadow:0 10px 22px rgba(7,166,101,.18)!important;font-size:16px;line-height:48px}
.reward-component{padding:12px;border-radius:15px;background:#fbfdfe!important}.reward-component-icon{width:42px;height:42px;border:0!important;border-radius:13px;background:#eaf6ff!important;color:#1577ca;font-size:15px}.reward-component-name{font-size:13px}.reward-component-amount{font-size:16px}.reward-component-copy{font-size:11px}.reward-status-mark{width:29px;height:29px;font-size:14px}.reward-notice{padding:11px 12px;border-radius:12px}.reward-notice text{font-size:12px}.reward-notice text:first-child{font-size:13px}
.appeal-sheet{padding:20px 16px calc(20px + env(safe-area-inset-bottom));border-radius:25px 25px 0 0}.appeal-textarea{height:132px;margin-top:14px;padding:12px;border-radius:14px;font-size:14px}.appeal-evidence{gap:7px;margin-top:12px}.appeal-image,.appeal-upload{height:104px;border-radius:12px}.appeal-upload>text{font-size:28px}.appeal-upload small{font-size:11px}.appeal-submit{height:46px;margin-top:14px;border-radius:14px;font-size:15px;line-height:46px}
@media screen and (min-width:768px){.page{width:390px;margin:0 auto;padding-left:16px;padding-right:16px}.claim-pagebar{margin-left:-16px;margin-right:-16px}.fixed-submit{left:50%;width:390px;box-sizing:border-box;transform:translateX(-50%)}.appeal-sheet{width:390px;margin:0 auto}}
.section-head{display:flex;align-items:center;justify-content:space-between;gap:12px}.section-note{color:#6b8291;font-size:12px}
.material-guide-card{margin-top:12px;padding:14px;border:1px solid #dce9ef;border-radius:18px;background:linear-gradient(145deg,#fff,#f3fbff);box-shadow:0 10px 24px rgba(34,78,103,.06)}
.material-guide-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px;margin-top:12px}.material-guide-item{display:flex;min-height:78px;flex-direction:column;justify-content:space-between;padding:10px;border:1px solid #d7e8ef;border-radius:13px;background:#fff}.material-guide-number{display:flex;width:22px;height:22px;align-items:center;justify-content:center;border-radius:7px;background:#e5f7ff;color:#1477c8;font-size:12px;font-weight:900}.material-guide-title{margin-top:6px;color:#15324a;font-size:13px;font-weight:800}.material-guide-copy{margin-top:3px;color:#71899a;font-size:11px;line-height:1.35}.material-tip{display:flex;gap:7px;margin-top:10px;padding:9px 10px;border-radius:11px;background:#eef9fc;color:#5d7989;font-size:11px;line-height:1.45}.material-tip-icon{color:#1477c8;font-size:14px;font-weight:900}
.review-rules-card{background:linear-gradient(145deg,#fff,#fffaf0)!important}.review-timeline{display:grid;gap:8px;margin-top:12px}.review-timeline>view{display:flex;align-items:center;gap:8px;padding:8px 9px;border:1px solid #eee5c9;border-radius:11px;background:#fffdf5;color:#405a70;font-size:12px}.review-timeline-no{display:flex;width:22px;height:22px;align-items:center;justify-content:center;flex:none;border-radius:50%;background:#ffe686;color:#735500;font-size:11px;font-weight:900}.review-rules-card .section-copy{font-size:12px;line-height:1.55}
</style>
