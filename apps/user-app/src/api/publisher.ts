import { ApiError, apiRequest, apiUrl, type ApiEnvelope, type ApiErrorPayload } from '@/api/http'

export interface PublisherSession { access_token: string; expires_at: string; publisher: { id: string; admin_display_name: string; can_publish: boolean; permissions?: string[] } }
export interface PublisherPool { id: string; name: string; asset_type: string; available_amount: number }
export interface PublisherStep { id: string; title: string; description: string; input_type: 'none' | 'text' | 'image' | 'text_image' | 'link'; required: boolean; link_url?: string | null; copy_text?: string | null }
export interface PublisherTaskVersion { version_no: number; status: string; change_type: string; source_version_no?: number | null; changed_fields: string[]; created_at?: string | null; published_at?: string | null }
export interface PublisherAuditEvent { id: string; action: string; actor?: string | null; reason?: string | null; before?: Record<string, unknown> | null; after?: Record<string, unknown> | null; created_at?: string | null }
export interface PublisherMediaAsset { id: string; url: string; name: string; mime_type: string; size_bytes: number; provider: string; status: string }
export interface PublisherTask {
  id: string
  title: string
  description?: string
  description_text?: string
  business_scenario?: string
  project_name?: string | null
  cover_image_url?: string | null
  supported_devices?: string[]
  visibility?: string
  task_type?: string
  participation_mode?: string
  verification_mode?: string
  tags?: string[]
  status: string
  version: number
  category?: string
  claim_starts_at?: string
  claim_ends_at?: string
  submit_ends_at?: string
  completion_window_hours?: number
  review_deadline_hours?: number
  submission_limit?: number
  rules?: string[]
  reward_quota_total?: number
  historical_claim_count?: number
  active_reservation_count?: number
  successful_reward_count?: number
  remaining_reward_slots?: number
  current_published_version?: number | null
  has_unpublished_changes?: boolean
  task_version?: PublisherTaskVersion
  reward_budget_bindings?: Array<{ reward_sequence: number; budget_pool_id: string }>
  publish_approval?: { status: string; triggered_rules?: string[]; review_note?: string | null } | null
  reward_budgets?: Array<{ asset_type: string; locked_amount: number; reserved_amount: number; issued_amount: number; available_amount: number; status: string }>
  rewards?: Array<{ type: string; points?: number; balance_amount?: number }>
  steps?: Array<PublisherStep & { kind?: string }>
}

export function loginPublisher(account: string, password: string, mfaCode?: string) {
  return apiRequest<PublisherSession>({ path: '/task-publisher/session', method: 'POST', data: { account, password, mfa_code: mfaCode || null } })
}
export function createMyPublisherSession(token: string) {
  return apiRequest<PublisherSession>({ path: '/me/task-publisher-session', method: 'POST', accessToken: token, data: {} })
}
export function listPublisherPools(token: string, assetType: 'points' | 'wallet_cash' = 'points') { return apiRequest<PublisherPool[]>({ path: `/task-publisher/budget-pools?asset_type=${assetType}`, accessToken: token }) }
export function listPublisherTasks(token: string) { return apiRequest<{ items: PublisherTask[] }>({ path: '/task-publisher/tasks', accessToken: token }) }
export function getPublisherTask(token: string, taskId: string) { return apiRequest<PublisherTask>({ path: `/task-publisher/tasks/${taskId}`, accessToken: token }) }
export function createPublisherTask(token: string, input: Record<string, unknown>) {
  return apiRequest<PublisherTask>({ path: '/task-publisher/tasks', method: 'POST', accessToken: token, data: input, headers: { 'Idempotency-Key': `publisher-task-${Date.now()}-${Math.random().toString(36).slice(2)}` } })
}
export function updatePublisherTask(token: string, taskId: string, input: Record<string, unknown>, version: number) {
  return apiRequest<PublisherTask>({ path: `/task-publisher/tasks/${taskId}`, method: 'PUT', accessToken: token, data: { ...input, version }, headers: { 'Idempotency-Key': `publisher-version-${taskId}-${Date.now()}-${Math.random().toString(36).slice(2)}` } })
}
export function listPublisherTaskVersions(token: string, taskId: string) {
  return apiRequest<PublisherTaskVersion[]>({ path: `/task-publisher/tasks/${taskId}/versions`, accessToken: token })
}
export function listPublisherTaskAudit(token: string, taskId: string) {
  return apiRequest<{ items: PublisherAuditEvent[] }>({ path: `/task-publisher/tasks/${taskId}/audit`, accessToken: token })
}
export function logoutPublisher(token: string) {
  return apiRequest<{ revoked: boolean }>({ path: '/task-publisher/session', method: 'DELETE', accessToken: token })
}
export function changePublisherTaskStatus(token: string, taskId: string, action: 'publish' | 'pause' | 'resume' | 'close' | 'archive', reason: string, existingClaimPolicy?: 'continue' | 'terminate_without_compensation' | 'terminate_with_compensation') {
  return apiRequest<PublisherTask>({ path: `/task-publisher/tasks/${taskId}/status`, method: 'POST', accessToken: token, data: { action, reason, existing_claim_policy: existingClaimPolicy || null }, headers: { 'Idempotency-Key': `publisher-${action}-${Date.now()}-${Math.random().toString(36).slice(2)}` } })
}
export function publishPublisherTask(token: string, taskId: string) {
  return changePublisherTaskStatus(token, taskId, 'publish', '发布方在移动端确认任务信息与预算后申请发布')
}

export function importPublisherMediaUrl(token: string, url: string) {
  return apiRequest<PublisherMediaAsset>({
    path: '/task-publisher/media/assets/import-url',
    method: 'POST',
    accessToken: token,
    data: { url },
  })
}

export function chooseAndUploadPublisherMedia(token: string): Promise<PublisherMediaAsset> {
  return new Promise((resolve, reject) => {
    uni.chooseImage({
      count: 1,
      sizeType: ['compressed'],
      success(selected) {
        const filePath = selected.tempFilePaths[0]
        if (!filePath) {
          reject(new Error('未选择图片。'))
          return
        }
        uni.uploadFile({
          url: apiUrl('/task-publisher/media/assets/upload'),
          filePath,
          name: 'file',
          header: { Accept: 'application/json', Authorization: `Bearer ${token}` },
          success(response) {
            let payload: ApiEnvelope<PublisherMediaAsset> | ApiErrorPayload
            try {
              payload = typeof response.data === 'string' ? JSON.parse(response.data) : response.data
            } catch {
              reject(new ApiError('INVALID_RESPONSE', '图片上传响应无法解析。', response.statusCode))
              return
            }
            if (response.statusCode >= 200 && response.statusCode < 300 && 'data' in payload) {
              resolve(payload.data)
              return
            }
            const error = payload as ApiErrorPayload
            reject(new ApiError(error.code || 'UPLOAD_FAILED', error.message || '图片上传失败。', response.statusCode, error.details))
          },
          fail(error) {
            reject(new ApiError('NETWORK_ERROR', error.errMsg || '图片上传网络连接失败。', 0))
          },
        })
      },
      fail(error) {
        reject(new Error(/cancel/i.test(error.errMsg || '') ? '已取消选择图片。' : (error.errMsg || '选择图片失败。')))
      },
    })
  })
}
