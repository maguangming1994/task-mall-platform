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
  paid_cash_amount: number
  paid_points: number
}

export interface Order {
  id: string
  order_no: string
  fulfillment_type: FulfillmentType
  status: string
  cash_payment_method: 'none' | CashPaymentMethod
  payable_cash_amount: number
  payable_points: number
  paid_wallet_amount: number
  paid_external_amount: number
  paid_points: number
  shipping_amount: number
  items: OrderItem[]
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

export function listOrders(token: string) {
  return apiRequest<{ items: Order[]; pagination: { page: number; page_size: number; total: number } }>({ path: '/orders', accessToken: token })
}

export function createPayment(token: string, orderId: string, idempotencyKey: string) {
  return apiRequest<Payment>({ path: `/orders/${orderId}/payments`, method: 'POST', accessToken: token, headers: { 'Idempotency-Key': idempotencyKey } })
}

export function completeOrder(token: string, orderId: string) { return apiRequest<{ id: string; status: 'completed' }>({ path: `/orders/${orderId}/complete`, method: 'POST', accessToken: token }) }
