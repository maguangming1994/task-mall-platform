<template>
  <view class="page">
    <ModulePlaceholder v-if="modules.resolved && !moduleEnabled" code="module_material" />
    <view v-else-if="!modules.resolved" class="skeleton-wrap">
      <view class="tm-skeleton skel-card" />
      <view class="tm-skeleton skel-card" />
    </view>
    <template v-else>
      <view class="head">
        <view class="head-back" @click="goBack">‹</view>
        <text class="head-title">我的获取</text>
      </view>

      <view class="tip">同一资料重复获取不再消耗额度，可随时回来复制链接。</view>

      <view v-if="loading" class="list">
        <view v-for="i in 3" :key="i" class="card"><view class="skel-line tm-skeleton" /><view class="skel-line short tm-skeleton" /></view>
      </view>
      <view v-else-if="items.length" class="list">
        <view v-for="item in items" :key="item.id" class="card">
          <view class="card-top">
            <text class="netdisk">{{ item.material.netdisk_label }}</text>
            <text v-if="item.material.category" class="cat">{{ item.material.category.name }}</text>
          </view>
          <text class="title" @click="openDetail(item.id)">{{ item.material.title }}</text>
          <text class="time">获取于 {{ formatRelativeTime(item.fetched_at) }}</text>

          <view class="row">
            <text class="row-label">分享链接</text>
            <text class="row-value link" @click="copy(item.material.share_url || '', '链接已复制')">{{ item.material.share_url || '—' }}</text>
          </view>
          <view v-if="item.material.extract_code" class="row">
            <text class="row-label">提取码</text>
            <text class="row-value code" @click="copy(item.material.extract_code || '', '提取码已复制')">{{ item.material.extract_code }}</text>
          </view>
          <view class="card-actions">
            <text class="link-btn" @click="openDetail(item.id)">查看详情 ›</text>
          </view>
        </view>

        <view class="load-more" @click="loadList(false)">
          <text v-if="loadingMore">加载中…</text>
          <text v-else-if="hasMore">加载更多</text>
          <text v-else>已经到底啦</text>
        </view>
      </view>
      <view v-else class="empty">
        <text class="empty-title">还没有获取记录</text>
        <text class="empty-sub">去资料广场找到想要的资料，获取后会出现在这里</text>
        <view class="empty-btn" @click="goSquare">去资料广场</view>
      </view>
    </template>
  </view>
</template>

<script setup lang="ts">
import { onReachBottom, onShow } from '@dcloudio/uni-app'
import { computed, ref } from 'vue'
import { listMyMaterialFetches, type MaterialFetchedItem } from '@/api/material'
import ModulePlaceholder from '@/components/ModulePlaceholder.vue'
import { useAuthStore } from '@/stores/auth'
import { useModulesStore } from '@/stores/modules'
import { formatRelativeTime } from '@/utils/dateTime'
import { navigateToLogin } from '@/utils/authRedirect'

const PAGE_SIZE = 20

const auth = useAuthStore()
const modules = useModulesStore()
const moduleEnabled = computed(() => modules.isLaunched('module_material'))

const items = ref<MaterialFetchedItem[]>([])
const loading = ref(false)
const loadingMore = ref(false)
const page = ref(1)
const hasMore = ref(false)

async function loadList(reset = true) {
  if (!auth.isAuthenticated) {
    navigateToLogin()
    return
  }
  if (reset) {
    page.value = 1
    loading.value = true
  } else {
    if (loadingMore.value || !hasMore.value) return
    loadingMore.value = true
  }
  try {
    const res = await listMyMaterialFetches(auth.accessToken || '', page.value, PAGE_SIZE)
    items.value = reset ? res.items : [...items.value, ...res.items]
    hasMore.value = items.value.length < res.pagination.total
    if (res.items.length > 0) page.value = res.pagination.page + 1
  } catch {
    uni.showToast({ title: '加载失败，请稍后重试', icon: 'none' })
  } finally {
    loading.value = false
    loadingMore.value = false
  }
}

function copy(text: string, toast: string) {
  if (!text) return
  uni.setClipboardData({ data: text, success: () => uni.showToast({ title: toast, icon: 'none' }) })
}

function openDetail(id: string) {
  uni.navigateTo({ url: `/pages/material-detail/index?id=${encodeURIComponent(id)}` })
}

function goSquare() {
  uni.navigateTo({ url: '/pages/material/index' })
}

function goBack() {
  const pages = getCurrentPages()
  if (pages.length > 1) uni.navigateBack()
  else uni.reLaunch({ url: '/pages/index/index' })
}

onShow(async () => {
  await modules.ensureReady()
  if (!moduleEnabled.value) return
  void loadList()
})

onReachBottom(() => {
  if (!moduleEnabled.value || loading.value) return
  void loadList(false)
})
</script>

<style lang="scss" scoped>
.page { min-height: 100vh; background: #F4F6F4; padding-bottom: 120rpx; }
.head { display: flex; align-items: center; gap: 16rpx; padding: calc(var(--status-bar-height) + 16rpx) 24rpx 12rpx; }
.head-back { width: 60rpx; height: 60rpx; border-radius: 30rpx; background: #fff; color: #191B1F; font-size: 40rpx; line-height: 54rpx; text-align: center; }
.head-title { font-size: 34rpx; font-weight: 800; color: #191B1F; }
.tip { margin: 4rpx 24rpx 20rpx; padding: 18rpx 24rpx; border-radius: 20rpx; background: #E8F6EF; color: #0A6B42; font-size: 23rpx; line-height: 1.5; }
.skeleton-wrap { padding: 24rpx; display: flex; flex-direction: column; gap: 20rpx; }
.skel-card { height: 220rpx; border-radius: 24rpx; }
.list { padding: 0 24rpx; display: flex; flex-direction: column; gap: 20rpx; }
.card { background: #fff; border-radius: 24rpx; padding: 24rpx; }
.card-top { display: flex; align-items: center; gap: 12rpx; margin-bottom: 10rpx; }
.netdisk { font-size: 21rpx; color: #0A6B42; background: #E8F6EF; padding: 4rpx 14rpx; border-radius: 999rpx; }
.cat { font-size: 21rpx; color: #6B7280; background: #F1F5F9; padding: 4rpx 14rpx; border-radius: 999rpx; }
.title { display: block; font-size: 30rpx; font-weight: 700; color: #191B1F; line-height: 1.4; }
.time { display: block; margin-top: 6rpx; font-size: 22rpx; color: #9CA3AF; }
.row { margin-top: 18rpx; display: flex; align-items: flex-start; gap: 16rpx; }
.row-label { width: 120rpx; flex-shrink: 0; font-size: 23rpx; color: #6B7280; padding-top: 4rpx; }
.row-value { flex: 1; font-size: 24rpx; line-height: 1.5; word-break: break-all; }
.link { color: #2563EB; }
.code { color: #D97706; font-weight: 600; }
.card-actions { margin-top: 18rpx; text-align: right; }
.link-btn { font-size: 24rpx; color: #0A6B42; }
.empty { padding: 140rpx 48rpx; text-align: center; }
.empty-title { display: block; font-size: 30rpx; font-weight: 700; color: #191B1F; }
.empty-sub { display: block; margin-top: 12rpx; font-size: 24rpx; color: #9CA3AF; line-height: 1.6; }
.empty-btn { display: inline-block; margin-top: 32rpx; padding: 16rpx 40rpx; border-radius: 999rpx; background: #0E8F58; color: #fff; font-size: 26rpx; }
.load-more { padding: 28rpx 0 8rpx; text-align: center; font-size: 22rpx; color: #AEB3BB; }
.skel-line { height: 28rpx; border-radius: 10rpx; margin-bottom: 16rpx; }
.short { width: 50%; }
</style>
