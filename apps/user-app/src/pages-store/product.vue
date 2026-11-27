<template>
  <view class="page">
    <view v-if="loading" class="empty">加载中…</view>
    <view v-else-if="!product" class="empty">商品已下架或门店暂停营业。</view>
    <template v-else>
      <swiper v-if="images.length > 0" class="gallery" indicator-dots circular>
        <swiper-item v-for="(image, index) in images" :key="image + index">
          <image :src="image" class="gallery-image" mode="aspectFill" />
        </swiper-item>
      </swiper>
      <view v-else class="gallery placeholder">暂无商品图片</view>

      <view class="info">
        <view class="price-row">
          <text class="price">{{ money(selectedSku?.cash_amount ?? minPrice) }}</text>
          <text class="stock">库存 {{ selectedSku?.available_quantity ?? 0 }}</text>
        </view>
        <text class="name">{{ product.name }}</text>
        <text v-if="product.subtitle" class="subtitle">{{ product.subtitle }}</text>
      </view>

      <view v-if="product.store" class="store" @tap="openStore">
        <image v-if="product.store.logo_url" class="store-logo" :src="product.store.logo_url" mode="aspectFill" />
        <view v-else class="store-logo placeholder">{{ product.store.name.slice(0, 1) }}</view>
        <view class="store-main">
          <text class="store-name">{{ product.store.name }}</text>
          <text class="store-meta">{{ product.store.business_hours || '营业时间未设置' }}<text v-if="product.store.address"> · {{ product.store.address }}</text></text>
        </view>
        <text class="arrow">›</text>
      </view>

      <view class="section">
        <text class="section-title">选择规格</text>
        <view class="skus">
          <view v-for="sku in product.skus.filter((item) => item.status === 'active')" :key="sku.id" class="sku" :class="{ active: selectedSkuId === sku.id, disabled: sku.available_quantity <= 0 }" @tap="selectSku(sku)">
            <text class="sku-name">{{ sku.name }}</text>
            <text class="sku-spec">{{ specText(sku) }}</text>
            <text class="sku-price">{{ money(sku.cash_amount) }}</text>
          </view>
        </view>
        <view class="quantity">
          <text class="quantity-label">购买数量</text>
          <view class="stepper">
            <text class="step" @tap="changeQuantity(-1)">−</text>
            <text class="step-value">{{ quantity }}</text>
            <text class="step" @tap="changeQuantity(1)">＋</text>
          </view>
        </view>
      </view>

      <view v-if="product.description" class="section">
        <text class="section-title">商品详情</text>
        <text class="description">{{ product.description }}</text>
      </view>

      <view class="bar">
        <button class="bar-btn" :disabled="!canBuy" @tap="addToCart(false)">加入购物车</button>
        <button class="bar-btn primary" :disabled="!canBuy" @tap="addToCart(true)">立即购买</button>
      </view>
    </template>
  </view>
</template>

<script setup lang="ts">
import { onLoad } from '@dcloudio/uni-app'
import { computed, ref } from 'vue'
import { addCartItem } from '@/api/cart'
import { getStoreProduct, type StoreProductDetail, type StoreSku } from '@/api/stores'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const product = ref<StoreProductDetail | null>(null)
const loading = ref(true)
const selectedSkuId = ref('')
const quantity = ref(1)
const toast = (title: string) => uni.showToast({ title, icon: 'none' })

const images = computed(() => {
  if (!product.value) return []
  return [product.value.cover_url, ...product.value.gallery_images].filter((url, index, list) => Boolean(url) && list.indexOf(url) === index) as string[]
})
const activeSkus = computed(() => product.value?.skus.filter((sku) => sku.status === 'active') ?? [])
const selectedSku = computed<StoreSku | undefined>(() => activeSkus.value.find((sku) => sku.id === selectedSkuId.value))
const minPrice = computed(() => {
  const prices = activeSkus.value.map((sku) => sku.cash_amount)
  return prices.length ? Math.min(...prices) : 0
})
const canBuy = computed(() => Boolean(selectedSku.value && selectedSku.value.available_quantity >= quantity.value))
const money = (cents: number) => `¥${(cents / 100).toFixed(2)}`
const specText = (sku: StoreSku) => Object.entries(sku.specs || {}).map(([key, value]) => `${key}:${value}`).join(' · ')

const selectSku = (sku: StoreSku) => {
  if (sku.available_quantity <= 0) return toast('该规格暂时缺货')
  selectedSkuId.value = sku.id
  quantity.value = 1
}
const changeQuantity = (delta: number) => {
  const max = selectedSku.value?.available_quantity ?? 1
  const next = quantity.value + delta
  quantity.value = Math.min(Math.max(1, next), Math.max(1, max))
}

const addToCart = async (buyNow: boolean) => {
  if (!auth.accessToken) {
    uni.navigateTo({ url: '/pages/login/index' })
    return
  }
  if (!selectedSku.value) return toast('请选择规格')
  if (!canBuy.value) return toast('库存不足')
  try {
    await addCartItem(auth.accessToken, selectedSku.value.id, 'cash', quantity.value)
    if (buyNow) {
      uni.navigateTo({ url: '/pages/cart/index' })
    } else {
      toast('已加入购物车')
    }
  } catch (error) {
    toast(error instanceof Error ? error.message : '加入购物车失败')
  }
}

const openStore = () => {
  if (!product.value?.store) return
  uni.navigateTo({ url: `/pages-store/store-home?id=${product.value.store.id}` })
}

onLoad(async (query) => {
  try {
    product.value = await getStoreProduct(String(query?.id || ''))
    const first = product.value.skus.find((sku) => sku.status === 'active' && sku.available_quantity > 0)
    if (first) selectedSkuId.value = first.id
  } catch (error) {
    product.value = null
  } finally {
    loading.value = false
  }
})
</script>

<style scoped>
.page { min-height: 100vh; background: #f5f6f8; padding-bottom: 160rpx; }
.empty { padding: 200rpx 0; text-align: center; color: #9ca3af; font-size: 26rpx; }
.gallery { width: 100%; height: 640rpx; background: #eef2ff; }
.gallery-image { width: 100%; height: 640rpx; }
.gallery.placeholder { display: flex; align-items: center; justify-content: center; color: #9ca3af; font-size: 26rpx; }
.info, .store, .section { background: #fff; margin-top: 16rpx; padding: 24rpx; }
.info { margin-top: 0; }
.price-row { display: flex; align-items: baseline; justify-content: space-between; }
.price { font-size: 44rpx; font-weight: 700; color: #ef4444; }
.stock { font-size: 22rpx; color: #9ca3af; }
.name { display: block; margin-top: 8rpx; font-size: 32rpx; font-weight: 700; color: #111827; }
.subtitle { display: block; margin-top: 8rpx; font-size: 24rpx; color: #6b7280; }
.store { display: flex; align-items: center; gap: 16rpx; }
.store-logo { width: 88rpx; height: 88rpx; border-radius: 16rpx; background: #eef2ff; flex-shrink: 0; }
.store-logo.placeholder { display: flex; align-items: center; justify-content: center; color: #2f6bff; font-weight: 700; font-size: 32rpx; }
.store-main { flex: 1; min-width: 0; }
.store-name { display: block; font-size: 27rpx; font-weight: 600; color: #111827; }
.store-meta { display: block; margin-top: 4rpx; font-size: 20rpx; color: #9ca3af; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.arrow { color: #d1d5db; font-size: 36rpx; }
.section-title { display: block; font-size: 27rpx; font-weight: 700; color: #111827; margin-bottom: 18rpx; }
.skus { display: flex; flex-wrap: wrap; gap: 16rpx; }
.sku { border: 2rpx solid #e5e7eb; border-radius: 16rpx; padding: 16rpx 20rpx; min-width: 200rpx; }
.sku.active { border-color: #2f6bff; background: #eef2ff; }
.sku.disabled { opacity: 0.45; }
.sku-name { display: block; font-size: 25rpx; color: #111827; font-weight: 600; }
.sku-spec { display: block; margin-top: 4rpx; font-size: 19rpx; color: #9ca3af; }
.sku-price { display: block; margin-top: 6rpx; font-size: 23rpx; color: #ef4444; }
.quantity { display: flex; align-items: center; justify-content: space-between; margin-top: 24rpx; }
.quantity-label { font-size: 25rpx; color: #374151; }
.stepper { display: flex; align-items: center; gap: 24rpx; }
.step { width: 56rpx; height: 56rpx; line-height: 56rpx; text-align: center; border-radius: 12rpx; background: #f3f4f6; color: #374151; font-size: 28rpx; }
.step-value { min-width: 48rpx; text-align: center; font-size: 27rpx; }
.description { font-size: 25rpx; color: #4b5563; line-height: 1.8; white-space: pre-wrap; }
.bar { position: fixed; left: 0; right: 0; bottom: 0; display: flex; gap: 16rpx; padding: 20rpx 24rpx 40rpx; background: #fff; box-shadow: 0 -4rpx 20rpx rgba(17, 24, 39, 0.06); }
.bar-btn { flex: 1; margin: 0; border-radius: 999rpx; font-size: 28rpx; background: #eef2ff; color: #2f6bff; }
.bar-btn.primary { background: #2f6bff; color: #fff; }
.bar-btn[disabled] { opacity: 0.5; }
</style>
