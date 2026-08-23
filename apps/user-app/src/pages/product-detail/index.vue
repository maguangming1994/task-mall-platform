<template>
  <view class="page">
    <view v-if="loading" class="loading-shell tm-page-shell"><view class="detail-cover tm-skeleton" /><view class="loading-line tm-skeleton" /><view class="loading-line short tm-skeleton" /></view>
    <view v-else-if="loadError" class="missing tm-page-shell"><text>商品详情加载失败</text><small>{{ loadError }}</small><button class="tm-primary-button" @click="load">重新加载</button><button class="tm-secondary-button" @click="backToMall">返回商城</button></view>
    <view v-else-if="product">
      <view class="commerce-topbar">
        <button class="commerce-back" aria-label="返回商城" @click="backToMall">‹</button>
        <view class="commerce-topbar-title"><text>商品详情</text><small>TASK · REWARD · LIFE</small></view>
        <button class="commerce-cart" aria-label="打开购物车" @click="openCart">购物车</button>
      </view>
      <swiper v-if="product.gallery_images.length" class="detail-gallery" circular :indicator-dots="true" :autoplay="product.gallery_images.length > 1">
        <swiper-item v-for="image in product.gallery_images" :key="image.url"><image class="detail-cover" :src="image.url" mode="aspectFill" /></swiper-item>
      </swiper>
      <view v-else class="detail-cover placeholder"><text>{{ product.name.slice(0, 1) }}</text><small>任务商城精选</small></view>

      <view class="detail-body">
        <view class="title-row"><view><text class="title">{{ product.name }}</text><text class="subtitle">{{ product.subtitle || product.description_text }}</text></view><text class="current-price">{{ priceText(selectedOption) }}</text></view>

        <view class="mode-list">
          <view v-for="option in selectedSku?.purchase_options.filter((item) => item.enabled)" :key="option.id" class="mode-pill" :class="{ active: option.id === selectedOption?.id }" @click="selectedOptionId = option.id">
            <text>{{ modeText(option.purchase_mode) }}</text><small>{{ priceText(option) }}</small>
          </view>
        </view>

        <view class="option-section">
          <view class="section-label"><text>选择规格</text><text>{{ selectedSku ? `库存 ${selectedSku.stock}` : '' }}</text></view>
          <view class="sku-list">
            <view v-for="sku in product.skus" :key="sku.id" class="sku-pill" :class="{ active: sku.id === selectedSkuId, disabled: sku.stock < 1 }" @click="selectSku(sku.id)">{{ sku.name }}</view>
          </view>
          <view v-if="selectedSku" class="spec-grid">
            <view v-for="(value, key) in selectedSku.specs" :key="key" class="spec"><text>{{ key }}</text><strong>{{ value }}</strong></view>
          </view>
        </view>

        <view v-if="!selectedSku || !selectedOption" class="availability-note"><text>当前商品暂无可购买规格</text><small>请返回商城或稍后重试。</small></view>
        <view class="delivery-note"><text>{{ deliveryText(selectedSku?.fulfillment_type || '') }}</text><text>支持现金、积分、现金 + 积分三种购买方式；运费仅支持现金支付。</text></view>

        <view class="description tm-panel">
          <text class="section-title">商品介绍</text>
          <rich-text class="rich-content" :nodes="product.description" />
        </view>
      </view>

      <view class="bottom-actions">
        <button class="tm-secondary-button" :disabled="!canBuy" :loading="submitting && action === 'cart'" @click="submit('cart')">加入购物车</button>
        <button class="tm-primary-button" :disabled="!canBuy" :loading="submitting && action === 'buy'" @click="submit('buy')">立即购买</button>
      </view>
    </view>
    <view v-else class="missing tm-page-shell"><text>商品暂不可用</text><button class="tm-primary-button" @click="backToMall">返回商城</button></view>
  </view>
</template>

<script setup lang="ts">
import { onLoad } from '@dcloudio/uni-app'
import { computed, ref } from 'vue'
import { addCartItem } from '@/api/cart'
import { getProduct, type MallProduct, type PurchaseMode, type PurchaseOption } from '@/api/products'
import { useAuthStore } from '@/stores/auth'
import { navigateToLogin } from '@/utils/authRedirect'

const auth = useAuthStore()
const product = ref<MallProduct | null>(null)
const loading = ref(true)
const loadError = ref('')
const productId = ref('')
const submitting = ref(false)
const action = ref<'cart' | 'buy'>('cart')
const selectedSkuId = ref('')
const selectedOptionId = ref('')
const selectedSku = computed(() => product.value?.skus.find((sku) => sku.id === selectedSkuId.value) || product.value?.skus[0] || null)
const selectedOption = computed(() => selectedSku.value?.purchase_options.find((option) => option.id === selectedOptionId.value && option.enabled) || selectedSku.value?.purchase_options.find((option) => option.enabled) || null)
const canBuy = computed(() => !!selectedSku.value && selectedSku.value.stock > 0 && !!selectedOption.value && !submitting.value)

onLoad((query) => {
  productId.value = typeof query?.id === 'string' ? query.id : ''
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
  } catch (error) { loadError.value = error instanceof Error ? error.message : '请稍后重试。'; uni.showToast({ title: loadError.value, icon: 'none' }) }
  finally { loading.value = false }
}

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
  if (!auth.accessToken) return navigateToLogin()
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
.page{min-height:100vh;background:var(--tm-canvas)}.detail-gallery,.detail-cover{display:block;width:100%;height:590rpx}.detail-cover.placeholder{display:flex;align-items:center;justify-content:center;flex-direction:column;background:linear-gradient(145deg,#d8eeeb,#f4eadc);color:var(--tm-brand-700)}.detail-cover.placeholder text{font-size:88rpx;font-weight:950}.detail-cover.placeholder small{margin-top:10rpx;font-size:22rpx}.detail-body{padding:30rpx 28rpx 150rpx}.title-row{display:flex;align-items:flex-start;justify-content:space-between;gap:24rpx}.title,.subtitle,.current-price,.section-title,.rich-content{display:block}.title{font-size:39rpx;font-weight:950;line-height:1.3}.subtitle{margin-top:8rpx;color:var(--tm-ink-500);font-size:22rpx}.current-price{flex:none;color:var(--tm-reward-600);font-size:31rpx;font-weight:950}.mode-list{display:flex;gap:12rpx;margin-top:24rpx;overflow-x:auto}.mode-pill{min-width:155rpx;padding:14rpx 18rpx;border:1rpx solid var(--tm-line);border-radius:15rpx;background:#fff;color:var(--tm-ink-700)}.mode-pill text,.mode-pill small{display:block}.mode-pill text{font-size:21rpx;font-weight:800}.mode-pill small{margin-top:5rpx;font-size:18rpx}.mode-pill.active{border-color:var(--tm-brand-600);background:var(--tm-brand-50);color:var(--tm-brand-700)}.option-section{margin-top:30rpx}.section-label{display:flex;justify-content:space-between;color:var(--tm-ink-500);font-size:20rpx}.section-label text:first-child{color:var(--tm-ink-900);font-size:26rpx;font-weight:900}.sku-list{display:flex;gap:12rpx;margin-top:16rpx;flex-wrap:wrap}.sku-pill{padding:14rpx 22rpx;border:1rpx solid var(--tm-line);border-radius:14rpx;background:#fff;color:var(--tm-ink-700);font-size:21rpx}.sku-pill.active{border-color:var(--tm-brand-600);background:var(--tm-brand-50);color:var(--tm-brand-700);font-weight:800}.sku-pill.disabled{opacity:.42}.spec-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10rpx;margin-top:16rpx}.spec{display:flex;justify-content:space-between;padding:16rpx;border-radius:13rpx;background:#fff}.spec text{color:var(--tm-ink-500);font-size:20rpx}.spec strong{font-size:21rpx}.delivery-note{margin-top:24rpx;padding:20rpx;border-radius:16rpx;background:var(--tm-reward-100);color:#89500c;font-size:20rpx;line-height:1.55}.delivery-note text{display:block}.delivery-note text:first-child{font-weight:900}.description{margin-top:24rpx;padding:26rpx}.section-title{font-size:27rpx;font-weight:900}.rich-content{margin-top:18rpx;color:var(--tm-ink-700);font-size:23rpx}.bottom-actions{position:fixed;z-index:50;right:0;bottom:0;left:0;display:flex;gap:14rpx;padding:16rpx 24rpx calc(16rpx + env(safe-area-inset-bottom));border-top:1rpx solid var(--tm-line);background:#fff}.bottom-actions button{flex:1;margin:0}.loading-shell{padding-top:0}.loading-line{height:32rpx;margin-top:28rpx;border-radius:10rpx}.loading-line.short{width:58%}.missing{text-align:center;padding-top:180rpx}.missing text{display:block;margin-bottom:30rpx;color:var(--tm-ink-500)}
@media screen and (min-width:768px){.page{width:750rpx;margin:0 auto}.bottom-actions{right:auto;left:50%;width:750rpx;box-sizing:border-box;transform:translateX(-50%)}}
.availability-note{margin-top:24rpx;padding:20rpx;border:1rpx solid #f0b4a8;border-radius:16rpx;background:#fff4f2;color:#b42318;font-size:21rpx}.availability-note text,.availability-note small,.missing text,.missing small{display:block}.availability-note small,.missing small{margin-top:8rpx;color:var(--tm-ink-500);font-size:19rpx}.missing{text-align:center;padding:180rpx 34rpx 40rpx}.missing text{font-size:27rpx;font-weight:850}.missing button{width:280rpx;height:70rpx;margin:20rpx auto 0;line-height:70rpx}.missing button+button{margin-top:12rpx}.missing button::after{border:0}
.commerce-topbar{display:flex;align-items:center;justify-content:space-between;gap:16rpx;padding:18rpx 24rpx 14rpx;background:rgba(255,255,255,.96);border-bottom:1rpx solid var(--tm-line);box-sizing:border-box}.commerce-back,.commerce-cart{display:flex;align-items:center;justify-content:center;flex:none;margin:0;border:1rpx solid var(--tm-line);border-radius:999rpx;background:var(--tm-surface);color:var(--tm-ink-900);box-shadow:none}.commerce-back{width:66rpx;height:62rpx;font-size:44rpx;line-height:55rpx}.commerce-cart{height:58rpx;padding:0 20rpx;font-size:18rpx;font-weight:800;line-height:56rpx}.commerce-back::after,.commerce-cart::after{border:0}.commerce-topbar-title{min-width:0;flex:1;text-align:center}.commerce-topbar-title text,.commerce-topbar-title small{display:block}.commerce-topbar-title text{font-size:29rpx;font-weight:900}.commerce-topbar-title small{margin-top:2rpx;color:var(--tm-ink-500);font-size:13rpx;letter-spacing:1.2rpx}
/* V09 campus commerce surface */
.page{background:linear-gradient(180deg,var(--tm-campus-cyan-soft) 0,var(--tm-canvas) 700rpx);color:var(--tm-ink-900)}
.detail-gallery,.detail-cover{border-bottom:0}
.detail-cover.placeholder{background:linear-gradient(145deg,var(--tm-campus-cyan-soft),var(--tm-reward-100));color:var(--tm-brand-700)}
.title-row,.description{border:1rpx solid var(--tm-line);border-radius:var(--tm-radius-lg);background:var(--tm-surface);box-shadow:var(--tm-shadow-sm)}
.title-row{padding:24rpx}
.mode-pill,.sku-pill{border:1rpx solid var(--tm-line);box-shadow:none;background:var(--tm-surface)}
.mode-pill.active,.sku-pill.active{border-color:var(--tm-brand-500);background:var(--tm-brand-50);color:var(--tm-brand-700);box-shadow:none}
.mode-pill:active,.sku-pill:active{transform:scale(.98)}
.spec{border:1rpx solid var(--tm-line);background:var(--tm-surface-soft)}
.delivery-note{border:1rpx solid rgba(183,123,18,.18);background:var(--tm-reward-100);box-shadow:none;color:#89500c}
.description{padding:26rpx}
.bottom-actions{border-top:1rpx solid var(--tm-line);background:rgba(255,255,255,.96);box-shadow:0 -8rpx 24rpx rgba(7,26,51,.06);padding-bottom:calc(16rpx + env(safe-area-inset-bottom))}
.bottom-actions button{border:1rpx solid transparent;box-shadow:none}
.bottom-actions button[disabled],.sku-pill.disabled{opacity:.45}
.bottom-actions button[disabled]{filter:saturate(.55)}
.loading-shell,.missing{margin:0 28rpx;border-radius:var(--tm-radius-lg)}
.missing{background:var(--tm-surface);box-shadow:var(--tm-shadow-sm)}
@media (prefers-reduced-motion: reduce){.mode-pill:active,.sku-pill:active{transform:none}}
</style>
