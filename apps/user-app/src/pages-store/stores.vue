<template>
  <view class="page">
    <view class="toolbar">
      <input v-model="keyword" class="search" placeholder="搜索校园门店" confirm-type="search" @confirm="reload" />
      <text class="search-btn" @tap="reload">搜索</text>
    </view>

    <view v-if="loading" class="empty">加载中…</view>
    <view v-else-if="stores.length === 0" class="empty">暂时还没有营业中的门店。</view>
    <view v-else class="list">
      <view v-for="store in stores" :key="store.id" class="card" @tap="open(store)">
        <image v-if="store.logo_url" class="logo" :src="store.logo_url" mode="aspectFill" />
        <view v-else class="logo placeholder">{{ store.name.slice(0, 1) }}</view>
        <view class="main">
          <view class="title-row"><text class="name">{{ store.name }}</text><text class="badge">{{ store.product_count }} 件商品</text></view>
          <text class="desc">{{ store.description || '欢迎进店选购' }}</text>
          <text class="meta">{{ store.business_hours || '营业时间未设置' }}<text v-if="store.address"> · {{ store.address }}</text></text>
        </view>
        <text class="arrow">›</text>
      </view>
    </view>
    <view v-if="hasMore" class="more" @tap="loadMore">加载更多</view>
  </view>
</template>

<script setup lang="ts">
import { onShow } from '@dcloudio/uni-app'
import { ref } from 'vue'
import { listStores, type StoreSummary } from '@/api/stores'

const stores = ref<StoreSummary[]>([])
const loading = ref(false)
const keyword = ref('')
const page = ref(1)
const hasMore = ref(false)
const toast = (title: string) => uni.showToast({ title, icon: 'none' })

const load = async (reset = true) => {
  if (reset) { page.value = 1; stores.value = [] }
  loading.value = true
  try {
    const result = await listStores({ keyword: keyword.value.trim(), page: page.value, page_size: 20 })
    stores.value = reset ? result.items : [...stores.value, ...result.items]
    hasMore.value = page.value < result.pagination.last_page
  } catch (error) {
    toast(error instanceof Error ? error.message : '加载失败')
  } finally {
    loading.value = false
  }
}
const reload = () => { void load(true) }
const loadMore = () => { page.value += 1; void load(false) }
const open = (store: StoreSummary) => uni.navigateTo({ url: `/pages-store/store-home?id=${store.id}` })

onShow(() => { void load(true) })
</script>

<style scoped>
.page { min-height: 100vh; background: #f5f6f8; padding: 24rpx 24rpx 80rpx; box-sizing: border-box; }
.toolbar { display: flex; gap: 16rpx; align-items: center; }
.search { flex: 1; background: #fff; border-radius: 999rpx; padding: 18rpx 28rpx; font-size: 26rpx; }
.search-btn { font-size: 26rpx; color: #2f6bff; }
.empty { padding: 140rpx 0; text-align: center; color: #9ca3af; font-size: 26rpx; }
.list { margin-top: 16rpx; }
.card { display: flex; gap: 20rpx; align-items: center; background: #fff; border-radius: 20rpx; padding: 22rpx; margin-bottom: 16rpx; }
.logo { width: 120rpx; height: 120rpx; border-radius: 18rpx; background: #eef2ff; flex-shrink: 0; }
.logo.placeholder { display: flex; align-items: center; justify-content: center; color: #2f6bff; font-size: 40rpx; font-weight: 700; }
.main { flex: 1; min-width: 0; }
.title-row { display: flex; align-items: center; gap: 12rpx; }
.name { font-size: 29rpx; font-weight: 700; color: #111827; }
.badge { font-size: 18rpx; color: #2f6bff; background: #eef2ff; padding: 2rpx 10rpx; border-radius: 999rpx; }
.desc { display: block; margin-top: 8rpx; font-size: 23rpx; color: #6b7280; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.meta { display: block; margin-top: 6rpx; font-size: 20rpx; color: #9ca3af; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.arrow { color: #d1d5db; font-size: 36rpx; }
.more { text-align: center; color: #2f6bff; font-size: 24rpx; padding: 20rpx; }
</style>
