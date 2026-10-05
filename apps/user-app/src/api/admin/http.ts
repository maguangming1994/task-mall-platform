import { ApiError, apiUrl } from '@/api/http'
import { clientPlatform } from '@/platform/runtime'

/**
 * 管理员审核工作台专用 HTTP 客户端。
 *
 * 与普通用户会话完全隔离：独立存储键、独立刷新流程，
 * 避免小程序内管理员操作污染用户登录态。
 */

export interface AdminSession {
  accessToken: string
  refreshToken: string
  expiresAt: number
}

export const adminSessionKey = 'task_mall_admin_session'

export function loadAdminSession(): AdminSession | null {
  const stored = uni.getStorageSync(adminSessionKey)
  return stored && typeof stored === 'object' ? stored as AdminSession : null
}

export function saveAdminSession(session: AdminSession) {
  uni.setStorageSync(adminSessionKey, session)
}

export function clearAdminSession() {
  uni.removeStorageSync(adminSessionKey)
}

const deviceStorageKey = 'task_mall_device_id'

function getDeviceId() {
  const stored = uni.getStorageSync(deviceStorageKey)
  if (typeof stored === 'string' && stored.length >= 8) return stored
  const generated = `device-${Date.now()}-${Math.random().toString(36).slice(2, 12)}`
  uni.setStorageSync(deviceStorageKey, generated)
  return generated
}

interface AdminRequestOptions {
  path: string
  method?: 'GET' | 'POST' | 'PUT' | 'DELETE'
  data?: Record<string, unknown>
  params?: Record<string, unknown>
  headers?: Record<string, string>
  /** 内部使用：刷新令牌重试时不再递归刷新 */
  skipAuthRefresh?: boolean
}

interface AdminEnvelope<T> {
  code: string
  message: string
  data: T
  request_id: string
}

interface AdminErrorPayload {
  code?: string
  message?: string
  details?: Record<string, unknown>
}

// 并发请求同时 401 时共享同一个刷新 Promise，避免多次刷新
let refreshing: Promise<boolean> | null = null

async function refreshAdminToken(): Promise<boolean> {
  const session = loadAdminSession()
  if (!session?.refreshToken) return false

  const result = await new Promise<boolean>((resolve) => {
    uni.request({
      url: apiUrl('/admin/auth/refresh'),
      method: 'POST',
      data: { refresh_token: session.refreshToken },
      header: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-Client-Platform': clientPlatform,
        'X-Device-Id': getDeviceId(),
      },
      success(response) {
        if (response.statusCode !== 200) {
          resolve(false)
          return
        }
        const payload = response.data as AdminEnvelope<{ access_token: string; refresh_token: string; expires_in: number }>
        // 保留存储中的 profile 等附加字段，只轮换令牌
        const previous = loadAdminSession() as Record<string, unknown> | null
        uni.setStorageSync(adminSessionKey, {
          ...(previous && typeof previous === 'object' ? previous : {}),
          accessToken: payload.data.access_token,
          refreshToken: payload.data.refresh_token,
          expiresAt: Date.now() + payload.data.expires_in * 1000,
        })
        resolve(true)
      },
      fail() {
        resolve(false)
      },
    })
  })

  if (!result) clearAdminSession()
  return result
}

function rawRequest<T>(options: AdminRequestOptions, session: AdminSession | null): Promise<T> {
  // 小程序运行时没有 URLSearchParams，统一手写查询串（跳过空值）
  const parts: string[] = []
  if (options.params) {
    for (const [k, v] of Object.entries(options.params)) {
      if (v === undefined || v === null || v === '') continue
      parts.push(`${encodeURIComponent(k)}=${encodeURIComponent(String(v))}`)
    }
  }
  const queryString = parts.length ? `?${parts.join('&')}` : ''

  return new Promise((resolve, reject) => {
    uni.request({
      url: `${apiUrl(options.path)}${queryString}`,
      method: options.method || 'GET',
      data: options.data,
      header: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-Client-Platform': clientPlatform,
        'X-Device-Id': getDeviceId(),
        ...(session?.accessToken ? { Authorization: `Bearer ${session.accessToken}` } : {}),
        ...(options.headers || {}),
      },
      success(response) {
        if (response.statusCode === 204) {
          resolve(undefined as T)
          return
        }
        if (response.statusCode >= 200 && response.statusCode < 300) {
          resolve((response.data as AdminEnvelope<T>).data)
          return
        }
        const payload = response.data as AdminErrorPayload
        reject(new ApiError(
          payload.code || 'REQUEST_FAILED',
          payload.message || '请求失败，请稍后重试。',
          response.statusCode,
          payload.details,
        ))
      },
      fail(error) {
        reject(new ApiError('NETWORK_ERROR', error.errMsg || '网络连接失败。', 0))
      },
    })
  })
}

export async function adminRequest<T>(options: AdminRequestOptions): Promise<T> {
  let session = loadAdminSession()
  // 登录/刷新/登出等认证接口本身的 401 代表凭据无效或会话已失效，
  // 不能再走静默刷新，否则会掩盖"账号或密码错误"等真实原因
  const isAuthEndpoint = options.path.startsWith('/admin/auth/')
  const effectiveOptions = isAuthEndpoint ? { ...options, skipAuthRefresh: true } : options
  try {
    return await rawRequest<T>(effectiveOptions, session)
  } catch (error) {
    if (!(error instanceof ApiError) || error.statusCode !== 401 || effectiveOptions.skipAuthRefresh) {
      throw error
    }
    // 401：用 refresh_token 静默续期一次后重试原请求
    if (!refreshing) {
      refreshing = refreshAdminToken().finally(() => {
        refreshing = null
      })
    }
    const renewed = await refreshing
    if (!renewed) {
      throw new ApiError('ADMIN_SESSION_EXPIRED', '登录已过期，请重新登录。', 401)
    }
    session = loadAdminSession()
    return rawRequest<T>({ ...effectiveOptions, skipAuthRefresh: true }, session)
  }
}
