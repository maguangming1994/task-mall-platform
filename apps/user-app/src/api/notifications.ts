import { apiRequest } from '@/api/http'
export interface AppNotification { id: string; category: string; scene_code: string; title: string; body: string; essential: boolean; read_at: string | null; created_at: string }
export interface NotificationPreference { in_app_enabled: boolean; wechat_enabled: boolean; sms_enabled: boolean; marketing_subscribed: boolean; quiet_hours_start: string; quiet_hours_end: string }
export function listNotifications(token: string) { return apiRequest<{ items: AppNotification[] }>({ path: '/notifications', accessToken: token }) }
export function markNotificationRead(token: string, id: string) { return apiRequest<AppNotification>({ path: `/notifications/${id}/read`, method: 'POST', accessToken: token }) }
export function getNotificationPreference(token: string) { return apiRequest<NotificationPreference>({ path: '/notification-preferences', accessToken: token }) }
export function updateNotificationPreference(token: string, data: NotificationPreference) { return apiRequest<NotificationPreference>({ path: '/notification-preferences', method: 'PUT', accessToken: token, data: data as unknown as Record<string, unknown> }) }
