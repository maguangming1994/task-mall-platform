import { adminRequest } from './http'

export interface AdminPushApplication {
  id: string
  type: 'join' | 'leader'
  status: 'pending' | 'approved' | 'rejected'
  team_name: string | null
  message: string | null
  review_note: string | null
  created_at: string | null
  reviewed_at: string | null
  user: { id: string; nickname: string } | null
  team: { id: string; name: string } | null
}

export interface AdminPushSubmission {
  id: string
  status: 'pending' | 'approved' | 'rejected'
  images: string[]
  remark: string | null
  contact_name: string | null
  contact_phone: string | null
  reject_reason: string | null
  level_code: string
  commission: string
  team_bonus: string
  settled: boolean
  created_at: string | null
  reviewed_at: string | null
  task: { id: string; title: string; reward?: string } | null
  user: { id: string; nickname: string } | null
}

export interface AdminPushApplicationList {
  items: AdminPushApplication[]
  pagination: { page: number; page_size: number; total: number }
  summary: { pending: number; leader_pending: number }
}

export interface AdminPushSubmissionList {
  items: AdminPushSubmission[]
  pagination: { page: number; page_size: number; total: number }
  summary: { pending: number; approved: number; rejected: number }
}

export function listPushApplications(status = 'pending', page = 1) {
  return adminRequest<AdminPushApplicationList>({
    path: '/admin/push/applications',
    params: { status, page, page_size: 20 },
  })
}

export function reviewPushApplication(id: string, input: { decision: 'approve' | 'reject'; note?: string }) {
  return adminRequest<AdminPushApplication>({
    path: `/admin/push/applications/${encodeURIComponent(id)}/review`,
    method: 'POST',
    data: input,
  })
}

export function listPushSubmissions(status = 'pending', page = 1) {
  return adminRequest<AdminPushSubmissionList>({
    path: '/admin/push/submissions',
    params: { status, page, page_size: 20 },
  })
}

export function reviewPushSubmission(id: string, input: { decision: 'approve' | 'reject'; reason?: string }) {
  return adminRequest<AdminPushSubmission>({
    path: `/admin/push/submissions/${encodeURIComponent(id)}/review`,
    method: 'POST',
    data: input,
  })
}
