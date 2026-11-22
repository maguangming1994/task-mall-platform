import { adminRequest } from './http'

export interface AdminContentAppeal {
  id: string
  action_id: string
  reason: string
  status: string
  review_note: string | null
  created_at: string | null
}

export function listCommunityAppeals() {
  return adminRequest<{ items: AdminContentAppeal[] }>({ path: '/admin/community/appeals' })
}

/** 复核申诉：通过即恢复内容与限制，驳回则维持原处置 */
export function reviewCommunityAppeal(id: string, decision: 'approve' | 'reject') {
  return adminRequest<AdminContentAppeal>({
    path: `/admin/community/appeals/${encodeURIComponent(id)}/review`,
    method: 'POST',
    data: {
      decision,
      note: decision === 'approve' ? '复核确认原处置有误，恢复内容、奖励与限制。' : '复核维持原处置。',
    },
  })
}

export interface AdminActivityParticipant {
  participant_id: number | null
  user_id: string | null
  nickname: string
  role: 'creator' | 'participant'
  status: 'joined' | 'left' | 'removed'
  joined_at: string | null
  left_at: string | null
}

export interface AdminCommunityActivity {
  id: string
  category_code: string
  category_label: string
  title: string
  details: string
  images: Array<{ file_id: string; url: string | null }>
  location: string
  starts_at: string
  participant_limit: number
  participant_count: number
  participants?: AdminActivityParticipant[]
  remaining_slots: number
  status: string
  creator: { id: string | null; nickname: string }
  created_at?: string | null
}

export function listCommunityActivities(status?: string) {
  return adminRequest<{ items: AdminCommunityActivity[] }>({
    path: '/admin/community/activities',
    params: status ? { status } : {},
  })
}

export function reviewCommunityActivity(
  id: string,
  decision: 'approve' | 'reject',
  note: string,
  reasonCode?: string,
) {
  return adminRequest<AdminCommunityActivity>({
    path: `/admin/community/activities/${encodeURIComponent(id)}/review`,
    method: 'POST',
    data: { decision, note, reason_code: reasonCode || undefined },
  })
}

export function closeCommunityActivity(id: string, reason: string) {
  return adminRequest<AdminCommunityActivity>({
    path: `/admin/community/activities/${encodeURIComponent(id)}/close`,
    method: 'POST',
    data: { reason },
  })
}

export function removeActivityParticipant(activityId: string, participantId: number, reason: string) {
  return adminRequest<AdminCommunityActivity>({
    path: `/admin/community/activities/${encodeURIComponent(activityId)}/participants/${participantId}/remove`,
    method: 'POST',
    data: { reason },
  })
}
