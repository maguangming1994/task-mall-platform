import { apiRequest } from './http'

export interface CampusAgentPostItem {
  public_id: string
  title: string
  content?: string
  category_code: string
  category_label: string
  author_name: string
  campus_name: string | null
  view_count: number
  like_count: number
  comment_count: number
  published_at: string | null
  is_mine: boolean
}

export interface CampusAgentIdentity {
  admin_user_id: number
  campus_id: number | null
  nickname: string | null
}

export interface CampusAgentPostListResult {
  items: CampusAgentPostItem[]
  pagination: { page: number; page_size: number; total: number }
  identity: CampusAgentIdentity
}

export function listCampusAgentPosts(
  accessToken: string,
  params: { page?: number; page_size?: number; category?: string } = {},
) {
  const query: Record<string, unknown> = {}
  if (params.page) query.page = params.page
  if (params.page_size) query.page_size = params.page_size
  if (params.category) query.category = params.category

  return apiRequest<CampusAgentPostListResult>({
    path: '/campus-agent/posts',
    method: 'GET',
    data: query,
    accessToken,
  })
}

export function getCampusAgentPost(accessToken: string, publicId: string) {
  return apiRequest<{ item: CampusAgentPostItem }>({
    path: `/campus-agent/posts/${publicId}`,
    method: 'GET',
    accessToken,
  })
}

export function createCampusAgentPost(
  accessToken: string,
  data: { title: string; content: string; category_code: string },
) {
  return apiRequest<{ item: CampusAgentPostItem }>({
    path: '/campus-agent/posts',
    method: 'POST',
    data,
    accessToken,
  })
}

export function deleteCampusAgentPost(accessToken: string, publicId: string) {
  return apiRequest<{ deleted: boolean }>({
    path: `/campus-agent/posts/${publicId}`,
    method: 'DELETE',
    accessToken,
  })
}
