import { clientPlatform } from '@/platform/runtime'
import { handleModuleDisabled } from '@/utils/moduleDisabled'

export interface ApiEnvelope<T> {
  code: string
  message: string
  data: T
  request_id: string
}

export interface ApiErrorPayload {
  code: string
  message: string
  details?: Record<string, unknown>
  request_id?: string
}

export class ApiError extends Error {
  constructor(
    public readonly code: string,
    message: string,
    public readonly statusCode: number,
    public readonly details?: Record<string, unknown>,
  ) {
    super(message)
    this.name = 'ApiError'
  }
}

let defaultApiBaseUrl = 'https://rwsc.jxgxnxs.cn/api/v1'
// #ifdef H5
defaultApiBaseUrl = '/api/v1'
// #endif

const apiBaseUrl = import.meta.env.VITE_API_BASE_URL || defaultApiBaseUrl

export function apiUrl(path: string) {
  return `${apiBaseUrl}${path}`
}

// 小程序运行时没有 URLSearchParams，统一用该函数拼接查询串（跳过空值）
export function toQueryString(params: Record<string, unknown>): string {
  const parts: string[] = []
  for (const [k, v] of Object.entries(params)) {
    if (v === undefined || v === null || v === '') continue
    parts.push(`${encodeURIComponent(k)}=${encodeURIComponent(String(v))}`)
  }
  return parts.join('&')
}

export function resolveApiAssetUrl(value?: string | null) {
  const url = String(value ?? '').trim()
  if (!url || /^(?:https?:|data:|blob:|wxfile:)/i.test(url) || !url.startsWith('/')) {
    return url
  }

  const origin = apiBaseUrl.match(/^(https?:\/\/[^/]+)/i)?.[1]
  return origin ? `${origin}${url}` : url
}

const deviceStorageKey = 'task_mall_device_id'
const campusStorageKey = 'task_mall_selected_campus'

function getDeviceId() {
  const stored = uni.getStorageSync(deviceStorageKey)
  if (typeof stored === 'string' && stored.length >= 8) {
    return stored
  }

  const generated = `device-${Date.now()}-${Math.random().toString(36).slice(2, 12)}`
  uni.setStorageSync(deviceStorageKey, generated)
  return generated
}

interface ApiRequestOptions {
  path: string
  method?: 'GET' | 'POST' | 'PUT' | 'DELETE'
  data?: string | Record<string, unknown> | ArrayBuffer
  accessToken?: string | null
  headers?: Record<string, string>
}

export function apiRequest<T>(options: ApiRequestOptions): Promise<T> {
  return new Promise((resolve, reject) => {
    uni.request({
      url: `${apiBaseUrl}${options.path}`,
      method: options.method || 'GET',
      data: options.data,
      header: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-Client-Platform': clientPlatform,
        'X-Device-Id': getDeviceId(),
        ...(uni.getStorageSync(campusStorageKey)?.id ? { 'X-Campus-Id': uni.getStorageSync(campusStorageKey).id } : {}),
        ...(options.accessToken ? { Authorization: `Bearer ${options.accessToken}` } : {}),
        ...options.headers,
      },
      success(response) {
        if (response.statusCode === 204) {
          resolve(undefined as T)
          return
        }

        if (response.statusCode >= 200 && response.statusCode < 300) {
          resolve((response.data as ApiEnvelope<T>).data)
          return
        }

        const payload = response.data as ApiErrorPayload
        // 模块门禁：统一中性提示 + 刷新开关 + 广播占位事件，随后照常 reject 供调用方终止流程
        if (payload.code === 'MODULE_DISABLED') {
          const moduleCode = payload.details && typeof payload.details.module === 'string'
            ? payload.details.module
            : undefined
          handleModuleDisabled(moduleCode)
        }
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
