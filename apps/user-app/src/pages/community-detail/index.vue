<template>
  <view class="page">
    <ModulePlaceholder v-if="modules.resolved && !moduleEnabled" code="module_community" />
    <view v-else-if="!modules.resolved" class="mp-skeleton">
      <view class="tm-skeleton" style="height: 200rpx; border-radius: 24rpx; margin: 24rpx 28rpx;" />
      <view class="tm-skeleton" style="height: 320rpx; border-radius: 24rpx; margin: 20rpx 28rpx;" />
    </view>
    <template v-else>
    <view class="subnav">
      <button class="nav-back" aria-label="返回社区" @click="goCommunity">‹</button>
      <text class="nav-title">动态详情</text>
      <button v-if="post" class="nav-more" aria-label="更多操作" @click="report('post', post.id)">•••</button>
      <view v-else class="nav-more" />
    </view>
    <view v-if="loading" class="loading-shell">
      <view class="loading-hero tm-skeleton" />
      <view class="loading-line tm-skeleton" />
      <view class="loading-line short tm-skeleton" />
      <view class="loading-comments tm-skeleton" />
    </view>
    <view v-else-if="invalidId" class="state-card">
      <view class="state-icon">链</view>
      <text class="state-title">动态链接无效</text>
      <text class="state-copy">请从社区列表重新打开一条动态。</text>
      <button class="btn-main" @click="goCommunity">返回社区</button>
    </view>
    <view v-else-if="loadError" class="state-card error">
      <view class="state-icon">!</view>
      <text class="state-title">动态加载失败</text>
      <text class="state-copy">{{ loadError }}</text>
      <button class="btn-main" @click="loadPost">重新加载</button>
    </view>
    <view v-else-if="notFound" class="state-card">
      <view class="state-icon">空</view>
      <text class="state-title">这条动态不存在或已下线</text>
      <text class="state-copy">你可以回到社区，看看其他校园分享。</text>
      <button class="btn-main" @click="goCommunity">返回社区</button>
    </view>
    <template v-else-if="post">
      <view class="card post-card">
        <view class="publisher">
          <view class="avatar">{{ avatarText(post.author.nickname) }}</view>
          <view class="pub-main">
            <text class="pub-name">{{ post.author.nickname }}</text>
            <text class="pub-sub">校园伙伴 · {{ formatTime(post.published_at || post.created_at) }}</text>
          </view>
          <button class="btn-follow" @click="openSharePanel">分享</button>
        </view>
        <view class="post-body">
          <text class="post-topic"># {{ post.category_label }}</text>
          <text class="post-title">{{ post.title }}</text>
          <text class="post-content">{{ post.content }}</text>
        </view>
        <view v-if="images.length" :class="['post-imgs', `count-${Math.min(images.length, 3)}`]">
          <image v-for="(item, index) in images" :key="item.file_id" :src="communityAttachmentUrl(item)" mode="aspectFill" @click="previewImages(index)" />
        </view>
        <view class="metrics">
          <text :class="['metric-like', { liked: post.liked_by_me }]" @click="toggleLike">{{ post.liked_by_me ? '♥' : '♡' }} {{ post.like_count }} 赞</text>
          <text class="metric">▢ {{ post.comment_count }} 评论</text>
        </view>
      </view>

      <view class="card comments-card">
        <view class="sec-head"><text>全部评论</text><text class="sec-count">{{ comments.length }}</text></view>
        <view v-if="commentsLoading" class="cstate">评论加载中…</view>
        <view v-else-if="commentsError" class="cstate error"><text>{{ commentsError }}</text><text class="cstate-retry" @click="retryComments">重新加载</text></view>
        <view v-else-if="!comments.length" class="cstate">还没有评论，来坐第一排吧。</view>
        <view v-for="item in comments" v-else :key="item.id" :class="['cmt', { 'cmt-reply-row': item.parent_comment_id }]">
          <view class="cmt-av">{{ avatarText(item.author.nickname) }}</view>
          <view class="cmt-main">
            <view class="cmt-nm">
              <text>{{ item.author.nickname }}<text v-if="item.parent_comment_id" class="cmt-reply-target"> 回复 @{{ parentCommentAuthor(item) }}</text></text>
              <text class="cmt-time">{{ formatTime(item.created_at) }}</text>
            </view>
            <text class="cmt-txt">{{ item.content }}</text>
            <view class="cmt-actions">
              <text v-if="!item.parent_comment_id" @click="openCommentComposer(item)">回复</text>
              <text v-if="auth.user?.id !== item.author.id" @click="report('comment', item.id)">举报</text>
            </view>
          </view>
        </view>
      </view>

      <view class="cmt-bar">
        <view class="cmt-input" @click="openCommentComposer()">说点什么…</view>
        <view class="cmt-ic" :class="{ hot: post.liked_by_me }" @click="toggleLike"><text class="cmt-ic-ico">{{ post.liked_by_me ? '♥' : '♡' }}</text><text>{{ post.like_count }}</text></view>
        <view class="cmt-ic" @click="openSharePanel"><text class="cmt-ic-ico">↗</text><text>分享</text></view>
      </view>
    </template>

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

    <view v-if="shareVisible" class="share-mask" @click.self="closeSharePanel">
      <view class="share-sheet">
        <view class="share-sheet-head"><view><text class="share-kicker">SHARE POST</text><text class="share-title">分享这条校园动态</text></view><button class="share-close" @click="closeSharePanel">×</button></view>
        <view class="share-options">
          <!-- #ifdef MP-WEIXIN -->
          <button class="share-option" open-type="share" @click="closeSharePanel"><text class="share-option-icon friend">友</text><text class="share-option-name">好友</text></button>
          <!-- #endif -->
          <!-- #ifndef MP-WEIXIN -->
          <button class="share-option" @click="shareToFriend"><text class="share-option-icon friend">友</text><text class="share-option-name">分享好友</text></button>
          <!-- #endif -->
          <button class="share-option" @click="shareToTimeline"><text class="share-option-icon timeline">圈</text><text class="share-option-name">朋友圈</text></button>
          <button class="share-option" @click="openPoster"><text class="share-option-icon poster">报</text><text class="share-option-name">生成海报</text></button>
          <button class="share-option" @click="copyCommunityLink"><text class="share-option-icon link">链</text><text class="share-option-name">复制链接</text></button>
        </view>
        <text class="share-tip">链接会直接打开这条动态，海报可保存后转发。</text>
      </view>
    </view>

    <view v-if="posterVisible" class="poster-mask" @click.self="closePoster">
      <view class="poster-sheet">
        <view class="poster-head"><view><text class="poster-kicker">POSTER</text><text class="poster-title">动态分享海报</text></view><button class="poster-close" @click="closePoster">×</button></view>
        <view class="poster-preview" :style="posterPreviewStyle">
          <canvas id="communityPosterCanvas" canvas-id="communityPosterCanvas" class="poster-canvas" :style="posterCanvasStyle" :width="posterWidth" :height="posterHeight" />
          <view v-if="!posterReady" class="poster-loading"><view class="poster-loading-dot" /><text>正在生成分享海报</text></view>
        </view>
        <text class="poster-tip">保存海报后可发送给好友或发布到朋友圈。</text>
        <view class="poster-actions"><button class="poster-copy" @click="copyCommunityLink">复制链接</button><button class="poster-save" :disabled="!posterReady" @click="savePoster">保存海报</button></view>
      </view>
    </view>
    </template>
  </view>
</template>
<script setup lang="ts">
import { formatDateTime as formatTime } from '@/utils/dateTime'
import { onLoad, onShareAppMessage, onShareTimeline } from '@dcloudio/uni-app'
import { enableWechatShare } from '@/platform/wechatJssdk'
import qrcode from 'qrcode-generator'
import { computed, nextTick, ref } from 'vue'
import CommunityCommentComposer from '@/components/CommunityCommentComposer.vue'
import { communityAttachmentUrl, createCommunityComment, createCommunityRequestKey, DEFAULT_COMMUNITY_PUBLISHING_RULES, getCommunityPost, likeCommunityPost, listCommunityCategories, listCommunityComments, reportCommunityContent, unlikeCommunityPost, type CommunityComment, type CommunityPost, type CommunityPublishingRules } from '@/api/community'
import { getPosterTemplate, type PosterTemplate } from '@/api/poster'
import { ApiError } from '@/api/http'
import { useAuthStore } from '@/stores/auth'
import { navigateToLogin } from '@/utils/authRedirect'
import ModulePlaceholder from '@/components/ModulePlaceholder.vue'
import { useModulesStore } from '@/stores/modules'

const modules = useModulesStore()
const moduleEnabled = computed(() => modules.isEnabled('module_community'))

const auth = useAuthStore()
const post = ref<CommunityPost | null>(null)
const comments = ref<CommunityComment[]>([])
const publishingRules = ref<CommunityPublishingRules>(DEFAULT_COMMUNITY_PUBLISHING_RULES)
const commentReply = ref<CommunityComment | null>(null)
const commentDraft = ref('')
const commentComposerVisible = ref(false)
const commentSubmitting = ref(false)
const commentSubmitError = ref('')
const commentRequestKey = ref('')
const loading = ref(true)
const commentsLoading = ref(false)
const commentsError = ref('')
const loadError = ref('')
const invalidId = ref(false)
const notFound = ref(false)
const shareVisible = ref(false)
const posterVisible = ref(false)
const posterReady = ref(false)
const posterWidth = ref(330)
const posterHeight = ref(520)
const posterTemplate = ref<PosterTemplate | null>(null)
const postId = ref('')
const DEFAULT_POSTER_TEMPLATE: PosterTemplate = {
  public_id: 'default',
  code: 'community_post',
  name: '默认模板',
  canvas_width: 330,
  canvas_height: 520,
  background_type: 'gradient',
  background_value: { stops: ['#0e799d', '#19c6d1', '#76df63'], angle: 135 },
  banner_config: {
    label_text: 'CAMPUS COMMUNITY', label_color: '#10213a', label_bg: '#ffdb4d',
    title_text: '校园动态', title_color: '#ffffff',
  },
  body_config: { card_bg: 'rgba(255,255,255,.96)', category_color: '#60798d', content_color: '#173c58' },
  qrcode_config: {
    cta_text: '扫码查看动态详情', cta_color: '#173c58',
    helper_text: '分享给同学，一起发现校园', helper_color: '#173c58',
    brand_watermark: '任务商城 · 校园社区', brand_color: '#748895',
    footer_text: '任务商城 · 校园成长与奖励平台', footer_color: '#ffffff',
  },
}
const images = computed(() => (post.value?.attachments || []).filter((item) => item.type === 'image'))
const posterCanvasStyle = computed(() => `width:${posterWidth.value}px;height:${posterHeight.value}px`)
const posterPreviewStyle = computed(() => `width:${posterWidth.value}px;height:${posterHeight.value}px`)
const sharePath = computed(() => `/pages/community-detail/index?id=${encodeURIComponent(post.value?.id || postId.value)}`)

onLoad(async (query) => {
  await modules.ensureReady()
  if (!moduleEnabled.value) { loading.value = false; return }
  void loadPublishingRules()
  postId.value = typeof query?.id === 'string' ? query.id.trim() : ''
  invalidId.value = !postId.value
  if (invalidId.value) { loading.value = false; return }
  void loadPost()
  // #ifdef MP-WEIXIN
  uni.showShareMenu({ withShareTicket: true, menus: ['shareAppMessage', 'shareTimeline'] })
  // #endif
})

onShareAppMessage(() => ({ title: shareTitle(), path: sharePath.value }))
onShareTimeline(() => ({ title: shareTitle(), query: `id=${encodeURIComponent(post.value?.id || postId.value)}` }))

async function loadPublishingRules() {
  try {
    publishingRules.value = (await listCommunityCategories()).publishing_rules || DEFAULT_COMMUNITY_PUBLISHING_RULES
  } catch {
    publishingRules.value = DEFAULT_COMMUNITY_PUBLISHING_RULES
  }
}

async function loadPost() {
  if (!postId.value) return
  loading.value = true
  loadError.value = ''
  notFound.value = false
  post.value = null
  try {
    post.value = await getCommunityPost(postId.value)
  } catch (error) {
    if (error instanceof ApiError && error.statusCode === 404) notFound.value = true
    else loadError.value = error instanceof Error ? error.message : '请检查网络后重试。'
    loading.value = false
    return
  }
  loading.value = false
  // 公众号内：配置 JSSDK 分享卡片；小程序端与浏览器端该调用会自动跳过。
  void enableWechatShare({ title: shareTitle(), link: shareLink(), imageUrl: images.value[0]?.url ?? undefined })
  await loadComments(postId.value)
}

async function loadComments(id: string) {
  commentsLoading.value = true
  commentsError.value = ''
  try { comments.value = (await listCommunityComments(id)).items }
  catch (error) { commentsError.value = error instanceof Error ? error.message : '评论加载失败，请稍后重试' }
  finally { commentsLoading.value = false }
}

function retryComments() { if (post.value) void loadComments(post.value.id) }
function parentCommentAuthor(comment: CommunityComment) { return comments.value.find(item => item.id === comment.parent_comment_id)?.author.nickname || '原评论' }
function ensureAuth() {
  if (auth.isAuthenticated) return true
  uni.showModal({ title: '登录后参与社区', content: '登录后可以点赞、评论和举报内容。', confirmText: '去登录', success: (result) => { if (result.confirm) navigateToLogin() } })
  return false
}
async function toggleLike() {
  if (!post.value || !ensureAuth() || !auth.accessToken) return
  try {
    const result = post.value.liked_by_me ? await unlikeCommunityPost(auth.accessToken, post.value.id) : await likeCommunityPost(auth.accessToken, post.value.id)
    post.value.liked_by_me = result.liked_by_me
    post.value.like_count = result.like_count
  } catch (error) { toast(error) }
}
function openCommentComposer(parent?: CommunityComment) {
  if (!post.value || !ensureAuth() || !auth.accessToken) return
  const sameTarget = commentReply.value?.id === parent?.id
  if (!sameTarget) {
    commentDraft.value = ''
    commentRequestKey.value = createCommunityRequestKey('comment')
  } else if (!commentRequestKey.value) {
    commentRequestKey.value = createCommunityRequestKey('comment')
  }
  commentReply.value = parent || null
  commentSubmitError.value = ''
  commentComposerVisible.value = true
}
function closeCommentComposer() {
  if (!commentSubmitting.value) commentComposerVisible.value = false
}
async function submitComment(content: string) {
  if (!post.value || !auth.accessToken || commentSubmitting.value) return
  commentSubmitting.value = true
  commentSubmitError.value = ''
  try {
    const result = await createCommunityComment(auth.accessToken, post.value.id, content, commentReply.value?.id, commentRequestKey.value)
    commentComposerVisible.value = false
    commentDraft.value = ''
    commentReply.value = null
    commentRequestKey.value = ''
    uni.showToast({ title: result.status === 'published' ? '评论成功' : '评论已提交审核', icon: 'success' })
    await loadComments(post.value.id)
    post.value.comment_count = comments.value.length
  } catch (error) {
    commentSubmitError.value = error instanceof Error ? error.message : '评论提交失败，请稍后重试。'
  } finally {
    commentSubmitting.value = false
  }
}
async function report(type: 'post' | 'comment', id: string) {
  if (!ensureAuth() || !auth.accessToken) return
  const result = await uni.showModal({ title: '举报内容', editable: true, placeholderText: '请说明具体问题，平台会保护你的隐私' })
  if (!result.confirm || !result.content?.trim()) return
  try { await reportCommunityContent(auth.accessToken, type, id, 'other', result.content.trim()); uni.showToast({ title: '举报已提交', icon: 'success' }) }
  catch (error) { toast(error) }
}
function previewImages(index: number) { const urls = images.value.map(communityAttachmentUrl).filter(Boolean); if (urls.length) uni.previewImage({ urls, current: urls[index] }) }

function shareTitle() { return post.value ? `${post.value.title}｜校园动态` : '校园动态分享' }
function shareLink() {
  const configured = String(import.meta.env.VITE_H5_PUBLIC_URL || '').replace(/\/$/, '')
  if (configured) return `${configured.replace(/#.*$/, '')}/#${sharePath.value}`
  // #ifdef H5
  const url = new URL(window.location.href)
  url.search = ''
  url.hash = sharePath.value
  return url.toString()
  // #endif
  // #ifndef H5
  return sharePath.value
  // #endif
}
function openSharePanel() { if (post.value) shareVisible.value = true }
function closeSharePanel() { shareVisible.value = false }
async function shareToFriend() {
  closeSharePanel()
  // #ifdef H5
  if (typeof navigator.share === 'function') {
    try { await navigator.share({ title: shareTitle(), text: shareTitle(), url: shareLink() }); return }
    catch (error) { if (isShareAbort(error)) return }
  }
  // #endif
  copyCommunityLink()
}
async function shareToTimeline() {
  closeSharePanel()
  // #ifdef MP-WEIXIN
  uni.showShareMenu({ withShareTicket: true, menus: ['shareAppMessage', 'shareTimeline'] })
  return undefined
  // #endif
  // #ifdef H5
  if (typeof navigator.share === 'function') {
    try { await navigator.share({ title: shareTitle(), text: `推荐一条校园动态：${shareTitle()}`, url: shareLink() }); return }
    catch (error) { if (isShareAbort(error)) return }
  }
  // #endif
  copyCommunityLink()
}
function isShareAbort(error: unknown) { return Boolean(error && typeof error === 'object' && 'name' in error && error.name === 'AbortError') }
function copyCommunityLink() { closeSharePanel(); uni.setClipboardData({ data: shareLink(), success: () => uni.showToast({ title: '链接已复制', icon: 'success' }) }) }

async function openPoster() {
  if (!post.value) return
  closeSharePanel()
  posterReady.value = false
  posterTemplate.value = DEFAULT_POSTER_TEMPLATE
  posterVisible.value = true

  // 先展示默认模板，同时拉取后端模板（按当前日期命中的）
  try {
    const resp = await getPosterTemplate('community_post')
    if (resp.template) {
      posterTemplate.value = resp.template
    }
  } catch {
    // 接口失败时使用默认模板，不阻塞体验
  }

  const system = uni.getSystemInfoSync()
  const baseW = posterTemplate.value.canvas_width || 330
  const baseH = posterTemplate.value.canvas_height || 520
  const fitW = Math.max(300, Math.min(360, system.windowWidth - 48))
  posterWidth.value = fitW
  posterHeight.value = Math.round(fitW * (baseH / baseW))

  nextTick(() => setTimeout(drawPoster, 80))
}
function closePoster() { posterVisible.value = false }
function roundedRect(ctx: UniApp.CanvasContext, x: number, y: number, width: number, height: number, radius: number) {
  const r = Math.min(radius, width / 2, height / 2)
  ctx.beginPath(); ctx.moveTo(x + r, y); ctx.lineTo(x + width - r, y); ctx.arcTo(x + width, y, x + width, y + r, r); ctx.lineTo(x + width, y + height - r); ctx.arcTo(x + width, y + height, x + width - r, y + height, r); ctx.lineTo(x + r, y + height); ctx.arcTo(x, y + height, x, y + height - r, r); ctx.lineTo(x, y + r); ctx.arcTo(x, y, x + r, y, r); ctx.closePath()
}
function posterLines(value: string, limit = 14) { const chars = Array.from(value); return [chars.slice(0, limit).join(''), chars.slice(limit, limit * 2).join('')].filter(Boolean) }

function applyBackground(ctx: UniApp.CanvasContext, width: number, height: number, scale: number, template: PosterTemplate) {
  const bg = template.background_value || {}
  const type = template.background_type

  if (type === 'gradient') {
    const stops = Array.isArray(bg.stops) && bg.stops.length >= 2 ? (bg.stops as string[]) : ['#0e799d', '#19c6d1', '#76df63']
    const gradient = ctx.createLinearGradient(0, 0, width, height)
    stops.forEach((c, i) => gradient.addColorStop(i / Math.max(1, stops.length - 1), c))
    ctx.setFillStyle(gradient); ctx.fillRect(0, 0, width, height)
    // 圆角装饰（和原设计一致）
    ctx.setFillStyle('rgba(255,255,255,.14)'); ctx.beginPath(); ctx.arc(scale * 288, scale * 56, scale * 82, 0, Math.PI * 2); ctx.fill()
  } else if (type === 'solid') {
    ctx.setFillStyle((bg.color as string) || '#ffffff'); ctx.fillRect(0, 0, width, height)
  } else {
    // image 背景：先铺底色兜底，图片加载完成后重绘
    ctx.setFillStyle('#ffffff'); ctx.fillRect(0, 0, width, height)
  }
}

function drawPoster() {
  if (!post.value || !posterTemplate.value) return
  const tpl = posterTemplate.value
  const width = posterWidth.value; const height = posterHeight.value; const scale = width / 330; const px = (v: number) => v * scale
  const ctx = uni.createCanvasContext('communityPosterCanvas')
  const banner = tpl.banner_config || {}
  const body = tpl.body_config || {}
  const qr = tpl.qrcode_config || {}

  // 背景
  applyBackground(ctx, width, height, scale, tpl)

  // 顶部 Banner 标签 + 标题
  const labelBg = (banner.label_bg as string) || '#ffdb4d'
  const labelColor = (banner.label_color as string) || '#10213a'
  const titleColor = (banner.title_color as string) || '#ffffff'
  roundedRect(ctx, px(22), px(23), px(112), px(24), px(12)); ctx.setFillStyle(labelBg); ctx.fill()
  ctx.setFillStyle(labelColor); ctx.setFontSize(px(11)); ctx.setTextAlign('center')
  ctx.fillText((banner.label_text as string) || 'CAMPUS COMMUNITY', px(78), px(39)); ctx.setTextAlign('left')
  ctx.setFillStyle(titleColor); ctx.setFontSize(px(15))
  ctx.fillText((banner.title_text as string) || '校园动态', px(22), px(75))
  ctx.setFontSize(px(25))
  posterLines(post.value.title).forEach((line, index) => ctx.fillText(line, px(22), px(116 + index * 34)))

  // 主体卡片
  const cardBg = (body.card_bg as string) || 'rgba(255,255,255,.96)'
  const catColor = (body.category_color as string) || '#60798d'
  const contentColor = (body.content_color as string) || '#173c58'
  ctx.setFillStyle(cardBg); roundedRect(ctx, px(18), px(188), px(294), px(296), px(24)); ctx.fill()
  ctx.setFillStyle(catColor); ctx.setFontSize(px(12))
  ctx.fillText(`# ${post.value.category_label}`, px(38), px(220))
  ctx.setFillStyle(contentColor); ctx.setFontSize(px(16))
  posterLines(post.value.content, 18).slice(0, 2).forEach((line, index) => ctx.fillText(line, px(38), px(252 + index * 25)))

  // CTA 小标签
  ctx.setFillStyle('#edf8fc'); roundedRect(ctx, px(36), px(315), px(258), px(48), px(14)); ctx.fill()
  ctx.setFillStyle((qr.cta_color as string) || '#173c58'); ctx.setFontSize(px(12))
  ctx.fillText((qr.cta_text as string) || '扫码查看动态详情', px(51), px(345))

  // 二维码
  const qrc = qrcode(0, 'M'); qrc.addData(shareLink()); qrc.make()
  const qrSize = px(86); const qrX = px(38); const qrY = px(382); const cells = qrc.getModuleCount(); const cell = qrSize / cells
  ctx.setFillStyle('#fff'); ctx.fillRect(qrX - px(7), qrY - px(7), qrSize + px(14), qrSize + px(14))
  ctx.setFillStyle('#11344f')
  for (let row = 0; row < cells; row += 1) for (let col = 0; col < cells; col += 1) if (qrc.isDark(row, col)) ctx.fillRect(qrX + col * cell, qrY + row * cell, Math.ceil(cell), Math.ceil(cell))

  // 底部辅助文案 + 水印
  ctx.setFillStyle((qr.helper_color as string) || '#173c58'); ctx.setFontSize(px(15))
  ctx.fillText((qr.helper_text as string) || '分享给同学，一起发现校园', px(150), px(414))
  ctx.setFillStyle((qr.brand_color as string) || '#748895'); ctx.setFontSize(px(10))
  ctx.fillText((qr.brand_watermark as string) || '任务商城 · 校园社区', px(150), px(438))
  ctx.setFillStyle((qr.footer_color as string) || '#fff'); ctx.setFontSize(px(10))
  ctx.fillText((qr.footer_text as string) || '任务商城 · 校园成长与奖励平台', px(22), height - px(17))

  // 如果是图片背景，加载后再绘一次
  if (tpl.background_type === 'image') {
    const imageUrl = (tpl.background_value?.image_url as string | undefined)
    if (imageUrl) {
      uni.getImageInfo({
        src: imageUrl,
        success: (info) => {
          const rctx = uni.createCanvasContext('communityPosterCanvas')
          // 先铺图片背景（cover 模式）
          const imgRatio = info.width / info.height
          const canvasRatio = width / height
          let drawW = width; let drawH = height; let drawX = 0; let drawY = 0
          if (imgRatio > canvasRatio) { drawW = height * imgRatio; drawX = (width - drawW) / 2 }
          else { drawH = width / imgRatio; drawY = (height - drawH) / 2 }
          rctx.drawImage(info.path, drawX, drawY, drawW, drawH)
          // 复用当前函数的其余绘制逻辑：重绘在最上层
          // 简化：用新的 ctx 把所有元素再画一遍
          // 这里不重复调用 applyBackground，直接跳过背景绘制
          // Banner
          rctx.setFillStyle(labelBg); roundedRect(rctx, px(22), px(23), px(112), px(24), px(12)); rctx.fill()
          rctx.setFillStyle(labelColor); rctx.setFontSize(px(11)); rctx.setTextAlign('center')
          rctx.fillText((banner.label_text as string) || 'CAMPUS COMMUNITY', px(78), px(39)); rctx.setTextAlign('left')
          rctx.setFillStyle(titleColor); rctx.setFontSize(px(15))
          rctx.fillText((banner.title_text as string) || '校园动态', px(22), px(75))
          rctx.setFontSize(px(25))
          posterLines(post.value!.title).forEach((line, index) => rctx.fillText(line, px(22), px(116 + index * 34)))
          // Body
          rctx.setFillStyle(cardBg); roundedRect(rctx, px(18), px(188), px(294), px(296), px(24)); rctx.fill()
          rctx.setFillStyle(catColor); rctx.setFontSize(px(12))
          rctx.fillText(`# ${post.value!.category_label}`, px(38), px(220))
          rctx.setFillStyle(contentColor); rctx.setFontSize(px(16))
          posterLines(post.value!.content, 18).slice(0, 2).forEach((line, index) => rctx.fillText(line, px(38), px(252 + index * 25)))
          // CTA box
          rctx.setFillStyle('#edf8fc'); roundedRect(rctx, px(36), px(315), px(258), px(48), px(14)); rctx.fill()
          rctx.setFillStyle((qr.cta_color as string) || '#173c58'); rctx.setFontSize(px(12))
          rctx.fillText((qr.cta_text as string) || '扫码查看动态详情', px(51), px(345))
          // QR
          const qrr = qrcode(0, 'M'); qrr.addData(shareLink()); qrr.make()
          const cells2 = qrr.getModuleCount(); const cell2 = qrSize / cells2
          rctx.setFillStyle('#fff'); rctx.fillRect(qrX - px(7), qrY - px(7), qrSize + px(14), qrSize + px(14))
          rctx.setFillStyle('#11344f')
          for (let row = 0; row < cells2; row += 1) for (let col = 0; col < cells2; col += 1) if (qrr.isDark(row, col)) rctx.fillRect(qrX + col * cell2, qrY + row * cell2, Math.ceil(cell2), Math.ceil(cell2))
          // Bottom text
          rctx.setFillStyle((qr.helper_color as string) || '#173c58'); rctx.setFontSize(px(15))
          rctx.fillText((qr.helper_text as string) || '分享给同学，一起发现校园', px(150), px(414))
          rctx.setFillStyle((qr.brand_color as string) || '#748895'); rctx.setFontSize(px(10))
          rctx.fillText((qr.brand_watermark as string) || '任务商城 · 校园社区', px(150), px(438))
          rctx.setFillStyle((qr.footer_color as string) || '#fff'); rctx.setFontSize(px(10))
          rctx.fillText((qr.footer_text as string) || '任务商城 · 校园成长与奖励平台', px(22), height - px(17))
          rctx.draw(false, () => { posterReady.value = true })
        },
        fail: () => {
          // 图片加载失败，保底完成渲染
          ctx.draw(false, () => { posterReady.value = true })
        },
      })
      return
    }
  }

  ctx.draw(false, () => { posterReady.value = true })
}
function savePoster() {
  if (!posterReady.value) return
  uni.canvasToTempFilePath({ canvasId: 'communityPosterCanvas', width: posterWidth.value, height: posterHeight.value, destWidth: posterWidth.value * 3, destHeight: posterHeight.value * 3, success: (result) => {
    // #ifdef H5
    const anchor = document.createElement('a'); anchor.href = result.tempFilePath; anchor.download = `${post.value?.title || '校园动态'}-分享海报.png`; anchor.click(); uni.showToast({ title: '海报已下载', icon: 'success' })
    // #endif
    // #ifndef H5
    uni.saveImageToPhotosAlbum({ filePath: result.tempFilePath, success: () => uni.showToast({ title: '海报已保存', icon: 'success' }), fail: () => uni.showToast({ title: '请允许保存到相册', icon: 'none' }) })
    // #endif
  }, fail: () => uni.showToast({ title: '海报生成失败，请重试', icon: 'none' }) })
}
function avatarText(value: string) { return (value || '同').trim().slice(0, 1) }
function toast(error: unknown) { uni.showToast({ title: error instanceof Error ? error.message : '操作失败', icon: 'none' }) }
function goCommunity() { uni.reLaunch({ url: '/pages/community/index' }) }
</script>
<style scoped>
.page{min-height:100vh;padding-bottom:150rpx;background:#F4F6F4;color:#191B1F}
.subnav{display:flex;align-items:center;height:88rpx;padding:0 16rpx;background:#fff}
.nav-back{display:flex;align-items:center;justify-content:center;width:68rpx;height:68rpx;margin:0;padding:0;color:#191B1F;font-size:40rpx;line-height:60rpx}
.nav-back::after{border:0}
.nav-title{flex:1;font-size:32rpx;font-weight:700;text-align:center}
.nav-more{display:flex;align-items:center;justify-content:center;width:68rpx;height:68rpx;margin:0;padding:0;color:#767C85;font-size:22rpx;letter-spacing:2rpx}
.nav-more::after{border:0}
.loading-shell{display:flex;flex-direction:column;gap:18rpx;padding:24rpx}
.loading-hero{height:420rpx;border-radius:32rpx}
.loading-line{width:78%;height:28rpx;border-radius:12rpx}
.loading-line.short{width:48%}
.loading-comments{height:360rpx;border-radius:32rpx}
.state-card{margin:20rpx 24rpx 0;padding:100rpx 40rpx;border-radius:32rpx;background:#fff;text-align:center}
.state-icon{display:flex;align-items:center;justify-content:center;width:120rpx;height:120rpx;margin:0 auto;border-radius:32rpx;background:#E7F9EF;color:#08A152;font-size:34rpx;font-weight:800}
.state-card.error .state-icon{background:#FFEEEA;color:#FF3B30}
.state-title{display:block;margin-top:24rpx;font-size:30rpx;font-weight:700}
.state-copy{display:block;margin:12rpx auto 0;max-width:560rpx;color:#767C85;font-size:24rpx;line-height:1.6}
.btn-main{height:80rpx;margin:32rpx auto 0;padding:0 48rpx;border-radius:999rpx;background:linear-gradient(135deg,#12D978,#08A152);color:#fff;font-size:28rpx;font-weight:700;line-height:80rpx}
.btn-main::after{border:0}
.card{margin:20rpx 24rpx 0;border-radius:32rpx;background:#fff}
.post-card{overflow:hidden;padding-bottom:24rpx}
.publisher{display:flex;align-items:center;gap:20rpx;padding:28rpx 30rpx 12rpx}
.avatar{display:flex;align-items:center;justify-content:center;width:88rpx;height:88rpx;flex:none;border-radius:50%;background:#E7F9EF;color:#08A152;font-size:34rpx;font-weight:800}
.pub-main{min-width:0;flex:1}
.pub-name{display:block;font-size:28rpx;font-weight:700}
.pub-sub{display:block;margin-top:6rpx;color:#AEB3BB;font-size:22rpx}
.btn-follow{height:56rpx;margin:0;padding:0 28rpx;border:1rpx solid #EDEFEF;border-radius:999rpx;background:#fff;color:#08A152;font-size:24rpx;font-weight:700;line-height:54rpx}
.btn-follow::after{border:0}
.post-body{padding:8rpx 30rpx 0}
.post-topic{display:inline-block;padding:8rpx 16rpx;border-radius:999rpx;background:#E7F9EF;color:#08A152;font-size:22rpx;font-weight:700}
.post-title{display:block;margin-top:16rpx;font-size:34rpx;font-weight:800;line-height:1.4}
.post-content{display:block;margin-top:12rpx;color:#26282D;font-size:28rpx;line-height:1.75;white-space:pre-wrap}
.post-imgs{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10rpx;margin:12rpx 30rpx 0}
.post-imgs.count-1{grid-template-columns:1fr}
.post-imgs image{width:100%;height:300rpx;border-radius:20rpx;background:#F2F4F3}
.post-imgs.count-1 image{height:420rpx}
.metrics{display:flex;align-items:center;gap:48rpx;margin:20rpx 14rpx 0;padding:20rpx 16rpx 0;border-top:1rpx solid #F5F6F6;color:#767C85;font-size:24rpx}
.metric-like{color:#767C85}
.metric-like.liked{color:#FF3B30;font-weight:700}
.comments-card{padding-bottom:8rpx}
.sec-head{display:flex;align-items:center;justify-content:space-between;padding:26rpx 30rpx 18rpx;font-size:30rpx;font-weight:700}
.sec-count{color:#AEB3BB;font-size:24rpx;font-weight:400}
.cstate{padding:44rpx 30rpx;color:#AEB3BB;font-size:24rpx;text-align:center}
.cstate.error{color:#FF3B30}
.cstate-retry{display:inline-block;margin-left:16rpx;color:#08A152;font-weight:700}
.cmt{display:flex;gap:20rpx;padding:26rpx 30rpx;border-top:1rpx solid #F5F6F6}
.cmt-reply-row{margin-left:60rpx;padding-left:24rpx;border-left:4rpx solid #E7F9EF}
.cmt-av{display:flex;align-items:center;justify-content:center;width:72rpx;height:72rpx;flex:none;border-radius:50%;background:#E7F9EF;color:#08A152;font-size:26rpx;font-weight:800}
.cmt-main{min-width:0;flex:1}
.cmt-nm{display:flex;align-items:center;justify-content:space-between;gap:20rpx;font-size:24rpx;color:#767C85}
.cmt-time{color:#AEB3BB;font-size:22rpx}
.cmt-reply-target{color:#08A152;font-size:22rpx;font-weight:700}
.cmt-txt{display:block;margin-top:8rpx;color:#26282D;font-size:28rpx;line-height:1.6;white-space:pre-wrap}
.cmt-actions{display:flex;gap:28rpx;margin-top:10rpx;color:#AEB3BB;font-size:22rpx}
.cmt-bar{position:fixed;z-index:50;right:0;bottom:0;left:0;display:flex;align-items:center;gap:24rpx;height:120rpx;padding:0 28rpx calc(0rpx + env(safe-area-inset-bottom));border-top:1rpx solid #EDEFEF;background:#fff;box-sizing:border-box}
.cmt-input{overflow:hidden;flex:1;height:72rpx;padding:0 28rpx;border-radius:999rpx;background:#F2F4F3;color:#AEB3BB;font-size:26rpx;line-height:72rpx;white-space:nowrap}
.cmt-ic{display:flex;flex-direction:column;align-items:center;gap:2rpx;color:#767C85;font-size:20rpx}
.cmt-ic-ico{font-size:40rpx;line-height:1}
.cmt-ic.hot{color:#FF3B30}
.share-mask,.poster-mask{position:fixed;z-index:80;inset:0;display:flex;align-items:flex-end;justify-content:center;padding:24rpx;box-sizing:border-box;background:rgba(23,26,23,.45)}
.share-sheet,.poster-sheet{width:100%;max-width:720rpx;padding:30rpx 28rpx calc(28rpx + env(safe-area-inset-bottom));box-sizing:border-box;border-radius:36rpx;background:#fff;box-shadow:0 30rpx 80rpx rgba(23,26,23,.25)}
.share-sheet-head,.poster-head{display:flex;align-items:center;justify-content:space-between}
.share-kicker,.poster-kicker,.share-title,.poster-title{display:block}
.share-kicker,.poster-kicker{color:#08A152;font-size:22rpx;font-weight:800;letter-spacing:2rpx}
.share-title,.poster-title{margin-top:6rpx;color:#191B1F;font-size:34rpx;font-weight:800}
.share-close,.poster-close{width:62rpx;height:62rpx;margin:0;padding:0;border:0;border-radius:50%;background:#F2F4F3;color:#767C85;font-size:34rpx;line-height:58rpx}
.share-close::after,.poster-close::after{border:0}
.share-options{display:grid;grid-template-columns:repeat(4,1fr);gap:14rpx;margin-top:30rpx}
.share-option{display:flex;flex-direction:column;align-items:center;gap:12rpx;margin:0;padding:0;border:0;background:transparent;color:#3A3D43;font-size:24rpx;line-height:1.25}
.share-option::after{border:0}
.share-option-icon{display:flex;align-items:center;justify-content:center;width:84rpx;height:84rpx;border-radius:26rpx;color:#fff;font-size:28rpx;font-weight:800}
.share-option-icon.friend{background:linear-gradient(135deg,#12D978,#08A152)}
.share-option-icon.timeline{background:linear-gradient(135deg,#5AC8FA,#3B82F6)}
.share-option-icon.poster{background:linear-gradient(135deg,#FF9A3D,#F2620A)}
.share-option-icon.link{background:linear-gradient(135deg,#AEB3BB,#767C85)}
.share-option-name{font-weight:600}
.share-tip,.poster-tip{display:block;margin-top:28rpx;padding:20rpx 22rpx;border-radius:20rpx;background:#F7F9F8;color:#767C85;font-size:24rpx;line-height:1.5}
.poster-mask{z-index:90;align-items:center;padding:28rpx}
.poster-sheet{padding-bottom:28rpx}
.poster-preview{position:relative;margin:24rpx auto 0;overflow:hidden;border-radius:24rpx;background:#F7F9F8}
.poster-canvas{display:block}
.poster-loading{position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:18rpx;background:rgba(244,246,244,.94);color:#767C85;font-size:26rpx}
.poster-loading-dot{width:42rpx;height:42rpx;border:6rpx solid #E7F9EF;border-top-color:#0BBF63;border-radius:50%;animation:poster-spin .8s linear infinite}
.poster-actions{display:grid;grid-template-columns:1fr 1.35fr;gap:16rpx;margin-top:20rpx}
.poster-copy,.poster-save{height:80rpx;margin:0;border:0;border-radius:999rpx;font-size:26rpx;font-weight:700;line-height:80rpx}
.poster-copy::after,.poster-save::after{border:0}
.poster-copy{background:#F2F4F3;color:#767C85}
.poster-save{background:linear-gradient(135deg,#12D978,#08A152);color:#fff}
.poster-save[disabled]{background:#EDEFEF;color:#AEB3BB}
@keyframes poster-spin{to{transform:rotate(360deg)}}
</style>
