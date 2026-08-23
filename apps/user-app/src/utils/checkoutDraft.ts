import type { CashPaymentMethod, FulfillmentType } from '@/api/orders'
import type { PurchaseMode } from '@/api/products'

/**
 * Checkout drafts deliberately contain only short-lived, non-sensitive intent:
 * product/sku ids, quantities, and the ids of the user's selected options.
 * They never contain an access token, payment credentials, address details, or
 * prices. The server remains the source of truth for all checkout amounts.
 */
export type CheckoutDraftSource = 'cart' | 'direct_buy'

export interface CheckoutDraftItem {
  skuId: string
  quantity: number
  purchaseMode: PurchaseMode
}

export interface CheckoutDraftDirectBuy {
  productId: string
  skuId: string
  purchaseMode: PurchaseMode
}

export interface CheckoutDraft {
  version: 1
  source: CheckoutDraftSource
  ownerUserId: string
  createdAt: number
  updatedAt: number
  expiresAt: number
  addressId?: string
  paymentMethod?: CashPaymentMethod
  selectedItems: CheckoutDraftItem[]
  selectedCoupons: Partial<Record<FulfillmentType, string>>
  directBuy?: CheckoutDraftDirectBuy
}

export interface SaveCheckoutDraftInput {
  source: CheckoutDraftSource
  addressId?: string | null
  paymentMethod?: CashPaymentMethod
  selectedItems?: CheckoutDraftItem[]
  selectedCoupons?: Partial<Record<FulfillmentType, string>>
  directBuy?: CheckoutDraftDirectBuy
}

// A checkout is intentionally resumable for a short period only. Saving an
// active draft refreshes its inactivity window, while stale records are
// removed on read instead of being silently reused.
export const CHECKOUT_DRAFT_TTL_MS = 15 * 60 * 1000

const storagePrefix = 'task_mall_checkout_draft_v1'

function storageKey(source: CheckoutDraftSource) {
  return `${storagePrefix}_${source}`
}

function safeString(value: unknown, maxLength = 160): string | undefined {
  if (typeof value !== 'string' || !value || value.length > maxLength) return undefined
  return value
}

function safePurchaseMode(value: unknown): PurchaseMode | undefined {
  return value === 'cash' || value === 'points' || value === 'cash_points' ? value : undefined
}

function safePaymentMethod(value: unknown): CashPaymentMethod | undefined {
  return value === 'wallet' || value === 'wechat' ? value : undefined
}

function sanitizeItems(value: unknown): CheckoutDraftItem[] {
  if (!Array.isArray(value)) return []
  return value.reduce<CheckoutDraftItem[]>((items, candidate) => {
    if (!candidate || typeof candidate !== 'object') return items
    const item = candidate as Record<string, unknown>
    const skuId = safeString(item.skuId)
    const purchaseMode = safePurchaseMode(item.purchaseMode)
    const quantity = Number(item.quantity)
    if (!skuId || !purchaseMode || !Number.isInteger(quantity) || quantity < 1 || quantity > 999) return items
    items.push({ skuId, quantity, purchaseMode })
    return items
  }, [])
}

function sanitizeCoupons(value: unknown): Partial<Record<FulfillmentType, string>> {
  if (!value || typeof value !== 'object') return {}
  const source = value as Record<string, unknown>
  const coupons: Partial<Record<FulfillmentType, string>> = {}
  for (const type of ['logistics', 'no_logistics', 'virtual_delivery'] as FulfillmentType[]) {
    const couponId = safeString(source[type])
    if (couponId) coupons[type] = couponId
  }
  return coupons
}

function sanitizeDirectBuy(value: unknown): CheckoutDraftDirectBuy | undefined {
  if (!value || typeof value !== 'object') return undefined
  const source = value as Record<string, unknown>
  const productId = safeString(source.productId)
  const skuId = safeString(source.skuId)
  const purchaseMode = safePurchaseMode(source.purchaseMode)
  if (!productId || !skuId || !purchaseMode) return undefined
  return { productId, skuId, purchaseMode }
}

export function saveCheckoutDraft(ownerUserId: string | undefined, input: SaveCheckoutDraftInput) {
  if (!ownerUserId) return
  const now = Date.now()
  const existing = readCheckoutDraft(ownerUserId, input.source)
  const draft: CheckoutDraft = {
    version: 1,
    source: input.source,
    ownerUserId,
    createdAt: existing?.createdAt || now,
    updatedAt: now,
    expiresAt: now + CHECKOUT_DRAFT_TTL_MS,
    ...(safeString(input.addressId) ? { addressId: safeString(input.addressId) } : {}),
    ...(safePaymentMethod(input.paymentMethod) ? { paymentMethod: safePaymentMethod(input.paymentMethod) } : {}),
    selectedItems: sanitizeItems(input.selectedItems),
    selectedCoupons: sanitizeCoupons(input.selectedCoupons),
    ...(input.directBuy ? { directBuy: sanitizeDirectBuy(input.directBuy) } : {}),
  }
  try {
    uni.setStorageSync(storageKey(input.source), draft)
  } catch {
    // Storage can be unavailable or full; checkout must continue online.
  }
}

export function readCheckoutDraft(ownerUserId: string | undefined, source: CheckoutDraftSource): CheckoutDraft | null {
  if (!ownerUserId) return null
  let stored: unknown
  try {
    stored = uni.getStorageSync(storageKey(source))
  } catch {
    return null
  }
  if (!stored || typeof stored !== 'object') return null
  const value = stored as Record<string, unknown>
  const expiresAt = Number(value.expiresAt)
  const updatedAt = Number(value.updatedAt)
  const now = Date.now()
  const hasInvalidWindow = !Number.isFinite(expiresAt) || !Number.isFinite(updatedAt) || updatedAt <= 0 || expiresAt <= now || expiresAt < updatedAt || expiresAt - updatedAt > CHECKOUT_DRAFT_TTL_MS || updatedAt > now + 60 * 1000
  if (value.version !== 1 || value.source !== source || value.ownerUserId !== ownerUserId || hasInvalidWindow) {
    clearCheckoutDraft(ownerUserId, source)
    return null
  }
  return {
    version: 1,
    source,
    ownerUserId,
    createdAt: Number(value.createdAt) || updatedAt,
    updatedAt,
    expiresAt,
    ...(safeString(value.addressId) ? { addressId: safeString(value.addressId) } : {}),
    ...(safePaymentMethod(value.paymentMethod) ? { paymentMethod: safePaymentMethod(value.paymentMethod) } : {}),
    selectedItems: sanitizeItems(value.selectedItems),
    selectedCoupons: sanitizeCoupons(value.selectedCoupons),
    ...(sanitizeDirectBuy(value.directBuy) ? { directBuy: sanitizeDirectBuy(value.directBuy) } : {}),
  }
}

export function clearCheckoutDraft(ownerUserId: string | undefined, source: CheckoutDraftSource) {
  if (!ownerUserId) return
  try {
    const stored = uni.getStorageSync(storageKey(source))
    if (stored && typeof stored === 'object' && (stored as Record<string, unknown>).ownerUserId === ownerUserId) uni.removeStorageSync(storageKey(source))
  } catch {
    // Best effort cleanup; a stale record will be rejected by readCheckoutDraft.
  }
}
