import { apiRequest } from '@/api/http'

export type PurchaseMode = 'cash' | 'points' | 'cash_points'
export interface PurchaseOption { id: string; purchase_mode: PurchaseMode; cash_amount: number; points: number; enabled: boolean; channels: string[] }
export interface ProductSku { id: string; sku_code: string; name: string; specs: Record<string, string>; fulfillment_type: 'logistics' | 'no_logistics' | 'virtual_delivery'; stock: number; purchase_options: PurchaseOption[] }
export interface MallProduct { id: string; name: string; subtitle: string | null; description: string; description_text?: string; cover_file_id: string | null; gallery_images: Array<{ url: string; alt?: string | null; sort_order: number }>; status: string; skus: ProductSku[] }

export function listProducts() { return apiRequest<{ items: MallProduct[] }>({ path: '/products' }) }
export function getProduct(id: string) { return apiRequest<MallProduct>({ path: `/products/${id}` }) }
