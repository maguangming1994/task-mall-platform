<template>
  <view class="page">
    <view class="tabs">
      <view :class="['tab', active === 'submitted' && 'active']" @click="switchTab('submitted')">待领取</view>
      <view :class="['tab', active === 'reviewing' && 'active']" @click="switchTab('reviewing')">审核中</view>
      <view :class="['tab', active === 'finished' && 'active']" @click="switchTab('finished')">已处理</view>
    </view>

    <view v-if="items.length === 0 && !loading" class="empty">
      <text class="empty-mark">✓</text>
      <text class="empty-text">{{ active === 'submitted' ? '暂无待领取的提交' : active === 'reviewing' ? '暂无审核中的提交' : '暂无处理记录' }}</text>
      <text class="empty-btn" @click="reload">刷新</text>
    </view>

    <view v-else class="list">
      <view v-for="item in items" :key="item.id" class="card" @click="openDetail(item.id)">
        <view class="card-head">
          <text class="title">{{ item.task_title || '任务提交' }}</text>
          <text :class="['status', `st-${item.status}`]">{{ statusLabel(item.status) }}</text>
        </view>
        <text class="sub-no">提交单号 #{{ item.submission_no }}</text>
        <view class="card-foot">
          <text class="time">提交于 {{ formatRelativeTime(item.submitted_at) }}</text>
          <text v-if="item.overdue" class="overdue">已超期</text>
          <text v-else-if="item.review_due_at" class="due">截止 {{ formatRelativeTime(item.review_due_at) }}</text>
        </view>
      </view>
    </view>

    <text v-if="loading" class="loading-tip">加载中…</text>
  </view>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { onShow } from '@dcloudio/uni-app'
import { ApiError } from '@/api/http'
import { fetchTaskSubmissions, type TaskSubmissionItem } from '@/api/admin/review'
import { useAdminStore } from '@/stores/admin'
import { formatRelativeTime } from '../format'

const admin = useAdminStore()
const active = ref<'submitted' | 'reviewing' | 'finished'>('submitted')
const items = ref<TaskSubmissionItem[]>([])
const loading = ref(false)

onShow(() => {
  if (!admin.isLoggedIn) {
    uni.redirectTo({ url: '/pages/admin/login/index' })
    return
  }
  void reload()
})

function statusLabel(status: string) {
  if (status === 'submitted') return '待领取'
  if (status === 'reviewing') return '审核中'
  if (status === 'approved') return '已通过'
  if (status === 'rejected') return '已驳回'
  if (status === 'withdrawn') return '已撤回'
  return status
}

async function switchTab(next: typeof active.value) {
  if (active.value === next) return
  active.value = next
  await reload()
}

async function reload() {
  loading.value = true
  try {
    // 已处理 = 通过 + 驳回，分别拉取后合并
    if (active.value === 'finished') {
      const [approved, rejected] = await Promise.all([
        fetchTaskSubmissions({ status: 'approved' }),
        fetchTaskSubmissions({ status: 'rejected' }),
      ])
      items.value = approved.items.concat(rejected.items)
    } else {
      items.value = (await fetchTaskSubmissions({ status: active.value })).items
    }
  } catch (error) {
    if (error instanceof ApiError && (error.code === 'ADMIN_SESSION_EXPIRED' || error.statusCode === 401)) {
      admin.clear()
      uni.redirectTo({ url: '/pages/admin/login/index' })
      return
    }
    uni.showToast({ title: error instanceof Error ? error.message : '加载失败', icon: 'none' })
  } finally {
    loading.value = false
  }
}

function openDetail(id: string) {
  uni.navigateTo({ url: `/pages/admin/task-detail/index?id=${id}` })
}
</script>

<style scoped>
.page{min-height:100vh;background:#F4F6F4}
.tabs{display:flex;padding:16rpx 24rpx 8rpx;position:sticky;top:0;z-index:5;background:#F4F6F4}
.tab{flex:1;text-align:center;padding:20rpx 0;margin:0 8rpx;border-radius:20rpx;color:#767C85;font-size:28rpx;font-weight:600}
.tab.active{background:#fff;color:#08A152;box-shadow:0 2rpx 8rpx rgba(8,161,82,.12)}
.list{padding:8rpx 24rpx 48rpx}
.card{margin-bottom:16rpx;padding:28rpx;border-radius:24rpx;background:#fff;box-shadow:0 2rpx 4rpx rgba(23,26,23,.03),0 8rpx 28rpx rgba(23,26,23,.04)}
.card-head{display:flex;align-items:flex-start;justify-content:space-between;gap:16rpx}
.title{flex:1;color:#191B1F;font-size:28rpx;font-weight:700;line-height:1.5}
.status{flex:none;padding:6rpx 16rpx;border-radius:16rpx;font-size:20rpx}
.st-submitted{background:#EAF2FF;color:#3B82F6}
.st-reviewing{background:#FFF6DE;color:#B8860B}
.st-approved{background:#E7F9EF;color:#08A152}
.st-rejected{background:#FFEAEA;color:#D14343}
.st-withdrawn{background:#F0F2F0;color:#8A918A}
.sub-no{display:block;margin-top:12rpx;color:#AEB3BB;font-size:20rpx}
.card-foot{display:flex;align-items:center;gap:16rpx;margin-top:14rpx}
.time{color:#767C85;font-size:22rpx}
.due{margin-left:auto;color:#B8860B;font-size:22rpx}
.overdue{margin-left:auto;color:#E54D42;font-size:22rpx;font-weight:700}
.empty{display:flex;align-items:center;flex-direction:column;padding:160rpx 48rpx}
.empty-mark{display:flex;align-items:center;justify-content:center;width:112rpx;height:112rpx;border-radius:50%;background:#E7F9EF;color:#08A152;font-size:52rpx}
.empty-text{margin-top:28rpx;color:#777;font-size:26rpx}
.empty-btn{margin-top:36rpx;padding:16rpx 56rpx;border-radius:36rpx;background:#08A152;color:#fff;font-size:26rpx}
.loading-tip{display:block;padding:28rpx;text-align:center;color:#AEB3BB;font-size:22rpx}
</style>
