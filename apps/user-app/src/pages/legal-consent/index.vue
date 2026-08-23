<template>
  <view class="page">
    <view class="intro">
      <text class="eyebrow">CURRENT AGREEMENTS</text>
      <text class="title">请确认当前协议</text>
      <text class="subtitle">继续使用任务、社区、商城和资产服务前，请阅读并接受当前生效版本。</text>
    </view>

    <view v-if="loading" class="state-card loading-state" role="status" aria-live="polite"><text class="state-title">正在加载当前协议…</text><text class="state-copy">正在同步当前生效版本。</text></view>
    <view v-else-if="loadError" class="state-card error-state" role="alert"><text class="state-title">协议加载失败</text><text class="state-copy">{{ loadError }}</text><button class="retry-button" @click="loadDocuments">重新加载</button></view>
    <view v-else-if="documents.length === 0" class="state-card success"><text>✓ 当前没有待确认的必要协议</text></view>
    <template v-else>
      <checkbox-group @change="onSelectionChange">
        <view v-for="(document, index) in documents" :key="document.id" class="document-card">
          <view class="document-heading" @click="toggle(index)">
            <view>
              <text class="document-title">{{ document.title }}</text>
              <text class="document-version">版本 {{ document.version }} · {{ formatDate(document.effective_at) }}</text>
            </view>
            <text class="toggle">{{ expandedIndex === index ? '收起' : '查看全文' }}</text>
          </view>
          <view v-if="expandedIndex === index" class="document-content">
            <rich-text v-if="document.content_format === 'rich_text_html'" :nodes="document.content" />
            <text v-else selectable>{{ document.content }}</text>
          </view>
          <label v-if="!readOnly" class="consent-row">
            <checkbox :value="document.id" color="#2f6bff" />
            <text>我已阅读并同意《{{ document.title }}》</text>
          </label>
        </view>
      </checkbox-group>

      <view v-if="!readOnly" class="notice">协议记录将保存版本、时间、客户端和必要安全摘要，已发布正文及同意记录不可被后台直接改写。</view>
      <button class="accept-button" :loading="submitting" :disabled="!readOnly && (!allSelected || submitting)" @click="readOnly ? goBack() : acceptAll()">
        {{ readOnly ? '返回登录' : '同意并继续' }}
      </button>
    </template>
  </view>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue'
import { onLoad } from '@dcloudio/uni-app'
import { ApiError } from '@/api/http'
import { acceptLegalDocument, loadCurrentLegalDocuments, loadPendingLegalDocuments, type LegalDocument } from '@/api/legal'
import { useAuthStore } from '@/stores/auth'
import { clearReturnUrl, redirectToLogin, resolveReturnUrl } from '@/utils/authRedirect'

const auth = useAuthStore()
const documents = ref<LegalDocument[]>([])
const selectedIds = ref<string[]>([])
const expandedIndex = ref(0)
const loading = ref(true)
const submitting = ref(false)
const loadError = ref('')
const next = ref<'home' | 'bind-phone'>('home')
const readOnly = ref(false)
const documentType = ref<string>()
const returnUrl = ref<string | null>(null)
const allSelected = computed(() => documents.value.length > 0 && selectedIds.value.length === documents.value.length)

onLoad((options) => {
  readOnly.value = options?.mode === 'read'
  documentType.value = options?.type
  next.value = options?.next === 'bind-phone' ? 'bind-phone' : 'home'
  returnUrl.value = resolveReturnUrl(options?.returnUrl)
  if (!auth.accessToken && !readOnly.value) {
    redirectToLogin()
    return
  }

  void loadDocuments()
})

async function loadDocuments() {
  if (!readOnly.value && !auth.accessToken) {
    redirectToLogin()
    return
  }
  loading.value = true
  loadError.value = ''
  try {
    documents.value = readOnly.value
      ? await loadCurrentLegalDocuments(documentType.value)
      : await loadPendingLegalDocuments(auth.accessToken, 'account')
    if (!readOnly.value && documents.value.length === 0) setTimeout(continueFlow, 300)
  } catch (error) {
    loadError.value = error instanceof ApiError ? error.message : '协议加载失败，请稍后重试。'
    showError(error)
  } finally {
    loading.value = false
  }
}

function onSelectionChange(event: { detail: { value: string[] } }) {
  selectedIds.value = event.detail.value
}

function toggle(index: number) {
  expandedIndex.value = expandedIndex.value === index ? -1 : index
}

async function acceptAll() {
  if (!allSelected.value) return
  submitting.value = true
  try {
    for (const document of documents.value) {
      await acceptLegalDocument(auth.accessToken, document.id)
    }
    uni.showToast({ title: '协议确认成功', icon: 'success' })
    setTimeout(continueFlow, 350)
  } catch (error) {
    showError(error)
  } finally {
    submitting.value = false
  }
}

function continueFlow() {
  const target = returnUrl.value || resolveReturnUrl()
  if (next.value === 'bind-phone') {
    redirectToLogin(target || '/pages/index/index')
  } else {
    clearReturnUrl()
    uni.reLaunch({ url: target || '/pages/index/index' })
  }
}

function goBack() {
  uni.navigateBack()
}

function formatDate(value: string) {
  return value ? value.slice(0, 10) : '待生效'
}

function showError(error: unknown) {
  const message = error instanceof ApiError ? error.message : '协议加载失败，请稍后重试。'
  uni.showToast({ title: message, icon: 'none', duration: 3000 })
}
</script>

<style scoped>
.page { min-height: 100vh; padding: 48rpx 32rpx 80rpx; box-sizing: border-box; background: #f5f7fb; }
.intro { padding: 24rpx 16rpx 36rpx; }
.eyebrow, .title, .subtitle, .document-title, .document-version { display: block; }
.eyebrow { color: #2f6bff; font-size: 21rpx; font-weight: 700; letter-spacing: 3rpx; }
.title { margin-top: 18rpx; color: #172033; font-size: 46rpx; font-weight: 750; }
.subtitle { margin-top: 14rpx; color: #667085; font-size: 26rpx; line-height: 1.7; }
.state-card, .document-card { margin-bottom: 20rpx; border: 1rpx solid #e5e9f0; border-radius: 24rpx; background: #fff; }
.state-card { padding: 34rpx; color: #667085; text-align: center; }
.state-card.success { color: #067647; }
.document-heading { display: flex; align-items: center; justify-content: space-between; padding: 30rpx; }
.document-title { color: #1d2939; font-size: 29rpx; font-weight: 650; }
.document-version { margin-top: 8rpx; color: #98a2b3; font-size: 21rpx; }
.toggle { color: #2f6bff; font-size: 23rpx; }
.document-content { max-height: 520rpx; overflow-y: auto; margin: 0 30rpx; padding: 28rpx; border-radius: 16rpx; background: #f8fafc; color: #475467; font-size: 24rpx; line-height: 1.9; white-space: pre-wrap; }
.consent-row { display: flex; align-items: flex-start; gap: 14rpx; padding: 28rpx 30rpx; color: #344054; font-size: 24rpx; line-height: 1.6; }
.notice { margin: 26rpx 8rpx; color: #7a8699; font-size: 21rpx; line-height: 1.7; }
.accept-button { height: 96rpx; border: 0; border-radius: 20rpx; background: linear-gradient(135deg, #2f6bff, #5b8cff); color: #fff; font-size: 29rpx; font-weight: 650; line-height: 96rpx; }
.accept-button[disabled] { background: #cfd7e6; color: #fff; }
.accept-button::after { border: 0; }
/* V09 agreement center: blue hero, soft content cards and an accessible primary action. */
.page{background:linear-gradient(180deg,#eefbfb 0,var(--tm-canvas) 520rpx)}
.intro{margin-bottom:24rpx;padding:30rpx 26rpx;border:1rpx solid #cfe8e7;border-radius:28rpx;background:linear-gradient(135deg,#e1f8f5,#eaf5ff);box-shadow:0 12rpx 30rpx rgba(34,107,113,.08)}
.eyebrow,.title{color:#1c4d59}.subtitle{color:#617780;font-weight:550}
.state-card,.document-card{border:1rpx solid #d6e6e8;box-shadow:0 12rpx 26rpx rgba(25,72,89,.07)}
.state-card.success{background:#eef9f5}.document-card:nth-child(even){background:#fbfefe}
.toggle{padding:7rpx 12rpx;border:1rpx solid #bedfdc;border-radius:10rpx;background:#eaf8f4;color:#197b72}
.document-content{border:1rpx solid #dbe9ea;background:#f8fcfc}.consent-row{background:#eef9f5}
.notice{padding:18rpx;border:1rpx solid #f0d6cb;border-radius:16rpx;background:#fff5f0;color:#667980}
.accept-button{border:0;background:#2a9e98;box-shadow:0 10rpx 20rpx rgba(24,125,111,.18)}
.retry-button{height:64rpx;margin:20rpx auto 0;padding:0 24rpx;border:0;border-radius:16rpx;background:#2a9e98;color:#fff;font-size:20rpx;line-height:64rpx}.retry-button::after{border:0}
.state-title,.state-copy{display:block}.state-title{color:#214d58;font-size:26rpx;font-weight:850}.state-copy{margin-top:8rpx;color:#72858c;font-size:20rpx;line-height:1.5}
</style>
