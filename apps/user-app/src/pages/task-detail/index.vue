<template>
  <view class="page">
    <ModulePlaceholder v-if="modules.resolved && !moduleEnabled" code="module_tasks" />
    <view v-else-if="!modules.resolved" class="mp-skeleton">
      <view class="tm-skeleton" style="height: 200rpx; border-radius: 24rpx; margin: 24rpx 28rpx;" />
      <view class="tm-skeleton" style="height: 320rpx; border-radius: 24rpx; margin: 20rpx 28rpx;" />
    </view>
    <template v-else>
    <view v-if="loading" class="detail-loading"><view class="detail-skeleton detail-skeleton-hero" /><view class="detail-skeleton detail-skeleton-card" /><view class="detail-skeleton detail-skeleton-card short" /></view>
    <view v-else-if="loadError" class="state-error"><text class="state-error-icon">!</text><text class="state-error-title">任务详情加载失败</text><text class="state-error-copy">{{ loadError }}</text><button class="state-error-action" @click="loadTask">重试</button></view>
    <view v-else-if="!task" class="state-text">任务不存在或已经下线</view>
    <template v-else>
      <view class="detail-hero">
        <image class="detail-hero-img" :src="heroImageSrc" mode="aspectFill" @error="onHeroImageError" />
        <view class="hero-nav">
          <view class="hero-nav-btn" @click="goBack">‹</view>
          <view class="hero-nav-btn" @click="openSharePanel">↗</view>
        </view>
      </view>

      <view class="detail-title-card">
        <text class="detail-h1">{{ task.title }}</text>
        <view class="tags">
          <text :class="['tag', task.is_platform_wide ? 'tag-platform' : 'tag-campus']">{{ task.is_platform_wide ? '平台·全校' : '本校' }}</text>
          <text class="tag tag-red">悬赏</text>
          <text class="tag tag-orange">{{ categoryLabel(task.category) }}</text>
          <text class="tag">{{ taskTypeLabel(task.task_type) }}</text>
        </view>
        <view class="price-row">
          <text class="big">{{ primaryRewardText(task) }}</text>
          <text class="sp" />
          <text class="people">{{ task.view_count }} 人浏览 · 剩余 {{ task.remaining_reward_slots }} 份</text>
        </view>
      </view>

      <view class="card">
        <view class="info-row"><text class="info-ic">⏱</text><text>限时完成</text><text class="sp" /><text class="v">领取后 {{ task.completion_window_hours }} 小时</text></view>
        <view class="info-row"><text class="info-ic">📅</text><text>领取截止</text><text class="sp" /><text class="v">{{ formatTime(task.claim_ends_at) }}</text></view>
        <view class="info-row"><text class="info-ic">✓</text><text>通过率</text><text class="sp" /><text class="v">{{ passRateText(task.pass_rate_percent) }} · 已完成 {{ task.completed_user_count }} 单</text></view>
        <view class="info-row"><text class="info-ic">☎</text><text>联系方式</text><text class="sp" /><text class="v">接单后按步骤指引联系</text></view>
      </view>

      <view v-if="task.steps.length" class="card steps-card">
        <view class="sec-title"><text class="sec-h2">任务步骤 / 流程</text><text v-if="evidenceSummary.requiredCount" class="sec-more">{{ evidenceSummary.requiredCount }} 项必做</text></view>
        <view v-for="(step, index) in task.steps" :key="step.id" class="step-row">
          <view class="step-index">{{ index + 1 }}</view>
          <view class="step-main">
            <view class="step-title-row"><text class="step-title">{{ step.title }}</text><text v-if="step.required" class="required-badge">必做</text></view>
            <text v-if="step.description" class="step-description">{{ step.description }}</text>
            <view v-if="step.example_image_url" class="step-guide-link" @click="previewStepImage(step.example_image_url)">查看示例图</view>
            <view v-if="step.link_url || step.copy_text" class="step-actions"><button v-if="step.link_url" class="step-action" @click="openStepLink(step.link_url)">{{ step.button_text || '打开链接' }}</button><button class="step-action secondary" @click="copyStepValue(step.copy_text || step.link_url || '')">复制{{ step.copy_text ? '内容' : '链接' }}</button></view>
            <text class="step-input-tip">{{ stepInputText(step.input_type) }}</text>
          </view>
        </view>
      </view>

      <view class="card">
        <view class="sec-title"><text class="sec-h2">任务说明</text><text class="sec-more">{{ task.view_count }} 次浏览</text></view>
        <rich-text class="desc rich-content" :nodes="taskDescriptionContent(task.description)" />
        <view v-if="task.tags.length" class="tag-row"><text v-for="tag in task.tags" :key="tag" class="tag tag-green">{{ tag }}</text></view>
      </view>

      <view class="card">
        <view class="sec-title"><text class="sec-h2">领取与审核规则</text></view>
        <view class="info-row"><text>领取截止</text><text class="sp" /><text class="v">{{ formatTime(task.claim_ends_at) }}</text></view>
        <view class="info-row"><text>完成时限</text><text class="sp" /><text class="v">领取后 {{ task.completion_window_hours }} 小时</text></view>
        <view class="info-row"><text>提交机会</text><text class="sp" /><text class="v">最多 {{ task.submission_limit }} 次</text></view>
        <view class="info-row"><text>审核期限</text><text class="sp" /><text class="v">提交后 {{ task.review_deadline_hours }} 小时</text></view>
        <view class="info-row"><text>申诉期限</text><text class="sp" /><text class="v">审核结果后 {{ task.appeal_deadline_days }} 天</text></view>
      </view>

      <view v-if="task.rules.length" class="card">
        <view class="sec-title"><text class="sec-h2">补充要求</text></view>
        <view class="rules-list"><text v-for="(rule, index) in task.rules" :key="index" class="rule-copy">{{ ruleText(rule, index) }}</text></view>
      </view>

      <view class="card">
        <view class="publisher">
          <view class="av">{{ (task.publisher.display_name || '校').charAt(0) }}</view>
          <view class="publisher-main">
            <view class="nm"><text>{{ task.publisher.display_name }}</text><text class="tag tag-orange">{{ task.publisher.publisher_badge }}</text></view>
            <text class="sub">已完成 {{ task.completed_user_count }} 单 · 通过率 {{ passRateText(task.pass_rate_percent) }}</text>
          </view>
          <text class="sp" />
        </view>
      </view>

      <text class="report-link" @click="reportCurrentTask">发现任务有问题？举报此任务</text>
      <view class="bottom-space" />

      <view class="action-bar">
        <view class="ab-ic" @click="openSharePanel"><text class="ab-ic-glyph">↗</text><text>分享</text></view>
        <view class="ab-ic" @click="reportCurrentTask"><text class="ab-ic-glyph">!</text><text>举报</text></view>
        <button class="ab-main" :disabled="claiming || (!task.can_claim && auth.isAuthenticated)" :loading="claiming" @click="claimCurrentTask">{{ !auth.isAuthenticated ? '登录后接单' : task.can_claim ? `我要接单 · ${primaryRewardText(task)}` : reasonText(task.claim_unavailable_reason) }}</button>
      </view>

      <template v-if="claimConfirmVisible">
        <view class="sheet-mask" @click.self="closeClaimConfirm" />
        <view class="sheet">
          <view class="sheet-grip" />
          <text class="sheet-h3">确认接单</text>
          <view class="task-sum">
            <view class="task-sum-icon">任</view>
            <view class="task-sum-main">
              <text class="task-sum-title">{{ task.title }}</text>
              <text class="task-sum-sub">领取后 {{ task.completion_window_hours }} 小时内完成 · 剩余 {{ task.remaining_reward_slots }} 份</text>
            </view>
            <text class="pts">{{ primaryRewardText(task) }}</text>
          </view>
          <view class="notice-list">
            <view class="notice-li"><text class="notice-check">✓</text>接单后请尽快按步骤完成，系统会立即预留全部奖励</view>
            <view class="notice-li"><text class="notice-check">✓</text>完成后按步骤提交材料，审核通过即可到账 {{ primaryRewardText(task) }}</view>
            <view class="notice-li"><text class="notice-check">✓</text>超时未提交将释放奖励名额，请合理安排时间</view>
          </view>
          <view class="sheet-btns">
            <button class="cancel" @click="closeClaimConfirm">我再看看</button>
            <button class="ok" :loading="claiming" @click="confirmClaim">确认接单</button>
          </view>
        </view>
      </template>

      <template v-if="shareVisible">
        <view class="sheet-mask" @click.self="closeSharePanel" />
        <view class="sheet">
          <view class="sheet-grip" />
          <text class="sheet-h3">分享这个校园任务</text>
          <view class="share-options">
            <!-- #ifdef MP-WEIXIN -->
            <button class="share-option" open-type="share"><text class="share-option-icon friend">友</text><text class="share-option-name">微信好友</text></button>
            <!-- #endif -->
            <!-- #ifndef MP-WEIXIN -->
            <button class="share-option" @click="shareToFriend"><text class="share-option-icon friend">友</text><text class="share-option-name">分享好友</text></button>
            <!-- #endif -->
            <button class="share-option" @click="shareToTimeline"><text class="share-option-icon timeline">圈</text><text class="share-option-name">朋友圈</text></button>
            <button class="share-option" @click="openPoster"><text class="share-option-icon poster">报</text><text class="share-option-name">生成海报</text></button>
            <button class="share-option" @click="copyTaskLink"><text class="share-option-icon link">链</text><text class="share-option-name">复制链接</text></button>
          </view>
          <text class="share-tip">分享内容将带上任务标题、奖励和任务详情入口。</text>
        </view>
      </template>

      <view v-if="posterVisible" class="poster-mask" @click.self="closePoster">
        <view class="poster-sheet">
          <view class="poster-head"><view><text class="poster-kicker">TASK POSTER</text><text class="poster-title">任务分享海报</text></view><button class="poster-close" @click="closePoster">×</button></view>
          <view class="poster-preview" :style="posterPreviewStyle">
            <canvas id="taskPosterCanvas" canvas-id="taskPosterCanvas" class="poster-canvas" :style="posterCanvasStyle" :width="posterWidth" :height="posterHeight" />
            <view v-if="!posterReady" class="poster-loading"><view class="poster-loading-dot" /><text>正在生成任务海报</text></view>
          </view>
          <text class="poster-tip">保存后可发送给好友或分享到朋友圈，扫码即可查看任务详情。</text>
          <view class="poster-actions"><button class="poster-copy" @click="copyTaskLink">复制任务链接</button><button class="poster-save" :disabled="!posterReady" @click="savePoster">保存海报</button></view>
        </view>
      </view>
    </template>
    </template>
  </view>
</template>

<script setup lang="ts">
import { formatDateTime as formatTime } from '@/utils/dateTime'
import { onLoad, onShareAppMessage, onShareTimeline } from '@dcloudio/uni-app'
import { enableWechatShare } from '@/platform/wechatJssdk'
import qrcode from 'qrcode-generator'
import { computed, nextTick, ref } from 'vue'
import { ApiError } from '@/api/http'
import { claimTask, getHallTask, reportTask, type HallTask, type TaskReward } from '@/api/tasks'
import { useAuthStore } from '@/stores/auth'
import { navigateToLogin } from '@/utils/authRedirect'
import { richTextContent } from '@/utils/richText'
import ModulePlaceholder from '@/components/ModulePlaceholder.vue'
import { useModulesStore } from '@/stores/modules'

const modules = useModulesStore()
const moduleEnabled = computed(() => modules.isEnabled('module_tasks'))

const auth = useAuthStore()
const task = ref<HallTask | null>(null)
const loading = ref(true)
const loadError = ref('')
const claiming = ref(false)
const claimConfirmVisible = ref(false)
const shareVisible = ref(false)
const posterVisible = ref(false)
const posterReady = ref(false)
const posterWidth = ref(330)
const posterHeight = ref(520)
const heroImageFallback = ref(false)
const heroImageSrc = computed(() => {
  const configured = task.value?.cover_image_url?.trim()
  return !heroImageFallback.value && configured ? configured : '/static/tasks/hero-task-campus-v09-final.png'
})
const posterCanvasStyle = computed(() => `width:${posterWidth.value}px;height:${posterHeight.value}px`)
const posterPreviewStyle = computed(() => `width:${posterWidth.value}px;height:${posterHeight.value}px`)
const evidenceSummary = computed(() => {
  const required = (task.value?.steps ?? []).filter((step) => step.required)
  return {
    requiredCount: required.length,
    imageCount: required.filter((step) => ['image', 'text_image'].includes(step.input_type)).length,
    textCount: required.filter((step) => ['text', 'text_image'].includes(step.input_type)).length,
    confirmCount: required.filter((step) => ['none', 'link'].includes(step.input_type)).length,
  }
})
let taskId = ''

onLoad(async (options) => {
  await modules.ensureReady()
  if (!moduleEnabled.value) return
  taskId = typeof options?.id === 'string' ? options.id : ''
  if (!taskId) { loading.value = false; return }
  await loadTask()
  // 公众号内：配置 JSSDK 分享卡片；小程序端与浏览器端该调用会自动跳过。
  void enableWechatShare({ title: taskShareTitle(), link: taskShareLink(), imageUrl: heroImageSrc.value })
  // #ifdef MP-WEIXIN
  uni.showShareMenu({ withShareTicket: true, menus: ['shareAppMessage', 'shareTimeline'] })
  // #endif
})

onShareAppMessage(() => ({
  title: taskShareTitle(),
  path: taskSharePath(),
}))

onShareTimeline(() => ({
  title: taskShareTitle(),
  query: `id=${encodeURIComponent(task.value?.id || taskId)}`,
}))

async function loadTask() {
  loading.value = true
  loadError.value = ''
  heroImageFallback.value = false
  try { task.value = await getHallTask(taskId) }
  catch (error) { task.value = null; loadError.value = error instanceof Error ? error.message : '请检查网络后重试。' }
  finally { loading.value = false }
}

function onHeroImageError() {
  heroImageFallback.value = true
}

async function claimCurrentTask() {
  if (!task.value) return
  if (!auth.isAuthenticated) return navigateToLogin()
  claimConfirmVisible.value = true
}

function closeClaimConfirm() {
  if (claiming.value) return
  claimConfirmVisible.value = false
}

function goBack() {
  uni.navigateBack()
}

// 同一页面会话内复用同一幂等键：首次请求若已在服务端成功但响应丢失，重试会命中幂等返回原领取记录而不是报“本周期已领取”
const claimIdempotencyKey = `task-claim-${Date.now()}-${Math.random().toString(36).slice(2)}`

async function confirmClaim() {
  if (!task.value || !auth.accessToken || claiming.value) return
  claimConfirmVisible.value = false
  claiming.value = true
  try {
    await claimTask(auth.accessToken, task.value.id, claimIdempotencyKey)
  } catch (error) {
    claiming.value = false
    await handleClaimError(error)
    return
  }
  claiming.value = false
  // 领取已在服务端成功：后续结果弹窗/跳转异常绝不能再报“领取失败”
  try {
    const result = await uni.showModal({ title: '领取成功', content: '任务已加入“我的任务”，请在规定时间内提交完成材料。', confirmText: '查看我的任务', cancelText: '继续浏览' })
    if (result.confirm) {
      uni.setStorageSync('task_mall_open_my_tasks', true)
      uni.reLaunch({ url: '/pages/tasks/index' })
    } else {
      await loadTask()
    }
  } catch {
    uni.showToast({ title: '领取成功，已加入我的任务', icon: 'success' })
    await loadTask()
  }
}

async function handleClaimError(error: unknown) {
  if (error instanceof ApiError && error.code === 'LEGAL_DOCUMENT_CONSENT_REQUIRED') {
    const returnUrl = encodeURIComponent(`/pages/task-detail/index?id=${taskId}`)
    const choice = await uni.showModal({ title: '需要先确认协议', content: '领取任务前请先阅读并确认相关协议。', confirmText: '去确认', cancelText: '取消' }).catch(() => null)
    if (choice?.confirm) uni.navigateTo({ url: `/pages/legal-consent/index?scope=task&returnUrl=${returnUrl}` })
    return
  }
  uni.showToast({ title: claimErrorMessage(error), icon: 'none' })
  await loadTask()
}

function claimErrorMessage(error: unknown) {
  if (error instanceof ApiError && error.code === 'VALIDATION_FAILED') {
    // 422 时把后端真实原因（如“本周期已经领取过”）直接透传，而不是笼统的“请求参数校验失败”
    const errors = error.details?.errors
    if (errors && typeof errors === 'object') {
      for (const value of Object.values(errors)) {
        if (Array.isArray(value) && value.length) return String(value[0])
        if (typeof value === 'string' && value) return value
      }
    }
  }
  return error instanceof Error ? error.message : '领取失败'
}

async function reportCurrentTask() {
  if (!task.value) return
  if (!auth.isAuthenticated) return navigateToLogin()
  const result = await uni.showModal({ title: '举报任务', editable: true, placeholderText: '请描述风险或违规情况' })
  if (!result.confirm || !result.content?.trim()) return
  try { await reportTask(auth.accessToken, task.value.id, 'other', result.content.trim()); uni.showToast({ title: '举报已提交', icon: 'success' }) }
  catch (error) { uni.showToast({ title: error instanceof Error ? error.message : '举报失败', icon: 'none' }) }
}

function rewardText(reward: TaskReward) { if (reward.type === 'points') return `+${reward.points} 积分`; if (reward.type === 'wallet_balance') return `+¥${((reward.balance_amount || 0) / 100).toFixed(2)} 余额`; return '优惠券奖励' }
function primaryRewardText(value: HallTask) { return value.rewards[0] ? rewardText(value.rewards[0]).replace(' 余额', '') : '任务奖励' }
function categoryLabel(value: string) { return ({ onboarding: '新手', daily: '日常', community: '社区', commerce: '商城', research: '调研', campaign: '活动' } as Record<string, string>)[value] || '其他' }
function taskTypeLabel(value: string) { return ({ one_time: '单次任务', daily: '每日任务', weekly: '每周任务', repeatable: '可重复任务' } as Record<string, string>)[value] || '常规任务' }
function durationText(value: number | null) { if (value === null) return '暂无'; if (value < 60) return `${value} 分钟`; return `${(value / 60).toFixed(value % 60 === 0 ? 0 : 1)} 小时` }
function passRateText(value: number | null) { return value === null ? '暂无' : `${value}%` }
function reasonText(reason: string | null) { return ({ not_started: '尚未开始', ended: '已结束', quota_exhausted: '名额已满', ineligible: '系统自动加入' } as Record<string, string>)[reason || ''] || '暂不可领取' }
function ruleText(rule: string | Record<string, unknown>, index: number) { if (typeof rule === 'string') return `${index + 1}. ${rule}`; return `${index + 1}. ${String(rule.title || rule.description || JSON.stringify(rule))}` }
function stepInputText(value: string) { return ({ none: '按说明完成，无需单独填写', text: '需要填写文字结果', image: '需要上传截图凭证', text_image: '需要填写文字并上传截图', link: '需要访问或复制指定链接' } as Record<string, string>)[value] || '' }
function copyStepValue(value: string) { uni.setClipboardData({ data: value, success: () => uni.showToast({ title: '已复制', icon: 'success' }) }) }
function openStepLink(value: string) {
  // #ifdef H5
  window.open(value, '_blank', 'noopener,noreferrer')
  // #endif
  // #ifndef H5
  copyStepValue(value)
  // #endif
}
function previewStepImage(value: string) { uni.previewImage({ urls: [value], current: value }) }

function taskDescriptionContent(value?: string | null) {
  return richTextContent(value).replace(/<(p|div|section|blockquote)([^>]*)>/gi, (_match, tag: string, attrs: string) => {
    const cleanAttrs = attrs.replace(/\sstyle=(["']).*?\1/gi, '')
    return `<${tag}${cleanAttrs} style="margin:0;padding:0;border:0;border-radius:0;background:transparent;box-shadow:none">`
  })
}

function taskSharePath() { return `/pages/task-detail/index?id=${task.value?.id || taskId}` }
function taskShareTitle() { return task.value ? `${task.value.title}｜完成可得 ${primaryRewardText(task.value)}` : '校园任务分享' }
function taskShareLink() {
  const configured = String(import.meta.env.VITE_H5_PUBLIC_URL || '').replace(/\/$/, '')
  if (configured) return `${configured}/#${taskSharePath()}`
  // #ifdef H5
  return `${window.location.origin}${window.location.pathname}#${taskSharePath()}`
  // #endif
  // #ifndef H5
  return taskSharePath()
  // #endif
}

function openSharePanel() { shareVisible.value = true }
function closeSharePanel() { shareVisible.value = false }

async function shareToFriend() {
  closeSharePanel()
  // #ifdef H5
  if (typeof navigator.share === 'function') {
    try {
      await navigator.share({ title: taskShareTitle(), text: taskShareTitle(), url: taskShareLink() })
      return
    } catch (error) {
      if (isShareAbort(error)) return
    }
  }
  // #endif
  copyTaskLink()
}

async function shareToTimeline() {
  closeSharePanel()
  // #ifdef MP-WEIXIN
  await uni.showModal({ title: '分享到朋友圈', content: '请点击右上角“…”并选择“分享到朋友圈”。分享卡片将自动带上任务标题和入口。', showCancel: false, confirmText: '我知道了' })
  return void 0
  // #endif
  // #ifdef H5
  if (typeof navigator.share === 'function') {
    try {
      await navigator.share({ title: taskShareTitle(), text: `推荐一个校园任务：${taskShareTitle()}`, url: taskShareLink() })
      return
    } catch (error) {
      if (isShareAbort(error)) return
    }
  }
  // #endif
  uni.setClipboardData({
    data: `${taskShareTitle()}\n${taskShareLink()}`,
    success: () => uni.showModal({ title: '分享文案已复制', content: '当前环境不能直接唤起朋友圈，请粘贴文案和链接后发布；也可以生成海报分享。', showCancel: false, confirmText: '好的' }),
  })
}

function isShareAbort(error: unknown) { return Boolean(error && typeof error === 'object' && 'name' in error && error.name === 'AbortError') }

function copyTaskLink() {
  if (!task.value) return
  closeSharePanel()
  uni.setClipboardData({ data: taskShareLink(), success: () => uni.showToast({ title: '任务链接已复制', icon: 'success' }) })
}

function openPoster() {
  if (!task.value) return
  closeSharePanel()
  const system = uni.getSystemInfoSync()
  posterWidth.value = Math.max(300, Math.min(360, system.windowWidth - 48))
  posterHeight.value = Math.round(posterWidth.value * 1.56)
  posterReady.value = false
  posterVisible.value = true
  nextTick(() => setTimeout(drawTaskPoster, 100))
}

function closePoster() { posterVisible.value = false }

function roundedRect(ctx: UniApp.CanvasContext, x: number, y: number, width: number, height: number, radius: number) {
  const r = Math.min(radius, width / 2, height / 2)
  ctx.beginPath(); ctx.moveTo(x + r, y); ctx.lineTo(x + width - r, y); ctx.arcTo(x + width, y, x + width, y + r, r); ctx.lineTo(x + width, y + height - r); ctx.arcTo(x + width, y + height, x + width - r, y + height, r); ctx.lineTo(x + r, y + height); ctx.arcTo(x, y + height, x, y + height - r, r); ctx.lineTo(x, y + r); ctx.arcTo(x, y, x + r, y, r); ctx.closePath()
}

function splitPosterText(value: string, limit = 13) {
  const chars = Array.from(value)
  return [chars.slice(0, limit).join(''), chars.slice(limit, limit * 2).join('')].filter(Boolean)
}

function drawTaskPoster() {
  if (!task.value) return
  const width = posterWidth.value
  const height = posterHeight.value
  const scale = width / 330
  const ctx = uni.createCanvasContext('taskPosterCanvas')
  const px = (value: number) => value * scale
  const gradient = ctx.createLinearGradient(0, 0, width, height)
  gradient.addColorStop(0, '#1268dc'); gradient.addColorStop(0.56, '#159ad9'); gradient.addColorStop(1, '#28c7bd')
  ctx.setFillStyle(gradient); ctx.fillRect(0, 0, width, height)
  ctx.setFillStyle('rgba(255,255,255,.10)'); ctx.beginPath(); ctx.arc(px(292), px(48), px(82), 0, Math.PI * 2); ctx.fill()
  ctx.setFillStyle('#ffdc53'); roundedRect(ctx, px(22), px(23), px(92), px(24), px(12)); ctx.fill()
  ctx.setFillStyle('#073764'); ctx.setFontSize(px(11)); ctx.setTextAlign('center'); ctx.fillText('CAMPUS TASK', px(68), px(39)); ctx.setTextAlign('left')
  ctx.setFillStyle('#ffffff'); ctx.setFontSize(px(15)); ctx.fillText('值得完成的校园任务', px(22), px(75))
  ctx.setFontSize(px(26))
  splitPosterText(task.value.title).forEach((line, index) => ctx.fillText(line, px(22), px(116 + index * 35)))

  ctx.setFillStyle('rgba(255,255,255,.96)'); roundedRect(ctx, px(18), px(188), px(294), px(296), px(24)); ctx.fill()
  ctx.setFillStyle('#60798d'); ctx.setFontSize(px(12)); ctx.fillText(`${categoryLabel(task.value.category)} · ${taskTypeLabel(task.value.task_type)}`, px(38), px(220))
  ctx.setFillStyle('#f05f22'); ctx.setFontSize(px(30)); ctx.fillText(primaryRewardText(task.value), px(38), px(263))
  ctx.setFillStyle('#718699'); ctx.setFontSize(px(11)); ctx.fillText('完成奖励', px(39), px(282))

  ctx.setFillStyle('#edf8fc'); roundedRect(ctx, px(36), px(304), px(258), px(58), px(14)); ctx.fill()
  ctx.setFillStyle('#173c58'); ctx.setFontSize(px(12)); ctx.fillText(`剩余 ${task.value.remaining_reward_slots} 份`, px(51), px(329)); ctx.fillText(`限时 ${task.value.completion_window_hours} 小时`, px(177), px(329))
  ctx.setFillStyle('#6f8392'); ctx.setFontSize(px(10)); ctx.fillText('扫码进入任务详情，领取后按步骤完成', px(51), px(349))

  const qr = qrcode(0, 'M'); qr.addData(taskShareLink()); qr.make()
  const qrSize = px(84); const qrX = px(38); const qrY = px(382); const cells = qr.getModuleCount(); const cell = qrSize / cells
  ctx.setFillStyle('#ffffff'); ctx.fillRect(qrX - px(7), qrY - px(7), qrSize + px(14), qrSize + px(14))
  ctx.setFillStyle('#11344f')
  for (let row = 0; row < cells; row += 1) for (let col = 0; col < cells; col += 1) if (qr.isDark(row, col)) ctx.fillRect(qrX + col * cell, qrY + row * cell, Math.ceil(cell), Math.ceil(cell))
  ctx.setFillStyle('#173c58'); ctx.setFontSize(px(15)); ctx.fillText('扫码查看任务', px(151), px(414))
  ctx.setFillStyle('#748895'); ctx.setFontSize(px(10)); ctx.fillText('分享给同学，一起完成', px(151), px(438))
  ctx.setFillStyle('#ffffff'); ctx.setFontSize(px(10)); ctx.fillText('任务商城 · 校园成长与奖励平台', px(22), height - px(17))
  ctx.draw(false, () => { posterReady.value = true })
}

function savePoster() {
  if (!posterReady.value) return
  uni.canvasToTempFilePath({
    canvasId: 'taskPosterCanvas',
    width: posterWidth.value,
    height: posterHeight.value,
    destWidth: posterWidth.value * 3,
    destHeight: posterHeight.value * 3,
    success: (result) => {
      // #ifdef H5
      const anchor = document.createElement('a'); anchor.href = result.tempFilePath; anchor.download = `${task.value?.title || '校园任务'}-分享海报.png`; anchor.click(); uni.showToast({ title: '海报已下载', icon: 'success' })
      // #endif
      // #ifndef H5
      uni.saveImageToPhotosAlbum({ filePath: result.tempFilePath, success: () => uni.showToast({ title: '海报已保存', icon: 'success' }), fail: () => uni.showToast({ title: '请允许保存到相册', icon: 'none' }) })
      // #endif
    },
    fail: () => uni.showToast({ title: '海报生成失败，请重试', icon: 'none' }),
  })
}
</script>

<style scoped>
/* v13 任务详情 */
.page{min-height:100vh;padding:0 24rpx calc(140rpx + env(safe-area-inset-bottom));box-sizing:border-box;background:#F4F6F4}
.state-text{padding:180rpx 0;text-align:center;color:#767C85}
.detail-hero{position:relative;height:480rpx;margin:0 -24rpx;border-radius:0 0 32rpx 32rpx;overflow:hidden}
.detail-hero-img{width:100%;height:100%}
.hero-nav{position:absolute;top:calc(env(safe-area-inset-top) + 12rpx);left:0;right:0;display:flex;align-items:center;justify-content:space-between;padding:0 24rpx;z-index:2}
.hero-nav-btn{width:68rpx;height:68rpx;display:flex;align-items:center;justify-content:center;border-radius:50%;background:rgba(0,0,0,.35);color:#fff;font-size:32rpx;font-weight:300;line-height:1}
.detail-title-card{position:relative;z-index:2;margin:-32rpx 16rpx 0;padding:28rpx 26rpx;border-radius:32rpx;background:#fff;box-shadow:0 2rpx 4rpx rgba(23,26,23,.03),0 12rpx 40rpx rgba(23,26,23,.05)}
.detail-h1{display:block;font-size:36rpx;font-weight:800;color:#191B1F;line-height:1.45}
.tags{display:flex;flex-wrap:wrap;gap:8rpx;margin-top:18rpx}
.tag{display:inline-flex;align-items:center;height:36rpx;padding:0 12rpx;border-radius:10rpx;font-size:20rpx;font-weight:500;background:#F2F4F3;color:#767C85}
.tag-red{background:#FFEEEA;color:#FF3B30}
.tag-orange{background:#FFF2E5;color:#F2620A}
.tag-green{background:#E7F9EF;color:#08A152}
.tag-platform{background:linear-gradient(135deg,#4C8DFF,#2F6BDE);color:#fff}
.tag-campus{background:#E7F9EF;color:#08A152}
.price-row{display:flex;align-items:baseline;gap:16rpx;margin-top:18rpx}
.big{font-size:52rpx;font-weight:800;color:#FF3B30;line-height:1}
.people{font-size:22rpx;color:#AEB3BB}
.sp{flex:1}
.card{margin-top:20rpx;padding:12rpx 26rpx;border-radius:32rpx;background:#fff;box-shadow:0 2rpx 4rpx rgba(23,26,23,.03),0 12rpx 40rpx rgba(23,26,23,.05)}
.info-row{display:flex;align-items:center;gap:16rpx;padding:18rpx 0;font-size:26rpx;color:#3A3D43;border-bottom:2rpx solid #F5F6F6}
.info-row:last-child{border-bottom:0}
.info-ic{font-size:26rpx;color:#0BBF63;flex:none}
.info-row .v{font-size:24rpx;color:#767C85}
.sec-title{display:flex;align-items:center;justify-content:space-between;padding:22rpx 0 18rpx}
.sec-h2{font-size:30rpx;font-weight:700;color:#191B1F}
.sec-h2::before{content:'';display:inline-block;width:8rpx;height:28rpx;border-radius:4rpx;background:linear-gradient(135deg,#12D978,#08A152);margin-right:14rpx;vertical-align:middle}
.sec-more{font-size:22rpx;color:#AEB3BB}
.desc{padding:4rpx 0 18rpx;font-size:28rpx;line-height:1.75;color:#3A3D43}
.tag-row{display:flex;gap:10rpx;flex-wrap:wrap;margin-top:16rpx}
.step-row{display:flex;gap:28rpx;padding:22rpx 0;border-bottom:2rpx solid #F5F6F6}
.step-row:last-child{padding-bottom:0;border-bottom:0}
.step-index{display:flex;align-items:center;justify-content:center;width:48rpx;height:48rpx;flex:none;border-radius:16rpx;background:linear-gradient(135deg,#12D978,#08A152);color:#fff;font-size:24rpx;font-weight:700}
.step-main{min-width:0;flex:1}
.step-title-row{display:flex;align-items:center;gap:12rpx}
.step-title{font-size:28rpx;font-weight:700;color:#191B1F}
.required-badge{padding:4rpx 10rpx;border-radius:8rpx;background:#FFEEEA;color:#FF3B30;font-size:20rpx}
.step-description{display:block;margin-top:10rpx;color:#767C85;font-size:24rpx;line-height:1.55}
.step-input-tip{display:inline-block;margin-top:10rpx;padding:5rpx 12rpx;border-radius:999rpx;background:#E7F9EF;color:#08A152;font-size:22rpx}
.step-actions{display:flex;gap:16rpx;margin-top:14rpx}
.step-action{height:58rpx;margin:0;padding:0 24rpx;border:0;border-radius:16rpx;background:linear-gradient(135deg,#12D978,#08A152);color:#fff;font-size:24rpx;line-height:58rpx}
.step-action.secondary{border:2rpx solid #0BBF63;background:#fff;color:#08A152}
.step-action::after{border:0}
.rule-row{display:flex;align-items:center;justify-content:space-between;gap:24rpx;padding:18rpx 0;border-bottom:2rpx solid #F5F6F6}
.rule-row:last-child{padding-bottom:0;border-bottom:0}
.rule-label{color:#767C85;font-size:26rpx}
.rule-value{text-align:right;font-size:26rpx;font-weight:600;color:#3A3D43}
.rules-list{margin-top:10rpx;padding:12rpx 16rpx;border-radius:16rpx;background:#F7F9F8}
.rule-copy{display:block;margin-top:6rpx;color:#3A3D43;font-size:26rpx;line-height:1.5}
.rule-copy:first-child{margin-top:0}
.publisher{display:flex;align-items:center;gap:20rpx;padding:18rpx 0}
.av{width:84rpx;height:84rpx;border-radius:50%;background:linear-gradient(135deg,#12D978,#08A152);display:flex;align-items:center;justify-content:center;color:#fff;font-size:34rpx;font-weight:700;flex:none}
.publisher-main{min-width:0;flex:1}
.nm{display:flex;align-items:center;gap:10rpx;font-size:28rpx;font-weight:700;color:#191B1F}
.sub{font-size:22rpx;color:#AEB3BB;margin-top:4rpx}
.report-link{display:block;padding:28rpx 0;text-align:center;color:#767C85;font-size:26rpx}
.bottom-space{height:20rpx}
.action-bar{position:fixed;left:0;right:0;bottom:0;display:flex;align-items:center;padding:16rpx 24rpx calc(16rpx + env(safe-area-inset-bottom));gap:12rpx;background:#fff;border-top:2rpx solid #EDEFEF;z-index:50}
.ab-ic{width:96rpx;display:flex;flex-direction:column;align-items:center;gap:4rpx;font-size:20rpx;color:#767C85}
.ab-ic-glyph{font-size:32rpx;line-height:1}
.ab-main{flex:1;height:84rpx;border-radius:42rpx;background:linear-gradient(135deg,#12D978,#08A152);color:#fff;font-size:30rpx;font-weight:700;display:flex;align-items:center;justify-content:center;box-shadow:0 12rpx 28rpx rgba(11,191,99,.3);margin:0;border:0;line-height:84rpx}
.ab-main::after{border:0}
.ab-main[disabled]{box-shadow:none;opacity:.5}
.sheet-mask{position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:80}
.sheet{position:fixed;left:0;right:0;bottom:0;z-index:90;padding:12rpx 32rpx calc(24rpx + env(safe-area-inset-bottom));background:#fff;border-radius:40rpx 40rpx 0 0}
.sheet-grip{width:72rpx;height:8rpx;border-radius:4rpx;background:#E1E4E2;margin:16rpx auto 28rpx}
.sheet-h3{display:block;font-size:34rpx;font-weight:800;text-align:center;color:#191B1F}
.task-sum{display:flex;gap:22rpx;background:#F7F9F8;border-radius:24rpx;padding:22rpx;margin-top:28rpx}
.task-sum-icon{width:116rpx;height:116rpx;border-radius:24rpx;background:linear-gradient(135deg,#12D978,#08A152);display:flex;align-items:center;justify-content:center;color:#fff;font-size:40rpx;font-weight:700;flex:none}
.task-sum-main{min-width:0;flex:1}
.task-sum-title{display:block;font-size:26rpx;font-weight:700;color:#191B1F;line-height:1.4}
.task-sum-sub{display:block;font-size:22rpx;color:#767C85;margin-top:8rpx}
.pts{margin-left:auto;font-size:36rpx;font-weight:800;color:#FF3B30;white-space:nowrap;flex:none}
.notice-list{margin-top:24rpx}
.notice-li{display:flex;gap:16rpx;font-size:24rpx;color:#767C85;line-height:1.6;padding:6rpx 0}
.notice-check{color:#0BBF63;flex:none;margin-top:2rpx}
.sheet-btns{display:flex;gap:20rpx;margin-top:28rpx}
.sheet-btns button{flex:1;height:88rpx;border-radius:44rpx;font-size:30rpx;font-weight:700;margin:0;border:0;line-height:88rpx}
.sheet-btns .cancel{background:#F2F4F3;color:#767C85}
.sheet-btns .ok{background:linear-gradient(135deg,#12D978,#08A152);color:#fff;box-shadow:0 12rpx 28rpx rgba(11,191,99,.3)}
.sheet-btns button::after{border:0}
.share-options{display:grid;grid-template-columns:repeat(4,1fr);gap:16rpx;margin-top:32rpx}
.share-option{display:flex;flex-direction:column;align-items:center;gap:12rpx;margin:0;padding:0;border:0;background:transparent;color:#3A3D43;font-size:24rpx;line-height:1.25}
.share-option-icon{display:flex;align-items:center;justify-content:center;width:96rpx;height:96rpx;border-radius:32rpx;color:#fff;font-size:28rpx;font-weight:700;box-shadow:0 10rpx 22rpx rgba(24,88,130,.15)}
.share-option-icon.friend{background:linear-gradient(145deg,#14b769,#36d39b)}
.share-option-icon.timeline{background:linear-gradient(145deg,#1a77dc,#25b8d1)}
.share-option-icon.poster{background:linear-gradient(145deg,#f3a229,#ffcb4c)}
.share-option-icon.link{background:linear-gradient(145deg,#7658d8,#a284f4)}
.share-option-name{font-weight:700}
.share-tip{display:block;margin-top:28rpx;padding:18rpx 20rpx;border-radius:18rpx;background:#F7F9F8;color:#767C85;font-size:22rpx;line-height:1.5}
.poster-mask{position:fixed;z-index:90;inset:0;display:flex;align-items:center;justify-content:center;padding:28rpx;background:rgba(0,0,0,.45)}
.poster-sheet{width:100%;max-width:720rpx;padding:30rpx 28rpx 28rpx;box-sizing:border-box;border-radius:34rpx;background:#fff}
.poster-head{display:flex;align-items:center;justify-content:space-between}
.poster-kicker,.poster-title{display:block}
.poster-kicker{color:#08A152;font-size:24rpx;font-weight:700;letter-spacing:2rpx}
.poster-title{margin-top:5rpx;color:#191B1F;font-size:36rpx;font-weight:800}
.poster-close{width:62rpx;height:62rpx;margin:0;padding:0;border:0;border-radius:50%;background:#F2F4F3;color:#767C85;font-size:28rpx;line-height:60rpx}
.poster-close::after{border:0}
.poster-preview{position:relative;margin:24rpx auto 0;overflow:hidden;border-radius:24rpx;background:#F7F9F8}
.poster-canvas{display:block}
.poster-loading{position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:18rpx;background:rgba(247,249,248,.94);color:#767C85;font-size:26rpx}
.poster-loading-dot{width:42rpx;height:42rpx;border:6rpx solid #EDEFEF;border-top-color:#0BBF63;border-radius:50%;animation:poster-spin .8s linear infinite}
.poster-tip{display:block;margin-top:20rpx;color:#767C85;font-size:24rpx}
.poster-actions{display:grid;grid-template-columns:1fr 1.35fr;gap:16rpx;margin-top:20rpx}
.poster-copy,.poster-save{height:76rpx;margin:0;border:0;border-radius:20rpx;font-size:26rpx;font-weight:700;line-height:76rpx}
.poster-copy{background:#F2F4F3;color:#3A3D43}
.poster-save{background:linear-gradient(135deg,#12D978,#08A152);color:#fff}
.poster-save[disabled]{background:#EDEFEF;color:#fff}
@keyframes poster-spin{to{transform:rotate(360deg)}}
.state-error{display:flex;align-items:center;flex-direction:column;margin:120rpx 12rpx 0;padding:42rpx 28rpx;border-radius:28rpx;background:#fff;box-shadow:0 14rpx 30rpx rgba(23,26,23,.08);text-align:center}
.state-error-icon{display:flex;width:64rpx;height:64rpx;align-items:center;justify-content:center;border-radius:22rpx;background:#FFEEEA;color:#FF3B30;font-size:28rpx;font-weight:900}
.state-error-title{margin-top:18rpx;color:#191B1F;font-size:30rpx;font-weight:900}
.state-error-copy{margin-top:8rpx;color:#767C85;font-size:24rpx;line-height:1.5}
.state-error-action{height:62rpx;margin-top:24rpx;padding:0 28rpx;border-radius:999rpx;background:#F2F4F3;color:#3A3D43;font-size:26rpx;font-weight:700;line-height:60rpx}
.state-error-action::after{border:0}
.detail-loading{padding-top:24rpx}
.detail-skeleton{border-radius:28rpx;background:linear-gradient(100deg,#e6f1f5 30%,#f8fcfd 45%,#e6f1f5 60%);background-size:220% 100%;animation:detail-skeleton-shimmer 1.25s ease-in-out infinite}
.detail-skeleton-hero{height:480rpx}
.detail-skeleton-card{height:230rpx;margin-top:20rpx}
.detail-skeleton-card.short{height:150rpx}
@keyframes detail-skeleton-shimmer{0%{background-position:100% 0}100%{background-position:-100% 0}}
.rich-content{display:block;width:100%;margin-top:16rpx!important;padding:0!important;border:0!important;border-radius:0!important;background:transparent!important;box-shadow:none!important;overflow:visible;color:#3A3D43;font-size:28rpx;line-height:1.75}
@media screen and (min-width:768px){.page{width:750rpx;margin:0 auto}.action-bar{left:50%;width:750rpx;box-sizing:border-box;transform:translateX(-50%)}.sheet{width:750rpx;margin:0 auto;left:50%;transform:translateX(-50%);border-radius:40rpx 40rpx 0 0}}
</style>
