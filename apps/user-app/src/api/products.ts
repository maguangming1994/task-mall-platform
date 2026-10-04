import { apiRequest } from '@/api/http'

export type PurchaseMode = 'cash' | 'points' | 'cash_points'
export interface PurchaseOption { id: string; purchase_mode: PurchaseMode; cash_amount: number; points: number; enabled: boolean; channels: string[] }
export interface ProductSku { id: string; sku_code: string; name: string; specs: Record<string, string>; fulfillment_type: 'logistics' | 'no_logistics' | 'virtual_delivery'; stock: number; purchase_options: PurchaseOption[] }
export interface ProductCategory { id: string; name: string; slug: string; sort_order: number; product_count: number }
export interface MallProduct { id: string; name: string; subtitle: string | null; description: string; description_text?: string; cover_file_id: string | null; gallery_images: Array<{ url: string; alt?: string | null; sort_order: number }>; category: Pick<ProductCategory, 'id' | 'name'> | null; status: string; skus: ProductSku[] }

export function listProductCategories() { return apiRequest<{ items: ProductCategory[] }>({ path: '/product-categories' }) }
export function listProducts(categoryId?: string, keyword?: string) {
  const params = new URLSearchParams()
  if (categoryId) params.set('category_id', categoryId)
  if (keyword) params.set('keyword', keyword)
  const qs = params.toString()
  return apiRequest<{ items: MallProduct[] }>({ path: `/products${qs ? `?${qs}` : ''}` })
}
export function getProduct(id: string) { return apiRequest<MallProduct>({ path: `/products/${id}` }) }
