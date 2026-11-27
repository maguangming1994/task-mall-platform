import { apiRequest, apiUrl } from '@/api/http'
import { buildQueryString } from '@/utils/query'

export type StoreStatus = 'pending' | 'active' | 'rejected' | 'suspended' | 'closed'
export type StoreProductStatus = 'draft' | 'on_sale' | 'off_shelf'
export type StoreSettlementStatus = 'pending' | 'confirmed' | 'paid'

export interface Pagination {
  page: number
  page_size: number
  total: number
  last_page: number
}

export interface StoreSummary {
  id: string
  name: string
  logo_url: string | null
  description: string | null
  contact_phone: string
  address: string | null
  business_hours: string | null
  product_count: number
  created_at: string | null
}

export interface StoreCategoryRef {
  id: string
  name: string
}

export interface StoreProductCard {
  id: string
  name: string
  subtitle: string | null
  cover_url: string | null
  category: StoreCategoryRef | null
  price_from: number | null
  price_to: number | null
  sku_count: number
  available_quantity: number
}

export interface StoreSku {
  id: string
  name: string
  specs: Record<string, string>
  image_url: string | null
  fulfillment_type: 'logistics' | 'no_logistics'
  weight_grams: number | null
  cash_amount: number
  limit_per_order: number | null
  status: 'active' | 'inactive'
  version: number
  available_quantity: number
  on_hand_quantity: number
  reserved_quantity: number
  inventory_version: number
}

export interface StoreProductDetail {
  id: string
  store: {
    id: string
    name: string
    logo_url: string | null
    contact_phone: string | null
    address: string | null
    business_hours: string | null
  } | null
  category: StoreCategoryRef | null
  name: string
  subtitle: string | null
  description: string | null
  cover_url: string | null
  gallery_images: string[]
  status: StoreProductStatus
  admin_off_shelf_reason: string | null
  version: number
  published_at: string | null
  off_shelf_at: string | null
  created_at: string | null
  skus: StoreSku[]
}

export interface MyStore {
  id: string
  name: string
  logo_url: string | null
  description: string | null
  status: StoreStatus
  role: 'owner' | 'manager' | 'staff'
  role_label: string
  is_owner: boolean
  reject_reason: string | null
  contact_phone: string
  address: string | null
  business_hours: string | null
  applied_at: string | null
  reviewed_at: string | null
  created_at: string | null
}

export interface StoreCampus {
  campus_id: string
  campus_name: string
  status: 'active' | 'disabled'
  is_primary: boolean
}

export interface StoreProfile {
  id: string
  name: string
  logo_url: string | null
  description: string | null
  contact_phone: string
  address: string | null
  business_hours: string | null
  status: StoreStatus
  reject_reason: string | null
  commission_rate_bps: number
  shipping_fee_amount: number
  free_shipping_threshold: number | null
  applied_at: string | null
  reviewed_at: string | null
  created_at: string | null
  campuses: StoreCampus[]
}

export interface StoreManagerProfile {
  store: StoreProfile
  member: {
    id: string
    role: 'owner' | 'manager' | 'staff'
    role_label: string
    can_manage: boolean
    nickname: string | null
    avatar_url: string | null
    joined_at: string | null
  }
}

export interface StoreManagerSession {
  access_token: string
  token_type: string
  expires_in: number
  expires_at: string
  store: StoreProfile
  member: StoreManagerProfile['member']
}

export interface StoreOverview {
  store: { id: string; name: string; logo_url: string | null; status: StoreStatus; commission_rate_bps: number }
  today: { paid_order_count: number; revenue_amount: number }
  pending_fulfillment_count: number
  product_counts: { total: number; on_sale: number; draft: number; off_shelf: number }
  low_stock_sku_count: number
  unbilled: { item_count: number; gross_amount: number; refunded_amount: number; commission_amount: number; net_amount: number }
}

export interface StoreInventoryItem {
  sku_id: string
  sku_name: string
  specs: Record<string, string>
  product_id: string
  product_name: string
  on_hand_quantity: number
  reserved_quantity: number
  available_quantity: number
  version: number
  low_stock: boolean
}

export interface StoreOrderItemView {
  id: string
  product_name: string
  sku_name: string
  sku_description: string
  image_file_public_id: string | null
  quantity: number
  unit_cash_amount: number
  paid_cash_amount: number
  refunded_cash_amount: number
  refunded_quantity: number
  fulfillment_type: string
}

export interface StoreOrderView {
  id: string
  order_no: string
  status: string
  fulfillment_type: string
  created_at: string | null
  paid_at: string | null
  shipped_at: string | null
  completed_at: string | null
  store_gross_amount: number
  store_refunded_amount: number
  shipping_amount: number
  items: StoreOrderItemView[]
}

export interface StoreSettlementView {
  id: string
  batch_no: string
  store: { id: string; name: string } | null
  campus_id: string | null
  campus_name: string | null
  period_start: string
  period_end: string
  gross_amount: number
  refunded_amount: number
  commission_amount: number
  net_amount: number
  item_count: number
  status: StoreSettlementStatus
  remark: string | null
  confirmed_at: string | null
  paid_at: string | null
  created_at: string | null
}

export interface StoreMemberView {
  id: string
  role: 'owner' | 'manager' | 'staff'
  role_label: string
  status: 'active' | 'disabled'
  nickname: string | null
  avatar_url: string | null
  phone_masked: string | null
  joined_at: string | null
}

export interface StoreProductInput {
  name: string
  subtitle?: string | null
  description?: string | null
  cover_url?: string | null
  gallery_images?: string[]
  category_id?: string | null
  status?: StoreProductStatus
  version?: number
  skus: Array<{
    id?: string
    name: string
    specs?: Record<string, string>
    image_url?: string | null
    fulfillment_type: 'logistics' | 'no_logistics'
    weight_grams?: number | null
    cash_amount: number
    limit_per_order?: number | null
    stock_quantity?: number | null
    status?: 'active' | 'inactive'
  }>
}

export function listStores(params: { keyword?: string; page?: number; page_size?: number } = {}) {
  return apiRequest<{ items: StoreSummary[]; pagination: Pagination }>({ path: `/stores?${buildQueryString(params)}` })
}

export function getStore(storeId: string) {
  return apiRequest<StoreSummary & { categories: StoreCategoryRef[]; products: StoreProductCard[] }>({ path: `/stores/${storeId}` })
}

export function listStoreProducts(storeId: string, params: { category_id?: string; keyword?: string; page?: number; page_size?: number } = {}) {
  return apiRequest<{ items: StoreProductCard[]; pagination: Pagination }>({ path: `/stores/${storeId}/products?${buildQueryString(params)}` })
}

export function getStoreProduct(productId: string) {
  return apiRequest<StoreProductDetail>({ path: `/store-products/${productId}` })
}

export function listMyStores(token: string) {
  return apiRequest<{ items: MyStore[] }>({ path: '/me/stores', accessToken: token })
}

export function applyStore(token: string, payload: {
  name: string
  contact_phone: string
  description?: string
  address?: string
  business_hours?: string
  logo_url?: string | null
}) {
  return apiRequest<MyStore>({ path: '/me/stores', method: 'POST', data: payload, accessToken: token })
}

export function updateMyStore(token: string, storeId: string, payload: Record<string, unknown>) {
  return apiRequest<MyStore>({ path: `/me/stores/${storeId}`, method: 'PUT', data: payload, accessToken: token })
}

export function resubmitStore(token: string, storeId: string) {
  return apiRequest<MyStore>({ path: `/me/stores/${storeId}/resubmit`, method: 'POST', accessToken: token })
}

export function openStoreManagerSession(token: string, storeId: string) {
  return apiRequest<StoreManagerSession>({ path: `/me/stores/${storeId}/manager-session`, method: 'POST', accessToken: token })
}

export function getStoreManagerProfile(managerToken: string) {
  return apiRequest<StoreManagerProfile>({ path: '/store-manager/profile', accessToken: managerToken })
}

export function closeStoreManagerSession(managerToken: string) {
  return apiRequest<{ revoked: boolean }>({ path: '/store-manager/session', method: 'DELETE', accessToken: managerToken })
}

export function getStoreOverview(managerToken: string, campusId?: string) {
  return apiRequest<StoreOverview>({
    path: `/store-manager/overview?${buildQueryString({ campus_id: campusId })}`,
    accessToken: managerToken,
  })
}

export function listStoreCategories(managerToken: string) {
  return apiRequest<{ items: Array<StoreCategoryRef & { sort_order: number; product_count: number }> }>({
    path: '/store-manager/categories', accessToken: managerToken,
  })
}

export function createStoreCategory(managerToken: string, payload: { name: string; sort_order?: number }) {
  return apiRequest<StoreCategoryRef>({ path: '/store-manager/categories', method: 'POST', data: payload, accessToken: managerToken })
}

export function deleteStoreCategory(managerToken: string, categoryId: string) {
  return apiRequest<null>({ path: `/store-manager/categories/${categoryId}`, method: 'DELETE', accessToken: managerToken })
}

export function listManagerProducts(managerToken: string, params: { status?: string; keyword?: string; campus_id?: string; page?: number; page_size?: number } = {}) {
  return apiRequest<{ items: StoreProductDetail[]; pagination: Pagination }>({
    path: `/store-manager/products?${buildQueryString(params)}`, accessToken: managerToken,
  })
}

export function getManagerProduct(managerToken: string, productId: string, campusId?: string) {
  return apiRequest<StoreProductDetail>({
    path: `/store-manager/products/${productId}?${buildQueryString({ campus_id: campusId })}`,
    accessToken: managerToken,
  })
}

export function createManagerProduct(managerToken: string, payload: StoreProductInput, campusId?: string) {
  return apiRequest<StoreProductDetail>({
    path: '/store-manager/products',
    method: 'POST',
    data: { ...payload, ...(campusId ? { campus_id: campusId } : {}) },
    accessToken: managerToken,
  })
}

export function updateManagerProduct(managerToken: string, productId: string, payload: StoreProductInput, campusId?: string) {
  return apiRequest<StoreProductDetail>({
    path: `/store-manager/products/${productId}`,
    method: 'PUT',
    data: { ...payload, ...(campusId ? { campus_id: campusId } : {}) },
    accessToken: managerToken,
  })
}

export function updateManagerProductStatus(managerToken: string, productId: string, action: 'publish' | 'off_shelf', campusId?: string) {
  return apiRequest<StoreProductDetail>({
    path: `/store-manager/products/${productId}/status`,
    method: 'POST',
    data: { action, ...(campusId ? { campus_id: campusId } : {}) },
    accessToken: managerToken,
  })
}

export function listManagerInventory(managerToken: string, params: { keyword?: string; low_stock?: boolean; campus_id?: string; page?: number; page_size?: number } = {}) {
  return apiRequest<{ items: StoreInventoryItem[]; pagination: Pagination }>({
    path: `/store-manager/inventory?${buildQueryString(params)}`, accessToken: managerToken,
  })
}

export function adjustManagerInventory(
  managerToken: string,
  skuId: string,
  payload: { action: 'increase' | 'decrease' | 'set'; quantity: number; version: number; reason?: string },
  campusId?: string,
) {
  return apiRequest<StoreInventoryItem>({
    path: `/store-manager/inventory/${skuId}/adjust`,
    method: 'POST',
    data: { ...payload, ...(campusId ? { campus_id: campusId } : {}) },
    accessToken: managerToken,
    headers: { 'Idempotency-Key': `store-inventory-${skuId}-${campusId ? `${campusId}-` : ''}${payload.action}-${payload.quantity}-${payload.version}` },
  })
}

export function listManagerOrders(managerToken: string, params: { status?: string; keyword?: string; campus_id?: string; page?: number; page_size?: number } = {}) {
  return apiRequest<{ items: StoreOrderView[]; pagination: Pagination }>({
    path: `/store-manager/orders?${buildQueryString(params)}`, accessToken: managerToken,
  })
}

export function getManagerOrder(managerToken: string, orderId: string, campusId?: string) {
  return apiRequest<StoreOrderView>({
    path: `/store-manager/orders/${orderId}?${buildQueryString({ campus_id: campusId })}`,
    accessToken: managerToken,
  })
}

export function listManagerSettlements(managerToken: string, params: { status?: string; campus_id?: string; page?: number; page_size?: number } = {}) {
  return apiRequest<{
    items: StoreSettlementView[]
    unbilled: { item_count: number; gross_amount: number; refunded_amount: number; commission_amount: number; net_amount: number }
    pagination: Pagination
  }>({ path: `/store-manager/settlements?${buildQueryString(params)}`, accessToken: managerToken })
}

export function getManagerSettlement(managerToken: string, settlementId: string) {
  return apiRequest<StoreSettlementView & { items: Array<{ order_item_id: number; order_id: number; gross_amount: number; refunded_amount: number; commission_amount: number; net_amount: number }> }>(
    { path: `/store-manager/settlements/${settlementId}`, accessToken: managerToken },
  )
}

export function listStoreMembers(managerToken: string) {
  return apiRequest<{ items: StoreMemberView[] }>({ path: '/store-manager/members', accessToken: managerToken })
}

export function addStoreMember(managerToken: string, payload: { phone: string; role?: 'manager' | 'staff' }) {
  return apiRequest<StoreMemberView>({ path: '/store-manager/members', method: 'POST', data: payload, accessToken: managerToken })
}

export function removeStoreMember(managerToken: string, memberId: string) {
  return apiRequest<null>({ path: `/store-manager/members/${memberId}`, method: 'DELETE', accessToken: managerToken })
}

export function updateManagerStore(managerToken: string, payload: Record<string, unknown>) {
  return apiRequest<StoreProfile>({ path: '/store-manager/store', method: 'PUT', data: payload, accessToken: managerToken })
}

export function uploadStoreImage(managerToken: string, filePath: string) {
  return new Promise<{ url: string }>((resolve, reject) => {
    uni.uploadFile({
      url: apiUrl('/store-manager/media/assets/upload'),
      filePath,
      name: 'file',
      header: { Authorization: `Bearer ${managerToken}` },
      success(response) {
        try {
          const payload = JSON.parse(response.data) as { data?: { url?: string }; message?: string }
          if (response.statusCode >= 200 && response.statusCode < 300 && payload.data?.url) {
            resolve({ url: payload.data.url })
            return
          }
          reject(new Error(payload.message || '图片上传失败'))
        } catch {
          reject(new Error('图片上传失败'))
        }
      },
      fail(error) {
        reject(new Error(error.errMsg || '图片上传失败'))
      },
    })
  })
}

export const storeStatusLabel = (status: StoreStatus | string) => {
  switch (status) {
    case 'pending': return '待审核'
    case 'active': return '营业中'
    case 'rejected': return '已驳回'
    case 'suspended': return '已暂停'
    case 'closed': return '已关闭'
    default: return status
  }
}

export const storeProductStatusLabel = (status: StoreProductStatus | string) => {
  switch (status) {
    case 'draft': return '草稿'
    case 'on_sale': return '在售'
    case 'off_shelf': return '已下架'
    default: return status
  }
}

export const storeOrderStatusLabel = (status: string) => {
  switch (status) {
    case 'pending_payment': return '待支付'
    case 'pending_fulfillment': return '待发货/待履约'
    case 'partially_refunded': return '部分退款'
    case 'completed': return '已完成'
    case 'cancelled': return '已取消'
    case 'refunded': return '已退款'
    default: return status
  }
}

export const storeSettlementStatusLabel = (status: StoreSettlementStatus | string) => {
  switch (status) {
    case 'pending': return '待确认'
    case 'confirmed': return '已确认'
    case 'paid': return '已打款'
    default: return status
  }
}
