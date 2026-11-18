import { apiRequest } from '@/api/http'
import { buildQueryString } from '@/utils/query'

export interface PushLevel {
  code: string
  name: string
  coefficient: number
  team_bonus_rate: number
  min_monthly_orders: number
  sort_order: number
  status: string
}

export interface PushTeamSummary {
  id: string
  name: string
  slogan: string | null
  intro: string | null
  level_code: string
  level_name: string
  status: string
  settlement_cycle: string
  review_hours: number
  member_limit: number
  member_count: number
  month_orders: number
  month_commission: string
  team_bonus_rate: number
  leader: { id: string; nickname: string } | null
  campus: { id: string; name: string } | null
  ranking?: Array<{ user_id: string | null; nickname: string; level_code: string; month_orders: number; month_commission: string }>
}

export interface PushTask {
  id: string
  title: string
  description: string | null
  cover_text: string | null
  reward_amount: string
  quota: number
  claimed_count: number
  remaining: number | null
  per_user_limit: number
  starts_at: string | null
  ends_at: string | null
  status: string
}

export interface PushPosition {
  id: string
  keyword: string | null
  status: string
  claimed_at: string | null
  expires_at: string | null
  task: PushTask | null
}

export interface PushSubmission {
  id: string
  status: string
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
  task: PushTask | null
  user?: { id: string; nickname: string } | null
}

export interface PushMembership {
  team_id: string
  team_name: string
  role: 'leader' | 'member'
  level_code: string
  month_orders: number
  month_commission: string
  joined_at: string | null
}

export interface PushWalletSummary {
  available_amount: string
  pending_amount: string
  settled_amount: string
  month_commission?: string
  month_orders?: number
  avg_commission?: string
  level_code?: string
  level_name?: string
}

export interface PushCenter {
  wallet: PushWalletSummary
  membership: PushMembership | null
  team: PushTeamSummary | null
  is_leader: boolean
  tasks: PushTask[]
  counts: { positions: number; pending_submissions: number; pending_applications: number }
  levels: PushLevel[]
}

export interface PushTeamDetail {
  team: PushTeamSummary
  conditions: { identity_verified: boolean; campus_matched: boolean; no_other_team: boolean; pending_application: boolean; can_apply: boolean }
  is_member: boolean
  pending_application: boolean
}

export interface PushTeamList {
  items: PushTeamSummary[]
  pagination: { page: number; page_size: number; total: number }
}

export interface PushApplication {
  id: string
  type: 'join' | 'leader'
  status: string
  team_name: string | null
  message: string | null
  review_note: string | null
  created_at: string | null
  user: { id: string; nickname: string } | null
  team: { id: string; name: string } | null
}

export interface PushMyTeam {
  membership: PushMembership | null
  team: PushTeamSummary | null
  members: Array<{ user_id: string | null; nickname: string; role: string; level_code: string; month_orders: number; month_commission: string; joined_at: string | null }>
  applications: PushApplication[]
  is_leader: boolean
}

export interface PushEarnings {
  wallet: { available_amount: string; pending_amount: string; settled_amount: string }
  level: PushLevel
  items: Array<{ id: string; task_title: string | null; status: string; commission: string; settled: boolean; created_at: string | null }>
}

export function getPushCenter(token: string): Promise<PushCenter> {
  return apiRequest<PushCenter>({ path: '/push/center', accessToken: token })
}

export function listPushTeams(token: string, params: { keyword?: string; sort?: string; page?: number } = {}): Promise<PushTeamList> {
  const query = buildQueryString({ keyword: params.keyword, sort: params.sort, page: params.page ?? 1, page_size: 20 })
  return apiRequest<PushTeamList>({ path: `/push/teams?${query}`, accessToken: token })
}

export function getPushTeam(token: string, id: string): Promise<PushTeamDetail> {
  return apiRequest<PushTeamDetail>({ path: `/push/teams/${encodeURIComponent(id)}`, accessToken: token })
}

export function applyPushTeam(token: string, id: string, message?: string): Promise<PushApplication> {
  return apiRequest<PushApplication>({ path: `/push/teams/${encodeURIComponent(id)}/apply`, method: 'POST', accessToken: token, data: { message } })
}

export function applyPushLeader(token: string, input: { team_name?: string; message?: string }): Promise<PushApplication> {
  return apiRequest<PushApplication>({ path: '/push/leader-applications', method: 'POST', accessToken: token, data: input })
}

export function getPushMyTeam(token: string): Promise<PushMyTeam> {
  return apiRequest<PushMyTeam>({ path: '/push/my-team', accessToken: token })
}

export function reviewPushApplication(token: string, id: string, decision: 'approve' | 'reject', note?: string): Promise<PushApplication> {
  return apiRequest<PushApplication>({ path: `/push/applications/${encodeURIComponent(id)}/review`, method: 'POST', accessToken: token, data: { decision, note } })
}

export function leavePushTeam(token: string): Promise<{ left: boolean }> {
  return apiRequest<{ left: boolean }>({ path: '/push/my-team/leave', method: 'POST', accessToken: token, data: {} })
}

export interface PushTaskList {
  items: PushTask[]
  pagination: { page: number; page_size: number; total: number }
}

export function listPushTasks(token: string, page = 1): Promise<PushTaskList> {
  const query = buildQueryString({ page, page_size: 20 })
  return apiRequest<PushTaskList>({ path: `/push/tasks?${query}`, accessToken: token })
}

export function getPushTask(token: string, id: string): Promise<{ task: PushTask; is_member: boolean; my_positions: PushPosition[] }> {
  return apiRequest({ path: `/push/tasks/${encodeURIComponent(id)}`, accessToken: token })
}

export function claimPushPosition(token: string, taskId: string, keyword?: string): Promise<PushPosition> {
  return apiRequest<PushPosition>({ path: `/push/tasks/${encodeURIComponent(taskId)}/positions`, method: 'POST', accessToken: token, data: { keyword } })
}

export function listPushPositions(token: string): Promise<{ items: PushPosition[] }> {
  return apiRequest<{ items: PushPosition[] }>({ path: '/push/positions', accessToken: token })
}

export function submitPushPosition(token: string, positionId: string, input: { images: string[]; remark?: string; contact_name?: string; contact_phone?: string }): Promise<PushSubmission> {
  return apiRequest<PushSubmission>({ path: `/push/positions/${encodeURIComponent(positionId)}/submissions`, method: 'POST', accessToken: token, data: input })
}

export function listPushSubmissions(token: string, status?: string): Promise<{ items: PushSubmission[] }> {
  const query = buildQueryString({ status })
  return apiRequest<{ items: PushSubmission[] }>({ path: `/push/submissions${query ? `?${query}` : ''}`, accessToken: token })
}

export function getPushEarnings(token: string): Promise<PushEarnings> {
  return apiRequest<PushEarnings>({ path: '/push/earnings', accessToken: token })
}
