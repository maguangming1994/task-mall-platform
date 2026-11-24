import { apiRequest } from '@/api/http'
import type { PurchaseMode } from '@/api/products'

export type FulfillmentType = 'logistics' | 'no_logistics' | 'virtual_delivery'
export type CashPaymentMethod = 'wallet' | 'wechat'

export interface CheckoutItemInput {
  sku_id: string
  quantity: number
  purchase_mode: PurchaseMode
}

export interface CheckoutGroupOption {
  fulfillment_type: FulfillmentType
  address_id?: string
  coupon_id?: string
  cash_payment_method?: CashPaymentMethod
}

export interface CheckoutInput {
  capability_version: 1
  items: CheckoutItemInput[]
  group_options: CheckoutGroupOption[]
  preview_token?: string
}

export interface CheckoutGroup {
  group_key: FulfillmentType
  fulfillment_type: FulfillmentType
  items_cash_amount: number
  items_points: number
  cash_discount_amount: number
  points_discount: number
  shipping_amount: number
  payable_cash_amount: number
  payable_points: number
  cash_payment_method: 'none' | CashPaymentMethod
  selected_coupon_id: string | null
  coupon_options: CheckoutCouponOption[]
}

export interface CheckoutCouponOption {
  id: string
  name: string
  cash_discount_amount: number
  points_discount: number
  minimum_cash_amount: number
  minimum_points: number
  estimated_cash_discount: number
  estimated_points_discount: number
  eligible: boolean
  unavailable_reason: string | null
  valid_from: string
  valid_to: string
}

export interface CheckoutPreview {
  preview_token: string
  capability_version: 1
  groups: CheckoutGroup[]
  items_cash_amount: number
  items_points: number
  cash_discount_amount: number
  points_discount: number
  shipping_amount: number
  payable_cash_amount: number
  payable_points: number
  expires_at: string
}

export interface OrderItem {
  id: string
  product_name: string
  sku_name: string
  sku_description: string
  purchase_mode: PurchaseMode
  fulfillment_type: FulfillmentType
  quantity: number
  unit_cash_amount: number
  unit_points: number
  paid_cash_amount: number
  paid_points: number
  unit_paid_cash_amount: number
  unit_paid_points: number
  refunded_quantity: number
  pending_refund_quantity: number
  active_exchange_quantity: number
  remaining_refundable_quantity: number
  remaining_exchangeable_quantity: number
  refundable_cash_amount: number
  refundable_points: number
  after_sale_workflow: {
    allowed: boolean
    available_service_types: Array<'refund_only' | 'return_and_refund' | 'exchange'>
    recommended_service_type: 'refund_only' | 'return_and_refund' | 'exchange' | null
    return_method: 'logistics' | 'no_logistics'
    requires_return_logistics: boolean
    has_active_after_sale: boolean
    summary: string
  }
}

export interface Fulfillment {
  id: string
  fulfillment_no: string
  method: FulfillmentType
  status: string
  carrier_code: string | null
  carrier_name: string | null
  tracking_no: string | null
  no_logistics_reason: string | null
  virtual_delivery: Record<string, unknown> | null
  fulfilled_at: string | null
}

export interface Order {
  id: string
  order_batch_id?: string
  order_no: string
  fulfillment_type: FulfillmentType
  status: string
  cash_payment_method: 'none' | CashPaymentMethod
  address?: Record<string, unknown> | null
  items_cash_amount?: number
  items_points?: number
  cash_discount_amount?: number
  points_discount?: number
  payable_cash_amount: number
  payable_points: number
  paid_wallet_amount: number
  paid_external_amount: number
  paid_points: number
  shipping_amount: number
  items: OrderItem[]
  fulfillments: Fulfillment[]
  auto_complete_at: string | null
  completed_at: string | null
  expires_at: string | null
  created_at: string
}

export interface OrderBatch {
  id: string
  batch_no: string
  status: string
  payable_cash_amount: number
  payable_points: number
  orders: Order[]
  created_at: string
}

export interface Payment {
  id: string
  payment_no: string
  order_id: string
  channel: 'wallet' | 'wechat'
  scene: string
  cash_amount: number
  status: string
  client_payload: ({ kind: 'wallet'; completed: true } | { kind: 'mweb'; mweb_url: string; sandbox: boolean } | { kind: 'mini_program' | 'jsapi'; appId: string; timeStamp: string; nonceStr: string; package: string; signType: string; paySign: string; sandbox: boolean }) | null
  expires_at: string
  succeeded_at: string | null
}

export function previewCheckout(token: string, input: CheckoutInput) {
  return apiRequest<CheckoutPreview>({ path: '/checkout/preview', method: 'POST', accessToken: token, data: input as unknown as Record<string, unknown> })
}

export function createOrders(token: string, input: CheckoutInput, idempotencyKey: string) {
  return apiRequest<OrderBatch>({ path: '/orders', method: 'POST', accessToken: token, data: input as unknown as Record<string, unknown>, headers: { 'Idempotency-Key': idempotencyKey } })
}

export interface OrderPagination {
  page: number
  page_size: number
  total: number
  last_page: number
}

export function listOrders(token: string, params: { page?: number; pageSize?: number; status?: string } = {}) {
  const query: string[] = []
  if (params.page) query.push(`page=${params.page}`)
  if (params.pageSize) query.push(`page_size=${params.pageSize}`)
  if (params.status) query.push(`status=${encodeURIComponent(params.status)}`)
  const suffix = query.length ? `?${query.join('&')}` : ''
  return apiRequest<{ items: Order[]; pagination: OrderPagination }>({ path: `/orders${suffix}`, accessToken: token })
}

/** 按分页逐页拉取全部订单，保持既有页面（状态筛选、售后可选订单）的完整数据。 */
export async function listAllOrders(token: string, status?: string): Promise<Order[]> {
  const items: Order[] = []
  let page = 1
  let lastPage = 1
  do {
    const result = await listOrders(token, { page, pageSize: 100, status })
    items.push(...result.items)
    lastPage = Math.max(result.pagination.last_page, 1)
    page += 1
  } while (page <= lastPage)
  return items
}

export function getOrder(token: string, orderId: string) {
  return apiRequest<Order>({ path: `/orders/${orderId}`, accessToken: token })
}

export function createPayment(token: string, orderId: string, idempotencyKey: string) {
  return apiRequest<Payment>({ path: `/orders/${orderId}/payments`, method: 'POST', accessToken: token, headers: { 'Idempotency-Key': idempotencyKey } })
}

export function getPayment(token: string, paymentId: string) {
  return apiRequest<Payment>({ path: `/payments/${paymentId}`, accessToken: token })
}

export function completeOrder(token: string, orderId: string) { return apiRequest<{ id: string; status: 'completed' }>({ path: `/orders/${orderId}/complete`, method: 'POST', accessToken: token }) }
