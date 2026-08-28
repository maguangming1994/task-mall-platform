<template>
  <view class="page tm-page-shell">
    <view class="page-nav">
      <view class="nav-back" aria-label="返回" @click="goBack">‹</view>
      <text>精选商城</text>
      <view class="nav-placeholder" />
    </view>

    <view class="mall-head">
      <view class="brand-lockup">
        <image v-if="mallAppearance.brand.logo_url" class="brand-logo" :src="mallAppearance.brand.logo_url" mode="aspectFit" />
        <view v-else class="brand-logo brand-logo-fallback">{{ mallAppearance.brand.logo_text || 'L' }}</view>
        <view><text class="brand-name">{{ mallAppearance.brand.name || '任务商城' }}</text><text class="brand-sub">{{ mallAppearance.brand.caption || 'TASK · REWARD · LIFE' }}</text></view>
      </view>
      <button class="cart-button" @click="openCart"><text class="cart-glyph">⌑</text>购物车 <text class="cart-count">{{ cartItemCount }}</text></button>
    </view>

    <view class="search-box">
      <text class="search-mark">⌕</text>
      <input v-model="keyword" confirm-type="search" placeholder="搜索商品、课程或数字权益" />
    </view>

    <view class="hero">
      <image class="hero-image" src="/static/home/hero-campus-reward.png" mode="aspectFill" />
      <view class="hero-shade" />
      <view class="hero-content">
        <text class="hero-title">把努力兑换成\n更好的日常</text>
        <text class="hero-copy">现金、积分或组合购买，价格清晰一致</text>
        <view class="hero-action" @click="scrollToProducts">查看本周好物 <text>›</text></view>
      </view>
    </view>

    <view class="mall-assurance tm-card-strong tm-campus-grid">
      <view class="assurance-head"><view><text class="tm-section-kicker">CAMPUS SELECT</text><strong>校园严选服务</strong></view><text>{{ products.length }} 件好物</text></view>
      <view class="assurance-grid">
        <view v-for="item in mallAppearance.assurances" :key="item.id">
          <view :class="['assurance-icon', `tone-${item.tone || 'cyan'}`]"><image v-if="item.icon_url" :src="item.icon_url" mode="aspectFit" /><text v-else>{{ item.mark }}</text></view>
          <strong>{{ item.label }}</strong><small>{{ item.description }}</small>
        </view>
      </view>
    </view>

    <scroll-view class="category-scroll" scroll-x :show-scrollbar="false">
      <view class="categories">
        <view class="category" :class="{ active: categoryId === '' }" @click="selectCategory('')">推荐</view>
        <view v-for="item in categories" :key="item.id" class="category" :class="{ active: categoryId === item.id }" @click="selectCategory(item.id)">{{ item.name }}</view>
      </view>
    </scroll-view>

    <view id="product-list" class="section-head"><text>本周精选</text><text>{{ filteredProducts.length }} 件在售</text></view>
    <view v-if="loading" class="product-grid">
      <view v-for="index in 4" :key="index" class="product-card tm-panel"><view class="cover tm-skeleton" /><view class="skeleton-line tm-skeleton" /><view class="skeleton-line short tm-skeleton" /></view>
    </view>
    <view v-else-if="loadError" class="load-error tm-panel"><text class="empty-title">商品列表加载失败</text><text>{{ loadError }}</text><button @click="load">重新加载</button></view>
    <view v-else-if="filteredProducts.length" class="product-grid">
      <view v-for="product in filteredProducts" :key="product.id" class="product-card tm-panel" @click="openProduct(product.id)">
        <image v-if="cover(product)" class="cover" :src="cover(product)" mode="aspectFill" />
        <view v-else class="cover placeholder"><text>{{ product.name.slice(0, 1) }}</text><small>精选好物</small></view>
        <view class="product-body">
          <text class="product-name">{{ product.name }}</text>
          <text class="product-sub">{{ product.subtitle || product.description_text || plainTextFromRich(product.description) }}</text>
          <text class="product-modes">{{ purchaseModesText(product) }} · 运费仅现金</text><view class="product-foot"><text class="price">{{ lowestPrice(product) }}</text><text class="arrow">→</text></view>
        </view>
      </view>
    </view>
    <view v-else class="empty tm-panel"><text class="empty-title">没有找到匹配商品</text><text>换个关键词或分类试试</text></view>

    <AppTabBar current-target="products" />
  </view>
</template>

<script setup lang="ts">
import { onPullDownRefresh, onShow } from '@dcloudio/uni-app'
import { computed, ref } from 'vue'
import AppTabBar from '@/components/AppTabBar.vue'
import { loadAppearanceContent, type MallAppearance } from '@/api/appearance'
import { getCart } from '@/api/cart'
import { listProductCategories, listProducts, type MallProduct, type ProductCategory, type PurchaseOption } from '@/api/products'
import { plainTextFromRich } from '@/utils/richText'
import { useAuthStore } from '@/stores/auth'

const products = ref<MallProduct[]>([])
const categories = ref<ProductCategory[]>([])
const loading = ref(false)
const loadError = ref('')
const keyword = ref('')
const categoryId = ref('')
const cartItemCount = ref(0)
const auth = useAuthStore()
const mallAppearance = ref<MallAppearance>({
  brand: { logo_url: '', logo_text: 'L', name: '任务商城', caption: 'TASK · REWARD · LIFE' },
  assurances: [
    { id: 'same_price', label: '同端同价', description: '各端价格保持一致', mark: '价', icon_url: '', tone: 'yellow' },
    { id: 'payments', label: '三种支付', description: '现金、积分、混合', mark: '付', icon_url: '', tone: 'cyan' },
    { id: 'after_sales', label: '售后可查', description: '退款进度完整留痕', mark: '退', icon_url: '', tone: 'green' },
  ],
})

const filteredProducts = computed(() => {
  const query = keyword.value.trim().toLowerCase()
  return products.value.filter((product) => {
    if (query && !`${product.name} ${product.subtitle || ''} ${product.description_text || ''}`.toLowerCase().includes(query)) return false
    return true
  })
})

onShow(load)
onPullDownRefresh(async () => { try { await load() } finally { uni.stopPullDownRefresh() } })

async function load() {
  loading.value = true
  loadError.value = ''
  try {
    const [productResult, categoryResult, appearanceResult, cartResult] = await Promise.all([
      listProducts(categoryId.value || undefined),
      listProductCategories(),
      loadAppearanceContent().catch(() => null),
      auth.isAuthenticated ? getCart(auth.accessToken).catch(() => null) : Promise.resolve(null),
    ])
    products.value = productResult.items
    categories.value = categoryResult.items
    if (appearanceResult) mallAppearance.value = appearanceResult.mall
    cartItemCount.value = cartResult?.items.reduce((count, item) => count + item.quantity, 0) || 0
  }
  catch (error) { loadError.value = error instanceof Error ? error.message : '请稍后重试。'; uni.showToast({ title: loadError.value, icon: 'none' }) }
  finally { loading.value = false }
}

function allOptions(product: MallProduct) { return product.skus.flatMap((sku) => sku.purchase_options.filter((option) => option.enabled)) }
async function selectCategory(id: string) { if (categoryId.value === id) return; categoryId.value = id; await load() }
function cover(product: MallProduct) { return product.gallery_images?.[0]?.url || '' }
function optionPrice(option: PurchaseOption) {
  if (option.purchase_mode === 'cash') return `¥${(option.cash_amount / 100).toFixed(2)}`
  if (option.purchase_mode === 'points') return `${option.points.toLocaleString()} 积分`
  return `¥${(option.cash_amount / 100).toFixed(2)} + ${option.points.toLocaleString()}积分`
}
function lowestPrice(product: MallProduct) {
  const options = allOptions(product)
  if (!options.length) return '暂未定价'
  const cash = options.filter((option) => option.purchase_mode === 'cash').sort((a, b) => a.cash_amount - b.cash_amount)[0]
  return optionPrice(cash || options[0])
}
function purchaseModesText(product: MallProduct) {
  const labels: Record<string, string> = { cash: '现金', points: '积分', cash_points: '现金 + 积分' }
  const modes = [...new Set(allOptions(product).map((option) => labels[option.purchase_mode] || option.purchase_mode))]
  return modes.length ? modes.join(' / ') : '暂无可购买方式'
}
function openProduct(id: string) { uni.navigateTo({ url: `/pages/product-detail/index?id=${encodeURIComponent(id)}` }) }
function openCart() { uni.navigateTo({ url: '/pages/cart/index' }) }
function scrollToProducts() { uni.pageScrollTo({ selector: '#product-list', duration: 260 }) }
function goBack() {
  const pages = getCurrentPages()
  if (pages.length > 1) uni.navigateBack()
  else uni.reLaunch({ url: '/pages/index/index' })
}
</script>

<style scoped>
.page{min-height:100vh;padding-top:0;background:linear-gradient(180deg,#fff 0,#f5fbff 430rpx,#f5faff 100%);color:#10264a}.page-nav{position:relative;display:flex;align-items:center;justify-content:center;height:88rpx;margin:0 -24rpx 8rpx;background:rgba(255,255,255,.96);font-size:19px;font-weight:900}.nav-back{position:absolute;left:20rpx;top:10rpx;display:flex;width:68rpx;height:68rpx;align-items:center;justify-content:center;color:#0e2142;font-size:30px;font-weight:400}.nav-placeholder{width:68rpx;height:68rpx}.mall-head,.brand-lockup,.product-foot{display:flex;align-items:center}.mall-head{justify-content:space-between;padding:18rpx 8rpx 4rpx}.brand-lockup{gap:16rpx}.brand-logo{width:60rpx;height:60rpx;border-radius:18rpx;box-shadow:0 10rpx 24rpx rgba(17,177,119,.14)}.brand-logo-fallback{display:flex;align-items:center;justify-content:center;background:linear-gradient(145deg,#20c77f,#00a864);color:#fff;font-size:17px;font-weight:950}.brand-name,.brand-sub,.hero-title,.hero-copy,.product-name,.product-sub,.empty text{display:block}.brand-name{color:#10264a;font-size:19px;font-weight:900}.brand-sub{margin-top:3rpx;color:#7988a5;font-size:10px;letter-spacing:1.2rpx}.cart-button{display:flex;align-items:center;gap:8rpx;height:64rpx;margin:0;padding:0 22rpx;border:1rpx solid #dce8f2;border-radius:999rpx;background:#fff;color:#173354;font-size:14px;line-height:62rpx;box-shadow:0 10rpx 26rpx rgba(35,72,110,.08)}.cart-button::after{border:0}.cart-glyph{font-size:17px;transform:rotate(-8deg)}.cart-count{min-width:22rpx;text-align:center;color:#0aa76f;font-size:13px;font-weight:900}.search-box{display:flex;align-items:center;gap:12rpx;height:76rpx;margin-top:20rpx;padding:0 24rpx;border:1rpx solid #dce8f2;border-radius:999rpx;background:#fff;box-shadow:0 12rpx 30rpx rgba(36,75,112,.07)}.search-box input{height:76rpx;flex:1;color:#173354;font-size:14px}.search-mark{color:#71819e;font-size:21px}.hero{position:relative;height:330rpx;overflow:hidden;margin-top:22rpx;border-radius:30rpx;background:#21c7c3;box-shadow:0 18rpx 40rpx rgba(25,119,154,.15)}.hero-image,.hero-shade{position:absolute;inset:0;width:100%;height:100%}.hero-shade{background:linear-gradient(90deg,rgba(4,139,155,.95) 0,rgba(15,179,178,.8) 46%,rgba(40,193,194,.06) 73%)}.hero-content{position:relative;z-index:1;width:61%;padding:54rpx 0 0 34rpx;color:#fff}.hero-title{white-space:pre-line;font-size:24px;font-weight:950;line-height:1.22;text-shadow:0 2rpx 8rpx rgba(8,79,100,.12)}.hero-copy{margin-top:17rpx;color:rgba(255,255,255,.94);font-size:14px;line-height:1.5}.hero-action{display:inline-flex;align-items:center;gap:12rpx;margin-top:25rpx;padding:14rpx 25rpx;border-radius:999rpx;background:#fff;color:#143254;font-size:14px;font-weight:850;box-shadow:0 8rpx 18rpx rgba(4,94,113,.12)}.hero-action text{font-size:17px}.mall-assurance{margin-top:24rpx;padding:24rpx;border:1rpx solid #dce8f2;border-radius:28rpx;background:rgba(255,255,255,.96);box-shadow:0 14rpx 36rpx rgba(39,74,108,.07)}.assurance-head{display:flex;align-items:flex-start;justify-content:space-between}.assurance-head>view strong{display:block;margin-top:9rpx;color:#10264a;font-size:17px;font-weight:950}.assurance-head>text{padding-top:7rpx;color:#7d8ba3;font-size:13px}.assurance-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:12rpx;margin-top:20rpx}.assurance-grid>view{min-width:0;padding:16rpx 13rpx;border:1rpx solid #dce8f2;border-radius:20rpx;background:#fff}.assurance-grid strong,.assurance-grid small{display:block}.assurance-grid strong{margin-top:10rpx;color:#10264a;font-size:13px;font-weight:900}.assurance-grid small{margin-top:5rpx;color:#7b8aa2;font-size:10px;line-height:1.35}.assurance-icon{display:flex;width:43rpx;height:43rpx;align-items:center;justify-content:center;border-radius:14rpx;color:#fff;font-size:13px;font-weight:950;box-shadow:0 7rpx 15rpx rgba(45,101,128,.1)}.assurance-icon image{width:100%;height:100%;border-radius:14rpx}.tone-yellow{background:linear-gradient(145deg,#ffca3d,#ffad1f)}.tone-cyan{background:linear-gradient(145deg,#15c9d0,#0ea5b2)}.tone-green{background:linear-gradient(145deg,#61d84c,#24b948)}.category-scroll{margin-top:22rpx;white-space:nowrap}.categories{display:inline-flex;gap:12rpx}.category{padding:13rpx 24rpx;border:1rpx solid #d9e6ef;border-radius:999rpx;background:#fff;color:#53657f;font-size:14px}.category.active{border-color:#0bb478;background:#0bb478;color:#fff;font-weight:850;box-shadow:0 8rpx 18rpx rgba(11,180,120,.18)}.section-head{display:flex;justify-content:space-between;align-items:flex-end;margin:34rpx 2rpx 18rpx}.section-head text:first-child{color:#10264a;font-size:19px;font-weight:950}.section-head text:last-child{color:#7b8aa3;font-size:13px}.product-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16rpx}.product-card{overflow:hidden;border:1rpx solid #dce8f2;border-radius:24rpx;background:#fff;box-shadow:0 12rpx 30rpx rgba(36,75,112,.07)}.cover{display:block;width:100%;height:224rpx;background:#edf5f7}.cover.placeholder{display:flex;align-items:center;justify-content:center;flex-direction:column;background:linear-gradient(145deg,#e4f8ee,#eef8ff);color:#0a936a}.cover.placeholder text{font-size:28px;font-weight:900}.cover.placeholder small{margin-top:8rpx;font-size:13px}.product-body{padding:20rpx}.product-name{overflow:hidden;color:#10264a;font-size:15px;font-weight:900;text-overflow:ellipsis;white-space:nowrap}.product-sub{height:58rpx;margin-top:7rpx;overflow:hidden;color:#7b8aa3;font-size:13px;line-height:1.45}.product-modes{display:block;margin-top:8rpx;color:#60728c;font-size:12px;line-height:1.4}.product-foot{justify-content:space-between;margin-top:14rpx}.price{color:#ff6a31;font-size:15px;font-weight:950}.arrow{display:flex;align-items:center;justify-content:center;width:42rpx;height:42rpx;border-radius:50%;background:#e8fbf3;color:#08a971;font-weight:950}.empty{padding:78rpx 30rpx;text-align:center;border:1rpx solid #dce8f2;border-radius:28rpx;background:rgba(255,255,255,.9);color:#7b8aa3;box-shadow:0 12rpx 30rpx rgba(36,75,112,.06)}.empty::before{content:'⌕';display:flex;align-items:center;justify-content:center;width:90rpx;height:90rpx;margin:0 auto 22rpx;border-radius:28rpx;background:linear-gradient(145deg,#e6f8ef,#e8f5ff);color:#20b87e;font-size:28px}.empty-title{margin-bottom:10rpx;color:#10264a;font-size:17px;font-weight:900}.skeleton-line{height:22rpx;margin:20rpx 18rpx 0;border-radius:8rpx}.skeleton-line.short{width:54%;margin-bottom:22rpx}.load-error{margin-top:25rpx;padding:50rpx 30rpx;text-align:center;border:1rpx solid #ffc9c1;border-radius:24rpx;background:#fff7f5;color:#b42318}.load-error button{height:68rpx;margin:22rpx auto 0;padding:0 28rpx;border:1rpx solid #e7a79f;border-radius:16rpx;background:#fff;color:#b42318;line-height:66rpx}.load-error button::after{border:0}@media screen and (min-width:768px){.page{max-width:750rpx;margin:0 auto}.hero{height:350rpx}}
.page-nav{height:88rpx;padding-top:calc(env(safe-area-inset-top) + 4rpx);box-sizing:content-box}
.nav-back{top:auto;bottom:10rpx}
</style>
