<template>
  <view class="page">
    <view class="filters">
      <text :class="['chip', { on: status === 'pending_review' }]" @click="switchStatus('pending_review')">待审核</text>
      <text :class="['chip', { on: status === '' }]" @click="switchStatus('')">全部</text>
    </view>

    <view v-if="loading" class="tip">加载中…</view>
    <view v-else-if="!items.length" class="tip">暂无组团活动</view>
    <view v-else class="list">
      <view v-for="item in items" :key="item.id" class="card" @click="openDetail(item)">
        <view class="card-head">
          <text class="title">{{ item.title }}</text>
          <text :class="['tag', statusTone(item.status)]">{{ statusText(item.status) }}</text>
        </view>
        <text class="meta">{{ item.category_label }} · {{ formatDateTime(item.starts_at) }}</text>
        <text class="meta">{{ item.location }} · {{ item.participant_count }}/{{ item.participant_limit }} 人</text>
        <text class="meta">发起人 {{ item.creator?.nickname || '—' }}</text>
      </view>
    </view>
  </view>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { onLoad, onShow } from '@dcloudio/uni-app'
import { ApiError } from '@/api/http'
import { listCommunityActivities, type AdminCommunityActivity } from '@/api/admin/community'
import { useAdminGuard } from '@/composables/use-admin-guard'
import { useAdminReviewSelection } from '@/stores/admin-review-selection'
import { formatDateTime } from '@/utils/dateTime'

const guard = useAdminGuard()
const selection = useAdminReviewSelection()

const status = ref('pending_review')
const loading = ref(true)
const items = ref<AdminCommunityActivity[]>([])
let loaded = false

onLoad(() => {
  if (!guard.requireSession()) return
  void load()
})

onShow(() => {
  // 从详情页处理完返回时刷新，避免已处理活动仍显示
  if (loaded) void load()
})

function statusText(value: string) {
  return ({
    pending_review: '待审核', recruiting: '招募中', ongoing: '进行中', finished: '已结束',
    cancelled: '已关闭', rejected: '未通过',
  } as Record<string, string>)[value] || value
}

function statusTone(value: string) {
  if (value === 'pending_review') return 'orange'
  if (value === 'rejected' || value === 'cancelled') return 'gray'
  return 'blue'
}

function switchStatus(next: string) {
  if (status.value === next) return
  status.value = next
  void load()
}

async function load() {
  loading.value = true
  try {
    const result = await listCommunityActivities(status.value || undefined)
    items.value = result.items
    loaded = true
  } catch (error) {
    if (guard.handleAuthError(error)) return
    uni.showToast({ title: error instanceof ApiError ? error.message : '加载失败', icon: 'none' })
  } finally {
    loading.value = false
  }
}

function openDetail(item: AdminCommunityActivity) {
  selection.activity = item
  uni.navigateTo({ url: '/pages/admin/activity-detail/index' })
}
</script>

<style scoped>
.page{min-height:100vh;background:#F4F6F4;padding:20rpx 24rpx 48rpx;box-sizing:border-box}
.filters{display:flex;gap:12rpx;margin-bottom:20rpx}
.chip{flex:1;padding:16rpx 0;border-radius:16rpx;background:#fff;color:#767C85;font-size:26rpx;text-align:center}
.chip.on{background:#E7F9EF;color:#087A43;font-weight:700}
.tip{padding:120rpx 0;color:#AEB3BB;font-size:26rpx;text-align:center}
.card{margin-bottom:20rpx;padding:28rpx;border-radius:28rpx;background:#fff}
.card-head{display:flex;align-items:center;justify-content:space-between;gap:20rpx;margin-bottom:12rpx}
.title{flex:1;color:#191B1F;font-size:30rpx;font-weight:700;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.tag{flex:none;padding:6rpx 16rpx;border-radius:999rpx;font-size:20rpx;background:#EAF2FF;color:#1D4ED8}
.tag.orange{background:#FFF2E5;color:#B74A05}
.tag.gray{background:#F1F3F1;color:#767C85}
.meta{display:block;margin-top:8rpx;color:#767C85;font-size:24rpx}
</style>
