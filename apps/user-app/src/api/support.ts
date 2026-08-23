import { apiRequest } from '@/api/http'

export type SupportTicketStatus = 'open' | 'processing' | 'waiting_user' | 'waiting_service' | 'resolved' | 'closed'
export type SupportTicketCategory = 'task' | 'order' | 'after_sale' | 'assets' | 'account' | 'community' | 'other'

export interface SupportTicketMessage {
  id: string
  sender_type: 'user' | 'admin'
  content: string
  attachment_file_ids: string[]
  created_at: string
}

export interface SupportTicket {
  id: string
  category: SupportTicketCategory
  subject: string
  status: SupportTicketStatus
  priority: 'low' | 'normal' | 'high' | 'urgent'
  workflow: { status_label: string; next_action: string; can_reply: boolean; can_close: boolean }
  related_type: string | null
  related_public_id: string | null
  created_at: string
  updated_at: string
  messages: SupportTicketMessage[]
}

export function listSupportTickets(accessToken: string) {
  return apiRequest<{ items: SupportTicket[] }>({ path: '/support-tickets', accessToken })
}

export function createSupportTicket(accessToken: string, input: { category: SupportTicketCategory; subject: string; content: string }) {
  return apiRequest<SupportTicket>({ path: '/support-tickets', method: 'POST', accessToken, data: input })
}

export function replySupportTicket(accessToken: string, id: string, content: string) {
  return apiRequest<SupportTicket>({ path: `/support-tickets/${id}/messages`, method: 'POST', accessToken, data: { content } })
}

export function closeSupportTicket(accessToken: string, id: string) {
  return apiRequest<SupportTicket>({ path: `/support-tickets/${id}/close`, method: 'POST', accessToken })
}
