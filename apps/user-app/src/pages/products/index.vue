<template>
  <view class="page">
    <view class="hero"><text class="eyebrow">SELECTED MALL</text><text class="title">把每份收获，换成喜欢的生活</text><text class="subtitle">现金、积分、现金＋积分三种方式，同一商品各端价格一致。</text></view>
    <view v-for="product in products" :key="product.id" class="card">
      <swiper v-if="product.gallery_images?.length" class="product-gallery" circular :indicator-dots="product.gallery_images.length > 1" :autoplay="product.gallery_images.length > 1" :interval="4000">
        <swiper-item v-for="image in product.gallery_images" :key="image.url"><image class="gallery-image" :src="image.url" mode="aspectFill" :alt="image.alt || product.name" /></swiper-item>
      </swiper>
      <view class="product-head"><view class="product-mark">{{ product.name.slice(0, 1) }}</view><view><text class="name">{{ product.name }}</text><text class="sub">{{ product.subtitle || product.description_text || plainTextFromRich(product.description) }}</text></view></view>
      <view v-for="sku in product.skus" :key="sku.id" class="sku">
        <view class="sku-head"><text>{{ sku.name }}</text><text class="delivery">{{ deliveryText(sku.fulfillment_type) }}</text></view>
        <view class="specs"><text v-for="(value, key) in sku.specs" :key="key" class="spec">{{ key }}：{{ value }}</text></view>
        <view class="prices">
          <view v-for="option in sku.purchase_options" :key="option.id" class="price-row">
            <view><text>{{ modeText(option.purchase_mode) }}</text><text class="price">{{ priceText(option) }}</text></view><button class="add" :disabled="sku.stock < 1" @click="add(sku.id, option.purchase_mode)">{{ sku.stock > 0 ? '加入购物车' : '暂时缺货' }}</button>
          </view>
        </view>
        <text class="stock">可售库存 {{ sku.stock }}</text>
      </view>
    </view>
    <button v-if="auth.isAuthenticated" class="cart-entry" @click="openCart">查看购物车</button>
    <text v-if="products.length === 0 && !loading" class="empty">暂无在售商品</text>
    <text v-if="loading" class="empty">正在加载商品…</text>
    <AppTabBar current-target="products" />
  </view>
</template>

<script setup lang="ts">
import { onPullDownRefresh, onShow } from '@dcloudio/uni-app'
import { ref } from 'vue'
import AppTabBar from '@/components/AppTabBar.vue'
import { listProducts, type MallProduct, type PurchaseMode, type PurchaseOption } from '@/api/products'
import { addCartItem } from '@/api/cart'
import { useAuthStore } from '@/stores/auth'
import { plainTextFromRich } from '@/utils/richText'

const products = ref<MallProduct[]>([])
const loading = ref(false)
const auth = useAuthStore()
async function load() { loading.value = true; try { products.value = (await listProducts()).items } finally { loading.value = false } }
onShow(load)
onPullDownRefresh(async () => { try { await load() } finally { uni.stopPullDownRefresh() } })
function modeText(mode: PurchaseMode) { return ({ cash: '现金购买', points: '积分兑换', cash_points: '现金 + 积分' } as const)[mode] }
function deliveryText(type: string) { return ({ logistics: '物流配送', no_logistics: '无需物流', virtual_delivery: '虚拟交付' } as Record<string, string>)[type] || type }
function priceText(option: PurchaseOption) {
  if (option.purchase_mode === 'cash') return `¥${(option.cash_amount / 100).toFixed(2)}`
  if (option.purchase_mode === 'points') return `${option.points} 积分`
  return `¥${(option.cash_amount / 100).toFixed(2)} + ${option.points} 积分`
}
async function add(skuId: string, mode: PurchaseMode) { if (!auth.accessToken) return uni.navigateTo({ url: '/pages/login/index' }); try { await addCartItem(auth.accessToken, skuId, mode); uni.showToast({ title: '已加入购物车', icon: 'success' }) } catch (error) { uni.showToast({ title: error instanceof Error ? error.message : '加入失败', icon: 'none' }) } }
function openCart() { uni.navigateTo({ url: '/pages/cart/index' }) }
</script>

<style scoped>
.page { min-height: 100vh; padding: 32rpx; background: #f5f7fb; box-sizing: border-box; }
.hero { padding: 40rpx; color: #fff; border-radius: 28rpx; background: linear-gradient(135deg, #ef6c35, #ff9b4b); }
.eyebrow,.title,.subtitle,.name,.sub,.empty { display: block; }.eyebrow { font-size: 22rpx; opacity: .8; }.title { margin-top: 12rpx; font-size: 44rpx; font-weight: 750; }.subtitle { margin-top: 14rpx; font-size: 26rpx; line-height: 1.6; }
.card { margin-top: 24rpx; padding: 30rpx; background: #fff; border-radius: 24rpx; }.name { font-size: 34rpx; font-weight: 700; }.sub { margin-top: 8rpx; color: #667085; font-size: 25rpx; }.sku { margin-top: 24rpx; padding-top: 24rpx; border-top: 1rpx solid #eee; }.sku-head,.price-row { display: flex; justify-content: space-between; align-items:center }.sku-head { font-weight: 650; }.delivery { color: #ef6c35; font-size: 24rpx; }.specs { margin-top: 12rpx; }.spec { margin-right: 16rpx; color: #667085; font-size: 24rpx; }.prices { margin-top: 18rpx; padding: 16rpx 20rpx; border-radius: 16rpx; background: #fff8f3; }.price-row { padding: 8rpx 0; font-size: 26rpx; }.price { display:block;color: #d94b17; font-weight: 700; }.add{margin:0;padding:0 20rpx;height:56rpx;line-height:56rpx;font-size:23rpx;background:#ef6c35;color:#fff}.add::after{border:0}.stock{display:block;margin-top:12rpx;color:#667085;font-size:23rpx}.cart-entry{position:sticky;bottom:24rpx;margin-top:26rpx;background:#1d2939;color:#fff}.empty { padding: 80rpx 0; text-align: center; color: #98a2b3; }
.page{padding:32rpx 28rpx 90rpx;background:var(--tm-canvas)}.hero{position:relative;overflow:hidden;padding:38rpx 34rpx;border-radius:32rpx;background:linear-gradient(135deg,#172f68,#315fd0 65%,#507fe8);box-shadow:0 20rpx 44rpx rgba(37,83,185,.18)}.hero::after{content:'';position:absolute;right:-75rpx;bottom:-115rpx;width:260rpx;height:260rpx;border:45rpx solid rgba(255,255,255,.08);border-radius:50%}.eyebrow{color:#cbdcff;font-size:19rpx;font-weight:750;letter-spacing:2rpx}.title{position:relative;max-width:560rpx;font-size:39rpx;font-weight:850;line-height:1.35}.subtitle{position:relative;color:rgba(255,255,255,.75);font-size:23rpx}.card{padding:27rpx;border:1rpx solid var(--tm-line);border-radius:28rpx;box-shadow:0 10rpx 26rpx rgba(35,49,80,.04)}.product-head{display:flex;align-items:center;gap:18rpx}.product-head>view:last-child{min-width:0}.product-mark{display:flex;align-items:center;justify-content:center;width:76rpx;height:76rpx;flex:none;border-radius:23rpx;background:var(--tm-reward-100);color:var(--tm-reward-600);font-size:27rpx;font-weight:850}.name{font-size:31rpx;font-weight:800}.sub{overflow:hidden;color:var(--tm-ink-500);font-size:23rpx;text-overflow:ellipsis;white-space:nowrap}.sku{border-color:var(--tm-line)}.delivery{padding:5rpx 10rpx;border-radius:9rpx;background:var(--tm-brand-100);color:var(--tm-brand-700);font-size:20rpx}.prices{background:#fafbfe}.price{color:var(--tm-reward-600);font-weight:800}.add{border-radius:15rpx;background:var(--tm-brand-600)}.cart-entry{height:82rpx;border-radius:22rpx;background:var(--tm-ink-900);font-size:25rpx;font-weight:750;line-height:82rpx;box-shadow:0 14rpx 30rpx rgba(23,32,51,.2)}
.product-gallery{height:360rpx;margin:-27rpx -27rpx 24rpx;overflow:hidden;border-radius:28rpx 28rpx 0 0}.gallery-image{display:block;width:100%;height:360rpx}
@media screen and (min-width:768px){.page{width:750rpx;margin:0 auto}}
</style>
