import { apiRequest } from '@/api/http'
import { buildQueryString } from '@/utils/query'

export interface TaskReward { type: 'points' | 'coupon' | 'wallet_balance'; points?: number; balance_amount?: number; quantity?: number; coupon_template_id?: string }
export interface TaskRewardDelivery {
  id: string
  asset_type: 'points' | 'wallet_cash' | 'coupon'
  amount: number
  status: 'pending' | 'failed' | 'delivered'
  attempt_count: number
  delivered_at: string | null
}
export interface TaskClaimRewardSummary { reward_status: string; items: TaskRewardDelivery[] }
export interface TaskClaimWorkflow {
  primary_action: { code: 'submit' | 'resubmit_failed_steps' | 'withdraw' | 'appeal' | 'view_rewards' | 'wait'; label: string }
  allowed_actions: string[]
  editable_step_ids: string[]
  locked_step_ids: string[]
  revision_only: boolean
  message: string
}
export interface TaskStepReviewResult { step_id: string; step_title: string; decision: 'approve' | 'reject'; reason_code: string | null; note: string | null }
export type TaskStepKind = 'instruction' | 'link' | 'qr_code' | 'copy_text' | 'image_guide' | 'verification_image' | 'form_input' | 'confirmation'
export interface TaskStep { id: string; kind?: TaskStepKind; title: string; description?: string | null; input_type: 'none' | 'text' | 'image' | 'text_image' | 'link'; required: boolean; link_url?: string | null; copy_text?: string | null; example_image_url?: string | null; button_text?: string | null; placeholder?: string | null; min_files?: number; max_files?: number }
export interface HallTask {
  id: string
  title: string
  description: string
  description_text?: string
  cover_image_url?: string | null
  category: string
  task_type: string
  tags: string[]
  participation_mode: string
  rewards: TaskReward[]
  rules: Array<string | Record<string, unknown>>
  steps: TaskStep[]
  remaining_reward_slots: number
  completed_user_count: number
  pass_rate_percent: number | null
  average_completion_minutes: number | null
  average_review_minutes: number | null
  view_count: number
  completion_window_hours: number
  submission_limit: number
  review_deadline_hours: number
  appeal_deadline_days: number
  claim_ends_at: string
  submit_ends_at: string
  published_at?: string | null
  location_text?: string | null
  urgent?: boolean
  bounty?: boolean
  gallery?: string[]
  publisher: { id?: string; publisher_type?: string; display_name: string; publisher_badge: string; verification_status: string; avatar_url?: string | null; credit_score?: number | null }
  can_claim: boolean
  claim_unavailable_reason: string | null
}
export interface WithdrawalNotice { id: string; user_masked: string; withdrawal_amount: number; completed_at: string }
export interface TaskActivityEvent {
  id: string
  type: 'completed' | 'published'
  actor_masked: string
  action_text: string
  task_title: string
  reward_text: string
  occurred_at: string
}
export interface HallListResponse {
  items: HallTask[]
  withdrawal_notices: WithdrawalNotice[]
  activity_feed: TaskActivityEvent[]
  pagination: { page: number; page_size: number; total: number }
}
export type HallSort = 'default' | 'latest' | 'reward'
export interface TaskClaim {
  id: string
  task_id: string
  title: string
  description: string
  description_text?: string
  category: string
  task_type: string
  tags: string[]
  steps: TaskStep[]
  rules: Array<string | Record<string, unknown>>
  attachment_rules: Record<string, unknown>
  enrollment_source: string
  cycle_key: string
  cycle_starts_at?: string | null
  cycle_ends_at?: string | null
  current_cycle?: { key: string; starts_at: string | null; ends_at: string | null; progress_target: number; progress_event_type: string | null }
  progress?: { current: number; target: number; percent: number; last_progress_at: string | null }
  status: string
  execution_status: string
  execution_status_label: string
  review_status: string | null
  review_status_label: string | null
  workflow: TaskClaimWorkflow
  slot_status: string
  cancel_count: number
  submission_count: number
  submission_limit: number
  reward_status: string
  appeal_count: number
  appeal_limit: number
  completion_deadline_at: string
  rewards: TaskReward[]
  created_at: string
  current_submission: { id: string; submission_no: number; status: string; submitted_at: string; review_due_at: string; review_started_at: string | null; revision_step_ids: string[] } | null
  last_review: { decision: string; reason_code: string | null; note: string | null; reviewed_at: string; step_results: TaskStepReviewResult[] } | null
}

export function listHallTasks(filters: { category?: string; claimable_only?: boolean; keyword?: string; sort?: HallSort } = {}) {
  const suffix = buildQueryString({
    category: filters.category,
    claimable_only: filters.claimable_only ? 1 : undefined,
    keyword: filters.keyword,
    sort: filters.sort && filters.sort !== 'default' ? filters.sort : undefined,
  })
  return apiRequest<HallListResponse>({ path: `/tasks${suffix ? `?${suffix}` : ''}` })
}
export function getHallTask(taskId: string) { return apiRequest<HallTask>({ path: `/tasks/${taskId}` }) }
export function claimTask(token: string, taskId: string, idempotencyKey = `task-claim-${Date.now()}-${Math.random().toString(36).slice(2)}`) {
  return apiRequest<TaskClaim>({ path: `/tasks/${taskId}/claims`, method: 'POST', accessToken: token, headers: { 'Idempotency-Key': idempotencyKey } })
}
export function listMyTaskClaims(token: string, group = '') {
  return apiRequest<{ items: TaskClaim[] }>({ path: `/task-claims${group ? `?group=${group}` : ''}`, accessToken: token })
}
export function getTaskClaim(token: string, claimId: string) { return apiRequest<TaskClaim>({ path: `/task-claims/${claimId}`, accessToken: token }) }
export function cancelTaskClaim(token: string, claimId: string) {
  return apiRequest<TaskClaim>({ path: `/task-claims/${claimId}/cancel`, method: 'POST', accessToken: token, data: { reason: '用户在未开始任务前主动取消。' }, headers: { 'Idempotency-Key': `task-cancel-${Date.now()}-${Math.random().toString(36).slice(2)}` } })
}

export interface TaskSubmission {
  id: string
  task_claim_id: string
  submission_no: number
  status: string
  content: { text: string | null; fields: Record<string, unknown> }
  file_ids: string[]
  submitted_at: string
  review_due_at: string
}

export function submitTaskClaim(token: string, claimId: string, text: string, fileIds: string[] = [], fields: Record<string, unknown> = {}) {
  return apiRequest<TaskSubmission>({
    path: `/task-claims/${claimId}/submissions`, method: 'POST', accessToken: token,
    data: { text, fields, file_ids: fileIds },
    headers: { 'Idempotency-Key': `task-submit-${Date.now()}-${Math.random().toString(36).slice(2)}` },
  })
}

export function listTaskSubmissions(token: string, claimId: string) {
  return apiRequest<{ items: TaskSubmission[] }>({ path: `/task-claims/${claimId}/submissions`, accessToken: token })
}

export function withdrawTaskSubmission(token: string, submissionId: string) {
  return apiRequest<TaskSubmission>({
    path: `/task-submissions/${submissionId}/withdraw`, method: 'POST', accessToken: token,
    data: { reason: '用户在审核开始前主动撤回材料。' },
    headers: { 'Idempotency-Key': `task-withdraw-${Date.now()}-${Math.random().toString(36).slice(2)}` },
  })
}

export function getTaskClaimRewards(token: string, claimId: string) {
  return apiRequest<TaskClaimRewardSummary>({ path: `/task-claims/${claimId}/rewards`, accessToken: token })
}

export function appealTaskClaim(token: string, claimId: string, reason: string, evidenceFileIds: string[] = []) {
  return apiRequest<{ id: string; status: string }>({
    path: `/task-claims/${claimId}/appeals`, method: 'POST', accessToken: token,
    data: { reason, evidence_file_ids: evidenceFileIds },
    headers: { 'Idempotency-Key': `task-appeal-${Date.now()}-${Math.random().toString(36).slice(2)}` },
  })
}

export function reportTask(token: string, taskId: string, reason: string, description: string) {
  return apiRequest<{ id: string; status: string }>({
    path: `/tasks/${taskId}/reports`, method: 'POST', accessToken: token,
    data: { reason, description, evidence_file_ids: [] },
    headers: { 'Idempotency-Key': `task-report-${Date.now()}-${Math.random().toString(36).slice(2)}` },
  })
}
