import { apiRequest } from '@/api/http'

export interface PublisherSession { access_token: string; expires_at: string; publisher: { id: string; admin_display_name: string; can_publish: boolean } }
export interface PublisherPool { id: string; name: string; asset_type: string; available_amount: number }
export interface PublisherTask { id: string; title: string; status: string; version: number; reward_quota_total?: number; rewards?: Array<{ type: string; points?: number; balance_amount?: number }> }
export interface PublisherStep { id: string; title: string; description: string; input_type: 'none' | 'text' | 'image' | 'text_image' | 'link'; required: boolean; link_url?: string | null; copy_text?: string | null }

export function loginPublisher(account: string, password: string, mfaCode?: string) {
  return apiRequest<PublisherSession>({ path: '/task-publisher/session', method: 'POST', data: { account, password, mfa_code: mfaCode || null } })
}
export function listPublisherPools(token: string, assetType: 'points' | 'wallet_cash' = 'points') { return apiRequest<PublisherPool[]>({ path: `/task-publisher/budget-pools?asset_type=${assetType}`, accessToken: token }) }
export function listPublisherTasks(token: string) { return apiRequest<{ items: PublisherTask[] }>({ path: '/task-publisher/tasks', accessToken: token }) }
export function createPublisherTask(token: string, input: Record<string, unknown>) {
  return apiRequest<PublisherTask>({ path: '/task-publisher/tasks', method: 'POST', accessToken: token, data: input, headers: { 'Idempotency-Key': `publisher-task-${Date.now()}-${Math.random().toString(36).slice(2)}` } })
}
export function publishPublisherTask(token: string, taskId: string) {
  return apiRequest<PublisherTask>({ path: `/task-publisher/tasks/${taskId}/status`, method: 'POST', accessToken: token, data: { action: 'publish', reason: '用户端发布中心确认发布' }, headers: { 'Idempotency-Key': `publisher-publish-${Date.now()}-${Math.random().toString(36).slice(2)}` } })
}
