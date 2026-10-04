import { apiRequest } from '@/api/http'

export interface BankCard {
  id: string
  cardholder_name: string
  bank_name: string
  card_tail: string
  card_masked: string
  phone_masked: string
  is_default: boolean
  created_at: string
}

export function listBankCards(token: string) {
  return apiRequest<{ items: BankCard[] }>({ path: '/wallet/bank-cards', accessToken: token })
}

export function createBankCard(token: string, data: { cardholder_name: string; bank_name: string; card_number: string; phone: string }) {
  return apiRequest<BankCard>({ path: '/wallet/bank-cards', method: 'POST', accessToken: token, data })
}

export function setDefaultBankCard(token: string, id: string) {
  return apiRequest<BankCard>({ path: `/wallet/bank-cards/${id}/default`, method: 'PUT', accessToken: token })
}

export function deleteBankCard(token: string, id: string) {
  return apiRequest<{ id: string; deleted: boolean }>({ path: `/wallet/bank-cards/${id}`, method: 'DELETE', accessToken: token })
}
