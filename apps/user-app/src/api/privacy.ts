import { apiRequest, apiUrl } from './http'

export interface PrivacyRequestItem {
  id: string
  request_type: 'access' | 'copy' | 'correction' | 'deletion' | 'withdraw_consent'
  scope: string[]
  status: string
  blockers: string[]
  result_file_id: string | null
  result_expires_at: string | null
  requested_at: string
}

export interface CancellationEligibility {
  eligible: boolean
  blockers: string[]
  wallet_balance_amount: number
  frozen_assets: boolean
  remaining_points: number
  remaining_coupons: number
}

export interface CancellationRequest {
  id: string
  status: 'cooling_off' | 'cancelled' | 'executed' | 'rejected'
  requested_at: string
  cooling_off_ends_at: string
  cancel_reason?: string | null
}

export function createPrivacyIdempotencyKey(prefix: string) {
  return `${prefix}-${Date.now()}-${Math.random().toString(36).slice(2, 12)}`
}

export function listPrivacyRequests(token: string) {
  return apiRequest<{ items: PrivacyRequestItem[] }>({ path: '/me/privacy-requests', accessToken: token })
}

export function createPrivacyCopy(token: string, idempotencyKey = createPrivacyIdempotencyKey('privacy-copy')) {
  return apiRequest<PrivacyRequestItem>({
    path: '/me/privacy-requests', method: 'POST', accessToken: token,
    headers: { 'Idempotency-Key': idempotencyKey },
    data: { request_type: 'copy', scope: ['all_available'], description: '用户从隐私中心申请个人数据副本。' },
  })
}

export function loadCancellationEligibility(token: string) {
  return apiRequest<CancellationEligibility>({ path: '/me/account-cancellation/eligibility', accessToken: token })
}

export async function loadCancellation(token: string) {
  try { return await apiRequest<CancellationRequest>({ path: '/me/account-cancellation', accessToken: token }) }
  catch { return null }
}

export function requestCancellation(token: string, idempotencyKey = createPrivacyIdempotencyKey('account-cancel')) {
  return apiRequest<CancellationRequest>({
    path: '/me/account-cancellation', method: 'POST', accessToken: token,
    headers: { 'Idempotency-Key': idempotencyKey },
    data: { forfeit_points_and_coupons: true, confirmation: 'CONFIRM_ACCOUNT_CANCELLATION' },
  })
}

export function withdrawCancellation(token: string) {
  return apiRequest<void>({ path: '/me/account-cancellation', method: 'DELETE', accessToken: token })
}

export function downloadPrivacyExport(token: string, fileId: string): Promise<string> {
  return new Promise((resolve, reject) => {
    uni.downloadFile({
      url: apiUrl(`/files/${fileId}/content`), header: { Authorization: `Bearer ${token}` },
      success: result => result.statusCode === 200 ? resolve(result.tempFilePath) : reject(new Error('下载失败')),
      fail: reject,
    })
  })
}
