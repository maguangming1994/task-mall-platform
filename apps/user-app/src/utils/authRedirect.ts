const RETURN_URL_STORAGE_KEY = 'task_mall_auth_return_url'
const LOGIN_PATH = '/pages/login/index'
const MAX_RETURN_URL_LENGTH = 1024

type PageLike = {
  route?: unknown
  options?: unknown
  $page?: {
    route?: unknown
    fullPath?: unknown
    options?: unknown
  }
}

function decodeCandidate(value: string) {
  let decoded = value
  for (let index = 0; index < 2; index += 1) {
    try {
      const next = decodeURIComponent(decoded)
      if (next === decoded) break
      decoded = next
    } catch {
      return null
    }
  }
  return decoded
}

/**
 * Only page routes owned by this app can be used as an authentication return
 * target. External URLs, path traversal, and the login page itself are never
 * accepted.
 */
export function normalizeInternalReturnUrl(value: unknown): string | null {
  if (typeof value !== 'string') return null
  const candidate = decodeCandidate(value.trim())
  if (!candidate || candidate.length > MAX_RETURN_URL_LENGTH || candidate.includes('\\') || candidate.includes('\u0000') || candidate.includes('\n') || candidate.includes('\r') || candidate.includes('#')) return null
  if (!candidate.startsWith('/pages/') || candidate.startsWith('//')) return null

  const path = candidate.split('?')[0]
  const segments = path.slice('/pages/'.length).split('/')
  if (!segments.length || segments.some(segment => !segment || segment === '.' || segment === '..' || !/^[A-Za-z0-9_-]+$/.test(segment))) return null
  if (path.toLowerCase() === LOGIN_PATH) return null
  return candidate
}

function pageOptions(page: PageLike): Record<string, unknown> {
  const options = page.options || page.$page?.options
  return options && typeof options === 'object' ? options as Record<string, unknown> : {}
}

function pageRoute(page: PageLike): string | null {
  const route = page.route || page.$page?.route
  if (typeof route === 'string' && route) return route
  const fullPath = page.$page?.fullPath
  if (typeof fullPath === 'string' && fullPath) return fullPath.split('?')[0]
  return null
}

function pageUrl(page: PageLike | undefined): string | null {
  if (!page) return null
  const fullPath = page.$page?.fullPath
  if (typeof fullPath === 'string' && fullPath) {
    const normalizedFullPath = fullPath.startsWith('/') ? fullPath : `/${fullPath}`
    const fromFullPath = normalizeInternalReturnUrl(normalizedFullPath)
    if (fromFullPath) return fromFullPath
  }
  const route = pageRoute(page)
  if (!route) return null
  const path = route.startsWith('/') ? route : `/${route}`
  const query = Object.entries(pageOptions(page))
    .filter(([, value]) => value !== undefined && value !== null && value !== '')
    .map(([key, value]) => `${encodeURIComponent(key)}=${encodeURIComponent(String(value))}`)
    .join('&')
  return normalizeInternalReturnUrl(query ? `${path}?${query}` : path)
}

export function getCurrentPageReturnUrl(): string | null {
  const pages = getCurrentPages()
  return pageUrl(pages[pages.length - 1] as PageLike | undefined)
}

export function getPreviousPageReturnUrl(): string | null {
  const pages = getCurrentPages()
  return pageUrl(pages[pages.length - 2] as PageLike | undefined)
}

export function saveReturnUrl(value: unknown): string | null {
  const normalized = normalizeInternalReturnUrl(value)
  if (normalized) uni.setStorageSync(RETURN_URL_STORAGE_KEY, normalized)
  return normalized
}

export function readStoredReturnUrl(): string | null {
  const stored = uni.getStorageSync(RETURN_URL_STORAGE_KEY)
  const normalized = normalizeInternalReturnUrl(stored)
  if (!normalized && stored) uni.removeStorageSync(RETURN_URL_STORAGE_KEY)
  return normalized
}

/** Resolve in order: explicit query, persisted target, then the previous page. */
export function resolveReturnUrl(queryValue?: unknown): string | null {
  const fromQuery = normalizeInternalReturnUrl(queryValue)
  if (fromQuery) {
    saveReturnUrl(fromQuery)
    return fromQuery
  }
  const stored = readStoredReturnUrl()
  if (stored) return stored
  const previous = getPreviousPageReturnUrl()
  if (previous) saveReturnUrl(previous)
  return previous
}

export function clearReturnUrl() {
  uni.removeStorageSync(RETURN_URL_STORAGE_KEY)
}

export function appendReturnUrl(url: string, returnUrl?: unknown): string {
  const normalized = normalizeInternalReturnUrl(returnUrl)
  if (!normalized) return url
  return `${url}${url.includes('?') ? '&' : '?'}returnUrl=${encodeURIComponent(normalized)}`
}

function goToLogin(method: 'navigateTo' | 'redirectTo', returnUrl?: unknown) {
  const target = normalizeInternalReturnUrl(returnUrl) || getCurrentPageReturnUrl()
  if (target) saveReturnUrl(target)
  const url = appendReturnUrl(LOGIN_PATH, target)
  if (method === 'navigateTo') return uni.navigateTo({ url })
  return uni.redirectTo({ url })
}

export function navigateToLogin(returnUrl?: unknown) {
  return goToLogin('navigateTo', returnUrl)
}

export function redirectToLogin(returnUrl?: unknown) {
  return goToLogin('redirectTo', returnUrl)
}
