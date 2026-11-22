import { adminRequest } from './http'
import type { AdminLoginOutcome } from './auth'

export interface AdminWechatBinding {
  bound: boolean
  bound_at: string | null
  last_used_at: string | null
  expires_at: string | null
}

/** 公众号 H5 免密登录：用微信授权 code 匹配已绑定管理员 */
export function loginByWechat(code: string) {
  return adminRequest<AdminLoginOutcome>({
    path: '/admin/auth/wechat/login',
    method: 'POST',
    data: { code },
  })
}

export function fetchWechatBinding() {
  return adminRequest<AdminWechatBinding>({ path: '/admin/auth/wechat/binding' })
}

/** 绑定当前微信（要求 15 分钟内完成过密码/TOTP 验证） */
export function bindWechat(code: string) {
  return adminRequest<AdminWechatBinding>({
    path: '/admin/auth/wechat/bind',
    method: 'POST',
    data: { code },
  })
}

export function unbindWechat() {
  return adminRequest<void>({ path: '/admin/auth/wechat/binding', method: 'DELETE' })
}
