import { apiRequest } from '@/api/http'
import type { ClientPlatform } from './runtime'
import type { ModuleCode } from './modules'

export type LoginMethod = 'wechat_mini' | 'wechat_oa' | 'phone'
export type CashPaymentMethodCode = 'wechat' | 'wallet'
export type ReviewStatus = 'reviewing' | 'reviewed'

export interface PaymentMethodCapability {
  code: CashPaymentMethodCode
  label: string
  enabled: boolean
  reason: string | null
}

export interface ModulePlaceholderCopy {
  title: string
  desc: string
}

export interface ClientCapabilities {
  version: number
  platform: ClientPlatform
  /** 本次判定所属校区 public_id（无 X-Campus-Id 时为 null） */
  campus_id?: string | null
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
  review_status: ReviewStatus
  feature_flags: Partial<Record<'checkout_v2' | 'community_video' | 'external_notifications' | ModuleCode, boolean>>
  module_placeholders: Partial<Record<ModuleCode, ModulePlaceholderCopy>>
}

export function loadClientCapabilities() {
  return apiRequest<ClientCapabilities>({ path: '/client-capabilities' })
}
