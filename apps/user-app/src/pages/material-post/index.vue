<template>
  <view class="page">
    <ModulePlaceholder v-if="modules.resolved && !moduleEnabled" code="module_material_post" />
    <template v-else>
      <view class="head">
        <view class="head-back" @click="goBack">‹</view>
        <text class="head-title">投喂资料</text>
      </view>

      <view class="tabs">
        <view :class="['tab', { active: tab === 'post' }]" @click="tab = 'post'">投喂资料</view>
        <view :class="['tab', { active: tab === 'mine' }]" @click="switchMine">我的投喂</view>
      </view>

      <template v-if="tab === 'post'">
        <view class="reward">
          <view class="reward-ic">✦</view>
          <view class="reward-main">
            <text class="reward-title">审核通过立赠 {{ bonus }} 次获取机会</text>
            <text class="reward-sub">长期有效 · 可无限累计 · 不受每日额度重置限制</text>
          </view>
        </view>

        <view class="form-card">
          <view class="form-head">
            <text class="form-label">网盘分享文案</text>
            <text class="parse-tag">智能解析</text>
          </view>
          <textarea
            v-model="shareText"
            class="form-textarea"
            :maxlength="8000"
            placeholder="请粘贴百度网盘、夸克网盘或阿里网盘的完整分享文本，支持自动提取名称、链接与提取码"
          />
          <text class="counter">{{ shareText.length }}/8000</text>

          <view v-if="parsed.share_url" class="parsed">
            <text class="parsed-title">解析结果</text>
            <view class="parsed-row"><text class="parsed-label">资料名称</text><text class="parsed-value">{{ parsed.title }}</text></view>
            <view class="parsed-row"><text class="parsed-label">网盘类型</text><text class="parsed-value">{{ netdiskLabel(parsed.netdisk_type) }}</text></view>
            <view class="parsed-row"><text class="parsed-label">分享链接</text><text class="parsed-value link">{{ parsed.share_url }}</text></view>
            <view class="parsed-row"><text class="parsed-label">提取码</text><text class="parsed-value code">{{ parsed.extract_code || '无' }}</text></view>
          </view>
          <text v-else-if="shareText.trim()" class="parse-error">暂未识别到网盘链接，请确认已粘贴完整分享文案。</text>

          <view class="form-block">
            <text class="form-label">资料名称（可选）</text>
            <input v-model="title" class="form-input" maxlength="60" placeholder="默认使用智能解析出的名称" />
          </view>

          <view class="form-block">
            <text class="form-label">资料分类</text>
            <view class="chips">
              <text :class="['chip', { active: !categoryId }]" @click="categoryId = ''">未分类</text>
              <text
                v-for="c in categories"
                :key="c.id"
                :class="['chip', { active: categoryId === c.id }]"
                @click="categoryId = c.id"
              >{{ c.name }}</text>
            </view>
          </view>

          <view class="agree" @click="agreed = !agreed">
            <view :class="['checkbox', { checked: agreed }]">{{ agreed ? '✓' : '' }}</view>
            <text class="agree-text">我已阅读并承诺遵守《资料上传版权承诺》及《用户协议》</text>
          </view>

          <button class="submit" :disabled="submitting || !canSubmit" @click="submit">
            {{ submitting ? '提交中…' : '确认投喂并提交审核' }}
          </button>
        </view>
      </template>

      <template v-else>
        <view v-if="loadingMine" class="mine-list">
          <view v-for="i in 3" :key="i" class="mine-card tm-skeleton" />
        </view>
        <view v-else-if="mineList.length" class="mine-list">
          <view v-for="item in mineList" :key="item.id" class="mine-card" @click="openDetail(item)">
            <view class="mine-top">
              <text class="mine-title">{{ item.title }}</text>
              <text :class="['pill', `p-${item.status}`]">{{ statusLabel(item.status) }}</text>
            </view>
            <view class="mine-meta">
              <text>{{ item.netdisk_label }}</text>
              <text v-if="item.category"> · {{ item.category.name }}</text>
              <text> · {{ formatTime(item.created_at) }}</text>
            </view>
            <text v-if="item.status === 'rejected' && item.reject_reason" class="mine-reason">驳回原因：{{ item.reject_reason }}</text>
          </view>
        </view>
        <view v-else class="empty">
          <text class="empty-title">还没有投喂记录</text>
          <text class="empty-sub">分享一份学习资料，审核通过可得长期获取额度</text>
          <button class="empty-btn" @click="tab = 'post'">去投喂</button>
        </view>
      </template>
    </template>
  </view>
</template>

<script setup lang="ts">
import { onLoad } from '@dcloudio/uni-app'
import { computed, ref } from 'vue'
import {
  getMaterialMeta,
  listMyMaterials,
  parseShareText,
  submitMaterial,
  type MaterialCategory,
  type MaterialItem,
  type MaterialMeta,
} from '@/api/material'
import ModulePlaceholder from '@/components/ModulePlaceholder.vue'
import { useAuthStore } from '@/stores/auth'
import { useModulesStore } from '@/stores/modules'

const auth = useAuthStore()
const modules = useModulesStore()
const moduleEnabled = computed(() => modules.isEnabled('module_material_post'))

const tab = ref<'post' | 'mine'>('post')
const meta = ref<MaterialMeta | null>(null)
const categories = ref<MaterialCategory[]>([])
const shareText = ref('')
const title = ref('')
const categoryId = ref('')
const agreed = ref(false)
const submitting = ref(false)
const mineList = ref<MaterialItem[]>([])
const loadingMine = ref(false)

const parsed = computed(() => parseShareText(shareText.value))
const bonus = computed(() => meta.value?.bonus_per_approved ?? 10)
const canSubmit = computed(() => agreed.value && Boolean(parsed.value.share_url) && shareText.value.trim().length >= 5)

function netdiskLabel(type: string) {
  const map: Record<string, string> = { baidu: '百度网盘', quark: '夸克网盘', aliyun: '阿里云盘', other: '其他资源' }
  return map[type] ?? '其他资源'
}

function statusLabel(status: string) {
  const map: Record<string, string> = { pending: '审核中', approved: '已通过', rejected: '已驳回', offline: '已下架' }
  return map[status] ?? status
}

function formatTime(value: string) {
  const d = new Date(value)
  if (Number.isNaN(d.getTime())) return ''
  return `${d.getMonth() + 1}月${d.getDate()}日`
}

async function loadMeta() {
  try {
    meta.value = await getMaterialMeta(auth.accessToken || undefined)
    categories.value = meta.value.categories
  } catch {
    /* ignore */
  }
}

async function loadMine() {
  if (!auth.isAuthenticated) return
  loadingMine.value = true
  try {
    const res = await listMyMaterials(auth.accessToken || '')
    mineList.value = res.items
  } catch {
    uni.showToast({ title: '加载失败', icon: 'none' })
  } finally {
    loadingMine.value = false
  }
}

function switchMine() {
  tab.value = 'mine'
  void loadMine()
}

async function submit() {
  if (!auth.isAuthenticated) {
    uni.navigateTo({ url: '/pages/login/index' })
    return
  }
  if (!canSubmit.value) {
    if (!parsed.value.share_url) uni.showToast({ title: '请粘贴含网盘链接的分享文案', icon: 'none' })
    else if (!agreed.value) uni.showToast({ title: '请先勾选版权承诺', icon: 'none' })
    return
  }
  submitting.value = true
  try {
    await submitMaterial(auth.accessToken || '', {
      share_text: shareText.value.trim(),
      title: title.value.trim() || undefined,
      category_id: categoryId.value || undefined,
      agreement_accepted: true,
    })
    uni.showToast({ title: '已提交审核', icon: 'success' })
    shareText.value = ''
    title.value = ''
    categoryId.value = ''
    agreed.value = false
    tab.value = 'mine'
    await loadMine()
  } catch (error) {
    const err = error as { code?: string }
    uni.showToast({ title: err.code === 'MATERIAL_PARSE_FAILED' ? '未能识别网盘链接' : '提交失败，请稍后重试', icon: 'none' })
  } finally {
    submitting.value = false
  }
}

function openDetail(item: MaterialItem) {
  uni.navigateTo({ url: `/pages/material-detail/index?id=${encodeURIComponent(item.id)}` })
}

function goBack() {
  const pages = getCurrentPages()
  if (pages.length > 1) uni.navigateBack()
  else uni.reLaunch({ url: '/pages/material/index' })
}

onLoad(async (query) => {
  await modules.ensureReady()
  if (!moduleEnabled.value) return
  if (String((query as Record<string, string>)?.tab || '') === 'mine') {
    tab.value = 'mine'
    void loadMine()
  }
  void loadMeta()
})
</script>

<style lang="scss" scoped>
.page { min-height: 100vh; background: #F4F6F4; padding-bottom: 60rpx; }
.head { display: flex; align-items: center; gap: 16rpx; padding: calc(var(--status-bar-height) + 16rpx) 24rpx 12rpx; }
.head-back { width: 60rpx; height: 60rpx; border-radius: 30rpx; background: #fff; font-size: 40rpx; line-height: 54rpx; text-align: center; }
.head-title { font-size: 34rpx; font-weight: 800; color: #191B1F; }

.tabs { display: flex; gap: 8rpx; margin: 8rpx 24rpx 20rpx; padding: 6rpx; border-radius: 999rpx; background: #E9EFEA; }
.tab { flex: 1; height: 68rpx; line-height: 68rpx; border-radius: 999rpx; text-align: center; font-size: 25rpx; color: #6B7280; }
.tab.active { background: #0A6B42; color: #fff; font-weight: 700; }

.reward { margin: 0 24rpx 20rpx; padding: 20rpx; border-radius: 22rpx; background: #E7F9EF; display: flex; gap: 14rpx; align-items: center; }
.reward-ic { width: 52rpx; height: 52rpx; border-radius: 16rpx; background: #0BBF63; color: #fff; display: flex; align-items: center; justify-content: center; font-size: 24rpx; }
.reward-title { display: block; font-size: 24rpx; font-weight: 700; color: #0A6B42; }
.reward-sub { display: block; margin-top: 6rpx; font-size: 20rpx; color: #4B8A68; }

.form-card { margin: 0 24rpx; padding: 26rpx; border-radius: 28rpx; background: #fff; }
.form-head { display: flex; align-items: center; }
.form-label { font-size: 24rpx; font-weight: 700; color: #191B1F; }
.parse-tag { margin-left: auto; padding: 4rpx 12rpx; border-radius: 8rpx; background: #E7F9EF; color: #08A152; font-size: 19rpx; }
.form-textarea { margin-top: 16rpx; width: 100%; height: 300rpx; padding: 20rpx; box-sizing: border-box; border-radius: 18rpx; background: #F7F8F7; font-size: 24rpx; color: #191B1F; line-height: 1.6; }
.counter { display: block; margin-top: 8rpx; text-align: right; font-size: 19rpx; color: #AEB3BB; }
.parsed { margin-top: 18rpx; padding: 20rpx; border-radius: 18rpx; background: #F0FBF5; }
.parsed-title { display: block; font-size: 22rpx; font-weight: 700; color: #0A6B42; margin-bottom: 10rpx; }
.parsed-row { display: flex; gap: 12rpx; padding: 6rpx 0; }
.parsed-label { flex: none; width: 120rpx; font-size: 21rpx; color: #8A9A90; }
.parsed-value { flex: 1; min-width: 0; font-size: 21rpx; color: #191B1F; word-break: break-all; }
.parsed-value.link { color: #2B6CE6; }
.parsed-value.code { font-weight: 800; letter-spacing: 2rpx; }
.parse-error { display: block; margin-top: 14rpx; font-size: 21rpx; color: #F04438; }

.form-block { margin-top: 24rpx; }
.form-input { margin-top: 12rpx; height: 76rpx; padding: 0 20rpx; border-radius: 16rpx; background: #F7F8F7; font-size: 24rpx; }
.chips { display: flex; flex-wrap: wrap; gap: 12rpx; margin-top: 12rpx; }
.chip { padding: 10rpx 20rpx; border-radius: 999rpx; background: #F2F4F3; color: #6B7280; font-size: 22rpx; }
.chip.active { background: #E7F9EF; color: #08A152; font-weight: 700; }

.agree { display: flex; align-items: flex-start; gap: 12rpx; margin-top: 26rpx; }
.checkbox { flex: none; width: 34rpx; height: 34rpx; margin-top: 2rpx; border-radius: 8rpx; border: 1rpx solid #D1D5DB; color: #fff; font-size: 22rpx; display: flex; align-items: center; justify-content: center; }
.checkbox.checked { background: #0BBF63; border-color: #0BBF63; }
.agree-text { font-size: 21rpx; color: #6B7280; line-height: 1.6; }

.submit { margin-top: 26rpx; height: 88rpx; line-height: 88rpx; border-radius: 44rpx; background: linear-gradient(135deg, #12D978, #08A152); color: #fff; font-size: 27rpx; font-weight: 700; }
.submit::after { border: 0; }
.submit[disabled] { opacity: .55; }

.mine-list { padding: 0 24rpx; display: flex; flex-direction: column; gap: 16rpx; }
.mine-card { padding: 22rpx; border-radius: 24rpx; background: #fff; }
.mine-card.tm-skeleton { height: 150rpx; }
.mine-top { display: flex; align-items: flex-start; gap: 12rpx; }
.mine-title { flex: 1; min-width: 0; font-size: 26rpx; font-weight: 700; color: #191B1F; line-height: 1.4; }
.pill { flex: none; padding: 4rpx 12rpx; border-radius: 8rpx; font-size: 19rpx; }
.p-pending { background: #FFF4E5; color: #B7791F; }
.p-approved { background: #E7F9EF; color: #08A152; }
.p-rejected { background: #FEECEB; color: #D92D20; }
.p-offline { background: #F2F4F3; color: #6B7280; }
.mine-meta { margin-top: 10rpx; font-size: 20rpx; color: #AEB3BB; }
.mine-reason { display: block; margin-top: 10rpx; font-size: 21rpx; color: #D92D20; }

.empty { display: flex; flex-direction: column; align-items: center; padding: 120rpx 40rpx; }
.empty-title { font-size: 28rpx; font-weight: 700; color: #191B1F; }
.empty-sub { margin-top: 12rpx; font-size: 22rpx; color: #AEB3BB; text-align: center; }
.empty-btn { margin-top: 28rpx; height: 76rpx; padding: 0 52rpx; line-height: 76rpx; border-radius: 38rpx; background: #0A6B42; color: #fff; font-size: 25rpx; }
.empty-btn::after { border: 0; }
</style>
