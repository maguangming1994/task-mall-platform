import { apiRequest, apiUrl } from '@/api/http'
import { buildQueryString } from '@/utils/query'

export interface CommunityCategory {
  code: 'experience' | 'guide' | 'question' | 'showcase'
  label: string
}

export interface CommunityPublishingRuleRange {
  min_length: number
  max_length: number
}

export interface CommunityPublishingRules {
  post: {
    title: CommunityPublishingRuleRange
    content: CommunityPublishingRuleRange
  }
  comment: CommunityPublishingRuleRange
}

export const DEFAULT_COMMUNITY_PUBLISHING_RULES: CommunityPublishingRules = {
  post: {
    title: { min_length: 5, max_length: 60 },
    content: { min_length: 20, max_length: 5000 },
  },
  comment: { min_length: 2, max_length: 500 },
}

export interface CommunityCategoriesResult {
  items: CommunityCategory[]
  publishing_rules: CommunityPublishingRules
  post_reward_points: number
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

export type CommunityFeed = 'discover' | 'following' | 'nearby' | 'featured'
export type CommunityVisibility = 'public' | 'followers' | 'anonymous'

export interface CommunityPostAuthor {
  id: string | null
  nickname: string
  avatar_url: string | null
  campus_name: string | null
  verified: boolean
  is_followed: boolean
  is_self: boolean
}

export interface CommunityPost {
  id: string
  author: CommunityPostAuthor
  title: string
  content: string
  category_code: CommunityCategory['code']
  category_label: string
  location_name: string | null
  visibility: CommunityVisibility
  location_latitude: number | null
  location_longitude: number | null
  status: 'pending_review' | 'published' | 'rejected' | 'hidden' | 'deleted'
  current_version_no: number
  published_version_no: number | null
  comment_count: number
  like_count: number
  liked_by_me: boolean
  favorite_count: number
  favorited_by_me: boolean
  reward_granted: boolean
  attachments: CommunityAttachment[]
  latest_version: CommunityPostVersion | null
  can_edit: boolean
  can_delete: boolean
  published_at: string | null
  created_at: string
}

export interface CommunitySocialStats {
  follower_count: number
  following_count: number
  is_followed: boolean
}

export type CommunityUserContentType = 'posts' | 'second_hand' | 'activities' | 'tasks' | 'claims' | 'impressions'

export interface CommunityUserProfile {
  id: string
  uid: string
  nickname: string
  avatar_url: string | null
  bio: string
  school_name: string | null
  campus_name: string | null
  college: string | null
  grade_year: number | null
  major: string | null
  verified: boolean
  is_self: boolean
  is_followed: boolean
  is_blocked: boolean
  can_message: boolean
  follower_count: number
  following_count: number
  visitor_count: number
  recent_visitors: Array<{ id: string; nickname: string; avatar_url: string | null; visited_at: string | null }>
  rating_average: number | null
  rating_count: number
  content_counts: Record<CommunityUserContentType, number>
}

export interface CommunityUserContentItem {
  id: string
  task_id?: string | null
  title?: string
  content?: string | null
  description?: string | null
  details?: string | null
  category_code?: string
  category_label?: string
  location_name?: string | null
  location?: string | null
  like_count?: number
  comment_count?: number
  attachments?: CommunityAttachment[]
  price?: number
  condition?: string
  condition_label?: string
  cover?: string | null
  category?: { id: string; name: string } | null
  starts_at?: string | null
  participant_count?: number
  participant_limit?: number
  task_type?: string | null
  status?: string
  approved_at?: string | null
  published_at?: string | null
  created_at?: string | null
  score?: number
  images?: string[]
  reply_content?: string | null
  rater?: { id: string; nickname: string; avatar_url: string | null } | null
}

export interface CommunityUserContentResult {
  type: CommunityUserContentType
  items: CommunityUserContentItem[]
  pagination: { page: number; page_size: number; total: number; has_more: boolean }
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
  location_name?: string | null
  visibility?: CommunityVisibility
  location_latitude?: number | null
  location_longitude?: number | null
  image_file_ids?: string[]
}

interface PaginatedPosts {
  items: CommunityPost[]
  pagination: { page: number; page_size: number; total: number }
}

export function listCommunityCategories() {
  return apiRequest<CommunityCategoriesResult>({ path: '/community/categories' })
}

export function loadCommunityHome() {
  return apiRequest<CommunityHomeConfig>({ path: '/community/home' })
}

export function communityHomeAssetUrl(value?: string | null) {
  if (!value) return ''
  if (/^(https?:|data:|blob:)/i.test(value) || value.startsWith('/api/')) return value
  return apiUrl(value.startsWith('/') ? value : `/${value}`)
}

export function listCommunityPosts(
  page = 1,
  pageSize = 10,
  category?: CommunityCategory['code'],
  token?: string,
  feed?: CommunityFeed,
) {
  const query = buildQueryString({ page, page_size: pageSize, category, feed })
  return apiRequest<PaginatedPosts>({ path: `/community/posts?${query}`, accessToken: token })
}

export function getCommunityPost(id: string, token?: string) {
  return apiRequest<CommunityPost>({ path: `/community/posts/${id}`, accessToken: token })
}

export function listMyCommunityPosts(token: string, page = 1, pageSize = 20) {
  return apiRequest<PaginatedPosts>({ path: `/community/my-posts?page=${page}&page_size=${pageSize}`, accessToken: token })
}

export function listCommunityComments(postId: string, token?: string) {
  return apiRequest<{ items: CommunityComment[] }>({ path: `/community/posts/${postId}/comments`, accessToken: token })
}

export function createCommunityRequestKey(scope: 'post' | 'comment') {
  return `community-${scope}-${Date.now()}-${Math.random().toString(36).slice(2, 12)}`
}

export function createCommunityPost(token: string, payload: CommunityPostPayload, idempotencyKey = createCommunityRequestKey('post')) {
  return apiRequest<CommunityPost>({
    path: '/community/posts',
    method: 'POST',
    accessToken: token,
    data: { ...payload },
    headers: { 'Idempotency-Key': idempotencyKey },
  })
}

export function updateCommunityPost(token: string, postId: string, payload: CommunityPostPayload) {
  return apiRequest<CommunityPost>({ path: `/community/posts/${postId}`, method: 'PUT', accessToken: token, data: { ...payload } })
}

export function deleteCommunityPost(token: string, postId: string) {
  return apiRequest<{ deleted: boolean }>({ path: `/community/posts/${postId}`, method: 'DELETE', accessToken: token })
}

export function communityAttachmentUrl(attachment: CommunityAttachment) {
  if (!attachment.url) return ''
  return /^(https?:|data:|blob:)/i.test(attachment.url) ? attachment.url : apiUrl(attachment.url)
}

export function createCommunityComment(token: string, postId: string, content: string, parentId?: string, idempotencyKey = createCommunityRequestKey('comment')) {
  return apiRequest<CommunityComment>({ path: `/community/posts/${postId}/comments`, method: 'POST', accessToken: token, data: { content, parent_comment_id: parentId }, headers: { 'Idempotency-Key': idempotencyKey } })
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

export function favoriteCommunityPost(token: string, postId: string) {
  return apiRequest<{ post_id: string; favorite_count: number; favorited_by_me: boolean }>({
    path: `/community/posts/${postId}/favorite`, method: 'POST', accessToken: token,
    headers: { 'Idempotency-Key': `community-favorite-${postId}-${Date.now()}` },
  })
}

export function unfavoriteCommunityPost(token: string, postId: string) {
  return apiRequest<{ post_id: string; favorite_count: number; favorited_by_me: boolean }>({
    path: `/community/posts/${postId}/favorite`, method: 'DELETE', accessToken: token,
  })
}

export function followCommunityUser(token: string, userId: string) {
  return apiRequest<CommunitySocialStats & { user_id: string }>({
    path: `/community/users/${userId}/follow`, method: 'POST', accessToken: token,
    headers: { 'Idempotency-Key': `community-follow-${userId}-${Date.now()}` },
  })
}

export function unfollowCommunityUser(token: string, userId: string) {
  return apiRequest<CommunitySocialStats & { user_id: string }>({
    path: `/community/users/${userId}/follow`, method: 'DELETE', accessToken: token,
  })
}

export function getCommunitySocialStats(token: string) {
  return apiRequest<CommunitySocialStats>({ path: '/community/social/stats', accessToken: token })
}

export function getCommunityUserProfile(userId: string, token?: string) {
  return apiRequest<CommunityUserProfile>({ path: `/community/users/${userId}/profile`, accessToken: token })
}

export function listCommunityUserContent(userId: string, type: CommunityUserContentType, page = 1, pageSize = 10, token?: string) {
  const query = buildQueryString({ type, page, page_size: pageSize })
  return apiRequest<CommunityUserContentResult>({ path: `/community/users/${userId}/content?${query}`, accessToken: token })
}

export function blockCommunityUser(token: string, userId: string) {
  return apiRequest<{ user_id: string; is_blocked: boolean; is_followed: boolean }>({
    path: `/community/users/${userId}/block`, method: 'POST', accessToken: token,
    headers: { 'Idempotency-Key': `community-block-${userId}-${Date.now()}` },
  })
}

export function unblockCommunityUser(token: string, userId: string) {
  return apiRequest<{ user_id: string; is_blocked: boolean; is_followed: boolean }>({
    path: `/community/users/${userId}/block`, method: 'DELETE', accessToken: token,
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
