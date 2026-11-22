import { adminRequest } from './http'

export interface AdminIdentityConflict {
  id: string
  kind: string
  provider: string
  status: 'pending' | 'resolved' | 'rejected'
  source_user_id: string | null
  target_user_id: string | null
  candidate_count: number
  resolution: string | null
  resolution_reason: string | null
  created_at: string | null
  resolved_at: string | null
}

export function listIdentityConflicts(status = 'pending') {
  return adminRequest<{ items: AdminIdentityConflict[] }>({
    path: '/admin/identity-conflicts',
    params: { status, page_size: 50 },
  })
}

export function resolveIdentityConflict(
  id: string,
  input: { resolution: 'attach_to_target' | 'reject'; reason: string; target_user_id?: string },
) {
  return adminRequest<AdminIdentityConflict>({
    path: `/admin/identity-conflicts/${encodeURIComponent(id)}/resolve`,
    method: 'POST',
    data: input,
  })
}
