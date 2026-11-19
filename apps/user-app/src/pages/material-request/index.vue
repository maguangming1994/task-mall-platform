<template>
  <view class="page">
    <ModulePlaceholder v-if="modules.resolved && !moduleEnabled" code="module_material_request" />
    <template v-else>
      <view class="head">
        <view class="head-back" @click="goBack">‹</view>
        <text class="head-title">提交需求</text>
      </view>

      <text class="intro">没找到想要的资料？告诉我们具体需求，平台会尽快帮你寻找。</text>

      <view class="tabs">
        <view :class="['tab', { active: tab === 'form' }]" @click="tab = 'form'">填写需求</view>
        <view :class="['tab', { active: tab === 'mine' }]" @click="switchMine">我的需求</view>
      </view>

      <template v-if="tab === 'form'">
        <view class="form-card">
          <text class="form-label">资料名称</text>
          <input v-model="title" class="form-input" maxlength="60" placeholder="例如：高等数学期末历年真题（至少 2 个字）" />

          <view class="label-row">
            <text class="form-label">具体要求</text>
          </view>
          <textarea
            v-model="requirement"
            class="form-textarea"
            maxlength="2000"
            placeholder="请说明课程、版本、年份等，至少 5 个字"
          />
          <text class="counter">{{ requirement.length }}/2000</text>

          <text class="form-tip">需求仅你和管理员可见，不消耗资料获取次数。</text>
          <button class="submit" :disabled="submitting || !canSubmit" @click="submit">
            {{ submitting ? '提交中…' : '提交需求' }}
          </button>
        </view>

        <view class="foot">
          <text class="foot-link" @click="openLegal">平台用户协议</text>
          <text class="foot-dot">·</text>
          <text class="foot-link" @click="openReport">侵权投诉与反馈</text>
          <text class="foot-note">全国高校学习资料共享平台 · 纯公益知识交流</text>
        </view>
      </template>

      <template v-else>
        <view v-if="loadingMine" class="mine-list">
          <view v-for="i in 3" :key="i" class="mine-card tm-skeleton" />
        </view>
        <view v-else-if="mineList.length" class="mine-list">
          <view v-for="item in mineList" :key="item.id" class="mine-card">
            <view class="mine-top">
              <text class="mine-title">{{ item.title }}</text>
              <text :class="['pill', `p-${item.status}`]">{{ statusLabel(item.status) }}</text>
            </view>
            <text class="mine-req">{{ item.requirement }}</text>
            <view class="mine-meta">
              <text>{{ formatTime(item.created_at) }}</text>
              <text v-if="item.admin_note" class="mine-note">管理员回复：{{ item.admin_note }}</text>
            </view>
          </view>
          <view class="load-more" @click="loadMine(false)">
            <text v-if="loadingMore">加载中…</text>
            <text v-else-if="hasMore">加载更多</text>
            <text v-else>已经到底啦</text>
          </view>
        </view>
        <view v-else class="empty">
          <text class="empty-title">还没有提交过需求</text>
          <text class="empty-sub">把想要的资料告诉我们，我们会尽力帮你找到</text>
          <button class="empty-btn" @click="tab = 'form'">去填写需求</button>
        </view>
      </template>
    </template>
  </view>
</template>

<script setup lang="ts">
import { onLoad } from '@dcloudio/uni-app'
import { computed, ref } from 'vue'
import {
  listMyMaterialRequests,
  submitMaterialRequest,
  type MaterialRequestItem,
} from '@/api/material'
import ModulePlaceholder from '@/components/ModulePlaceholder.vue'
import { useAuthStore } from '@/stores/auth'
import { useModulesStore } from '@/stores/modules'
import { navigateToLogin } from '@/utils/authRedirect'

const PAGE_SIZE = 20

const auth = useAuthStore()
const modules = useModulesStore()
// 新增模块采用 fail-close：服务端未明确开启时不展示入口与内容
const moduleEnabled = computed(() => modules.isLaunched('module_material_request'))

const tab = ref<'form' | 'mine'>('form')
const title = ref('')
const requirement = ref('')
const submitting = ref(false)
const mineList = ref<MaterialRequestItem[]>([])
const loadingMine = ref(false)
const loadingMore = ref(false)
const minePage = ref(1)
const hasMore = ref(false)

const canSubmit = computed(() => title.value.trim().length >= 2 && requirement.value.trim().length >= 5)

function statusLabel(status: string) {
  const map: Record<string, string> = { pending: '待处理', processing: '处理中', done: '已找到', closed: '已关闭' }
  return map[status] ?? status
}

function formatTime(value: string) {
  const d = new Date(value)
  if (Number.isNaN(d.getTime())) return ''
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

async function loadMine(reset = true) {
  if (!auth.isAuthenticated) return
  if (reset) {
    minePage.value = 1
    loadingMine.value = true
  } else {
    if (loadingMore.value || !hasMore.value) return
    loadingMore.value = true
  }
  try {
    const res = await listMyMaterialRequests(auth.accessToken || '', minePage.value, PAGE_SIZE)
    mineList.value = reset ? res.items : [...mineList.value, ...res.items]
    hasMore.value = mineList.value.length < res.pagination.total
    if (res.items.length > 0) minePage.value = res.pagination.page + 1
  } catch {
    uni.showToast({ title: '加载失败', icon: 'none' })
  } finally {
    loadingMine.value = false
    loadingMore.value = false
  }
}

function switchMine() {
  if (!auth.isAuthenticated) {
    navigateToLogin()
    return
  }
  tab.value = 'mine'
  void loadMine()
}

async function submit() {
  if (!auth.isAuthenticated) {
    uni.navigateTo({ url: '/pages/login/index' })
    return
  }
  if (!canSubmit.value) {
    uni.showToast({ title: '请完整填写资料名称与具体要求', icon: 'none' })
    return
  }
  submitting.value = true
  try {
    await submitMaterialRequest(auth.accessToken || '', {
      title: title.value.trim(),
      requirement: requirement.value.trim(),
    })
    uni.showToast({ title: '需求已提交', icon: 'success' })
    title.value = ''
    requirement.value = ''
    tab.value = 'mine'
    await loadMine()
  } catch {
    uni.showToast({ title: '提交失败，请稍后重试', icon: 'none' })
  } finally {
    submitting.value = false
  }
}

function openLegal() {
  uni.navigateTo({ url: '/pages/privacy/index' })
}

function openReport() {
  uni.showToast({ title: '可在资料详情页发起侵权举报', icon: 'none' })
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
    if (!auth.isAuthenticated) {
      navigateToLogin()
      return
    }
    tab.value = 'mine'
    void loadMine()
  }
})
</script>

<style lang="scss" scoped>
.page { min-height: 100vh; background: #F4F6F4; padding-bottom: 60rpx; }
.head { display: flex; align-items: center; gap: 16rpx; padding: calc(var(--status-bar-height) + 16rpx) 24rpx 12rpx; }
.head-back { width: 60rpx; height: 60rpx; border-radius: 30rpx; background: #fff; font-size: 40rpx; line-height: 54rpx; text-align: center; }
.head-title { font-size: 34rpx; font-weight: 800; color: #191B1F; }
.intro { display: block; padding: 0 28rpx 18rpx; font-size: 23rpx; color: #6B7280; line-height: 1.6; }

.tabs { display: flex; gap: 8rpx; margin: 0 24rpx 20rpx; padding: 6rpx; border-radius: 999rpx; background: #E9EFEA; }
.tab { flex: 1; height: 68rpx; line-height: 68rpx; border-radius: 999rpx; text-align: center; font-size: 25rpx; color: #6B7280; }
.tab.active { background: #0A6B42; color: #fff; font-weight: 700; }

.form-card { margin: 0 24rpx; padding: 26rpx; border-radius: 28rpx; background: #fff; }
.form-label { display: block; font-size: 24rpx; font-weight: 700; color: #191B1F; }
.form-input { margin-top: 14rpx; height: 82rpx; padding: 0 20rpx; border-radius: 16rpx; background: #F7F8F7; font-size: 24rpx; }
.label-row { margin-top: 26rpx; }
.form-textarea { margin-top: 14rpx; width: 100%; height: 300rpx; padding: 20rpx; box-sizing: border-box; border-radius: 16rpx; background: #F7F8F7; font-size: 24rpx; line-height: 1.6; }
.counter { display: block; margin-top: 8rpx; text-align: right; font-size: 19rpx; color: #AEB3BB; }
.form-tip { display: block; margin-top: 14rpx; font-size: 21rpx; color: #8A9A90; line-height: 1.6; }
.submit { margin-top: 26rpx; height: 88rpx; line-height: 88rpx; border-radius: 44rpx; background: linear-gradient(135deg, #12D978, #08A152); color: #fff; font-size: 27rpx; font-weight: 700; }
.submit::after { border: 0; }
.submit[disabled] { opacity: .55; }

.foot { margin-top: 70rpx; display: flex; flex-wrap: wrap; align-items: center; justify-content: center; gap: 12rpx; }
.foot-link { font-size: 22rpx; color: #AEB3BB; }
.foot-dot { font-size: 20rpx; color: #D1D5DB; }
.foot-note { width: 100%; margin-top: 8rpx; text-align: center; font-size: 19rpx; color: #C4C9CF; }

.mine-list { padding: 0 24rpx; display: flex; flex-direction: column; gap: 16rpx; }
.mine-card { padding: 22rpx; border-radius: 24rpx; background: #fff; }
.mine-card.tm-skeleton { height: 150rpx; }
.mine-top { display: flex; align-items: flex-start; gap: 12rpx; }
.mine-title { flex: 1; min-width: 0; font-size: 26rpx; font-weight: 700; color: #191B1F; line-height: 1.4; }
.pill { flex: none; padding: 4rpx 12rpx; border-radius: 8rpx; font-size: 19rpx; }
.p-pending { background: #FFF4E5; color: #B7791F; }
.p-processing { background: #E8F0FE; color: #2B6CE6; }
.p-done { background: #E7F9EF; color: #08A152; }
.p-closed { background: #F2F4F3; color: #6B7280; }
.mine-req { display: block; margin-top: 10rpx; font-size: 22rpx; color: #6B7280; line-height: 1.6; }
.mine-meta { display: flex; flex-wrap: wrap; gap: 10rpx; margin-top: 12rpx; font-size: 20rpx; color: #AEB3BB; }
.mine-note { width: 100%; color: #08A152; }
.load-more { padding: 20rpx 0 8rpx; text-align: center; font-size: 22rpx; color: #AEB3BB; }

.empty { display: flex; flex-direction: column; align-items: center; padding: 120rpx 40rpx; }
.empty-title { font-size: 28rpx; font-weight: 700; color: #191B1F; }
.empty-sub { margin-top: 12rpx; font-size: 22rpx; color: #AEB3BB; text-align: center; }
.empty-btn { margin-top: 28rpx; height: 76rpx; padding: 0 52rpx; line-height: 76rpx; border-radius: 38rpx; background: #0A6B42; color: #fff; font-size: 25rpx; }
.empty-btn::after { border: 0; }
</style>
