import { adminRequest } from './http'

export interface AdminTaskAppeal {
  id: string
  claim_id: string
  appeal_no: number
  status: string
  reason: string
  result_reason: string | null
  created_at: string
}

export interface AdminTaskRiskCase {
  id: string
  task_id: string
  source: string
  risk_level: string
  status: string
  reward_frozen: boolean
  conclusion: string | null
  response_due_at: string
  overdue: boolean
}

export function listTaskAppeals() {
  return adminRequest<{ items: AdminTaskAppeal[] }>({
    path: '/admin/task-appeals',
    params: { status: 'submitted' },
  })
}

export function reviewTaskAppeal(id: string, decision: 'approve' | 'reject', reason: string) {
  return adminRequest<AdminTaskAppeal>({
    path: `/admin/task-appeals/${encodeURIComponent(id)}/review`,
    method: 'POST',
    data: { decision, reason },
  })
}

export function listTaskRiskCases() {
  return adminRequest<{ items: AdminTaskRiskCase[] }>({
    path: '/admin/task-risk-cases',
    params: { status: 'open' },
  })
}

export function reviewTaskRiskCase(
  id: string,
  decision: 'clear' | 'request_more_information' | 'confirm_violation',
  actions: string[],
  reason: string,
) {
  return adminRequest<AdminTaskRiskCase>({
    path: `/admin/task-risk-cases/${encodeURIComponent(id)}/review`,
    method: 'POST',
    data: { decision, actions, reason },
  })
}
