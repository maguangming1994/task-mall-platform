<template>
  <view class="page" :style="topSafeAreaStyle">
    <ModulePlaceholder v-if="modules.resolved && !moduleEnabled" code="module_community" />
    <view v-else-if="!modules.resolved" class="mp-skeleton">
      <view class="tm-skeleton" style="height: 200rpx; border-radius: 24rpx; margin: 24rpx 28rpx;" />
      <view class="tm-skeleton" style="height: 320rpx; border-radius: 24rpx; margin: 20rpx 28rpx;" />
    </view>
    <template v-else>
    <view class="campus-head">
      <view class="campus-brand" @click="showCampusTip">
        <view class="campus-logo"><image v-if="home.brand.logo_url" :src="communityHomeAssetUrl(home.brand.logo_url)" mode="aspectFit" /><text v-else>{{ home.brand.logo_text || '校' }}</text></view>
        <view class="brand-copy"><text class="campus-name">{{ home.brand.name }}</text><text class="campus-sub">{{ home.brand.caption }}</text></view>
        <text class="chevron">⌄</text>
      </view>
      <view class="head-actions">
        <button class="round-action" aria-label="搜索校园内容" @click="searching = true">⌕</button>
        <button class="round-action has-dot" aria-label="打开社区消息" @click="openTarget('messages')">信</button>
      </view>
    </view>

    <view v-if="searching" class="search-panel">
      <input v-model="keyword" confirm-type="search" focus placeholder="搜索帖子标题、内容或同学" @confirm="searching = false" />
      <text @click="clearSearch">{{ keyword ? '清空' : '取消' }}</text>
    </view>

    <view class="community-intro">
      <view class="intro-copy">
        <text class="section-kicker">CAMPUS COMMUNITY</text>
        <text class="intro-title">校园社区</text>
        <text class="intro-subtitle">分享校园生活，找到同路伙伴</text>
      </view>
      <view class="intro-stats"><strong>{{ posts.length }}</strong><text>条新动态</text></view>
    </view>

    <scroll-view class="category-scroll" scroll-x :show-scrollbar="false">
      <view class="category-row">
        <view :class="['category-tab', { active: activeView === 'square' && !activeCategory }]" @click="selectCategory('')">全部</view>
        <view v-for="category in categories" :key="category.code" :class="['category-tab', { active: activeView === 'square' && activeCategory === category.code }]" @click="selectCategory(category.code)">{{ category.label }}</view>
        <view v-if="auth.isAuthenticated" :class="['category-tab', { active: activeView === 'mine' }]" @click="switchView('mine')">我的发布</view>
      </view>
    </scroll-view>

    <view class="community-guide" @click="openTarget('legal')">
      <view class="guide-icon">✓</view>
      <view><text>友善、真实、安全地交流</text><text>发布前请阅读社区文明公约与隐私提醒</text></view>
      <text class="guide-arrow">›</text>
    </view>

    <view v-for="(banner, index) in displayBanners" :key="index" class="feature-card" :style="bannerStyle(banner, index)" @click="openTarget(banner.link_type)">
      <image v-if="banner.image_url" class="feature-image" :src="communityHomeAssetUrl(banner.image_url)" mode="aspectFill" />
      <view class="feature-copy">
        <text class="feature-badge">校园精选</text>
        <text class="feature-title">{{ banner.title }}</text>
        <text class="feature-subtitle">{{ banner.subtitle }}</text>
        <text class="feature-link">查看详情 ›</text>
      </view>
    </view>

    <view class="quick-grid">
      <view v-for="(entry, index) in quickEntries.slice(0, 4)" :key="entry.id || index" class="quick-entry" @click="openTarget(entry.target)">
        <view class="quick-icon" :style="quickStyle(entry, index)"><image v-if="entry.icon_url" :src="communityHomeAssetUrl(entry.icon_url)" mode="aspectFit" /><text v-else>{{ fallbackQuickIcon(index) }}</text></view>
        <view><text class="quick-label">{{ entry.label }}</text><text class="quick-sub">{{ entry.description || fallbackQuickSubtitle(index) }}</text></view>
        <text class="quick-arrow">›</text>
      </view>
    </view>

    <view class="hot-strip" @click="openHotTopic(activeHotTopic)">
      <view class="hot-brand">校园热榜 <text>HOT</text></view>
      <view class="hot-rank">{{ hotTopicIndex + 1 }}</view>
      <text class="hot-title">{{ activeHotTopic?.label || '发现校园里的新鲜事' }}</text>
      <text class="hot-arrow">›</text>
    </view>

    <template v-if="activeView === 'square'">
      <view class="feed-heading"><view><text class="feed-title">{{ keyword ? '搜索结果' : activeCategoryLabel }}</text><text class="feed-copy">校园真实动态 · 友善交流</text></view><view class="group-link" @click="openTarget('activities')">组团大厅 <text>›</text></view></view>

      <view v-if="publicError && !posts.length" class="state-card error-card">
        <view class="state-illustration">!</view><text class="state-title">内容暂时走丢了</text><text class="state-copy">{{ publicError }}</text><button @click="refreshSquare">重新加载</button>
      </view>
      <view v-else-if="loadingPublic && !posts.length" class="skeleton-list"><view v-for="index in 3" :key="index" class="post-card skeleton-card tm-skeleton" /></view>
      <template v-else>
        <view v-for="post in visiblePosts" :key="post.id" class="post-card">
          <view class="post-head">
            <view class="author-row">
              <view class="author-avatar">{{ avatarText(post.author.nickname) }}</view>
              <view class="author-info"><view><text class="author-name">{{ post.author.nickname }}</text><text class="campus-tag">校园伙伴</text></view><text class="post-time">{{ formatTime(post.published_at || post.created_at) }}</text></view>
            </view>
            <text class="more" @click="report('post', post.id)">•••</text>
          </view>
          <text class="post-title" @click="openPost(post.id)"><text class="topic-prefix">#{{ post.category_label }}</text> {{ post.title }}</text>
          <text :class="['post-content', { collapsed: !isExpanded(post.id) }]" @click="toggleExpanded(post.id)">{{ post.content }}</text>
          <text v-if="unicodeLength(post.content) > 92" class="expand-link" @click="toggleExpanded(post.id)">{{ isExpanded(post.id) ? '收起' : '展开全文' }}</text>
          <view v-if="postImages(post).length" :class="['media-grid', `count-${Math.min(postImages(post).length, 3)}`]">
            <image v-for="(attachment, imageIndex) in postImages(post).slice(0, 6)" :key="attachment.file_id" class="post-image" :src="communityAttachmentUrl(attachment)" mode="aspectFill" @click="previewPostImages(post, imageIndex)" />
          </view>
          <view class="post-meta"><text>◉ {{ postViewCount(post) }}</text><view><text :class="{ liked: post.liked_by_me }" @click="toggleLike(post)">{{ post.liked_by_me ? '♥' : '♡' }} {{ post.like_count || 0 }}</text><text @click="toggleComments(post)">▢ {{ post.comment_count }}</text></view></view>
          <view v-if="openedPostId === post.id" class="comments-panel">
            <view class="comment-compose" @click="openCommentComposer(post)">友善评论，说说你的想法…</view>
            <view v-if="commentsLoading" class="comments-state">评论加载中…</view>
            <view v-else-if="commentsError" class="comments-state error"><text>{{ commentsError }}</text><text @click="loadComments(post.id)">重试</text></view>
            <view v-else-if="!comments.length" class="comments-state">还没有评论，来坐第一排吧。</view>
            <view v-for="item in comments" v-else :key="item.id" :class="['comment-row', { 'reply-row': item.parent_comment_id }]">
              <view class="comment-avatar">{{ avatarText(item.author.nickname) }}</view>
              <view class="comment-main"><text class="comment-author">{{ item.author.nickname }}<text v-if="item.parent_comment_id" class="comment-reply-target"> 回复 @{{ parentCommentAuthor(item) }}</text></text><text class="comment-content">{{ item.content }}</text><view><text v-if="!item.parent_comment_id" @click="openCommentComposer(post, item)">回复</text><text v-if="auth.user?.id !== item.author.id" @click="report('comment', item.id)">举报</text></view></view>
            </view>
          </view>
        </view>

        <view v-if="!visiblePosts.length" class="state-card">
          <view class="state-illustration">✦</view><text class="state-title">还没有同学发过这类内容</text><text class="state-copy">分享校园新鲜事、学习经验或互助问题，成为第一个发帖的人。</text><button v-if="modules.isEnabled('module_community_post')" @click="openCompose(activeCategory || undefined)">发布第一条动态</button>
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
        <view class="mine-head"><text :class="['status-pill', `status-${displayStatus(post).tone}`]">{{ displayStatus(post).label }}</text><text>{{ formatTime(post.created_at) }}</text></view>
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

    <view class="community-action-dock">
      <button class="secondary-action" @click="switchView(activeView === 'mine' ? 'square' : 'mine')">{{ activeView === 'mine' ? '逛校园广场' : '我的发布' }}</button>
      <button v-if="modules.isEnabled('module_community_post')" class="primary-action" @click="showPublishSheet = true">＋ 发布动态</button>
    </view>
    </template>

    <AppTabBar current-target="community" @compose="showPublishSheet = true" />
  </view>
</template>

<script setup lang="ts">
import { formatDateTime as formatTime } from '@/utils/dateTime'
import { computed, ref } from 'vue'
import { onLoad, onPullDownRefresh, onShow } from '@dcloudio/uni-app'
import AppTabBar from '@/components/AppTabBar.vue'
import CommunityCommentComposer from '@/components/CommunityCommentComposer.vue'
import {
  communityAttachmentUrl, communityHomeAssetUrl, createCommunityComment, createCommunityRequestKey, deleteCommunityPost, likeCommunityPost,
  DEFAULT_COMMUNITY_PUBLISHING_RULES,
  listCommunityCategories, listCommunityComments, listCommunityPosts, listMyCommunityPosts,
  loadCommunityHome, reportCommunityContent, unlikeCommunityPost,
  type CommunityAttachment, type CommunityCategory, type CommunityComment, type CommunityHomeBanner,
  type CommunityHomeConfig, type CommunityHotTopic, type CommunityPost, type CommunityPublishingRules, type CommunityQuickEntry,
} from '@/api/community'
import { useAuthStore } from '@/stores/auth'
import { navigateToLogin } from '@/utils/authRedirect'
import { buildQueryString } from '@/utils/query'
import { createTopSafeAreaStyle } from '@/utils/safeArea'
import { unicodeLength } from '@/utils/textInput'
import ModulePlaceholder from '@/components/ModulePlaceholder.vue'
import { useModulesStore } from '@/stores/modules'

const topSafeAreaStyle = createTopSafeAreaStyle()

const modules = useModulesStore()
const moduleEnabled = computed(() => modules.isEnabled('module_community'))

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
const activeCategory = ref<CommunityCategory['code'] | ''>('')
const keyword = ref('')
const searching = ref(false)
const bannerIndex = ref(0)
const hotTopicIndex = ref(0)
const showPublishSheet = ref(false)
const openedPostId = ref('')
const expandedIds = ref<string[]>([])
const page = ref(1)
const total = ref(0)
const loadingPublic = ref(false)
const loadingMore = ref(false)
const loadingMine = ref(false)
const commentsLoading = ref(false)
const publicError = ref('')
const mineError = ref('')
const commentsError = ref('')
let hotTimer: ReturnType<typeof setInterval> | null = null

const hasMore = computed(() => posts.value.length < total.value)
const displayBanners = computed(() => [home.value.banner])
const quickEntries = computed(() => home.value.quick_entries.filter(item => item.enabled).sort((left, right) => (left.sort_order || 0) - (right.sort_order || 0)))
const hotTopics = computed(() => home.value.hot_topics.filter(item => item.enabled))
const activeCategoryLabel = computed(() => categories.value.find(item => item.code === activeCategory.value)?.label || '校园广场')
const pendingCount = computed(() => myPosts.value.filter(post => post.latest_version?.review_status === 'pending').length)
const publishedCount = computed(() => myPosts.value.filter(post => post.status === 'published').length)
const activeHotTopic = computed(() => hotTopics.value[hotTopicIndex.value % Math.max(hotTopics.value.length, 1)])
const visiblePosts = computed(() => {
  const query = keyword.value.trim().toLowerCase()
  if (!query) return posts.value
  return posts.value.filter(post => `${post.title} ${post.content} ${post.author.nickname}`.toLowerCase().includes(query))
})

onLoad((options) => {
  const category = typeof options?.category === 'string' ? options.category : ''
  if (category && fallbackCategories.some(item => item.code === category)) activeCategory.value = category as CommunityCategory['code']
  if (options?.view === 'mine') activeView.value = 'mine'
})

onShow(async () => {
  await modules.ensureReady()
  if (!moduleEnabled.value) return
  if (activeView.value === 'mine' && auth.isAuthenticated) await loadMine()
  else await refreshSquare()
  await Promise.all([loadHomeConfig(), loadCategories()])
  if (hotTimer) clearInterval(hotTimer)
  hotTimer = setInterval(() => { if (hotTopics.value.length) hotTopicIndex.value = (hotTopicIndex.value + 1) % hotTopics.value.length }, 3600)
})
onPullDownRefresh(async () => { try { await modules.ensureReady(); if (!moduleEnabled.value) return; await Promise.all([activeView.value === 'mine' ? loadMine() : refreshSquare(), loadHomeConfig(), loadCategories()]) } finally { uni.stopPullDownRefresh() } })

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
async function refreshSquare() { loadingPublic.value = true; publicError.value = ''; page.value = 1; try { const result = await listCommunityPosts(1, 10, activeCategory.value || undefined, auth.accessToken || undefined); posts.value = result.items; total.value = result.pagination.total } catch (error) { publicError.value = errorMessage(error) } finally { loadingPublic.value = false } }
async function loadMore() { if (loadingMore.value || !hasMore.value) return; loadingMore.value = true; try { const next = page.value + 1; const result = await listCommunityPosts(next, 10, activeCategory.value || undefined, auth.accessToken || undefined); posts.value.push(...result.items); page.value = next; total.value = result.pagination.total } catch (error) { toast(error) } finally { loadingMore.value = false } }
async function loadMine() { if (!auth.accessToken) return; loadingMine.value = true; mineError.value = ''; try { myPosts.value = (await listMyCommunityPosts(auth.accessToken)).items } catch (error) { mineError.value = errorMessage(error) } finally { loadingMine.value = false } }
async function switchView(view: 'square' | 'mine') { if (view === 'mine' && !ensureAuth()) return; activeView.value = view; if (view === 'mine') await loadMine() }
async function selectCategory(category: CommunityCategory['code'] | '') { activeView.value = 'square'; if (activeCategory.value === category) return; activeCategory.value = category; await refreshSquare() }

function bannerStyle(banner: CommunityHomeBanner, index: number) { const tails = ['#13E2A2','#FF8F37','#7B5CFF']; return { background: `linear-gradient(135deg,${banner.accent_color || '#9CFF45'},${tails[index % tails.length]})` } }
function quickStyle(entry: CommunityQuickEntry, index: number) { const colors = ['#aeeaff','#ffc2cc','#d7c3ff','#a9f1d5']; return { background: entry.color || colors[index % colors.length] } }
function fallbackQuickIcon(index: number) { return ['助','团','任','＋'][index] || '校' }
function fallbackQuickSubtitle(index: number) { return ['同学互助','一起出发','赚取奖励','发现服务'][index] || '校园服务' }
function clearSearch() { if (keyword.value) keyword.value = ''; else searching.value = false }
function showCampusTip() { uni.showToast({ title: '后续可切换已认证校园', icon: 'none' }) }
function openHotTopic(topic?: CommunityHotTopic) { if (topic) openTarget(topic.target) }
function openTarget(target: string) {
  if (!target) return
  if (target.startsWith('/pages/')) return uni.navigateTo({ url: target })
  if (target.startsWith('search:')) { keyword.value = target.slice(7); searching.value = false; return }
  if (target.startsWith('compose:')) return openCompose(target.slice(8) as CommunityCategory['code'])
  const routes: Record<string, string> = { activities: '/pages/community-activities/index', 'activity-compose': '/pages/community-activity-compose/index', messages: '/pages/notifications/index?category=community', products: '/pages/products/index', tasks: '/pages/tasks/index', publisher: '/pages/publisher/index', legal: '/pages/legal/index?key=community_guidelines' }
  if (target === 'compose' || target === 'publish_post') return openCompose()
  if (target === 'posts') { void selectCategory(''); return }
  if (target === 'notifications') return uni.navigateTo({ url: '/pages/notifications/index?category=community' })
  if (['experience','guide','question','showcase'].includes(target)) { void selectCategory(target as CommunityCategory['code']); return }
  if (['study','movie','dining','carpool','group_buy','gaming','sports','travel','other'].includes(target)) return uni.navigateTo({ url: `/pages/community-activities/index?category=${target}` })
  if (target === 'publish') { showPublishSheet.value = true; return }
  if (target === 'mine') { void switchView('mine'); return }
  const url = routes[target]
  if (url) uni.navigateTo({ url })
}
function openCompose(category?: CommunityCategory['code'], postId?: string) { if (!ensureAuth()) return; showPublishSheet.value = false; const suffix = buildQueryString({ category, postId }); uni.navigateTo({ url: `/pages/community-compose/index${suffix ? `?${suffix}` : ''}` }) }
function openPost(id: string) { uni.navigateTo({ url: `/pages/community-detail/index?id=${encodeURIComponent(id)}` }) }
function ensureAuth() { if (auth.isAuthenticated) return true; uni.showModal({ title: '登录后参与校园社区', content: '登录后可以发帖、点赞、评论和参加组团。', confirmText: '去登录', success: result => { if (result.confirm) navigateToLogin() } }); return false }
function avatarText(name: string) { return (name || '同').trim().slice(0, 1) }
function postViewCount(post: CommunityPost) { return Number((post as CommunityPost & { view_count?: number }).view_count || 0) }
function postImages(post: CommunityPost) { return (post.attachments || []).filter(item => item.type === 'image') }
function toggleExpanded(id: string) { expandedIds.value = expandedIds.value.includes(id) ? expandedIds.value.filter(item => item !== id) : [...expandedIds.value, id] }
function isExpanded(id: string) { return expandedIds.value.includes(id) }
function previewPostImages(post: CommunityPost, current: number) { const urls = postImages(post).map(communityAttachmentUrl).filter(Boolean); if (urls.length) uni.previewImage({ urls, current: urls[current] }) }
async function toggleLike(post: CommunityPost) { if (!ensureAuth() || !auth.accessToken) return; const previous = { liked: post.liked_by_me, count: post.like_count || 0 }; post.liked_by_me = !previous.liked; post.like_count = Math.max(0, previous.count + (post.liked_by_me ? 1 : -1)); try { const result = post.liked_by_me ? await likeCommunityPost(auth.accessToken, post.id) : await unlikeCommunityPost(auth.accessToken, post.id); post.liked_by_me = result.liked_by_me; post.like_count = result.like_count } catch (error) { post.liked_by_me = previous.liked; post.like_count = previous.count; toast(error) } }
async function toggleComments(post: CommunityPost) { if (openedPostId.value === post.id) { openedPostId.value = ''; comments.value = []; return } openedPostId.value = post.id; await loadComments(post.id) }
async function loadComments(postId: string) { commentsLoading.value = true; commentsError.value = ''; try { comments.value = (await listCommunityComments(postId)).items } catch (error) { commentsError.value = errorMessage(error) } finally { commentsLoading.value = false } }
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
.page{--campus-ink:#111827;--campus-green:#13d995;--campus-yellow:#ffe33d;--campus-blue:#38bdff;--campus-red:#ff5c70;min-height:100vh;padding:calc(22rpx + env(safe-area-inset-top)) 24rpx 150rpx;box-sizing:border-box;background:linear-gradient(180deg,#eeffd8 0,#f9fff4 340rpx,#f7f8fb 680rpx);color:var(--campus-ink)}text{box-sizing:border-box}.campus-head,.head-actions,.campus-brand,.search-pill,.message-button,.post-head,.author-row,.author-info>view,.post-meta,.post-meta>view,.feed-heading,.mine-hero,.mine-head,.mine-actions,.sheet-head{display:flex;align-items:center}.campus-head{justify-content:space-between;gap:16rpx}.campus-brand{min-width:0;gap:11rpx}.campus-logo{display:flex;align-items:center;justify-content:center;width:66rpx;height:66rpx;flex:none;border:3rpx solid var(--campus-ink);border-radius:20rpx;background:var(--campus-yellow);box-shadow:0 6rpx 0 var(--campus-ink);font-size:17px;font-weight:950}.campus-name,.campus-sub,.hero-title,.hero-copy,.hero-badge,.quick-label,.quick-sub,.feed-title,.feed-copy,.author-name,.post-time,.post-title,.post-content,.expand-link,.state-title,.state-copy,.mine-title,.mine-content,.sheet-head text,.publish-primary text,.publish-option text,.publish-option small{display:block}.campus-name{overflow:hidden;max-width:250rpx;font-size:15px;font-weight:950;text-overflow:ellipsis;white-space:nowrap}.campus-sub{margin-top:2rpx;color:#5c6678;font-size:12px}.chevron{font-size:14px}.head-actions{gap:10rpx}.search-pill{gap:5rpx;padding:0 16rpx;height:58rpx;border:2rpx solid var(--campus-ink);border-radius:999rpx;background:rgba(255,255,255,.78);font-size:13px}.search-pill text:first-child{font-size:17px}.message-button{position:relative;justify-content:center;flex-direction:column;width:58rpx;height:58rpx;border:2rpx solid var(--campus-ink);border-radius:18rpx;background:#fff;font-size:10px;font-weight:800}.message-button text:first-child{position:absolute;right:8rpx;top:5rpx;color:var(--campus-red);font-size:10px}.search-panel{display:flex;align-items:center;gap:16rpx;margin-top:18rpx;padding:12rpx 18rpx;border:2rpx solid var(--campus-ink);border-radius:18rpx;background:#fff;box-shadow:0 6rpx 0 rgba(17,24,39,.12)}.search-panel input{height:58rpx;flex:1;font-size:14px}.search-panel>text{font-size:13px;font-weight:850}.hero-swiper{height:278rpx;margin-top:24rpx}.hero-card{position:relative;height:252rpx;overflow:hidden;border:3rpx solid var(--campus-ink);border-radius:28rpx;box-shadow:0 10rpx 0 var(--campus-ink)}.hero-image{position:absolute;inset:0;width:100%;height:100%;opacity:.54}.hero-copy-wrap{position:relative;z-index:2;width:65%;padding:28rpx}.hero-badge{width:max-content;padding:6rpx 12rpx;border:2rpx solid var(--campus-ink);border-radius:999rpx;background:#fff;font-size:12px;font-weight:900}.hero-title{margin-top:12rpx;font-size:21px;font-weight:950;line-height:1.18}.hero-copy{margin-top:8rpx;font-size:13px;font-weight:650;line-height:1.45}.hero-link{width:max-content;margin-top:17rpx;padding:9rpx 16rpx;border:2rpx solid var(--campus-ink);border-radius:999rpx;background:var(--campus-ink);color:#fff;font-size:12px;font-weight:900}.hero-link text{margin-left:6rpx}.hero-shape{position:absolute;border:3rpx solid var(--campus-ink);background:rgba(255,255,255,.3);transform:rotate(-12deg)}.shape-one{right:45rpx;top:35rpx;width:105rpx;height:80rpx;border-radius:24rpx}.shape-two{right:-25rpx;bottom:-35rpx;width:180rpx;height:140rpx;border-radius:50%}.swiper-dots{display:flex;justify-content:center;gap:8rpx;height:24rpx}.swiper-dots text{width:10rpx;height:10rpx;border-radius:50%;background:#c8cfca}.swiper-dots text.active{width:28rpx;border-radius:999rpx;background:var(--campus-ink)}.quick-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10rpx;margin-top:13rpx;padding:24rpx 14rpx 19rpx;border:2rpx solid var(--campus-ink);border-radius:26rpx;background:#fff;box-shadow:0 8rpx 0 rgba(17,24,39,.1)}.quick-entry{text-align:center}.quick-icon{display:flex;align-items:center;justify-content:center;width:74rpx;height:74rpx;margin:0 auto;border:3rpx solid var(--campus-ink);border-radius:23rpx;background:color-mix(in srgb,var(--quick-color) 25%,white);box-shadow:inset -10rpx -10rpx 0 color-mix(in srgb,var(--quick-color) 20%,transparent);color:var(--campus-ink);font-size:17px;font-weight:950}.quick-label{margin-top:11rpx;font-size:13px;font-weight:950}.quick-sub{margin-top:3rpx;color:#7b8494;font-size:10px}.hot-strip{display:flex;align-items:center;gap:11rpx;margin-top:20rpx;padding:17rpx 18rpx;border:2rpx solid var(--campus-ink);border-radius:20rpx;background:#f6ff8e;box-shadow:0 6rpx 0 rgba(17,24,39,.1)}.hot-brand{flex:none;font-size:14px;font-weight:950}.hot-brand text{margin-left:3rpx;color:#f13b50;font-size:10px}.hot-rank{display:flex;align-items:center;justify-content:center;width:33rpx;height:33rpx;flex:none;border-radius:50%;background:var(--campus-red);color:#fff;font-size:12px;font-weight:950}.hot-title{overflow:hidden;flex:1;font-size:13px;text-overflow:ellipsis;white-space:nowrap}.hot-arrow{font-size:19px}.category-scroll{margin:26rpx -24rpx 0;padding:0 24rpx;box-sizing:border-box;white-space:nowrap}.category-row{display:inline-flex;gap:24rpx;padding-right:28rpx}.category-tab{position:relative;padding:15rpx 2rpx;color:#697385;font-size:14px;font-weight:750}.category-tab.active{color:var(--campus-ink);font-size:17px;font-weight:950}.category-tab.active::after{content:'';position:absolute;right:10%;bottom:3rpx;left:10%;height:6rpx;border-radius:999rpx;background:var(--campus-green)}.feed-heading{justify-content:space-between;margin:22rpx 4rpx 4rpx}.feed-title{font-size:17px;font-weight:950}.feed-copy{margin-top:4rpx;color:#7b8494;font-size:12px}.group-link{padding:10rpx 15rpx;border:2rpx solid var(--campus-ink);border-radius:999rpx;background:var(--campus-yellow);font-size:12px;font-weight:900}.post-card,.mine-card{margin-top:18rpx;padding:24rpx;border:2rpx solid var(--campus-ink);border-radius:24rpx;background:#fff;box-shadow:0 8rpx 0 rgba(17,24,39,.1)}.post-head{justify-content:space-between}.author-row{gap:13rpx}.author-avatar,.comment-avatar{display:flex;align-items:center;justify-content:center;flex:none;border:2rpx solid var(--campus-ink);border-radius:50%;background:linear-gradient(145deg,#ffef70,#ff8d96);font-weight:950}.author-avatar{width:58rpx;height:58rpx;font-size:15px}.author-info{min-width:0}.author-info>view{gap:8rpx}.author-name{max-width:220rpx;overflow:hidden;font-size:14px;font-weight:950;text-overflow:ellipsis;white-space:nowrap}.campus-tag{padding:3rpx 8rpx;border-radius:7rpx;background:#e8ebef;color:#667183;font-size:10px}.post-time{margin-top:3rpx;color:#8b93a1;font-size:10px}.more{padding:10rpx;color:#798292;font-weight:950}.post-title{margin-top:18rpx;font-size:15px;font-weight:900;line-height:1.45}.topic-prefix{color:#0abf82}.post-content{margin-top:8rpx;color:#3f4858;font-size:14px;line-height:1.65;white-space:pre-wrap}.post-content.collapsed{display:-webkit-box;overflow:hidden;-webkit-box-orient:vertical;-webkit-line-clamp:3}.expand-link{margin-top:7rpx;color:#118a68;font-size:13px;font-weight:850}.media-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:7rpx;margin-top:14rpx}.media-grid.count-1{grid-template-columns:1fr}.media-grid.count-2{grid-template-columns:repeat(2,1fr)}.post-image{width:100%;height:170rpx;border:2rpx solid var(--campus-ink);border-radius:14rpx}.count-1 .post-image{height:310rpx}.post-meta{justify-content:space-between;margin-top:18rpx;padding-top:15rpx;border-top:2rpx dashed #d7dce3;color:#89909c;font-size:13px}.post-meta>view{gap:28rpx}.post-meta .liked{color:#f13b50;font-weight:950}.comments-panel{margin-top:16rpx;padding:15rpx;border-radius:17rpx;background:#f3f5f7}.comment-compose{padding:14rpx 16rpx;border:2rpx solid #d8dde4;border-radius:999rpx;background:#fff;color:#9299a5;font-size:13px}.comments-state{padding:20rpx;text-align:center;color:#747e8d;font-size:13px}.comments-state.error{color:#d83b4b}.comment-row{display:flex;gap:11rpx;padding:15rpx 0;border-bottom:1rpx solid #dde2e7}.comment-avatar{width:44rpx;height:44rpx;font-size:12px}.comment-main{min-width:0;flex:1}.comment-author,.comment-content{display:block}.comment-author{font-size:13px;font-weight:900}.comment-content{margin-top:3rpx;font-size:13px;line-height:1.5}.comment-main>view{display:flex;gap:18rpx;margin-top:7rpx;color:#717b8b;font-size:12px}.state-card{margin-top:20rpx;padding:54rpx 28rpx;text-align:center;border:2rpx solid var(--campus-ink);border-radius:25rpx;background:#fff;box-shadow:0 8rpx 0 rgba(17,24,39,.1)}.state-illustration{display:flex;align-items:center;justify-content:center;width:90rpx;height:90rpx;margin:0 auto;border:3rpx solid var(--campus-ink);border-radius:28rpx;background:var(--campus-yellow);box-shadow:0 7rpx 0 var(--campus-ink);font-size:21px;font-weight:950}.state-title{margin-top:25rpx;font-size:17px;font-weight:950}.state-copy{margin:9rpx auto 0;max-width:480rpx;color:#717b8b;font-size:13px;line-height:1.6}.state-card button,.load-more{height:66rpx;margin:22rpx auto 0;border:2rpx solid var(--campus-ink);border-radius:999rpx;background:var(--campus-green);color:var(--campus-ink);font-size:13px;font-weight:950;line-height:62rpx;box-shadow:0 5rpx 0 var(--campus-ink)}.state-card button::after,.load-more::after,.mine-hero button::after,.mine-actions button::after,.sheet-cancel::after{border:0}.error-card .state-illustration{background:#ff7b89}.skeleton-card{height:260rpx}.list-end{display:block;padding:34rpx 0;text-align:center;color:#858d9a;font-size:12px}.mine-hero{justify-content:space-between;margin-top:24rpx;padding:26rpx;border:2rpx solid var(--campus-ink);border-radius:24rpx;background:linear-gradient(135deg,#ffe33d,#ff9f43);box-shadow:0 8rpx 0 var(--campus-ink)}.mine-hero>view text{display:block}.mine-hero>view text:first-child{font-size:17px;font-weight:950}.mine-hero>view text:last-child{margin-top:5rpx;font-size:12px}.mine-hero button{width:auto;height:58rpx;margin:0;padding:0 18rpx;border:2rpx solid var(--campus-ink);border-radius:999rpx;background:#fff;font-size:12px;font-weight:900;line-height:54rpx}.mine-summary{display:grid;grid-template-columns:repeat(3,1fr);margin-top:18rpx;border:2rpx solid var(--campus-ink);border-radius:20rpx;background:#fff}.mine-summary view{padding:20rpx;text-align:center}.mine-summary view+view{border-left:2rpx solid var(--campus-ink)}.mine-summary strong,.mine-summary text{display:block}.mine-summary strong{font-size:17px}.mine-summary text{margin-top:4rpx;color:#747e8d;font-size:12px}.mine-head{justify-content:space-between;color:#808a99;font-size:12px}.status-pill{padding:5rpx 12rpx;border-radius:999rpx;font-weight:850}.status-success{background:#d7f9e9;color:#087b55}.status-pending{background:#fff2b8;color:#8a6500}.status-danger{background:#ffe1e4;color:#b92535}.mine-title{margin-top:16rpx;font-size:15px;font-weight:950}.mine-content{display:-webkit-box;overflow:hidden;margin-top:7rpx;color:#5e6878;font-size:13px;line-height:1.55;-webkit-box-orient:vertical;-webkit-line-clamp:2}.review-note{margin-top:13rpx;padding:12rpx;border-radius:12rpx;background:#fff0f1;color:#b92535;font-size:12px}.mine-actions{justify-content:flex-end;gap:10rpx;margin-top:15rpx}.mine-actions button{width:auto;height:54rpx;margin:0;padding:0 18rpx;border:2rpx solid var(--campus-ink);border-radius:999rpx;background:#fff;font-size:12px;line-height:50rpx}.mine-actions button.danger{border-color:#e54253;color:#c83242}.sheet-mask{position:fixed;z-index:1200;inset:0;display:flex;align-items:flex-end;justify-content:center;background:rgba(11,17,28,.58)}.publish-sheet{width:100%;max-width:750rpx;padding:14rpx 28rpx calc(26rpx + env(safe-area-inset-bottom));border:3rpx solid var(--campus-ink);border-bottom:0;border-radius:34rpx 34rpx 0 0;background:#fff;box-sizing:border-box}.sheet-handle{width:80rpx;height:8rpx;margin:0 auto 18rpx;border-radius:999rpx;background:#cbd1d9}.sheet-head{justify-content:space-between}.sheet-head>view text:first-child{font-size:19px;font-weight:950}.sheet-head>view text:last-child{margin-top:4rpx;color:#7b8494;font-size:12px}.sheet-close{display:flex;align-items:center;justify-content:center;width:55rpx;height:55rpx;border:2rpx solid var(--campus-ink);border-radius:50%;font-size:19px}.publish-primary{display:flex;align-items:center;justify-content:space-between;margin-top:22rpx;padding:24rpx;border:3rpx solid var(--campus-ink);border-radius:24rpx;background:linear-gradient(135deg,#19e196,#48f0bf);box-shadow:0 8rpx 0 var(--campus-ink)}.publish-primary view text:first-child{font-size:17px;font-weight:950}.publish-primary view text:last-child{margin-top:5rpx;font-size:12px}.reward-chip{padding:7rpx 11rpx;border:2rpx solid var(--campus-ink);border-radius:999rpx;background:var(--campus-yellow);font-size:10px;font-weight:900}.publish-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:13rpx;margin-top:20rpx}.publish-option{position:relative;padding:20rpx 18rpx;border:3rpx solid var(--campus-ink);border-radius:22rpx;box-shadow:0 7rpx 0 var(--campus-ink)}.publish-option.help{background:#ffe45e}.publish-option.group{background:#ff8e9c}.publish-option.mall{background:#b8f4ff}.publish-option.task{background:#cbb5ff}.option-icon{position:absolute;right:13rpx;top:13rpx;display:flex!important;align-items:center;justify-content:center;width:40rpx;height:40rpx;border:2rpx solid var(--campus-ink);border-radius:12rpx;background:#fff;font-size:13px}.publish-option>text:nth-child(2){font-size:15px;font-weight:950}.publish-option small{margin-top:5rpx;font-size:12px}.sheet-cancel{height:66rpx;margin-top:22rpx;border:2rpx solid var(--campus-ink);border-radius:999rpx;background:#fff;font-size:13px;font-weight:900;line-height:62rpx}@media screen and (min-width:768px){.page{width:750rpx;margin:0 auto}.publish-sheet{border-right:3rpx solid var(--campus-ink);border-left:3rpx solid var(--campus-ink)}}
.community-pulse{display:flex;align-items:center;justify-content:space-between;gap:16rpx;margin-top:20rpx;padding:20rpx;background:linear-gradient(135deg,#fff 0%,#e9f7ff 100%)}.community-pulse>view:first-child{min-width:0}.community-pulse>view:first-child strong,.community-pulse>view:first-child small{display:block}.community-pulse>view:first-child strong{margin-top:8rpx;font-size:15px;font-weight:950}.community-pulse>view:first-child small{margin-top:3rpx;color:#697385;font-size:10px}.pulse-social{display:flex;flex:none;overflow:hidden;border:2rpx solid var(--campus-ink);border-radius:16rpx;background:#fff}.pulse-social view{min-width:58rpx;padding:11rpx 8rpx;text-align:center}.pulse-social view+view{border-left:2rpx solid var(--campus-ink)}.pulse-social strong,.pulse-social text{display:block}.pulse-social strong{font-size:13px;font-weight:950}.pulse-social text{margin-top:2rpx;color:#697385;font-size:10px}.quick-icon image{width:48rpx;height:48rpx}.quick-icon text{font-size:17px;font-weight:950}
.campus-logo{overflow:hidden}.campus-logo image{width:100%;height:100%}

/* V09 柔和校园社区：以轻层级、细描边和真实内容为主。 */
.page{--community-green:#08a978;--community-cyan:#20b7c8;--community-blue:#198be6;--community-ink:#102a43;--community-muted:#667c8e;--community-line:#dceaf0;--community-surface:#fff;--community-canvas:#f4fafc;min-height:100vh;padding:var(--tm-custom-header-top,calc(env(safe-area-inset-top) + 8px)) 22rpx 272rpx;background:radial-gradient(circle at 94% 3%,rgba(39,190,194,.14),transparent 260rpx),linear-gradient(180deg,#fbfefd 0,#f2f9fc 460rpx,#f7fafc 100%);color:var(--community-ink)}
.campus-head{justify-content:space-between;gap:18rpx;padding:4rpx 2rpx 10rpx}.campus-brand{gap:12rpx;min-width:0}.brand-copy{min-width:0}.campus-logo{width:68rpx;height:68rpx;border:0;border-radius:21rpx;background:linear-gradient(145deg,#14b987,#19a6d6);box-shadow:0 10rpx 24rpx rgba(15,139,161,.2);color:#fff;font-size:17px}.campus-name{max-width:250rpx;color:var(--community-ink);font-size:17px;font-weight:850}.campus-sub{color:var(--community-muted);font-size:12px}.chevron{color:#7690a2}.head-actions{gap:10rpx}.round-action{display:flex;align-items:center;justify-content:center;width:62rpx;height:62rpx;margin:0;padding:0;border:1rpx solid var(--community-line);border-radius:20rpx;background:rgba(255,255,255,.92);box-shadow:0 8rpx 22rpx rgba(42,85,105,.08);color:#24455c;font-size:14px;font-weight:800;line-height:62rpx}.round-action::after{border:0}.round-action.has-dot{position:relative}.round-action.has-dot::before{content:'';position:absolute;right:9rpx;top:8rpx;width:10rpx;height:10rpx;border:3rpx solid #fff;border-radius:50%;background:#ff6b62}.search-panel{margin-top:12rpx;padding:8rpx 16rpx;border:1rpx solid var(--community-line);border-radius:20rpx;background:#fff;box-shadow:0 10rpx 28rpx rgba(40,82,104,.08)}.search-panel input{font-size:14px}.search-panel>text{color:var(--community-blue);font-size:13px}.community-intro{position:relative;display:flex;align-items:flex-end;justify-content:space-between;overflow:hidden;margin-top:18rpx;padding:30rpx 28rpx;border:1rpx solid rgba(28,153,178,.18);border-radius:30rpx;background:linear-gradient(135deg,#e9fff5 0,#e7f8ff 58%,#e8efff 100%);box-shadow:0 16rpx 36rpx rgba(28,111,139,.1)}.community-intro::after{content:'';position:absolute;right:-34rpx;top:-50rpx;width:180rpx;height:180rpx;border-radius:50%;background:linear-gradient(145deg,rgba(20,183,168,.16),rgba(77,135,244,.08))}.intro-copy,.intro-stats{position:relative;z-index:1}.section-kicker,.intro-title,.intro-subtitle,.intro-stats strong,.intro-stats text{display:block}.section-kicker{color:#087d9a;font-size:10px;font-weight:900;letter-spacing:2rpx}.intro-title{margin-top:8rpx;font-size:22px;font-weight:900;letter-spacing:-1rpx}.intro-subtitle{margin-top:7rpx;color:#536f81;font-size:13px}.intro-stats{padding:14rpx 18rpx;border:1rpx solid rgba(255,255,255,.78);border-radius:19rpx;background:rgba(255,255,255,.68);text-align:center}.intro-stats strong{font-size:17px}.intro-stats text{margin-top:2rpx;color:#5a7687;font-size:10px}.category-scroll{margin:20rpx -22rpx 0;padding:0 22rpx}.category-row{gap:12rpx;padding-right:28rpx}.category-tab{padding:13rpx 22rpx;border:1rpx solid var(--community-line);border-radius:999rpx;background:#fff;color:#657b8d;font-size:13px;font-weight:750;box-shadow:0 5rpx 14rpx rgba(51,90,109,.04)}.category-tab.active{border-color:transparent;background:linear-gradient(135deg,var(--community-green),var(--community-cyan));color:#fff;font-size:13px;font-weight:850;box-shadow:0 9rpx 22rpx rgba(14,170,151,.2)}.category-tab.active::after{display:none}.community-guide{display:flex;align-items:center;gap:14rpx;margin-top:18rpx;padding:20rpx;border:1rpx solid #dff0d9;border-radius:23rpx;background:linear-gradient(135deg,#f7fff0,#fffdf0);box-shadow:0 10rpx 24rpx rgba(90,124,54,.06)}.guide-icon{display:flex;align-items:center;justify-content:center;width:50rpx;height:50rpx;flex:none;border-radius:16rpx;background:#dff8c7;color:#188452;font-size:14px;font-weight:900}.community-guide>view:nth-child(2){min-width:0;flex:1}.community-guide>view:nth-child(2) text{display:block}.community-guide>view:nth-child(2) text:first-child{font-size:14px;font-weight:850}.community-guide>view:nth-child(2) text:last-child{margin-top:4rpx;color:#718272;font-size:12px}.guide-arrow{color:#759080;font-size:17px}.feature-card{position:relative;min-height:196rpx;overflow:hidden;margin-top:18rpx;border:1rpx solid rgba(15,123,145,.15);border-radius:28rpx;background:linear-gradient(135deg,#ddfff2,#dcf6ff)!important;box-shadow:0 14rpx 34rpx rgba(34,105,128,.1)}.feature-card::after{content:'';position:absolute;right:-42rpx;bottom:-64rpx;width:210rpx;height:210rpx;border-radius:50%;background:rgba(61,185,188,.14)}.feature-image{position:absolute;right:0;top:0;width:44%;height:100%;opacity:.72}.feature-copy{position:relative;z-index:2;width:64%;padding:25rpx}.feature-copy text{display:block}.feature-badge{width:max-content;padding:5rpx 11rpx;border-radius:999rpx;background:#fff7a8;color:#755f00;font-size:10px;font-weight:900}.feature-title{margin-top:10rpx;font-size:17px;font-weight:900;line-height:1.25}.feature-subtitle{display:-webkit-box!important;overflow:hidden;margin-top:7rpx;color:#496779;font-size:12px;line-height:1.45;-webkit-box-orient:vertical;-webkit-line-clamp:2}.feature-link{margin-top:13rpx;color:#087c9e;font-size:12px;font-weight:850}.quick-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12rpx;margin-top:18rpx;padding:0;border:0;background:transparent;box-shadow:none}.quick-entry{display:flex;align-items:center;gap:12rpx;min-width:0;padding:17rpx;border:1rpx solid var(--community-line);border-radius:22rpx;background:#fff;text-align:left;box-shadow:0 9rpx 24rpx rgba(50,94,115,.07)}.quick-entry>view:nth-child(2){min-width:0;flex:1}.quick-icon{width:52rpx;height:52rpx;margin:0;border:0;border-radius:17rpx;background:color-mix(in srgb,var(--quick-color) 18%,white);box-shadow:none;color:#124154;font-size:14px}.quick-icon image{width:34rpx;height:34rpx}.quick-icon text{font-size:14px}.quick-label{margin:0;font-size:13px;font-weight:850}.quick-sub{overflow:hidden;margin-top:2rpx;color:#7a8c99;font-size:10px;text-overflow:ellipsis;white-space:nowrap}.quick-arrow{color:#8ca0ad;font-size:15px}.hot-strip{margin-top:18rpx;padding:17rpx 19rpx;border:1rpx solid #f1e8c8;border-radius:20rpx;background:#fffaf0;box-shadow:0 8rpx 20rpx rgba(124,97,27,.05)}.hot-brand{font-size:13px}.hot-rank{background:#ff7a66}.hot-title{color:#536979}.feed-heading{margin:24rpx 2rpx 6rpx}.feed-title{font-size:17px}.feed-copy{color:#758a98}.group-link{padding:9rpx 15rpx;border:1rpx solid #bcdfe7;background:#fff;color:#147e9d;font-size:12px}.post-card,.mine-card{margin-top:15rpx;padding:23rpx;border:1rpx solid var(--community-line);border-radius:25rpx;background:#fff;box-shadow:0 12rpx 30rpx rgba(41,83,103,.08)}.author-avatar,.comment-avatar{border:0;background:linear-gradient(145deg,#ddfff0,#d9edff);color:#176a82}.campus-tag{background:#eef8f5;color:#3d7a6b}.topic-prefix{color:var(--community-green)}.post-content{color:#3c5668}.post-image{border:0;border-radius:17rpx}.post-meta{border-top:1rpx solid #e7eff3}.comments-panel{background:#f4f9fb}.comment-compose{border:1rpx solid #dbe9ef}.state-card{margin-top:18rpx;padding:48rpx 28rpx;border:1rpx solid var(--community-line);border-radius:26rpx;background:#fff;box-shadow:0 12rpx 30rpx rgba(41,83,103,.07)}.state-illustration{border:0;background:#eaf8fb;box-shadow:none;color:#1388a4}.state-card button,.load-more{border:0;background:linear-gradient(135deg,var(--community-green),var(--community-cyan));color:#fff;box-shadow:0 10rpx 22rpx rgba(10,168,147,.2)}.mine-hero{margin-top:20rpx;border:0;border-radius:26rpx;background:linear-gradient(135deg,#dffcf0,#e3f6ff);box-shadow:0 12rpx 28rpx rgba(38,101,123,.08)}.mine-hero button{border:1rpx solid #b9dfe6;background:#fff;color:#167d98}.mine-summary{border:1rpx solid var(--community-line);border-radius:21rpx}.mine-summary view+view{border-left:1rpx solid var(--community-line)}.mine-actions button{border:1rpx solid var(--community-line)}.sheet-mask{background:rgba(14,37,53,.46);backdrop-filter:blur(4px)}.publish-sheet{border:0;border-radius:34rpx 34rpx 0 0;box-shadow:0 -18rpx 50rpx rgba(20,54,72,.18)}.sheet-close{border:1rpx solid var(--community-line);color:#597080}.publish-primary{border:0;background:linear-gradient(135deg,#dffcf0,#dbf6ff);box-shadow:0 12rpx 26rpx rgba(25,113,136,.1)}.reward-chip{border:0;background:#fff3a5;color:#806000}.publish-option{border:1rpx solid var(--community-line);box-shadow:none}.publish-option.help{background:#fff9dc}.publish-option.group{background:#fff0f3}.publish-option.mall{background:#e9faff}.publish-option.task{background:#f1edff}.option-icon{border:0}.sheet-cancel{border:1rpx solid var(--community-line)}.community-action-dock{position:fixed;z-index:80;left:50%;bottom:calc(112rpx + env(safe-area-inset-bottom));display:grid;grid-template-columns:1fr 1.28fr;gap:12rpx;width:min(690rpx,calc(100% - 44rpx));padding:11rpx;border:1rpx solid rgba(206,226,234,.9);border-radius:26rpx;background:rgba(255,255,255,.94);box-shadow:0 16rpx 40rpx rgba(34,74,94,.16);transform:translateX(-50%);backdrop-filter:blur(12px)}.community-action-dock button{height:68rpx;margin:0;border-radius:20rpx;font-size:13px;font-weight:850;line-height:68rpx}.community-action-dock button::after{border:0}.secondary-action{border:1rpx solid #cce2e9;background:#fff;color:#266077}.primary-action{border:0;background:linear-gradient(135deg,var(--community-green),var(--community-cyan));color:#fff;box-shadow:0 8rpx 18rpx rgba(12,169,151,.2)}
@media screen and (min-width:768px){.page{width:750rpx;margin:0 auto}.publish-sheet{border:0}.community-action-dock{width:690rpx}}
</style>
<style scoped>
.community-action-dock{z-index:var(--tm-z-bottom-action);bottom:calc(84px + env(safe-area-inset-bottom))}
.comment-row.reply-row{margin-left:34rpx;padding-left:12rpx;border-left:3rpx solid #d8ecef}
.comment-reply-target{margin-left:6rpx;color:#1686a2;font-size:12px;font-weight:700}
</style>
