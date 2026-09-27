import { ApiError } from '@/api/http'
import type { PublisherSession } from '@/api/publisher'

const storageKey = 'publisher_session'

export interface StoredPublisherSession {
  token: string
  name: string
  user_id: string
  expires_at: string
}

export function readPublisherSession(userId: string): StoredPublisherSession | null {
  const saved = uni.getStorageSync(storageKey)
  if (!saved || typeof saved !== 'object') return null
  const candidate = saved as Partial<StoredPublisherSession>
  const expiresAt = typeof candidate.expires_at === 'string' ? Date.parse(candidate.expires_at) : Number.NaN
  if (!candidate.token || typeof candidate.name !== 'string' || candidate.user_id !== userId || !Number.isFinite(expiresAt) || expiresAt <= Date.now()) {
    clearPublisherSession()
    return null
  }

  return candidate as StoredPublisherSession
}

export function persistPublisherSession(session: PublisherSession, userId: string) {
  uni.setStorageSync(storageKey, {
    token: session.access_token,
    name: session.publisher.admin_display_name,
    user_id: userId,
    expires_at: session.expires_at,
  } satisfies StoredPublisherSession)
}

export function clearPublisherSession() {
  uni.removeStorageSync(storageKey)
}

export function isPublisherAccessError(error: unknown) {
  return error instanceof ApiError && (error.statusCode === 401 || error.statusCode === 403)
}
