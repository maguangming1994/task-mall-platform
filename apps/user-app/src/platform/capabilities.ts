import { apiRequest } from '@/api/http'
import type { ClientPlatform } from './runtime'

export type LoginMethod = 'wechat_mini' | 'wechat_oa' | 'phone'

export interface ClientCapabilities {
  version: number
  platform: ClientPlatform
  visitor_browsing: boolean
  login_methods: LoginMethod[]
  share_methods: string[]
  cash_payment_methods: string[]
  wechat_payment_enabled: boolean
  wallet_payment_enabled: boolean
  feature_flags: Record<'checkout_v2' | 'community_video' | 'external_notifications', boolean>
}

export function loadClientCapabilities() {
  return apiRequest<ClientCapabilities>({ path: '/client-capabilities' })
}
