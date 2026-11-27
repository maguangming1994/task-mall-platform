import { adminRequest } from './admin'

export interface CampusAgentBindingItem {
  public_id: string
  admin_user_id: number
  admin_name: string | null
  admin_email: string | null
  app_id: string
  nickname_snapshot: string | null
  bound_at: string | null
  last_used_at: string | null
}

export interface CampusAgentBindingListResult {
  items: CampusAgentBindingItem[]
  pagination: { page: number; page_size: number; total: number }
}

export interface CampusAgentPostItem {
  public_id: string
  title: string
  content: string
  category_code: string
  status: string
  author_name: string | null
  campus_name: string | null
  view_count: number
  published_at: string | null
}

export interface CampusAgentPostListResult {
  items: CampusAgentPostItem[]
  pagination: { page: number; page_size: number; total: number }
}

export function listCampusAgentBindings(page = 1, pageSize = 20) {
  return adminRequest<CampusAgentBindingListResult>(
    `/admin/campus-agent-bindings?page=${page}&page_size=${pageSize}`,
  )
}

export function createCampusAgentBinding(data: {
  openid: string
  admin_user_id: number
  app_id?: string
  nickname_snapshot?: string
}) {
  return adminRequest<{ public_id: string }>('/admin/campus-agent-bindings', {
    method: 'POST',
    body: JSON.stringify(data),
  })
}

export function deleteCampusAgentBinding(publicId: string) {
  return adminRequest<{ deleted: boolean }>(`/admin/campus-agent-bindings/${publicId}`, {
    method: 'DELETE',
  })
}

export function listCampusAgentPosts(page = 1, pageSize = 20) {
  return adminRequest<CampusAgentPostListResult>(
    `/admin/campus-agent-bindings/posts?page=${page}&page_size=${pageSize}`,
  )
}

export function toggleCampusAgentPost(publicId: string, status: 'published' | 'hidden') {
  return adminRequest<{ public_id: string; status: string }>(
    `/admin/campus-agent-bindings/posts/${publicId}/toggle`,
    { method: 'POST', body: JSON.stringify({ status }) },
  )
}
