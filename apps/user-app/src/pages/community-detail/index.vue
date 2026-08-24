<template>
  <view class="page tm-page-shell">
    <view class="community-topbar">
      <button class="community-back" aria-label="返回社区" @click="goCommunity">‹</button>
      <view class="community-topbar-title"><text>动态详情</text><small>CAMPUS COMMUNITY</small></view>
      <button class="community-topbar-share" aria-label="分享动态" @click="openSharePanel">分享</button>
    </view>
    <view v-if="loading" class="loading-shell">
      <view class="loading-hero tm-panel tm-skeleton" />
      <view class="loading-line tm-skeleton" />
      <view class="loading-line short tm-skeleton" />
      <view class="loading-comments tm-panel tm-skeleton" />
    </view>
    <view v-else-if="invalidId" class="state-card tm-panel">
      <view class="state-icon">链</view>
      <text class="state-title">动态链接无效</text>
      <text class="state-copy">请从社区列表重新打开一条动态。</text>
      <button class="state-action" @click="goCommunity">返回社区</button>
    </view>
    <view v-else-if="loadError" class="state-card tm-panel error-card">
      <view class="state-icon">!</view>
      <text class="state-title">动态加载失败</text>
      <text class="state-copy">{{ loadError }}</text>
      <button class="state-action" @click="loadPost">重新加载</button>
    </view>
    <view v-else-if="notFound" class="state-card tm-panel">
      <view class="state-icon">空</view>
      <text class="state-title">这条动态不存在或已下线</text>
      <text class="state-copy">你可以回到社区，看看其他校园分享。</text>
      <button class="state-action" @click="goCommunity">返回社区</button>
    </view>
    <template v-else-if="post">
      <view class="post-card tm-panel">
        <view class="author-row"><view class="avatar">{{ avatarText(post.author.nickname) }}</view><view class="author-main"><text>{{ post.author.nickname }}</text><text>校园伙伴 · {{ formatTime(post.published_at || post.created_at) }}</text></view><view class="author-actions"><button class="share-inline" @click="openSharePanel">分享</button><button class="more" @click="report('post',post.id)">•••</button></view></view>
        <view class="category-chip"># {{ post.category_label }}</view>
        <text class="title">{{ post.title }}</text>
        <text class="content">{{ post.content }}</text>
        <view v-if="images.length" :class="['media-grid',`count-${Math.min(images.length,3)}`]"><image v-for="(item,index) in images" :key="item.file_id" :src="communityAttachmentUrl(item)" mode="aspectFill" @click="previewImages(index)" /></view>
        <view class="metrics"><text>{{ post.comment_count }} 条评论</text><text :class="{liked:post.liked_by_me}" @click="toggleLike">{{ post.liked_by_me ? '♥ 已赞' : '♡ 点赞' }} {{ post.like_count }}</text></view>
      </view>
      <view class="comment-section tm-panel">
        <view class="section-head"><text>全部评论</text><text>{{ comments.length }}</text></view>
        <view v-if="commentsLoading" class="state">评论加载中…</view><view v-else-if="commentsError" class="state error"><text>{{ commentsError }}</text><text @click="retryComments">重新加载</text></view><view v-else-if="!comments.length" class="state">还没有评论，来坐第一排吧。</view>
        <view v-for="item in comments" v-else :key="item.id" class="comment-row"><view class="comment-avatar">{{ avatarText(item.author.nickname) }}</view><view class="comment-main"><view class="comment-head"><text>{{ item.author.nickname }}</text><text>{{ formatTime(item.created_at) }}</text></view><text class="comment-content">{{ item.content }}</text><view class="comment-actions"><text @click="writeComment(item)">回复</text><text v-if="auth.user?.id!==item.author.id" @click="report('comment',item.id)">举报</text></view></view></view>
      </view>
      <view class="composer"><view @click="writeComment()">友善评论，说说你的想法…</view><button :class="{liked:post.liked_by_me}" @click="toggleLike">{{ post.liked_by_me ? '♥' : '♡' }}</button><button @click="openSharePanel">分享</button></view>
    </template>

    <view v-if="shareVisible" class="share-mask" @click.self="closeSharePanel">
      <view class="share-sheet">
        <view class="share-sheet-head"><view><text class="share-kicker">SHARE POST</text><text class="share-title">分享这条校园动态</text></view><button class="share-close" @click="closeSharePanel">×</button></view>
        <view class="share-options">
          <!-- #ifdef MP-WEIXIN -->
          <button class="share-option" open-type="share" @click="closeSharePanel"><text class="share-option-icon friend">友</text><text class="share-option-name">微信好友</text></button>
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
  </view>
</template>
<script setup lang="ts">
import { onLoad, onShareAppMessage, onShareTimeline } from '@dcloudio/uni-app'
import qrcode from 'qrcode-generator'
import { computed, nextTick, ref } from 'vue'
import { communityAttachmentUrl, createCommunityComment, getCommunityPost, likeCommunityPost, listCommunityComments, reportCommunityContent, unlikeCommunityPost, type CommunityComment, type CommunityPost } from '@/api/community'
import { ApiError } from '@/api/http'
import { useAuthStore } from '@/stores/auth'
import { navigateToLogin } from '@/utils/authRedirect'

const auth = useAuthStore()
const post = ref<CommunityPost | null>(null)
const comments = ref<CommunityComment[]>([])
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
const postId = ref('')
const images = computed(() => (post.value?.attachments || []).filter((item) => item.type === 'image'))
const posterCanvasStyle = computed(() => `width:${posterWidth.value}px;height:${posterHeight.value}px`)
const posterPreviewStyle = computed(() => `width:${posterWidth.value}px;height:${posterHeight.value}px`)
const sharePath = computed(() => `/pages/community-detail/index?id=${encodeURIComponent(post.value?.id || postId.value)}`)

onLoad((query) => {
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
function ensureAuth() {
  if (auth.isAuthenticated) return true
  uni.showModal({ title: '登录后参与社区', content: '登录后可以点赞、评论和举报内容。', confirmText: '去登录', success: (result) => { if (result.confirm) navigateToLogin() } })
  return false
}
async function toggleLike() {
  if (!post.value || !auth.accessToken || !ensureAuth()) return
  try {
    const result = post.value.liked_by_me ? await unlikeCommunityPost(auth.accessToken, post.value.id) : await likeCommunityPost(auth.accessToken, post.value.id)
    post.value.liked_by_me = result.liked_by_me
    post.value.like_count = result.like_count
  } catch (error) { toast(error) }
}
async function writeComment(parent?: CommunityComment) {
  if (!post.value || !auth.accessToken || !ensureAuth()) return
  const result = await uni.showModal({ title: parent ? `回复 ${parent.author.nickname}` : '发表评论', editable: true, placeholderText: '保持友善，分享真实有用的信息' })
  if (!result.confirm || !result.content?.trim()) return
  try {
    await createCommunityComment(auth.accessToken, post.value.id, result.content.trim(), parent?.id)
    uni.showToast({ title: '评论已提交', icon: 'success' })
    await loadComments(post.value.id)
    post.value.comment_count = comments.value.length
  } catch (error) { toast(error) }
}
async function report(type: 'post' | 'comment', id: string) {
  if (!auth.accessToken || !ensureAuth()) return
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

function openPoster() {
  if (!post.value) return
  closeSharePanel()
  const system = uni.getSystemInfoSync()
  posterWidth.value = Math.max(300, Math.min(360, system.windowWidth - 48))
  posterHeight.value = Math.round(posterWidth.value * 1.56)
  posterReady.value = false
  posterVisible.value = true
  nextTick(() => setTimeout(drawPoster, 100))
}
function closePoster() { posterVisible.value = false }
function roundedRect(ctx: UniApp.CanvasContext, x: number, y: number, width: number, height: number, radius: number) {
  const r = Math.min(radius, width / 2, height / 2)
  ctx.beginPath(); ctx.moveTo(x + r, y); ctx.lineTo(x + width - r, y); ctx.arcTo(x + width, y, x + width, y + r, r); ctx.lineTo(x + width, y + height - r); ctx.arcTo(x + width, y + height, x + width - r, y + height, r); ctx.lineTo(x + r, y + height); ctx.arcTo(x, y + height, x, y + height - r, r); ctx.lineTo(x, y + r); ctx.arcTo(x, y, x + r, y, r); ctx.closePath()
}
function posterLines(value: string, limit = 14) { const chars = Array.from(value); return [chars.slice(0, limit).join(''), chars.slice(limit, limit * 2).join('')].filter(Boolean) }
function drawPoster() {
  if (!post.value) return
  const width = posterWidth.value; const height = posterHeight.value; const scale = width / 330; const ctx = uni.createCanvasContext('communityPosterCanvas'); const px = (value: number) => value * scale
  const gradient = ctx.createLinearGradient(0, 0, width, height); gradient.addColorStop(0, '#0e799d'); gradient.addColorStop(.55, '#19c6d1'); gradient.addColorStop(1, '#76df63'); ctx.setFillStyle(gradient); ctx.fillRect(0, 0, width, height)
  ctx.setFillStyle('rgba(255,255,255,.14)'); ctx.beginPath(); ctx.arc(px(288), px(56), px(82), 0, Math.PI * 2); ctx.fill(); ctx.setFillStyle('#ffdb4d'); roundedRect(ctx, px(22), px(23), px(112), px(24), px(12)); ctx.fill()
  ctx.setFillStyle('#10213a'); ctx.setFontSize(px(11)); ctx.setTextAlign('center'); ctx.fillText('CAMPUS COMMUNITY', px(78), px(39)); ctx.setTextAlign('left'); ctx.setFillStyle('#fff'); ctx.setFontSize(px(15)); ctx.fillText('校园动态', px(22), px(75)); ctx.setFontSize(px(25)); posterLines(post.value.title).forEach((line, index) => ctx.fillText(line, px(22), px(116 + index * 34)))
  ctx.setFillStyle('rgba(255,255,255,.96)'); roundedRect(ctx, px(18), px(188), px(294), px(296), px(24)); ctx.fill(); ctx.setFillStyle('#60798d'); ctx.setFontSize(px(12)); ctx.fillText(`# ${post.value.category_label}`, px(38), px(220)); ctx.setFillStyle('#173c58'); ctx.setFontSize(px(16)); posterLines(post.value.content, 18).slice(0, 2).forEach((line, index) => ctx.fillText(line, px(38), px(252 + index * 25)))
  ctx.setFillStyle('#edf8fc'); roundedRect(ctx, px(36), px(315), px(258), px(48), px(14)); ctx.fill(); ctx.setFillStyle('#173c58'); ctx.setFontSize(px(12)); ctx.fillText('扫码查看动态详情', px(51), px(345))
  const qr = qrcode(0, 'M'); qr.addData(shareLink()); qr.make(); const qrSize = px(86); const qrX = px(38); const qrY = px(382); const cells = qr.getModuleCount(); const cell = qrSize / cells; ctx.setFillStyle('#fff'); ctx.fillRect(qrX - px(7), qrY - px(7), qrSize + px(14), qrSize + px(14)); ctx.setFillStyle('#11344f'); for (let row = 0; row < cells; row += 1) for (let col = 0; col < cells; col += 1) if (qr.isDark(row, col)) ctx.fillRect(qrX + col * cell, qrY + row * cell, Math.ceil(cell), Math.ceil(cell)); ctx.setFillStyle('#173c58'); ctx.setFontSize(px(15)); ctx.fillText('分享给同学，一起发现校园', px(150), px(414)); ctx.setFillStyle('#748895'); ctx.setFontSize(px(10)); ctx.fillText('任务商城 · 校园社区', px(150), px(438)); ctx.setFillStyle('#fff'); ctx.setFontSize(px(10)); ctx.fillText('任务商城 · 校园成长与奖励平台', px(22), height - px(17)); ctx.draw(false, () => { posterReady.value = true })
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
function formatTime(value: string | null) { if (!value) return ''; const date = new Date(value); return Number.isNaN(date.getTime()) ? '' : `${date.getMonth() + 1}月${date.getDate()}日 ${String(date.getHours()).padStart(2, '0')}:${String(date.getMinutes()).padStart(2, '0')}` }
function toast(error: unknown) { uni.showToast({ title: error instanceof Error ? error.message : '操作失败', icon: 'none' }) }
function goCommunity() { uni.reLaunch({ url: '/pages/community/index' }) }
</script>
<style scoped>
.page{min-height:100vh;padding-bottom:150rpx;background:linear-gradient(180deg,var(--tm-campus-cyan-soft),var(--tm-canvas) 430rpx)}
.loading-shell{display:flex;flex-direction:column;gap:18rpx}.loading-hero{height:420rpx}.loading-line{width:78%;height:28rpx;border-radius:10rpx}.loading-line.short{width:48%}.loading-comments{height:360rpx;margin-top:6rpx}
.state-card{margin-top:140rpx;padding:68rpx 28rpx;text-align:center}.state-icon{display:flex;align-items:center;justify-content:center;width:88rpx;height:88rpx;margin:0 auto;border-radius:28rpx;background:var(--tm-campus-yellow);color:var(--tm-ink-900);font-size:19px;font-weight:900}.state-title,.state-copy{display:block}.state-title{margin-top:24rpx;font-size:17px;font-weight:900}.state-copy{margin-top:10rpx;color:var(--tm-ink-500);font-size:14px;line-height:1.6}.state-action{width:250rpx;height:68rpx;margin:24rpx auto 0;border-radius:18rpx;background:var(--tm-brand-600);color:#fff;font-size:14px;font-weight:850;line-height:68rpx}.error-card .state-icon{background:var(--tm-campus-coral-soft);color:var(--tm-danger)}
.post-card{padding:26rpx}.author-row{display:flex;align-items:center;gap:14rpx}.avatar,.comment-avatar{display:flex;align-items:center;justify-content:center;flex:none;width:58rpx;height:58rpx;border-radius:50%;background:linear-gradient(145deg,var(--tm-campus-yellow),var(--tm-campus-coral));color:var(--tm-ink-900);font-weight:900}.author-main{min-width:0;flex:1}.author-main text{display:block}.author-main text:first-child{font-size:15px;font-weight:900}.author-main text:last-child{margin-top:5rpx;color:var(--tm-ink-500);font-size:13px}.author-actions{display:flex;align-items:center;gap:6rpx}.share-inline,.more{height:52rpx;margin:0;padding:0 14rpx;border:1rpx solid var(--tm-line);border-radius:14rpx;background:#fff;color:var(--tm-brand-700);font-size:13px;line-height:50rpx}.more{padding:0 8rpx;color:var(--tm-ink-500);font-size:15px}.category-chip{display:inline-block;margin-top:20rpx;padding:7rpx 13rpx;border-radius:999rpx;background:var(--tm-brand-100);color:var(--tm-brand-700);font-size:13px;font-weight:800}.title{display:block;margin-top:17rpx;font-size:19px;font-weight:900;line-height:1.4}.content{display:block;margin-top:10rpx;color:var(--tm-ink-700);font-size:14px;line-height:1.7;white-space:pre-wrap}.media-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8rpx;margin-top:18rpx}.media-grid.count-1{grid-template-columns:1fr}.media-grid.count-2{grid-template-columns:repeat(2,1fr)}.media-grid image{width:100%;height:190rpx;border-radius:16rpx;background:#edf2f5}.media-grid.count-1 image{height:340rpx}.metrics{display:flex;align-items:center;justify-content:space-between;margin-top:20rpx;padding-top:17rpx;border-top:1rpx solid var(--tm-line);color:var(--tm-ink-500);font-size:13px}.metrics .liked{color:var(--tm-danger);font-weight:850}.comment-section{margin-top:18rpx;padding:26rpx}.section-head{display:flex;align-items:center;justify-content:space-between;padding-bottom:17rpx;border-bottom:1rpx solid var(--tm-line);font-size:15px;font-weight:900}.section-head text:last-child{color:var(--tm-ink-500);font-size:13px}.state{padding:32rpx 8rpx;text-align:center;color:var(--tm-ink-500);font-size:13px}.state.error{color:#c84455}.state.error text:last-child{display:inline-block;margin-left:16rpx;color:var(--tm-brand-700);font-weight:850}.comment-row{display:flex;gap:12rpx;padding:20rpx 0;border-bottom:1rpx solid var(--tm-line)}.comment-avatar{width:44rpx;height:44rpx;font-size:12px}.comment-main{min-width:0;flex:1}.comment-head,.comment-actions{display:flex;align-items:center;justify-content:space-between;gap:12rpx}.comment-head text:first-child{font-size:13px;font-weight:850}.comment-head text:last-child{color:var(--tm-ink-500);font-size:12px}.comment-content{display:block;margin-top:7rpx;color:var(--tm-ink-700);font-size:14px;line-height:1.55;white-space:pre-wrap}.comment-actions{justify-content:flex-start;margin-top:9rpx;color:var(--tm-ink-500);font-size:12px}.composer{position:fixed;z-index:20;right:0;bottom:0;left:0;display:flex;align-items:center;gap:10rpx;padding:16rpx 28rpx calc(16rpx + env(safe-area-inset-bottom));border-top:1rpx solid var(--tm-line);background:rgba(255,255,255,.96);backdrop-filter:blur(14px)}.composer>view{overflow:hidden;flex:1;height:64rpx;padding:0 20rpx;border:1rpx solid var(--tm-line);border-radius:999rpx;background:#fff;color:var(--tm-ink-500);font-size:13px;line-height:64rpx;white-space:nowrap}.composer button{width:64rpx;height:64rpx;margin:0;padding:0;border:1rpx solid var(--tm-line);border-radius:50%;background:#fff;color:var(--tm-brand-700);font-size:14px;line-height:62rpx}.composer button:last-child{width:94rpx;border-radius:999rpx;background:var(--tm-brand-600);color:#fff;font-size:13px}.composer button.liked{color:var(--tm-danger)}
.share-mask,.poster-mask{position:fixed;z-index:80;inset:0;display:flex;align-items:flex-end;justify-content:center;padding:24rpx;box-sizing:border-box;background:rgba(9,35,57,.45);backdrop-filter:blur(8rpx)}.share-sheet,.poster-sheet{width:100%;max-width:720rpx;padding:30rpx 28rpx calc(28rpx + env(safe-area-inset-bottom));box-sizing:border-box;border-radius:34rpx;background:#fff;box-shadow:0 30rpx 80rpx rgba(13,42,64,.25)}.share-sheet-head,.poster-head{display:flex;align-items:center;justify-content:space-between}.share-kicker,.poster-kicker,.share-title,.poster-title{display:block}.share-kicker,.poster-kicker{color:#1576ca;font-size:13px;font-weight:850;letter-spacing:2rpx}.share-title,.poster-title{margin-top:5rpx;color:#14344c;font-size:19px;font-weight:850}.share-close,.poster-close{width:62rpx;height:62rpx;margin:0;padding:0;border:0;border-radius:50%;background:#f0f6f9;color:#557183;font-size:21px;line-height:58rpx}.share-options{display:grid;grid-template-columns:repeat(4,1fr);gap:14rpx;margin-top:30rpx}.share-option{display:flex;flex-direction:column;align-items:center;gap:12rpx;margin:0;padding:0;border:0;background:transparent;color:#36536a;font-size:14px;line-height:1.25}.share-option-icon{display:flex;align-items:center;justify-content:center;width:82rpx;height:82rpx;border-radius:26rpx;color:#fff;font-size:15px;font-weight:850;box-shadow:0 10rpx 22rpx rgba(24,88,130,.15)}.share-option-icon.friend{background:linear-gradient(145deg,#14b769,#36d39b)}.share-option-icon.timeline{background:linear-gradient(145deg,#1a77dc,#25b8d1)}.share-option-icon.poster{background:linear-gradient(145deg,#f3a229,#ffcb4c)}.share-option-icon.link{background:linear-gradient(145deg,#7658d8,#a284f4)}.share-option-name{font-weight:750}.share-tip,.poster-tip{display:block;margin-top:28rpx;padding:18rpx 20rpx;border-radius:18rpx;background:#f3f9fc;color:#6a8191;font-size:13px;line-height:1.5}.poster-mask{z-index:90;align-items:center;padding:28rpx}.poster-sheet{padding-bottom:28rpx}.poster-preview{position:relative;margin:24rpx auto 0;overflow:hidden;border-radius:24rpx;background:linear-gradient(145deg,#e6f4fa,#f6fbfd);box-shadow:0 14rpx 36rpx rgba(26,75,103,.14)}.poster-canvas{display:block}.poster-loading{position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:18rpx;background:rgba(242,249,252,.94);color:#5c788b;font-size:14px}.poster-loading-dot{width:42rpx;height:42rpx;border:6rpx solid #cfe6ee;border-top-color:#1598c8;border-radius:50%;animation:poster-spin .8s linear infinite}.poster-actions{display:grid;grid-template-columns:1fr 1.35fr;gap:16rpx;margin-top:20rpx}.poster-copy,.poster-save{height:76rpx;margin:0;border:0;border-radius:20rpx;font-size:14px;font-weight:800;line-height:76rpx}.poster-copy{background:#edf6fa;color:#286784}.poster-save{background:linear-gradient(135deg,#1268dc,#1bb5c7);color:#fff}.poster-save[disabled]{background:#cbd9df;color:#fff}@keyframes poster-spin{to{transform:rotate(360deg)}}
@media screen and (max-width:380px){.share-options{gap:6rpx}.share-option-icon{width:72rpx;height:72rpx;border-radius:22rpx}.share-option-name{font-size:13px}}
.page{background:radial-gradient(circle at 94% 0,rgba(55,205,212,.18),transparent 27%),linear-gradient(180deg,#eefaff 0,#f7fbfd 44%,#f4f8fa 100%)!important}.post-card,.comment-section,.state-card{border:1rpx solid #dce9ef!important;box-shadow:0 12rpx 30rpx rgba(34,78,103,.075)!important;background:rgba(255,255,255,.97)!important}.avatar,.comment-avatar{border:0!important;background:linear-gradient(145deg,#dff5ff,#e7faef)!important;color:#1475c5!important}.category-chip{border:0;background:#e8f8fb;color:#14758a}.share-inline,.more{border:1rpx solid #cfe1e9!important;background:#fff;color:#1475c5}.metrics,.section-head{border-color:#e1edf1!important}.composer{border-top:1rpx solid #dce9ef!important;background:rgba(255,255,255,.95)!important;box-shadow:0 -10rpx 28rpx rgba(31,76,101,.08)}.composer>view,.composer button{border:1rpx solid #cfe1e9!important;background:#fff!important;box-shadow:none!important}.composer button:last-child{border:0!important;background:linear-gradient(135deg,#1268c9,#19a7c8)!important}.state-card{margin-top:120rpx}.state-icon{border:0;background:#e8f7fb;color:#1477c7}.error-card .state-icon{background:#fff0ed;color:#c53c31}.state-action{border:1rpx solid #b9dbe5!important;border-radius:999rpx!important;background:#fff!important;color:#1475c5!important;box-shadow:none!important}.share-mask,.poster-mask{background:rgba(9,35,57,.46)!important}.share-sheet,.poster-sheet{border:1rpx solid #dce9ef;background:#fff!important;box-shadow:0 24rpx 60rpx rgba(13,67,91,.2)}.poster-save{border:0!important;background:linear-gradient(135deg,#08a967,#0abca1)!important;box-shadow:0 8rpx 18rpx rgba(8,169,103,.16)!important}.poster-copy{border:1rpx solid #cfe1e9!important;background:#f5fafc!important;color:#28708a!important}
.community-topbar{display:flex;align-items:center;justify-content:space-between;gap:14rpx;margin:-18rpx -28rpx 18rpx;padding:14rpx 22rpx 12rpx;border-bottom:1rpx solid #dce9ef;background:rgba(255,255,255,.96);box-sizing:border-box}.community-back,.community-topbar-share{display:flex;align-items:center;justify-content:center;flex:none;margin:0;border:1rpx solid #cfe1e9;border-radius:999rpx;background:#fff;color:#1475c5;box-shadow:none}.community-back{width:62rpx;height:58rpx;font-size:24px;line-height:50rpx}.community-topbar-share{height:54rpx;padding:0 18rpx;font-size:13px;font-weight:850;line-height:52rpx}.community-back::after,.community-topbar-share::after{border:0}.community-topbar-title{min-width:0;flex:1;text-align:center}.community-topbar-title text,.community-topbar-title small{display:block}.community-topbar-title text{color:#14344c;font-size:17px;font-weight:900}.community-topbar-title small{margin-top:2rpx;color:#7892a1;font-size:10px;letter-spacing:1.8rpx}
</style>
