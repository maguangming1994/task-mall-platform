<template>
  <view class="page">
    <view class="filters">
      <text
        v-for="option in statusOptions"
        :key="option.key"
        :class="['chip', { on: status === option.key }]"
        @click="switchStatus(option.key)"
      >{{ option.label }}</text>
    </view>

    <view v-if="loading" class="tip">加载中…</view>
    <view v-else-if="!items.length" class="tip">当前没有实名认证记录</view>
    <view v-else class="list">
      <view v-for="item in items" :key="item.id" class="card" @click="openDetail(item)">
        <view class="card-head">
          <text class="name">{{ item.user?.nickname || '用户' }}</text>
          <text :class="['tag', item.status]">{{ statusText(item.status) }}</text>
        </view>
        <text class="meta">姓名 {{ item.real_name_masked || '—' }} · 证件 {{ item.identity_number_masked || '—' }}</text>
        <text class="meta">提交时间 {{ formatDateTime(item.submitted_at) }}</text>
      </view>
      <button v-if="hasMore" class="more" :disabled="loadingMore" @click="loadMore">
        {{ loadingMore ? '加载中…' : '加载更多' }}
      </button>
    </view>
  </view>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue'
import { onLoad } from '@dcloudio/uni-app'
import { ApiError } from '@/api/http'
import { listIdentityVerifications, type AdminIdentityRecord } from '@/api/admin/identity'
import { useAdminGuard } from '@/composables/use-admin-guard'
import { useAdminReviewSelection } from '@/stores/admin-review-selection'
import { formatDateTime } from '@/utils/dateTime'

const guard = useAdminGuard()
const selection = useAdminReviewSelection()

const statusOptions = [
  { key: 'pending', label: '待审核' },
  { key: 'verified', label: '已通过' },
  { key: 'failed', label: '未通过' },
]

const status = ref('pending')
const items = ref<AdminIdentityRecord[]>([])
const loading = ref(true)
const loadingMore = ref(false)
const page = ref(1)
const total = ref(0)

const hasMore = computed(() => items.value.length < total.value)

onLoad(() => {
  if (!guard.requireSession()) return
  void load()
})

function statusText(value: string) {
  return ({ pending: '待审核', verified: '已通过', failed: '未通过', provider_error: '渠道异常' } as Record<string, string>)[value] || value
}

function switchStatus(key: string) {
  if (status.value === key) return
  status.value = key
  void load()
}

async function load() {
  loading.value = true
  page.value = 1
  try {
    const result = await listIdentityVerifications({ status: status.value, page: 1 })
    items.value = result.items
    total.value = result.pagination.total
  } catch (error) {
    if (guard.handleAuthError(error)) return
    uni.showToast({ title: error instanceof ApiError ? error.message : '加载失败', icon: 'none' })
  } finally {
    loading.value = false
  }
}

async function loadMore() {
  if (loadingMore.value) return
  loadingMore.value = true
  try {
    const next = page.value + 1
    const result = await listIdentityVerifications({ status: status.value, page: next })
    items.value = [...items.value, ...result.items]
    total.value = result.pagination.total
    page.value = next
  } catch (error) {
    if (guard.handleAuthError(error)) return
    uni.showToast({ title: '加载更多失败', icon: 'none' })
  } finally {
    loadingMore.value = false
  }
}

function openDetail(item: AdminIdentityRecord) {
  selection.identity = item
  uni.navigateTo({ url: '/pages/admin/identity-detail/index' })
}
</script>

<style scoped>
.page{min-height:100vh;background:#F4F6F4;padding:20rpx 24rpx 48rpx;box-sizing:border-box}
.filters{display:flex;gap:16rpx;margin-bottom:20rpx}
.chip{flex:1;padding:18rpx 0;border-radius:18rpx;background:#fff;color:#767C85;font-size:26rpx;text-align:center;border:2rpx solid transparent}
.chip.on{background:#E7F9EF;border-color:#08A152;color:#087A43;font-weight:700}
.tip{padding:120rpx 0;color:#AEB3BB;font-size:26rpx;text-align:center}
.card{margin-bottom:20rpx;padding:28rpx 28rpx;border-radius:28rpx;background:#fff}
.card-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:16rpx}
.name{color:#191B1F;font-size:30rpx;font-weight:700}
.tag{padding:6rpx 16rpx;border-radius:999rpx;font-size:20rpx;background:#EAF2FF;color:#1D4ED8}
.tag.verified{background:#E7F9EF;color:#08A152}
.tag.failed{background:#FFECEA;color:#C0261D}
.tag.provider_error{background:#FFF2E5;color:#B74A05}
.meta{display:block;margin-top:8rpx;color:#767C85;font-size:24rpx}
.more{height:80rpx;margin-top:12rpx;border-radius:40rpx;background:#fff;color:#08A152;font-size:26rpx;line-height:80rpx}
.more::after{border:0}
</style>
