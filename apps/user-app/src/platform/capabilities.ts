import { apiRequest } from '@/api/http'
import type { ClientPlatform } from './runtime'

export type LoginMethod = 'wechat_mini' | 'wechat_oa' | 'phone'
export type CashPaymentMethodCode = 'wechat' | 'wallet'

export interface PaymentMethodCapability {
  code: CashPaymentMethodCode
  label: string
  enabled: boolean
  reason: string | null
}

export interface ClientCapabilities {
  version: number
  platform: ClientPlatform
  visitor_browsing: boolean
  login_methods: LoginMethod[]
  wechat_official_account_app_id: string | null
  share_methods: string[]
  cash_payment_methods: CashPaymentMethodCode[]
  payment_methods: PaymentMethodCapability[]
  wechat_payment_enabled: boolean
  wallet_payment_enabled: boolean
  payment_unavailable_message: string | null
  wechat_payment_unavailable_message: string | null
  wallet_payment_unavailable_message: string | null
  feature_flags: Record<'checkout_v2' | 'community_video' | 'external_notifications', boolean>
}

export function loadClientCapabilities() {
  return apiRequest<ClientCapabilities>({ path: '/client-capabilities' })
}
