import { apiRequest } from '@/api/http'

export interface CheckInDay {
  date: string
  weekday: string
  is_today: boolean
  checked_in: boolean
  reward_points: number
}

export interface DailyCheckInStatus {
  today: string
  checked_in_today: boolean
  current_streak: number
  today_reward_points: number
  week: CheckInDay[]
  rules: {
    daily_reward_points: number
    validity_days: number
    reward_source: string
    description: string
    versions: { reward: number | null; validity: number | null }
  }
  workflow: {
    status_label: string
    next_action: string
    can_check_in: boolean
  }
}

export function getDailyCheckIn(token: string) {
  return apiRequest<DailyCheckInStatus>({ path: '/growth/check-in', accessToken: token })
}

export function submitDailyCheckIn(token: string) {
  return apiRequest<DailyCheckInStatus>({ path: '/growth/check-in', method: 'POST', accessToken: token })
}
