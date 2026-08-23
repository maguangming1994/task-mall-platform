<template>
  <view class="page">
    <view v-if="loading" class="detail-loading"><view class="detail-skeleton detail-skeleton-hero" /><view class="detail-skeleton detail-skeleton-card" /><view class="detail-skeleton detail-skeleton-card short" /></view>
    <view v-else-if="loadError" class="state-error"><text class="state-error-icon">!</text><text class="state-error-title">任务详情加载失败</text><text class="state-error-copy">{{ loadError }}</text><button class="state-error-action" @click="loadTask">重试</button></view>
    <view v-else-if="!task" class="state-text">任务不存在或已经下线</view>
    <template v-else>
      <view class="detail-pagebar">
        <text class="detail-pagebar-back" @click="goBack">‹</text>
        <text class="detail-pagebar-title">任务详情</text>
        <view class="detail-pagebar-share" @click="openSharePanel"><text class="detail-pagebar-share-icon">↗</text><text>分享</text></view>
      </view>
      <view class="detail-hero">
        <image class="detail-hero-art" :src="heroImageSrc" mode="aspectFill" @error="onHeroImageError" />
        <view class="hero-badges"><text class="hero-badge">{{ categoryLabel(task.category) }}</text><text class="hero-badge">{{ taskTypeLabel(task.task_type) }}</text></view>
        <text class="detail-title">{{ task.title }}</text>
        <text v-if="task.description_text" class="detail-summary">{{ task.description_text }}</text>
        <view class="publisher-row"><text>{{ task.publisher.display_name }}</text><text class="verified-badge">✓ {{ task.publisher.publisher_badge }}</text></view>
      </view>

      <view v-if="task.steps.length" class="content-card task-steps-card">
        <text class="section-title">任务步骤 / 流程</text>
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
        <view v-if="evidenceSummary.requiredCount" class="evidence-inline">
          <view class="section-head"><text class="evidence-inline-title">提交材料</text><text class="view-count">{{ evidenceSummary.requiredCount }} 项必做</text></view>
          <text class="evidence-inline-copy">截图 {{ evidenceSummary.imageCount }} 项 · 文字 {{ evidenceSummary.textCount }} 项 · 确认 {{ evidenceSummary.confirmCount }} 项，具体要求以步骤为准。</text>
        </view>
      </view>

      <view class="reward-card">
        <view><text class="section-eyebrow">完成任务可获得</text><text class="reward-main">{{ primaryRewardText(task) }}</text></view>
        <view class="slot-box"><text class="slot-value">{{ task.remaining_reward_slots }}</text><text class="slot-label">剩余名额</text></view>
      </view>
      <view v-if="task.rewards.length > 1" class="reward-list"><text v-for="(reward, index) in task.rewards" :key="index" class="reward-pill">{{ rewardText(reward) }}</text></view>

      <view class="stats-card">
        <view class="stat"><text class="stat-value">{{ task.completion_window_hours }}小时</text><text class="stat-label">限时完成</text></view>
        <view class="stat"><text class="stat-value">{{ task.review_deadline_hours }}小时</text><text class="stat-label">限时审核</text></view>
        <view class="stat"><text class="stat-value">{{ task.completed_user_count }}</text><text class="stat-label">已完成数</text></view>
        <view class="stat"><text class="stat-value">{{ passRateText(task.pass_rate_percent) }}</text><text class="stat-label">通过率</text></view>
      </view>

      <view class="content-card task-description-card">
        <view class="section-head"><text class="section-title">任务说明</text><text class="view-count">◉ {{ task.view_count }} 次浏览</text></view>
        <rich-text class="description rich-content" :nodes="taskDescriptionContent(task.description)" />
        <view class="tag-row"><text v-for="tag in task.tags" :key="tag" class="task-tag">{{ tag }}</text></view>
      </view>

      <view class="content-card review-rules-card">
        <text class="section-title">领取与审核规则</text>
        <view class="rule-row"><text class="rule-label">领取截止</text><text class="rule-value">{{ formatTime(task.claim_ends_at) }}</text></view>
        <view class="rule-row"><text class="rule-label">完成时限</text><text class="rule-value">领取后 {{ task.completion_window_hours }} 小时</text></view>
        <view class="rule-row"><text class="rule-label">提交机会</text><text class="rule-value">最多 {{ task.submission_limit }} 次</text></view>
        <view class="rule-row"><text class="rule-label">审核期限</text><text class="rule-value">提交后 {{ task.review_deadline_hours }} 小时</text></view>
        <view class="rule-row"><text class="rule-label">申诉期限</text><text class="rule-value">审核结果后 {{ task.appeal_deadline_days }} 天</text></view>
      </view>

      <view v-if="task.rules.length" class="content-card supplement-card">
        <text class="section-title">补充要求</text>
        <view class="rules-list">
          <text v-for="(rule, index) in task.rules" :key="index" class="rule-copy">{{ ruleText(rule, index) }}</text>
        </view>
      </view>

      <text class="report-link compact-report" @click="reportCurrentTask">发现任务有问题？举报此任务</text>
      <view class="bottom-space" />

      <view class="fixed-action">
        <view class="fixed-reward"><text class="fixed-caption">任务奖励</text><text class="fixed-value">{{ primaryRewardText(task) }}</text></view>
        <button class="claim-button" :disabled="claiming || (!task.can_claim && !!auth.accessToken)" :loading="claiming" @click="claimCurrentTask">{{ !auth.accessToken ? '登录后领取' : task.can_claim ? '立即领取' : reasonText(task.claim_unavailable_reason) }}</button>
      </view>

      <view v-if="claimConfirmVisible" class="claim-confirm-mask" @click.self="closeClaimConfirm">
        <view class="claim-confirm-sheet">
          <view class="claim-confirm-icon">✓</view>
          <text class="claim-confirm-title">确认领取任务</text>
          <text class="claim-confirm-task">{{ task.title }}</text>
          <text class="claim-confirm-copy">领取后需在 {{ task.completion_window_hours }} 小时内完成，系统会立即预留全部奖励。</text>
          <view class="claim-confirm-meta"><view><text class="claim-confirm-reward">{{ primaryRewardText(task) }}</text><text>完成奖励</text></view><view><text class="claim-confirm-slots">{{ task.remaining_reward_slots }}</text><text>剩余名额</text></view></view>
          <view class="claim-confirm-actions"><button class="claim-confirm-cancel" @click="closeClaimConfirm">取消</button><button class="claim-confirm-submit" :loading="claiming" @click="confirmClaim">确认领取</button></view>
        </view>
      </view>

      <view v-if="shareVisible" class="share-mask" @click.self="closeSharePanel">
        <view class="share-sheet">
          <view class="share-sheet-head"><view><text class="share-kicker">SHARE TASK</text><text class="share-title">分享这个校园任务</text></view><button class="share-close" @click="closeSharePanel">×</button></view>
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
      </view>

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
  </view>
</template>

<script setup lang="ts">
import { onLoad, onShareAppMessage, onShareTimeline } from '@dcloudio/uni-app'
import qrcode from 'qrcode-generator'
import { computed, nextTick, ref } from 'vue'
import { claimTask, getHallTask, reportTask, type HallTask, type TaskReward } from '@/api/tasks'
import { useAuthStore } from '@/stores/auth'
import { navigateToLogin } from '@/utils/authRedirect'
import { richTextContent } from '@/utils/richText'

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
  taskId = typeof options?.id === 'string' ? options.id : ''
  if (!taskId) { loading.value = false; return }
  await loadTask()
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
  if (!auth.accessToken) return navigateToLogin()
  claimConfirmVisible.value = true
}

function closeClaimConfirm() {
  if (claiming.value) return
  claimConfirmVisible.value = false
}

function goBack() {
  uni.navigateBack()
}

async function confirmClaim() {
  if (!task.value || !auth.accessToken) return
  claimConfirmVisible.value = false
  claiming.value = true
  try {
    await claimTask(auth.accessToken, task.value.id)
    const result = await uni.showModal({ title: '领取成功', content: '任务已加入“我的任务”，请在规定时间内提交完成材料。', confirmText: '查看我的任务', cancelText: '继续浏览' })
    if (result.confirm) {
      uni.setStorageSync('task_mall_open_my_tasks', true)
      uni.reLaunch({ url: '/pages/tasks/index' })
    } else {
      await loadTask()
    }
  } catch (error) { uni.showToast({ title: error instanceof Error ? error.message : '领取失败', icon: 'none' }) }
  finally { claiming.value = false }
}

async function reportCurrentTask() {
  if (!task.value) return
  if (!auth.accessToken) return navigateToLogin()
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
function formatTime(value: string) { return new Date(value).toLocaleString() }
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
.page{min-height:100vh;padding:0 28rpx;box-sizing:border-box;background:var(--tm-canvas)}.state-text{padding:180rpx 0;text-align:center;color:var(--tm-ink-500)}.detail-hero{position:relative;padding:34rpx 4rpx 20rpx;color:var(--tm-ink-900)}.share-button{position:absolute;z-index:2;right:4rpx;top:28rpx;padding:9rpx 18rpx;border:1rpx solid var(--tm-line);border-radius:18rpx;background:#fff;color:var(--tm-brand-700);font-size:20rpx;font-weight:700}.hero-badges{display:flex;gap:10rpx;padding-right:100rpx}.hero-badge{padding:7rpx 14rpx;border-radius:10rpx;background:var(--tm-brand-50);color:var(--tm-brand-700);font-size:19rpx;font-weight:700}.detail-title{display:block;margin-top:22rpx;padding-right:60rpx;font-size:38rpx;font-weight:850;line-height:1.35}.publisher-row{display:flex;align-items:center;gap:12rpx;margin-top:18rpx;color:var(--tm-ink-500);font-size:21rpx}.verified-badge{padding:4rpx 10rpx;border-radius:8rpx;background:var(--tm-brand-50);color:var(--tm-brand-700)}.reward-card{display:flex;align-items:center;justify-content:space-between;margin-top:20rpx;padding:28rpx;border:1rpx solid #ffe0bd;border-radius:28rpx;background:linear-gradient(135deg,#fffaf3,#fff2df)}.section-eyebrow,.reward-main,.slot-value,.slot-label,.section-title,.description,.rule-copy,.safety-title,.safety-copy,.fixed-caption,.fixed-value{display:block}.section-eyebrow{color:#9a6b43;font-size:20rpx}.reward-main{margin-top:7rpx;color:#f05f22;font-size:38rpx;font-weight:850}.slot-box{text-align:right}.slot-value{color:var(--tm-brand-700);font-size:34rpx;font-weight:850}.slot-label{margin-top:3rpx;color:#98a2b3;font-size:18rpx}.reward-list{display:flex;gap:10rpx;margin-top:12rpx}.reward-pill{padding:8rpx 14rpx;border-radius:12rpx;background:var(--tm-reward-100);color:var(--tm-reward-600);font-size:21rpx;font-weight:750}.stats-card{display:grid;grid-template-columns:repeat(4,1fr);margin-top:20rpx;padding:24rpx 8rpx;border:1rpx solid var(--tm-line);border-radius:26rpx;background:#fff}.stat{text-align:center;border-right:1rpx solid var(--tm-line)}.stat:last-child{border-right:0}.stat-value,.stat-label{display:block}.stat-value{font-size:22rpx;font-weight:850}.stat-label{margin-top:7rpx;color:#98a2b3;font-size:17rpx}.content-card{margin-top:20rpx;padding:28rpx;border:1rpx solid var(--tm-line);border-radius:28rpx;background:#fff}.section-head{display:flex;align-items:center;justify-content:space-between}.section-title{font-size:29rpx;font-weight:850}.view-count{color:#98a2b3;font-size:20rpx}.description{margin-top:20rpx;color:#475467;font-size:24rpx;line-height:1.75}.tag-row{display:flex;gap:10rpx;flex-wrap:wrap;margin-top:20rpx}.task-tag{padding:8rpx 14rpx;border-radius:10rpx;background:#f4f6f9;color:#667085;font-size:20rpx}.step-row{display:flex;gap:18rpx;padding:24rpx 0;border-bottom:1rpx solid #f0f2f5}.step-row:last-child{padding-bottom:0;border-bottom:0}.step-index{display:flex;align-items:center;justify-content:center;width:50rpx;height:50rpx;flex:none;border-radius:15rpx;background:var(--tm-brand-600);color:#fff;font-size:22rpx;font-weight:800}.step-main{min-width:0;flex:1}.step-title-row{display:flex;align-items:center;gap:10rpx}.step-title{font-size:25rpx;font-weight:800}.required-badge{padding:3rpx 8rpx;border-radius:7rpx;background:#fff0ed;color:#d92d20;font-size:16rpx}.step-description{display:block;margin-top:8rpx;color:#667085;font-size:21rpx;line-height:1.55}.step-input-tip{display:block;margin-top:9rpx;color:var(--tm-brand-600);font-size:19rpx}.rule-row{display:flex;align-items:flex-start;justify-content:space-between;gap:24rpx;padding:19rpx 0;border-bottom:1rpx solid #f0f2f5}.rule-row:last-child{padding-bottom:0;border-bottom:0}.rule-label{color:#667085;font-size:22rpx}.rule-value{text-align:right;font-size:22rpx;font-weight:700}.rule-copy{margin-top:16rpx;color:#475467;font-size:23rpx;line-height:1.65}.safety-card{margin-top:20rpx;padding:24rpx 26rpx;border-radius:24rpx;background:var(--tm-brand-50)}.safety-title{color:var(--tm-brand-700);font-size:24rpx;font-weight:800}.safety-copy{margin-top:8rpx;color:#5c6f91;font-size:21rpx;line-height:1.6}.report-link{display:block;padding:28rpx 0;text-align:center;color:#98a2b3;font-size:21rpx}.bottom-space{height:150rpx}.fixed-action{position:fixed;z-index:20;left:0;right:0;bottom:0;display:flex;align-items:center;gap:24rpx;padding:18rpx 28rpx calc(18rpx + env(safe-area-inset-bottom));border-top:1rpx solid var(--tm-line);background:rgba(255,255,255,.96);box-shadow:0 -10rpx 30rpx rgba(29,43,73,.08)}.fixed-reward{min-width:180rpx}.fixed-caption{color:#98a2b3;font-size:18rpx}.fixed-value{margin-top:3rpx;color:#f05f22;font-size:27rpx;font-weight:850}.claim-button{height:82rpx;flex:1;margin:0;border-radius:20rpx;background:var(--tm-brand-600);color:#fff;font-size:26rpx;font-weight:800;line-height:82rpx}.claim-button::after{border:0}.claim-button[disabled]{background:#d0d5dd;color:#fff}@media screen and (min-width:768px){.page{width:750rpx;margin:0 auto}.fixed-action{left:50%;width:750rpx;box-sizing:border-box;transform:translateX(-50%)}}
.task-cover{display:block;width:calc(100% + 56rpx);height:360rpx;margin:0 -28rpx;background:#eaf0fa}.step-guide-image{display:block;width:100%;max-height:520rpx;margin-top:16rpx;border-radius:18rpx;background:#f2f4f7}.step-actions{display:flex;gap:12rpx;margin-top:14rpx}.step-action{height:58rpx;margin:0;padding:0 20rpx;border:0;border-radius:14rpx;background:var(--tm-brand-600);color:#fff;font-size:20rpx;line-height:58rpx}.step-action.secondary{border:1rpx solid var(--tm-brand-500);background:#fff;color:var(--tm-brand-700)}.step-action::after{border:0}
/* Task Experience V4: 与首页、任务大厅统一的柔和蓝青视觉系统。 */
.page{background:radial-gradient(circle at 94% 2%,rgba(57,211,214,.22),transparent 27%),linear-gradient(180deg,#eaf9ff 0,#f6fbff 34%,#f5f7fb 100%)}
.task-cover{height:330rpx;border:0;border-radius:0 0 30rpx 30rpx;box-shadow:0 16rpx 34rpx rgba(31,89,122,.12)}
.detail-hero{overflow:hidden;margin-top:20rpx;padding:30rpx 28rpx 28rpx;border:0;border-radius:30rpx;background:linear-gradient(128deg,#1265d8 0%,#168ee3 55%,#28c4c6 100%);box-shadow:0 18rpx 42rpx rgba(26,105,169,.2);color:#fff}
.detail-hero::after{content:'';position:absolute;right:-65rpx;bottom:-92rpx;width:230rpx;height:230rpx;border-radius:50%;background:rgba(255,255,255,.1)}
.detail-title{position:relative;z-index:1;font-size:36rpx}.share-button,.hero-badge,.verified-badge{border:0;box-shadow:none}.share-button{background:rgba(255,255,255,.94);color:#136ac8}.hero-badge{background:rgba(255,255,255,.18);color:#fff;backdrop-filter:blur(8rpx)}.publisher-row{position:relative;z-index:1;color:rgba(255,255,255,.78)}.verified-badge{background:rgba(216,255,234,.94);color:#087a4b}
.reward-card,.stats-card,.content-card,.safety-card{border:1rpx solid #dce9ef;box-shadow:0 13rpx 32rpx rgba(34,76,100,.075)}.content-card{padding:27rpx 28rpx;background:rgba(255,255,255,.96)}.content-card:nth-of-type(even){background:rgba(255,255,255,.96)}.section-title{color:#12314b;font-size:28rpx}.reward-card{border-color:#f3dfb5;background:linear-gradient(135deg,#fffaf0 0%,#fff3d7 58%,#f0fbff 100%)}.stats-card{background:rgba(255,255,255,.96)}
.reward-pill,.task-tag,.required-badge{border:0}.reward-pill{border-radius:999rpx;background:#fff3c8;color:#8c6200}.task-tag{border-radius:999rpx;background:#edf8fc;color:#28728c}.step-row{gap:16rpx;padding:23rpx 0;border-bottom-color:#e8f0f3}.step-index{width:48rpx;height:48rpx;border:0;border-radius:15rpx;background:linear-gradient(145deg,#1268dc,#20b9c8);box-shadow:0 7rpx 16rpx rgba(18,104,220,.18)}.step-title{color:#17344b}.required-badge{border-radius:999rpx;background:#fff0ec;color:#d74628}.step-description{color:#627787;line-height:1.6}.step-input-tip{display:inline-block;margin-top:10rpx;padding:5rpx 10rpx;border-radius:999rpx;background:#edf7ff;color:#1670c7}
.step-guide-image{border:1rpx solid #dce8ed;box-shadow:0 8rpx 22rpx rgba(34,75,99,.08)}.step-action{border:0;background:linear-gradient(135deg,#1268dc,#1c9ed4);box-shadow:none}.step-action.secondary{border:1rpx solid #b8d7e8;background:#fff;color:#146bc2}.description{padding:0;border:0;background:transparent;color:#526878;line-height:1.75}.rule-row{border-bottom-color:#e8f0f3}.rule-label{color:#748694}.rule-value{color:#263f54}
.safety-card{background:linear-gradient(135deg,#eaf9ff,#edfbf4)}.safety-title{color:#126bc3}.safety-copy{color:#5d7485}.fixed-action{border-top:1rpx solid rgba(199,218,228,.9);background:rgba(255,255,255,.94);box-shadow:0 -12rpx 34rpx rgba(31,76,101,.1);backdrop-filter:blur(18rpx)}.claim-button{border:0;background:linear-gradient(135deg,#1268dc,#19a8cf);box-shadow:0 10rpx 22rpx rgba(18,104,220,.2)}
.rich-content{display:block;width:100%;margin-top:16rpx!important;padding:0!important;border:0!important;border-radius:0!important;background:transparent!important;box-shadow:none!important;overflow:visible;color:#526878;font-size:24rpx;line-height:1.75}
.share-mask,.poster-mask{position:fixed;z-index:80;inset:0;display:flex;align-items:flex-end;justify-content:center;padding:24rpx;box-sizing:border-box;background:rgba(9,35,57,.45);backdrop-filter:blur(8rpx)}.share-sheet,.poster-sheet{width:100%;max-width:720rpx;padding:30rpx 28rpx calc(28rpx + env(safe-area-inset-bottom));box-sizing:border-box;border-radius:34rpx;background:#fff;box-shadow:0 30rpx 80rpx rgba(13,42,64,.25)}.share-sheet-head,.poster-head{display:flex;align-items:center;justify-content:space-between}.share-kicker,.poster-kicker,.share-title,.poster-title{display:block}.share-kicker,.poster-kicker{color:#1576ca;font-size:18rpx;font-weight:850;letter-spacing:2rpx}.share-title,.poster-title{margin-top:5rpx;color:#14344c;font-size:30rpx;font-weight:850}.share-close,.poster-close{width:62rpx;height:62rpx;margin:0;padding:0;border:0;border-radius:50%;background:#f0f6f9;color:#557183;font-size:35rpx;line-height:58rpx}.share-close::after,.poster-close::after,.share-option::after,.poster-copy::after,.poster-save::after{border:0}.share-options{display:grid;grid-template-columns:repeat(4,1fr);gap:14rpx;margin-top:30rpx}.share-option{display:flex;flex-direction:column;align-items:center;gap:12rpx;margin:0;padding:0;border:0;background:transparent;color:#36536a;font-size:21rpx;line-height:1.25}.share-option-icon{display:flex;align-items:center;justify-content:center;width:82rpx;height:82rpx;border-radius:26rpx;color:#fff;font-size:26rpx;font-weight:850;box-shadow:0 10rpx 22rpx rgba(24,88,130,.15)}.share-option-icon.friend{background:linear-gradient(145deg,#14b769,#36d39b)}.share-option-icon.timeline{background:linear-gradient(145deg,#1a77dc,#25b8d1)}.share-option-icon.poster{background:linear-gradient(145deg,#f3a229,#ffcb4c)}.share-option-icon.link{background:linear-gradient(145deg,#7658d8,#a284f4)}.share-option-name{font-weight:750}.share-tip,.poster-tip{display:block;margin-top:28rpx;padding:18rpx 20rpx;border-radius:18rpx;background:#f3f9fc;color:#6a8191;font-size:20rpx;line-height:1.5}
.poster-mask{z-index:90;align-items:center;padding:28rpx}.poster-sheet{padding-bottom:28rpx}.poster-preview{position:relative;margin:24rpx auto 0;overflow:hidden;border-radius:24rpx;background:linear-gradient(145deg,#e6f4fa,#f6fbfd);box-shadow:0 14rpx 36rpx rgba(26,75,103,.14)}.poster-canvas{display:block}.poster-loading{position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:18rpx;background:rgba(242,249,252,.94);color:#5c788b;font-size:22rpx}.poster-loading-dot{width:42rpx;height:42rpx;border:6rpx solid #cfe6ee;border-top-color:#1598c8;border-radius:50%;animation:poster-spin .8s linear infinite}.poster-actions{display:grid;grid-template-columns:1fr 1.35fr;gap:16rpx;margin-top:20rpx}.poster-copy,.poster-save{height:76rpx;margin:0;border:0;border-radius:20rpx;font-size:23rpx;font-weight:800;line-height:76rpx}.poster-copy{background:#edf6fa;color:#286784}.poster-save{background:linear-gradient(135deg,#1268dc,#1bb5c7);color:#fff}.poster-save[disabled]{background:#cbd9df;color:#fff}@keyframes poster-spin{to{transform:rotate(360deg)}}
@media screen and (max-width:380px){.share-options{gap:6rpx}.share-option-icon{width:72rpx;height:72rpx;border-radius:22rpx}.share-option-name{font-size:19rpx}}
  .state-error{display:flex;align-items:center;flex-direction:column;margin:120rpx 12rpx 0;padding:42rpx 28rpx;border:1rpx solid #f0d1cc;border-radius:28rpx;background:#fff8f6;box-shadow:0 14rpx 30rpx rgba(113,63,55,.08);text-align:center}.state-error-icon{display:flex;width:64rpx;height:64rpx;align-items:center;justify-content:center;border-radius:22rpx;background:#fff0ed;color:#d92d20;font-size:31rpx;font-weight:900}.state-error-title{margin-top:18rpx;color:#8f2e26;font-size:28rpx;font-weight:900}.state-error-copy{margin-top:8rpx;color:#a96f68;font-size:20rpx;line-height:1.5}.state-error-action{height:62rpx;margin-top:24rpx;padding:0 28rpx;border:1rpx solid #e4aaa1;border-radius:999rpx;background:#fff;color:#b53b30;font-size:21rpx;font-weight:800;line-height:60rpx}.state-error-action::after{border:0}
  .claim-confirm-mask{position:fixed;z-index:100;inset:0;display:flex;align-items:center;justify-content:center;padding:34rpx;background:rgba(13,37,55,.48);backdrop-filter:blur(8rpx)}.claim-confirm-sheet{width:100%;max-width:650rpx;padding:40rpx 34rpx 30rpx;box-sizing:border-box;border:1rpx solid #dbe8ee;border-radius:32rpx;background:#fff;box-shadow:0 26rpx 70rpx rgba(19,54,74,.25);text-align:center}.claim-confirm-icon{display:flex;width:104rpx;height:104rpx;align-items:center;justify-content:center;margin:0 auto;border-radius:50%;background:linear-gradient(145deg,#e2f5ff,#eafbf2);color:#1682d4;font-size:53rpx;font-weight:950;box-shadow:0 8rpx 22rpx rgba(32,127,185,.13)}.claim-confirm-title{display:block;margin-top:21rpx;color:#14344d;font-size:32rpx;font-weight:950}.claim-confirm-copy{display:block;margin:12rpx auto 0;max-width:500rpx;color:#647988;font-size:22rpx;line-height:1.65}.claim-confirm-meta{display:grid;grid-template-columns:1fr 1fr;margin-top:24rpx;padding:20rpx 8rpx;border-top:1rpx solid #e4edf1;border-bottom:1rpx solid #e4edf1}.claim-confirm-meta>view{display:flex;align-items:center;flex-direction:column;border-right:1rpx solid #e4edf1}.claim-confirm-meta>view:last-child{border-right:0}.claim-confirm-meta text:last-child{margin-top:5rpx;color:#83929e;font-size:18rpx}.claim-confirm-reward{color:#ef5b32;font-size:28rpx;font-weight:950}.claim-confirm-slots{color:#176fca;font-size:28rpx;font-weight:950}.claim-confirm-actions{display:grid;grid-template-columns:1fr 1fr;gap:16rpx;margin-top:24rpx}.claim-confirm-actions button{height:76rpx;margin:0;border-radius:20rpx;font-size:24rpx;font-weight:900;line-height:76rpx}.claim-confirm-cancel{border:1rpx solid #d8e2e7;background:#f7fafb;color:#586e7c}.claim-confirm-submit{border:0;background:linear-gradient(110deg,#08a967,#0ac180);box-shadow:0 10rpx 22rpx rgba(7,166,101,.2);color:#fff}.claim-confirm-actions button::after{border:0}
  .detail-loading{padding-top:24rpx}.detail-skeleton{border-radius:28rpx;background:linear-gradient(100deg,#e6f1f5 30%,#f8fcfd 45%,#e6f1f5 60%);background-size:220% 100%;animation:detail-skeleton-shimmer 1.25s ease-in-out infinite}.detail-skeleton-hero{height:300rpx}.detail-skeleton-card{height:230rpx;margin-top:20rpx}.detail-skeleton-card.short{height:150rpx}@keyframes detail-skeleton-shimmer{0%{background-position:100% 0}100%{background-position:-100% 0}}
  .evidence-card{background:linear-gradient(145deg,#fff 0%,#f2fbff 100%)}.evidence-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:10rpx;margin-top:18rpx}.evidence-grid view{padding:16rpx 8rpx;border:1rpx solid #dcebf0;border-radius:17rpx;background:#f8fcfd;text-align:center}.evidence-grid strong,.evidence-grid text{display:block}.evidence-grid strong{color:#1473c8;font-size:28rpx;font-weight:950}.evidence-grid text{margin-top:4rpx;color:#718695;font-size:18rpx}.evidence-note{display:block;margin-top:15rpx;color:#79909e;font-size:19rpx;line-height:1.55}
/* V09 任务详情：对应 03/04 的顶部导航、校园主视觉和 hero→步骤→奖励→规则顺序。 */
.page{padding:0 28rpx calc(154rpx + env(safe-area-inset-bottom));background:linear-gradient(180deg,#edf9ff 0%,#f7fbfd 46%,#f2f7fa 100%)}
.detail-pagebar{display:flex;align-items:center;justify-content:space-between;height:88rpx;margin:0 -4rpx}.detail-pagebar-back{width:76rpx;color:#18364d;font-size:54rpx;font-weight:300;line-height:1;text-align:left}.detail-pagebar-title{color:#17334a;font-size:30rpx;font-weight:900}.detail-pagebar-share{padding:8rpx 17rpx;border:1rpx solid #bdd9e8;border-radius:999rpx;background:#fff;color:#1475c8;font-size:20rpx;font-weight:850}
.task-cover{display:none}.detail-hero{position:relative;min-height:272rpx;margin:0 0 18rpx;padding:28rpx 28rpx 25rpx;overflow:hidden;border:1rpx solid #c9e2ee;border-radius:29rpx;background:linear-gradient(125deg,#eaf8ff 0%,#ddf2ff 58%,#f3faff 100%);box-shadow:0 12rpx 28rpx rgba(31,87,116,.08);color:#12324a}.detail-hero::after{content:'';position:absolute;right:-76rpx;bottom:-92rpx;width:240rpx;height:240rpx;border-radius:50%;background:rgba(32,185,205,.15)}.detail-hero-art{position:absolute;z-index:0;right:-2rpx;bottom:0;width:55%;height:100%;object-fit:cover;opacity:.9}.detail-hero::before{content:'';position:absolute;z-index:1;left:0;top:0;bottom:0;width:62%;background:linear-gradient(90deg,rgba(234,248,255,.98),rgba(234,248,255,.85),rgba(234,248,255,0))}.detail-hero>*:not(.detail-hero-art){position:relative;z-index:2}.hero-badges{gap:8rpx;padding-right:40%}.hero-badge{padding:6rpx 12rpx;border-radius:9rpx;background:#fff;border:1rpx solid #b8dce9;color:#1b72bd;font-size:18rpx;font-weight:850}.detail-title{max-width:58%;margin-top:17rpx;padding-right:0;color:#12324a;font-size:34rpx;line-height:1.28;font-weight:950}.detail-summary{display:block;max-width:58%;margin-top:9rpx;color:#536f82;font-size:20rpx;line-height:1.5}.publisher-row{gap:9rpx;margin-top:13rpx;color:#5c7787;font-size:19rpx}.verified-badge{padding:4rpx 9rpx;background:#e5fbef;color:#078354;font-size:17rpx}
.content-card,.reward-card,.stats-card,.safety-card{border-color:#d7e8ef;border-radius:25rpx;box-shadow:0 10rpx 25rpx rgba(32,79,102,.06)}.content-card{margin-top:16rpx;padding:24rpx 25rpx;background:rgba(255,255,255,.96)}.section-title{color:#133750;font-size:27rpx}.step-row{padding:19rpx 0;border-bottom-color:#e3edf1}.step-index{width:45rpx;height:45rpx;border-radius:14rpx;background:linear-gradient(145deg,#1676d7,#1cbcc4);box-shadow:0 5rpx 12rpx rgba(24,119,205,.16);font-size:21rpx}.step-title{color:#163b54;font-size:24rpx}.step-description{color:#607b8c;font-size:20rpx}.step-input-tip{background:#edf8ff;color:#1475c6;font-size:18rpx}.evidence-inline{margin-top:17rpx;padding-top:17rpx;border-top:1rpx solid #e4eef2}.evidence-inline-title{color:#316076;font-size:21rpx;font-weight:850}.evidence-inline-copy{display:block;margin-top:8rpx;color:#78909e;font-size:18rpx;line-height:1.5}
.reward-card{margin-top:16rpx;padding:23rpx 25rpx;background:linear-gradient(135deg,#fffaf0,#fff3d7 65%,#f2fbff)}.section-eyebrow{color:#956d46;font-size:18rpx}.reward-main{margin-top:5rpx;color:#ef5e2f;font-size:33rpx}.slot-value{color:#116cca;font-size:31rpx}.slot-label{font-size:17rpx}.reward-list{gap:8rpx;margin-top:10rpx}.reward-pill{padding:7rpx 12rpx;background:#fff4ca;font-size:18rpx}.stats-card{margin-top:16rpx;padding:20rpx 6rpx;background:#fff}.stat-value{color:#183d59;font-size:21rpx}.stat-label{font-size:16rpx}.description,.rich-content{margin-top:15rpx;color:#526f81;font-size:21rpx;line-height:1.65}.tag-row{gap:8rpx;margin-top:14rpx}.task-tag{padding:6rpx 11rpx;background:#edf8fc;color:#28728c;font-size:18rpx}.rule-row{padding:16rpx 0;border-bottom-color:#e6eef2}.rule-label{color:#708797;font-size:20rpx}.rule-value{color:#1d4058;font-size:20rpx}.rule-copy{margin-top:12rpx;color:#5a7587;font-size:21rpx}.safety-card{margin-top:16rpx;padding:20rpx 23rpx;background:linear-gradient(135deg,#eaf9ff,#edfbf4)}.safety-title{font-size:22rpx}.safety-copy{margin-top:6rpx;font-size:19rpx}.report-link{padding:22rpx 0;font-size:19rpx}.bottom-space{height:114rpx}
.fixed-action{padding:14rpx 23rpx calc(14rpx + env(safe-area-inset-bottom));background:rgba(255,255,255,.96)}.fixed-reward{min-width:165rpx}.fixed-caption{font-size:16rpx}.fixed-value{font-size:25rpx}.claim-button{height:74rpx;border-radius:999rpx;background:linear-gradient(110deg,#10ae72,#27c4a3);box-shadow:0 8rpx 18rpx rgba(10,169,112,.18);font-size:24rpx;line-height:74rpx}
.claim-confirm-sheet{max-width:620rpx;padding:29rpx 25rpx 24rpx;border-radius:29rpx}.claim-confirm-icon{width:72rpx;height:72rpx;border-radius:22rpx;font-size:36rpx}.claim-confirm-title{margin-top:14rpx;font-size:28rpx}.claim-confirm-task{display:block;margin-top:6rpx;color:#153b55;font-size:22rpx;font-weight:850}.claim-confirm-copy{margin-top:9rpx;font-size:20rpx}.claim-confirm-meta{margin-top:18rpx;padding:16rpx 6rpx}.claim-confirm-reward,.claim-confirm-slots{font-size:25rpx}.claim-confirm-actions{margin-top:18rpx}.claim-confirm-actions button{height:66rpx;border-radius:18rpx;font-size:22rpx;line-height:66rpx}.share-mask,.poster-mask{padding:20rpx}.share-sheet,.poster-sheet{border-radius:29rpx}
/* V09 parity tune: 03/04/05 对齐顶部导航、hero 比例、步骤层级和固定领取栏。 */
.detail-pagebar{height:78rpx;margin:0 -28rpx;padding:0 28rpx;box-sizing:border-box;background:#fff;border-bottom:1rpx solid #edf3f6}
.detail-pagebar-back{width:64rpx;font-size:46rpx;line-height:1}
.detail-pagebar-title{font-size:32rpx;letter-spacing:.2rpx}
.detail-pagebar-share{min-width:70rpx;box-sizing:border-box;padding:8rpx 2rpx;border:0;border-radius:0;background:transparent;color:#17334a;text-align:center;font-size:20rpx}
.detail-hero{height:230rpx;min-height:230rpx;box-sizing:border-box;padding:22rpx 28rpx 17rpx}
.detail-hero-art{right:-4rpx;width:52%;height:100%;object-position:right center}
.detail-title{max-width:57%;font-size:36rpx;line-height:1.3}
.detail-summary{max-width:57%;overflow:hidden;font-size:21rpx;white-space:nowrap;text-overflow:ellipsis}
.content-card{padding:25rpx 26rpx}
.task-steps-card{padding:23rpx 25rpx 19rpx}
.section-title{font-size:29rpx}
.step-row{padding:16rpx 0}
.step-index{width:46rpx;height:46rpx}
.step-title{font-size:26rpx}
.step-description{font-size:21rpx;line-height:1.58}
.reward-main{font-size:35rpx}
.stats-card{padding-top:21rpx;padding-bottom:21rpx}
.stat-value{font-size:23rpx}
.stat-label{font-size:17rpx}
.description,.rich-content{font-size:22rpx;line-height:1.68}
.rule-label,.rule-value{font-size:21rpx}
.rule-copy{font-size:22rpx}
.fixed-action{min-height:108rpx;box-sizing:border-box}
.detail-pagebar-share{display:flex;align-items:center;justify-content:center;gap:6rpx}
.detail-pagebar-share-icon{font-size:29rpx;font-weight:700;line-height:1}
.step-guide-link{display:inline-flex;align-items:center;margin-top:8rpx;padding:5rpx 10rpx;border-radius:999rpx;background:#edf8ff;color:#1475c6;font-size:18rpx;line-height:1.2}
.evidence-inline{display:none}
/* 任务详情设计稿为 2x 输出：步骤区保持紧凑，避免把奖励与规则整体推到首屏之外。 */
.task-steps-card{padding:20rpx 24rpx 12rpx}
.task-steps-card .section-title{font-size:26rpx}
.task-steps-card .step-row{gap:14rpx;padding:10rpx 0}
.task-steps-card .step-index{width:36rpx;height:36rpx;border-radius:11rpx;font-size:18rpx}
.task-steps-card .step-title{font-size:22rpx}
.task-steps-card .required-badge{padding:2rpx 6rpx;font-size:14rpx}
.task-steps-card .step-description{margin-top:4rpx;font-size:18rpx;line-height:1.35}
.task-steps-card .step-input-tip{display:inline-flex;margin-top:5rpx;padding:3rpx 8rpx;font-size:16rpx;line-height:1.2}
.reward-card{padding:18rpx 24rpx}
.stats-card{padding-top:16rpx;padding-bottom:16rpx}
/* V09 03/04 最终像素复核：以 426px 设计稿视口校准边距、首屏比例和规则卡层级。 */
.page{padding-left:32rpx;padding-right:32rpx}
.detail-pagebar{height:76rpx;margin-left:-32rpx;margin-right:-32rpx;padding-left:32rpx;padding-right:32rpx}
.detail-hero{height:230rpx;min-height:230rpx;margin-top:0;margin-bottom:18rpx;border-radius:28rpx;padding:22rpx 28rpx 17rpx}
.detail-hero-art{left:0;right:auto;width:100%;height:100%;object-fit:cover;opacity:1}
.detail-hero::before{width:64%;background:linear-gradient(90deg,rgba(234,248,255,.99) 0%,rgba(234,248,255,.9) 55%,rgba(234,248,255,.12) 100%)}
.detail-title{max-width:58%;font-size:35rpx;line-height:1.28}
.detail-summary{max-width:58%;font-size:20rpx;line-height:1.45}
.task-steps-card{margin-top:0;padding:20rpx 24rpx 13rpx}
.task-steps-card .step-row{padding:12rpx 0}
.task-steps-card .step-row:last-child{padding-bottom:4rpx}
.reward-card{margin-top:16rpx;min-height:94rpx;box-sizing:border-box}
.rules-list{margin-top:12rpx;padding:9rpx 14rpx;border:1rpx solid #d7eaf2;border-radius:16rpx;background:#f4fbfe}
.supplement-card .rule-copy{display:block;margin-top:6rpx;font-size:20rpx;line-height:1.5}
.supplement-card .rule-copy:first-child{margin-top:0}
.fixed-action{padding-left:32rpx;padding-right:32rpx}

/* V09 final parity: 03-任务详情与奖励。保持动态数据与业务操作，仅校准成品稿的视觉密度。 */
.page{background:linear-gradient(180deg,#f8fcfe 0%,#f6fafc 100%)}
.detail-pagebar{height:74rpx;border-bottom-color:#eaf1f5}
.detail-pagebar-back{font-size:42rpx}
.detail-pagebar-title{font-size:31rpx}
.detail-pagebar-share{min-width:86rpx;color:#16354c;font-size:21rpx;font-weight:700}
.detail-pagebar-share-icon{font-size:27rpx}
.detail-hero{height:226rpx;min-height:226rpx;margin-bottom:18rpx;padding:22rpx 30rpx 17rpx;border-color:#d8eaf2;border-radius:30rpx;box-shadow:0 12rpx 30rpx rgba(39,93,117,.065)}
.detail-hero-art{object-position:center center}
.detail-hero::before{width:66%;background:linear-gradient(90deg,rgba(239,249,255,.99) 0%,rgba(239,249,255,.93) 54%,rgba(239,249,255,.12) 100%)}
.hero-badges{padding-right:41%}
.hero-badge{padding:6rpx 13rpx;border-color:#c5e1ec;background:rgba(255,255,255,.82);font-size:18rpx}
.detail-title{max-width:60%;margin-top:18rpx;color:#102f49;font-size:36rpx;line-height:1.28}
.detail-summary{max-width:60%;margin-top:10rpx;color:#496b80;font-size:21rpx;line-height:1.5}
.publisher-row{margin-top:14rpx;color:#527386;font-size:19rpx}
.content-card,.reward-card,.stats-card,.safety-card{border-color:#dfeaf0;box-shadow:0 10rpx 28rpx rgba(45,84,105,.055)}
.task-steps-card{padding:20rpx 24rpx 12rpx}
.task-steps-card .section-title{font-size:26rpx}
.task-steps-card .step-row{gap:14rpx;padding:10rpx 0}
.task-steps-card .step-index{width:36rpx;height:36rpx;border-radius:11rpx;font-size:18rpx}
.task-steps-card .step-title{font-size:22rpx}
.task-steps-card .step-description{margin-top:4rpx;font-size:18rpx;line-height:1.38}
.task-steps-card .step-input-tip{margin-top:5rpx;padding:3rpx 8rpx;font-size:16rpx}
.reward-card{min-height:94rpx;padding:18rpx 24rpx;border-color:#f1d79d;background:linear-gradient(110deg,#fffaf0 0%,#fff6df 62%,#f8fcff 100%)}
.reward-main{font-size:36rpx}
.slot-value{font-size:34rpx}
.stats-card{padding:18rpx 7rpx}
.stat-value{font-size:23rpx}
.stat-label{font-size:17rpx}
.content-card{padding:24rpx 26rpx}
.description,.rich-content{font-size:21rpx;line-height:1.64}
.rule-row{padding:14rpx 0}
.rules-list{padding:12rpx 16rpx;background:#f6fbfd}
.fixed-action{min-height:112rpx;padding-top:15rpx;padding-bottom:calc(15rpx + env(safe-area-inset-bottom));box-shadow:0 -8rpx 28rpx rgba(29,64,84,.08)}
.claim-button{height:76rpx;line-height:76rpx}
/* V09 lower-page parity: keep all actions, but fold secondary information into the compact review flow. */
.page{padding-bottom:calc(118rpx + env(safe-area-inset-bottom))}
.task-steps-card .step-actions{gap:8rpx;margin-top:8rpx}
.task-steps-card .step-action{height:44rpx;margin:0;padding:0 14rpx;border-radius:999rpx;font-size:17rpx;line-height:44rpx}
.task-steps-card{padding:10rpx 24rpx 18rpx}
.reward-card{padding-top:15rpx;padding-bottom:15rpx}
.stats-card{padding-top:15rpx;padding-bottom:15rpx}
.task-description-card{padding:14rpx 24rpx}
.task-description-card .description,.task-description-card .rich-content{margin-top:8rpx!important;font-size:20rpx;line-height:1.52}
.task-description-card .tag-row{margin-top:8rpx}
.task-description-card .task-tag{padding:5rpx 10rpx;font-size:17rpx}
.review-rules-card{padding:14rpx 24rpx}
.review-rules-card .rule-row{min-height:38rpx;box-sizing:border-box;padding:5rpx 0}
.review-rules-card .rule-label,.review-rules-card .rule-value{font-size:19rpx}
.review-rules-card .rule-value{max-width:68%;overflow:hidden;white-space:nowrap;text-overflow:ellipsis;text-align:right}
.supplement-card{padding:10rpx 22rpx}
.supplement-card .rules-list{margin-top:6rpx;padding:3rpx 8rpx;border-radius:14rpx}
.supplement-card .rule-copy{margin-top:2rpx;font-size:18rpx;line-height:1.25}
.compact-report{display:block;height:52rpx;margin-top:16rpx;padding:0;color:#6d8290;font-size:18rpx;line-height:52rpx;text-align:center}
.bottom-space{height:0}
.fixed-reward{width:30%;min-width:0}
.fixed-action{min-height:96rpx;padding-top:7rpx;padding-bottom:calc(7rpx + env(safe-area-inset-bottom))}
</style>
