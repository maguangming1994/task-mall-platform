<template>
  <view v-if="visible" class="poster-mask" @click.self="emit('close')">
    <view class="poster-sheet">
      <view class="poster-head">
        <view>
          <text class="poster-kicker">{{ kicker }}</text>
          <text class="poster-title">{{ heading }}</text>
        </view>
        <button class="poster-close" aria-label="关闭海报" @click="emit('close')">×</button>
      </view>
      <view class="poster-preview" :style="previewStyle">
        <canvas :id="canvasId" :canvas-id="canvasId" class="poster-canvas" :style="previewStyle" :width="posterWidth" :height="posterHeight" />
        <view v-if="!posterReady" class="poster-loading"><view class="poster-loading-dot" /><text>正在生成分享海报</text></view>
      </view>
      <text class="poster-tip">保存海报后可发送给好友或发布到朋友圈，扫码即可查看详情。</text>
      <view class="poster-actions">
        <button class="poster-copy" @click="copyLink">复制链接</button>
        <button class="poster-save" :disabled="!posterReady" @click="savePoster">保存海报</button>
      </view>
    </view>

    <!-- #ifdef H5 -->
    <!-- H5 的 canvas 无法直接保存，展示生成的图片供用户长按保存 -->
    <view v-if="previewImage" class="poster-image-mask" @click.self="previewImage = ''">
      <view class="poster-image-card">
        <img class="poster-image" :src="previewImage" alt="分享海报" />
        <text class="poster-image-tip">长按图片保存到相册，再发布到朋友圈</text>
        <button class="poster-image-done" @click="previewImage = ''">完成</button>
      </view>
    </view>
    <!-- #endif -->
  </view>
</template>

<script setup lang="ts">
import qrcode from 'qrcode-generator'
import { computed, nextTick, ref, watch } from 'vue'
import { getPosterTemplate, type PosterTemplate } from '@/api/poster'
import type { ShareQrTargetType } from '@/api/share'
import { loadPosterQr, type PosterQr } from '@/utils/posterQr'

const props = withDefaults(defineProps<{
  /** 是否显示海报弹层 */
  visible: boolean
  /** 后端海报模板编码，为空时直接使用内置默认模板 */
  templateCode?: string
  /** 弹层顶部小标题 */
  kicker?: string
  /** 弹层标题 */
  heading?: string
  /** 海报上的栏目标签（卡片顶部的 # 标签） */
  label?: string
  /** 海报主标题 */
  title?: string
  /** 海报正文摘要 */
  content?: string
  /** 二维码指向的完整地址（小程序码不可用时的 H5 兜底） */
  link?: string
  /** 分享二维码目标类型：提供后优先使用微信小程序码 */
  qrTargetType?: ShareQrTargetType
  /** 分享二维码目标 ID（public_id 或邀请码） */
  qrTargetId?: string
  /** 可选图片，模板使用图片背景但未配置图片时作为兜底 */
  imageUrl?: string
  /** canvas 元素 id，同一页面只挂载一个海报弹层时保持默认即可 */
  canvasId?: string
}>(), {
  templateCode: '',
  kicker: 'SHARE POSTER',
  heading: '分享海报',
  label: '任务商城',
  title: '',
  content: '',
  link: '',
  qrTargetId: '',
  imageUrl: '',
  canvasId: 'sharePosterCanvas',
})

const emit = defineEmits<{ (e: 'close'): void }>()

const posterReady = ref(false)
const posterWidth = ref(330)
const posterHeight = ref(520)
// H5 保存时生成的图片地址，用于长按保存
const previewImage = ref('')
const template = ref<PosterTemplate>(createDefaultTemplate())
const posterQr = ref<PosterQr>({ link: '' })

const previewStyle = computed(() => `width:${posterWidth.value}px;height:${posterHeight.value}px`)

watch(() => props.visible, (open) => {
  if (!open) {
    previewImage.value = ''
    return
  }
  void preparePoster()
})

// 内置默认模板：后端模板不存在或接口失败时兜底，保证海报始终可生成
function createDefaultTemplate(): PosterTemplate {
  return {
    public_id: 'default',
    code: 'default',
    name: '默认模板',
    canvas_width: 330,
    canvas_height: 520,
    background_type: 'gradient',
    background_value: { stops: ['#0e799d', '#19c6d1', '#76df63'], angle: 135 },
    banner_config: {
      label_text: '', label_color: '#10213a', label_bg: '#ffdb4d',
      title_text: '', title_color: '#ffffff',
    },
    body_config: { card_bg: 'rgba(255,255,255,.96)', category_color: '#60798d', content_color: '#173c58' },
    qrcode_config: {
      cta_text: '扫码查看详情', cta_color: '#173c58',
      helper_text: '扫码查看详情，分享给朋友', helper_color: '#173c58',
      brand_watermark: '任务商城 · 校园生活', brand_color: '#748895',
      footer_text: '任务商城 · 校园成长与奖励平台', footer_color: '#ffffff',
    },
  }
}

// 打开弹层：先渲染默认模板，再尝试拉取后端模板，最后绘制
async function preparePoster() {
  posterReady.value = false
  previewImage.value = ''
  template.value = createDefaultTemplate()
  fitCanvas()

  if (props.templateCode) {
    try {
      const resp = await getPosterTemplate(props.templateCode)
      if (resp.template) template.value = resp.template
    } catch {
      // 模板接口失败时沿用默认模板，不阻塞体验
    }
  }

  if (!props.visible) return
  // 二维码优先取小程序码；小程序未上线时自动回退 H5 链接
  posterQr.value = await loadPosterQr(props.qrTargetType, props.qrTargetId, props.link)
  if (!props.visible) return
  fitCanvas()
  await nextTick()
  // 等待弹层与 canvas 节点渲染完成后再绘制
  setTimeout(() => { if (props.visible) drawPoster() }, 80)
}

// 根据屏幕宽度与模板比例计算展示尺寸
function fitCanvas() {
  const system = uni.getSystemInfoSync()
  const baseW = template.value.canvas_width || 330
  const baseH = template.value.canvas_height || 520
  const fitW = Math.max(300, Math.min(360, system.windowWidth - 48))
  posterWidth.value = fitW
  posterHeight.value = Math.round(fitW * (baseH / baseW))
}

function drawPoster() {
  if (!props.visible) return
  const tpl = template.value
  const bgImage = tpl.background_type === 'image'
    ? (String(tpl.background_value?.image_url || '') || props.imageUrl)
    : ''

  if (bgImage) {
    uni.getImageInfo({
      src: bgImage,
      success: (info) => {
        if (!props.visible) return
        const ctx = uni.createCanvasContext(props.canvasId)
        paint(ctx, { path: info.path, width: info.width, height: info.height })
        ctx.draw(false, () => { posterReady.value = true })
      },
      fail: () => {
        if (!props.visible) return
        const ctx = uni.createCanvasContext(props.canvasId)
        paint(ctx, null)
        ctx.draw(false, () => { posterReady.value = true })
      },
    })
    return
  }

  const ctx = uni.createCanvasContext(props.canvasId)
  paint(ctx, null)
  ctx.draw(false, () => { posterReady.value = true })
}

function roundedRect(ctx: UniApp.CanvasContext, x: number, y: number, width: number, height: number, radius: number) {
  const r = Math.min(radius, width / 2, height / 2)
  ctx.beginPath(); ctx.moveTo(x + r, y); ctx.lineTo(x + width - r, y); ctx.arcTo(x + width, y, x + width, y + r, r); ctx.lineTo(x + width, y + height - r); ctx.arcTo(x + width, y + height, x + width - r, y + height, r); ctx.lineTo(x + r, y + height); ctx.arcTo(x, y + height, x, y + height - r, r); ctx.lineTo(x, y + r); ctx.arcTo(x, y, x + r, y, r); ctx.closePath()
}

// 按字符数把长文本拆成两行，超出部分截断
function posterLines(value: string, limit = 14) {
  const chars = Array.from(value || '')
  return [chars.slice(0, limit).join(''), chars.slice(limit, limit * 2).join('')].filter(Boolean)
}

function applyBackground(ctx: UniApp.CanvasContext, width: number, height: number, scale: number, tpl: PosterTemplate) {
  const bg = tpl.background_value || {}
  if (tpl.background_type === 'solid') {
    ctx.setFillStyle((bg.color as string) || '#ffffff'); ctx.fillRect(0, 0, width, height)
    return
  }
  const stops = Array.isArray(bg.stops) && bg.stops.length >= 2 ? (bg.stops as string[]) : ['#0e799d', '#19c6d1', '#76df63']
  const gradient = ctx.createLinearGradient(0, 0, width, height)
  stops.forEach((color, index) => gradient.addColorStop(index / Math.max(1, stops.length - 1), color))
  ctx.setFillStyle(gradient); ctx.fillRect(0, 0, width, height)
  // 右上角圆形装饰
  ctx.setFillStyle('rgba(255,255,255,.14)'); ctx.beginPath(); ctx.arc(scale * 288, scale * 56, scale * 82, 0, Math.PI * 2); ctx.fill()
}

function paint(ctx: UniApp.CanvasContext, bg: { path: string; width: number; height: number } | null) {
  const tpl = template.value
  const width = posterWidth.value
  const height = posterHeight.value
  const scale = width / 330
  const px = (value: number) => value * scale
  const banner = tpl.banner_config || {}
  const body = tpl.body_config || {}
  const qr = tpl.qrcode_config || {}

  // 背景：图片背景按 cover 铺满，避免拉伸变形
  if (bg) {
    const imgRatio = bg.width / bg.height
    const canvasRatio = width / height
    let drawW = width
    let drawH = height
    let drawX = 0
    let drawY = 0
    if (imgRatio > canvasRatio) { drawW = height * imgRatio; drawX = (width - drawW) / 2 }
    else { drawH = width / imgRatio; drawY = (height - drawH) / 2 }
    ctx.drawImage(bg.path, drawX, drawY, drawW, drawH)
  } else {
    applyBackground(ctx, width, height, scale, tpl)
  }

  // 顶部 Banner 标签
  const labelBg = (banner.label_bg as string) || '#ffdb4d'
  const labelColor = (banner.label_color as string) || '#10213a'
  const titleColor = (banner.title_color as string) || '#ffffff'
  const labelText = (banner.label_text as string) || 'TASK MALL · CAMPUS'
  roundedRect(ctx, px(22), px(23), px(112), px(24), px(12)); ctx.setFillStyle(labelBg); ctx.fill()
  ctx.setFillStyle(labelColor); ctx.setFontSize(px(11)); ctx.setTextAlign('center')
  ctx.fillText(labelText, px(78), px(39)); ctx.setTextAlign('left')

  // Banner 小标题 + 主标题
  ctx.setFillStyle(titleColor); ctx.setFontSize(px(15))
  ctx.fillText((banner.title_text as string) || props.label, px(22), px(75))
  ctx.setFontSize(px(25))
  posterLines(props.title || props.label).forEach((line, index) => ctx.fillText(line, px(22), px(116 + index * 34)))

  // 主体白卡
  const cardBg = (body.card_bg as string) || 'rgba(255,255,255,.96)'
  const categoryColor = (body.category_color as string) || '#60798d'
  const contentColor = (body.content_color as string) || '#173c58'
  ctx.setFillStyle(cardBg); roundedRect(ctx, px(18), px(188), px(294), px(296), px(24)); ctx.fill()
  if (props.label) {
    ctx.setFillStyle(categoryColor); ctx.setFontSize(px(12))
    ctx.fillText(`# ${props.label}`, px(38), px(220))
  }
  if (props.content) {
    ctx.setFillStyle(contentColor); ctx.setFontSize(px(16))
    posterLines(props.content, 18).slice(0, 2).forEach((line, index) => ctx.fillText(line, px(38), px(252 + index * 25)))
  }

  // CTA 小标签
  ctx.setFillStyle('#edf8fc'); roundedRect(ctx, px(36), px(315), px(258), px(48), px(14)); ctx.fill()
  ctx.setFillStyle((qr.cta_color as string) || '#173c58'); ctx.setFontSize(px(12))
  ctx.fillText((qr.cta_text as string) || '扫码查看详情', px(51), px(345))

  // 二维码：优先绘制微信小程序码，回退到 H5 链接本地二维码
  const qrImage = posterQr.value.imagePath
  const qrLink = posterQr.value.link
  if (qrImage || qrLink) {
    const qrSize = px(86); const qrX = px(38); const qrY = px(382)
    ctx.setFillStyle('#fff'); ctx.fillRect(qrX - px(7), qrY - px(7), qrSize + px(14), qrSize + px(14))
    if (qrImage) {
      ctx.drawImage(qrImage, qrX, qrY, qrSize, qrSize)
    } else if (qrLink) {
      const qrc = qrcode(0, 'M'); qrc.addData(qrLink); qrc.make()
      const cells = qrc.getModuleCount(); const cell = qrSize / cells
      ctx.setFillStyle('#11344f')
      for (let row = 0; row < cells; row += 1) {
        for (let col = 0; col < cells; col += 1) {
          if (qrc.isDark(row, col)) ctx.fillRect(qrX + col * cell, qrY + row * cell, Math.ceil(cell), Math.ceil(cell))
        }
      }
    }
  }

  // 底部辅助文案与品牌水印
  ctx.setFillStyle((qr.helper_color as string) || '#173c58'); ctx.setFontSize(px(15))
  ctx.fillText((qr.helper_text as string) || '扫码查看详情，分享给朋友', px(150), px(414))
  ctx.setFillStyle((qr.brand_color as string) || '#748895'); ctx.setFontSize(px(10))
  ctx.fillText((qr.brand_watermark as string) || '任务商城 · 校园生活', px(150), px(438))
  ctx.setFillStyle((qr.footer_color as string) || '#fff'); ctx.setFontSize(px(10))
  ctx.fillText((qr.footer_text as string) || '任务商城 · 校园成长与奖励平台', px(22), height - px(17))
}

function savePoster() {
  if (!posterReady.value) return
  uni.canvasToTempFilePath({
    canvasId: props.canvasId,
    width: posterWidth.value,
    height: posterHeight.value,
    destWidth: posterWidth.value * 3,
    destHeight: posterHeight.value * 3,
    success: (result) => {
      // #ifdef H5
      // H5 无法直接写入相册，展示图片引导用户长按保存
      previewImage.value = result.tempFilePath
      uni.showToast({ title: '长按图片可保存', icon: 'none' })
      // #endif
      // #ifndef H5
      uni.saveImageToPhotosAlbum({
        filePath: result.tempFilePath,
        success: () => uni.showToast({ title: '海报已保存', icon: 'success' }),
        fail: () => uni.showToast({ title: '请允许保存到相册', icon: 'none' }),
      })
      // #endif
    },
    fail: () => uni.showToast({ title: '海报生成失败，请重试', icon: 'none' }),
  })
}

function copyLink() {
  if (!props.link) return
  uni.setClipboardData({ data: props.link, success: () => uni.showToast({ title: '链接已复制', icon: 'success' }) })
}
</script>

<style scoped>
.poster-mask{position:fixed;z-index:90;inset:0;display:flex;align-items:center;justify-content:center;padding:28rpx;box-sizing:border-box;background:rgba(23,26,23,.45)}
.poster-sheet{width:100%;max-width:720rpx;padding:30rpx 28rpx 28rpx;box-sizing:border-box;border-radius:36rpx;background:#fff;box-shadow:0 30rpx 80rpx rgba(23,26,23,.25)}
.poster-head{display:flex;align-items:center;justify-content:space-between}
.poster-kicker,.poster-title{display:block}
.poster-kicker{color:#08A152;font-size:22rpx;font-weight:800;letter-spacing:2rpx}
.poster-title{margin-top:6rpx;color:#191B1F;font-size:34rpx;font-weight:800}
.poster-close{width:62rpx;height:62rpx;margin:0;padding:0;border:0;border-radius:50%;background:#F2F4F3;color:#767C85;font-size:34rpx;line-height:58rpx}
.poster-close::after{border:0}
.poster-preview{position:relative;margin:24rpx auto 0;overflow:hidden;border-radius:24rpx;background:#F7F9F8}
.poster-canvas{display:block}
.poster-loading{position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:18rpx;background:rgba(244,246,244,.94);color:#767C85;font-size:26rpx}
.poster-loading-dot{width:42rpx;height:42rpx;border:6rpx solid #E7F9EF;border-top-color:#0BBF63;border-radius:50%;animation:poster-spin .8s linear infinite}
.poster-tip{display:block;margin-top:28rpx;padding:20rpx 22rpx;border-radius:20rpx;background:#F7F9F8;color:#767C85;font-size:24rpx;line-height:1.5}
.poster-actions{display:grid;grid-template-columns:1fr 1.35fr;gap:16rpx;margin-top:20rpx}
.poster-copy,.poster-save{height:80rpx;margin:0;border:0;border-radius:999rpx;font-size:26rpx;font-weight:700;line-height:80rpx}
.poster-copy::after,.poster-save::after{border:0}
.poster-copy{background:#F2F4F3;color:#767C85}
.poster-save{background:linear-gradient(135deg,#12D978,#08A152);color:#fff}
.poster-save[disabled]{background:#EDEFEF;color:#AEB3BB}
.poster-image-mask{position:fixed;z-index:99;inset:0;display:flex;align-items:center;justify-content:center;padding:40rpx;box-sizing:border-box;background:rgba(0,0,0,.72)}
.poster-image-card{display:flex;flex-direction:column;align-items:center;gap:22rpx;max-height:100%}
.poster-image{display:block;max-width:100%;max-height:72vh;border-radius:20rpx}
.poster-image-tip{color:#fff;font-size:26rpx}
.poster-image-done{height:72rpx;margin:0;padding:0 56rpx;border:0;border-radius:999rpx;background:#fff;color:#191B1F;font-size:28rpx;font-weight:700;line-height:72rpx}
.poster-image-done::after{border:0}
@keyframes poster-spin{to{transform:rotate(360deg)}}
</style>
