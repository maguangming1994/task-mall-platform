import { useAuthStore } from '@/stores/auth'
import { useCampusProfileStore } from '@/stores/campusProfile'
import { CAMPUS_BIND_PAGE, currentPageRoute, gotoOptionsCampusBind } from './campusGate'

/** 未完成绑校时无需强制跳转的页面 */
const GATE_EXEMPT_ROUTES = ['/pages/login/index', '/pages/legal-consent/index', '/pages/admin/login/index']

let redirectingAt = 0

/**
 * 登录用户 campus_required=true 时强制进入三步绑校向导（无跳过入口）。
 * 匿名浏览不拦截。
 */
export async function ensureCampusBinding(): Promise<boolean> {
  const auth = useAuthStore()
  if (!auth.accessToken) return false
  const route = currentPageRoute()
  // 冷启动时页面栈尚未就绪，交由首页 onShow 再判定
  if (!route || GATE_EXEMPT_ROUTES.includes(route)) return false
  if (Date.now() - redirectingAt < 1500) return true

  const profile = useCampusProfileStore()
  await profile.load(true).catch(() => {})
  if (!profile.required) return false
  if (currentPageRoute() === CAMPUS_BIND_PAGE) return true
  redirectingAt = Date.now()
  gotoOptionsCampusBind(true)
  return true
}
