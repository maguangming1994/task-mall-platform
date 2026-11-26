import { apiRequest } from '@/api/http'
import { buildQueryString } from '@/utils/query'

export type ExpressPickupStatus =
  | 'pending_payment'
  | 'pending_accept'
  | 'accepted'
  | 'picked_up'
  | 'delivered'
  | 'completed'
  | 'cancelled'

export interface ExpressPickupOrder {
  id: string
  order_no: string
  status: ExpressPickupStatus
  campus_id: number
  pickup_site: string
  pickup_code: string
  package_info: string | null
  package_count: number
  deliver_building: string
  deliver_address: string
  contact_name: string
  contact_phone: string
  reward_cents: number
  total_cents: number
  payment_method: string | null
  paid_at: string | null
  expected_at: string | null
  remark: string | null
  runner_user_id: string | null
  runner_name: string | null
  accepted_at: string | null
  picked_up_at: string | null
  delivered_at: string | null
  completed_at: string | null
  cancelled_at: string | null
  cancel_reason: string | null
  print_status: string | null
  created_at: string | null
}

export interface ExpressPickupOrderDetail extends ExpressPickupOrder {
  events?: Array<{ from_status: string | null; to_status: string; event: string; reason: string | null; created_at: string | null }>
}

export interface ExpressPickupRunnerProfile {
  id: string
  campus_id: number
  status: 'pending' | 'active' | 'rejected' | 'suspended'
  real_name: string
  phone: string
  applied_at: string | null
  reviewed_at: string | null
  reject_reason: string | null
}

export interface CreateExpressPickupInput {
  pickup_site: string
  pickup_code: string
  package_info?: string
  package_count?: number
  deliver_building: string
  deliver_address: string
  contact_name: string
  contact_phone: string
  reward_cents: number
  expected_at?: string | null
  remark?: string
}

export interface ExpressPickupPaymentResult {
  order: ExpressPickupOrder
  payment: { id: string; payment_no: string; channel: string; cash_amount: number; client_payload?: Record<string, unknown> | null } | null
}

const newKey = (prefix: string) => `${prefix}-${Date.now()}-${Math.random().toString(36).slice(2)}`

export function listMyExpressPickupOrders(token: string, status?: string) {
  const query = buildQueryString({ status })
  return apiRequest<{ items: ExpressPickupOrder[] }>({ path: `/express-pickup/orders${query}`, accessToken: token })
}

export function getExpressPickupOrder(token: string, id: string) {
  return apiRequest<ExpressPickupOrderDetail>({ path: `/express-pickup/orders/${id}`, accessToken: token })
}

export function createExpressPickupOrder(token: string, input: CreateExpressPickupInput, idempotencyKey = newKey('ep-create')) {
  return apiRequest<ExpressPickupOrder>({
    path: '/express-pickup/orders',
    method: 'POST',
    accessToken: token,
    data: input as unknown as Record<string, unknown>,
    headers: { 'Idempotency-Key': idempotencyKey },
  })
}

export function cancelExpressPickupOrder(token: string, id: string, reason: string) {
  return apiRequest<ExpressPickupOrder>({
    path: `/express-pickup/orders/${id}/cancel`,
    method: 'POST',
    accessToken: token,
    data: { reason },
  })
}

export function confirmExpressPickupOrder(token: string, id: string) {
  return apiRequest<ExpressPickupOrder>({ path: `/express-pickup/orders/${id}/confirm`, method: 'POST', accessToken: token, data: {} })
}

export function payExpressPickupOrder(token: string, id: string, method: 'wallet' | 'wechat', idempotencyKey = newKey('ep-pay')) {
  return apiRequest<ExpressPickupPaymentResult>({
    path: `/express-pickup/orders/${id}/payments`,
    method: 'POST',
    accessToken: token,
    data: { method, scene: 'wechat-mini' },
    headers: { 'Idempotency-Key': idempotencyKey },
  })
}

export function getExpressPickupRunnerProfile(token: string) {
  return apiRequest<{ runner: ExpressPickupRunnerProfile | null }>({ path: '/express-pickup/runners/me', accessToken: token })
}

export function applyExpressPickupRunner(token: string, input: { real_name: string; phone: string }) {
  return apiRequest<ExpressPickupRunnerProfile>({ path: '/express-pickup/runners/apply', method: 'POST', accessToken: token, data: input })
}

export function listRunnerHall(token: string) {
  return apiRequest<{ items: ExpressPickupOrder[] }>({ path: '/express-pickup/runner/orders', accessToken: token })
}

export function listMyRunnerOrders(token: string, status?: string) {
  const query = buildQueryString({ status })
  return apiRequest<{ items: ExpressPickupOrder[] }>({ path: `/express-pickup/runner/mine${query}`, accessToken: token })
}

export function acceptExpressPickupOrder(token: string, id: string) {
  return apiRequest<ExpressPickupOrder>({ path: `/express-pickup/runner/orders/${id}/accept`, method: 'POST', accessToken: token, data: {} })
}

export function pickupExpressPickupOrder(token: string, id: string) {
  return apiRequest<ExpressPickupOrder>({ path: `/express-pickup/runner/orders/${id}/pickup`, method: 'POST', accessToken: token, data: {} })
}

export function deliverExpressPickupOrder(token: string, id: string) {
  return apiRequest<ExpressPickupOrder>({ path: `/express-pickup/runner/orders/${id}/deliver`, method: 'POST', accessToken: token, data: {} })
}

export const EXPRESS_STATUS_LABELS: Record<ExpressPickupStatus, string> = {
  pending_payment: '待支付',
  pending_accept: '待接单',
  accepted: '已接单',
  picked_up: '已取件',
  delivered: '已送达',
  completed: '已完成',
  cancelled: '已取消',
}

export function expressStatusLabel(status: string): string {
  return EXPRESS_STATUS_LABELS[status as ExpressPickupStatus] ?? status
}

export function expressMoney(cents: number): string {
  return (cents / 100).toFixed(2)
}
