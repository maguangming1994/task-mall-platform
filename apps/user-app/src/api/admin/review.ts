import { adminRequest } from './http'

/* ============ 工作台汇总 ============ */

export interface ReviewSummary {
  second_hand_pending?: number
  community_posts_pending?: number
  community_comments_pending?: number
  community_reports_pending?: number
  task_submissions_pending?: number
  identity_verifications_pending?: number
  materials_pending?: number
  material_reports_pending?: number
  refunds_pending?: number
  second_hand_refunds_pending?: number
  permissions: {
    community: boolean
    task: boolean
    identity?: boolean
    material?: boolean
    after_sale?: boolean
  }
}

export function fetchReviewSummary() {
  return adminRequest<ReviewSummary>({ path: '/admin/review-workbench/summary' })
}

/* ============ 闲置审核 ============ */

export interface SecondHandSeller {
  id: string
  nickname: string
  avatar: string | null
  identity_verified: boolean
  on_sale_count: number
  sold_count: number
  rating_average: number | null
  rating_count: number
}

export interface SecondHandReviewItem {
  id: string
  title: string
  description?: string
  price: number
  price_fen: number
  condition: string
  condition_label: string
  cover: string | null
  images: string[]
  status: string
  audit_status: 'pending' | 'approved' | 'rejected'
  rejected_reason: string | null
  trade_type: string
  location: string | null
  view_count: number
  category: { id: string; name: string } | null
  seller: SecondHandSeller | null
  submitted_at: string | null
  reviewed_at: string | null
  reviewed_by_name: string | null
  created_at?: string
}

export interface Pagination<T> {
  items: T[]
  pagination: { page: number; page_size: number; total: number }
}

export function fetchSecondHandItems(params: { audit_status?: string; page?: number; page_size?: number }) {
  return adminRequest<Pagination<SecondHandReviewItem>>({
    path: '/admin/second-hand/items',
    params: params as Record<string, unknown>,
  })
}

export function fetchSecondHandItem(id: string) {
  return adminRequest<SecondHandReviewItem>({ path: `/admin/second-hand/items/${id}` })
}

export function reviewSecondHandItem(id: string, decision: 'approve' | 'reject', reason?: string) {
  return adminRequest<SecondHandReviewItem>({
    path: `/admin/second-hand/items/${id}/review`,
    method: 'POST',
    data: reason ? { decision, reason } : { decision },
    headers: idempotencyKey(`second-hand-review-${id}-${decision}`),
  })
}

/* ============ 社区审核 ============ */

export interface CommunityPostItem {
  id: string
  title: string
  content: string
  category_code?: string
  category_label?: string
  status: string
  author?: {
    id: string
    nickname: string
    avatar_url?: string | null
    campus_name?: string | null
    verified?: boolean
    is_followed?: boolean
    is_self?: boolean
  }
  created_at?: string
  [key: string]: unknown
}

export interface CommunityCommentItem {
  id: string
  post_id: string | null
  author: { id: string; nickname: string }
  content: string
  status: string
  created_at: string
}

export interface CommunityReportItem {
  id: string
  content_type: 'post' | 'comment'
  content_id: string
  reason_code: string
  description: string | null
  status: 'submitted' | 'handled'
  created_at: string
}

export interface ListEnvelope<T> {
  items: T[]
}

export function fetchCommunityPosts() {
  return adminRequest<ListEnvelope<CommunityPostItem>>({ path: '/admin/community/posts' })
}

export function reviewCommunityPost(id: string, decision: 'approve' | 'reject', note: string, reasonCode?: string) {
  return adminRequest<CommunityPostItem>({
    path: `/admin/community/posts/${id}/review`,
    method: 'POST',
    data: { decision, note, ...(reasonCode ? { reason_code: reasonCode } : {}) },
    // 幂等键只与对象+结论相关，双击/刷新重试返回同一结果，不产生重复审核
    headers: idempotencyKey(`community-post-review-${id}-${decision}`),
  })
}

export function fetchCommunityComments() {
  return adminRequest<ListEnvelope<CommunityCommentItem>>({ path: '/admin/community/comments' })
}

export function reviewCommunityComment(id: string, decision: 'approve' | 'reject', note: string, reasonCode?: string) {
  return adminRequest<CommunityCommentItem>({
    path: `/admin/community/comments/${id}/review`,
    method: 'POST',
    data: { decision, note, ...(reasonCode ? { reason_code: reasonCode } : {}) },
    headers: idempotencyKey(`community-comment-review-${id}-${decision}`),
  })
}

export function fetchCommunityReports() {
  return adminRequest<ListEnvelope<CommunityReportItem>>({ path: '/admin/community/reports' })
}

export function moderateCommunityContent(
  contentType: 'post' | 'comment',
  contentId: string,
  decision: 'warning' | 'hide' | 'delete',
  reasonCode: string,
  note: string,
) {
  return adminRequest<Record<string, unknown>>({
    path: `/admin/community/${contentType}/${contentId}/moderate`,
    method: 'POST',
    data: { decision, reason_code: reasonCode, note },
    // 稳定幂等键：同一内容同一处置动作只生成一张处置单，防连点造成重复处罚/通知
    headers: idempotencyKey(`mod-${contentType}-${contentId}-${decision}-${reasonCode}`),
  })
}

/* ============ 任务审核 ============ */

export interface TaskStep {
  id: string
  title: string
  [key: string]: unknown
}

export interface TaskSubmissionItem {
  id: string
  claim_id: string | null
  submission_no: number
  status: 'submitted' | 'reviewing' | 'approved' | 'rejected' | 'withdrawn'
  execution_status: string | null
  execution_status_label: string | null
  review_status: string | null
  review_status_label: string | null
  content: Record<string, unknown> | null
  file_ids: string[]
  reviewer: { id: string; display_name: string } | null
  revision_step_ids: string[]
  latest_review: {
    decision: string
    reason_code: string | null
    note: string | null
    reviewed_at: string | null
    step_results: Array<Record<string, unknown>>
  } | null
  submitted_at: string | null
  review_due_at: string | null
  review_overdue: boolean
  user_id?: string
  task_title?: string
  task_steps?: TaskStep[]
  overdue?: boolean
}

export interface TaskReviewReasonItem {
  id: string
  code: string
  label: string
  suggested_note: string | null
  sort_order: number
}

export function fetchTaskSubmissions(params?: { status?: string }) {
  return adminRequest<Pagination<TaskSubmissionItem>>({
    path: '/admin/task-submissions',
    params: params as Record<string, unknown> | undefined,
  })
}

export function fetchTaskReviewReasons() {
  return adminRequest<{ items: TaskReviewReasonItem[]; reviewers: Array<{ id: string; account: string; display_name: string }> }>({
    path: '/admin/task-review-reasons',
  })
}

/* 后端要求 Idempotency-Key 长度 12-128；同一对象同一动作使用稳定键，保证重试/双击幂等 */
function idempotencyKey(seed: string) {
  return { 'Idempotency-Key': seed.slice(0, 128).padEnd(12, '-') }
}

export function startTaskReview(id: string) {
  return adminRequest<TaskSubmissionItem>({
    path: `/admin/task-submissions/${id}/start-review`,
    method: 'POST',
    headers: idempotencyKey(`start-${id}`),
  })
}

export function releaseTaskReview(id: string, reason: string) {
  return adminRequest<TaskSubmissionItem>({
    path: `/admin/task-submissions/${id}/release-review`,
    method: 'POST',
    data: { reason },
    headers: idempotencyKey(`release-${id}`),
  })
}

export interface TaskStepResultInput {
  step_id: string
  decision: 'approve' | 'reject'
  reason_code?: string
  note?: string
}

export function reviewTaskSubmission(
  id: string,
  payload: { decision: 'approve' | 'reject'; reason_code?: string; note?: string; step_results?: TaskStepResultInput[] },
) {
  return adminRequest<TaskSubmissionItem>({
    path: `/admin/task-submissions/${id}/review`,
    method: 'POST',
    data: payload as Record<string, unknown>,
    headers: idempotencyKey(`review-${id}-${payload.decision}`),
  })
}
