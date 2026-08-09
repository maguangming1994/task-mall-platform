import { apiRequest } from '@/api/http'

export interface PointAccount { available_points: number; frozen_points: number; debt_points: number; spendable: boolean; expiring_points: number; next_expiration_at: string | null }
export interface PointTransaction { id: string; type: string; source_code?: string; change_points: number; balance_after: number; debt_after: number; reason: string; created_at: string }
export interface WalletAccount { currency: 'CNY'; available_amount: number; frozen_amount: number; withdrawable_amount: number }
export interface WalletTransaction { id: string; type: string; change_amount: number; balance_after: number; remark: string; created_at: string }
export interface UserCoupon { id: string; name: string; cash_discount_amount: number; points_discount: number; valid_to: string; status: string }
export interface WithdrawalQuote { requested_amount: number; fee_rate: number; fee_amount: number; received_amount: number; minimum_amount: number; maximum_amount: number }
export interface Withdrawal { id: string; withdrawal_no: string; requested_amount: number; fee_amount: number; received_amount: number; status: string; created_at: string }

export function getPointAccount(token: string) { return apiRequest<PointAccount>({ path: '/points/account', accessToken: token }) }
export function listPointTransactions(token: string) { return apiRequest<{ items: PointTransaction[] }>({ path: '/points/transactions', accessToken: token }) }
export function getWalletAccount(token: string) { return apiRequest<WalletAccount>({ path: '/wallet/account', accessToken: token }) }
export function listWalletTransactions(token: string) { return apiRequest<{ items: WalletTransaction[] }>({ path: '/wallet/transactions', accessToken: token }) }
export function listCoupons(token: string) { return apiRequest<UserCoupon[]>({ path: '/coupons', accessToken: token }) }
export function getWithdrawalQuote(token: string, amount: number) { return apiRequest<WithdrawalQuote>({ path: `/wallet/withdrawal-quote?amount=${amount}`, accessToken: token }) }
export function createWithdrawal(token: string, amount: number) { return apiRequest<Withdrawal>({ path: '/wallet/withdrawals', method: 'POST', accessToken: token, data: { amount, channel: 'wechat_transfer' }, headers: { 'Idempotency-Key': `withdrawal-${Date.now()}-${Math.random().toString(36).slice(2)}` } }) }
export function listWithdrawals(token: string) { return apiRequest<{ items: Withdrawal[] }>({ path: '/wallet/withdrawals', accessToken: token }) }
