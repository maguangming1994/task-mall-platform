import { adminRequest } from './admin'

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
  return adminRequest<T>(path, init)
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
