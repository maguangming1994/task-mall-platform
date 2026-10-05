<template>
  <view class="page">
    <view class="head">
      <text class="title">闲置市场</text>
    </view>

    <view class="search-box" @click.stop>
      <text class="search-ic">⌕</text>
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

    <view class="tip-card">
      <view class="tip-ic"><text>✓</text></view>
      <text class="tip-text">买卖沟通请使用平台私信，面交请选择校内人多场所</text>
    </view>

    <view v-if="loading" class="list-card">
      <view v-for="i in 4" :key="i" class="sh-item">
        <view class="sh-cover tm-skeleton" />
        <view class="sh-main"><view class="skel-line tm-skeleton" /><view class="skel-line short tm-skeleton" /></view>
      </view>
    </view>
    <view v-else-if="items.length" class="list-card">
      <view v-for="item in items" :key="item.id" class="sh-item" @click="openDetail(item.id)">
        <image v-if="item.cover" class="sh-cover" :src="resolveCover(item.cover)" mode="aspectFill" />
        <view v-else class="sh-cover placeholder"><text>{{ item.title.slice(0, 1) }}</text></view>
        <view class="sh-main">
          <text class="sh-title">{{ item.title }}</text>
          <view class="sh-tags">
            <text class="tag tag-green">{{ item.condition_label }}</text>
            <text v-if="item.category" class="tag">{{ item.category.name }}</text>
          </view>
          <text class="sh-meta">{{ item.seller?.nickname || '同学' }} · {{ item.location || '校内' }} · 浏览 {{ item.view_count }}</text>
          <view class="sh-prow">
            <text class="sh-price">¥{{ item.price }}</text>
            <text class="sh-fav">♡ {{ item.favorite_count || 0 }}</text>
          </view>
        </view>
      </view>
    </view>
    <view v-else class="empty"><text>暂无闲置物品</text></view>

    <view class="fab-publish" @click="goPublish"><text class="fab-plus">＋</text><text>发布</text></view>
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
.page { min-height: 100vh; background: #F4F6F4; padding-bottom: 160rpx; }

/* 头部（自定义导航：避开状态栏与右上角胶囊区） */
.head { display: flex; justify-content: space-between; align-items: center; padding: calc(var(--status-bar-height) + 16rpx) 24rpx 16rpx; }
.title { font-size: 40rpx; font-weight: 800; color: #191B1F; }

/* 发布悬浮按钮（右下角，避开小程序胶囊） */
.fab-publish { position: fixed; right: 32rpx; bottom: calc(48rpx + env(safe-area-inset-bottom)); z-index: 20; display: flex; align-items: center; gap: 6rpx; height: 84rpx; padding: 0 34rpx; border-radius: 42rpx; background: linear-gradient(135deg, #12D978, #08A152); color: #fff; font-size: 28rpx; font-weight: 700; box-shadow: 0 12rpx 28rpx rgba(11, 191, 99, .35); }
.fab-plus { font-size: 34rpx; line-height: 1; }

/* 搜索 */
.search-box { margin: 0 24rpx 16rpx; background: #F2F4F3; border-radius: 34rpx; padding: 0 24rpx; height: 68rpx; display: flex; align-items: center; gap: 10rpx; }
.search-ic { font-size: 30rpx; color: #AEB3BB; }
.search-box input { flex: 1; font-size: 26rpx; color: #191B1F; }

/* 分类 chips */
.cat-scroll { white-space: nowrap; }
.cats { display: inline-flex; gap: 16rpx; padding: 4rpx 24rpx 12rpx; }
.cat { flex: none; height: 62rpx; line-height: 62rpx; padding: 0 28rpx; border-radius: 31rpx; background: #fff; border: 1rpx solid #EDEFEF; font-size: 24rpx; color: #767C85; }
.cat.active { background: linear-gradient(135deg, #12D978, #08A152); color: #fff; font-weight: 700; border-color: transparent; box-shadow: 0 8rpx 20rpx rgba(11, 191, 99, .25); }

/* 排序 */
.sort-bar { display: flex; gap: 32rpx; padding: 4rpx 24rpx 12rpx; }
.sort { font-size: 24rpx; color: #AEB3BB; }
.sort.active { color: #08A152; font-weight: 700; }

/* 安全提示 */
.tip-card { margin: 0 24rpx 20rpx; background: #E7F9EF; border-radius: 32rpx; padding: 20rpx 24rpx; display: flex; align-items: center; gap: 16rpx; }
.tip-ic { width: 56rpx; height: 56rpx; border-radius: 20rpx; background: #0BBF63; color: #fff; display: flex; align-items: center; justify-content: center; flex: none; font-size: 26rpx; font-weight: 700; }
.tip-text { font-size: 22rpx; color: #08A152; line-height: 1.5; }

/* 列表 */
.list-card { background: #fff; border-radius: 32rpx; margin: 0 24rpx; overflow: hidden; }
.sh-item { display: flex; gap: 20rpx; padding: 24rpx 26rpx; }
.sh-item + .sh-item { border-top: 1rpx solid #F5F6F6; }
.sh-cover { width: 184rpx; height: 184rpx; border-radius: 20rpx; background: #EDEFEF; flex: none; }
.sh-cover.placeholder { display: flex; align-items: center; justify-content: center; color: #AEB3BB; font-size: 56rpx; }
.sh-main { flex: 1; min-width: 0; }
.sh-title { font-size: 27rpx; font-weight: 600; color: #191B1F; line-height: 1.4; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
.sh-tags { margin-top: 10rpx; display: flex; gap: 8rpx; }
.tag { display: inline-flex; align-items: center; height: 36rpx; padding: 0 12rpx; border-radius: 10rpx; font-size: 20rpx; font-weight: 500; background: #F2F4F3; color: #767C85; }
.tag-green { background: #E7F9EF; color: #08A152; }
.sh-meta { display: block; font-size: 22rpx; color: #AEB3BB; margin-top: 10rpx; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.sh-prow { display: flex; align-items: center; margin-top: 12rpx; }
.sh-price { font-size: 32rpx; font-weight: 800; color: #FF2442; }
.sh-fav { margin-left: auto; font-size: 22rpx; color: #AEB3BB; }

/* 骨架 & 空态 */
.skel-line { height: 32rpx; border-radius: 8rpx; margin-top: 20rpx; }
.skel-line.short { width: 60%; }
.empty { text-align: center; padding: 140rpx 0; color: #AEB3BB; font-size: 26rpx; }
</style>
