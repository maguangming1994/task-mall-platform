import { apiRequest } from '@/api/http'

export interface AfterSaleReason { code: string; name: string; service_types: string[]; default_responsibility: string; return_shipping_payer: string; evidence_required: boolean }
export interface ShippingCompensation { id: string; requested_amount: number; approved_amount: number; status: string; review_note: string | null; paid_at: string | null }
export interface AfterSaleShipment { id?: string; direction?: 'return' | 'replacement'; method?: string; carrier_code?: string | null; tracking_no?: string | null; no_logistics_note?: string | null; paid_shipping_amount?: number; status?: string; shipped_at?: string | null; received_at?: string | null; compensation?: ShippingCompensation | null }
export interface Refund { id: string; refund_no: string; service_type: string; reason_name: string; status: string; refund_cash_amount: number; refund_wallet_amount?: number; refund_external_amount?: number; refund_points: number; responsibility_party: string; return_shipping_payer: string; return_method: string | null; review_note?: string | null; user_action_deadline_at: string | null; original_shipping_refunded?: boolean; return_shipment?: AfterSaleShipment | null; compensation?: ShippingCompensation | null; created_at?: string }
export interface Exchange { id: string; exchange_no: string; reason_name: string; status: string; quantity: number; responsibility_party: string; return_shipping_payer: string; replacement_shipping_payer: string; return_method: string; review_note?: string | null; return_deadline_at: string | null; replacement_auto_complete_at: string | null; shipments: AfterSaleShipment[]; created_at?: string }

/** Generate once when an operation starts and reuse it for every retry of that operation. */
export function createAfterSaleIdempotencyKey(prefix: string) {
  return `${prefix}-${Date.now()}-${Math.random().toString(36).slice(2, 12)}`
}

export function listAfterSaleReasons(serviceType: 'refund_only' | 'return_and_refund' | 'exchange') { return apiRequest<{ items: AfterSaleReason[] }>({ path: `/after-sale-reasons?service_type=${serviceType}` }) }
export function listRefunds(token: string) { return apiRequest<{ items: Refund[] }>({ path: '/refunds', accessToken: token }) }
export function listExchanges(token: string) { return apiRequest<{ items: Exchange[] }>({ path: '/exchanges', accessToken: token }) }
export function getExchange(token: string, id: string) { return apiRequest<Exchange>({ path: `/exchanges/${id}`, accessToken: token }) }
export function createRefund(token: string, input: Record<string, unknown>, idempotencyKey = createAfterSaleIdempotencyKey('refund')) { return apiRequest<Refund>({ path: '/refunds', method: 'POST', accessToken: token, data: input, headers: { 'Idempotency-Key': idempotencyKey } }) }
export function createExchange(token: string, input: Record<string, unknown>, idempotencyKey = createAfterSaleIdempotencyKey('exchange')) { return apiRequest<Exchange>({ path: '/exchanges', method: 'POST', accessToken: token, data: input, headers: { 'Idempotency-Key': idempotencyKey } }) }
export function submitRefundReturn(token: string, id: string, input: Record<string, unknown>, idempotencyKey = createAfterSaleIdempotencyKey('return')) { return apiRequest<Refund>({ path: `/refunds/${id}/return-shipment`, method: 'POST', accessToken: token, data: input, headers: { 'Idempotency-Key': idempotencyKey } }) }
export function cancelRefund(token: string, id: string) { return apiRequest<Refund>({ path: `/refunds/${id}/cancel`, method: 'POST', accessToken: token }) }
export function submitExchangeReturn(token: string, id: string, input: Record<string, unknown>, idempotencyKey = createAfterSaleIdempotencyKey('exchange-return')) { return apiRequest<Exchange>({ path: `/exchanges/${id}/return-shipment`, method: 'POST', accessToken: token, data: input, headers: { 'Idempotency-Key': idempotencyKey } }) }
export function completeExchange(token: string, id: string) { return apiRequest<Exchange>({ path: `/exchanges/${id}/complete`, method: 'POST', accessToken: token }) }
export function cancelExchange(token: string, id: string) { return apiRequest<Exchange>({ path: `/exchanges/${id}/cancel`, method: 'POST', accessToken: token }) }
