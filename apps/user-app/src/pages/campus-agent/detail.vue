<template>
  <view class="page">
    <view v-if="loading" class="state">加载中…</view>
    <template v-else-if="item">
      <view class="head">
        <text class="cat">{{ item.category_label }}</text>
        <text class="campus">{{ item.campus_name || '校区代理' }}</text>
      </view>
      <view class="title">{{ item.title }}</view>
      <view class="meta">{{ item.author_name }} · {{ formatDate(item.published_at) }} · {{ item.view_count }} 阅读</view>
      <view class="content">{{ item.content }}</view>
    </template>
    <view v-else class="state">加载失败或已删除</view>
  </view>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { onLoad } from '@dcloudio/uni-app'
import { getCampusAgentPost, type CampusAgentPostItem } from '@/api/campusAgent'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const item = ref<CampusAgentPostItem | null>(null)
const loading = ref(true)

async function load(id: string) {
  if (!auth.accessToken) {
    item.value = null
    loading.value = false
    return
  }
  try {
    const res = await getCampusAgentPost(auth.accessToken, id)
    item.value = res.item
  } catch {
    item.value = null
  } finally {
    loading.value = false
  }
}

function formatDate(iso: string | null) {
  if (!iso) return ''
  const d = new Date(iso)
  return `${d.getMonth() + 1}/${d.getDate()} ${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`
}

onLoad((options) => { if (options?.id) load(options.id) })
</script>

<style scoped>
.page { padding: 24rpx; background: #fff; min-height: 100vh; }
.state { text-align: center; color: #8a92a6; padding: 80rpx 0; font-size: 28rpx; }
.head { display: flex; gap: 12rpx; align-items: center; margin-bottom: 16rpx; }
.cat { font-size: 22rpx; color: #19b683; background: rgba(25,182,131,.1); padding: 2rpx 12rpx; border-radius: 6rpx; }
.campus { font-size: 22rpx; color: #8a92a6; }
.title { font-size: 36rpx; font-weight: 600; color: #1a1e28; margin-bottom: 12rpx; }
.meta { font-size: 22rpx; color: #8a92a6; margin-bottom: 24rpx; padding-bottom: 16rpx; border-bottom: 1rpx solid #eef1f6; }
.content { font-size: 30rpx; color: #2a2f3a; line-height: 1.7; white-space: pre-wrap; }
</style>
