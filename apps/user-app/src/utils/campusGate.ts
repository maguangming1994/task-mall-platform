/** 全局绑校广播事件（api/http 拦截 403 后 uni.$emit） */
export const CAMPUS_REQUIRED_EVENT = 'campus:required'
export const CAMPUS_BIND_PAGE = '/pages/campus-bind/index'

/** 与 stores/auth 保持一致的本地登录态存储键（此处直接读缓存，避免 http 依赖 store 形成循环） */
const AUTH_STORAGE_KEY = 'task_mall_auth'

/** 未完成绑校时无需强制跳转的页面（登录 / 协议确认由各自流程负责） */
const GATE_EXEMPT_ROUTES = ['/pages/login/index', '/pages/legal-consent/index', '/pages/admin/login/index']

let promptOpening = false

export function currentPageRoute(): string {
  const pages = getCurrentPages()
  const page = pages[pages.length - 1] as { route?: string } | undefined
  return page?.route ? `/${page.route}` : ''
}

export function hasSessionToken(): boolean {
  try {
    const stored = uni.getStorageSync(AUTH_STORAGE_KEY) as { accessToken?: unknown } | null
    return Boolean(stored && typeof stored === 'object' && typeof stored.accessToken === 'string' && stored.accessToken)
  } catch {
    return false
  }
}

export function gotoOptionsCampusBind(replace = false) {
  if (currentPageRoute() === CAMPUS_BIND_PAGE) return
  if (replace) {
    uni.reLaunch({ url: CAMPUS_BIND_PAGE })
    return
  }
  uni.navigateTo({
    url: CAMPUS_BIND_PAGE,
    fail: () => uni.reLaunch({ url: CAMPUS_BIND_PAGE }),
  })
}

/**
 * api/http 全局拦截入口：广播事件，由 App.vue 刷新账号校区状态并展示统一提示。
 * 匿名（无本地登录态）不触发。
 */
export function handleCampusGate(code?: string | null) {
  if (!hasSessionToken()) return
  uni.$emit(CAMPUS_REQUIRED_EVENT, code || '')
}

export function showCampusRequiredModal(code?: string | null) {
  if (promptOpening) return
  if (!hasSessionToken()) return
  const route = currentPageRoute()
  if (route === CAMPUS_BIND_PAGE || GATE_EXEMPT_ROUTES.includes(route)) return

  promptOpening = true
  uni.showModal({
    title: '需要先绑定学校',
    content: code === 'CAMPUS_PROFILE_INCOMPLETE'
      ? '请先补全学校、年级与专业信息，完成后即可继续使用该功能。'
      : '绑定学校后才能继续该操作。你可以先绑定学校，也可以取消后继续浏览内容。',
    confirmText: '去绑定',
    cancelText: '先浏览',
    success(result) {
      if (result.confirm) gotoOptionsCampusBind()
    },
    complete() {
      promptOpening = false
    },
  })
}
