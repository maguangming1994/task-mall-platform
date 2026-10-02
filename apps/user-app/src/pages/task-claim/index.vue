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
      <view class="subnav">
        <view class="subnav-back" @click="goBack">‹</view>
        <text class="subnav-title">任务提交</text>
        <view class="subnav-right" />
      </view>

      <!-- 任务摘要（task-confirm task-sum 风格） -->
      <view class="card task-sum">
        <view class="sum-icon">{{ categoryMark(claim.category) }}</view>
        <view class="sum-main">
          <text class="sum-title">{{ claim.title }}</text>
          <view class="sum-tags">
            <text class="tag tag-orange">{{ categoryLabel(claim.category) }}</text>
            <text class="tag">{{ taskTypeLabel(claim.task_type) }}</text>
            <text :class="['tag', 'tag-status', `status-${claim.status}`]">{{ claim.execution_status_label || statusText(claim.status) }}</text>
            <text v-if="claim.review_status_label" class="tag tag-review">{{ claim.review_status_label }}</text>
          </view>
        </view>
        <text class="sum-pts">{{ rewardText(claim.rewards[0]) }}</text>
      </view>

      <view class="card metric-strip">
        <view><text class="metric-value">{{ completionHours }}</text><text class="metric-label">小时内完成</text></view>
        <view><text class="metric-value">{{ reviewHours }}</text><text class="metric-label">小时内审核</text></view>
        <view><text class="metric-value">{{ claim.submission_count }}/{{ claim.submission_limit }}</text><text class="metric-label">已用提交次数</text></view>
      </view>

      <view class="card dual-state-card"><view><text>执行状态</text><strong>{{ claim.execution_status_label || statusText(claim.status) }}</strong></view><view><text>审核状态</text><strong>{{ claim.review_status_label || '尚未提交审核' }}</strong></view></view>

      <view class="workflow-guide">
        <view><text class="workflow-eyebrow">下一步</text><text class="workflow-title">{{ primaryActionLabel }}</text></view>
        <text class="workflow-copy">{{ claim.workflow?.message || fallbackWorkflowMessage }}</text>
      </view>

      <view v-if="claim.progress && claim.progress.target > 1" class="card cycle-progress-card"><view class="reward-section-head"><view><text class="card-title">本周期成长进度</text><text class="reward-overview">{{ cycleWindowText }}</text></view><text class="progress-percent">{{ claim.progress.percent }}%</text></view><view class="cycle-progress-track"><view :style="{ width: `${claim.progress.percent}%` }" /></view><view class="row"><text>当前 {{ claim.progress.current }} / {{ claim.progress.target }}</text><text>{{ claim.progress.percent >= 100 ? '目标已达成，等待结算' : `还差 ${Math.max(0, claim.progress.target - claim.progress.current)}` }}</text></view></view>

      <view :class="['countdown-card', { urgent: remainingMs > 0 && remainingMs < 3600000 }]">
        <text class="countdown-label">{{ editable ? '距离提交截止' : '当前任务状态' }}</text><text class="countdown-value">{{ editable ? countdownText : statusText(claim.status) }}</text>
      </view>

      <view v-if="claim.last_review?.decision === 'reject'" class="review-alert">
        <text class="review-title">上次提交未通过</text><text class="review-copy">{{ claim.last_review.note || '材料不符合任务要求，请根据步骤修改后重新提交。' }}</text>
        <view v-if="failedStepReviews.length" class="failed-step-list"><view v-for="result in failedStepReviews" :key="result.step_id"><strong>{{ result.step_title }}</strong><text>{{ result.note || reviewReasonLabel(result.reason_code) }}</text></view></view>
      </view>

      <view class="card">
        <text class="card-title">任务说明</text><rich-text class="section-copy rich-content" :nodes="richTextContent(claim.description)" />
      </view>

      <view v-if="editable" class="card">
        <view class="section-head"><text class="card-title">任务材料指引</text><text class="section-note">按顺序完成</text></view>
        <view class="material-guide-grid">
          <view class="material-guide-item"><text class="material-guide-number">1</text><text class="material-guide-title">上传现场照片</text><text class="material-guide-copy">清晰、真实、能核验</text></view>
          <view class="material-guide-item"><text class="material-guide-number">2</text><text class="material-guide-title">填写地点与建议</text><text class="material-guide-copy">补充现场文字结果</text></view>
          <view class="material-guide-item"><text class="material-guide-number">3</text><text class="material-guide-title">核对并提交</text><text class="material-guide-copy">确认材料完整后提交</text></view>
          <view class="material-guide-item"><text class="material-guide-number">4</text><text class="material-guide-title">审核与发放</text><text class="material-guide-copy">通过后奖励自动到账</text></view>
        </view>
        <view class="material-tip"><text class="material-tip-icon">ⓘ</text><text>请按任务步骤逐项提交材料；图片需完整可读，文字结果需与实际完成情况一致。</text></view>
      </view>

      <view v-if="editable" class="card steps-card">
        <text class="card-title">任务步骤 / 提交材料</text>
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

      <view v-else class="card">
        <text class="card-title">提交与审核进度</text>
        <view v-for="submission in submissions" :key="submission.id" class="submission-row"><view><text class="submission-title">第 {{ submission.submission_no }} 次提交</text><text class="submission-time">{{ formatTime(submission.submitted_at) }}</text></view><text class="submission-status">{{ statusText(submission.status) }}</text></view>
        <text v-if="submissions.length === 0" class="section-copy">尚未提交任务材料。</text>
      </view>

      <view class="card reward-delivery-card">
        <view class="reward-section-head"><view><text class="card-title">奖励到账进度</text><text class="reward-overview">{{ rewardOverviewText }}</text></view><text :class="['reward-overall-status', `reward-overall-${claimRewards?.reward_status || claim.reward_status}`]">{{ rewardOverallStatusText(claimRewards?.reward_status || claim.reward_status) }}</text></view>
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

      <!-- 审核须知（task-confirm notice-list 风格） -->
      <view class="card">
        <view class="section-head"><text class="card-title">审核规则</text><text class="section-note">提交前必读</text></view>
        <view class="notice-list">
          <view class="notice-li"><text class="notice-check">✓</text><text>提交后进入审核队列，平台按步骤逐项核验</text></view>
          <view class="notice-li"><text class="notice-check">✓</text><text>审核通过后奖励自动发放，各类奖励独立到账</text></view>
          <view class="notice-li"><text class="notice-check">✓</text><text>材料需真实、完整且清晰可核验；不通过时可按原因修改后重新提交</text></view>
        </view>
      </view>

      <view class="action-links"><text v-if="canCancel" @click="cancelCurrent">放弃任务</text><text v-if="canWithdraw" @click="withdrawCurrent">撤回本次提交</text><text v-if="canAppeal" @click="appealCurrent">对审核结果申诉</text></view>
      <view class="bottom-space" />

      <!-- 申诉弹层（task-confirm sheet 风格） -->
      <view v-if="appealVisible" class="sheet-mask" @click.self="appealVisible=false">
        <view class="sheet">
          <view class="sheet-grip" />
          <text class="sheet-h3">提交任务申诉</text>
          <text class="sheet-subtitle">说明审核结果存在的问题，并上传能支持申诉的截图。</text>
          <textarea v-model="appealReason" class="sheet-textarea" maxlength="1000" placeholder="请具体说明申诉原因、任务完成过程和希望重新核查的内容" />
          <view class="appeal-evidence"><view v-for="(url,index) in appealPreviews" :key="url" class="appeal-image"><image :src="url" mode="aspectFill" @click="previewAppealImage(url)"/><text @click="removeAppealImage(index)">删除</text></view><view v-if="appealFiles.length<6" class="appeal-upload" @click="uploadAppealImage"><text>＋</text><small>{{ appealUploading ? '上传中…' : '添加申诉凭证' }}</small></view></view>
          <view class="notice-list">
            <view class="notice-li"><text class="notice-check">✓</text><text>最多 6 张凭证；提交后由其他审核人员复核</text></view>
            <view class="notice-li"><text class="notice-check">✓</text><text>原任务材料和本次申诉凭证都会保留</text></view>
          </view>
          <view class="sheet-btns">
            <button class="cancel" @click="appealVisible=false">我再想想</button>
            <button class="ok" :loading="appealSubmitting" @click="submitAppeal">提交申诉</button>
          </view>
        </view>
      </view>

      <!-- 底部固定提交栏 -->
      <view v-if="editable" class="action-bar"><view class="ab-count"><text class="submit-caption">剩余时间</text><text class="submit-countdown">{{ countdownText }}</text></view><button class="ab-main" :loading="submitting" @click="submitCurrent">{{ primaryActionLabel }}</button></view>
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
.page{min-height:100vh;padding:0 28rpx 200rpx;box-sizing:border-box;background:#F4F6F4}
.state-text{padding:180rpx 0;text-align:center;color:#AEB3BB;font-size:14px}

/* 顶部白色导航 */
.subnav{display:flex;align-items:center;justify-content:space-between;height:88rpx;margin:0 -28rpx;padding:0 28rpx;background:#fff}
.subnav-back{display:flex;align-items:center;justify-content:center;width:64rpx;height:64rpx;border-radius:50%;background:#F4F6F4;color:#191B1F;font-size:22px;font-weight:800}
.subnav-title{flex:1;text-align:center;color:#191B1F;font-size:17px;font-weight:800}
.subnav-right{width:64rpx}

/* 加载骨架 */
.claim-loading{padding-top:20rpx}
.claim-skeleton{border-radius:32rpx;background:linear-gradient(100deg,#EDEFEF 30%,#F8FAF8 45%,#EDEFEF 60%);background-size:220% 100%;animation:claim-skeleton-shimmer 1.25s ease-in-out infinite}
.claim-skeleton-summary{height:150rpx}
.claim-skeleton-card{height:220rpx;margin-top:20rpx}
.claim-skeleton-card.short{height:140rpx}
@keyframes claim-skeleton-shimmer{0%{background-position:100% 0}100%{background-position:-100% 0}}

/* 异常 / 登录态 */
.state-error{display:flex;align-items:center;flex-direction:column;margin:120rpx 12rpx 0;padding:48rpx 32rpx;border-radius:32rpx;background:#fff;box-shadow:0 2rpx 4rpx rgba(23,26,23,.03),0 12rpx 40rpx rgba(23,26,23,.05);text-align:center}
.state-error-icon{display:flex;width:64rpx;height:64rpx;align-items:center;justify-content:center;border-radius:22rpx;background:#FFF0EE;color:#FF3B30;font-size:19px;font-weight:900}
.state-error-title{margin-top:18rpx;color:#191B1F;font-size:17px;font-weight:900}
.state-error-copy{margin-top:8rpx;color:#767C85;font-size:13px;line-height:1.5}
.state-error-action{height:64rpx;margin-top:24rpx;padding:0 32rpx;border:0;border-radius:999rpx;background:linear-gradient(135deg,#12D978,#08A152);color:#fff;font-size:14px;font-weight:800;line-height:64rpx}
.state-error-action::after{border:0}
.login-required{margin-top:120rpx}

/* 通用卡片 */
.card{margin-top:20rpx;padding:26rpx;border-radius:32rpx;background:#fff;box-shadow:0 2rpx 4rpx rgba(23,26,23,.03),0 12rpx 40rpx rgba(23,26,23,.05)}
.card-title{display:block;margin-bottom:14rpx;color:#191B1F;font-size:17px;font-weight:800}
.section-copy{display:block;margin-top:6rpx;color:#767C85;font-size:14px;line-height:1.7}
.section-head{display:flex;align-items:center;justify-content:space-between;gap:24rpx}
.section-note{color:#AEB3BB;font-size:12px}

/* 任务摘要（task-sum） */
.task-sum{display:flex;align-items:flex-start;gap:20rpx}
.sum-icon{display:flex;align-items:center;justify-content:center;width:96rpx;height:96rpx;flex:none;border-radius:24rpx;background:linear-gradient(135deg,#12D978,#08A152);color:#fff;font-size:21px;font-weight:850}
.sum-main{min-width:0;flex:1}
.sum-title{display:block;color:#191B1F;font-size:16px;font-weight:800;line-height:1.4}
.sum-tags{display:flex;align-items:center;flex-wrap:wrap;gap:10rpx;margin-top:12rpx}
.tag{padding:6rpx 12rpx;border-radius:8rpx;background:#F4F6F4;color:#767C85;font-size:12px}
.tag-orange{background:#FFF3E8;color:#F2620A}
.tag-status{background:#E7F9EF;color:#08A152}
.tag-review{background:#FFF7E6;color:#F2620A;font-weight:800}
.sum-pts{flex:none;color:#FF3B30;font-size:17px;font-weight:800;white-space:nowrap}

/* 指标条 */
.metric-strip{display:grid;grid-template-columns:repeat(3,1fr);padding:24rpx 6rpx}
.metric-strip>view{text-align:center;border-right:1rpx solid #EDEFEF}
.metric-strip>view:last-child{border-right:0}
.metric-value,.metric-label{display:block}
.metric-value{color:#191B1F;font-size:17px;font-weight:850}
.metric-label{margin-top:6rpx;color:#AEB3BB;font-size:12px}

/* 双状态卡 */
.dual-state-card{display:grid;grid-template-columns:1fr 1fr;gap:16rpx}
.dual-state-card view{padding:20rpx;border-radius:20rpx;background:#F4F6F4}
.dual-state-card view:last-child{background:#E7F9EF}
.dual-state-card text,.dual-state-card strong{display:block}
.dual-state-card text{color:#767C85;font-size:13px}
.dual-state-card strong{margin-top:5rpx;color:#191B1F;font-size:14px}

/* 工作流指引 */
.workflow-guide{display:flex;align-items:center;justify-content:space-between;gap:24rpx;margin-top:20rpx;padding:24rpx 26rpx;border-radius:28rpx;background:linear-gradient(135deg,#E7F9EF,#F4F6F4);box-shadow:0 2rpx 4rpx rgba(23,26,23,.03),0 12rpx 40rpx rgba(23,26,23,.05)}
.workflow-guide>view{flex:none}
.workflow-eyebrow,.workflow-title{display:block}
.workflow-eyebrow{color:#08A152;font-size:12px}
.workflow-title{margin-top:5rpx;color:#191B1F;font-size:15px;font-weight:900}
.workflow-copy{max-width:430rpx;color:#767C85;font-size:13px;line-height:1.5}

/* 周期进度 */
.reward-section-head{display:flex;align-items:flex-start;justify-content:space-between;gap:18rpx}
.reward-overview{display:block;margin-top:7rpx;color:#AEB3BB;font-size:13px}
.progress-percent{color:#08A152;font-size:17px;font-weight:900}
.cycle-progress-track{height:14rpx;margin:20rpx 0 12rpx;overflow:hidden;border-radius:999rpx;background:#EDEFEF}
.cycle-progress-track view{height:100%;border-radius:999rpx;background:linear-gradient(135deg,#12D978,#08A152)}
.cycle-progress-card>.row{display:flex;justify-content:space-between;color:#767C85;font-size:13px}

/* 倒计时 */
.countdown-card{display:flex;align-items:center;justify-content:space-between;margin-top:20rpx;padding:22rpx 26rpx;border-radius:24rpx;background:#E7F9EF;color:#08A152}
.countdown-card.urgent{background:#FFF0EE;color:#FF3B30}
.countdown-label{font-size:14px}
.countdown-value{font-size:15px;font-weight:850}

/* 驳回提醒 */
.review-alert{margin-top:20rpx;padding:24rpx 26rpx;border-radius:24rpx;background:#FFF0EE}
.review-title,.review-copy{display:block}
.review-title{color:#FF3B30;font-size:14px;font-weight:800}
.review-copy{margin-top:8rpx;color:#D92D20;font-size:14px;line-height:1.55}
.failed-step-list{display:grid;gap:10rpx;margin-top:16rpx}
.failed-step-list view{padding:14rpx 16rpx;border-radius:14rpx;background:#fff}
.failed-step-list strong,.failed-step-list text{display:block}
.failed-step-list strong{color:#191B1F;font-size:13px}
.failed-step-list text{margin-top:5rpx;color:#D92D20;font-size:13px}

/* 材料指引 */
.material-guide-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14rpx;margin-top:18rpx}
.material-guide-item{display:flex;min-height:150rpx;flex-direction:column;justify-content:space-between;padding:18rpx;border-radius:18rpx;background:#F7F9F8}
.material-guide-number{display:flex;width:36rpx;height:36rpx;align-items:center;justify-content:center;border-radius:12rpx;background:linear-gradient(135deg,#12D978,#08A152);color:#fff;font-size:12px;font-weight:900}
.material-guide-title{margin-top:10rpx;color:#191B1F;font-size:13px;font-weight:800}
.material-guide-copy{margin-top:4rpx;color:#767C85;font-size:11px;line-height:1.35}
.material-tip{display:flex;gap:10rpx;margin-top:16rpx;padding:16rpx 18rpx;border-radius:16rpx;background:#E7F9EF;color:#08A152;font-size:12px;line-height:1.45}
.material-tip-icon{font-size:14px;font-weight:900}

/* 步骤 */
.submission-hint{display:block;margin:10rpx 0 4rpx;padding:16rpx 18rpx;border-radius:16rpx;background:#F7F9F8;color:#767C85;font-size:13px;line-height:1.55}
.submit-step{display:flex;gap:18rpx;padding-top:26rpx}
.step-rail{display:flex;width:52rpx;flex:none;align-items:center;flex-direction:column}
.step-rail>text{display:flex;align-items:center;justify-content:center;width:52rpx;height:52rpx;border-radius:16rpx;background:linear-gradient(135deg,#12D978,#08A152);color:#fff;font-size:14px;font-weight:800}
.step-rail>view{width:2rpx;min-height:160rpx;flex:1;background:#EDEFEF}
.step-locked{opacity:.78}
.step-locked .step-rail>text{background:#E7F9EF;color:#08A152}
.step-content{min-width:0;flex:1;padding-bottom:8rpx}
.step-title-row{display:flex;align-items:center;gap:10rpx}
.step-title{color:#191B1F;font-size:15px;font-weight:850}
.required-badge{padding:3rpx 8rpx;border-radius:7rpx;background:#FFF0EE;color:#FF3B30;font-size:12px}
.passed-badge{margin-left:auto;padding:4rpx 9rpx;border-radius:8rpx;background:#E7F9EF;color:#08A152;font-size:12px;font-weight:800}
.step-description{display:block;margin-top:8rpx;color:#767C85;font-size:14px;line-height:1.55}
.step-guide-image{display:block;width:100%;max-height:520rpx;margin-top:15rpx;border-radius:17rpx;background:#F4F6F4}
.link-actions{display:flex;gap:12rpx;flex-wrap:wrap;margin-top:14rpx}
.mini-action{height:58rpx;margin:0;padding:0 20rpx;border:1rpx solid #0BBF63;border-radius:14rpx;background:#fff;color:#08A152;font-size:13px;line-height:58rpx}
.mini-action.primary{border:0;background:linear-gradient(135deg,#12D978,#08A152);color:#fff}
.mini-action::after{border:0}
.step-textarea{width:100%;height:150rpx;margin-top:15rpx;padding:16rpx;box-sizing:border-box;border:0;border-radius:16rpx;background:#F4F6F4;color:#191B1F;font-size:14px}
.preview-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12rpx;margin-top:15rpx}
.preview-item,.preview-grid .upload-box{position:relative;height:156rpx;min-height:0;margin:0;padding:0;overflow:hidden}
.preview-item{border-radius:15rpx;background:#F4F6F4}
.preview-item image{width:100%;height:100%}
.preview-placeholder{display:flex;align-items:center;justify-content:center;width:100%;height:100%;color:#08A152;font-size:13px}
.preview-item>text{position:absolute;top:6rpx;right:6rpx;padding:4rpx 8rpx;border-radius:7rpx;background:rgba(25,27,31,.72);color:#fff;font-size:12px}
.preview-grid .upload-box{display:flex;align-items:center;justify-content:center;flex-direction:column;border:2rpx dashed #0BBF63;border-radius:15rpx;background:#F7F9F8;text-align:center}
.camera-mark{display:flex;align-items:center;justify-content:center;width:46rpx;height:46rpx;border-radius:14rpx;background:linear-gradient(135deg,#12D978,#08A152);color:#fff;font-size:17px}
.upload-title{display:block;margin-top:8rpx;color:#08A152;font-size:13px;font-weight:800}
.upload-note{display:block;margin-top:4rpx;color:#AEB3BB;font-size:12px}
.check-row{display:flex;align-items:center;gap:12rpx;margin-top:15rpx;color:#767C85;font-size:14px}
.fake-check{display:flex;align-items:center;justify-content:center;width:36rpx;height:36rpx;border:1rpx solid #EDEFEF;border-radius:9rpx;color:transparent}
.fake-check.checked{border-color:#0BBF63;background:#0BBF63;color:#fff}
.locked-step-note{margin-top:13rpx;padding:14rpx 16rpx;border-radius:14rpx;background:#E7F9EF;color:#08A152;font-size:13px;line-height:1.5}

/* 提交记录 */
.submission-row{display:flex;align-items:center;justify-content:space-between;padding:20rpx 0;border-bottom:1rpx solid #EDEFEF}
.submission-row:last-child{border-bottom:0}
.submission-title{display:block;color:#191B1F;font-size:14px;font-weight:750}
.submission-time{display:block;margin-top:5rpx;color:#AEB3BB;font-size:13px}
.submission-status{color:#08A152;font-size:14px}

/* 奖励到账 */
.reward-overall-status{flex:none;padding:7rpx 13rpx;border-radius:999rpx;background:#F4F6F4;color:#767C85;font-size:13px;font-weight:800}
.reward-overall-delivered{background:#E7F9EF;color:#08A152}
.reward-overall-partially_delivered,.reward-overall-delivering,.reward-overall-pending{background:#FFF7E6;color:#F2620A}
.reward-overall-delivery_failed{background:#FFF0EE;color:#FF3B30}
.reward-component-grid{display:grid;gap:14rpx;margin-top:20rpx}
.reward-component{display:flex;align-items:center;gap:16rpx;padding:18rpx;border-radius:19rpx;background:#F7F9F8}
.reward-component-icon{display:flex;align-items:center;justify-content:center;width:62rpx;height:62rpx;flex:none;border-radius:18rpx;background:#E7F9EF;color:#08A152;font-size:14px;font-weight:900}
.reward-component-main{min-width:0;flex:1}
.reward-component-name,.reward-component-amount,.reward-component-copy{display:block}
.reward-component-name{color:#191B1F;font-size:13px;font-weight:800}
.reward-component-amount{margin-top:3rpx;color:#FF3B30;font-size:15px;font-weight:900}
.reward-component-copy{margin-top:4rpx;color:#AEB3BB;font-size:12px}
.reward-status-mark{display:flex;align-items:center;justify-content:center;width:42rpx;height:42rpx;flex:none;border-radius:50%;background:#EDEFEF;color:#767C85;font-size:14px;font-weight:900}
.reward-status-delivered{background:#E7F9EF;color:#08A152}
.reward-status-pending{background:#FFF7E6;color:#F2620A}
.reward-status-failed{background:#FFF0EE;color:#FF3B30}
.reward-notice{margin-top:14rpx;padding:17rpx 19rpx;border-radius:16rpx}
.reward-notice text{display:block;font-size:13px;line-height:1.55}
.reward-notice text:first-child{font-size:13px;font-weight:900}
.reward-notice.processing{background:#E7F9EF;color:#08A152}
.reward-notice.failed{background:#FFF0EE;color:#FF3B30}

/* 须知列表（task-confirm notice-list） */
.notice-list{margin-top:14rpx}
.notice-li{display:flex;gap:12rpx;padding:8rpx 0;color:#767C85;font-size:12px;line-height:1.6}
.notice-check{display:flex;align-items:center;justify-content:center;width:28rpx;height:28rpx;flex:none;margin-top:4rpx;border-radius:50%;background:#E7F9EF;color:#0BBF63;font-size:12px;font-weight:900}

/* 操作链接 */
.action-links{display:flex;justify-content:center;gap:40rpx;padding:32rpx 0;color:#767C85;font-size:14px}
.bottom-space{height:40rpx}

/* 申诉弹层（task-confirm sheet） */
.sheet-mask{position:fixed;z-index:80;inset:0;display:flex;align-items:flex-end;background:rgba(0,0,0,.45)}
.sheet{width:100%;padding:12rpx 32rpx calc(32rpx + env(safe-area-inset-bottom));box-sizing:border-box;border-radius:40rpx 40rpx 0 0;background:#fff;box-shadow:0 -18rpx 48rpx rgba(23,26,23,.16)}
.sheet-grip{width:72rpx;height:8rpx;margin:8rpx auto 20rpx;border-radius:4rpx;background:#E1E4E2}
.sheet-h3{display:block;text-align:center;color:#191B1F;font-size:17px;font-weight:800}
.sheet-subtitle{display:block;margin-top:10rpx;text-align:center;color:#767C85;font-size:13px;line-height:1.5}
.sheet-textarea{width:100%;height:190rpx;margin-top:24rpx;padding:20rpx;box-sizing:border-box;border:0;border-radius:20rpx;background:#F4F6F4;color:#191B1F;font-size:14px;line-height:1.55}
.appeal-evidence{display:grid;grid-template-columns:repeat(3,1fr);gap:14rpx;margin-top:18rpx}
.appeal-image,.appeal-upload{position:relative;height:154rpx;overflow:hidden;border-radius:18rpx;background:#F4F6F4}
.appeal-image image{width:100%;height:100%}
.appeal-image text{position:absolute;right:8rpx;top:8rpx;padding:5rpx 9rpx;border-radius:10rpx;background:rgba(25,27,31,.72);color:#fff;font-size:12px}
.appeal-upload{display:flex;align-items:center;justify-content:center;flex-direction:column;color:#08A152}
.appeal-upload>text{font-size:24px}
.appeal-upload small{font-size:12px}
.sheet-btns{display:flex;gap:20rpx;margin-top:24rpx}
.sheet-btns button{flex:1;height:88rpx;margin:0;border:0;border-radius:44rpx;font-size:15px;font-weight:700;line-height:88rpx}
.sheet-btns button::after{border:0}
.sheet-btns .cancel{background:#F2F4F3;color:#767C85}
.sheet-btns .ok{background:linear-gradient(135deg,#12D978,#08A152);color:#fff;box-shadow:0 12rpx 28rpx rgba(11,191,99,.3)}

/* 底部固定提交栏 */
.action-bar{position:fixed;z-index:20;left:0;right:0;bottom:0;display:flex;align-items:center;gap:20rpx;padding:20rpx 28rpx calc(20rpx + env(safe-area-inset-bottom));border-top:1rpx solid #EDEFEF;background:#fff}
.ab-count{min-width:180rpx}
.submit-caption{display:block;color:#AEB3BB;font-size:12px}
.submit-countdown{display:block;margin-top:3rpx;color:#FF3B30;font-size:13px;font-weight:800}
.ab-main{height:88rpx;flex:1;margin:0;border:0;border-radius:24rpx;background:linear-gradient(135deg,#12D978,#08A152);color:#fff;font-size:15px;font-weight:850;line-height:88rpx}
.ab-main::after{border:0}

@media screen and (min-width:768px){
  .page{width:750rpx;margin:0 auto}
  .action-bar{left:50%;width:750rpx;box-sizing:border-box;transform:translateX(-50%)}
  .sheet{width:750rpx;margin:0 auto}
}
</style>
