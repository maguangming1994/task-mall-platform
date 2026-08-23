<template>
  <view class="page tm-page-shell">
    <view class="summary tm-panel">
      <text class="summary-title">隐私政策摘要</text>
      <text class="summary-copy">我们仅在登录、任务审核、商城履约和售后服务所必需的范围内处理个人信息，并记录后台操作审计。</text>
    </view>

    <view v-if="loading" class="state-card loading-state" role="status" aria-live="polite"><text class="state-title">正在加载协议与政策…</text><text class="state-copy">正在同步当前生效版本。</text></view>
    <view v-else-if="loadError" class="state-card error-state" role="alert"><text class="state-title">协议加载失败</text><text class="state-copy">{{ loadError }}</text><button class="retry-button" @click="load">重新加载</button></view>

    <view class="section-head"><text>协议与政策</text><text>{{ documents.length }} 份有效文件</text></view>
    <view class="document-list tm-panel">
      <view v-for="document in documents" :key="document.id" class="document-row" @click="toggle(document.id)">
        <view><text class="document-title">{{ document.title }}</text><text class="document-note">版本 {{ document.version }} · {{ dateText(document.effective_at) }}</text></view>
        <view class="document-status"><text v-if="isPending(document.id)" class="pending">待确认</text><text v-else>查看详情 ›</text></view>
      </view>
      <view v-if="expandedDocument" class="document-content"><rich-text v-if="expandedDocument.content_format === 'rich_text_html'" class="rich-content" :nodes="expandedDocument.content" /><text v-else>{{ expandedDocument.content }}</text></view>
      <view v-if="!loading && !loadError && !documents.length" class="empty-card"><text>暂无可查看的有效文件</text><text>如需确认协议，请稍后重新加载。</text></view>
    </view>

    <view class="section-head"><text>授权管理</text><text>可在系统设置调整</text></view>
    <view class="permission-list tm-panel">
      <view class="permission-row"><text>微信基础信息</text><text class="enabled">登录后按需授权</text></view>
      <view class="permission-row"><text>相册与相机</text><text>仅上传凭证时访问</text></view>
      <view class="permission-row"><text>消息通知</text><text>由消息中心管理</text></view>
    </view>

    <label class="consent-row"><checkbox :checked="confirmed" color="#0b8f89" @click="confirmed = !confirmed" /><text>我已阅读并同意当前版本的用户协议和隐私政策。</text></label>
    <button class="tm-primary-button" :loading="submitting" :disabled="submitting || !confirmed" @click="confirmPending">{{ pending.length ? `确认并继续（${pending.length}）` : '已是最新版本' }}</button>
  </view>
</template>

<script setup lang="ts">
import { onShow } from '@dcloudio/uni-app'
import { computed, ref } from 'vue'
import { acceptLegalDocument, loadCurrentLegalDocuments, loadPendingLegalDocuments, type LegalDocument } from '@/api/legal'
import { useAuthStore } from '@/stores/auth'
import { navigateToLogin } from '@/utils/authRedirect'

const auth = useAuthStore()
const documents = ref<LegalDocument[]>([])
const pending = ref<LegalDocument[]>([])
const expandedId = ref('')
const confirmed = ref(false)
const submitting = ref(false)
const loading = ref(false)
const loadError = ref('')
const expandedDocument = computed(() => documents.value.find((item) => item.id === expandedId.value) || null)

onShow(load)
async function load() {
  loading.value = true
  loadError.value = ''
  try {
    documents.value = await loadCurrentLegalDocuments()
    pending.value = auth.accessToken ? await loadPendingLegalDocuments(auth.accessToken) : []
    confirmed.value = pending.value.length === 0
  } catch (error) { loadError.value = error instanceof Error ? error.message : '请检查网络后重试'; uni.showToast({ title: loadError.value, icon: 'none' }) }
  finally { loading.value = false }
}
function toggle(id: string) { expandedId.value = expandedId.value === id ? '' : id }
function isPending(id: string) { return pending.value.some((item) => item.id === id) }
function dateText(value: string) { const date = new Date(value); return Number.isNaN(date.getTime()) ? value : `${date.getFullYear()}.${date.getMonth() + 1}.${date.getDate()}` }
async function confirmPending() {
  if (!pending.value.length) return uni.showToast({ title: '当前已是最新版本', icon: 'none' })
  if (!auth.accessToken) return navigateToLogin()
  submitting.value = true
  try {
    for (const document of pending.value) await acceptLegalDocument(auth.accessToken, document.id)
    await load(); uni.showToast({ title: '协议已确认', icon: 'success' })
  } catch (error) { uni.showToast({ title: error instanceof Error ? error.message : '确认失败', icon: 'none' }) }
  finally { submitting.value = false }
}
</script>

<style scoped>
.summary{padding:28rpx}.summary-title,.summary-copy,.document-title,.document-note,.document-content text{display:block}.summary-title{font-size:29rpx;font-weight:950}.summary-copy{margin-top:14rpx;color:var(--tm-ink-700);font-size:22rpx;line-height:1.7}.section-head{display:flex;align-items:flex-end;justify-content:space-between;margin:32rpx 2rpx 14rpx}.section-head text:first-child{font-size:30rpx;font-weight:950}.section-head text:last-child{color:var(--tm-ink-500);font-size:18rpx}.document-list,.permission-list{padding:0 24rpx}.document-row,.permission-row{display:flex;align-items:center;justify-content:space-between;gap:24rpx;padding:23rpx 0;border-bottom:1rpx solid var(--tm-line)}.document-row:last-of-type,.permission-row:last-child{border-bottom:0}.document-title{font-size:23rpx;font-weight:850}.document-note{margin-top:6rpx;color:var(--tm-ink-500);font-size:18rpx}.document-status{color:var(--tm-ink-500);font-size:19rpx}.document-status .pending{padding:5rpx 9rpx;border-radius:8rpx;background:var(--tm-reward-100);color:var(--tm-reward-600);font-weight:850}.document-content{max-height:520rpx;overflow:auto;margin:0 -4rpx 22rpx;padding:22rpx;border-radius:14rpx;background:var(--tm-surface-soft);color:var(--tm-ink-700);font-size:21rpx;line-height:1.7;white-space:pre-wrap}.permission-row{font-size:22rpx}.permission-row text:last-child{color:var(--tm-ink-500);font-size:19rpx}.permission-row .enabled{color:var(--tm-success)!important}.consent-row{display:flex;align-items:flex-start;gap:10rpx;margin:24rpx 4rpx;color:var(--tm-ink-700);font-size:20rpx;line-height:1.55}
/* V09 legal center: blue campus notice with soft policy and permission cards. */
.page{background:linear-gradient(180deg,#eefbfb 0,var(--tm-canvas) 500rpx)}
.summary,.document-list,.permission-list{border:1rpx solid #d4e7e8;box-shadow:0 12rpx 28rpx rgba(25,72,89,.07)}
.summary{background:linear-gradient(135deg,#e2f8f5,#eaf4ff)}
.document-list{background:#fff}.permission-list{background:#f5fbfb}
.document-status .pending{border:0;background:#fff0dd;color:#b46600}.document-content{border:1rpx solid #dbe9ea;background:#f8fcfc}
.consent-row{padding:20rpx;border:1rpx solid #cfe6e2;border-radius:18rpx;background:#eef9f5}
.tm-primary-button{border:0;box-shadow:0 10rpx 20rpx rgba(24,125,111,.18)}
.state-card{margin-top:20rpx;padding:38rpx 28rpx;text-align:center;border:1rpx solid #d6e6e8;border-radius:24rpx;background:#fff;box-shadow:0 10rpx 24rpx rgba(25,72,89,.06)}.state-title,.state-copy{display:block}.state-title{color:#214d58;font-size:26rpx;font-weight:850}.state-copy{margin-top:8rpx;color:#72858c;font-size:20rpx;line-height:1.5}.retry-button{height:64rpx;margin:20rpx auto 0;padding:0 24rpx;border:0;border-radius:16rpx;background:#2a9e98;color:#fff;font-size:20rpx;line-height:64rpx}.retry-button::after{border:0}.empty-card{padding:36rpx 20rpx;text-align:center}.empty-card text{display:block;color:#72858c;font-size:20rpx}.empty-card text:first-child{color:#355d67;font-size:24rpx;font-weight:800}.empty-card text+text{margin-top:8rpx}
</style>
