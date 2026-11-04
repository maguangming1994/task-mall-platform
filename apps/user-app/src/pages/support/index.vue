<template>
  <view class="page">
    <view class="subnav">
      <view class="back" role="button" aria-label="返回上一页" @click="goBack"><text>‹</text></view>
      <text class="nav-title">帮助与反馈</text>
      <view class="nav-spacer" aria-hidden="true" />
    </view>

    <view class="search-bar">
      <view class="search-box"><text class="search-ic">⌕</text><text>搜索常见问题</text></view>
    </view>

    <view class="card">
      <view class="sec-title"><text class="sec-name">常见问题</text></view>
      <view class="qa-row">如何接单？<text class="chev">›</text></view>
      <view class="qa-row">积分如何获取？<text class="chev">›</text></view>
      <view class="qa-row">如何发布闲置？<text class="chev">›</text></view>
      <view class="qa-row">如何联系卖家？<text class="chev">›</text></view>
    </view>

    <view class="card">
      <view class="sec-title"><text class="sec-name">联系客服</text></view>
      <view class="sp-svc" @click="previewQRCode">
        <view class="svc-ic">服</view>
        <view class="svc-tx"><text class="svc-b">在线客服</text><text class="svc-p">工作日 9:00 - 21:00</text></view>
        <text class="tag tag-green">在线</text>
      </view>
      <view class="sp-svc">
        <view class="svc-ic">☎</view>
        <view class="svc-tx"><text class="svc-b">客服电话</text><text class="svc-p">400-820-1024</text></view>
        <text class="chev">›</text>
      </view>
    </view>

    <view class="card qr-card">
      <view class="sec-title"><text class="sec-name">客服二维码</text><text class="online-pill" :class="{ configured: Boolean(support.qr_code_url) }">{{ support.qr_code_url ? '已配置' : '待配置' }}</text></view>
      <view class="qr-stage">
        <view v-if="loading" class="qr-empty qr-loading" role="status" aria-live="polite">
          <view class="qr-spinner" aria-hidden="true" />
          <text>正在加载客服配置…</text>
        </view>
        <view v-else-if="loadError" class="qr-empty qr-error" role="alert">
          <text class="qr-empty-mark">!</text>
          <strong>客服二维码加载失败</strong>
          <text>{{ loadError }}</text>
          <button class="retry-button" @click="loadSupport">重新加载</button>
        </view>
        <view v-else-if="support.qr_code_url" class="qr-frame" role="button" aria-label="预览客服二维码" @click="previewQRCode">
          <image class="qr-image" :src="support.qr_code_url" mode="aspectFit" :show-menu-by-longpress="true" />
        </view>
        <view v-else class="qr-empty">
          <text class="qr-empty-mark">码</text>
          <strong>客服二维码待配置</strong>
          <text>请管理员在后台“首页悬浮客服”中上传二维码。</text>
        </view>
      </view>

      <text class="scan-tip">{{ support.qr_code_url ? (support.qr_code_tip || '长按二维码识别，或使用微信扫一扫') : '配置后可扫码联系在线客服' }}</text>
      <button v-if="support.qr_code_url" class="preview-button" @click="previewQRCode">查看二维码大图</button>
    </view>

    <view class="card">
      <view class="sec-title"><text class="sec-name">扫码说明</text></view>
      <view class="guide-step"><strong>1</strong><text>长按二维码，选择识别图中二维码</text></view>
      <view class="guide-step"><strong>2</strong><text>进入客服小程序后发送你的问题</text></view>
      <view class="guide-step"><strong>3</strong><text>涉及订单或任务时，请同时提供相关编号</text></view>
    </view>

    <view class="card feedback-card">
      <view class="sec-title"><text class="sec-name">意见反馈</text></view>
      <textarea v-model="feedback" class="feedback-input" placeholder="描述你遇到的问题或建议…" />
      <view class="upload-grid"><view class="upload-add"><text class="up-plus">＋</text><text class="up-text">添加截图 0/3</text></view></view>
    </view>
    <view class="feedback-action"><button class="feedback-btn" @click="submitFeedback">提交反馈</button></view>
    <AppTabBar current-target="account" />
  </view>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { onShow } from '@dcloudio/uni-app'
import { loadHomeContent, type HomeFloatingSupport } from '@/api/home'
import AppTabBar from '@/components/AppTabBar.vue'

const support = ref<HomeFloatingSupport>({
  enabled: true,
  show_label: true,
  icon_text: '服',
  icon_url: '',
  label: '客服二维码',
  subtitle: '扫码添加校园客服',
  qr_code_url: '',
  qr_code_tip: '长按二维码识别，或使用微信扫一扫',
  position: 'right',
  bottom_offset_rpx: 170,
})
const loading = ref(false)
const loadError = ref('')
const feedback = ref('')

function submitFeedback() {
  if (!feedback.value.trim()) {
    uni.showToast({ title: '请描述你遇到的问题或建议', icon: 'none' })
    return
  }
  uni.showModal({ title: '提交反馈', content: '意见反馈提交功能即将开放，当前可通过上方在线客服二维码联系我们。', showCancel: false })
}

onShow(async () => {
  await loadSupport()
})

async function loadSupport() {
  if (loading.value) return
  loading.value = true
  loadError.value = ''
  try {
    const content = await loadHomeContent()
    support.value = { ...support.value, ...(content.floating_support || {}) }
  } catch (error) {
    loadError.value = error instanceof Error ? error.message : '请检查网络后重试'
    uni.showToast({ title: '客服二维码加载失败，请稍后重试', icon: 'none' })
  } finally {
    loading.value = false
  }
}

function previewQRCode() {
  if (!support.value.qr_code_url) return
  uni.previewImage({ current: support.value.qr_code_url, urls: [support.value.qr_code_url] })
}

function goBack() {
  const pages = getCurrentPages()
  if (pages.length > 1) {
    uni.navigateBack()
    return
  }
  uni.reLaunch({ url: '/pages/index/index' })
}
</script>

<style scoped>
.support-topbar{display:flex;align-items:center;justify-content:space-between;height:84rpx;padding:calc(var(--status-bar-height) + 4rpx) 4rpx 0;box-sizing:content-box}.support-back{display:flex;width:64rpx;height:64rpx;align-items:center;justify-content:center;border-radius:18rpx;color:#10283f;font-size:30px;font-weight:350;line-height:1}.support-topbar-spacer{width:64rpx;height:64rpx}.support-page-title{color:#0d2740;font-size:22px;font-weight:950}
.page{min-height:100vh;padding:30rpx 26rpx 100rpx;box-sizing:border-box;background:radial-gradient(circle at 90% 0,rgba(55,211,211,.24),transparent 28%),linear-gradient(180deg,#eefaff 0,#f8fbfc 48%,#f5f8fa 100%)}
.support-hero{position:relative;overflow:hidden;padding:34rpx 32rpx;border-radius:30rpx;background:linear-gradient(128deg,#075eb7 0%,#0b82bd 54%,#168b80 100%);box-shadow:0 18rpx 42rpx rgba(16,86,139,.24);color:#fff}.support-hero::before,.support-hero::after{content:'';position:absolute;border-radius:50%;background:rgba(255,255,255,.09)}.support-hero::before{right:-52rpx;top:-80rpx;width:220rpx;height:220rpx}.support-hero::after{right:104rpx;bottom:-96rpx;width:150rpx;height:150rpx}.support-hero-kicker,.support-hero-title,.support-hero-copy,.service-title,.service-subtitle,.scan-tip,.guide-title,.guide-step text,.qr-empty text,.qr-empty strong{position:relative;z-index:1;display:block}.support-hero-kicker{color:#dffbff;font-size:13px;font-weight:900;letter-spacing:.14em;text-shadow:0 2rpx 8rpx rgba(3,42,78,.2)}.support-hero-title{margin-top:12rpx;color:#fff;font-size:24px;font-weight:950;line-height:1.3;text-shadow:0 3rpx 12rpx rgba(3,42,78,.26)}.support-hero-copy{max-width:590rpx;margin-top:10rpx;color:#effdff;font-size:14px;font-weight:650;line-height:1.65;text-shadow:0 2rpx 8rpx rgba(3,42,78,.18)}
.qr-card{margin-top:22rpx;padding:27rpx;border:1rpx solid #d9e9ef;border-radius:30rpx;background:#fff;box-shadow:0 16rpx 38rpx rgba(38,85,111,.1)}.qr-card-head{display:flex;align-items:center}.service-avatar{position:relative;display:flex;align-items:center;justify-content:center;width:76rpx;height:76rpx;border-radius:23rpx;background:linear-gradient(145deg,#8cf27c,#40d7c0);color:#123a4d;font-size:17px;font-weight:950}.service-avatar i{position:absolute;right:-2rpx;top:-2rpx;width:15rpx;height:15rpx;border:4rpx solid #fff;border-radius:50%;background:#20c878}.service-copy{min-width:0;margin-left:18rpx;flex:1}.service-title{font-size:17px;font-weight:900}.service-subtitle{margin-top:5rpx;color:#74818b;font-size:13px}.online-pill{padding:6rpx 14rpx;border-radius:999rpx;background:#fff1d1;color:#b36b00;font-size:13px;font-weight:850}.online-pill.configured{background:#ddf8e9;color:#087b4b}
.qr-stage{display:flex;justify-content:center;margin-top:30rpx}.qr-frame{position:relative;width:440rpx;height:440rpx;padding:28rpx;border:1rpx solid #e2ebee;border-radius:28rpx;background:#fff;box-shadow:0 12rpx 30rpx rgba(25,85,114,.1);box-sizing:border-box}.qr-image{width:100%;height:100%;border-radius:14rpx}.corner{position:absolute;width:42rpx;height:42rpx;border-color:#1594da}.corner-a{left:12rpx;top:12rpx;border-left:6rpx solid #1594da;border-top:6rpx solid #1594da;border-radius:10rpx 0 0}.corner-b{right:12rpx;top:12rpx;border-right:6rpx solid #1594da;border-top:6rpx solid #1594da;border-radius:0 10rpx 0 0}.corner-c{left:12rpx;bottom:12rpx;border-left:6rpx solid #1594da;border-bottom:6rpx solid #1594da;border-radius:0 0 0 10rpx}.corner-d{right:12rpx;bottom:12rpx;border-right:6rpx solid #1594da;border-bottom:6rpx solid #1594da;border-radius:0 0 10rpx}.qr-empty{display:flex;width:440rpx;height:440rpx;align-items:center;justify-content:center;flex-direction:column;padding:40rpx;border:2rpx dashed #9ccfe0;border-radius:28rpx;background:#f4fbfd;box-sizing:border-box;text-align:center}.qr-empty-mark{display:flex!important;align-items:center;justify-content:center;width:100rpx;height:100rpx;border-radius:30rpx;background:#dff4fb;color:#0e6f9b;font-size:22px;font-weight:950}.qr-empty strong{margin-top:24rpx;color:#16364a;font-size:17px}.qr-empty text:last-child{margin-top:10rpx;color:#5f707c;font-size:14px;line-height:1.55}.scan-tip{margin-top:22rpx;color:#536773;font-size:14px;text-align:center}.preview-button{height:78rpx;margin-top:22rpx;border:0;border-radius:22rpx;background:linear-gradient(135deg,#126bdb,#118fa5);color:#fff;font-size:15px;font-weight:850;line-height:78rpx}.preview-button::after{border:0}
.qr-loading{gap:16rpx;color:#57717f}.qr-spinner{width:32rpx;height:32rpx;border:4rpx solid #ccebe8;border-top-color:#1b9f92;border-radius:50%;animation:support-spin .9s linear infinite}.qr-error .qr-empty-mark{background:#ffe7e2;color:#b42318}.retry-button{height:60rpx;margin-top:20rpx;padding:0 22rpx;border:1rpx solid #c74a3b;border-radius:15rpx;background:#fff;color:#b42318;font-size:13px;line-height:58rpx}.retry-button::after{border:0}
.guide-card{margin-top:22rpx;padding:28rpx;border:1rpx solid #deeaee;border-radius:28rpx;background:rgba(255,255,255,.92)}.guide-title{font-size:17px;font-weight:900}.guide-step{display:flex;align-items:center;gap:17rpx;margin-top:20rpx}.guide-step strong{display:flex;width:42rpx;height:42rpx;align-items:center;justify-content:center;flex:none;border-radius:14rpx;background:#fff1a8;color:#725500;font-size:13px}.guide-step text{color:#53636e;font-size:14px;line-height:1.45}
@media screen and (min-width:768px){.page{width:750rpx;margin:0 auto}}
@keyframes support-spin{to{transform:rotate(360deg)}}
/* ===== 新视觉（设计屏 51 support） ===== */
.page{--green:#0BBF63;--green-deep:#08A152;--green-soft:#E7F9EF;--t1:#191B1F;--t2:#767C85;--t3:#AEB3BB;--line:#EDEFEF;background:#F4F6F4!important;padding:0 0 150rpx;color:var(--t1)}
.subnav{display:flex;align-items:center;padding:calc(var(--status-bar-height,20px) + 8rpx) 20rpx 8rpx;background:#F4F6F4}
.subnav .back{width:64rpx;height:64rpx;display:flex;align-items:center;justify-content:center;font-size:44rpx;color:var(--t1);line-height:1}
.nav-title{flex:1;text-align:center;font-size:32rpx;font-weight:800}
.nav-spacer{width:64rpx;height:64rpx}
.search-bar{background:#fff;padding:16rpx 24rpx}
.search-box{flex:1;height:68rpx;border-radius:34rpx;background:#F2F4F3;display:flex;align-items:center;gap:12rpx;padding:0 24rpx;color:var(--t3);font-size:25rpx}
.search-ic{font-size:30rpx;color:var(--t3)}
.card{background:#fff;border-radius:24rpx;margin:20rpx 24rpx 0;overflow:hidden}
.sec-title{display:flex;align-items:center;justify-content:space-between;padding:26rpx 28rpx 18rpx}
.sec-name{font-size:30rpx;font-weight:700;color:var(--t1);display:flex;align-items:center;gap:14rpx}
.sec-name::before{content:'';width:8rpx;height:28rpx;border-radius:4rpx;background:var(--green)}
.qa-row{display:flex;align-items:center;padding:28rpx;font-size:27rpx;font-weight:600;color:var(--t1);border-top:2rpx solid #F5F6F6}
.qa-row .chev{margin-left:auto;color:var(--t3);font-size:32rpx}
.sp-svc{display:flex;align-items:center;gap:20rpx;padding:24rpx 28rpx;border-top:2rpx solid #F5F6F6}
.svc-ic{width:68rpx;height:68rpx;border-radius:20rpx;background:var(--green-soft);color:var(--green-deep);display:flex;align-items:center;justify-content:center;font-size:28rpx;font-weight:800;flex:none}
.svc-tx{flex:1;min-width:0}
.svc-b{display:block;font-size:27rpx;font-weight:600;color:var(--t1)}
.svc-p{display:block;margin-top:6rpx;font-size:22rpx;color:var(--t3)}
.tag{font-size:20rpx;padding:6rpx 16rpx;border-radius:8rpx;background:#F2F4F3;color:var(--t2)}
.tag-green{background:var(--green-soft);color:var(--green-deep)}
.sp-svc .chev{color:var(--t3);font-size:32rpx}
.qr-card{border:0;box-shadow:none;padding-bottom:28rpx}
.online-pill{background:var(--green-soft)!important;color:var(--green-deep)!important;font-size:20rpx;padding:6rpx 16rpx;border-radius:8rpx;font-weight:600}
.qr-stage{display:flex;justify-content:center;margin-top:8rpx;padding:0 28rpx}
.qr-frame{width:400rpx;height:400rpx;padding:20rpx;border:2rpx solid var(--line);border-radius:28rpx;background:#F7F9F8;box-shadow:none;box-sizing:border-box}
.qr-image{border-radius:16rpx}
.qr-empty{width:400rpx;height:400rpx;border:3rpx dashed #D7DBD8;border-radius:28rpx;background:#F7F9F8;color:var(--t2)}
.qr-empty-mark{background:var(--green-soft)!important;color:var(--green-deep)!important;border-radius:28rpx;font-size:40rpx;font-weight:800}
.qr-empty strong{color:var(--t1);font-size:28rpx}
.qr-empty text:last-child{color:var(--t3);font-size:23rpx}
.scan-tip{display:block;color:var(--t2);font-size:23rpx;text-align:center;margin-top:24rpx;padding:0 28rpx}
.preview-button{height:80rpx;margin:24rpx 28rpx 0;width:calc(100% - 56rpx);border:0;border-radius:40rpx;background:linear-gradient(135deg,#12D978,#08A152);color:#fff;font-size:27rpx;font-weight:700;line-height:80rpx;box-shadow:0 8rpx 18rpx rgba(11,191,99,.22)}
.preview-button::after{border:0}
.qr-spinner{border-color:var(--green-soft);border-top-color:var(--green)}
.retry-button{border-color:#F3C4BE;color:#B42318;border-radius:999rpx}
.retry-button::after{border:0}
.guide-step{display:flex;align-items:center;gap:20rpx;padding:20rpx 28rpx}
.guide-step strong{width:48rpx;height:48rpx;flex:none;border-radius:16rpx;background:var(--green-soft);color:var(--green-deep);font-size:25rpx;font-weight:800;display:flex;align-items:center;justify-content:center}
.guide-step text{color:var(--t2);font-size:25rpx;line-height:1.5}
.feedback-card{padding-bottom:10rpx}
.feedback-input{width:calc(100% - 56rpx);height:180rpx;margin:0 28rpx;padding:20rpx;box-sizing:border-box;border-radius:16rpx;background:#F7F9F8;font-size:26rpx;line-height:1.6}
.upload-grid{display:flex;gap:16rpx;padding:20rpx 28rpx 24rpx}
.upload-add{width:200rpx;height:150rpx;border-radius:18rpx;border:3rpx dashed #D7DBD8;background:#F7F9F8;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:8rpx}
.up-plus{font-size:40rpx;color:var(--t3);line-height:1}
.up-text{font-size:22rpx;color:var(--t3)}
.feedback-action{padding:24rpx 24rpx 0}
.feedback-btn{height:88rpx;border-radius:44rpx;background:linear-gradient(135deg,#12D978,#08A152);color:#fff;font-size:30rpx;font-weight:700;line-height:88rpx;box-shadow:0 8rpx 18rpx rgba(11,191,99,.22)}
.feedback-btn::after{border:0}
</style>
