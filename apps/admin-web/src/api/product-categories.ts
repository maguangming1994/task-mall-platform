import { ApiError } from './admin'

const ACCESS_KEY = 'task_mall_admin_access'

interface ApiEnvelope<T> { data: T }
interface ApiFailure { message?: string; code?: string; request_id?: string; details?: { errors?: Record<string, string[]> } }

export interface ManagedProductCategory {
  id: string
  parent_id: string | null
  parent_name: string | null
  name: string
  slug: string
  status: 'active' | 'inactive'
  sort_order: number
  product_count: number
  children_count: number
}

export interface ProductCategoryInput {
  parent_id?: string | null
  name: string
  slug: string
  status: 'active' | 'inactive'
  sort_order: number
}

async function categoryRequest<T>(path: string, init: RequestInit = {}): Promise<T> {
  const headers = new Headers(init.headers)
  headers.set('Accept', 'application/json')
  if (init.body) headers.set('Content-Type', 'application/json')
  const accessToken = sessionStorage.getItem(ACCESS_KEY)
  if (accessToken) headers.set('Authorization', `Bearer ${accessToken}`)
  const response = await fetch(`/api/v1${path}`, { ...init, headers })
  if (response.status === 204) return undefined as T
  const payload = await response.json().catch(() => ({})) as Partial<ApiEnvelope<T>> & ApiFailure
  if (!response.ok) {
    const errors = payload.details?.errors ?? {}
    const firstValidationMessage = Object.values(errors).flat()[0]
    throw new ApiError(firstValidationMessage || payload.message || '商品分类操作失败，请稍后重试。', response.status, payload.code || 'HTTP_ERROR', payload.request_id, errors)
  }
  return payload.data as T
}

export function listProductCategories(): Promise<{ items: ManagedProductCategory[] }> {
  return categoryRequest('/admin/product-categories')
}

export function createProductCategory(input: ProductCategoryInput): Promise<ManagedProductCategory> {
  return categoryRequest('/admin/product-categories', { method: 'POST', body: JSON.stringify(input) })
}

export function updateProductCategory(id: string, input: ProductCategoryInput): Promise<ManagedProductCategory> {
  return categoryRequest(`/admin/product-categories/${encodeURIComponent(id)}`, { method: 'PUT', body: JSON.stringify(input) })
}

export function deleteProductCategory(id: string): Promise<void> {
  return categoryRequest(`/admin/product-categories/${encodeURIComponent(id)}`, { method: 'DELETE' })
}
