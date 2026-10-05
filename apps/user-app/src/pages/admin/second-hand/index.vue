<template>
  <view class="page">
    <view class="tabs">
      <view
        v-for="tab in tabs"
        :key="tab.value"
        :class="['tab', active === tab.value && 'active']"
        @click="switchTab(tab.value)"
      >
        {{ tab.label }}
      </view>
    </view>

    <view v-if="items.length === 0 && !loading" class="empty">
      <text class="empty-mark">✓</text>
      <text class="empty-text">{{ active === 'pending' ? '暂无待审核商品' : '暂无相关记录' }}</text>
      <text class="empty-btn" @click="reload">刷新</text>
    </view>

    <view v-else class="list">
      <view v-for="item in items" :key="item.id" class="card" @click="openDetail(item.id)">
        <image
          class="thumb"
          :src="resolveApiAssetUrl(item.cover) || defaultThumb"
          mode="aspectFill"
        />
        <view class="card-main">
          <text class="title">{{ item.title }}</text>
          <view class="meta-row">
            <text class="price">¥{{ item.price }}</text>
            <text v-if="item.category" class="tag">{{ item.category.name }}</text>
            <text :class="['status', `st-${item.audit_status}`]">{{ statusLabel(item.audit_status) }}</text>
          </view>
          <text class="seller">
            {{ item.seller?.nickname || '同学' }} ·
            <text v-if="item.seller?.identity_verified" class="verified">已实名</text>
            <text v-else>未实名</text>
            · 信用{{ item.seller?.rating_average ?? '—' }} ·
            {{ formatTime(item.submitted_at || item.created_at) }}
          </text>
        </view>
        <text class="arrow">›</text>
      </view>

      <view v-if="loading" class="loading-tip">加载中…</view>
      <view v-else-if="finished" class="loading-tip">— 没有更多了 —</view>
      <view v-else-if="items.length" class="loading-tip" @click="loadMore">上拉或点击加载更多</view>
    </view>
  </view>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { onShow } from '@dcloudio/uni-app'
import { resolveApiAssetUrl, ApiError } from '@/api/http'
import {
  fetchSecondHandItems,
  type SecondHandReviewItem,
} from '@/api/admin/review'
import { useAdminStore } from '@/stores/admin'
import { formatRelativeTime } from '../format'

const admin = useAdminStore()
const defaultThumb = '/static/icons/service-tasks.svg'

const tabs = [
  { label: '待审核', value: 'pending' },
  { label: '已通过', value: 'approved' },
  { label: '已拒绝', value: 'rejected' },
] as const

type TabValue = typeof tabs[number]['value']
const active = ref<TabValue>('pending')
const items = ref<SecondHandReviewItem[]>([])
const page = ref(1)
const pageSize = 20
const total = ref(0)
const loading = ref(false)
const finished = ref(false)

onShow(() => {
  if (!admin.isLoggedIn) {
    uni.redirectTo({ url: '/pages/admin/login/index' })
    return
  }
  void reload()
})

function statusLabel(status: string) {
  return status === 'approved' ? '已通过' : status === 'rejected' ? '已拒绝' : '待审核'
}

function formatTime(value?: string | null) {
  return value ? formatRelativeTime(value) : ''
}

function switchTab(value: TabValue) {
  if (active.value === value) return
  active.value = value
  void reload()
}

async function reload() {
  page.value = 1
  finished.value = false
  await loadPage()
}

async function loadMore() {
  if (loading.value || finished.value) return
  page.value += 1
  await loadPage()
}

async function loadPage() {
  loading.value = true
  try {
    const result = await fetchSecondHandItems({
      audit_status: active.value,
      page: page.value,
      page_size: pageSize,
    })
    if (page.value === 1) {
      items.value = result.items
    } else {
      items.value = items.value.concat(result.items)
    }
    total.value = result.pagination.total
    finished.value = items.value.length >= result.pagination.total
  } catch (error) {
    // 加载下一页失败时回退页码，避免永久跳过该页
    if (page.value > 1) page.value -= 1
    handleError(error)
  } finally {
    loading.value = false
  }
}

function openDetail(id: string) {
  uni.navigateTo({ url: `/pages/admin/second-hand-detail/index?id=${id}` })
}

function handleError(error: unknown) {
  if (error instanceof ApiError && (error.code === 'ADMIN_SESSION_EXPIRED' || error.statusCode === 401)) {
    admin.clear()
    uni.redirectTo({ url: '/pages/admin/login/index' })
    return
  }
  uni.showToast({ title: error instanceof Error ? error.message : '加载失败', icon: 'none' })
}
</script>

<style scoped>
.page{min-height:100vh;background:#F4F6F4}
.tabs{display:flex;padding:16rpx 24rpx 8rpx;background:#F4F6F4;position:sticky;top:0;z-index:5}
.tab{flex:1;text-align:center;padding:20rpx 0;color:#767C85;font-size:28rpx;font-weight:600;border-radius:20rpx;margin:0 8rpx;background:transparent}
.tab.active{background:#fff;color:#08A152;box-shadow:0 2rpx 8rpx rgba(8,161,82,.12)}
.list{padding:8rpx 24rpx 48rpx}
.card{display:flex;align-items:center;padding:24rpx;margin-bottom:16rpx;border-radius:24rpx;background:#fff;box-shadow:0 2rpx 4rpx rgba(23,26,23,.03),0 8rpx 28rpx rgba(23,26,23,.04)}
.thumb{width:168rpx;height:168rpx;flex:none;border-radius:18rpx;background:#F0F2F0}
.card-main{flex:1;min-width:0;margin-left:22rpx}
.title{display:block;color:#191B1F;font-size:28rpx;font-weight:700;line-height:1.4;overflow:hidden;text-overflow:ellipsis;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical}
.meta-row{display:flex;align-items:center;gap:12rpx;margin-top:14rpx}
.price{color:#E54D42;font-size:32rpx;font-weight:800}
.tag{padding:4rpx 14rpx;border-radius:16rpx;background:#F0F2F0;color:#767C85;font-size:20rpx}
.status{padding:4rpx 14rpx;border-radius:16rpx;font-size:20rpx}
.st-pending{background:#FFF6DE;color:#B8860B}
.st-approved{background:#E7F9EF;color:#08A152}
.st-rejected{background:#FFEAEA;color:#D14343}
.seller{display:block;margin-top:12rpx;color:#AEB3BB;font-size:20rpx;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.verified{color:#08A152}
.arrow{margin-left:12rpx;color:#C6CBc6;font-size:40rpx;font-weight:300}
.empty{display:flex;align-items:center;flex-direction:column;padding:160rpx 48rpx}
.empty-mark{display:flex;align-items:center;justify-content:center;width:112rpx;height:112rpx;border-radius:50%;background:#E7F9EF;color:#08A152;font-size:52rpx}
.empty-text{margin-top:28rpx;color:#767C85;font-size:26rpx}
.empty-btn{margin-top:36rpx;padding:16rpx 56rpx;border-radius:36rpx;background:#08A152;color:#fff;font-size:26rpx}
.loading-tip{padding:28rpx;text-align:center;color:#AEB3BB;font-size:22rpx}
</style>
