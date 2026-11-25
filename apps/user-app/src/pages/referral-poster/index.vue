<template>
  <view class="page poster-page">
    <ModulePlaceholder v-if="modules.resolved && !moduleEnabled" code="module_referrals" />
    <view v-else-if="!modules.resolved" class="mp-skeleton">
      <view class="tm-skeleton" style="height: 200rpx; border-radius: 24rpx; margin: 24rpx 28rpx;" />
      <view class="tm-skeleton" style="height: 320rpx; border-radius: 24rpx; margin: 20rpx 28rpx;" />
    </view>
    <template v-else>
    <view class="poster-subnav">
      <view class="poster-back" @click="goBack"><text>‹</text></view>
      <text class="poster-nav-title">推荐海报</text>
      <text class="poster-nav-rule" @click="showRules">规则</text>
    </view>

    <view class="poster-card">
      <view class="poster-logo">校</view>
      <text class="poster-name">校圈</text>
      <text class="poster-slogan">做任务赚积分 · 好物送到宿舍</text>
      <view class="poster-deco"><text>校园任务</text><text>积分商城</text><text>闲置交易</text></view>
      <view class="poster-qr"><view class="qr-inner"><text class="qr-mark">码</text></view></view>
      <text class="poster-hint">长按识别小程序码，和我一起赚积分</text>
    </view>
    <view class="poster-btns">
      <button class="btn-save" :disabled="!referralCode" @click="openPoster">保存图片</button>
      <button class="btn-share" :disabled="!referralCode" @click="shareWechat">分享给好友</button>
    </view>

    <view v-if="loading && !dashboard" class="state-card" role="status" aria-live="polite"><text>正在加载邀请信息…</text></view>
    <view v-if="loadError" class="state-card error-state" role="alert"><text>{{ loadError }}</text><button @click="load">重新加载</button></view>

    <view class="poster-hero"><image class="poster-hero-art" src="/static/home/hero-campus-reward.png" mode="aspectFit" /><view class="hero-copy"><text class="hero-title">邀请好友，一起成长</text><text class="hero-subtitle">邀请好友加入，奖励自动进入积分账户</text></view><view class="hero-reward"><text>{{ rewardPoints }}</text><small>积分 / 人</small></view><view class="code-box"><text>我的邀请码</text><view><text>{{ displayReferralCode }}</text><button :disabled="!referralCode" @click="copyCode">复制邀请码</button></view></view><view class="hero-actions"><button class="generate-button" :disabled="!referralCode" @click="openPoster">▣　生成邀请海报<small>分享海报，邀请更方便</small></button><button class="copy-link-button" :disabled="!referralCode" @click="copyLink"><text class="chain-icon">↗</text><view><text>复制链接</text><small>复制链接，快速分享</small></view></button></view></view>

    <view class="section-card rules-card"><view class="section-heading"><text class="section-icon">♢</text><view><text class="section-title">邀请好友奖励规则</text><text class="section-subtitle">好友完成全部条件，奖励自动到账，每人仅可获得一次奖励</text></view></view><view class="rule-flow"><view><view class="flow-icon">♙+</view><text>好友通过你的<br />专属邀请加入</text></view><text class="arrow">→</text><view><view class="flow-icon">✓</view><text>好友完成全部<br />配置条件</text></view><text class="arrow">→</text><view><view class="flow-icon">▣</view><text>奖励自动发放至<br />你的积分账户</text></view></view><view class="rule-note">ⓘ　禁止刷单、虚假邀请等违规行为，发现后将取消奖励资格并进行处理。详情见《用户协议》和《隐私政策》。</view></view>

    <view class="section-card progress-card"><view class="section-heading"><text class="section-icon people">♙♙</text><view><text class="section-title">邀请进度</text></view></view><view class="metrics"><view><strong>{{ invitedCount }}</strong><text>已邀请</text></view><view><strong>{{ rewardedCount }}</strong><text>已完成</text></view><view><strong>{{ rewardTotal }}</strong><text>可得积分</text></view></view><view v-if="!items.length" class="empty-progress"><view class="empty-art"><text>➤</text></view><view><text class="empty-title">还没有邀请记录</text><text class="empty-copy">快去分享邀请好友，完成条件后可获得积分奖励~</text></view></view><view v-else class="friend-list"><view v-for="item in items" :key="item.id" class="friend-row"><view class="friend-avatar">{{ item.referred_user.nickname.slice(0, 1) }}</view><view><text>{{ item.referred_user.nickname }}</text><small>{{ statusText(item.status) }}</small></view></view></view></view>

    <view class="section-card quick-share"><view class="section-heading"><text class="section-icon">↗</text><view><text class="section-title">快速分享</text><text class="section-subtitle">选择以下方式，快速分享给好友</text></view></view><view class="share-grid"><button v-for="item in shareOptions" :key="item.key" :class="['share-option', item.tone]" :disabled="!referralCode" @click="item.action"><text class="share-option-icon">{{ item.icon }}</text><text>{{ item.label }}</text></button></view></view>

    <view v-if="posterVisible" class="poster-mask" @click.self="closePoster"><view class="poster-sheet"><view class="sheet-head"><text>邀请海报</text><button @click="closePoster">×</button></view><view class="poster-preview" :style="posterPreviewStyle"><canvas id="referralPosterCanvas" canvas-id="referralPosterCanvas" class="poster-canvas" :style="posterCanvasStyle" :width="posterWidth" :height="posterHeight" /><view v-if="!posterReady" class="poster-loading">正在生成专属海报…</view></view><text class="poster-tip">海报已带上你的邀请码，好友扫码后即可进入邀请页面。</text><view class="sheet-actions"><button @click="copyLink">复制邀请链接</button><button class="save-button" :disabled="!posterReady" @click="savePoster">保存海报</button></view></view></view>
    </template>
  </view>
</template>

<script setup lang="ts">
import { onShareAppMessage, onShow } from '@dcloudio/uni-app'
import qrcode from 'qrcode-generator'
import { computed, nextTick, ref } from 'vue'
import { getReferrals, type ReferralDashboard } from '@/api/referrals'
import { useAuthStore } from '@/stores/auth'
import { navigateToLogin } from '@/utils/authRedirect'
import { loadPosterQr, type PosterQr } from '@/utils/posterQr'
import ModulePlaceholder from '@/components/ModulePlaceholder.vue'
import { useModulesStore } from '@/stores/modules'

const modules = useModulesStore()
const moduleEnabled = computed(() => modules.isEnabled('module_referrals'))

const auth = useAuthStore()
const dashboard = ref<ReferralDashboard | null>(null)
const loading = ref(false)
const loadError = ref('')
const posterVisible = ref(false)
const posterReady = ref(false)
const posterQr = ref<PosterQr>({ link: '' })
const posterWidth = ref(330)
const posterHeight = ref(520)
const referralCode = computed(() => dashboard.value?.referral_code || '')
const rewardPoints = computed(() => dashboard.value?.rules.reward.points ?? 0)
const items = computed(() => dashboard.value?.items || [])
const invitedCount = computed(() => dashboard.value?.summary.invited_count ?? 0)
const rewardedCount = computed(() => dashboard.value?.summary.rewarded_count ?? 0)
const rewardTotal = computed(() => dashboard.value?.summary.reward_points_total ?? 0)
const displayReferralCode = computed(() => referralCode.value ? `********${referralCode.value.slice(-2)}` : '加载中')
const sharePath = computed(() => dashboard.value?.share.path || '/pages/referrals/index')
const posterCanvasStyle = computed(() => `width:${posterWidth.value}px;height:${posterHeight.value}px`)
const posterPreviewStyle = computed(() => `width:${posterWidth.value}px;height:${posterHeight.value}px`)
const shareOptions = [
  { key: 'wechat', label: '好友', icon: '●', tone: 'wechat', action: () => shareWechat() },
  { key: 'moments', label: '朋友圈', icon: '◉', tone: 'moments', action: () => copyLink() },
  { key: 'qq', label: 'QQ 好友', icon: '♟', tone: 'qq', action: () => copyLink() },
  { key: 'space', label: 'QQ 空间', icon: '★', tone: 'space', action: () => copyLink() },
  { key: 'copy', label: '复制链接', icon: '↗', tone: 'copy', action: () => copyLink() },
]

onShow(async () => { await modules.ensureReady(); if (!moduleEnabled.value) return; if (!auth.isAuthenticated) { navigateToLogin(); return }; void load() })
onShareAppMessage(() => ({ title: shareTitle(), path: sharePath.value }))

async function load() {
  if (!auth.accessToken || loading.value) return
  loading.value = true
  loadError.value = ''
  try { dashboard.value = await getReferrals(auth.accessToken) }
  catch (error) { loadError.value = errorMessage(error, '邀请信息加载失败，请稍后重试'); uni.showToast({ title: loadError.value, icon: 'none' }) }
  finally { loading.value = false }
}

function goBack() { const pages = getCurrentPages(); if (pages.length > 1) uni.navigateBack({ delta: 1 }); else uni.redirectTo({ url: '/pages/referrals/index' }) }
function showRules() { uni.showModal({ title: '邀请规则说明', content: '好友通过你的邀请加入并完成后台配置的全部条件后，奖励会自动到账。每位好友仅奖励一次。', showCancel: false }) }
function copyCode() { if (referralCode.value) uni.setClipboardData({ data: referralCode.value, success: () => uni.showToast({ title: '邀请码已复制', icon: 'success' }) }) }
function inviteLink() { let link = sharePath.value; const publicBase = String(import.meta.env.VITE_H5_PUBLIC_URL || '').trim().replace(/\/$/, ''); if (publicBase) link = `${publicBase}/#${sharePath.value}`; /* #ifdef H5 */ if (!publicBase) link = `${window.location.origin}/#${sharePath.value}`; /* #endif */ return link }
function copyLink() { if (referralCode.value) uni.setClipboardData({ data: inviteLink(), success: () => uni.showToast({ title: '邀请链接已复制', icon: 'success' }) }) }
function shareWechat() { if (!referralCode.value) return; uni.showToast({ title: '请使用右上角分享给好友', icon: 'none' }) }
async function openPoster() { if (!referralCode.value) return uni.showToast({ title: '邀请码加载完成后再生成海报', icon: 'none' }); const system = uni.getSystemInfoSync(); posterWidth.value = Math.max(280, Math.min(360, system.windowWidth - 56)); posterHeight.value = Math.round(posterWidth.value * 1.56); posterVisible.value = true; posterReady.value = false; posterQr.value = await loadPosterQr('referral', referralCode.value, inviteLink()); void nextTick(() => setTimeout(drawPoster, 80)) }
function closePoster() { posterVisible.value = false }
function roundedRect(context: UniApp.CanvasContext, x: number, y: number, width: number, height: number, radius: number, color: string) { context.beginPath(); context.moveTo(x + radius, y); context.lineTo(x + width - radius, y); context.arcTo(x + width, y, x + width, y + radius, radius); context.lineTo(x + width, y + height - radius); context.arcTo(x + width, y + height, x + width - radius, y + height, radius); context.lineTo(x + radius, y + height); context.arcTo(x, y + height, x, y + height - radius, radius); context.lineTo(x, y + radius); context.arcTo(x, y, x + radius, y, radius); context.closePath(); context.setFillStyle(color); context.fill() }
function drawPoster() {
  const width = posterWidth.value; const height = posterHeight.value; const context = uni.createCanvasContext('referralPosterCanvas'); const gradient = context.createLinearGradient(0, 0, width, height); gradient.addColorStop(0, '#12D978'); gradient.addColorStop(1, '#08A152'); context.setFillStyle(gradient); context.fillRect(0, 0, width, height); context.setFillStyle('rgba(255,255,255,.14)'); context.beginPath(); context.arc(width - 35, 45, 70, 0, Math.PI * 2); context.fill(); context.setFillStyle('#fff'); context.setTextAlign('left'); context.setFontSize(Math.round(width * .078)); context.fillText('邀请好友', 24, 70); context.setFontSize(Math.round(width * .065)); context.fillText('一起成长', 24, 110); context.setFillStyle('rgba(255,255,255,.85)'); context.setFontSize(Math.round(width * .039)); context.fillText(`每人奖励 ${rewardPoints.value} 积分`, 24, 142); roundedRect(context, 20, 166, width - 40, height - 188, 22, '#fff'); context.setFillStyle('#10213a'); context.setFontSize(Math.round(width * .045)); context.fillText('我的邀请码', 42, 207); context.setFontSize(Math.round(width * .07)); context.fillText(referralCode.value, 42, 250); const size = Math.min(174, Math.round(width * .5)); const x = Math.round((width - size) / 2); const y = 280; roundedRect(context, x - 12, y - 12, size + 24, size + 24, 17, '#f7fbff'); if (posterQr.value.imagePath) { context.drawImage(posterQr.value.imagePath, x, y, size, size) } else { const qr = qrcode(0, 'M'); qr.addData(posterQr.value.link || inviteLink()); qr.make(); const count = qr.getModuleCount(); const cell = size / count; context.setFillStyle('#10213a'); for (let row = 0; row < count; row += 1) for (let col = 0; col < count; col += 1) if (qr.isDark(row, col)) context.fillRect(x + Math.floor(col * cell), y + Math.floor(row * cell), Math.ceil(cell), Math.ceil(cell)) } context.setTextAlign('center'); context.setFillStyle('#748099'); context.setFontSize(Math.round(width * .036)); context.fillText('扫码进入邀请页', width / 2, y + size + 38); context.setFillStyle('#10213a'); context.setFontSize(Math.round(width * .046)); context.fillText(`奖励 ${rewardPoints.value} 积分`, width / 2, y + size + 67); context.draw(false, () => { posterReady.value = true })
}
function savePoster() { if (!posterReady.value) return; uni.canvasToTempFilePath({ canvasId: 'referralPosterCanvas', width: posterWidth.value, height: posterHeight.value, destWidth: posterWidth.value * 2, destHeight: posterHeight.value * 2, fileType: 'png', quality: 1, success: (result) => { /* #ifdef H5 */ const anchor = document.createElement('a'); anchor.href = result.tempFilePath; anchor.download = `校园邀请海报-${referralCode.value}.png`; document.body.appendChild(anchor); anchor.click(); document.body.removeChild(anchor); uni.showToast({ title: '海报已下载', icon: 'success' }); /* #endif */ /* #ifndef H5 */ uni.saveImageToPhotosAlbum({ filePath: result.tempFilePath, success: () => uni.showToast({ title: '海报已保存到相册', icon: 'success' }), fail: () => uni.showToast({ title: '请允许访问相册后再保存', icon: 'none' }) }); /* #endif */ }, fail: () => uni.showToast({ title: '海报生成失败，请重试', icon: 'none' }) }) }
function shareTitle() { return `邀请你加入任务商城，完成条件可得 ${rewardPoints.value} 积分奖励` }
function statusText(value: string) { return ({ tracking: '进行中', rewarded: '奖励已到账', qualified_cap_reached: '已达标', revoked: '已失效' } as Record<string, string>)[value] || '进度跟踪中' }
function errorMessage(error: unknown, fallback: string) { return error instanceof Error ? error.message : fallback }
</script>

<style scoped>
.page{min-height:100vh;box-sizing:border-box;padding:0 26rpx 48rpx;background:linear-gradient(180deg,#fff 0,#f5fbff 410rpx,#f6faff 100%);color:#10213a}.page-header{display:flex;height:96rpx;align-items:center;justify-content:space-between;margin:0 -9rpx}.page-title{font-size:21px;font-weight:900}.back-button{width:70rpx;height:70rpx;margin:0;padding:0;border:0;background:transparent;color:#10213a;font-size:35px;line-height:58rpx}.rules-button{height:60rpx;margin:0;padding:0;border:0;background:transparent;color:#56677f;font-size:13px;line-height:60rpx}.state-card{margin-top:18rpx;padding:26rpx;border:1rpx solid #dce9ef;border-radius:20rpx;background:#fff;color:#748099;text-align:center;font-size:13px}.state-card button{height:52rpx;margin:15rpx auto 0;padding:0 18rpx;border:1rpx solid currentColor;border-radius:999rpx;background:#fff;color:inherit;font-size:13px;line-height:50rpx}.error-state{color:#b42318;background:#fff5f2}.poster-hero{position:relative;overflow:hidden;margin:0 -1rpx;border-radius:30rpx;background:linear-gradient(135deg,#12D978,#08A152);box-shadow:0 16rpx 34rpx rgba(11,191,99,.22);color:#fff}.poster-hero::after{content:'';position:absolute;inset:0;background:linear-gradient(90deg,rgba(8,161,82,.98) 0%,rgba(8,161,82,.85) 44%,rgba(8,161,82,.06) 100%)}.hero-copy{position:relative;z-index:1;padding:49rpx 37rpx 34rpx}.hero-title{display:block;font-size:24px;font-weight:900;line-height:1.25}.hero-subtitle{display:block;margin-top:12rpx;color:rgba(255,255,255,.86);font-size:13px}.hero-reward{position:absolute;z-index:2;top:45rpx;right:22rpx;display:flex;width:118rpx;height:94rpx;align-items:center;justify-content:center;flex-direction:column;border-radius:17rpx;background:#ffe05e;color:#10213a}.hero-reward text{font-size:22px;font-weight:900}.hero-reward small{margin-top:2rpx;font-size:12px}.code-box{position:relative;z-index:1;margin:23rpx 38rpx 0;padding:22rpx 23rpx;border-radius:18rpx;background:#fff;color:#10213a}.code-box>text{display:block;color:#60728c;font-size:13px}.code-box>view{display:flex;align-items:center;justify-content:space-between;margin-top:5rpx}.code-box>view>text{font-size:19px;font-weight:900;letter-spacing:2rpx}.code-box button{height:55rpx;margin:0;padding:0 16rpx;border:1rpx solid #0BBF63;border-radius:999rpx;background:#fff;color:#08A152;font-size:13px;line-height:53rpx}.hero-actions{position:relative;z-index:1;display:grid;grid-template-columns:1fr 1fr;gap:13rpx;margin:23rpx 20rpx 19rpx}.hero-actions button{height:102rpx;margin:0;border-radius:22rpx;font-weight:850}.generate-button{border:0;background:linear-gradient(135deg,#09ad73,#06b98f);box-shadow:0 8rpx 18rpx rgba(7,173,112,.2);color:#fff;font-size:14px}.generate-button small,.copy-link-button small{display:block;margin-top:5rpx;font-size:12px;font-weight:500}.copy-link-button{display:flex;align-items:center;justify-content:center;gap:12rpx;border:2rpx solid #0BBF63;background:#fff;color:#191B1F;font-size:14px}.copy-link-button small{color:#767C85}.chain-icon{font-size:24px;color:#08A152}.hero-actions button[disabled]{opacity:.5}.section-card{margin-top:20rpx;padding:27rpx;border:1rpx solid #dbe8f0;border-radius:27rpx;background:rgba(255,255,255,.98);box-shadow:0 10rpx 27rpx rgba(31,56,92,.06)}.section-heading{display:flex;align-items:center;gap:14rpx}.section-icon{display:flex;width:48rpx;height:48rpx;align-items:center;justify-content:center;flex:none;color:#08A152;font-size:19px}.section-icon.people{font-size:17px}.section-title{display:block;font-size:17px;font-weight:900}.section-subtitle{display:block;margin-top:6rpx;color:#71819a;font-size:13px;line-height:1.45}.rule-flow{display:flex;align-items:flex-start;justify-content:space-between;margin-top:28rpx}.rule-flow>view{display:flex;min-width:0;flex:1;align-items:center;flex-direction:column;text-align:center}.flow-icon{display:flex;width:67rpx;height:67rpx;align-items:center;justify-content:center;border-radius:50%;background:#E7F9EF;color:#0BBF63;font-size:21px}.rule-flow>view:nth-of-type(2) .flow-icon{color:#0BBF63}.rule-flow>view>text{display:block;margin-top:11rpx;color:#203855;font-size:13px;line-height:1.55}.arrow{padding-top:25rpx;color:#aab7c8;font-size:22px}.rule-note{margin:24rpx -27rpx -21rpx;padding:20rpx 27rpx;border-top:1rpx solid #e2ebf1;color:#70819a;font-size:13px;line-height:1.55}.progress-card{padding-bottom:27rpx}.metrics{display:grid;grid-template-columns:repeat(3,1fr);margin-top:22rpx}.metrics>view{display:flex;align-items:center;flex-direction:column;border-right:1rpx solid #dce5ef}.metrics>view:last-child{border-right:0}.metrics strong{font-size:21px}.metrics text{margin-top:4rpx;color:#71819a;font-size:13px}.empty-progress{display:flex;align-items:center;margin-top:25rpx;padding:23rpx;border:1rpx solid #ebf0f6;border-radius:18rpx;background:#f8fbff}.empty-art{display:flex;width:127rpx;height:110rpx;align-items:center;justify-content:center;margin-right:16rpx;background:radial-gradient(circle at 50% 45%,#cdeed9 0 28%,transparent 29%),linear-gradient(140deg,transparent 35%,#d9f2e2 36% 58%,transparent 59%);color:#08A152;font-size:28px;transform:rotate(-10deg)}.empty-title,.empty-copy{display:block}.empty-title{font-size:15px;font-weight:850}.empty-copy{margin-top:9rpx;color:#6f8099;font-size:13px;line-height:1.5}.friend-list{margin-top:19rpx}.friend-row{display:flex;align-items:center;gap:12rpx;padding:14rpx 0;border-top:1rpx solid #e4edf4}.friend-avatar{display:flex;width:48rpx;height:48rpx;align-items:center;justify-content:center;border-radius:15rpx;background:#E7F9EF;color:#08A152;font-weight:900}.friend-row text,.friend-row small{display:block}.friend-row text{font-size:13px;font-weight:750}.friend-row small{margin-top:4rpx;color:#748099;font-size:12px}.quick-share{padding-bottom:27rpx}.share-grid{display:grid;grid-template-columns:repeat(5,1fr);gap:10rpx;margin-top:23rpx}.share-option{display:flex;height:115rpx;align-items:center;justify-content:center;flex-direction:column;margin:0;padding:7rpx 3rpx;border:1rpx solid #e0eaf3;border-radius:17rpx;background:#f9fbff;color:#667895;font-size:12px;line-height:1.35}.share-option-icon{display:flex;width:52rpx;height:52rpx;align-items:center;justify-content:center;margin-bottom:8rpx;border-radius:17rpx;font-size:17px}.share-option.wechat .share-option-icon{background:#E7F9EF;color:#08A152}.share-option.moments .share-option-icon{background:#E7F9EF;color:#0BBF63}.share-option.qq .share-option-icon{background:#e9f1ff;color:#317be7}.share-option.space .share-option-icon{background:#e9f1ff;color:#317be7}.share-option.copy .share-option-icon{background:#eff3f8;color:#70809b}.share-option[disabled]{opacity:.48}.poster-mask{position:fixed;z-index:1200;inset:0;display:flex;align-items:flex-end;justify-content:center;padding:36rpx 22rpx;background:rgba(9,35,57,.5);backdrop-filter:blur(8rpx)}.poster-sheet{width:100%;padding:24rpx;border-radius:27rpx;background:#fff;box-shadow:0 24rpx 60rpx rgba(13,67,91,.2)}.sheet-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:17rpx;font-size:17px;font-weight:900}.sheet-head button{width:56rpx;height:56rpx;margin:0;padding:0;border:0;border-radius:50%;background:#eef3f8;color:#596c83;font-size:22px;line-height:52rpx}.poster-preview{position:relative;max-width:100%;margin:0 auto;overflow:hidden;border-radius:20rpx;background:#247bed}.poster-canvas{display:block;max-width:100%}.poster-loading{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,#247bed,#16b8aa);color:#fff;font-size:13px}.poster-tip{display:block;margin-top:15rpx;color:#71819a;text-align:center;font-size:12px}.sheet-actions{display:flex;gap:12rpx;margin-top:17rpx}.sheet-actions button{height:68rpx;flex:1;margin:0;border:1rpx solid #EDEFEF;border-radius:16rpx;background:#fff;color:#08A152;font-size:13px;line-height:66rpx}.sheet-actions .save-button{border:0;background:linear-gradient(135deg,#09ad73,#06b98f);color:#fff}.sheet-actions button[disabled]{opacity:.45}@media screen and (max-width:360px){.page{padding-left:20rpx;padding-right:20rpx}.hero-actions{gap:8rpx;margin-left:13rpx;margin-right:13rpx}.generate-button,.copy-link-button{font-size:13px}.share-grid{gap:5rpx}.share-option{font-size:10px}}
.poster-hero-art{position:absolute;right:0;top:50%;width:365rpx;height:365rpx;transform:translateY(-50%)}
/* ===== 新视觉（设计屏 49 ref-poster） ===== */
.poster-page{--green:#0BBF63;--green-deep:#08A152;--green-soft:#E7F9EF;--t1:#191B1F;--t2:#767C85;--t3:#AEB3BB;--line:#EDEFEF;background:linear-gradient(160deg,#12D978 0%,#08A152 100%)!important;padding:0 32rpx 48rpx;color:#fff;min-height:100vh}
.poster-subnav{display:flex;align-items:center;padding:calc(var(--status-bar-height,20px) + 12rpx) 4rpx 8rpx}
.poster-back{width:64rpx;height:64rpx;display:flex;align-items:center;justify-content:center;font-size:44rpx;color:#fff;line-height:1}
.poster-nav-title{flex:1;text-align:center;font-size:32rpx;font-weight:800;margin-right:64rpx}
.poster-nav-rule{font-size:26rpx;color:rgba(255,255,255,.9);padding:0 8rpx}
.poster-card{position:relative;background:#fff;border-radius:36rpx;margin-top:24rpx;padding:52rpx 36rpx 40rpx;text-align:center;box-shadow:0 24rpx 60rpx rgba(0,0,0,.18);overflow:hidden}
.poster-card::before{content:'';position:absolute;width:240rpx;height:240rpx;border-radius:50%;background:var(--green-soft);top:-110rpx;right:-90rpx}
.poster-logo{position:relative;width:116rpx;height:116rpx;margin:0 auto;border-radius:32rpx;background:linear-gradient(135deg,#12D978,#08A152);color:#fff;font-size:54rpx;font-weight:900;display:flex;align-items:center;justify-content:center;box-shadow:0 14rpx 30rpx rgba(11,191,99,.3)}
.poster-name{position:relative;display:block;margin-top:28rpx;font-size:40rpx;font-weight:800;letter-spacing:6rpx;color:var(--t1)}
.poster-slogan{position:relative;display:block;margin-top:16rpx;font-size:25rpx;color:var(--t2);letter-spacing:2rpx}
.poster-deco{position:relative;display:flex;justify-content:center;gap:12rpx;margin-top:28rpx}
.poster-deco text{height:44rpx;padding:0 20rpx;border-radius:22rpx;background:var(--green-soft);color:var(--green-deep);font-size:20rpx;font-weight:600;display:flex;align-items:center}
.poster-qr{position:relative;width:256rpx;height:256rpx;margin:40rpx auto 0;border-radius:28rpx;border:2rpx solid var(--line);background:#fff;display:flex;align-items:center;justify-content:center}
.qr-inner{width:192rpx;height:192rpx;border-radius:20rpx;background:linear-gradient(135deg,#12D978,#08A152);display:flex;align-items:center;justify-content:center}
.qr-mark{color:#fff;font-size:56rpx;font-weight:800;opacity:.85}
.poster-hint{position:relative;display:block;margin-top:24rpx;font-size:21rpx;color:var(--t3)}
.poster-btns{display:flex;flex-direction:column;gap:20rpx;margin-top:32rpx}
.poster-btns button{height:88rpx;border-radius:44rpx;font-size:30rpx;font-weight:700;display:flex;align-items:center;justify-content:center;margin:0}
.poster-btns button::after{border:0}
.btn-save{background:#fff;color:var(--green-deep);box-shadow:0 12rpx 28rpx rgba(0,0,0,.15)}
.btn-share{background:transparent;color:#fff;border:3rpx solid rgba(255,255,255,.85)}
.poster-btns button[disabled]{opacity:.55}
.state-card{background:rgba(255,255,255,.16);color:#fff;border:0}
.state-card.error-state{background:#fff;color:#B42318}
.poster-hero{margin-top:24rpx;border-radius:32rpx;background:#fff!important;box-shadow:none;color:var(--t1)}
.poster-hero::after{display:none}
.hero-copy{padding:36rpx 32rpx 28rpx}
.hero-title{color:var(--t1);font-size:34rpx}
.hero-subtitle{color:var(--t2);font-size:24rpx}
.hero-reward{background:var(--green-soft);color:var(--green-deep);width:110rpx;height:88rpx;top:36rpx}
.code-box{background:#F7F9F8;color:var(--t1);margin:0 28rpx 24rpx}
.code-box button{border-color:var(--green-deep);color:var(--green-deep)}
.hero-actions{margin:0 24rpx 24rpx}
.generate-button{background:linear-gradient(135deg,#12D978,#08A152)!important;box-shadow:0 10rpx 22rpx rgba(11,191,99,.22)!important}
.copy-link-button{border-color:var(--green-soft);color:var(--green-deep)}
.chain-icon{color:var(--green-deep)}
.section-card{margin-top:20rpx;border:0;border-radius:28rpx;background:#fff;box-shadow:none;padding:30rpx}
.section-icon{color:var(--green-deep)}
.section-title{color:var(--t1)}
.section-subtitle{color:var(--t2)}
.flow-icon{background:var(--green-soft);color:var(--green-deep)}
.rule-flow>view>text{color:var(--t2)}
.arrow{color:var(--t3)}
.rule-note{color:var(--t2)}
.metrics strong{color:var(--t1)}
.metrics text{color:var(--t3)}
.empty-progress{border-color:var(--line);background:#F7F9F8}
.empty-title{color:var(--t1)}
.empty-copy{color:var(--t3)}
.friend-avatar{background:var(--green-soft);color:var(--green-deep);border-radius:50%}
.friend-row text{color:var(--t1)}
.friend-row small{color:var(--t3)}
.share-option{border-color:var(--line);background:#F7F9F8;color:var(--t2)}
.share-option.wechat .share-option-icon,.share-option.moments .share-option-icon{background:var(--green-soft);color:var(--green-deep)}
.share-option.qq .share-option-icon,.share-option.space .share-option-icon{background:#EAF2FE;color:#2F7DE1}
.share-option.copy .share-option-icon{background:#F2F4F3;color:var(--t2)}
.poster-preview,.poster-loading{background:linear-gradient(135deg,#12D978,#08A152)}
.sheet-actions button{color:var(--green-deep);border-color:var(--green-soft)}
.sheet-actions .save-button{background:linear-gradient(135deg,#12D978,#08A152)}
</style>
