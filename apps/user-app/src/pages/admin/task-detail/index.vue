<template>
  <view class="page">
    <view v-if="item" class="body">
      <!-- 任务信息 -->
      <view class="panel">
        <view class="head-row">
          <text class="title">{{ taskTitle }}</text>
          <text :class="['status', `st-${item.status}`]">{{ statusLabel(item.status) }}</text>
        </view>
        <text class="line">提交单号：#{{ item.submission_no }}</text>
        <text class="line">提交时间：{{ formatDateTime(item.submitted_at) || '—' }}</text>
        <text class="line" v-if="item.review_due_at">审核截止：{{ formatDateTime(item.review_due_at) }}<text v-if="item.overdue" class="overdue">（已超期）</text></text>
        <text class="line">当前审核人：{{ item.reviewer?.display_name || '待领取' }}</text>
      </view>

      <!-- 提交内容 -->
      <view class="panel">
        <text class="panel-title">提交内容</text>
        <template v-if="contentRows.length">
          <view v-for="row in contentRows" :key="row.label" class="kv">
            <text class="kv-label">{{ row.label }}</text>
            <text class="kv-value">{{ row.value }}</text>
          </view>
        </template>
        <text v-else class="muted">该提交未填写文字内容。</text>
        <view v-if="item.file_ids.length" class="files">
          <text class="files-text">附件 {{ item.file_ids.length }} 个（移动端暂不支持预览）</text>
          <text class="files-tip">请在电脑端管理后台查看审核附件</text>
        </view>
      </view>

      <!-- 历史审核结果 -->
      <view v-if="item.status === 'approved' || item.status === 'rejected'" class="panel">
        <text class="panel-title">审核结果</text>
        <text class="line">结论：<text :class="item.latest_review?.decision === 'approve' ? 'ok' : 'bad'">{{ item.latest_review?.decision === 'approve' ? '通过' : '驳回' }}</text></text>
        <text v-if="item.latest_review?.note" class="line">说明：{{ item.latest_review.note }}</text>
        <view v-if="(item.latest_review?.step_results || []).length" class="step-result-list">
          <view v-for="(step, idx) in item.latest_review?.step_results" :key="idx" class="step-result-row">
            <text class="sr-step">{{ stepTitle(String(step.step_id)) }}</text>
            <text :class="['sr-decision', step.decision === 'approve' ? 'ok' : 'bad']">{{ step.decision === 'approve' ? '通过' : '驳回' }}</text>
          </view>
        </view>
      </view>

      <!-- 审核中：逐步审核 -->
      <view v-if="item.status === 'reviewing' && mine">
        <view class="panel" v-if="scopeSteps.length">
          <text class="panel-title">逐步审核（{{ scopeSteps.length }} 步）</text>
          <view v-for="(step, idx) in scopeSteps" :key="step.id" class="step">
            <view class="step-head">
              <text class="step-index">{{ idx + 1 }}</text>
              <text class="step-title">{{ step.title }}</text>
            </view>
            <view class="step-toggle">
              <text :class="['toggle', decisionOf(step.id) === 'approve' && 'toggle-ok']" @click="setDecision(step.id, 'approve')">✓ 通过</text>
              <text :class="['toggle', decisionOf(step.id) === 'reject' && 'toggle-bad']" @click="setDecision(step.id, 'reject')">驳回</text>
            </view>
            <view v-if="decisionOf(step.id) === 'reject'" class="step-reject">
              <picker :range="reasons" range-key="label" @change="onReasonPick(step.id, $event)">
                <view class="picker">
                  {{ reasonOf(step.id)?.label || '请选择驳回原因' }}
                  <text class="picker-arrow">›</text>
                </view>
              </picker>
              <textarea
                class="note-input"
                v-model="notes[step.id]"
                maxlength="500"
                placeholder="请填写补交说明（学生将看到此内容）"
              />
            </view>
          </view>
        </view>

        <view class="panel" v-else>
          <text class="panel-title">审核结论</text>
          <text class="muted">该任务无分步要求，请直接给出审核结论；驳回时需选择原因并填写说明。</text>
          <view class="step-toggle" style="margin-top:20rpx">
            <text :class="['toggle', !overallReject && 'toggle-ok']" @click="overallReject = false">✓ 审核通过</text>
            <text :class="['toggle', overallReject && 'toggle-bad']" @click="overallReject = true">驳回提交</text>
          </view>
          <view v-if="overallReject" class="step-reject">
            <picker :range="reasons" range-key="label" @change="onReasonPick('__overall__', $event)">
              <view class="picker">
                {{ reasonOf('__overall__')?.label || '请选择驳回原因' }}
                <text class="picker-arrow">›</text>
              </view>
            </picker>
            <textarea class="note-input" v-model="notes.__overall__" maxlength="500" placeholder="请填写驳回说明" />
          </view>
        </view>

        <view class="footer two">
          <button class="btn ghost" :disabled="submitting" @click="onRelease">释放审核</button>
          <button class="btn primary" :disabled="submitting" @click="onSubmit">
            {{ canApprove ? '全部通过，发奖' : '确认提交审核结果' }}
          </button>
        </view>
      </view>

      <!-- 审核中但不是自己领取的 -->
      <view v-else-if="item.status === 'reviewing'" class="panel locked">
        <text class="panel-title">该提交审核中</text>
        <text class="muted">当前审核人：{{ item.reviewer?.display_name || '—' }}。如需转交或接管，请在电脑端管理后台操作。</text>
      </view>

      <!-- 待领取 -->
      <view v-if="item.status === 'submitted'" class="footer">
        <button class="btn primary big" :disabled="submitting" @click="onStart">领取审核</button>
      </view>
    </view>

    <view v-else class="loading-box">{{ loadError ? '加载失败，请返回重试' : '加载中…' }}</view>
  </view>
</template>

<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import { onLoad } from '@dcloudio/uni-app'
import { ApiError } from '@/api/http'
import {
  fetchTaskSubmissions,
  fetchTaskReviewReasons,
  startTaskReview,
  releaseTaskReview,
  reviewTaskSubmission,
  type TaskSubmissionItem,
  type TaskStep,
  type TaskReviewReasonItem,
} from '@/api/admin/review'
import { useAdminStore } from '@/stores/admin'
import { formatDateTime } from '../format'

const admin = useAdminStore()
const item = ref<TaskSubmissionItem | null>(null)
const taskSteps = ref<TaskStep[]>([])
const reasons = ref<TaskReviewReasonItem[]>([])
const submitting = ref(false)
const loadError = ref(false)

const decisions = reactive<Record<string, 'approve' | 'reject'>>({})
const reasonCodes = reactive<Record<string, string>>({})
const notes = reactive<Record<string, string>>({})

const taskTitle = computed(() => item.value?.task_title || '任务提交')

const mine = computed(() => !item.value?.reviewer || item.value.reviewer.id === admin.profile?.id)

// 本次审核范围：补交场景只审 revision_step_ids；首审审全部步骤
const scopeSteps = computed(() => {
  if (!item.value) return []
  const revisions = item.value.revision_step_ids || []
  if (revisions.length) {
    return taskSteps.value.filter(step => revisions.includes(step.id))
  }
  return taskSteps.value
})

const overallReject = ref(false)

const canApprove = computed(() => {
  if (!scopeSteps.value.length) return !overallReject.value
  return scopeSteps.value.length > 0 && scopeSteps.value.every(step => decisions[step.id] === 'approve')
})

const contentRows = computed(() => {
  const content = item.value?.content
  if (!content || typeof content !== 'object') return []
  const rows: Array<{ label: string; value: string }> = []
  const source = (content.fields && typeof content.fields === 'object') ? content.fields as Record<string, unknown> : content
  Object.entries(source).forEach(([key, value]) => {
    if (key === 'step_files' || key.includes('file')) return
    if (value === null || value === undefined) return
    const text = typeof value === 'object' ? JSON.stringify(value) : String(value)
    if (text) rows.push({ label: humanLabel(key), value: text })
  })
  return rows.slice(0, 20)
})

function humanLabel(key: string) {
  return key.replace(/[_-]/g, ' ').replace(/^\w/, c => c.toUpperCase())
}

function stepTitle(stepId: string) {
  return taskSteps.value.find(step => step.id === stepId)?.title || stepId
}

function decisionOf(stepId: string) {
  return decisions[stepId]
}

function reasonOf(key: string) {
  const code = reasonCodes[key]
  return reasons.value.find(reason => reason.code === code)
}

function setDecision(stepId: string, decision: 'approve' | 'reject') {
  decisions[stepId] = decision
}

function onReasonPick(key: string, event: { detail: { value: number | string } }) {
  const idx = Number(event.detail.value)
  const reason = reasons.value[idx]
  if (!reason) return
  reasonCodes[key] = reason.code
  if (!notes[key] && reason.suggested_note) {
    notes[key] = reason.suggested_note
  }
}

onLoad((query) => {
  const id = String(query?.id || '')
  if (!id || !admin.isLoggedIn) {
    uni.redirectTo({ url: '/pages/admin/login/index' })
    return
  }
  void load(id)
})

function statusLabel(status: string) {
  return { submitted: '待领取', reviewing: '审核中', approved: '已通过', rejected: '已驳回', withdrawn: '已撤回' }[status] || status
}

/** 后端列表没有单条详情接口，用列表接口在各状态里定位该提交 */
async function load(id: string) {
  try {
    const [reasonsResult, ...lists] = await Promise.all([
      fetchTaskReviewReasons(),
      fetchTaskSubmissions({ status: 'submitted' }),
      fetchTaskSubmissions({ status: 'reviewing' }),
      fetchTaskSubmissions({ status: 'approved' }),
      fetchTaskSubmissions({ status: 'rejected' }),
    ])
    reasons.value = reasonsResult.items
    const found = lists.flatMap(result => result.items).find(entry => entry.id === id)
    if (!found) {
      loadError.value = true
      uni.showToast({ title: '未找到该提交单', icon: 'none' })
      return
    }
    applyItem(found)
  } catch (error) {
    loadError.value = true
    if (error instanceof ApiError && (error.code === 'ADMIN_SESSION_EXPIRED' || error.statusCode === 401)) {
      admin.clear()
      uni.redirectTo({ url: '/pages/admin/login/index' })
      return
    }
    uni.showToast({ title: error instanceof Error ? error.message : '加载失败', icon: 'none' })
  }
}

function applyItem(next: TaskSubmissionItem) {
  // 动作接口返回的 present 不含 task_title/task_steps，需与已有数据合并
  const merged: TaskSubmissionItem = {
    ...(item.value || {}),
    ...next,
    task_title: next.task_title ?? item.value?.task_title,
    task_steps: next.task_steps && next.task_steps.length ? next.task_steps : (item.value?.task_steps || []),
  }
  taskSteps.value = merged.task_steps || []
  item.value = merged
}

async function onStart() {
  if (!item.value) return
  submitting.value = true
  try {
    const started = await startTaskReview(item.value.id)
    // 补回当前管理员为审核人
    started.reviewer = { id: admin.profile!.id, display_name: admin.displayName }
    applyItem(started)
    uni.showToast({ title: '已领取，请开始审核', icon: 'success' })
  } catch (error) {
    showError(error)
  } finally {
    submitting.value = false
  }
}

async function onRelease() {
  if (!item.value) return
  const modal = await uni.showModal({
    title: '释放审核',
    editable: true,
    placeholderText: '请填写释放原因（2字以上）',
    confirmText: '确认释放',
    confirmColor: '#B8860B',
  }).catch(() => null)
  if (!modal?.confirm) return
  const reason = String(modal.content || '').trim()
  if (reason.length < 2) {
    uni.showToast({ title: '释放原因不少于 2 个字', icon: 'none' })
    return
  }
  submitting.value = true
  try {
    applyItem(await releaseTaskReview(item.value.id, reason))
    uni.showToast({ title: '已释放，其他审核员可领取', icon: 'success' })
  } catch (error) {
    showError(error)
  } finally {
    submitting.value = false
  }
}

async function onSubmit() {
  if (!item.value) return

  // 无步骤任务
  if (!scopeSteps.value.length) {
    if (!overallReject.value) {
      const confirmed = await uni.showModal({
        title: '确认审核通过？',
        content: '通过后将立即发放任务奖励。',
        confirmText: '确认通过',
        confirmColor: '#08A152',
      }).catch(() => null)
      if (!confirmed?.confirm) return
    } else {
      if (!reasonCodes.__overall__ || !String(notes.__overall__ || '').trim()) {
        uni.showToast({ title: '请选择驳回原因并填写说明', icon: 'none' })
        return
      }
    }
    submitting.value = true
    try {
      const payload = overallReject.value
        ? { decision: 'reject' as const, reason_code: reasonCodes.__overall__, note: String(notes.__overall__ || '').trim() }
        : { decision: 'approve' as const }
      applyItem(await reviewTaskSubmission(item.value.id, payload))
      uni.showToast({ title: '审核完成', icon: 'success' })
    } catch (error) {
      showError(error)
    } finally {
      submitting.value = false
    }
    return
  }

  // 分步任务：每步必须有结论
  const missing = scopeSteps.value.some(step => !decisions[step.id])
  if (missing) {
    uni.showToast({ title: '请完成每一步的审核结论', icon: 'none' })
    return
  }
  const rejectedSteps = scopeSteps.value.filter(step => decisions[step.id] === 'reject')
  for (const step of rejectedSteps) {
    if (!reasonCodes[step.id] || !String(notes[step.id] || '').trim()) {
      uni.showToast({ title: `「${step.title}」需选择驳回原因并填写说明`, icon: 'none' })
      return
    }
  }

  const allApproved = rejectedSteps.length === 0
  const confirmText = allApproved ? '全部通过并发放奖励？' : `有 ${rejectedSteps.length} 步被驳回，将退回学生补交？`
  const confirmed = await uni.showModal({
    title: '确认提交审核结果',
    content: confirmText,
    confirmText: allApproved ? '确认通过' : '确认驳回',
    confirmColor: allApproved ? '#08A152' : '#D14343',
  }).catch(() => null)
  if (!confirmed?.confirm) return

  const stepResults = scopeSteps.value.map(step => {
    const rejected = decisions[step.id] === 'reject'
    return {
      step_id: step.id,
      decision: decisions[step.id],
      ...(rejected ? { reason_code: reasonCodes[step.id], note: String(notes[step.id] || '').trim() } : {}),
    }
  })

  const firstReject = rejectedSteps[0]
  const payload = allApproved
    ? { decision: 'approve' as const, step_results: stepResults }
    : {
        decision: 'reject' as const,
        reason_code: reasonCodes[firstReject.id],
        note: String(notes[firstReject.id] || '').trim(),
        step_results: stepResults,
      }

  submitting.value = true
  try {
    applyItem(await reviewTaskSubmission(item.value.id, payload))
    uni.showToast({ title: '审核完成', icon: 'success' })
  } catch (error) {
    showError(error)
  } finally {
    submitting.value = false
  }
}

function showError(error: unknown) {
  if (error instanceof ApiError && (error.code === 'ADMIN_SESSION_EXPIRED' || error.statusCode === 401)) {
    admin.clear()
    uni.redirectTo({ url: '/pages/admin/login/index' })
    return
  }
  uni.showToast({ title: error instanceof Error ? error.message : '操作失败', icon: 'none' })
}
</script>

<style scoped>
.page{min-height:100vh;padding-bottom:160rpx;background:#F4F6F4}
.body{padding:20rpx 24rpx}
.panel{margin-bottom:20rpx;padding:28rpx;border-radius:24rpx;background:#fff;box-shadow:0 2rpx 4rpx rgba(23,26,23,.03),0 8rpx 28rpx rgba(23,26,23,.04)}
.head-row{display:flex;align-items:flex-start;justify-content:space-between;gap:16rpx;margin-bottom:12rpx}
.title{flex:1;color:#191B1F;font-size:30rpx;font-weight:700;line-height:1.5}
.status{flex:none;padding:6rpx 16rpx;border-radius:16rpx;font-size:20rpx}
.st-submitted{background:#EAF2FF;color:#3B82F6}
.st-reviewing{background:#FFF6DE;color:#B8860B}
.st-approved{background:#E7F9EF;color:#08A152}
.st-rejected{background:#FFEAEA;color:#D14343}
.st-withdrawn{background:#F0F2F0;color:#8A918A}
.line{display:block;color:#50565C;font-size:24rpx;line-height:2;margin-top:4rpx}
.overdue{color:#E54D42;font-weight:700}
.panel-title{display:block;margin-bottom:18rpx;color:#191B1F;font-size:28rpx;font-weight:700}
.muted{color:#AEB3BB;font-size:24rpx;line-height:1.7}
.kv{padding:14rpx 0;border-bottom:1rpx solid #F0F2F0}
.kv-label{display:block;color:#AEB3BB;font-size:20rpx}
.kv-value{display:block;margin-top:6rpx;color:#191B1F;font-size:26rpx;line-height:1.6;white-space:pre-wrap;word-break:break-all}
.files{margin-top:20rpx;padding:20rpx;border-radius:16rpx;background:#F4F6F4}
.files-text{display:block;color:#50565C;font-size:24rpx}
.files-tip{display:block;margin-top:8rpx;color:#AEB3BB;font-size:20rpx}
.step{padding:22rpx 0;border-bottom:1rpx solid #F0F2F0}
.step:last-child{border-bottom:0}
.step-head{display:flex;align-items:center;gap:14rpx}
.step-index{display:flex;align-items:center;justify-content:center;width:40rpx;height:40rpx;border-radius:50%;background:#E7F9EF;color:#08A152;font-size:22rpx;font-weight:800}
.step-title{flex:1;color:#191B1F;font-size:26rpx;font-weight:600;line-height:1.5}
.step-toggle{display:flex;gap:16rpx;margin-top:16rpx}
.toggle{flex:1;text-align:center;padding:18rpx 0;border:2rpx solid #E2E7E2;border-radius:16rpx;color:#767C85;font-size:26rpx;font-weight:700}
.toggle-ok{border-color:#08A152;background:#E7F9EF;color:#08A152}
.toggle-bad{border-color:#E54D42;background:#FFEAEA;color:#D14343}
.step-reject{margin-top:16rpx}
.picker{display:flex;align-items:center;justify-content:space-between;padding:22rpx 24rpx;border-radius:16rpx;background:#F4F6F4;color:#191B1F;font-size:26rpx}
.picker-arrow{color:#AEB3BB;font-size:32rpx}
.note-input{width:100%;box-sizing:border-box;margin-top:14rpx;padding:20rpx;border-radius:16rpx;background:#F4F6F4;color:#191B1F;font-size:24rpx;min-height:140rpx}
.step-result-list{margin-top:12rpx}
.step-result-row{display:flex;align-items:center;justify-content:space-between;padding:12rpx 0}
.sr-step{flex:1;color:#50565C;font-size:24rpx}
.sr-decision{font-size:24rpx;font-weight:700}
.ok{color:#08A152}
.bad{color:#D14343}
.locked{text-align:left}
.footer{position:fixed;left:0;right:0;bottom:0;display:flex;gap:20rpx;padding:20rpx 24rpx calc(20rpx + env(safe-area-inset-bottom));background:#fff;box-shadow:0 -4rpx 20rpx rgba(23,26,23,.06)}
.btn{flex:1;height:88rpx;border-radius:44rpx;font-size:30rpx;font-weight:800;line-height:88rpx}
.btn::after{border:0}
.btn.primary{background:linear-gradient(135deg,#12D978,#08A152);color:#fff}
.btn.ghost{background:#F4F6F4;color:#767C85}
.btn.big{width:100%}
.btn[disabled]{opacity:.6}
.loading-box{padding:200rpx 48rpx;text-align:center;color:#767C85;font-size:26rpx}
</style>
