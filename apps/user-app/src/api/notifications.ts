import { apiRequest } from '@/api/http'
import { buildQueryString } from '@/utils/query'

export type NotificationCategory = 'task' | 'asset' | 'order' | 'after_sale' | 'community' | 'system' | 'material'

export interface AppNotification {
  id: string
  category: NotificationCategory
  scene_code: string
  title: string
  body: string
  data: Record<string, unknown> | null
  essential: boolean
  read_at: string | null
  created_at: string
}

export interface NotificationPagination {
  page: number
  page_size: number
  total: number
  has_more: boolean
}

export interface NotificationListResponse {
  items: AppNotification[]
  pagination: NotificationPagination
  unread_total: number
  category_unread_counts: Partial<Record<NotificationCategory, number>>
}

export interface NotificationPreference { in_app_enabled: boolean; wechat_enabled: boolean; sms_enabled: boolean; marketing_subscribed: boolean; quiet_hours_start: string; quiet_hours_end: string }
export type NotificationSubscriptionContext = 'task' | 'order' | 'community'
export interface WechatSubscriptionGrantState { template_id: string; status: string; available_count: number }
export interface WechatSubscriptionResult { template_id: string; status: string }
export interface WechatSubscriptionGroup {
  context: NotificationSubscriptionContext
  label: string
  template_ids: string[]
  scenes: string[]
  grants: WechatSubscriptionGrantState[]
}
export interface WechatSubscriptionOptions { configured: boolean; groups: WechatSubscriptionGroup[] }

export function listNotifications(token: string, filters: { page?: number; page_size?: number; category?: NotificationCategory; unread_only?: boolean } = {}) {
  const suffix = buildQueryString({
    page: filters.page,
    page_size: filters.page_size,
    category: filters.category,
    unread_only: filters.unread_only ? 1 : undefined,
  })
  return apiRequest<NotificationListResponse>({ path: `/notifications${suffix ? `?${suffix}` : ''}`, accessToken: token })
}

export function markNotificationRead(token: string, id: string) { return apiRequest<AppNotification>({ path: `/notifications/${id}/read`, method: 'POST', accessToken: token }) }
export function markAllNotificationsRead(token: string, category?: NotificationCategory) {
  return apiRequest<{ updated_count: number; unread_total: number }>({ path: '/notifications/read-all', method: 'POST', accessToken: token, data: category ? { category } : {} })
}
export function getNotificationPreference(token: string) { return apiRequest<NotificationPreference>({ path: '/notification-preferences', accessToken: token }) }
export function updateNotificationPreference(token: string, data: NotificationPreference) { return apiRequest<NotificationPreference>({ path: '/notification-preferences', method: 'PUT', accessToken: token, data: data as unknown as Record<string, unknown> }) }
export function getWechatSubscriptionOptions(token: string) { return apiRequest<WechatSubscriptionOptions>({ path: '/notification-subscriptions/wechat', accessToken: token }) }
export function recordWechatSubscriptionResults(token: string, results: WechatSubscriptionResult[], requestId: string) {
  return apiRequest<WechatSubscriptionOptions>({ path: '/notification-subscriptions/wechat', method: 'POST', accessToken: token, data: { request_id: requestId, results } as unknown as Record<string, unknown> })
}
