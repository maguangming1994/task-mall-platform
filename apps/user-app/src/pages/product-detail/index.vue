<template>
  <view class="page">
    <ModulePlaceholder v-if="modules.resolved && !moduleEnabled" code="module_mall" />
    <view v-else-if="!modules.resolved" class="mp-skeleton">
      <view class="tm-skeleton" style="height: 200rpx; border-radius: 24rpx; margin: 24rpx 28rpx;" />
      <view class="tm-skeleton" style="height: 320rpx; border-radius: 24rpx; margin: 20rpx 28rpx;" />
    </view>
    <template v-else>
    <view v-if="loading" class="loading-shell">
      <view class="loading-hero tm-skeleton" />
      <view class="loading-line tm-skeleton" />
      <view class="loading-line short tm-skeleton" />
    </view>
    <view v-else-if="loadError" class="missing">
      <text class="missing-title">商品详情加载失败</text>
      <small>{{ loadError }}</small>
      <button class="btn-main" @click="load">重新加载</button>
      <button class="btn-ghost" @click="backToMall">返回商城</button>
    </view>
    <template v-else-if="product">
      <view class="detail-hero">
        <swiper v-if="product.gallery_images.length" class="hero-swiper" circular :indicator-dots="product.gallery_images.length > 1" :autoplay="product.gallery_images.length > 1">
          <swiper-item v-for="image in product.gallery_images" :key="image.url"><image class="hero-img" :src="resolveApiAssetUrl(image.url)" mode="aspectFill" /></swiper-item>
        </swiper>
        <view v-else class="hero-img hero-placeholder"><text>{{ product.name.slice(0, 1) }}</text></view>
        <view class="hero-nav">
          <button class="hero-btn hero-back" aria-label="返回商城" @click="backToMall">‹</button>
          <button class="hero-btn cart-btn" aria-label="打开购物车" @click="openCart">购物车</button>
        </view>
      </view>

      <view class="title-card">
        <view class="price-row">
          <text class="price">{{ priceText(selectedOption) }}</text>
          <text class="spacer" />
          <text v-if="selectedSku" class="people">库存 {{ selectedSku.stock }}</text>
        </view>
        <text class="p-name">{{ product.name }}</text>
        <text v-if="product.subtitle || product.description_text" class="p-sub">{{ product.subtitle || product.description_text }}</text>
        <view class="tags">
          <text class="tag tag-green">{{ deliveryText(selectedSku?.fulfillment_type || '') }}</text>
          <text v-if="selectedOption && selectedOption.points > 0" class="tag tag-orange">支持积分</text>
        </view>
      </view>

      <view class="form-card">
        <view v-if="selectedSku && selectedSku.purchase_options.filter((item) => item.enabled).length" class="frow">
          <text class="f-label">方式</text>
          <view class="chip-pick">
            <text v-for="option in selectedSku.purchase_options.filter((item) => item.enabled)" :key="option.id" :class="{ on: option.id === selectedOption?.id }" @click="selectedOptionId = option.id">{{ modeText(option.purchase_mode) }}</text>
          </view>
        </view>
        <view class="frow">
          <text class="f-label">规格</text>
          <view class="chip-pick">
            <text v-for="sku in product.skus" :key="sku.id" :class="{ on: sku.id === selectedSkuId, off: sku.stock < 1 }" @click="selectSku(sku.id)">{{ sku.name }}</text>
          </view>
        </view>
        <view v-for="(value, key) in selectedSku?.specs || {}" :key="key" class="frow">
          <text class="f-label">{{ key }}</text>
          <text class="f-val">{{ value }}</text>
        </view>
        <view class="frow">
          <text class="f-label">配送</text>
          <text class="f-val">{{ deliveryText(selectedSku?.fulfillment_type || '') }}</text>
        </view>
      </view>

      <view v-if="!selectedSku || !selectedOption" class="availability-note">
        <text>当前商品暂无可购买规格</text>
        <small>请返回商城或稍后重试。</small>
      </view>

      <view class="card desc-card">
        <view class="sec-title"><text>商品介绍</text></view>
        <rich-text class="rich-content" :nodes="product.description" />
      </view>

      <view class="action-bar">
        <view class="ab-ic" @click="posterVisible = true"><text class="ab-ic-ico">↗</text><text>分享</text></view>
        <view class="ab-ic"><text class="ab-ic-ico">♡</text><text>收藏</text></view>
        <button class="ab-duo cart" :disabled="!canBuy" :loading="submitting && action === 'cart'" @click="submit('cart')">加入购物车</button>
        <button class="ab-duo buy" :disabled="!canBuy" :loading="submitting && action === 'buy'" @click="submit('buy')">立即兑换</button>
      </view>

      <!-- 分享朋友圈：生成带二维码的海报后保存转发 -->
      <SharePosterSheet
        :visible="posterVisible"
        kicker="PRODUCT POSTER"
        heading="商品分享海报"
        label="校园好物"
        :title="product.name"
        :content="shareDesc()"
        :link="posterLink"
        qr-target-type="product"
        :qr-target-id="product.id"
        :image-url="shareImage()"
        @close="posterVisible = false"
      />
    </template>
    <view v-else class="missing">
      <text class="missing-title">商品暂不可用</text>
      <button class="btn-main" @click="backToMall">返回商城</button>
    </view>
    </template>
  </view>
</template>

<script setup lang="ts">
import { onLoad, onShareAppMessage, onShareTimeline } from '@dcloudio/uni-app'
import { computed, ref } from 'vue'
import { addCartItem } from '@/api/cart'
import { resolveApiAssetUrl } from '@/api/http'
import { getProduct, type MallProduct, type PurchaseMode, type PurchaseOption } from '@/api/products'
import { enableWechatShare, toWechatShareLink } from '@/platform/wechatJssdk'
import SharePosterSheet from '@/components/SharePosterSheet.vue'
import { useAuthStore } from '@/stores/auth'
import { navigateToLogin } from '@/utils/authRedirect'
import ModulePlaceholder from '@/components/ModulePlaceholder.vue'
import { useModulesStore } from '@/stores/modules'

const modules = useModulesStore()
const moduleEnabled = computed(() => modules.isEnabled('module_mall'))

const auth = useAuthStore()
const product = ref<MallProduct | null>(null)
const loading = ref(true)
const loadError = ref('')
const productId = ref('')
const submitting = ref(false)
const action = ref<'cart' | 'buy'>('cart')
const posterVisible = ref(false)
const selectedSkuId = ref('')
const selectedOptionId = ref('')
const selectedSku = computed(() => product.value?.skus.find((sku) => sku.id === selectedSkuId.value) || product.value?.skus[0] || null)
const selectedOption = computed(() => selectedSku.value?.purchase_options.find((option) => option.id === selectedOptionId.value && option.enabled) || selectedSku.value?.purchase_options.find((option) => option.enabled) || null)
const canBuy = computed(() => !!selectedSku.value && selectedSku.value.stock > 0 && !!selectedOption.value && !submitting.value)

onLoad(async (query) => {
  productId.value = typeof query?.id === 'string' ? query.id : ''
  await modules.ensureReady()
  if (!moduleEnabled.value) return
  void load()
})

async function load() {
  const id = productId.value
  loading.value = true
  loadError.value = ''
  if (!id) { loading.value = false; loadError.value = '缺少商品编号。'; return }
  try {
    product.value = await getProduct(id)
    const firstSku = product.value.skus.find((sku) => sku.stock > 0) || product.value.skus[0]
    selectedSkuId.value = firstSku?.id || ''
    selectedOptionId.value = firstSku?.purchase_options.find((option) => option.enabled)?.id || ''
    void enableWechatShare({ title: shareTitle(), desc: shareDesc(), link: sharePath(), imageUrl: shareImage() })
  } catch (error) { loadError.value = error instanceof Error ? error.message : '请稍后重试。'; uni.showToast({ title: loadError.value, icon: 'none' }) }
  finally { loading.value = false }
}

function shareTitle() {
  return product.value?.name || '校园好物'
}
function shareDesc() {
  return product.value?.subtitle || product.value?.description_text || '发现校园好物，积分与现金灵活购。'
}
function sharePath() {
  return `/pages/product-detail/index?id=${encodeURIComponent(product.value?.id || productId.value)}`
}
function shareImage() {
  const url = product.value?.gallery_images?.[0]?.url
  return url ? resolveApiAssetUrl(url) : undefined
}
// 海报二维码使用绝对地址，微信内扫码可直接打开商品详情
const posterLink = computed(() => toWechatShareLink(sharePath()))

onShareAppMessage(() => ({ title: shareTitle(), path: sharePath(), imageUrl: shareImage() }))
onShareTimeline(() => ({ title: shareTitle(), query: `id=${encodeURIComponent(product.value?.id || productId.value)}`, imageUrl: shareImage() }))

function selectSku(id: string) {
  const sku = product.value?.skus.find((item) => item.id === id)
  if (!sku || sku.stock < 1) return
  selectedSkuId.value = id
  selectedOptionId.value = sku.purchase_options.find((option) => option.enabled)?.id || ''
}
function modeText(mode: PurchaseMode) { return ({ cash: '现金', points: '积分', cash_points: '现金 + 积分' } as const)[mode] }
function priceText(option: PurchaseOption | null) {
  if (!option) return '暂未定价'
  if (option.purchase_mode === 'cash') return `¥${(option.cash_amount / 100).toFixed(2)}`
  if (option.purchase_mode === 'points') return `${option.points.toLocaleString()} 积分`
  return `¥${(option.cash_amount / 100).toFixed(2)} + ${option.points.toLocaleString()}积分`
}
function deliveryText(type: string) { return ({ logistics: '物流配送', no_logistics: '无需物流', virtual_delivery: '虚拟交付' } as Record<string, string>)[type] || '交付方式以订单为准' }
async function submit(nextAction: 'cart' | 'buy') {
  if (!auth.isAuthenticated) return navigateToLogin()
  if (!selectedSku.value || !selectedOption.value) return
  action.value = nextAction
  submitting.value = true
  try {
    if (nextAction === 'buy') {
      const url = `/pages/checkout/index?directBuy=1&productId=${encodeURIComponent(product.value?.id || '')}&skuId=${encodeURIComponent(selectedSku.value.id)}&purchaseMode=${encodeURIComponent(selectedOption.value.purchase_mode)}`
      uni.navigateTo({ url })
    } else {
      await addCartItem(auth.accessToken, selectedSku.value.id, selectedOption.value.purchase_mode)
      uni.showToast({ title: '已加入购物车', icon: 'success' })
    }
  } catch (error) { uni.showToast({ title: error instanceof Error ? error.message : '操作失败', icon: 'none' }) }
  finally { submitting.value = false }
}
function backToMall() { uni.reLaunch({ url: '/pages/products/index' }) }
function openCart() { uni.navigateTo({ url: '/pages/cart/index' }) }
</script>

<style scoped>
.page{min-height:100vh;padding-bottom:200rpx;background:#F4F6F4;color:#191B1F}
.detail-hero{position:relative}
.hero-swiper,.hero-img{display:block;width:100%;height:560rpx}
.hero-placeholder{display:flex;align-items:center;justify-content:center;background:linear-gradient(145deg,#E7F9EF,#FFF2E5);color:#08A152;font-size:80rpx;font-weight:900}
.hero-nav{position:absolute;top:0;right:0;left:0;display:flex;align-items:center;justify-content:space-between;padding:24rpx 24rpx 80rpx;background:linear-gradient(to bottom,rgba(0,0,0,.28),transparent)}
.hero-btn{display:flex;align-items:center;justify-content:center;min-width:60rpx;height:60rpx;margin:0;padding:0 16rpx;border-radius:999rpx;background:rgba(255,255,255,.92);color:#191B1F;font-size:34rpx;line-height:56rpx}
.hero-btn.cart-btn{font-size:24rpx;font-weight:700}
.hero-btn::after{border:0}
/* #ifdef MP-WEIXIN */
/* 小程序原生导航栏已有返回键，页内返回键不再显示，避免出现两个返回按钮；购物车入口保留 */
.hero-back{visibility:hidden}
/* #endif */
.title-card{position:relative;margin:-32rpx 24rpx 0;padding:28rpx;border-radius:32rpx;background:#fff;box-shadow:0 2rpx 4rpx rgba(23,26,23,.03),0 12rpx 40rpx rgba(23,26,23,.05)}
.price-row{display:flex;align-items:baseline;gap:16rpx}
.price{color:#FF3B30;font-size:44rpx;font-weight:800}
.spacer{flex:1}
.people{color:#AEB3BB;font-size:22rpx}
.p-name{display:block;margin-top:16rpx;font-size:34rpx;font-weight:700;line-height:1.4}
.p-sub{display:block;margin-top:8rpx;color:#767C85;font-size:26rpx;line-height:1.5}
.tags{display:flex;gap:10rpx;margin-top:18rpx}
.tag{display:inline-flex;align-items:center;height:36rpx;padding:0 14rpx;border-radius:10rpx;font-size:20rpx}
.tag-green{background:#E7F9EF;color:#08A152}
.tag-orange{background:#FFF2E5;color:#F2620A}
.form-card{margin:20rpx 24rpx 0;padding:4rpx 28rpx;border-radius:32rpx;background:#fff}
.frow{display:flex;align-items:center;gap:20rpx;min-height:96rpx;padding:24rpx 0;border-bottom:1rpx solid #F5F6F6}
.frow:last-child{border-bottom:0}
.f-label{width:96rpx;flex:none;font-size:28rpx;font-weight:600;color:#191B1F}
.f-val{flex:1;font-size:26rpx;color:#767C85}
.chip-pick{display:flex;flex:1;flex-wrap:wrap;gap:14rpx}
.chip-pick text{padding:14rpx 26rpx;border-radius:14rpx;background:#F2F4F3;color:#767C85;font-size:26rpx}
.chip-pick text.on{background:#E7F9EF;color:#08A152;font-weight:700;box-shadow:inset 0 0 0 2rpx #0BBF63}
.chip-pick text.off{opacity:.4}
.availability-note{margin:20rpx 24rpx 0;padding:28rpx;border-radius:32rpx;background:#FFEEEA;color:#FF3B30;font-size:26rpx}
.availability-note text,.availability-note small{display:block}
.availability-note small{margin-top:6rpx;color:#767C85;font-size:24rpx}
.card{margin:20rpx 24rpx 0;overflow:hidden;border-radius:32rpx;background:#fff}
.sec-title{display:flex;align-items:center;padding:26rpx 28rpx 18rpx}
.sec-title text{display:flex;align-items:center;gap:14rpx;font-size:30rpx;font-weight:700}
.sec-title text::before{width:8rpx;height:28rpx;border-radius:4rpx;background:#0BBF63;content:''}
.rich-content{display:block;padding:0 28rpx 28rpx;color:#3A3D43;font-size:26rpx;line-height:1.7}
.action-bar{position:fixed;z-index:50;right:0;bottom:0;left:0;display:flex;align-items:center;gap:14rpx;padding:16rpx 24rpx calc(16rpx + env(safe-area-inset-bottom));border-top:1rpx solid #EDEFEF;background:#fff}
.ab-ic{display:flex;flex-direction:column;align-items:center;gap:4rpx;width:84rpx;color:#767C85;font-size:20rpx}
.ab-ic-ico{font-size:38rpx;line-height:1}
.ab-duo{flex:1;height:84rpx;margin:0;border-radius:999rpx;font-size:28rpx;font-weight:700;line-height:84rpx}
.ab-duo::after{border:0}
.ab-duo.cart{background:#FFF2E5;color:#F2620A}
.ab-duo.buy{background:linear-gradient(135deg,#FF9A3D,#F2620A);color:#fff;box-shadow:0 12rpx 28rpx rgba(242,98,10,.28)}
.ab-duo[disabled]{opacity:.5}
.loading-shell{padding:24rpx}
.loading-hero{height:560rpx;border-radius:32rpx}
.loading-line{height:32rpx;margin-top:28rpx;border-radius:12rpx}
.loading-line.short{width:58%}
.missing{padding:200rpx 48rpx 60rpx;text-align:center}
.missing-title{display:block;font-size:32rpx;font-weight:700}
.missing small{display:block;margin-top:12rpx;color:#767C85;font-size:26rpx}
.btn-main,.btn-ghost{width:320rpx;height:80rpx;border-radius:999rpx;font-size:28rpx;line-height:80rpx}
.btn-main{margin:32rpx auto 0;background:linear-gradient(135deg,#12D978,#08A152);color:#fff;font-weight:700}
.btn-ghost{margin:16rpx auto 0;border:1rpx solid #EDEFEF;background:#fff;color:#767C85}
.btn-main::after,.btn-ghost::after{border:0}
</style>
