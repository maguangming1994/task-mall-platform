<template>
  <view class="page">
    <view v-if="loading" class="state">加载中…</view>
    <view v-else-if="error" class="state" :class="{ warn: isAgentForbidden }">
      {{ error }}
    </view>

    <template v-else>
      <view class="header">
        <text class="title">校区代理交流板</text>
        <button size="mini" @tap="goCompose">发帖</button>
      </view>

      <view v-if="!items.length" class="state">还没有代理动态，发第一条试试</view>

      <view v-for="item in items" :key="item.public_id" class="card" @tap="goDetail(item.public_id)">
        <view class="row">
          <text class="cat">{{ item.category_label }}</text>
          <text class="campus">{{ item.campus_name || '校区代理' }}</text>
        </view>
        <view class="title">{{ item.title }}</view>
        <view class="meta">
          {{ item.author_name }} · {{ formatDate(item.published_at) }}
        </view>
      </view>
    </template>
  </view>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { onShow } from '@dcloudio/uni-app'
import { listCampusAgentPosts, type CampusAgentPostItem } from '@/api/campusAgent'
import { ApiError } from '@/api/http'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const items = ref<CampusAgentPostItem[]>([])
const loading = ref(false)
const error = ref('')
const isAgentForbidden = ref(false)

async function load() {
  if (!auth.accessToken) {
    isAgentForbidden.value = true
    error.value = '请先登录'
    return
  }
  loading.value = true
  error.value = ''
  isAgentForbidden.value = false
  try {
    const res = await listCampusAgentPosts(auth.accessToken, { page: 1, page_size: 30 })
    items.value = res.items || []
  } catch (e) {
    if (e instanceof ApiError && e.code === 'NOT_CAMPUS_AGENT') {
      isAgentForbidden.value = true
      error.value = '仅校区代理可访问'
    } else {
      error.value = e instanceof Error ? e.message : '加载失败'
    }
  } finally {
    loading.value = false
  }
}

function formatDate(iso: string | null) {
  if (!iso) return ''
  const d = new Date(iso)
  return `${d.getMonth() + 1}/${d.getDate()} ${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`
}

function goCompose() {
  uni.navigateTo({ url: '/pages/campus-agent/compose' })
}
function goDetail(pid: string) {
  uni.navigateTo({ url: `/pages/campus-agent/detail?id=${pid}` })
}

onShow(load)
</script>

<style scoped>
.page { padding: 24rpx; background: #f7f8fb; min-height: 100vh; }
.state { text-align: center; color: #8a92a6; padding: 80rpx 0; font-size: 28rpx; }
.state.warn { color: #c0392b; }
.header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 20rpx; }
.title { font-size: 34rpx; font-weight: 600; color: #1a1e28; }
.card { background: #fff; border-radius: 16rpx; padding: 24rpx; margin-bottom: 16rpx; box-shadow: 0 2rpx 8rpx rgba(0,0,0,.04); }
.row { display: flex; align-items: center; gap: 12rpx; margin-bottom: 8rpx; }
.cat { font-size: 22rpx; color: #19b683; background: rgba(25,182,131,.1); padding: 2rpx 12rpx; border-radius: 6rpx; }
.campus { font-size: 22rpx; color: #8a92a6; }
.title { font-size: 30rpx; color: #1a1e28; font-weight: 500; margin-bottom: 8rpx; }
.meta { font-size: 22rpx; color: #8a92a6; }
</style>
