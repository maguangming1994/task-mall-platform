<template>
  <view class="page" :style="topSafeAreaStyle">
    <ModulePlaceholder v-if="modules.resolved && !moduleEnabled" code="module_community" />
    <view v-else-if="!modules.resolved" class="mp-skeleton">
      <view class="tm-skeleton" style="height: 200rpx; border-radius: 24rpx; margin: 24rpx 28rpx;" />
      <view class="tm-skeleton" style="height: 320rpx; border-radius: 24rpx; margin: 20rpx 28rpx;" />
    </view>
    <template v-else>
    <view class="wall-head">
      <text class="wall-title">校园墙</text>
      <view class="wall-actions">
        <button class="icon-action has-dot" aria-label="社区消息" @click="openTarget('messages')">
          <text class="bell-glyph">🔔</text>
        </button>
        <button v-if="modules.isEnabled('module_community_post')" class="publish-btn" @click="showPublishSheet = true">＋ 发布</button>
      </view>
    </view>

    <view v-if="searching" class="search-panel" @click="focusSearch">
      <input v-model="keyword" confirm-type="search" :focus="searchFocused" placeholder="搜索帖子标题、内容或同学" @blur="searchFocused = false" @confirm="searching = false" />
      <text @click="clearSearch">{{ keyword ? '清空' : '取消' }}</text>
    </view>

    <!-- 主信息流 Tab：关注 / 发现 / 附近 / 精选 -->
    <view class="feed-tabs">
      <view
        v-for="tab in feedTabs"
        :key="tab.code"
        :class="['feed-tab', { active: activeView === 'square' && activeFeed === tab.code }]"
        @click="switchFeed(tab.code)"
      >{{ tab.label }}</view>
      <view :class="['feed-tab', 'mine-tab', { active: activeView === 'mine' }]" @click="switchView('mine')">我的</view>
    </view>

    <scroll-view v-if="activeView === 'square'" class="sticky-bar" scroll-x :show-scrollbar="false" enhanced :bounces="false">
      <view class="category-row">
        <template v-if="activeFeed === 'discover'">
          <view :class="['category-tab', { active: !activeCategory }]" @click="selectCategory('')">全部</view>
          <view v-for="category in categories" :key="category.code" :class="['category-tab', { active: activeCategory === category.code }]" @click="selectCategory(category.code)">{{ category.label }}</view>
        </template>
        <view v-else class="feed-hint">{{ feedHint }}</view>
      </view>
    </scroll-view>

    <scroll-view v-if="activeView === 'square' && activeFeed === 'discover'" class="tool-scroll" scroll-x :show-scrollbar="false" enhanced :bounces="false">
      <view class="tool-row">
        <view class="tool-chip guide-chip" @click="openTarget('legal')">
          <text class="chip-icon guide-check">✓</text>
          <view class="chip-copy"><text class="chip-title">文明公约</text><text class="chip-sub">发布前必读</text></view>
        </view>
        <view v-for="topic in hotTopics" :key="topic.id" class="tool-chip hot-chip" @click="openTarget(topic.target)">
          <text class="chip-icon hot-icon">热</text>
          <view class="chip-copy"><text class="chip-title hot-title">{{ topic.label }}</text></view>
        </view>
        <view v-for="(entry, index) in quickEntries" :key="entry.id || index" class="tool-chip" @click="openTarget(entry.target)">
          <view class="chip-icon quick-icon" :style="quickStyle(entry, index)"><image v-if="entry.icon_url" :src="communityHomeAssetUrl(entry.icon_url)" mode="aspectFit" /><text v-else>{{ fallbackQuickIcon(index) }}</text></view>
          <view class="chip-copy"><text class="chip-title">{{ entry.label }}</text><text class="chip-sub">{{ entry.description || fallbackQuickSubtitle(index) }}</text></view>
        </view>
      </view>
    </scroll-view>

    <template v-if="activeView === 'square'">
      <view class="feed-heading">
        <view class="feed-heading-left">
          <text class="search-glyph" @click="openSearch">⌕</text>
          <view><text class="feed-title">{{ keyword ? '搜索结果' : feedHeadingTitle }}</text><text class="feed-copy">{{ feedHeadingCopy }}</text></view>
        </view>
        <view class="feed-heading-links">
          <text class="group-link" @click="switchView('mine')">我的发布</text>
          <text class="group-link" @click="openTarget('activities')">组团大厅 ›</text>
        </view>
      </view>

      <view v-if="publicError && !posts.length" class="state-card error-card">
        <view class="state-illustration">!</view><text class="state-title">内容暂时走丢了</text><text class="state-copy">{{ publicError }}</text><button @click="refreshSquare">重新加载</button>
      </view>
      <view v-else-if="loadingPublic && !posts.length" class="skeleton-list"><view v-for="index in 3" :key="index" class="post-card skeleton-card tm-skeleton" /></view>
      <template v-else>
        <view v-for="post in visiblePosts" :key="post.id" class="post-card">
          <view class="post-head">
            <view class="author-row" @click="openUserProfile(post.author.id)">
              <view class="author-avatar">
                <image v-if="post.author.avatar_url" :src="post.author.avatar_url" mode="aspectFill" />
                <text v-else>{{ avatarText(post.author.nickname) }}</text>
              </view>
              <view class="author-info">
                <view class="author-name-row">
                  <text class="author-name">{{ post.author.nickname }}</text>
                  <text v-if="post.author.verified" class="verified-badge">校园认证</text>
                </view>
                <text class="post-time">{{ formatRelativeTime(post.published_at || post.created_at) }}<text v-if="post.location_name || post.author.campus_name"> · {{ post.location_name || post.author.campus_name }}</text></text>
              </view>
            </view>
            <view class="head-right">
              <button
                v-if="!post.author.is_self && post.author.id"
                :class="['follow-btn', { followed: post.author.is_followed }]"
                :disabled="followLoadingId === post.id"
                @click="toggleFollow(post)"
              >{{ post.author.is_followed ? '已关注' : '＋ 关注' }}</button>
              <text class="more" @click="report('post', post.id)">•••</text>
            </view>
          </view>
          <text class="post-title" @click="openPost(post.id)"><text class="topic-prefix">#{{ post.category_label }}</text> {{ post.title }}</text>
          <text :class="['post-content', { collapsed: !isExpanded(post.id) }]" @click="openPost(post.id)">{{ post.content }}</text>
          <text v-if="unicodeLength(post.content) > 92" class="expand-link" @click="toggleExpanded(post.id)">{{ isExpanded(post.id) ? '收起' : '展开全文' }}</text>
          <view v-if="postImages(post).length" :class="['media-grid', `count-${Math.min(postImages(post).length, 3)}`]">
            <image v-for="(attachment, imageIndex) in postImages(post).slice(0, 6)" :key="attachment.file_id" class="post-image" :src="communityAttachmentUrl(attachment)" mode="aspectFill" @click="previewPostImages(post, imageIndex)" />
          </view>
          <view v-if="post.location_name" class="loc-chip"><text class="loc-pin">⌖</text><text>{{ post.location_name }}</text></view>
          <view class="post-meta">
            <view class="meta-left">
              <text @click="toggleComments(post)">💬 {{ post.comment_count }}</text>
              <text :class="{ liked: post.liked_by_me }" @click="toggleLike(post)">{{ post.liked_by_me ? '♥' : '♡' }} {{ post.like_count || 0 }}</text>
              <text :class="{ favored: post.favorited_by_me }" @click="toggleFavorite(post)">{{ post.favorited_by_me ? '★' : '☆' }} {{ post.favorite_count || 0 }}</text>
            </view>
            <!-- #ifdef MP-WEIXIN -->
            <button class="share-btn" open-type="share" aria-label="分享">↗</button>
            <!-- #endif -->
            <!-- #ifndef MP-WEIXIN -->
            <text class="share-glyph" @click="sharePost(post)">↗</text>
            <!-- #endif -->
          </view>
          <view v-if="openedPostId === post.id" class="comments-panel">
            <view class="comment-compose" @click="openCommentComposer(post)">友善评论，说说你的想法…</view>
            <view v-if="commentsLoading" class="comments-state">评论加载中…</view>
            <view v-else-if="commentsError" class="comments-state error"><text>{{ commentsError }}</text><text @click="loadComments(post.id)">重试</text></view>
            <view v-else-if="!comments.length" class="comments-state">还没有评论，来坐第一排吧。</view>
            <view v-for="item in comments" v-else :key="item.id" :class="['comment-row', { 'reply-row': item.parent_comment_id }]">
              <view class="comment-avatar" @click="openUserProfile(item.author.id)">{{ avatarText(item.author.nickname) }}</view>
              <view class="comment-main"><text class="comment-author" @click="openUserProfile(item.author.id)">{{ item.author.nickname }}<text v-if="item.parent_comment_id" class="comment-reply-target"> 回复 @{{ parentCommentAuthor(item) }}</text></text><text class="comment-content">{{ item.content }}</text><view><text v-if="!item.parent_comment_id" @click="openCommentComposer(post, item)">回复</text><text v-if="auth.user?.id !== item.author.id" @click="report('comment', item.id)">举报</text></view></view>
            </view>
          </view>
        </view>

        <view v-if="!visiblePosts.length" class="state-card">
          <template v-if="activeFeed === 'following'">
            <view class="state-illustration">＋</view>
            <text class="state-title">关注后，TA 的新动态会出现在这里</text>
            <text class="state-copy">去发现页关注有趣的同学，第一时间收到校园新鲜事。</text>
            <button @click="switchFeed('discover')">去发现同学</button>
          </template>
          <template v-else-if="activeFeed === 'nearby'">
            <view class="state-illustration">⌖</view>
            <text class="state-title">{{ strictCampus ? '本校暂无附近动态' : '附近暂时没有同校动态' }}</text>
            <text class="state-copy">{{ strictCampus ? '发布第一条动态，让本校同学看见你。' : '成为这栋楼第一个分享的人，让附近的同学看见你。' }}</text>
            <text v-if="strictCampus" class="strict-note">严格校区模式：仅展示本校同学的内容</text>
            <button v-if="modules.isEnabled('module_community_post')" @click="openCompose(activeCategory || undefined)">发布附近第一条</button>
          </template>
          <template v-else>
            <view class="state-illustration">✦</view>
            <text class="state-title">{{ strictCampus ? '本校暂无动态' : '还没有同学发过这类内容' }}</text>
            <text class="state-copy">{{ strictCampus ? '发布第一条动态，让本校同学看见你。' : '分享校园新鲜事、学习经验或互助问题，成为第一个发帖的人。' }}</text>
            <text v-if="strictCampus" class="strict-note">严格校区模式：仅展示本校同学的内容</text>
            <button v-if="modules.isEnabled('module_community_post')" @click="openCompose(activeCategory || undefined)">发布第一条动态</button>
          </template>
        </view>
        <button v-else-if="hasMore && !keyword" class="load-more" :loading="loadingMore" @click="loadMore">{{ loadingMore ? '加载中…' : '加载更多校园动态' }}</button>
        <text v-else-if="visiblePosts.length" class="list-end">到底啦 · 先去校园里逛逛吧</text>
      </template>
    </template>

    <template v-else>
      <view class="mine-hero"><view><text>我的校园发布</text><text>审核、修改与处置记录都在这里</text></view><button v-if="modules.isEnabled('module_community_post')" @click="showPublishSheet = true">＋ 新发布</button></view>
      <view class="mine-summary"><view><strong>{{ myPosts.length }}</strong><text>全部</text></view><view><strong>{{ pendingCount }}</strong><text>审核中</text></view><view><strong>{{ publishedCount }}</strong><text>已公开</text></view></view>
      <view v-if="mineError" class="state-card error-card"><text class="state-title">我的发布加载失败</text><text class="state-copy">{{ mineError }}</text><button @click="loadMine">重试</button></view>
      <view v-for="post in myPosts" v-else :key="post.id" class="mine-card">
        <view class="mine-head"><text :class="['status-pill', `status-${displayStatus(post).tone}`]">{{ displayStatus(post).label }}</text><text>{{ formatRelativeTime(post.created_at) }}</text></view>
        <text class="mine-title">{{ post.latest_version?.title || post.title }}</text><text class="mine-content">{{ post.latest_version?.content || post.content }}</text>
        <view v-if="post.latest_version?.review_status === 'rejected'" class="review-note">{{ post.latest_version.review_note || '内容暂未通过审核，请修改后重新提交。' }}</view>
        <view class="mine-actions"><button v-if="post.can_edit && modules.isEnabled('module_community_post')" @click="openCompose(undefined, post.id)">编辑</button><button v-if="post.can_delete" class="danger" @click="removePost(post)">删除</button></view>
      </view>
      <view v-if="!loadingMine && !myPosts.length" class="state-card"><view class="state-illustration">写</view><text class="state-title">还没有发布记录</text><text class="state-copy">你发布的帖子会在这里显示审核状态。</text><button v-if="modules.isEnabled('module_community_post')" @click="showPublishSheet = true">开始发布</button></view>
    </template>

    <view v-if="showPublishSheet" class="sheet-mask" @click="showPublishSheet = false">
      <view class="publish-sheet" @click.stop>
        <view class="sheet-handle" />
        <view class="sheet-head"><view><text>在校园里发布</text><text>选择最符合内容的入口</text></view><text class="sheet-close" @click="showPublishSheet = false">×</text></view>
        <view v-if="modules.isEnabled('module_community_post')" class="publish-primary" @click="openCompose()"><view><text>发布校园动态</text><text>分享见闻、求助、经验和作品</text></view><text class="reward-chip">首发可获积分</text></view>
        <view class="publish-grid">
          <view v-if="modules.isEnabled('module_community_post')" class="publish-option help" @click="openCompose('question')"><text class="option-icon">?</text><text>校园求助</text><small>找同学帮忙</small></view>
          <view v-if="modules.isEnabled('module_community_post')" class="publish-option group" @click="openTarget('activity-compose')"><text class="option-icon">团</text><text>发起组团</text><small>自习·运动·拼车</small></view>
          <view class="publish-option mall" @click="openTarget('products')"><text class="option-icon">物</text><text>校园好物</text><small>去商城逛逛</small></view>
          <view class="publish-option task" @click="openTarget('publisher')"><text class="option-icon">任</text><text>发布任务</text><small>平台发布入口</small></view>
        </view>
        <button class="sheet-cancel" @click="showPublishSheet = false">取消</button>
      </view>
    </view>

    <CommunityCommentComposer
      v-model="commentDraft"
      :visible="commentComposerVisible"
      :reply-to="commentReply?.author.nickname || ''"
      :submitting="commentSubmitting"
      :error="commentSubmitError"
      :min-length="publishingRules.comment.min_length"
      :max-length="publishingRules.comment.max_length"
      @close="closeCommentComposer"
      @submit="submitComment"
    />

    <button v-if="modules.isEnabled('module_community_post') && activeView === 'square'" class="publish-fab" aria-label="发布动态" @click="showPublishSheet = true">＋</button>
    </template>

    <AppTabBar current-target="community" @compose="showPublishSheet = true" />
  </view>
</template>

<script setup lang="ts">
import { formatRelativeTime } from '@/utils/dateTime'
import { computed, nextTick, ref } from 'vue'
import { onLoad, onPullDownRefresh, onShareAppMessage, onShow } from '@dcloudio/uni-app'
import AppTabBar from '@/components/AppTabBar.vue'
import CommunityCommentComposer from '@/components/CommunityCommentComposer.vue'
import {
  communityAttachmentUrl, communityHomeAssetUrl, createCommunityComment, createCommunityRequestKey, deleteCommunityPost,
  favoriteCommunityPost, followCommunityUser, likeCommunityPost,
  DEFAULT_COMMUNITY_PUBLISHING_RULES,
  listCommunityCategories, listCommunityComments, listCommunityPosts, listMyCommunityPosts,
  loadCommunityHome, reportCommunityContent, unfavoriteCommunityPost, unlikeCommunityPost, unfollowCommunityUser,
  type CommunityCategory, type CommunityComment, type CommunityFeed,
  type CommunityHomeConfig, type CommunityPost, type CommunityPublishingRules, type CommunityQuickEntry,
} from '@/api/community'
import { useAuthStore } from '@/stores/auth'
import { navigateToLogin } from '@/utils/authRedirect'
import { buildQueryString } from '@/utils/query'
import { createTopSafeAreaStyle } from '@/utils/safeArea'
import { unicodeLength } from '@/utils/textInput'
import ModulePlaceholder from '@/components/ModulePlaceholder.vue'
import { useModulesStore } from '@/stores/modules'
import { useCampusProfileStore } from '@/stores/campusProfile'

const topSafeAreaStyle = createTopSafeAreaStyle()

const modules = useModulesStore()
const moduleEnabled = computed(() => modules.isEnabled('module_community'))
const campusProfile = useCampusProfileStore()
const strictCampus = computed(() => campusProfile.isStrict)

const feedTabs: Array<{ code: CommunityFeed; label: string }> = [
  { code: 'following', label: '关注' },
  { code: 'discover', label: '发现' },
  { code: 'nearby', label: '附近' },
  { code: 'featured', label: '精选' },
]

const fallbackCategories: CommunityCategory[] = [
  { code: 'experience', label: '校园动态' }, { code: 'guide', label: '学习攻略' },
  { code: 'question', label: '互助求助' }, { code: 'showcase', label: '作品展示' },
]
const fallbackHome: CommunityHomeConfig = {
  brand: { logo_url: '', logo_text: '校', name: '任务商城校园', caption: '同学都在这里' },
  banner: { title: '校园新鲜事，就在这里发生', subtitle: '求助、组团、分享，让同学之间更近一点', image_url: null, link_type: 'posts', accent_color: '#20D68B' },
  quick_entries: [
    { id: 'publish', label: '发布帖子', description: '分享、吐槽、求助', icon_url: null, target: 'publish_post', enabled: true, color: '#39C8FF', sort_order: 10 },
    { id: 'group', label: '兴趣组团', description: '一起出发', icon_url: null, target: 'activities', enabled: true, color: '#FF5F78', sort_order: 20 },
    { id: 'task', label: '校园任务', description: '赚取奖励', icon_url: null, target: 'tasks', enabled: true, color: '#A65CFF', sort_order: 30 },
    { id: 'posts', label: '校园广场', description: '发现新鲜事', icon_url: null, target: 'posts', enabled: true, color: '#19D58B', sort_order: 40 },
  ],
  hot_topics: [
    { id: 'study', label: '#期末复习互助', target: 'guide', enabled: true, color: '#20D68B' },
    { id: 'sport', label: '#周末运动搭子', target: 'sport', enabled: true, color: '#FF5964' },
    { id: 'new', label: '#新生校园攻略', target: 'experience', enabled: true, color: '#4D9CFF' },
  ],
  version: null,
}

const auth = useAuthStore()
const home = ref<CommunityHomeConfig>(fallbackHome)
const categories = ref<CommunityCategory[]>(fallbackCategories)
const publishingRules = ref<CommunityPublishingRules>(DEFAULT_COMMUNITY_PUBLISHING_RULES)
const posts = ref<CommunityPost[]>([])
const myPosts = ref<CommunityPost[]>([])
const comments = ref<CommunityComment[]>([])
const commentComposerPost = ref<CommunityPost | null>(null)
const commentReply = ref<CommunityComment | null>(null)
const commentDraft = ref('')
const commentComposerVisible = ref(false)
const commentSubmitting = ref(false)
const commentSubmitError = ref('')
const commentRequestKey = ref('')
const activeView = ref<'square' | 'mine'>('square')
const activeFeed = ref<CommunityFeed>('discover')
const activeCategory = ref<CommunityCategory['code'] | ''>('')
const keyword = ref('')
const searching = ref(false)
const searchFocused = ref(false)

function openSearch() {
  searching.value = true
  nextTick(() => { searchFocused.value = true })
}

function focusSearch() {
  searchFocused.value = true
}
const showPublishSheet = ref(false)
const openedPostId = ref('')
const expandedIds = ref<string[]>([])
const followLoadingId = ref('')
const page = ref(1)
const total = ref(0)
const loadingPublic = ref(false)
const loadingMore = ref(false)
const loadingMine = ref(false)
const commentsLoading = ref(false)
const publicError = ref('')
const mineError = ref('')
const commentsError = ref('')

const hasMore = computed(() => posts.value.length < total.value)
const quickEntries = computed(() => home.value.quick_entries.filter(item => item.enabled).sort((left, right) => (left.sort_order || 0) - (right.sort_order || 0)))
const hotTopics = computed(() => home.value.hot_topics.filter(item => item.enabled))
const feedHint = computed(() => {
  if (activeFeed.value === 'following') return '只看你关注的同学'
  if (activeFeed.value === 'nearby') return '同校同学的动态'
  if (activeFeed.value === 'featured') return '高赞热门内容'
  return ''
})
const feedHeadingTitle = computed(() => {
  if (activeFeed.value === 'following') return '关注动态'
  if (activeFeed.value === 'nearby') return '附近'
  if (activeFeed.value === 'featured') return '精选'
  return categories.value.find(item => item.code === activeCategory.value)?.label || '校园广场'
})
const feedHeadingCopy = computed(() => {
  if (activeFeed.value === 'following') return '关注同学的最新分享'
  if (activeFeed.value === 'nearby') return '同校动态 · 就在身边'
  if (activeFeed.value === 'featured') return '大家都在看的热门'
  return '校园真实动态 · 友善交流'
})
const pendingCount = computed(() => myPosts.value.filter(post => post.latest_version?.review_status === 'pending').length)
const publishedCount = computed(() => myPosts.value.filter(post => post.status === 'published').length)
const visiblePosts = computed(() => {
  const query = keyword.value.trim().toLowerCase()
  if (!query) return posts.value
  return posts.value.filter(post => `${post.title} ${post.content} ${post.author.nickname}`.toLowerCase().includes(query))
})

onLoad((options) => {
  const feed = typeof options?.feed === 'string' ? options.feed : ''
  if ((feedTabs.map(item => item.code) as string[]).includes(feed)) activeFeed.value = feed as CommunityFeed
  const category = typeof options?.category === 'string' ? options.category : ''
  if (category && fallbackCategories.some(item => item.code === category)) activeCategory.value = category as CommunityCategory['code']
  if (options?.view === 'mine' && ensureAuth()) activeView.value = 'mine'
})

onShow(async () => {
  void campusProfile.ensure()
  await modules.ensureReady()
  if (!moduleEnabled.value) return
  if (activeView.value === 'mine' && auth.isAuthenticated) await loadMine()
  else await refreshSquare()
  await Promise.all([loadHomeConfig(), loadCategories()])
})
onPullDownRefresh(async () => { try { await modules.ensureReady(); if (!moduleEnabled.value) return; await Promise.all([activeView.value === 'mine' ? loadMine() : refreshSquare(), loadHomeConfig(), loadCategories()]) } finally { uni.stopPullDownRefresh() } })

onShareAppMessage(() => {
  const post = posts.value.find(item => item.id === openedPostId.value)
  return {
    title: post ? `${post.author.nickname}：${post.title}` : '校园墙 · 同学都在这里',
    path: '/pages/community/index',
  }
})

async function loadHomeConfig() { try { const value = await loadCommunityHome(); home.value = { ...fallbackHome, ...value, brand: { ...fallbackHome.brand, ...(value.brand || {}) }, banner: value.banner?.title ? value.banner : fallbackHome.banner, quick_entries: value.quick_entries?.length ? value.quick_entries : fallbackHome.quick_entries, hot_topics: value.hot_topics?.length ? value.hot_topics : fallbackHome.hot_topics } } catch { home.value = fallbackHome } }
async function loadCategories() {
  try {
    const value = await listCommunityCategories()
    categories.value = value.items.length ? value.items : fallbackCategories
    publishingRules.value = value.publishing_rules || DEFAULT_COMMUNITY_PUBLISHING_RULES
  } catch {
    categories.value = fallbackCategories
    publishingRules.value = DEFAULT_COMMUNITY_PUBLISHING_RULES
  }
}
async function refreshSquare() { loadingPublic.value = true; publicError.value = ''; page.value = 1; try { const result = await listCommunityPosts(1, 10, activeFeed.value === 'discover' ? activeCategory.value || undefined : undefined, auth.accessToken || undefined, activeFeed.value); posts.value = result.items; total.value = result.pagination.total } catch (error) { publicError.value = errorMessage(error) } finally { loadingPublic.value = false } }
async function loadMore() { if (loadingMore.value || !hasMore.value) return; loadingMore.value = true; try { const next = page.value + 1; const result = await listCommunityPosts(next, 10, activeFeed.value === 'discover' ? activeCategory.value || undefined : undefined, auth.accessToken || undefined, activeFeed.value); posts.value.push(...result.items); page.value = next; total.value = result.pagination.total } catch (error) { toast(error) } finally { loadingMore.value = false } }
async function loadMine() { if (!auth.accessToken) return; loadingMine.value = true; mineError.value = ''; try { myPosts.value = (await listMyCommunityPosts(auth.accessToken)).items } catch (error) { mineError.value = errorMessage(error) } finally { loadingMine.value = false } }
async function switchView(view: 'square' | 'mine') { if (view === 'mine' && !ensureAuth()) return; activeView.value = view; if (view === 'mine') await loadMine(); else await refreshSquare() }
async function switchFeed(feed: CommunityFeed) {
  if ((feed === 'following' || feed === 'nearby') && !ensureAuth()) return
  activeView.value = 'square'
  keyword.value = ''
  if (activeFeed.value === feed) return
  activeFeed.value = feed
  openedPostId.value = ''
  await refreshSquare()
}
async function selectCategory(category: CommunityCategory['code'] | '') { activeView.value = 'square'; if (activeCategory.value === category) return; activeCategory.value = category; await refreshSquare() }

function quickStyle(entry: CommunityQuickEntry, index: number) { const colors = ['#aeeaff','#ffc2cc','#d7c3ff','#a9f1d5']; return { background: entry.color || colors[index % colors.length] } }
function fallbackQuickIcon(index: number) { return ['助','团','任','＋'][index] || '校' }
function fallbackQuickSubtitle(index: number) { return ['同学互助','一起出发','赚取奖励','发现服务'][index] || '校园服务' }
function clearSearch() { if (keyword.value) keyword.value = ''; else searching.value = false }
function openTarget(target: string) {
  if (!target) return
  if (target.startsWith('/pages/')) return uni.navigateTo({ url: target })
  if (target.startsWith('search:')) { keyword.value = target.slice(7); searching.value = false; return }
  if (target.startsWith('compose:')) return openCompose(target.slice(8) as CommunityCategory['code'])
  const routes: Record<string, string> = { activities: '/pages/community-activities/index', 'activity-compose': '/pages/community-activity-compose/index', messages: '/pages/notifications/index?category=community', products: '/pages/products/index', tasks: '/pages/tasks/index', publisher: '/pages/publisher/index', legal: '/pages/legal/index?key=community_guidelines' }
  if (target === 'compose' || target === 'publish_post') return openCompose()
  if (target === 'posts') { void selectCategory(''); return }
  if (target === 'notifications') return uni.navigateTo({ url: '/pages/notifications/index?category=community' })
  if (['experience','guide','question','showcase'].includes(target)) { activeFeed.value = 'discover'; void selectCategory(target as CommunityCategory['code']); return }
  if (['study','movie','dining','carpool','group_buy','gaming','sports','travel','other'].includes(target)) return uni.navigateTo({ url: `/pages/community-activities/index?category=${target}` })
  if (target === 'publish') { showPublishSheet.value = true; return }
  if (target === 'mine') { void switchView('mine'); return }
  const url = routes[target]
  if (url) uni.navigateTo({ url })
}
function openCompose(category?: CommunityCategory['code'], postId?: string) { if (!ensureAuth()) return; showPublishSheet.value = false; const suffix = buildQueryString({ category, postId }); uni.navigateTo({ url: `/pages/community-compose/index${suffix ? `?${suffix}` : ''}` }) }
function openPost(id: string) { uni.navigateTo({ url: `/pages/community-detail/index?id=${encodeURIComponent(id)}` }) }
function openUserProfile(id: string | null) { if (id) uni.navigateTo({ url: `/pages/user-profile/index?id=${encodeURIComponent(id)}` }) }
function ensureAuth() { if (auth.isAuthenticated) return true; uni.showModal({ title: '登录后参与校园社区', content: '登录后可以发帖、点赞、关注、收藏和参加组团。', confirmText: '去登录', success: result => { if (result.confirm) navigateToLogin() } }); return false }
function avatarText(name: string) { return (name || '同').trim().slice(0, 1) }
function postImages(post: CommunityPost) { return (post.attachments || []).filter(item => item.type === 'image') }
function toggleExpanded(id: string) { expandedIds.value = expandedIds.value.includes(id) ? expandedIds.value.filter(item => item !== id) : [...expandedIds.value, id] }
function isExpanded(id: string) { return expandedIds.value.includes(id) }
function previewPostImages(post: CommunityPost, current: number) { const urls = postImages(post).map(communityAttachmentUrl).filter(Boolean); if (urls.length) uni.previewImage({ urls, current: urls[current] }) }
function sharePost(post: CommunityPost) {
  // #ifndef MP-WEIXIN
  uni.setClipboardData({ data: `【${post.title}】来校园墙看看同学们的新鲜事` })
  // #endif
}
async function toggleLike(post: CommunityPost) { if (!ensureAuth() || !auth.accessToken) return; const previous = { liked: post.liked_by_me, count: post.like_count || 0 }; post.liked_by_me = !previous.liked; post.like_count = Math.max(0, previous.count + (post.liked_by_me ? 1 : -1)); try { const result = post.liked_by_me ? await likeCommunityPost(auth.accessToken, post.id) : await unlikeCommunityPost(auth.accessToken, post.id); post.liked_by_me = result.liked_by_me; post.like_count = result.like_count } catch (error) { post.liked_by_me = previous.liked; post.like_count = previous.count; toast(error) } }
async function toggleFavorite(post: CommunityPost) {
  if (!ensureAuth() || !auth.accessToken) return
  const previous = { favored: post.favorited_by_me, count: post.favorite_count || 0 }
  post.favorited_by_me = !previous.favored
  post.favorite_count = Math.max(0, previous.count + (post.favorited_by_me ? 1 : -1))
  try {
    const result = post.favorited_by_me ? await favoriteCommunityPost(auth.accessToken, post.id) : await unfavoriteCommunityPost(auth.accessToken, post.id)
    post.favorited_by_me = result.favorited_by_me
    post.favorite_count = result.favorite_count
  } catch (error) {
    post.favorited_by_me = previous.favored
    post.favorite_count = previous.count
    toast(error)
  }
}
async function toggleFollow(post: CommunityPost) {
  if (!ensureAuth() || !auth.accessToken || !post.author.id || followLoadingId.value) return
  if (post.author.is_followed) {
    const result = await uni.showModal({ title: '取消关注？', content: `取消后将不再优先看到「${post.author.nickname}」的动态。`, confirmText: '取消关注', confirmColor: '#e93f4f' })
    if (!result.confirm) return
  }
  const userId = post.author.id
  const previous = post.author.is_followed
  followLoadingId.value = post.id
  post.author.is_followed = !previous
  try {
    const state = post.author.is_followed ? await followCommunityUser(auth.accessToken, userId) : await unfollowCommunityUser(auth.accessToken, userId)
    post.author.is_followed = state.is_followed
  } catch (error) {
    post.author.is_followed = previous
    toast(error)
  } finally {
    followLoadingId.value = ''
  }
}
async function toggleComments(post: CommunityPost) { if (openedPostId.value === post.id) { openedPostId.value = ''; comments.value = []; return } openedPostId.value = post.id; await loadComments(post.id) }
async function loadComments(postId: string) { commentsLoading.value = true; commentsError.value = ''; try { comments.value = (await listCommunityComments(postId, auth.accessToken || undefined)).items } catch (error) { commentsError.value = errorMessage(error) } finally { commentsLoading.value = false } }
function parentCommentAuthor(comment: CommunityComment) { return comments.value.find(item => item.id === comment.parent_comment_id)?.author.nickname || '原评论' }
function openCommentComposer(post: CommunityPost, parent?: CommunityComment) {
  if (!ensureAuth() || !auth.accessToken) return
  const sameTarget = commentComposerPost.value?.id === post.id && commentReply.value?.id === parent?.id
  if (!sameTarget) {
    commentDraft.value = ''
    commentRequestKey.value = createCommunityRequestKey('comment')
  } else if (!commentRequestKey.value) {
    commentRequestKey.value = createCommunityRequestKey('comment')
  }
  commentComposerPost.value = post
  commentReply.value = parent || null
  commentSubmitError.value = ''
  commentComposerVisible.value = true
}
function closeCommentComposer() {
  if (!commentSubmitting.value) commentComposerVisible.value = false
}
async function submitComment(content: string) {
  const targetPost = commentComposerPost.value
  if (!targetPost || !auth.accessToken || commentSubmitting.value) return
  commentSubmitting.value = true
  commentSubmitError.value = ''
  try {
    const result = await createCommunityComment(auth.accessToken, targetPost.id, content, commentReply.value?.id, commentRequestKey.value)
    commentComposerVisible.value = false
    commentDraft.value = ''
    commentReply.value = null
    commentRequestKey.value = ''
    uni.showToast({ title: result.status === 'published' ? '评论成功' : '评论已提交审核', icon: 'success' })
    await Promise.all([loadComments(targetPost.id), refreshSquare()])
  } catch (error) {
    commentSubmitError.value = errorMessage(error)
  } finally {
    commentSubmitting.value = false
  }
}
async function report(type: 'post' | 'comment', id: string) { if (!ensureAuth() || !auth.accessToken) return; const description = await input('请说明举报原因'); if (!description) return; try { await reportCommunityContent(auth.accessToken, type, id, 'other', description); uni.showToast({ title: '举报已提交', icon: 'success' }) } catch (error) { toast(error) } }
async function removePost(post: CommunityPost) { if (!auth.accessToken) return; const result = await uni.showModal({ title: '删除这条发布？', content: '删除后无法恢复，已发放的帖子奖励会按规则处理。', confirmText: '删除', confirmColor: '#e93f4f' }); if (!result.confirm) return; try { await deleteCommunityPost(auth.accessToken, post.id); await Promise.all([loadMine(), refreshSquare()]); uni.showToast({ title: '已删除', icon: 'success' }) } catch (error) { toast(error) } }
function displayStatus(post: CommunityPost) { const review = post.latest_version?.review_status; if (review === 'pending') return { label: '审核中', tone: 'pending' }; if (review === 'rejected') return { label: '未通过', tone: 'danger' }; if (post.status === 'published') return { label: '已公开', tone: 'success' }; return { label: '处理中', tone: 'pending' } }
function input(title: string): Promise<string | null> { return new Promise(resolve => uni.showModal({ title, editable: true, success: result => resolve(result.confirm ? result.content?.trim() || null : null) })) }
function errorMessage(error: unknown) { return error instanceof Error ? error.message : '操作失败，请稍后重试。' }
function toast(error: unknown) { uni.showToast({ title: errorMessage(error), icon: 'none' }) }
</script>

<style scoped>
.page{--c-green:#0BBF63;--c-deep:#08A152;--c-soft:#E7F9EF;--c-softer:#F0FBF5;--c-line:#EDEFEF;--c-canvas:#F4F6F4;--t1:#191B1F;--t2:#767C85;--t3:#AEB3BB;--c-red:#FF3B30;--c-orange:#F2620A;min-height:100vh;padding:var(--tm-custom-header-top,calc(env(safe-area-inset-top) + 8px)) 22rpx 260rpx;box-sizing:border-box;background:radial-gradient(circle at 92% 2%,rgba(11,191,99,.1),transparent 300rpx),linear-gradient(180deg,#F2FAF5 0,#F4F6F4 420rpx);color:var(--t1)}
text{box-sizing:border-box}

/* 顶部标题栏 */
.wall-head{display:flex;align-items:center;justify-content:space-between;gap:16rpx;padding:6rpx 2rpx 4rpx}
.wall-title{color:var(--t1);font-size:22px;font-weight:900;letter-spacing:1rpx}
.wall-actions{display:flex;align-items:center;gap:14rpx}
.icon-action{display:flex;align-items:center;justify-content:center;width:64rpx;height:64rpx;margin:0;padding:0;border:0;border-radius:50%;background:#fff;box-shadow:0 8rpx 22rpx rgba(23,26,23,.07);line-height:64rpx}
.icon-action::after{border:0}
.bell-glyph{font-size:17px;line-height:1}
.icon-action.has-dot{position:relative}
.icon-action.has-dot::after{content:'';position:absolute;right:12rpx;top:12rpx;width:12rpx;height:12rpx;border:3rpx solid #fff;border-radius:50%;background:var(--c-red)}
.publish-btn{height:64rpx;margin:0;padding:0 26rpx;border:0;border-radius:999rpx;background:linear-gradient(135deg,#12D978,#08A152);box-shadow:0 10rpx 22rpx rgba(11,191,99,.28);color:#fff;font-size:14px;font-weight:800;line-height:64rpx}
.publish-btn::after{border:0}

/* 搜索面板 */
.search-panel{display:flex;align-items:center;gap:16rpx;margin-top:14rpx;padding:10rpx 18rpx;border-radius:999rpx;background:#fff;box-shadow:0 10rpx 26rpx rgba(23,26,23,.06)}
.search-panel input{height:60rpx;flex:1;font-size:14px}
.search-panel>text{color:var(--c-deep);font-size:13px;font-weight:700}

/* 主 Tab */
.feed-tabs{display:flex;align-items:center;gap:34rpx;margin:18rpx 4rpx 4rpx}
.feed-tab{position:relative;padding:8rpx 2rpx;color:var(--t2);font-size:16px;font-weight:700}
.feed-tab.active{color:var(--t1);font-size:19px;font-weight:900}
.feed-tab.active::after{content:'';position:absolute;left:50%;bottom:-8rpx;width:34rpx;height:6rpx;border-radius:999rpx;background:linear-gradient(135deg,#12D978,#08A152);transform:translateX(-50%)}
.feed-tab.mine-tab{margin-left:auto;padding:8rpx 20rpx;border:1rpx dashed #9FD8B6;border-radius:999rpx;background:var(--c-softer);color:var(--c-deep);font-size:12px}
.feed-tab.mine-tab.active{border-color:transparent;background:linear-gradient(135deg,#12D978,#08A152);color:#fff}

/* 吸顶分类栏 */
.sticky-bar{position:sticky;z-index:60;top:var(--tm-custom-header-top,calc(env(safe-area-inset-top) + 8px));margin:14rpx -22rpx 0;padding:10rpx 22rpx 12rpx;background:rgba(243,250,245,.94);backdrop-filter:blur(10px);white-space:nowrap}
.category-row{display:inline-flex;align-items:center;gap:12rpx;padding-right:28rpx}
.category-tab{padding:13rpx 26rpx;border-radius:999rpx;background:#fff;color:var(--t2);font-size:13px;font-weight:700;box-shadow:0 4rpx 12rpx rgba(23,26,23,.04)}
.category-tab.active{background:linear-gradient(135deg,#12D978,#08A152);color:#fff;font-weight:800;box-shadow:0 8rpx 20rpx rgba(11,191,99,.24)}
.feed-hint{padding:10rpx 6rpx;color:var(--t3);font-size:12px}

/* 轻量工具条（公约/热榜/快捷入口合并横滑，仅发现页） */
.tool-scroll{margin:14rpx -22rpx 0;padding:0 22rpx;box-sizing:border-box;white-space:nowrap}
.tool-row{display:inline-flex;gap:12rpx;padding:4rpx 28rpx 8rpx 2rpx}
.tool-chip{display:inline-flex;align-items:center;gap:12rpx;padding:14rpx 20rpx 14rpx 14rpx;border-radius:18rpx;background:#fff;box-shadow:0 6rpx 16rpx rgba(23,26,23,.05);vertical-align:top}
.chip-icon{display:flex;align-items:center;justify-content:center;width:52rpx;height:52rpx;flex:none;border-radius:14rpx;font-size:13px;font-weight:900}
.guide-chip{background:linear-gradient(135deg,#E7F9EF,#F0FBF5)}
.guide-check{background:var(--c-green);color:#fff}
.hot-icon{background:#FFEEEA;color:var(--c-red)}
.chip-copy{min-width:0}
.chip-title{display:block;overflow:hidden;max-width:200rpx;color:var(--t1);font-size:12px;font-weight:800;text-overflow:ellipsis;white-space:nowrap}
.chip-sub{display:block;overflow:hidden;max-width:160rpx;margin-top:3rpx;color:var(--t3);font-size:10px;text-overflow:ellipsis;white-space:nowrap}
.quick-icon image{width:34rpx;height:34rpx}
.quick-icon text{font-size:13px;font-weight:900}

/* Feed 标题 */
.feed-heading{display:flex;align-items:center;justify-content:space-between;gap:16rpx;margin:26rpx 2rpx 6rpx}
.feed-heading-left{display:flex;align-items:center;gap:14rpx;min-width:0}
.search-glyph{display:flex;align-items:center;justify-content:center;width:52rpx;height:52rpx;flex:none;border-radius:50%;background:#fff;color:var(--c-deep);font-size:18px;font-weight:800;box-shadow:0 6rpx 16rpx rgba(23,26,23,.06)}
.feed-title{position:relative;display:block;padding-left:16rpx;color:var(--t1);font-size:17px;font-weight:900}
.feed-title::before{content:'';position:absolute;left:0;top:6rpx;bottom:6rpx;width:6rpx;border-radius:999rpx;background:linear-gradient(180deg,#12D978,#08A152)}
.feed-copy{display:block;margin-top:4rpx;color:var(--t3);font-size:11px}
.feed-heading-links{display:flex;align-items:center;gap:12rpx;flex:none}
.group-link{padding:10rpx 16rpx;border-radius:999rpx;background:var(--c-soft);color:var(--c-deep);font-size:11px;font-weight:800}

/* 帖子卡片 */
.post-card,.mine-card{margin-top:14rpx;padding:26rpx;border-radius:24rpx;background:#fff;box-shadow:0 10rpx 28rpx rgba(23,26,23,.05)}
.post-head{display:flex;align-items:flex-start;justify-content:space-between;gap:16rpx}
.author-row{display:flex;align-items:center;gap:14rpx;min-width:0}
.author-avatar,.comment-avatar{display:flex;align-items:center;justify-content:center;flex:none;overflow:hidden;border-radius:50%;background:linear-gradient(145deg,#DFF5E7,#E7F9EF);color:var(--c-deep);font-weight:900}
.author-avatar{width:76rpx;height:76rpx;font-size:15px}
.author-avatar image{width:100%;height:100%}
.author-info{min-width:0}
.author-name-row{display:flex;align-items:center;gap:8rpx}
.author-name{overflow:hidden;max-width:240rpx;color:var(--t1);font-size:14px;font-weight:850;text-overflow:ellipsis;white-space:nowrap}
.verified-badge{padding:3rpx 10rpx;flex:none;border-radius:8rpx;background:#FFF3E2;color:#D97706;font-size:10px;font-weight:800}
.post-time{display:block;margin-top:4rpx;color:var(--t3);font-size:10px}
.head-right{display:flex;align-items:center;gap:10rpx;flex:none}
.follow-btn{height:52rpx;margin:0;padding:0 22rpx;border:1rpx solid var(--c-green);border-radius:999rpx;background:#fff;color:var(--c-deep);font-size:12px;font-weight:800;line-height:50rpx}
.follow-btn::after{border:0}
.follow-btn.followed{border-color:#C9E7D4;background:var(--c-softer);color:var(--t2)}
.follow-btn[disabled]{opacity:.6}
.more{padding:10rpx 6rpx;color:var(--t3);font-weight:900}
.post-title{display:block;margin-top:18rpx;color:var(--t1);font-size:15px;font-weight:850;line-height:1.5}
.topic-prefix{color:var(--c-green)}
.post-content{display:block;margin-top:8rpx;color:#3E454D;font-size:14px;line-height:1.7;white-space:pre-wrap}
.post-content.collapsed{display:-webkit-box;overflow:hidden;-webkit-box-orient:vertical;-webkit-line-clamp:3}
.expand-link{display:block;margin-top:8rpx;color:var(--c-deep);font-size:13px;font-weight:700}
.media-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8rpx;margin-top:16rpx}
.media-grid.count-1{grid-template-columns:1fr}
.media-grid.count-2{grid-template-columns:repeat(2,1fr)}
.post-image{width:100%;height:180rpx;border-radius:14rpx;background:var(--c-canvas)}
.count-1 .post-image{height:320rpx}
.loc-chip{display:inline-flex;align-items:center;gap:8rpx;margin-top:14rpx;padding:8rpx 16rpx;border-radius:999rpx;background:#F2F6F4;color:var(--t2);font-size:11px}
.loc-pin{color:var(--c-deep);font-size:12px}
.post-meta{display:flex;align-items:center;justify-content:space-between;margin-top:18rpx;padding-top:16rpx;border-top:1rpx solid #F2F4F3;color:var(--t3);font-size:12px}
.meta-left{display:flex;align-items:center;gap:36rpx}
.post-meta .liked{color:var(--c-red);font-weight:900}
.post-meta .favored{color:#F5A623;font-weight:900}
.share-btn{display:flex;align-items:center;justify-content:center;width:56rpx;height:48rpx;margin:0;padding:0;border:0;background:transparent;color:var(--t3);font-size:15px;line-height:48rpx}
.share-btn::after{border:0}
.share-glyph{padding:4rpx 8rpx;color:var(--t3);font-size:15px}

/* 评论面板 */
.comments-panel{margin-top:16rpx;padding:16rpx;border-radius:18rpx;background:#F7F9F7}
.comment-compose{padding:16rpx 20rpx;border-radius:999rpx;background:#fff;color:var(--t3);font-size:13px}
.comments-state{padding:22rpx;text-align:center;color:var(--t2);font-size:13px}
.comments-state.error{color:var(--c-red)}
.comment-row{display:flex;gap:12rpx;padding:16rpx 0;border-bottom:1rpx solid #EDEFEF}
.comment-row:last-child{border-bottom:0}
.comment-row.reply-row{margin-left:40rpx;padding-left:14rpx;border-left:3rpx solid #CDE8D2}
.comment-avatar{width:48rpx;height:48rpx;font-size:12px}
.comment-main{min-width:0;flex:1}
.comment-author,.comment-content{display:block}
.comment-author{color:var(--t1);font-size:13px;font-weight:800}
.comment-reply-target{margin-left:6rpx;color:var(--c-deep);font-size:12px;font-weight:700}
.comment-content{margin-top:4rpx;color:#3E454D;font-size:13px;line-height:1.6}
.comment-main>view{display:flex;gap:20rpx;margin-top:8rpx;color:var(--t3);font-size:12px}

/* 状态卡 */
.state-card{margin-top:16rpx;padding:56rpx 28rpx;border-radius:24rpx;background:#fff;text-align:center;box-shadow:0 10rpx 28rpx rgba(23,26,23,.05)}
.state-illustration{display:flex;align-items:center;justify-content:center;width:96rpx;height:96rpx;margin:0 auto;border-radius:28rpx;background:var(--c-soft);color:var(--c-deep);font-size:22px;font-weight:900}
.state-title{display:block;margin-top:24rpx;color:var(--t1);font-size:16px;font-weight:900}
.state-copy{display:block;margin:10rpx auto 0;max-width:460rpx;color:var(--t2);font-size:13px;line-height:1.6}
.strict-note{display:inline-block;margin-top:16rpx;padding:8rpx 20rpx;border-radius:999rpx;background:#F1F5F1;color:#5C7264;font-size:12px;line-height:1.5}
.state-card button,.load-more{height:72rpx;margin:24rpx auto 0;padding:0 40rpx;border:0;border-radius:999rpx;background:linear-gradient(135deg,#12D978,#08A152);color:#fff;font-size:13px;font-weight:800;line-height:72rpx;box-shadow:0 10rpx 24rpx rgba(11,191,99,.22)}
.state-card button::after,.load-more::after,.mine-hero button::after,.mine-actions button::after,.sheet-cancel::after,.publish-fab::after{border:0}
.error-card .state-illustration{background:#FFEEEA;color:var(--c-red)}
.skeleton-card{height:260rpx}
.load-more{display:block;width:320rpx;padding:0}
.list-end{display:block;padding:36rpx 0;text-align:center;color:var(--t3);font-size:12px}

/* 我的发布 */
.mine-hero{display:flex;align-items:center;justify-content:space-between;margin-top:20rpx;padding:28rpx;border-radius:24rpx;background:linear-gradient(135deg,#E7F9EF,#F0FBF5);box-shadow:0 10rpx 26rpx rgba(11,191,99,.1)}
.mine-hero>view text{display:block}
.mine-hero>view text:first-child{color:var(--t1);font-size:16px;font-weight:900}
.mine-hero>view text:last-child{margin-top:5rpx;color:var(--t2);font-size:12px}
.mine-hero button{width:auto;height:60rpx;margin:0;padding:0 24rpx;border:0;border-radius:999rpx;background:linear-gradient(135deg,#12D978,#08A152);color:#fff;font-size:12px;font-weight:800;line-height:60rpx;box-shadow:0 8rpx 18rpx rgba(11,191,99,.22)}
.mine-summary{display:grid;grid-template-columns:repeat(3,1fr);margin-top:16rpx;border-radius:20rpx;background:#fff;box-shadow:0 8rpx 22rpx rgba(23,26,23,.04)}
.mine-summary view{padding:22rpx;text-align:center}
.mine-summary view+view{border-left:1rpx solid #F2F4F3}
.mine-summary strong,.mine-summary text{display:block}
.mine-summary strong{color:var(--t1);font-size:17px}
.mine-summary text{margin-top:4rpx;color:var(--t3);font-size:11px}
.mine-head{display:flex;align-items:center;justify-content:space-between;color:var(--t3);font-size:12px}
.status-pill{padding:5rpx 14rpx;border-radius:999rpx;font-size:11px;font-weight:800}
.status-success{background:#E7F9EF;color:#08A152}
.status-pending{background:#EAF2FF;color:#3B82F6}
.status-danger{background:#FFEEEA;color:#FF3B30}
.mine-title{display:block;margin-top:16rpx;color:var(--t1);font-size:15px;font-weight:850}
.mine-content{display:-webkit-box;overflow:hidden;margin-top:8rpx;color:var(--t2);font-size:13px;line-height:1.6;-webkit-box-orient:vertical;-webkit-line-clamp:2}
.review-note{margin-top:14rpx;padding:14rpx 16rpx;border-radius:14rpx;background:#FFEEEA;color:#D0322B;font-size:12px;line-height:1.5}
.mine-actions{display:flex;align-items:center;justify-content:flex-end;gap:12rpx;margin-top:16rpx}
.mine-actions button{width:auto;height:56rpx;margin:0;padding:0 22rpx;border:1rpx solid var(--c-line);border-radius:999rpx;background:#fff;color:var(--t2);font-size:12px;font-weight:700;line-height:52rpx}
.mine-actions button.danger{border-color:#FFC9C4;color:var(--c-red)}

/* 发布 Sheet */
.sheet-mask{position:fixed;z-index:1200;inset:0;display:flex;align-items:flex-end;justify-content:center;background:rgba(15,20,16,.45);backdrop-filter:blur(4px)}
.publish-sheet{width:100%;max-width:750rpx;padding:16rpx 28rpx calc(28rpx + env(safe-area-inset-bottom));border-radius:32rpx 32rpx 0 0;background:#fff;box-sizing:border-box;box-shadow:0 -16rpx 48rpx rgba(15,20,16,.14)}
.sheet-handle{width:72rpx;height:8rpx;margin:0 auto 20rpx;border-radius:999rpx;background:#E3E7E3}
.sheet-head{display:flex;align-items:center;justify-content:space-between}
.sheet-head>view text{display:block}
.sheet-head>view text:first-child{color:var(--t1);font-size:18px;font-weight:900}
.sheet-head>view text:last-child{margin-top:4rpx;color:var(--t3);font-size:12px}
.sheet-close{display:flex;align-items:center;justify-content:center;width:56rpx;height:56rpx;border-radius:50%;background:#F2F4F3;color:var(--t2);font-size:18px}
.publish-primary{display:flex;align-items:center;justify-content:space-between;margin-top:22rpx;padding:26rpx;border-radius:22rpx;background:linear-gradient(135deg,#E7F9EF,#F0FBF5)}
.publish-primary view text{display:block}
.publish-primary view text:first-child{color:var(--t1);font-size:16px;font-weight:900}
.publish-primary view text:last-child{margin-top:5rpx;color:var(--t2);font-size:12px}
.reward-chip{padding:8rpx 14rpx;flex:none;border-radius:999rpx;background:#FFF2E5;color:var(--c-orange);font-size:10px;font-weight:800}
.publish-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14rpx;margin-top:18rpx}
.publish-option{position:relative;padding:22rpx 20rpx;border-radius:20rpx;background:#F7F9F7}
.publish-option.help{background:#FFF8E3}
.publish-option.group{background:#FFF0F3}
.publish-option.mall{background:#E7F9EF}
.publish-option.task{background:#F1EDFF}
.option-icon{position:absolute;right:16rpx;top:16rpx;display:flex;align-items:center;justify-content:center;width:44rpx;height:44rpx;border-radius:12rpx;background:#fff;font-size:13px;font-weight:900}
.publish-option>text:nth-child(2){display:block;color:var(--t1);font-size:14px;font-weight:850}
.publish-option small{display:block;margin-top:5rpx;color:var(--t2);font-size:11px}
.sheet-cancel{height:72rpx;margin-top:20rpx;border:1rpx solid var(--c-line);border-radius:999rpx;background:#fff;color:var(--t2);font-size:13px;font-weight:700;line-height:70rpx}

/* 悬浮发布按钮 */
.publish-fab{position:fixed;z-index:80;right:30rpx;bottom:calc(190rpx + env(safe-area-inset-bottom));display:flex;align-items:center;justify-content:center;width:104rpx;height:104rpx;margin:0;padding:0 0 6rpx;border:0;border-radius:50%;background:linear-gradient(135deg,#12D978,#08A152);box-shadow:0 14rpx 32rpx rgba(11,191,99,.35);color:#fff;font-size:44rpx;font-weight:400;line-height:1}

@media screen and (min-width:768px){.page{width:750rpx;margin:0 auto}}
</style>
