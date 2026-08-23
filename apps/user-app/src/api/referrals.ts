import { apiRequest } from '@/api/http'

export interface ReferralMilestoneRule {
  code: 'first_task' | 'first_cash_order'
  label: string
  description: string
}

export interface ReferralMilestoneProgress extends ReferralMilestoneRule {
  completed: boolean
  completed_at: string | null
}

export interface ReferralRewardRecord {
  id: string
  referral_id: string
  referred_user: { nickname: string }
  asset_type: 'points'
  points: number
  available_points: number
  debt_repaid_points: number
  credited_at: string | null
}

export interface ReferralProgress {
  id: string
  referred_user: { nickname: string }
  bound_at: string | null
  milestones: ReferralMilestoneProgress[]
  first_task_completed: boolean
  first_cash_order_completed: boolean
  status: 'tracking' | 'rewarded' | 'qualified_cap_reached' | 'revoked' | string
  qualified_at: string | null
  reward_points: number
  reward: ReferralRewardRecord | null
  rewarded_at: string | null
}

export interface ReferralDashboard {
  referral_code: string
  share: { path: string }
  rules: {
    match_mode: 'all'
    milestones: ReferralMilestoneRule[]
    reward: { asset_type: 'points'; points: number }
    monthly_cap: number
    versions: { milestones: number | null; reward: number | null; monthly_cap: number | null }
  }
  summary: {
    invited_count: number
    rewarded_count: number
    reward_points_total: number
    rewarded_this_month: number
    remaining_rewards_this_month: number
  }
  reward_records: ReferralRewardRecord[]
  items: ReferralProgress[]
  pagination: { page: number; page_size: number; total: number }
}

export function getReferrals(token: string) {
  return apiRequest<ReferralDashboard>({ path: '/growth/referrals', accessToken: token })
}

export function bindReferral(token: string, referralCode: string) {
  return apiRequest<{ id: string; status: string }>({
    path: '/growth/referrals',
    method: 'POST',
    accessToken: token,
    data: { referral_code: referralCode },
  })
}
