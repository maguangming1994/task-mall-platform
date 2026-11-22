import { adminRequest } from './http'

/** 管理员档案（来自 /admin/auth/login 与 /admin/me） */
export interface AdminProfile {
  id: string
  account: string
  display_name: string
  email: string | null
  status: string
  requires_mfa: boolean
  roles: string[]
  permissions: string[]
  campuses: Array<{ id: string; name: string; is_primary: boolean }>
}

export interface AdminLoginResult {
  access_token: string
  refresh_token: string
  token_type: 'Bearer'
  expires_in: number
  admin: AdminProfile
  navigation: Array<Record<string, unknown>>
}

/** 开启了二次验证的账号，登录返回挑战，需再提交动态码 */
export interface AdminMfaChallenge {
  mfa_required: true
  challenge_id: string
  challenge_token: string
  expires_at: string
}

export type AdminLoginOutcome = AdminLoginResult | AdminMfaChallenge

export function adminLogin(account: string, password: string, remember = true) {
  return adminRequest<AdminLoginOutcome>({
    path: '/admin/auth/login',
    method: 'POST',
    data: { account, password, remember },
  })
}

export function adminVerifyMfa(challenge: AdminMfaChallenge, code: string, remember = true) {
  return adminRequest<AdminLoginResult>({
    path: '/admin/auth/mfa/verify',
    method: 'POST',
    data: {
      challenge_id: challenge.challenge_id,
      challenge_token: challenge.challenge_token,
      code,
      remember,
    },
  })
}

/** 高风险操作前的近期身份校验，成功后 15 分钟内有效 */
export function verifyRecentAdmin(password: string, mfaCode?: string) {
  return adminRequest<{ verified_at: string }>({
    path: '/admin/auth/verify',
    method: 'POST',
    data: mfaCode ? { password, mfa_code: mfaCode } : { password },
  })
}

export function fetchAdminMe() {
  return adminRequest<AdminProfile>({ path: '/admin/me' })
}

export function adminLogout() {
  return adminRequest<void>({ path: '/admin/auth/logout', method: 'POST' })
}
