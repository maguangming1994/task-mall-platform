import { apiRequest } from '@/api/http'

export interface AfterSaleReason { code: string; name: string; service_types: string[]; default_responsibility: string; return_shipping_payer: string; evidence_required: boolean }
export interface Refund { id: string; refund_no: string; service_type: string; reason_name: string; status: string; refund_cash_amount: number; refund_points: number; responsibility_party: string; return_shipping_payer: string; return_method: string | null; user_action_deadline_at: string | null }
export interface Exchange { id: string; exchange_no: string; reason_name: string; status: string; quantity: number; responsibility_party: string; return_deadline_at: string | null }

export function listAfterSaleReasons(serviceType: 'refund_only' | 'return_and_refund' | 'exchange') { return apiRequest<{ items: AfterSaleReason[] }>({ path: `/after-sale-reasons?service_type=${serviceType}` }) }
export function listRefunds(token: string) { return apiRequest<{ items: Refund[] }>({ path: '/refunds', accessToken: token }) }
export function createRefund(token: string, input: Record<string, unknown>) { return apiRequest<Refund>({ path: '/refunds', method: 'POST', accessToken: token, data: input, headers: { 'Idempotency-Key': `refund-${Date.now()}-${Math.random()}` } }) }
export function createExchange(token: string, input: Record<string, unknown>) { return apiRequest<Exchange>({ path: '/exchanges', method: 'POST', accessToken: token, data: input, headers: { 'Idempotency-Key': `exchange-${Date.now()}-${Math.random()}` } }) }
export function submitRefundReturn(token: string, id: string, input: Record<string, unknown>) { return apiRequest<Refund>({ path: `/refunds/${id}/return-shipment`, method: 'POST', accessToken: token, data: input, headers: { 'Idempotency-Key': `return-${Date.now()}-${Math.random()}` } }) }
