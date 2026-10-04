import { apiRequest, toQueryString } from '@/api/http'

export type SecondHandCondition = 'new' | 'like_new' | 'very_good' | 'good' | 'fair'
export type SecondHandTradeType = 'meetup' | 'mail' | 'both'
export type SecondHandItemStatus = 'on_sale' | 'reserved' | 'sold' | 'off_shelf'

export interface SecondHandCategory { id: string; name: string; icon: string | null; sort_order: number }
export interface SecondHandUser { id: string; nickname: string; avatar: string | null }

export interface SecondHandItem {
  id: string
  title: string
  price: number
  price_fen: number
  condition: SecondHandCondition
  condition_label: string
  cover: string | null
  images: string[]
  status: SecondHandItemStatus
  audit_status: 'pending' | 'approved' | 'rejected'
  rejected_reason?: string | null
  trade_type: SecondHandTradeType
  location: string | null
  view_count: number
  created_at: string
  category: { id: string; name: string } | null
  seller: SecondHandUser | null
  description?: string
  favorite_count?: number
  is_owner?: boolean
}

export interface SecondHandSellerProfile {
  id: string
  nickname: string
  avatar: string | null
  campus_name: string | null
  identity_verified: boolean
  on_sale_count: number
  sold_count: number
  rating_average: number | null
  rating_count: number
  good_rate_percent: number | null
}

export interface SecondHandOrder {
  id: string
  order_no: string
  role: 'buyer' | 'seller'
  status: string
  title: string
  cover: string | null
  price: number
  trade_type: string
  item_id: string | null
  counterparty: SecondHandUser | null
  created_at: string
  paid_at: string | null
  shipped_at: string | null
  completed_at: string | null
  address?: Record<string, unknown> | null
  buyer_message?: string | null
  buyer?: SecondHandUser | null
  seller?: SecondHandUser | null
  has_refund?: boolean
  has_rated?: boolean
}

export interface SecondHandRating {
  id: string
  order_id: string | null
  score: number
  content: string | null
  images: string[]
  reply_content: string | null
  replied_at: string | null
  rater: SecondHandUser | null
  ratee: SecondHandUser | null
  created_at: string
}

export type SecondHandRefundStatus = 'pending' | 'seller_approved' | 'seller_rejected' | 'arbitrating' | 'arbitrated_approved' | 'arbitrated_rejected' | 'cancelled'

export interface SecondHandRefund {
  id: string
  order_id: string | null
  amount: number
  reason: string
  images: string[]
  status: SecondHandRefundStatus
  seller_note: string | null
  seller_responded_at: string | null
  admin_note: string | null
  arbitrated_at: string | null
  auto_approve_at: string | null
  buyer: SecondHandUser | null
  seller: SecondHandUser | null
  created_at: string
}

// 分类
export function listSecondHandCategories(token: string) {
  return apiRequest<{ items: SecondHandCategory[]; conditions: Record<string, string> }>({ path: '/second-hand/categories', accessToken: token })
}

// 列表
export function listSecondHandItems(token: string, params: { page?: number; page_size?: number; category_id?: string; seller?: string; keyword?: string; condition?: SecondHandCondition; trade_type?: SecondHandTradeType; sort?: 'latest' | 'price_asc' | 'price_desc' }) {
  const qs = toQueryString(params)
  return apiRequest<{ items: SecondHandItem[]; pagination: { page: number; page_size: number; total: number } }>({ path: `/second-hand/items${qs ? `?${qs}` : ''}`, accessToken: token })
}

// 详情
export function getSecondHandItem(token: string, id: string) {
  return apiRequest<SecondHandItem>({ path: `/second-hand/items/${id}`, accessToken: token })
}

// 发布
export function createSecondHandItem(token: string, data: Record<string, unknown>, idempotencyKey: string) {
  return apiRequest<SecondHandItem>({ path: '/second-hand/items', method: 'POST', accessToken: token, data, headers: { 'Idempotency-Key': idempotencyKey } })
}

// 编辑
export function updateSecondHandItem(token: string, id: string, data: Record<string, unknown>) {
  return apiRequest<SecondHandItem>({ path: `/second-hand/items/${id}`, method: 'PUT', accessToken: token, data })
}

// 下架
export function deleteSecondHandItem(token: string, id: string) {
  return apiRequest<{ id: string; status: string }>({ path: `/second-hand/items/${id}`, method: 'DELETE', accessToken: token })
}

// 重新上架
export function relistSecondHandItem(token: string, id: string) {
  return apiRequest<SecondHandItem>({ path: `/second-hand/items/${id}/relist`, method: 'POST', accessToken: token })
}

// 卖家公开资料
export function getSecondHandSellerProfile(sellerId: string, token?: string) {
  return apiRequest<SecondHandSellerProfile>({ path: `/second-hand/sellers/${sellerId}`, accessToken: token })
}

// 某用户收到的闲置评价
export function listUserSecondHandRatings(token: string, userId: string, params: { page?: number; page_size?: number; role?: 'buyer' | 'seller' } = {}) {
  const qs = toQueryString(params)
  return apiRequest<{ items: SecondHandRating[]; pagination: { page: number; page_size: number; total: number }; average_score: number | null; total_count: number }>({ path: `/users/${userId}/second-hand-ratings${qs ? `?${qs}` : ''}`, accessToken: token })
}

// 我发布的
export function mySecondHandItems(token: string, params: { page?: number; page_size?: number; status?: SecondHandItemStatus }) {
  const qs = toQueryString(params)
  return apiRequest<{ items: SecondHandItem[]; pagination: { page: number; page_size: number; total: number } }>({ path: `/second-hand/my-items${qs ? `?${qs}` : ''}`, accessToken: token })
}

// 下单
export function createSecondHandOrder(token: string, data: { item_id: string; trade_type?: 'meetup' | 'mail'; address?: Record<string, unknown>; buyer_message?: string }, idempotencyKey: string) {
  return apiRequest<SecondHandOrder>({ path: '/second-hand/orders', method: 'POST', accessToken: token, data: data as unknown as Record<string, unknown>, headers: { 'Idempotency-Key': idempotencyKey } })
}

// 订单列表
export function listSecondHandOrders(token: string, params: { role: 'buyer' | 'seller'; status?: string; page?: number; page_size?: number }) {
  const qs = toQueryString(params)
  return apiRequest<{ items: SecondHandOrder[]; pagination: { page: number; page_size: number; total: number } }>({ path: `/second-hand/orders${qs ? `?${qs}` : ''}`, accessToken: token })
}

// 订单详情
export function getSecondHandOrder(token: string, id: string) {
  return apiRequest<SecondHandOrder>({ path: `/second-hand/orders/${id}`, accessToken: token })
}

// 支付
export function paySecondHandOrder(token: string, id: string) {
  return apiRequest<SecondHandOrder>({ path: `/second-hand/orders/${id}/pay`, method: 'POST', accessToken: token })
}

// 发货
export function shipSecondHandOrder(token: string, id: string) {
  return apiRequest<SecondHandOrder>({ path: `/second-hand/orders/${id}/ship`, method: 'POST', accessToken: token })
}

// 确认收货
export function completeSecondHandOrder(token: string, id: string) {
  return apiRequest<SecondHandOrder>({ path: `/second-hand/orders/${id}/complete`, method: 'POST', accessToken: token })
}

// 取消
export function cancelSecondHandOrder(token: string, id: string) {
  return apiRequest<SecondHandOrder>({ path: `/second-hand/orders/${id}/cancel`, method: 'POST', accessToken: token })
}

// 评价
export function rateSecondHandOrder(token: string, orderId: string, data: { score: number; content?: string; images?: string[] }) {
  return apiRequest<SecondHandRating>({ path: `/second-hand/orders/${orderId}/rate`, method: 'POST', accessToken: token, data: data as unknown as Record<string, unknown> })
}

// 订单评价列表
export function listSecondHandOrderRatings(token: string, orderId: string) {
  return apiRequest<{ items: SecondHandRating[] }>({ path: `/second-hand/orders/${orderId}/ratings`, accessToken: token })
}

// 回复评价
export function replySecondHandRating(token: string, ratingId: string, replyContent: string) {
  return apiRequest<SecondHandRating>({ path: `/second-hand/ratings/${ratingId}/reply`, method: 'PUT', accessToken: token, data: { reply_content: replyContent } })
}

// 申请退款
export function requestSecondHandRefund(token: string, orderId: string, data: { reason: string; images?: string[] }) {
  return apiRequest<SecondHandRefund>({ path: `/second-hand/orders/${orderId}/refund`, method: 'POST', accessToken: token, data: data as unknown as Record<string, unknown> })
}

// 退款详情（无退款时后端返回 404）
export function getSecondHandRefund(token: string, orderId: string) {
  return apiRequest<SecondHandRefund>({ path: `/second-hand/orders/${orderId}/refund`, accessToken: token })
}

// 卖家响应退款
export function respondSecondHandRefund(token: string, orderId: string, data: { action: 'approve' | 'reject'; seller_note?: string }) {
  return apiRequest<SecondHandRefund>({ path: `/second-hand/orders/${orderId}/refund/respond`, method: 'POST', accessToken: token, data: data as unknown as Record<string, unknown> })
}

// 买家申请平台介入
export function escalateSecondHandRefund(token: string, orderId: string) {
  return apiRequest<SecondHandRefund>({ path: `/second-hand/orders/${orderId}/refund/escalate`, method: 'POST', accessToken: token })
}

// 买家撤销退款申请（卖家处理前）
export function cancelSecondHandRefund(token: string, orderId: string) {
  return apiRequest<SecondHandRefund>({ path: `/second-hand/orders/${orderId}/refund/cancel`, method: 'POST', accessToken: token })
}
