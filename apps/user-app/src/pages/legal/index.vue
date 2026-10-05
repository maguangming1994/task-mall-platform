<template>
  <view class="page">
    <!-- 隐私政策摘要横幅 -->
    <view class="hero">
      <view class="hero-icon">盾</view>
      <view class="hero-main">
        <text class="hero-title">隐私政策摘要</text>
        <text class="hero-copy">我们仅在登录、实名认证、任务审核、商城履约和售后服务所必需的范围内处理个人信息，并记录后台操作审计，你可以随时查询与撤回授权。</text>
      </view>
    </view>

    <view v-if="loading" class="state-card" role="status" aria-live="polite">
      <text class="state-title">正在加载协议与政策…</text>
      <text class="state-copy">正在同步当前生效版本。</text>
    </view>
    <view v-else-if="loadError" class="state-card error-state" role="alert">
      <text class="state-title">协议加载失败</text>
      <text class="state-copy">{{ loadError }}</text>
      <button class="retry-button" @click="load">重新加载</button>
    </view>

    <template v-else>
      <!-- 协议与政策 -->
      <view class="section-head">
        <text class="section-title">协议与政策</text>
        <text class="section-meta">{{ documents.length }} 份有效文件</text>
      </view>
      <view class="panel">
        <view v-for="document in documents" :key="document.id" class="doc-block">
          <view class="document-row" @click="toggle(document.id)">
            <view class="doc-info">
              <text class="document-title">{{ document.title }}</text>
              <text class="document-note">版本 {{ document.version }} · {{ dateText(document.effective_at) }}</text>
            </view>
            <view class="document-status">
              <text v-if="isPending(document.id)" class="pending-badge">待确认</text>
              <text :class="['chevron', expandedId === document.id && 'open']">›</text>
            </view>
          </view>
          <view v-if="expandedId === document.id" class="document-content">
            <rich-text v-if="document.content_format === 'rich_text_html'" class="rich-content" :nodes="document.content" />
            <view v-else>
              <text v-for="(line, idx) in markdownLines(document.content)" :key="idx" :class="['md-line', `md-${line.kind}`]">{{ line.text }}</text>
            </view>
          </view>
        </view>
        <view v-if="!documents.length" class="empty-card">
          <text>暂无可查看的有效文件</text>
          <text>如需确认协议，请稍后重新加载。</text>
        </view>
      </view>

      <!-- 授权管理 -->
      <view class="section-head">
        <text class="section-title">授权管理</text>
        <text class="section-meta">可在系统设置调整</text>
      </view>
      <view class="panel">
        <view class="permission-row">
          <text class="permission-name">微信基础信息</text>
          <text class="permission-value enabled">登录后按需授权</text>
        </view>
        <view class="permission-row">
          <text class="permission-name">相册与相机</text>
          <text class="permission-value">仅上传凭证时访问</text>
        </view>
        <view class="permission-row">
          <text class="permission-name">消息通知</text>
          <text class="permission-value">由消息中心管理</text>
        </view>
      </view>

      <!-- 同意与提交 -->
      <label v-if="pending.length" class="consent-row">
        <view class="checkbox" :class="{ checked: confirmed }" @click.prevent="confirmed = !confirmed">{{ confirmed ? '✓' : '' }}</view>
        <text class="consent-text" @click="confirmed = !confirmed">我已阅读并同意当前版本的《用户协议》和《隐私政策》，并理解上述授权与信息处理方式。</text>
      </label>
      <button class="primary-button" :loading="submitting" :disabled="submitting || (pending.length > 0 && !confirmed)" @click="confirmPending">
        {{ pending.length ? `确认并继续（${pending.length}）` : '已是最新版本' }}
      </button>
    </template>
  </view>
</template>

<script setup lang="ts">
import { formatDate as dateText } from '@/utils/dateTime'
import { onLoad, onShow } from '@dcloudio/uni-app'
import { ref } from 'vue'
import { acceptLegalDocument, loadCurrentLegalDocuments, loadPendingLegalDocuments, type LegalDocument } from '@/api/legal'
import { useAuthStore } from '@/stores/auth'
import { navigateToLogin } from '@/utils/authRedirect'
import { markdownLines } from '@/utils/richText'

const auth = useAuthStore()
const documents = ref<LegalDocument[]>([])
const pending = ref<LegalDocument[]>([])
const expandedId = ref('')
const confirmed = ref(false)
const submitting = ref(false)
const loading = ref(false)
const loadError = ref('')
const focusType = ref('')

onLoad((query) => {
  // 支持实名页等场景带 ?key=document_type 直达指定协议
  if (query?.key) focusType.value = String(query.key)
})

onShow(load)

async function load() {
  loading.value = true
  loadError.value = ''
  try {
    const current = await loadCurrentLegalDocuments()
    documents.value = orderDocuments(current)
    pending.value = auth.isAuthenticated ? await loadPendingLegalDocuments(auth.accessToken) : []
    confirmed.value = pending.value.length === 0
    if (focusType.value) {
      const target = documents.value.find(item => item.document_type === focusType.value)
      if (target) expandedId.value = target.id
      focusType.value = ''
    }
  } catch (error) {
    loadError.value = error instanceof Error ? error.message : '请检查网络后重试'
    uni.showToast({ title: loadError.value, icon: 'none' })
  } finally {
    loading.value = false
  }
}

/* 固定协议展示顺序：用户协议、隐私政策、实名认证、支付服务，其余按返回顺序追加 */
function orderDocuments(items: LegalDocument[]) {
  const order = ['user_agreement', 'privacy_policy', 'identity_service', 'payment_service']
  return [...items].sort((a, b) => {
    const ia = order.indexOf(a.document_type)
    const ib = order.indexOf(b.document_type)
    return (ia === -1 ? 99 : ia) - (ib === -1 ? 99 : ib)
  })
}

function toggle(id: string) {
  expandedId.value = expandedId.value === id ? '' : id
}

function isPending(id: string) {
  return pending.value.some(item => item.id === id)
}

async function confirmPending() {
  if (!pending.value.length) {
    uni.showToast({ title: '当前已是最新版本', icon: 'none' })
    return
  }
  if (!auth.isAuthenticated) return navigateToLogin()
  submitting.value = true
  try {
    for (const document of pending.value) {
      // 顺序同意，避免并发造成的版本竞争
      await acceptLegalDocument(auth.accessToken, document.id)
    }
    await load()
    uni.showToast({ title: '协议已确认', icon: 'success' })
  } catch (error) {
    uni.showToast({ title: error instanceof Error ? error.message : '确认失败', icon: 'none' })
  } finally {
    submitting.value = false
  }
}
</script>

<style scoped>
.page { min-height: 100vh; padding: 24rpx 24rpx 80rpx; background: #F4F6F4; box-sizing: border-box; }

.hero { display: flex; align-items: flex-start; gap: 20rpx; padding: 32rpx 30rpx; border-radius: 28rpx; background: linear-gradient(145deg, #12D978, #08A152); box-shadow: 0 14rpx 34rpx rgba(11,191,99,.26); }
.hero-icon { display: flex; align-items: center; justify-content: center; width: 72rpx; height: 72rpx; flex: none; border-radius: 20rpx; border: 2rpx solid rgba(255,255,255,.5); background: rgba(255,255,255,.17); color: #fff; font-size: 32rpx; font-weight: 900; }
.hero-main { flex: 1; }
.hero-title { display: block; color: #fff; font-size: 30rpx; font-weight: 900; }
.hero-copy { display: block; margin-top: 10rpx; color: rgba(255,255,255,.9); font-size: 23rpx; line-height: 1.65; }

.section-head { display: flex; align-items: center; justify-content: space-between; margin: 30rpx 4rpx 14rpx; }
.section-title { position: relative; padding-left: 20rpx; color: #191B1F; font-size: 30rpx; font-weight: 900; }
.section-title::before { content: ''; position: absolute; left: 0; top: 50%; transform: translateY(-50%); width: 8rpx; height: 30rpx; border-radius: 4rpx; background: #0BBF63; }
.section-meta { color: #AEB3BB; font-size: 22rpx; }

.panel { border: 1rpx solid #EDEFEF; border-radius: 24rpx; background: #fff; padding: 0 26rpx; box-shadow: 0 2rpx 4rpx rgba(23,26,23,.03), 0 12rpx 40rpx rgba(23,26,23,.05); }
.doc-block { border-bottom: 1rpx solid #F2F4F3; }
.doc-block:last-child { border-bottom: 0; }
.document-row { display: flex; align-items: center; justify-content: space-between; gap: 20rpx; padding: 26rpx 0; }
.doc-info { flex: 1; min-width: 0; }
.document-title { display: block; color: #191B1F; font-size: 28rpx; font-weight: 800; }
.document-note { display: block; margin-top: 8rpx; color: #767C85; font-size: 22rpx; }
.document-status { display: flex; align-items: center; gap: 12rpx; flex: none; }
.pending-badge { padding: 6rpx 14rpx; border-radius: 14rpx; background: #FFF2E5; color: #F2620A; font-size: 20rpx; font-weight: 800; }
.chevron { color: #C6CBC6; font-size: 38rpx; font-weight: 300; transition: transform .2s; }
.chevron.open { transform: rotate(90deg); }

.document-content { margin-bottom: 24rpx; padding: 22rpx 24rpx; border: 1rpx solid #EDEFEF; border-radius: 16rpx; background: #F7F9F8; }
.md-line { display: block; color: #50565C; font-size: 24rpx; line-height: 1.75; }
.md-h1 { margin: 8rpx 0 12rpx; color: #191B1F; font-size: 32rpx; font-weight: 900; }
.md-h2 { margin: 18rpx 0 8rpx; color: #191B1F; font-size: 27rpx; font-weight: 800; }
.md-h3 { margin: 12rpx 0 6rpx; color: #191B1F; font-size: 25rpx; font-weight: 700; }
.md-quote { padding: 10rpx 16rpx; border-left: 6rpx solid #9FD8B6; background: #EFF8F2; color: #767C85; }
.md-list { padding-left: 8rpx; }

.permission-row { display: flex; align-items: center; justify-content: space-between; padding: 26rpx 0; border-bottom: 1rpx solid #F2F4F3; }
.permission-row:last-child { border-bottom: 0; }
.permission-name { color: #191B1F; font-size: 27rpx; font-weight: 600; }
.permission-value { color: #767C85; font-size: 23rpx; }
.permission-value.enabled { color: #08A152; font-weight: 700; }

.consent-row { display: flex; align-items: flex-start; gap: 14rpx; margin: 28rpx 4rpx 20rpx; padding: 22rpx 24rpx; border: 1rpx solid #CDE8D2; border-radius: 20rpx; background: #F4FBF7; }
.checkbox { display: flex; align-items: center; justify-content: center; width: 34rpx; height: 34rpx; flex: none; border: 2rpx solid #B7D9C2; border-radius: 50%; color: #fff; font-size: 20rpx; }
.checkbox.checked { background: #0BBF63; border-color: #0BBF63; }
.consent-text { color: #50565C; font-size: 23rpx; line-height: 1.6; }

.primary-button { height: 96rpx; margin-top: 8rpx; border: 0; border-radius: 999rpx; background: linear-gradient(135deg, #12D978, #08A152); box-shadow: 0 12rpx 28rpx rgba(11,191,99,.22); color: #fff; font-size: 30rpx; font-weight: 850; line-height: 96rpx; }
.primary-button[disabled] { background: #A9D8BE; box-shadow: none; color: rgba(255,255,255,.9); }
.primary-button::after { border: 0; }

.state-card { margin-top: 20rpx; padding: 40rpx 28rpx; text-align: center; border: 1rpx solid #EDEFEF; border-radius: 24rpx; background: #fff; box-shadow: 0 12rpx 40rpx rgba(23,26,23,.05); }
.state-title, .state-copy { display: block; }
.state-title { color: #191B1F; font-size: 28rpx; font-weight: 850; }
.state-copy { margin-top: 8rpx; color: #767C85; font-size: 23rpx; line-height: 1.5; }
.retry-button { height: 64rpx; margin: 20rpx auto 0; padding: 0 32rpx; border: 0; border-radius: 999rpx; background: linear-gradient(135deg, #12D978, #08A152); color: #fff; font-size: 24rpx; line-height: 64rpx; }
.retry-button::after { border: 0; }
.empty-card { padding: 40rpx 20rpx; text-align: center; }
.empty-card text { display: block; color: #767C85; font-size: 23rpx; }
.empty-card text:first-child { color: #191B1F; font-size: 27rpx; font-weight: 800; }
.empty-card text + text { margin-top: 8rpx; }
</style>
