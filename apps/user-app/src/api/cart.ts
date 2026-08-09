import { apiRequest } from '@/api/http'
import type { PurchaseMode } from './products'

export interface CartItem { id: string; product_id: string; product_name: string; sku_id: string; sku_name: string; specs: Record<string, string>; fulfillment_type: string; purchase_mode: PurchaseMode; quantity: number; selected: boolean; unit_cash_amount: number | null; unit_points: number | null; available_quantity: number; valid: boolean; invalid_reason: string | null }
export interface Cart { items: CartItem[]; summary: { item_count: number; selected_quantity: number; all_valid: boolean } }
export interface ShippingQuote { shipping_cash_amount: number; cash_only: true; groups: Array<{ template_id: string; shipping_cash_amount: number; remote_surcharge_amount: number; free_rule: Record<string, unknown> | null }> }

export function getCart(token: string) { return apiRequest<Cart>({ path: '/cart', accessToken: token }) }
export function addCartItem(token: string, skuId: string, purchaseMode: PurchaseMode, quantity = 1) { return apiRequest<Cart>({ path: '/cart/items', method: 'POST', accessToken: token, data: { sku_id: skuId, purchase_mode: purchaseMode, quantity } }) }
export function updateCartItem(token: string, id: string, data: { quantity?: number; selected?: boolean }) { return apiRequest<Cart>({ path: `/cart/items/${id}`, method: 'PUT', accessToken: token, data }) }
export function deleteCartItem(token: string, id: string) { return apiRequest<void>({ path: `/cart/items/${id}`, method: 'DELETE', accessToken: token }) }
export function quoteShipping(token: string, addressId: string) { return apiRequest<ShippingQuote>({ path: '/shipping/quote', method: 'POST', accessToken: token, data: { address_id: addressId } }) }
