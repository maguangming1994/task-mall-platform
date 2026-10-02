<template>
  <view class="page">
    <view class="head">
      <text class="title">校园闲置</text>
      <view class="actions">
        <view class="publish-btn" @click="goPublish">发布闲置</view>
      </view>
    </view>

    <view class="search-box">
      <input v-model="keyword" confirm-type="search" placeholder="搜索闲置物品" @confirm="load" />
    </view>

    <scroll-view class="cat-scroll" scroll-x :show-scrollbar="false">
      <view class="cats">
        <view :class="['cat', { active: !categoryId }]" @click="selectCategory('')">全部</view>
        <view v-for="c in categories" :key="c.id" :class="['cat', { active: categoryId === c.id }]" @click="selectCategory(c.id)">{{ c.name }}</view>
      </view>
    </scroll-view>

    <view class="sort-bar">
      <view :class="['sort', { active: sort === 'latest' }]" @click="setSort('latest')">最新</view>
      <view :class="['sort', { active: sort === 'price_asc' }]" @click="setSort('price_asc')">价格↑</view>
      <view :class="['sort', { active: sort === 'price_desc' }]" @click="setSort('price_desc')">价格↓</view>
    </view>

    <view v-if="loading" class="grid">
      <view v-for="i in 4" :key="i" class="card"><view class="cover tm-skeleton" /><view class="skel tm-skeleton" /></view>
    </view>
    <view v-else-if="items.length" class="grid">
      <view v-for="item in items" :key="item.id" class="card" @click="openDetail(item.id)">
        <image v-if="item.cover" class="cover" :src="resolveCover(item.cover)" mode="aspectFill" />
        <view v-else class="cover placeholder"><text>{{ item.title.slice(0, 1) }}</text></view>
        <view class="body">
          <text class="name">{{ item.title }}</text>
          <text class="cond">{{ item.condition_label }}</text>
          <view class="foot">
            <text class="price">¥{{ item.price }}</text>
            <text class="loc">{{ item.location || '校内' }}</text>
          </view>
        </view>
      </view>
    </view>
    <view v-else class="empty"><text>暂无闲置物品</text></view>
  </view>
</template>

<script setup lang="ts">
import { onShow } from '@dcloudio/uni-app'
import { ref } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { listSecondHandCategories, listSecondHandItems, type SecondHandCategory, type SecondHandItem } from '@/api/second-hand'
import { resolveApiAssetUrl } from '@/api/http'

const auth = useAuthStore()
const items = ref<SecondHandItem[]>([])
const categories = ref<SecondHandCategory[]>([])
const loading = ref(false)
const keyword = ref('')
const categoryId = ref('')
const sort = ref<'latest' | 'price_asc' | 'price_desc'>('latest')

function resolveCover(id: string) {
  return resolveApiAssetUrl(id)
}

async function load() {
  if (!auth.accessToken) return
  loading.value = true
  try {
    const res = await listSecondHandItems(auth.accessToken, { keyword: keyword.value, category_id: categoryId.value || undefined, sort: sort.value })
    items.value = res.items
  } catch (e) {
    uni.showToast({ title: '加载失败', icon: 'none' })
  } finally {
    loading.value = false
  }
}

async function loadCategories() {
  if (!auth.accessToken) return
  try {
    const res = await listSecondHandCategories(auth.accessToken)
    categories.value = res.items
  } catch { /* ignore */ }
}

function selectCategory(id: string) {
  categoryId.value = id
  load()
}

function setSort(s: 'latest' | 'price_asc' | 'price_desc') {
  sort.value = s
  load()
}

function openDetail(id: string) {
  uni.navigateTo({ url: `/pages/second-hand-detail/index?id=${id}` })
}

function goPublish() {
  if (!auth.isAuthenticated) {
    uni.navigateTo({ url: '/pages/login/index' })
    return
  }
  uni.navigateTo({ url: '/pages/second-hand-publish/index' })
}

onShow(() => {
  loadCategories()
  load()
})
</script>

<style lang="scss" scoped>
.page { min-height: 100vh; background: #f6f6f8; padding-bottom: 40rpx; }
.head { display: flex; justify-content: space-between; align-items: center; padding: 24rpx 28rpx 16rpx; }
.title { font-size: 40rpx; font-weight: 700; color: #1a1a1b; }
.publish-btn { background: #07c160; color: #fff; padding: 14rpx 28rpx; border-radius: 32rpx; font-size: 26rpx; }
.search-box { margin: 0 28rpx 20rpx; background: #fff; border-radius: 32rpx; padding: 0 28rpx; height: 72rpx; display: flex; align-items: center; }
.search-box input { flex: 1; font-size: 28rpx; }
.cat-scroll { white-space: nowrap; padding: 0 20rpx 16rpx; }
.cats { display: inline-flex; gap: 16rpx; }
.cat { display: inline-block; padding: 12rpx 28rpx; background: #fff; border-radius: 28rpx; font-size: 26rpx; color: #6e6e73; }
.cat.active { background: #07c160; color: #fff; }
.sort-bar { display: flex; gap: 32rpx; padding: 0 28rpx 16rpx; }
.sort { font-size: 26rpx; color: #6e6e73; }
.sort.active { color: #07c160; font-weight: 600; }
.grid { display: flex; flex-wrap: wrap; padding: 0 20rpx; gap: 20rpx; }
.card { width: calc(50% - 10rpx); background: #fff; border-radius: 20rpx; overflow: hidden; }
.cover { width: 100%; height: 320rpx; background: #eee; }
.cover.placeholder { display: flex; align-items: center; justify-content: center; color: #ccc; font-size: 60rpx; }
.body { padding: 16rpx 20rpx 20rpx; }
.name { font-size: 28rpx; color: #1a1a1b; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
.cond { font-size: 22rpx; color: #a0a0a5; margin-top: 8rpx; display: block; }
.foot { display: flex; justify-content: space-between; align-items: center; margin-top: 12rpx; }
.price { font-size: 32rpx; color: #ff2442; font-weight: 700; }
.loc { font-size: 22rpx; color: #a0a0a5; }
.empty { text-align: center; padding: 120rpx 0; color: #a0a0a5; font-size: 28rpx; }
.skel { height: 40rpx; margin: 16rpx; border-radius: 8rpx; }
</style>
