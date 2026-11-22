import { ApiError } from '@/api/http'
import { useAdminStore } from '@/stores/admin'

/**
 * 管理端页面统一会话守卫。
 *
 * - 未登录：回管理员登录页
 * - 401 / 会话过期：清理管理员会话后回登录页
 * - 高风险操作缺少近期验证：跳转身份校验页，用户完成密码（+动态码）后返回
 */
export function useAdminGuard() {
  const admin = useAdminStore()

  /** 页面进入前调用；返回 false 时调用方应立即停止后续加载 */
  function requireSession(): boolean {
    if (admin.isLoggedIn) return true
    uni.redirectTo({ url: '/pages/admin/login/index' })
    return false
  }

  /** 刷新管理员档案；会话失效返回 false */
  async function ensureProfile(): Promise<boolean> {
    try {
      await admin.fetchMe()
      return true
    } catch (error) {
      return !handleAuthError(error)
    }
  }

  /** 处理鉴权类错误；返回 true 表示已处理（已跳转），调用方无需再提示 */
  function handleAuthError(error: unknown): boolean {
    if (!(error instanceof ApiError)) return false
    if (error.code === 'ADMIN_SESSION_EXPIRED' || error.statusCode === 401) {
      admin.clear()
      uni.redirectTo({ url: '/pages/admin/login/index' })
      return true
    }
    if (error.code === 'ADMIN_RECENT_VERIFICATION_REQUIRED') {
      uni.navigateTo({ url: '/pages/admin/verify/index' })
      return true
    }
    return false
  }

  return { requireSession, ensureProfile, handleAuthError }
}
