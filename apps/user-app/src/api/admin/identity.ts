import { adminRequest } from './http'

export interface AdminIdentityRecord {
  id: string
  status: 'pending' | 'verified' | 'failed' | 'provider_error'
  provider: string
  real_name_masked: string | null
  identity_number_masked: string | null
  failure_code: string | null
  submitted_at: string | null
  verified_at: string | null
  reviewed_at: string | null
  review_note: string | null
  reviewed_by_name: string | null
  user: { id: string; nickname: string; avatar: string | null }
}

export interface AdminIdentityList {
  items: AdminIdentityRecord[]
  pagination: { page: number; page_size: number; total: number }
}

export function listIdentityVerifications(params: { status?: string; page?: number; page_size?: number } = {}) {
  return adminRequest<AdminIdentityList>({
    path: '/admin/identity-verifications',
    params: { status: params.status, page: params.page, page_size: params.page_size ?? 20 },
  })
}

export function reviewIdentityVerification(id: string, input: { decision: 'approve' | 'reject'; note?: string }) {
  return adminRequest<AdminIdentityRecord>({
    path: `/admin/identity-verifications/${encodeURIComponent(id)}/review`,
    method: 'POST',
    data: input,
  })
}
