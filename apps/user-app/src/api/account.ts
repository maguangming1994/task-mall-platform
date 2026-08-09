import { apiRequest } from '@/api/http'
import type { CurrentUser } from '@/stores/auth'

export interface AddressInput {
  recipient_name: string
  phone: string
  province: string
  city: string
  district: string
  detail: string
  postal_code?: string
  is_default?: boolean
}

export interface UserAddress extends AddressInput {
  id: string
  is_default: boolean
}

export interface AssetOverview {
  points: { available: number; frozen: number; debt: number }
  wallet: { available_amount: number; frozen_amount: number; currency: 'CNY' }
  coupons: { available: number }
  profile: { completed: boolean; reward_status: 'not_eligible' | 'pending' | 'delivered' | 'failed' }
  version: number
}

export function updateProfile(token: string, input: { nickname?: string; avatar_file_id?: string | null }) {
  return apiRequest<CurrentUser>({ path: '/me', method: 'PUT', accessToken: token, data: input })
}

export function loadAssetOverview(token: string) {
  return apiRequest<AssetOverview>({ path: '/me/asset-overview', accessToken: token })
}

export function listAddresses(token: string) {
  return apiRequest<UserAddress[]>({ path: '/addresses', accessToken: token })
}

export function createAddress(token: string, input: AddressInput) {
  return apiRequest<UserAddress>({
    path: '/addresses', method: 'POST', accessToken: token, data: { ...input },
    headers: { 'Idempotency-Key': `address-${Date.now()}-${Math.random().toString(36).slice(2)}` },
  })
}

export function updateAddress(token: string, id: string, input: Partial<AddressInput>) {
  return apiRequest<UserAddress>({ path: `/addresses/${id}`, method: 'PUT', accessToken: token, data: input })
}

export function deleteAddress(token: string, id: string) {
  return apiRequest<void>({ path: `/addresses/${id}`, method: 'DELETE', accessToken: token })
}
