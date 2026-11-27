<template>
  <view class="page">
    <view v-if="campus" class="campus-bar"><text>当前校区</text><text class="campus-name">{{ campus.campus_name }}</text></view>
    <view class="toolbar">
      <input v-model="keyword" class="search" placeholder="搜索商品名称" confirm-type="search" @confirm="reload" />
      <text class="search-btn" @tap="reload">搜索</text>
    </view>
    <scroll-view scroll-x class="chips">
      <view v-for="item in filters" :key="item.value" class="chip" :class="{ active: status === item.value }" @tap="selectStatus(item.value)">{{ item.label }}</view>
    </scroll-view>

    <view v-if="loading" class="empty">加载中…</view>
    <view v-else-if="products.length === 0" class="empty">还没有商品，点击右下角新增。</view>
    <view v-else class="list">
      <view v-for="product in products" :key="product.id" class="card">
        <image v-if="product.cover_url" class="cover" :src="product.cover_url" mode="aspectFill" />
        <view v-else class="cover placeholder">品</view>
        <view class="card-main">
          <view class="card-title-row">
            <text class="name">{{ product.name }}</text>
            <text class="status" :class="product.status">{{ storeProductStatusLabel(product.status) }}</text>
          </view>
          <text class="meta">{{ priceRange(product) }} · {{ product.skus.length }} 个规格 · 库存 {{ stockOf(product) }}</text>
          <text v-if="product.admin_off_shelf_reason" class="reject">平台下架：{{ product.admin_off_shelf_reason }}</text>
          <view class="ops">
            <button class="mini" @tap="edit(product)">编辑</button>
            <button v-if="product.status !== 'on_sale'" class="mini primary" @tap="changeStatus(product, 'publish')">上架</button>
            <button v-else class="mini" @tap="changeStatus(product, 'off_shelf')">下架</button>
          </view>
        </view>
      </view>
    </view>

    <view v-if="hasMore" class="more" @tap="loadMore">加载更多</view>
    <button class="fab" @tap="create">＋</button>
  </view>
</template>

<script setup lang="ts">
import { onShow } from '@dcloudio/uni-app'
import { ref } from 'vue'
import {
  listManagerProducts,
  storeProductStatusLabel,
  updateManagerProductStatus,
  type StoreCampus,
  type StoreProductDetail,
} from '@/api/stores'
import { getSelectedStoreCampus, requireStoreSession } from '@/utils/storeSession'

const session = requireStoreSession()
const campus = ref<StoreCampus | null>(null)
const products = ref<StoreProductDetail[]>([])
const loading = ref(false)
const keyword = ref('')
const status = ref('')
const page = ref(1)
const hasMore = ref(false)
const filters = [
  { value: '', label: '全部' },
  { value: 'on_sale', label: '在售' },
  { value: 'off_shelf', label: '已下架' },
  { value: 'draft', label: '草稿' },
]

const toast = (title: string) => uni.showToast({ title, icon: 'none' })
const money = (cents: number) => `¥${(cents / 100).toFixed(2)}`

const priceRange = (product: StoreProductDetail) => {
  const prices = product.skus.map((sku) => sku.cash_amount).filter((value) => value > 0)
  if (prices.length === 0) return '未设置价格'
  const min = Math.min(...prices)
  const max = Math.max(...prices)
  return min === max ? money(min) : `${money(min)}~${money(max)}`
}
const stockOf = (product: StoreProductDetail) => product.skus.reduce((sum, sku) => sum + sku.available_quantity, 0)

const load = async (reset = true) => {
  if (!session) return
  if (reset) { page.value = 1; products.value = [] }
  loading.value = true
  try {
    const result = await listManagerProducts(session.token, { status: status.value, keyword: keyword.value.trim(), campus_id: campus.value?.campus_id, page: page.value, page_size: 20 })
    products.value = reset ? result.items : [...products.value, ...result.items]
    hasMore.value = page.value < result.pagination.last_page
  } catch (error) {
    toast(error instanceof Error ? error.message : '加载失败')
  } finally {
    loading.value = false
  }
}
const reload = () => { void load(true) }
const loadMore = () => { page.value += 1; void load(false) }
const selectStatus = (value: string) => { status.value = value; void load(true) }
const edit = (product: StoreProductDetail) => uni.navigateTo({ url: `/pages-store/product-edit?id=${product.id}` })
const create = () => uni.navigateTo({ url: '/pages-store/product-edit' })

const changeStatus = async (product: StoreProductDetail, action: 'publish' | 'off_shelf') => {
  if (!session) return
  try {
    await updateManagerProductStatus(session.token, product.id, action, campus.value?.campus_id)
    toast(action === 'publish' ? '已上架' : '已下架')
    await load(true)
  } catch (error) {
    toast(error instanceof Error ? error.message : '操作失败')
  }
}

onShow(() => {
  if (session) campus.value = getSelectedStoreCampus(session.storeId)
  void load(true)
})
</script>

<style scoped>
.page { min-height: 100vh; background: #f5f6f8; padding: 24rpx 24rpx 140rpx; box-sizing: border-box; }
.campus-bar { display: flex; gap: 12rpx; margin-bottom: 16rpx; padding: 0 4rpx; font-size: 22rpx; color: #6b7280; }
.campus-name { color: #2f6bff; font-weight: 600; }
.toolbar { display: flex; align-items: center; gap: 16rpx; }
.search { flex: 1; background: #fff; border-radius: 999rpx; padding: 18rpx 28rpx; font-size: 26rpx; }
.search-btn { font-size: 26rpx; color: #2f6bff; }
.chips { white-space: nowrap; margin-top: 16rpx; }
.chip { display: inline-block; padding: 10rpx 26rpx; border-radius: 999rpx; background: #fff; color: #6b7280; font-size: 24rpx; margin-right: 12rpx; }
.chip.active { background: #2f6bff; color: #fff; }
.empty { padding: 140rpx 0; text-align: center; color: #9ca3af; font-size: 26rpx; }
.list { margin-top: 16rpx; }
.card { display: flex; gap: 20rpx; background: #fff; border-radius: 20rpx; padding: 20rpx; margin-bottom: 16rpx; }
.cover { width: 150rpx; height: 150rpx; border-radius: 16rpx; background: #eef2ff; flex-shrink: 0; }
.cover.placeholder { display: flex; align-items: center; justify-content: center; color: #2f6bff; font-size: 40rpx; font-weight: 700; }
.card-main { flex: 1; min-width: 0; }
.card-title-row { display: flex; align-items: center; justify-content: space-between; gap: 12rpx; }
.name { font-size: 28rpx; font-weight: 600; color: #111827; }
.status { font-size: 20rpx; padding: 4rpx 12rpx; border-radius: 999rpx; background: #f3f4f6; color: #6b7280; flex-shrink: 0; }
.status.on_sale { background: #ecfdf5; color: #059669; }
.status.draft { background: #fff7ed; color: #d97706; }
.meta { display: block; margin-top: 10rpx; font-size: 22rpx; color: #9ca3af; }
.reject { display: block; margin-top: 8rpx; font-size: 20rpx; color: #dc2626; }
.ops { display: flex; gap: 12rpx; margin-top: 16rpx; }
.mini { margin: 0; font-size: 22rpx; height: 56rpx; line-height: 56rpx; padding: 0 24rpx; border-radius: 999rpx; background: #f3f4f6; color: #374151; }
.mini.primary { background: #2f6bff; color: #fff; }
.more { text-align: center; color: #2f6bff; font-size: 24rpx; padding: 20rpx; }
.fab { position: fixed; right: 32rpx; bottom: 60rpx; width: 96rpx; height: 96rpx; border-radius: 50%; background: #2f6bff; color: #fff; font-size: 48rpx; line-height: 96rpx; padding: 0; box-shadow: 0 8rpx 24rpx rgba(47, 107, 255, 0.35); }
</style>
