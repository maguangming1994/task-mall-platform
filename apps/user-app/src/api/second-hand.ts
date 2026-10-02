import { apiRequest } from '@/api/http'

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
  buyer?: SecondHandUser | null
  seller?: SecondHandUser | null
}

// 分类
export function listSecondHandCategories(token: string) {
  return apiRequest<{ items: SecondHandCategory[]; conditions: Record<string, string> }>({ path: '/second-hand/categories', accessToken: token })
}

// 列表
export function listSecondHandItems(token: string, params: { page?: number; page_size?: number; category_id?: string; keyword?: string; condition?: SecondHandCondition; trade_type?: SecondHandTradeType; sort?: 'latest' | 'price_asc' | 'price_desc' }) {
  const q = new URLSearchParams()
  Object.entries(params).forEach(([k, v]) => { if (v !== undefined && v !== null && String(v) !== '') q.set(k, String(v)) })
  const qs = q.toString()
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

// 我发布的
export function mySecondHandItems(token: string, params: { page?: number; page_size?: number; status?: SecondHandItemStatus }) {
  const q = new URLSearchParams()
  Object.entries(params).forEach(([k, v]) => { if (v !== undefined && v !== null && String(v) !== '') q.set(k, String(v)) })
  const qs = q.toString()
  return apiRequest<{ items: SecondHandItem[]; pagination: { page: number; page_size: number; total: number } }>({ path: `/second-hand/my-items${qs ? `?${qs}` : ''}`, accessToken: token })
}

// 下单
export function createSecondHandOrder(token: string, data: { item_id: string; trade_type?: 'meetup' | 'mail'; address?: Record<string, unknown> }, idempotencyKey: string) {
  return apiRequest<SecondHandOrder>({ path: '/second-hand/orders', method: 'POST', accessToken: token, data: data as unknown as Record<string, unknown>, headers: { 'Idempotency-Key': idempotencyKey } })
}

// 订单列表
export function listSecondHandOrders(token: string, params: { role: 'buyer' | 'seller'; status?: string; page?: number; page_size?: number }) {
  const q = new URLSearchParams()
  Object.entries(params).forEach(([k, v]) => { if (v !== undefined && v !== null && String(v) !== '') q.set(k, String(v)) })
  return apiRequest<{ items: SecondHandOrder[]; pagination: { page: number; page_size: number; total: number } }>({ path: `/second-hand/orders?${q.toString()}`, accessToken: token })
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
