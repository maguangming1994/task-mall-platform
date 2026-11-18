import { apiRequest } from '@/api/http'
import type { ClientPlatform } from './runtime'
import type { ModuleCode } from './modules'

export type LoginMethod = 'wechat_mini' | 'wechat_oa' | 'phone'
export type CashPaymentMethodCode = 'wechat' | 'wallet'
export type ReviewStatus = 'reviewing' | 'reviewed'
export type CampusIsolationMode = 'legacy' | 'strict'

export interface CampusCapability {
  id: string
  name: string
  isolation_mode: CampusIsolationMode
}

export interface CampusChangeCapability {
  cooldown_days: number
  bound_at: string | null
  next_available_at: string | null
  can_change: boolean
}

export interface CampusProfileCapability {
  grade_year: number | null
  major: string | null
  college: string | null
}

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
  /** 登录用户未绑校或校园档案不完整时需要先完成绑校 */
  campus_required: boolean
  /** 当前账号所属学校（未绑校时为 null） */
  campus: CampusCapability | null
  /** 换校冷却状态（未绑校时为 null） */
  campus_change: CampusChangeCapability | null
  /** 账号的年级 / 专业 / 学院档案 */
  campus_profile: CampusProfileCapability | null
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

/** 与 stores/auth 保持一致的本地登录态存储键（避免 import 形成循环依赖） */
const AUTH_STORAGE_KEY = 'task_mall_auth'

function storedAccessToken(): string | null {
  try {
    const stored = uni.getStorageSync(AUTH_STORAGE_KEY) as { accessToken?: unknown } | null
    if (stored && typeof stored === 'object' && typeof stored.accessToken === 'string' && stored.accessToken) {
      return stored.accessToken
    }
  } catch {
    // ignore storage errors
  }
  return null
}

export function loadClientCapabilities() {
  // 登录态下必须携带 token：服务端按账号校区判定 campus_required / campus / 模块开关
  return apiRequest<ClientCapabilities>({ path: '/client-capabilities', accessToken: storedAccessToken() })
}
