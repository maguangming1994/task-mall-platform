import { apiRequest, apiUrl } from '@/api/http'
import { buildQueryString } from '@/utils/query'

export interface CommunityCategory {
  code: 'experience' | 'guide' | 'question' | 'showcase'
  label: string
}

export interface CommunityAttachment {
  file_id: string
  type: 'image' | 'video'
  url: string | null
}

export interface CommunityPostVersion {
  version_no: number
  title: string
  content: string
  category_code: CommunityCategory['code']
  review_status: 'pending' | 'approved' | 'rejected'
  review_reason_code: string | null
  review_note: string | null
  attachments: CommunityAttachment[]
}

export interface CommunityPost {
  id: string
  author: { id: string; nickname: string }
  title: string
  content: string
  category_code: CommunityCategory['code']
  category_label: string
  status: 'pending_review' | 'published' | 'rejected' | 'hidden' | 'deleted'
  current_version_no: number
  published_version_no: number | null
  comment_count: number
  like_count: number
  liked_by_me: boolean
  reward_granted: boolean
  attachments: CommunityAttachment[]
  latest_version: CommunityPostVersion | null
  can_edit: boolean
  can_delete: boolean
  published_at: string | null
  created_at: string
}

export interface CommunityHomeBanner {
  title: string
  subtitle: string
  image_url: string | null
  link_type: 'posts' | 'activities' | 'tasks' | 'none'
  accent_color?: string
}

export interface CommunityQuickEntry {
  id: string
  label: string
  description?: string
  icon_url?: string | null
  target: 'publish_post' | 'activities' | 'tasks' | 'posts' | 'notifications'
  enabled: boolean
  color?: string
  sort_order?: number
}

export interface CommunityHotTopic {
  id: string
  label: string
  target: string
  enabled: boolean
  color?: string
}

export interface CommunityHomeBrand {
  logo_url: string
  logo_text: string
  name: string
  caption: string
}

export interface CommunityHomeConfig {
  brand: CommunityHomeBrand
  banner: CommunityHomeBanner
  quick_entries: CommunityQuickEntry[]
  hot_topics: CommunityHotTopic[]
  version: number | null
}

export interface CommunityActivityCategory {
  code: string
  label: string
  color?: string
}

export interface CommunityActivityImage {
  file_id: string
  url: string | null
}

export interface CommunityActivityParticipantPreview {
  id: string | null
  nickname: string
  role: 'creator' | 'participant'
}

export interface CommunityActivity {
  id: string
  category_code: string
  category_label: string
  title: string
  details: string
  images: CommunityActivityImage[]
  location: string
  starts_at: string
  participant_limit: number
  participant_count: number
  participant_preview: CommunityActivityParticipantPreview[]
  remaining_slots: number
  status: 'pending_review' | 'approved' | 'rejected' | 'closed'
  joined_by_me: boolean
  is_creator: boolean
  can_join: boolean
  creator: { id: string | null; nickname: string }
  review_reason_code?: string | null
  review_note?: string | null
  reviewed_at?: string | null
  created_at?: string
}

export interface CommunityActivityPayload {
  category_code: string
  title: string
  details: string
  image_file_ids?: string[]
  location: string
  starts_at: string
  participant_limit: number
}

export interface CommunityComment {
  id: string
  post_id: string
  parent_comment_id: string | null
  author: { id: string; nickname: string }
  content: string
  status: string
  reward_granted: boolean
  created_at: string
}

export interface ModerationAction {
  id: string
  content_type: 'post' | 'comment'
  content_id: number
  action_type: string
  reason_code: string
  status: string
  reward_revoked: boolean
  appeal_deadline_at: string | null
  appeal: null | { id: string; status: string; review_note: string | null }
}

export interface CommunityPostPayload {
  title: string
  content: string
  category_code: CommunityCategory['code']
  image_file_ids?: string[]
}

interface PaginatedPosts {
  items: CommunityPost[]
  pagination: { page: number; page_size: number; total: number }
}

export function listCommunityCategories() {
  return apiRequest<{ items: CommunityCategory[] }>({ path: '/community/categories' })
}

export function loadCommunityHome() {
  return apiRequest<CommunityHomeConfig>({ path: '/community/home' })
}

export function communityHomeAssetUrl(value?: string | null) {
  if (!value) return ''
  if (/^(https?:|data:|blob:)/i.test(value) || value.startsWith('/api/')) return value
  return apiUrl(value.startsWith('/') ? value : `/${value}`)
}

export function listCommunityPosts(page = 1, pageSize = 10, category?: CommunityCategory['code'], token?: string) {
  const query = buildQueryString({ page, page_size: pageSize, category })
  return apiRequest<PaginatedPosts>({ path: `/community/posts?${query}`, accessToken: token })
}

export function getCommunityPost(id: string) {
  return apiRequest<CommunityPost>({ path: `/community/posts/${id}` })
}

export function listMyCommunityPosts(token: string, page = 1, pageSize = 20) {
  return apiRequest<PaginatedPosts>({ path: `/community/my-posts?page=${page}&page_size=${pageSize}`, accessToken: token })
}

export function listCommunityComments(postId: string) {
  return apiRequest<{ items: CommunityComment[] }>({ path: `/community/posts/${postId}/comments` })
}

export function createCommunityPost(token: string, payload: CommunityPostPayload) {
  return apiRequest<CommunityPost>({
    path: '/community/posts',
    method: 'POST',
    accessToken: token,
    data: { ...payload },
    headers: { 'Idempotency-Key': `community-post-${Date.now()}-${Math.random()}` },
  })
}

export function updateCommunityPost(token: string, postId: string, payload: CommunityPostPayload) {
  return apiRequest<CommunityPost>({ path: `/community/posts/${postId}`, method: 'PUT', accessToken: token, data: { ...payload } })
}

export function deleteCommunityPost(token: string, postId: string) {
  return apiRequest<{ deleted: boolean }>({ path: `/community/posts/${postId}`, method: 'DELETE', accessToken: token })
}

export function communityAttachmentUrl(attachment: CommunityAttachment) {
  return attachment.url ? apiUrl(attachment.url) : ''
}

export function createCommunityComment(token: string, postId: string, content: string, parentId?: string) {
  return apiRequest<CommunityComment>({ path: `/community/posts/${postId}/comments`, method: 'POST', accessToken: token, data: { content, parent_comment_id: parentId }, headers: { 'Idempotency-Key': `community-comment-${Date.now()}-${Math.random()}` } })
}

export function likeCommunityPost(token: string, postId: string) {
  return apiRequest<{ post_id: string; like_count: number; liked_by_me: boolean }>({
    path: `/community/posts/${postId}/like`, method: 'POST', accessToken: token,
    headers: { 'Idempotency-Key': `community-like-${postId}-${Date.now()}` },
  })
}

export function unlikeCommunityPost(token: string, postId: string) {
  return apiRequest<{ post_id: string; like_count: number; liked_by_me: boolean }>({
    path: `/community/posts/${postId}/like`, method: 'DELETE', accessToken: token,
  })
}

export function listCommunityActivityCategories() {
  return apiRequest<{ items: CommunityActivityCategory[] }>({ path: '/community/activity-categories' })
}

export function listCommunityActivities(filters: { category?: string; page?: number; page_size?: number } = {}, token?: string) {
  const suffix = buildQueryString(filters)
  return apiRequest<{ items: CommunityActivity[]; pagination: { page: number; page_size: number; total: number } }>({
    path: `/community/activities${suffix ? `?${suffix}` : ''}`, accessToken: token,
  })
}

export function getCommunityActivity(id: string, token?: string) {
  return apiRequest<CommunityActivity>({ path: `/community/activities/${id}`, accessToken: token })
}

export function createCommunityActivity(token: string, payload: CommunityActivityPayload) {
  return apiRequest<CommunityActivity>({
    path: '/community/activities', method: 'POST', accessToken: token, data: payload as unknown as Record<string, unknown>,
    headers: { 'Idempotency-Key': `community-activity-${Date.now()}-${Math.random()}` },
  })
}

export function joinCommunityActivity(token: string, id: string) {
  return apiRequest<CommunityActivity>({
    path: `/community/activities/${id}/join`, method: 'POST', accessToken: token,
    headers: { 'Idempotency-Key': `community-activity-join-${id}-${Date.now()}` },
  })
}

export function leaveCommunityActivity(token: string, id: string) {
  return apiRequest<CommunityActivity>({ path: `/community/activities/${id}/join`, method: 'DELETE', accessToken: token })
}

export function listMyCommunityActivities(token: string, scope: 'created' | 'joined') {
  return apiRequest<{ items: CommunityActivity[]; pagination: { page: number; page_size: number; total: number } }>({ path: `/community/my-activities?scope=${scope}`, accessToken: token })
}

export function communityActivityImageUrl(image: CommunityActivityImage) {
  return image.url ? apiUrl(image.url) : ''
}

export function reportCommunityContent(token: string, type: 'post' | 'comment', id: string, reasonCode: string, description?: string) {
  return apiRequest<{ id: string }>({ path: `/community/content/${type}/${id}/reports`, method: 'POST', accessToken: token, data: { reason_code: reasonCode, description }, headers: { 'Idempotency-Key': `community-report-${Date.now()}-${Math.random()}` } })
}

export function listMyModerationActions(token: string) {
  return apiRequest<{ items: ModerationAction[] }>({ path: '/community/my-moderation-actions', accessToken: token })
}

export function appealModerationAction(token: string, id: string, reason: string) {
  return apiRequest<{ id: string; status: string }>({ path: `/community/moderation-actions/${id}/appeals`, method: 'POST', accessToken: token, data: { reason }, headers: { 'Idempotency-Key': `community-appeal-${Date.now()}-${Math.random()}` } })
}
