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
            <view v-else>
              <text v-for="(line, lineIndex) in markdownLines(document.content)" :key="lineIndex" :class="['md-line', `md-${line.kind}`]" selectable>{{ line.text }}</text>
            </view>
          </view>
          <label v-if="!readOnly" class="consent-row">
            <checkbox :value="document.id" color="#0BBF63" />
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
import { formatDate as formatBusinessDate } from '@/utils/dateTime'
import { computed, ref } from 'vue'
import { onLoad } from '@dcloudio/uni-app'
import { ApiError } from '@/api/http'
import { acceptLegalDocument, loadCurrentLegalDocuments, loadPendingLegalDocuments, type LegalDocument } from '@/api/legal'
import { useAuthStore } from '@/stores/auth'
import { clearReturnUrl, redirectToLogin, resolveReturnUrl } from '@/utils/authRedirect'
import { markdownLines } from '@/utils/richText'

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
const scope = ref('account')
const returnUrl = ref<string | null>(null)
const allSelected = computed(() => documents.value.length > 0 && selectedIds.value.length === documents.value.length)

onLoad((options) => {
  readOnly.value = options?.mode === 'read'
  documentType.value = options?.type
  next.value = options?.next === 'bind-phone' ? 'bind-phone' : 'home'
  if (typeof options?.scope === 'string' && options.scope) scope.value = options.scope
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
      : await loadPendingLegalDocuments(auth.accessToken, scope.value)
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
  return formatBusinessDate(value, '待生效')
}

function showError(error: unknown) {
  const message = error instanceof ApiError ? error.message : '协议加载失败，请稍后重试。'
  uni.showToast({ title: message, icon: 'none', duration: 3000 })
}
</script>

<style scoped>
.page { min-height: 100vh; padding: 48rpx 32rpx 80rpx; box-sizing: border-box; background: #F4F6F4; }
.intro { padding: 24rpx 16rpx 36rpx; }
.eyebrow, .title, .subtitle, .document-title, .document-version { display: block; }
.eyebrow { color: #08A152; font-size: 14px; font-weight: 700; letter-spacing: 3rpx; }
.title { margin-top: 18rpx; color: #191B1F; font-size: 26px; font-weight: 750; }
.subtitle { margin-top: 14rpx; color: #767C85; font-size: 15px; line-height: 1.7; }
.state-card, .document-card { margin-bottom: 20rpx; border: 1rpx solid #EDEFEF; border-radius: 28rpx; background: #fff; }
.state-card { padding: 34rpx; color: #767C85; text-align: center; }
.state-card.success { color: #08A152; }
.document-heading { display: flex; align-items: center; justify-content: space-between; padding: 30rpx; }
.document-title { color: #191B1F; font-size: 17px; font-weight: 650; }
.document-version { margin-top: 8rpx; color: #AEB3BB; font-size: 14px; }
.toggle { color: #08A152; font-size: 14px; }
.document-content { max-height: 520rpx; overflow-y: auto; margin: 0 30rpx; padding: 28rpx; border-radius: 16rpx; background: #F7F9F8; color: #767C85; font-size: 15px; line-height: 1.9; white-space: pre-wrap; }
.consent-row { display: flex; align-items: flex-start; gap: 14rpx; padding: 28rpx 30rpx; color: #4B515A; font-size: 15px; line-height: 1.6; }
.notice { margin: 26rpx 8rpx; color: #767C85; font-size: 14px; line-height: 1.7; }
.accept-button { height: 96rpx; border: 0; border-radius: 999rpx; background: linear-gradient(135deg, #12D978, #08A152); color: #fff; font-size: 17px; font-weight: 650; line-height: 96rpx; box-shadow: 0 12rpx 28rpx rgba(11,191,99,.22); }
.accept-button[disabled] { background: #DDE3DE; color: #fff; box-shadow: none; }
.accept-button::after { border: 0; }
/* V13 agreement center: green soft hero, white content cards and an accessible primary action. */
.page{background:#F4F6F4}
.intro{margin-bottom:24rpx;padding:30rpx 26rpx;border:1rpx solid #CDE8D2;border-radius:28rpx;background:linear-gradient(135deg,#E7F9EF,#F0FBF5);box-shadow:0 12rpx 40rpx rgba(23,26,23,.05)}
.eyebrow{color:#08A152}.title{color:#191B1F}.subtitle{color:#767C85;font-weight:550}
.state-card,.document-card{border:1rpx solid #EDEFEF;box-shadow:0 12rpx 40rpx rgba(23,26,23,.05)}
.state-card.success{background:#F0FBF5}.document-card:nth-child(even){background:#fff}
.toggle{padding:7rpx 12rpx;border:1rpx solid #9FD8B6;border-radius:10rpx;background:#E7F9EF;color:#08A152}
.document-content{border:1rpx solid #EDEFEF;background:#F7F9F8}.consent-row{background:#F4FBF7}
.md-line{display:block;font-size:15px;line-height:1.85}.md-h1{margin:8rpx 0 12rpx;color:#191B1F;font-size:20px;font-weight:900}.md-h2{margin:18rpx 0 8rpx;color:#191B1F;font-size:17px;font-weight:800}.md-h3{margin:12rpx 0 6rpx;color:#191B1F;font-size:16px;font-weight:700}.md-quote{padding:10rpx 16rpx;border-left:6rpx solid #9FD8B6;background:#EFF8F2}.md-list{padding-left:8rpx}
.notice{padding:18rpx;border:1rpx solid #CDE8D2;border-radius:16rpx;background:#F0FBF5;color:#767C85}
.accept-button{border:0;background:linear-gradient(135deg,#12D978,#08A152);box-shadow:0 12rpx 28rpx rgba(11,191,99,.22)}
.retry-button{height:64rpx;margin:20rpx auto 0;padding:0 24rpx;border:0;border-radius:999rpx;background:linear-gradient(135deg,#12D978,#08A152);color:#fff;font-size:13px;line-height:64rpx}.retry-button::after{border:0}
.state-title,.state-copy{display:block}.state-title{color:#191B1F;font-size:15px;font-weight:850}.state-copy{margin-top:8rpx;color:#767C85;font-size:13px;line-height:1.5}
.state-card.error-state{border-color:rgba(255,59,48,.18);background:#FFEEEA;color:#D0322B}.state-card.error-state .state-title{color:#D0322B}
</style>
