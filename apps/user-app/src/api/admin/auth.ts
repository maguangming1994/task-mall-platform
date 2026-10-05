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

export function adminLogin(account: string, password: string, remember = true) {
  return adminRequest<AdminLoginResult>({
    path: '/admin/auth/login',
    method: 'POST',
    data: { account, password, remember },
  })
}

export function fetchAdminMe() {
  return adminRequest<AdminProfile>({ path: '/admin/me' })
}

export function adminLogout() {
  return adminRequest<void>({ path: '/admin/auth/logout', method: 'POST' })
}
