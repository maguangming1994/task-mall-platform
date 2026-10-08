import { apiRequest } from '@/api/http'

export interface PointAccount { available_points: number; frozen_points: number; debt_points: number; spendable: boolean; expiring_points: number; next_expiration_at: string | null; expiring_7_days: number; expiring_30_days: number; points_earned_today: number }
export interface PointBatch { id: string; source_code: string; issued_points: number; remaining_points: number; frozen_points: number; status: string; issued_at: string | null; expires_at: string | null; days_remaining: number | null }
export interface PointTransaction { id: string; type: string; source_code?: string; change_points: number; balance_after: number; debt_after: number; reason: string; created_at: string }
export interface WalletAccount { currency: 'CNY'; available_amount: number; frozen_amount: number; withdrawable_amount: number }
export interface WalletTransaction { id: string; type: string; change_amount: number; balance_after: number; remark: string; created_at: string }
export interface UserCoupon { id: string; name: string; cash_discount_amount: number; points_discount: number; valid_to: string; status: string }
export interface WithdrawalQuote { requested_amount: number; fee_rate: number; fee_amount: number; received_amount: number; minimum_amount: number; maximum_amount: number }
export interface WithdrawalRuleChannel { code: WithdrawalChannel; label: string; arrival_text: string }
export interface WithdrawalRules {
  enabled: boolean
  minimum_amount: number
  maximum_amount: number
  fee_rate: number
  daily_count_limit: number
  daily_amount_limit: number
  today_count: number
  today_amount: number
  daily_count_remaining: number
  daily_amount_remaining: number
  review_mode: string
  arrival_text: string
  channels: WithdrawalRuleChannel[]
}
export type WithdrawalChannel = 'wechat_transfer' | 'bank_card'
export interface WithdrawalBankCard { id: string; cardholder_name: string; bank_name: string; card_tail: string; card_masked: string; phone_masked: string }
export interface Withdrawal { id: string; withdrawal_no: string; requested_amount: number; fee_amount: number; received_amount: number; channel?: WithdrawalChannel; channel_label?: string; bank_card?: WithdrawalBankCard | null; status: string; failure_message?: string | null; workflow?: { status_label: string; next_action: string; is_terminal: boolean }; created_at: string }
export type RechargeMethod = 'card_code' | 'online' | 'offline'
export interface WalletRecharge { id: string; recharge_no: string; method: RechargeMethod; amount_cents: number; status: string; payer_name?: string | null; offline_reference?: string | null; note?: string | null; offline_proof_file_ids: string[]; review_note?: string | null; expires_at?: string | null; succeeded_at?: string | null; created_at: string }
export interface RechargePayment { id: string; payment_no: string; wallet_recharge_id: string; status: string; cash_amount: number; client_payload: ({ kind: 'mweb'; mweb_url: string; sandbox: boolean } | { kind: 'mini_program' | 'jsapi'; appId: string; timeStamp: string; nonceStr: string; package: string; signType: string; paySign: string; sandbox: boolean }) | null }

export function getPointAccount(token: string) { return apiRequest<PointAccount>({ path: '/points/account', accessToken: token }) }
export function listPointBatches(token: string) { return apiRequest<{ items: PointBatch[] }>({ path: '/points/batches', accessToken: token }) }
export function listPointTransactions(token: string) { return apiRequest<{ items: PointTransaction[] }>({ path: '/points/transactions', accessToken: token }) }
export function getWalletAccount(token: string) { return apiRequest<WalletAccount>({ path: '/wallet/account', accessToken: token }) }
export function listWalletTransactions(token: string) { return apiRequest<{ items: WalletTransaction[] }>({ path: '/wallet/transactions', accessToken: token }) }
export function listCoupons(token: string) { return apiRequest<UserCoupon[]>({ path: '/coupons', accessToken: token }) }
export interface CouponCenterItem { id: string; name: string; cash_discount_amount: number; points_discount: number; minimum_cash_amount: number; minimum_points: number; valid_days: number; remaining: number; total_quantity: number; claimed: boolean }
export function listCouponCenter(token: string) { return apiRequest<{ items: CouponCenterItem[] }>({ path: '/coupon-center', accessToken: token }) }
export function claimCoupon(token: string, templateId: string) {
  return apiRequest<UserCoupon>({
    path: `/coupon-center/${encodeURIComponent(templateId)}/claim`,
    method: 'POST',
    accessToken: token,
    headers: { 'Idempotency-Key': `coupon-claim-${templateId}-${Date.now()}` },
  })
}
export function getWithdrawalRules(token: string) { return apiRequest<WithdrawalRules>({ path: '/wallet/withdrawal-rules', accessToken: token }) }
export function getWithdrawalQuote(token: string, amount: number) { return apiRequest<WithdrawalQuote>({ path: `/wallet/withdrawal-quote?amount=${amount}`, accessToken: token }) }
export function createWithdrawal(token: string, amount: number, channel: WithdrawalChannel = 'wechat_transfer', bankCardId?: string) {
  return apiRequest<Withdrawal>({
    path: '/wallet/withdrawals',
    method: 'POST',
    accessToken: token,
    data: { amount, channel, ...(channel === 'bank_card' && bankCardId ? { bank_card_id: bankCardId } : {}) },
    headers: { 'Idempotency-Key': `withdrawal-${Date.now()}-${Math.random().toString(36).slice(2)}` },
  })
}
export function listWithdrawals(token: string) { return apiRequest<{ items: Withdrawal[] }>({ path: '/wallet/withdrawals', accessToken: token }) }
export function listWalletRecharges(token: string) { return apiRequest<{ items: WalletRecharge[] }>({ path: '/wallet/recharges', accessToken: token }) }
export function getWalletRecharge(token: string, id: string) { return apiRequest<WalletRecharge>({ path: `/wallet/recharges/${id}`, accessToken: token }) }
export function createWalletRecharge(token: string, input: Record<string, unknown>, idempotencyKey?: string) { return apiRequest<WalletRecharge>({ path: '/wallet/recharges', method: 'POST', accessToken: token, data: input, headers: { 'Idempotency-Key': idempotencyKey || `recharge-${Date.now()}-${Math.random().toString(36).slice(2)}` } }) }
export function createRechargePayment(token: string, rechargeId: string, scene: string, idempotencyKey?: string) { return apiRequest<RechargePayment>({ path: `/wallet/recharges/${rechargeId}/payments`, method: 'POST', accessToken: token, data: { scene }, headers: { 'Idempotency-Key': idempotencyKey || `recharge-pay-${rechargeId}-${Date.now()}` } }) }
