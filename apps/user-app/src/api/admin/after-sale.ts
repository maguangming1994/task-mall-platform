import { adminRequest } from './http'

export interface AdminRefund {
  id: string
  refund_no: string
  order_no?: string | null
  user?: { id: string; nickname: string } | null
  service_type: string
  reason_name: string
  status: string
  refund_cash_amount: number
  refund_points: number
  responsibility_party: string
  return_shipping_payer: string
  return_method: string | null
  review_note?: string | null
  user_action_deadline_at: string | null
  created_at: string
}

export interface AdminSecondHandRefund {
  id: string
  order_id: string
  amount: number
  reason: string
  images: string[]
  status: string
  seller_note: string | null
  seller_responded_at: string | null
  admin_note: string | null
  arbitrated_at: string | null
  auto_approve_at: string | null
  buyer: { id: string; nickname: string; avatar: string | null }
  seller: { id: string; nickname: string; avatar: string | null }
  created_at: string
}

/** 商城退款无分页，服务端最多返回 100 条 */
export function listManagedRefunds(params: { status?: string } = {}) {
  return adminRequest<{ items: AdminRefund[] }>({
    path: '/admin/refunds',
    params: { status: params.status },
  })
}

export function reviewManagedRefund(
  id: string,
  input: {
    decision: 'approve' | 'reject'
    note: string
    responsibility_party?: string
    return_shipping_payer?: string
    return_method?: string
  },
) {
  return adminRequest<AdminRefund>({
    path: `/admin/refunds/${encodeURIComponent(id)}/review`,
    method: 'POST',
    data: input,
  })
}

export function receiveManagedReturn(id: string, note: string) {
  return adminRequest<AdminRefund>({
    path: `/admin/refunds/${encodeURIComponent(id)}/receive-return`,
    method: 'POST',
    data: { note },
  })
}

export function listSecondHandRefunds(params: { status?: string; page?: number; page_size?: number } = {}) {
  return adminRequest<{ items: AdminSecondHandRefund[]; pagination: { page: number; page_size: number; total: number } }>({
    path: '/admin/second-hand/refunds',
    params: { status: params.status, page: params.page, page_size: params.page_size ?? 20 },
  })
}

export function arbitrateSecondHandRefund(id: string, input: { decision: 'approve' | 'reject'; note: string }) {
  return adminRequest<AdminSecondHandRefund>({
    path: `/admin/second-hand/refunds/${encodeURIComponent(id)}/arbitrate`,
    method: 'POST',
    data: input,
  })
}
