<template>
  <view class="page">
    <view v-if="loading" class="empty">加载中…</view>
    <template v-else-if="store">
      <view class="hero">
        <image v-if="store.logo_url" class="logo" :src="store.logo_url" mode="aspectFill" />
        <view v-else class="logo placeholder">{{ store.name.slice(0, 1) }}</view>
        <view class="hero-main">
          <text class="name">{{ store.name }}</text>
          <text class="desc">{{ store.description || '欢迎进店选购' }}</text>
          <text class="meta">{{ store.business_hours || '营业时间未设置' }}</text>
          <text v-if="store.address" class="meta">{{ store.address }}</text>
        </view>
      </view>

      <scroll-view v-if="store.categories.length > 0" scroll-x class="chips">
        <view class="chip" :class="{ active: categoryId === '' }" @tap="selectCategory('')">全部</view>
        <view v-for="category in store.categories" :key="category.id" class="chip" :class="{ active: categoryId === category.id }" @tap="selectCategory(category.id)">{{ category.name }}</view>
      </scroll-view>

      <view v-if="products.length === 0" class="empty small">该分类下暂无商品。</view>
      <view v-else class="grid">
        <view v-for="product in products" :key="product.id" class="product" @tap="openProduct(product)">
          <image v-if="product.cover_url" class="cover" :src="product.cover_url" mode="aspectFill" />
          <view v-else class="cover placeholder">品</view>
          <text class="product-name">{{ product.name }}</text>
          <view class="price-row">
            <text class="price">{{ priceText(product) }}</text>
            <text v-if="product.available_quantity <= 0" class="soldout">缺货</text>
          </view>
        </view>
      </view>
      <view v-if="hasMore" class="more" @tap="loadMore">加载更多</view>
    </template>
    <view v-else class="empty">门店不存在或已暂停营业。</view>
  </view>
</template>

<script setup lang="ts">
import { onLoad } from '@dcloudio/uni-app'
import { ref } from 'vue'
import { getStore, listStoreProducts, type StoreProductCard, type StoreSummary } from '@/api/stores'

const storeId = ref('')
const store = ref<(StoreSummary & { categories: Array<{ id: string; name: string }> }) | null>(null)
const products = ref<StoreProductCard[]>([])
const categoryId = ref('')
const loading = ref(true)
const page = ref(1)
const hasMore = ref(false)
const toast = (title: string) => uni.showToast({ title, icon: 'none' })

const priceText = (product: StoreProductCard) => {
  if (!product.price_from) return '价格待定'
  const from = `¥${(product.price_from / 100).toFixed(2)}`
  return product.price_to && product.price_to > product.price_from ? `${from} 起` : from
}

const loadStore = async () => {
  loading.value = true
  try {
    store.value = await getStore(storeId.value)
  } catch (error) {
    store.value = null
    toast(error instanceof Error ? error.message : '门店加载失败')
  } finally {
    loading.value = false
  }
}

const loadProducts = async (reset = true) => {
  if (!storeId.value) return
  if (reset) { page.value = 1; products.value = [] }
  try {
    const result = await listStoreProducts(storeId.value, { category_id: categoryId.value || undefined, page: page.value, page_size: 20 })
    products.value = reset ? result.items : [...products.value, ...result.items]
    hasMore.value = page.value < result.pagination.last_page
  } catch (error) {
    toast(error instanceof Error ? error.message : '商品加载失败')
  }
}

const selectCategory = (id: string) => { categoryId.value = id; void loadProducts(true) }
const loadMore = () => { page.value += 1; void loadProducts(false) }
const openProduct = (product: StoreProductCard) => uni.navigateTo({ url: `/pages-store/product?id=${product.id}` })

onLoad((query) => {
  storeId.value = String(query?.id || '')
  if (!storeId.value) { loading.value = false; return }
  void loadStore()
  void loadProducts(true)
})
</script>

<style scoped>
.page { min-height: 100vh; background: #f5f6f8; padding: 24rpx 24rpx 80rpx; box-sizing: border-box; }
.empty { padding: 160rpx 0; text-align: center; color: #9ca3af; font-size: 26rpx; }
.empty.small { padding: 80rpx 0; }
.hero { display: flex; gap: 20rpx; background: #fff; border-radius: 20rpx; padding: 24rpx; }
.logo { width: 130rpx; height: 130rpx; border-radius: 20rpx; background: #eef2ff; flex-shrink: 0; }
.logo.placeholder { display: flex; align-items: center; justify-content: center; color: #2f6bff; font-size: 44rpx; font-weight: 700; }
.hero-main { flex: 1; min-width: 0; }
.name { display: block; font-size: 32rpx; font-weight: 700; color: #111827; }
.desc { display: block; margin-top: 8rpx; font-size: 23rpx; color: #6b7280; }
.meta { display: block; margin-top: 4rpx; font-size: 20rpx; color: #9ca3af; }
.chips { white-space: nowrap; margin-top: 20rpx; }
.chip { display: inline-block; padding: 10rpx 26rpx; border-radius: 999rpx; background: #fff; color: #6b7280; font-size: 24rpx; margin-right: 12rpx; }
.chip.active { background: #2f6bff; color: #fff; }
.grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 16rpx; margin-top: 16rpx; }
.product { background: #fff; border-radius: 20rpx; overflow: hidden; padding-bottom: 18rpx; }
.cover { width: 100%; height: 320rpx; background: #eef2ff; display: block; }
.cover.placeholder { display: flex; align-items: center; justify-content: center; color: #2f6bff; font-size: 52rpx; font-weight: 700; }
.product-name { margin: 14rpx 18rpx 0; font-size: 25rpx; color: #111827; overflow: hidden; text-overflow: ellipsis; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; }
.price-row { display: flex; align-items: center; justify-content: space-between; margin: 10rpx 18rpx 0; }
.price { font-size: 28rpx; color: #ef4444; font-weight: 700; }
.soldout { font-size: 18rpx; color: #9ca3af; }
.more { text-align: center; color: #2f6bff; font-size: 24rpx; padding: 20rpx; }
</style>
