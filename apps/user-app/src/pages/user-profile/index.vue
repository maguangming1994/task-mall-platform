<template>
  <view class="page">
    <view v-if="loading" class="loading-page">
      <view class="loading-hero tm-skeleton" />
      <view class="loading-card tm-skeleton" />
      <view class="loading-list tm-skeleton" />
    </view>

    <view v-else-if="loadError" class="page-state">
      <view class="state-symbol">!</view>
      <text class="state-title">主页暂时打不开</text>
      <text class="state-copy">{{ loadError }}</text>
      <button class="primary-btn" @click="initialize">重新加载</button>
      <button class="plain-btn" @click="goBack">返回上一页</button>
    </view>

    <template v-else-if="profile">
      <view class="profile-hero">
        <image v-if="profile.avatar_url" class="hero-bg" :src="profile.avatar_url" mode="aspectFill" />
        <view class="hero-shade" />
        <view class="topnav">
          <button class="nav-circle" aria-label="返回" @click="goBack">‹</button>
          <text class="nav-title">个人主页</text>
          <button v-if="!profile.is_self" class="nav-circle nav-more" aria-label="更多操作" @click="openMore">•••</button>
          <view v-else class="nav-circle nav-placeholder" />
        </view>

        <view class="identity-row">
          <view class="avatar-ring">
            <image v-if="profile.avatar_url" class="profile-avatar" :src="profile.avatar_url" mode="aspectFill" />
            <view v-else class="profile-avatar avatar-fallback">{{ avatarText(profile.nickname) }}</view>
          </view>
          <view class="visitor-chip">
            <view class="visitor-avatars">
              <template v-for="visitor in profile.recent_visitors.slice(0, 3)" :key="visitor.id">
                <image v-if="visitor.avatar_url" :src="visitor.avatar_url" mode="aspectFill" @click="openUser(visitor.id)" />
                <text v-else @click="openUser(visitor.id)">{{ avatarText(visitor.nickname) }}</text>
              </template>
            </view>
            <text>访客 {{ profile.visitor_count }}</text>
            <text class="visitor-arrow">›</text>
          </view>
        </view>

        <view class="name-row">
          <text class="nickname">{{ profile.nickname }}</text>
          <text v-if="profile.verified" class="verified">已认证</text>
        </view>
        <text class="uid">UID：{{ profile.uid }}</text>
        <view class="profile-tags">
          <text v-if="profile.school_name" class="profile-tag school-tag">{{ profile.school_name }}</text>
          <text v-if="profile.grade_year" class="profile-tag">{{ profile.grade_year }}级</text>
          <text v-if="profile.college" class="profile-tag">{{ profile.college }}</text>
          <text v-if="profile.major" class="profile-tag">{{ profile.major }}</text>
        </view>
        <text class="bio">{{ profile.bio || '这个人很真诚，简介还在酝酿中。' }}</text>

        <view class="social-row">
          <view class="social-item"><text class="social-number">{{ profile.following_count }}</text><text class="social-label">关注</text></view>
          <view class="social-item"><text class="social-number">{{ profile.follower_count }}</text><text class="social-label">粉丝</text></view>
          <view class="social-item"><text class="social-number">{{ profile.content_counts.posts }}</text><text class="social-label">动态</text></view>
          <view v-if="profile.rating_average !== null" class="rating-chip">★ {{ profile.rating_average }} 信用印象</view>
        </view>
      </view>

      <view class="action-card">
        <template v-if="profile.is_self">
          <button class="action-btn edit-btn" @click="editProfile">编辑资料</button>
        </template>
        <template v-else-if="profile.is_blocked">
          <view class="blocked-copy"><text class="blocked-title">已拉黑该用户</text><text class="blocked-sub">对方的内容和私信已屏蔽</text></view>
          <button class="action-btn unblock-btn" :disabled="actionLoading" @click="unblockUser">解除拉黑</button>
        </template>
        <template v-else>
          <button :class="['action-btn', 'follow-action', { followed: profile.is_followed }]" :disabled="actionLoading" @click="toggleFollow">{{ profile.is_followed ? '已关注' : '＋ 关注' }}</button>
          <button class="action-btn message-action" :disabled="actionLoading" @click="contact">私信</button>
        </template>
      </view>

      <scroll-view class="tabs-scroll" scroll-x :show-scrollbar="false" enhanced :bounces="false">
        <view class="content-tabs">
          <view
            v-for="tab in tabs"
            :key="tab.code"
            :class="['content-tab', { active: activeTab === tab.code }]"
            @click="switchTab(tab.code)"
          >
            <text>{{ tab.label }}</text>
            <text class="tab-count">{{ profile.content_counts[tab.code] }}</text>
            <view v-if="activeTab === tab.code" class="tab-indicator" />
          </view>
        </view>
      </scroll-view>

      <view v-if="profile.is_blocked" class="content-state blocked-state">
        <view class="state-symbol small">隐</view>
        <text class="state-title">内容已屏蔽</text>
        <text class="state-copy">解除拉黑后，可重新查看 TA 的公开内容。</text>
      </view>

      <template v-else>
        <view v-if="contentLoading && !items.length" class="content-skeleton">
          <view v-for="index in 3" :key="index" class="content-card skeleton-card tm-skeleton" />
        </view>
        <view v-else-if="contentError && !items.length" class="content-state">
          <text class="state-title">内容加载失败</text>
          <text class="state-copy">{{ contentError }}</text>
          <button class="retry-btn" @click="loadContent(true)">重新加载</button>
        </view>
        <view v-else-if="!items.length" class="content-state">
          <view class="state-symbol small">{{ emptyContent.icon }}</view>
          <text class="state-title">{{ emptyContent.title }}</text>
          <text class="state-copy">{{ emptyContent.copy }}</text>
        </view>

        <view v-else class="content-list">
          <template v-for="item in items" :key="item.id">
            <view v-if="activeTab === 'posts'" class="content-card post-card" @click="openItem(item)">
              <view class="card-kicker"><text># {{ item.category_label }}</text><text>{{ formatTime(item.published_at || item.created_at) }}</text></view>
              <text class="card-title">{{ item.title }}</text>
              <text class="card-copy clamp-3">{{ item.content }}</text>
              <view v-if="postImages(item).length" :class="['post-images', { single: postImages(item).length === 1 }]">
                <image v-for="image in postImages(item).slice(0, 3)" :key="image.file_id" :src="communityAttachmentUrl(image)" mode="aspectFill" @click.stop="previewPostImages(item, image.file_id)" />
              </view>
              <view class="card-footer"><text v-if="item.location_name">⌖ {{ item.location_name }}</text><view><text>♡ {{ item.like_count || 0 }}</text><text>▢ {{ item.comment_count || 0 }}</text></view></view>
            </view>

            <view v-else-if="activeTab === 'second_hand'" class="content-card goods-card" @click="openItem(item)">
              <image v-if="item.cover" class="goods-cover" :src="assetUrl(item.cover)" mode="aspectFill" />
              <view v-else class="goods-cover goods-placeholder">{{ (item.title || '闲').slice(0, 1) }}</view>
              <view class="goods-main">
                <text class="card-title clamp-2">{{ item.title }}</text>
                <view class="goods-tags"><text>{{ item.condition_label }}</text><text v-if="item.category">{{ item.category.name }}</text></view>
                <text class="goods-meta">{{ item.location || profile.campus_name || '校内交易' }}</text>
                <text class="goods-price">¥{{ item.price }}</text>
              </view>
            </view>

            <view v-else-if="activeTab === 'activities'" class="content-card activity-card" @click="openItem(item)">
              <view class="activity-top"><text class="activity-category">{{ item.category_label }}</text><text class="activity-date">{{ formatTime(item.starts_at) }}</text></view>
              <text class="card-title">{{ item.title }}</text>
              <text class="card-copy clamp-2">{{ item.details }}</text>
              <view class="activity-bottom"><text>⌖ {{ item.location }}</text><text>{{ item.participant_count }}/{{ item.participant_limit }} 人</text></view>
            </view>

            <view v-else-if="activeTab === 'tasks' || activeTab === 'claims'" class="content-card task-card" @click="openItem(item)">
              <view class="task-icon">{{ activeTab === 'claims' ? '完' : '任' }}</view>
              <view class="task-main">
                <view class="task-heading"><text class="card-title">{{ item.title }}</text><text v-if="activeTab === 'claims'" class="done-badge">已完成</text></view>
                <text class="card-copy clamp-2">{{ item.description || '查看任务详情与完成记录' }}</text>
                <text class="task-time">{{ activeTab === 'claims' ? '完成于' : '发布于' }} {{ formatTime(item.approved_at || item.published_at || item.created_at) }}</text>
              </view>
              <text class="card-arrow">›</text>
            </view>

            <view v-else class="content-card impression-card">
              <view class="impression-head">
                <template v-if="item.rater">
                  <image v-if="item.rater.avatar_url" class="rater-avatar" :src="item.rater.avatar_url" mode="aspectFill" @click="openUser(item.rater.id)" />
                  <view v-else class="rater-avatar avatar-fallback" @click="openUser(item.rater.id)">{{ avatarText(item.rater.nickname) }}</view>
                  <view class="rater-main"><text>{{ item.rater.nickname }}</text><text>{{ formatTime(item.created_at) }}</text></view>
                </template>
                <view class="stars"><text v-for="score in 5" :key="score" :class="{ on: score <= (item.score || 0) }">★</text></view>
              </view>
              <text v-if="item.content" class="impression-copy">{{ item.content }}</text>
              <view v-if="item.images?.length" class="impression-images"><image v-for="(image, index) in item.images" :key="image" :src="assetUrl(image)" mode="aspectFill" @click="previewRatingImages(item.images || [], index)" /></view>
              <view v-if="item.reply_content" class="impression-reply"><text>TA 的回复</text>{{ item.reply_content }}</view>
            </view>
          </template>

          <view v-if="contentLoading" class="list-status">正在加载更多…</view>
          <view v-else-if="contentError" class="list-status error-status" @click="loadContent()">加载失败，点击重试</view>
          <view v-else-if="hasMore" class="list-status" @click="loadContent()">加载更多</view>
          <view v-else class="list-status">— 已经到底啦 —</view>
        </view>
      </template>
    </template>
  </view>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue'
import { onLoad, onReachBottom } from '@dcloudio/uni-app'
import {
  blockCommunityUser,
  communityAttachmentUrl,
  followCommunityUser,
  getCommunityUserProfile,
  listCommunityUserContent,
  unblockCommunityUser,
  unfollowCommunityUser,
  type CommunityAttachment,
  type CommunityUserContentItem,
  type CommunityUserContentType,
  type CommunityUserProfile,
} from '@/api/community'
import { ensureChatConversation } from '@/api/chat'
import { ApiError, resolveApiAssetUrl } from '@/api/http'
import { useAuthStore } from '@/stores/auth'
import { navigateToLogin } from '@/utils/authRedirect'
import { formatDateTime } from '@/utils/dateTime'

const tabs: Array<{ code: CommunityUserContentType; label: string }> = [
  { code: 'posts', label: '帖子' },
  { code: 'second_hand', label: '闲置' },
  { code: 'activities', label: '攒局' },
  { code: 'tasks', label: '任务' },
  { code: 'claims', label: '接单' },
  { code: 'impressions', label: '印象' },
]

const auth = useAuthStore()
const userId = ref('')
const profile = ref<CommunityUserProfile | null>(null)
const activeTab = ref<CommunityUserContentType>('posts')
const items = ref<CommunityUserContentItem[]>([])
const loading = ref(true)
const loadError = ref('')
const contentLoading = ref(false)
const contentError = ref('')
const actionLoading = ref(false)
const page = ref(1)
const hasMore = ref(false)

const emptyContent = computed(() => {
  const own = profile.value?.is_self
  const values: Record<CommunityUserContentType, { icon: string; title: string; copy: string }> = {
    posts: { icon: '帖', title: own ? '你还没有公开帖子' : 'TA 还没有公开帖子', copy: '审核通过的公开动态会展示在这里。' },
    second_hand: { icon: '闲', title: '暂时没有在售闲置', copy: '正在出售且审核通过的宝贝会展示在这里。' },
    activities: { icon: '局', title: '暂时没有公开攒局', copy: '审核通过的活动会展示在这里。' },
    tasks: { icon: '任', title: '暂时没有发布任务', copy: '已发布的校园任务会展示在这里。' },
    claims: { icon: '单', title: '暂时没有完成记录', copy: '为保护隐私，仅展示已审核通过的接单记录。' },
    impressions: { icon: '评', title: '还没有收到印象', copy: '交易完成后收到的评价会展示在这里。' },
  }
  return values[activeTab.value]
})

onLoad((query) => {
  userId.value = String(query?.id || '')
  if (!userId.value) {
    loading.value = false
    loadError.value = '用户链接无效，请从校园动态重新进入。'
    return
  }
  initialize()
})

onReachBottom(() => {
  if (hasMore.value && !contentLoading.value && !profile.value?.is_blocked) loadContent()
})

async function initialize() {
  if (!userId.value) return
  loading.value = true
  loadError.value = ''
  try {
    profile.value = await getCommunityUserProfile(userId.value, auth.accessToken || undefined)
    activeTab.value = 'posts'
    await loadContent(true)
  } catch (error) {
    loadError.value = errorMessage(error, '请稍后重试。')
  } finally {
    loading.value = false
  }
}

async function loadContent(reset = false) {
  if (!userId.value || contentLoading.value || profile.value?.is_blocked) return
  if (reset) {
    page.value = 1
    items.value = []
    hasMore.value = false
  } else {
    page.value += 1
  }
  contentLoading.value = true
  contentError.value = ''
  try {
    const result = await listCommunityUserContent(userId.value, activeTab.value, page.value, 10, auth.accessToken || undefined)
    items.value = reset ? result.items : [...items.value, ...result.items]
    hasMore.value = result.pagination.has_more
  } catch (error) {
    if (!reset) page.value -= 1
    contentError.value = errorMessage(error, '内容加载失败，请重试。')
  } finally {
    contentLoading.value = false
  }
}

function switchTab(type: CommunityUserContentType) {
  if (activeTab.value === type || contentLoading.value) return
  activeTab.value = type
  loadContent(true)
}

async function toggleFollow() {
  if (!profile.value || actionLoading.value) return
  if (!requireLogin()) return
  actionLoading.value = true
  try {
    const result = profile.value.is_followed
      ? await unfollowCommunityUser(auth.accessToken, profile.value.id)
      : await followCommunityUser(auth.accessToken, profile.value.id)
    profile.value.is_followed = result.is_followed
    profile.value.follower_count = result.follower_count
    profile.value.following_count = result.following_count
  } catch (error) {
    uni.showToast({ title: errorMessage(error, '操作失败，请重试。'), icon: 'none' })
  } finally {
    actionLoading.value = false
  }
}

async function contact() {
  if (!profile.value || actionLoading.value || !requireLogin()) return
  actionLoading.value = true
  try {
    const conversation = await ensureChatConversation(auth.accessToken, { user_id: profile.value.id })
    uni.navigateTo({ url: `/pages/chat-detail/index?id=${conversation.id}` })
  } catch (error) {
    uni.showToast({ title: errorMessage(error, '暂时无法发起私信。'), icon: 'none' })
  } finally {
    actionLoading.value = false
  }
}

function openMore() {
  if (!profile.value) return
  uni.showActionSheet({
    itemList: [profile.value.is_blocked ? '解除拉黑' : '拉黑该用户'],
    success: ({ tapIndex }) => {
      if (tapIndex !== 0) return
      if (profile.value?.is_blocked) unblockUser()
      else confirmBlock()
    },
  })
}

function confirmBlock() {
  if (!profile.value || !requireLogin()) return
  uni.showModal({
    title: `拉黑 ${profile.value.nickname}？`,
    content: '拉黑后将互相屏蔽公开内容和私信，并解除双方关注关系。你可以随时解除。',
    confirmText: '确认拉黑',
    confirmColor: '#E34B4B',
    success: ({ confirm }) => { if (confirm) blockUser() },
  })
}

async function blockUser() {
  if (!profile.value || actionLoading.value) return
  actionLoading.value = true
  try {
    await blockCommunityUser(auth.accessToken, profile.value.id)
    await refreshProfile()
    items.value = []
    uni.showToast({ title: '已拉黑并屏蔽内容', icon: 'none' })
  } catch (error) {
    uni.showToast({ title: errorMessage(error, '拉黑失败，请重试。'), icon: 'none' })
  } finally {
    actionLoading.value = false
  }
}

async function unblockUser() {
  if (!profile.value || actionLoading.value || !requireLogin()) return
  actionLoading.value = true
  try {
    await unblockCommunityUser(auth.accessToken, profile.value.id)
    await refreshProfile()
    await loadContent(true)
    uni.showToast({ title: '已解除拉黑', icon: 'none' })
  } catch (error) {
    uni.showToast({ title: errorMessage(error, '解除失败，请重试。'), icon: 'none' })
  } finally {
    actionLoading.value = false
  }
}

async function refreshProfile() {
  if (!profile.value) return
  profile.value = await getCommunityUserProfile(profile.value.id, auth.accessToken || undefined)
}

function openItem(item: CommunityUserContentItem) {
  const paths: Partial<Record<CommunityUserContentType, string>> = {
    posts: `/pages/community-detail/index?id=${item.id}`,
    second_hand: `/pages/second-hand-detail/index?id=${item.id}`,
    activities: `/pages/community-activity-detail/index?id=${item.id}`,
    tasks: `/pages/task-detail/index?id=${item.id}`,
    claims: item.task_id ? `/pages/task-detail/index?id=${item.task_id}` : undefined,
  }
  const path = paths[activeTab.value]
  if (path) uni.navigateTo({ url: path })
}

function openUser(id: string) {
  if (id === profile.value?.id) return
  uni.navigateTo({ url: `/pages/user-profile/index?id=${id}` })
}

function postImages(item: CommunityUserContentItem) {
  return (item.attachments || []).filter((attachment): attachment is CommunityAttachment => attachment.type === 'image' && Boolean(attachment.url))
}

function previewPostImages(item: CommunityUserContentItem, fileId: string) {
  const images = postImages(item)
  const urls = images.map(communityAttachmentUrl)
  uni.previewImage({ urls, current: Math.max(0, images.findIndex(image => image.file_id === fileId)) })
}

function previewRatingImages(images: string[], index: number) {
  uni.previewImage({ urls: images.map(assetUrl), current: index })
}

function assetUrl(value?: string | null) { return resolveApiAssetUrl(value) }
function avatarText(value: string) { return value.trim().slice(0, 1) || '友' }
function formatTime(value?: string | null) { return formatDateTime(value, '') }
function requireLogin() {
  if (auth.accessToken) return true
  navigateToLogin()
  return false
}
function errorMessage(error: unknown, fallback: string) {
  return error instanceof ApiError && error.message ? error.message : fallback
}
function editProfile() { uni.navigateTo({ url: '/pages/account/index' }) }
function goBack() {
  const pages = getCurrentPages()
  if (pages.length > 1) uni.navigateBack()
  else uni.reLaunch({ url: '/pages/community/index' })
}
</script>

<style scoped>
.page { min-height:100vh; background:#F4F6F4; color:#191B1F; padding-bottom:40rpx; }
.loading-page { min-height:100vh;background:#F4F6F4; }
.loading-hero { height:570rpx;border-radius:0 0 40rpx 40rpx; }
.loading-card { height:104rpx;margin:-48rpx 24rpx 0;border-radius:24rpx;position:relative; }
.loading-list { height:500rpx;margin:36rpx 24rpx 0;border-radius:24rpx; }
.profile-hero { position:relative;overflow:hidden;padding:0 30rpx 80rpx;background:linear-gradient(145deg,#075E43,#0BBF63 58%,#72D6A5);color:#fff; }
.hero-bg { position:absolute;inset:-60rpx;width:calc(100% + 120rpx);height:calc(100% + 120rpx);filter:blur(36rpx);transform:scale(1.12); }
.hero-shade { position:absolute;inset:0;background:linear-gradient(180deg,rgba(4,25,19,.42),rgba(4,61,43,.68) 48%,rgba(3,84,54,.9)); }
.topnav { position:relative;height:88rpx;padding-top:var(--status-bar-height);display:flex;align-items:center;justify-content:space-between; }
.nav-circle { width:64rpx;height:64rpx;border-radius:50%;background:rgba(0,0,0,.2);color:#fff;font-size:46rpx;line-height:58rpx;padding:0;margin:0;text-align:center; }
.nav-circle::after { border:0; }
.nav-title { font-size:30rpx;font-weight:650; }
.nav-more { font-size:28rpx;line-height:48rpx;letter-spacing:2rpx; }
.nav-placeholder { background:transparent; }
.identity-row { position:relative;display:flex;align-items:flex-end;justify-content:space-between;margin-top:34rpx; }
.avatar-ring { width:152rpx;height:152rpx;padding:6rpx;border:2rpx solid rgba(255,255,255,.72);border-radius:50%;background:rgba(255,255,255,.18);box-shadow:0 12rpx 36rpx rgba(0,0,0,.18); }
.profile-avatar { width:100%;height:100%;border-radius:50%;background:#D9EEE3; }
.avatar-fallback { display:flex;align-items:center;justify-content:center;color:#27825C;font-weight:800; }
.profile-avatar.avatar-fallback { font-size:54rpx; }
.visitor-chip { display:flex;align-items:center;height:56rpx;padding:0 16rpx 0 8rpx;border-radius:30rpx;background:rgba(0,0,0,.2);font-size:21rpx;color:rgba(255,255,255,.94); }
.visitor-avatars { display:flex;padding-left:10rpx;margin-right:10rpx; }
.visitor-avatars image,.visitor-avatars text { width:38rpx;height:38rpx;line-height:38rpx;text-align:center;border-radius:50%;border:2rpx solid rgba(255,255,255,.72);background:#8CC9AE;margin-left:-10rpx;font-size:18rpx;color:#fff;overflow:hidden; }
.visitor-arrow { margin-left:8rpx;font-size:30rpx;line-height:1; }
.name-row { position:relative;display:flex;align-items:center;gap:12rpx;margin-top:22rpx; }
.nickname { font-size:42rpx;line-height:1.25;font-weight:800;letter-spacing:1rpx; }
.verified { padding:5rpx 12rpx;border-radius:8rpx;background:rgba(255,255,255,.2);border:1rpx solid rgba(255,255,255,.34);font-size:19rpx; }
.uid { position:relative;display:block;margin-top:10rpx;font-size:21rpx;color:rgba(255,255,255,.68); }
.profile-tags { position:relative;display:flex;flex-wrap:wrap;gap:10rpx;margin-top:18rpx; }
.profile-tag { max-width:100%;padding:7rpx 13rpx;border-radius:9rpx;background:rgba(0,0,0,.18);font-size:20rpx;line-height:1.35;color:rgba(255,255,255,.9); }
.school-tag { background:rgba(255,255,255,.18); }
.bio { position:relative;display:block;margin-top:18rpx;font-size:24rpx;line-height:1.6;color:rgba(255,255,255,.9); }
.social-row { position:relative;display:flex;align-items:center;margin-top:26rpx; }
.social-item { display:flex;align-items:baseline;margin-right:34rpx; }
.social-number { font-size:32rpx;font-weight:800; }
.social-label { margin-left:8rpx;font-size:21rpx;color:rgba(255,255,255,.72); }
.rating-chip { margin-left:auto;padding:8rpx 14rpx;border-radius:18rpx;background:rgba(255,255,255,.16);font-size:20rpx;color:#FFF3C2; }
.action-card { position:relative;z-index:2;display:flex;align-items:center;gap:18rpx;min-height:102rpx;margin:-52rpx 24rpx 0;padding:18rpx 20rpx;border-radius:24rpx;background:#fff;box-shadow:0 10rpx 32rpx rgba(27,62,45,.08);box-sizing:border-box; }
.action-btn { flex:1;height:68rpx;line-height:68rpx;padding:0;margin:0;border-radius:18rpx;font-size:27rpx;font-weight:700; }
.action-btn::after { border:0; }
.follow-action { background:#0BBF63;color:#fff; }
.follow-action.followed { background:#F0F3F1;color:#657069; }
.message-action,.edit-btn { background:#E7F9EF;color:#07844B; }
.blocked-copy { flex:1;min-width:0;padding-left:6rpx; }
.blocked-title,.blocked-sub { display:block; }
.blocked-title { font-size:26rpx;font-weight:750; }
.blocked-sub { margin-top:5rpx;font-size:20rpx;color:#929A95; }
.unblock-btn { max-width:220rpx;background:#F0F3F1;color:#4C5650;font-size:24rpx; }
.tabs-scroll { margin-top:24rpx;background:#fff;border-bottom:2rpx solid #EEF1EF;white-space:nowrap; }
.content-tabs { display:inline-flex;min-width:100%;padding:0 12rpx;box-sizing:border-box; }
.content-tab { position:relative;display:flex;align-items:center;justify-content:center;gap:5rpx;width:118rpx;height:88rpx;flex:none;font-size:26rpx;color:#7B837E; }
.content-tab.active { color:#111A15;font-weight:800; }
.tab-count { font-size:20rpx;color:#A0A8A3;font-weight:500; }
.content-tab.active .tab-count { color:#0A9D56; }
.tab-indicator { position:absolute;bottom:0;left:50%;width:34rpx;height:6rpx;margin-left:-17rpx;border-radius:6rpx;background:#0BBF63; }
.content-list { padding:18rpx 24rpx 0; }
.content-card { padding:26rpx;margin-bottom:18rpx;border-radius:24rpx;background:#fff;box-shadow:0 4rpx 18rpx rgba(26,60,43,.035);box-sizing:border-box; }
.card-kicker,.card-footer,.activity-top,.activity-bottom,.task-heading { display:flex;align-items:center;justify-content:space-between;gap:16rpx; }
.card-kicker { font-size:21rpx;color:#98A19B; }
.card-kicker text:first-child { color:#0A9D56;font-weight:650; }
.card-title { display:block;margin-top:14rpx;font-size:30rpx;line-height:1.45;font-weight:750;color:#181C19; }
.card-copy { display:block;margin-top:12rpx;font-size:25rpx;line-height:1.7;color:#616A64; }
.clamp-2,.clamp-3 { display:-webkit-box;-webkit-box-orient:vertical;overflow:hidden; }
.clamp-2 { -webkit-line-clamp:2; }
.clamp-3 { -webkit-line-clamp:3; }
.post-images { display:grid;grid-template-columns:repeat(3,1fr);gap:10rpx;margin-top:20rpx; }
.post-images image { width:100%;height:156rpx;border-radius:14rpx;background:#EDF1EE; }
.post-images.single { grid-template-columns:1fr; }
.post-images.single image { height:300rpx; }
.card-footer { margin-top:20rpx;font-size:21rpx;color:#929B95; }
.card-footer view { display:flex;gap:24rpx; }
.goods-card { display:flex;gap:22rpx; }
.goods-cover { width:190rpx;height:190rpx;flex:none;border-radius:18rpx;background:#EDF1EE; }
.goods-placeholder { display:flex;align-items:center;justify-content:center;font-size:54rpx;color:#A8B2AB; }
.goods-main { flex:1;min-width:0; }
.goods-main .card-title { margin-top:0;font-size:28rpx; }
.goods-tags { display:flex;gap:10rpx;margin-top:12rpx; }
.goods-tags text { padding:4rpx 10rpx;border-radius:7rpx;background:#EAF8F0;color:#0A9252;font-size:19rpx; }
.goods-tags text + text { background:#F1F3F2;color:#79827C; }
.goods-meta { display:block;margin-top:10rpx;font-size:20rpx;color:#98A19B; }
.goods-price { display:block;margin-top:8rpx;color:#FF3B4E;font-size:32rpx;font-weight:800; }
.activity-category { padding:6rpx 14rpx;border-radius:9rpx;background:#E7F9EF;color:#078B4E;font-size:21rpx;font-weight:700; }
.activity-date { font-size:21rpx;color:#929B95; }
.activity-bottom { margin-top:20rpx;padding-top:18rpx;border-top:2rpx solid #F1F3F2;font-size:22rpx;color:#7D8780; }
.task-card { display:flex;align-items:center;gap:20rpx; }
.task-icon { width:78rpx;height:78rpx;flex:none;display:flex;align-items:center;justify-content:center;border-radius:22rpx;background:#E7F9EF;color:#079550;font-size:30rpx;font-weight:800; }
.task-main { flex:1;min-width:0; }
.task-heading .card-title { flex:1;min-width:0;margin-top:0;font-size:28rpx; }
.done-badge { flex:none;padding:4rpx 10rpx;border-radius:8rpx;background:#E7F9EF;color:#079550;font-size:19rpx; }
.task-main .card-copy { font-size:23rpx; }
.task-time { display:block;margin-top:12rpx;color:#9AA39D;font-size:20rpx; }
.card-arrow { color:#B2BAB5;font-size:38rpx; }
.impression-head { display:flex;align-items:center; }
.rater-avatar { width:72rpx;height:72rpx;border-radius:50%;background:#E4F1E9;font-size:26rpx; }
.rater-main { flex:1;margin-left:16rpx; }
.rater-main text { display:block;font-size:25rpx;font-weight:650; }
.rater-main text + text { margin-top:5rpx;color:#9AA39D;font-size:20rpx;font-weight:400; }
.stars { color:#D8DDD9;font-size:25rpx;letter-spacing:3rpx; }
.stars .on { color:#FFB020; }
.impression-copy { display:block;margin-top:20rpx;font-size:26rpx;line-height:1.7; }
.impression-images { display:flex;flex-wrap:wrap;gap:12rpx;margin-top:18rpx; }
.impression-images image { width:140rpx;height:140rpx;border-radius:14rpx;background:#EEF1EF; }
.impression-reply { margin-top:18rpx;padding:18rpx;border-radius:16rpx;background:#F5F8F6;color:#657069;font-size:23rpx;line-height:1.6; }
.impression-reply text { display:block;margin-bottom:6rpx;color:#0A9654;font-weight:700; }
.content-skeleton { padding:18rpx 24rpx; }
.skeleton-card { height:250rpx; }
.content-state,.page-state { display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center; }
.content-state { min-height:420rpx;padding:70rpx 50rpx; }
.page-state { min-height:100vh;padding:60rpx;background:#fff;box-sizing:border-box; }
.state-symbol { width:104rpx;height:104rpx;display:flex;align-items:center;justify-content:center;margin-bottom:24rpx;border-radius:50%;background:#E7F9EF;color:#0AA158;font-size:40rpx;font-weight:800; }
.state-symbol.small { width:88rpx;height:88rpx;font-size:30rpx; }
.state-title { font-size:29rpx;font-weight:750; }
.state-copy { max-width:560rpx;margin-top:14rpx;color:#8A948D;font-size:23rpx;line-height:1.65; }
.primary-btn,.plain-btn,.retry-btn { margin:32rpx 0 0;padding:0 44rpx;height:72rpx;line-height:72rpx;border-radius:36rpx;font-size:25rpx; }
.primary-btn { background:#0BBF63;color:#fff; }
.plain-btn { margin-top:12rpx;background:transparent;color:#7D8780; }
.retry-btn { background:#E7F9EF;color:#078B4E; }
.primary-btn::after,.plain-btn::after,.retry-btn::after { border:0; }
.list-status { padding:24rpx 0 36rpx;text-align:center;color:#9BA39E;font-size:22rpx; }
.error-status { color:#E34B4B; }
.blocked-state { min-height:460rpx; }
</style>
