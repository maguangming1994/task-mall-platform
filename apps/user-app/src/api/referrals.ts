import { apiRequest } from '@/api/http'

export interface ReferralProgress {
  id: string
  referred_user: { nickname: string }
  first_task_completed: boolean
  first_cash_order_completed: boolean
  status: string
  rewarded_at: string | null
}

export function getReferrals(token: string) {
  return apiRequest<{ referral_code: string; items: ReferralProgress[] }>({ path: '/growth/referrals', accessToken: token })
}

export function bindReferral(token: string, referralCode: string) {
  return apiRequest<{ id: string; status: string }>({ path: '/growth/referrals', method: 'POST', accessToken: token, data: { referral_code: referralCode } })
}
