import { apiRequest } from '@/api/http'

export interface ChatUser { id: string; nickname: string; avatar: string | null }

export interface ChatConversation {
  id: string
  other_user: ChatUser | null
  last_message: string | null
  last_message_type: string
  last_message_at: string | null
  unread: number
  item: { id: string; title: string; cover: string | null; price: number } | null
}

export interface ChatMessage {
  id: string
  sender_id: string
  mine: boolean
  type: 'text' | 'image'
  content: string
  created_at: string
}

// 会话列表
export function listChatConversations(token: string) {
  return apiRequest<{ items: ChatConversation[] }>({ path: '/chat/conversations', accessToken: token })
}

// 创建/获取会话
export function ensureChatConversation(token: string, data: { user_id: string; item_id?: string; order_id?: string }) {
  return apiRequest<{ id: string }>({ path: '/chat/conversations', method: 'POST', accessToken: token, data: data as unknown as Record<string, unknown> })
}

// 消息列表
export function listChatMessages(token: string, conversationId: string, params: { page?: number; page_size?: number }) {
  const q = new URLSearchParams()
  Object.entries(params).forEach(([k, v]) => { if (v !== undefined && v !== null && String(v) !== '') q.set(k, String(v)) })
  const qs = q.toString()
  return apiRequest<{ items: ChatMessage[]; pagination: { page: number; page_size: number; total: number }; conversation: { id: string; other_user: ChatUser | null; item: { id: string; title: string; cover: string | null; price: number } | null } }>({ path: `/chat/conversations/${conversationId}/messages${qs ? `?${qs}` : ''}`, accessToken: token })
}

// 发消息
export function sendChatMessage(token: string, conversationId: string, data: { type?: 'text' | 'image'; content: string }) {
  return apiRequest<ChatMessage>({ path: `/chat/conversations/${conversationId}/messages`, method: 'POST', accessToken: token, data: data as unknown as Record<string, unknown> })
}

// 标记已读
export function readChatConversation(token: string, conversationId: string) {
  return apiRequest<{ id: string; unread: number }>({ path: `/chat/conversations/${conversationId}/read`, method: 'POST', accessToken: token })
}
