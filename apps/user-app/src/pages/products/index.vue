<template>
  <view class="page" :style="topSafeAreaStyle">
    <ModulePlaceholder v-if="modules.resolved && !moduleEnabled" code="module_mall" />
    <view v-else-if="!modules.resolved" class="mp-skeleton">
      <view class="tm-skeleton" style="height: 200rpx; border-radius: 24rpx; margin: 24rpx 28rpx;" />
      <view class="tm-skeleton" style="height: 320rpx; border-radius: 24rpx; margin: 20rpx 28rpx;" />
    </view>
    <template v-else>
      <view class="page-head">
        <text class="page-title">积分商城</text>
        <button class="cart-btn" @click="openCart">
          <text class="cart-icon">🛒</text>
          <text v-if="cartItemCount > 0" class="cart-badge">{{ cartItemCount }}</text>
        </button>
      </view>

      <view class="search-full" @click="focusSearch">
        <text class="search-icon">⌕</text>
        <input v-model="keyword" confirm-type="search" placeholder="搜索商品，如「柠檬茶」「键盘」" :focus="searchFocused" @blur="searchFocused = false" />
      </view>

      <DecorationRenderer
        v-if="decoration.sections.length"
        :sections="decoration.sections"
        @navigate="handleDecorationLink"
      />

      <view class="coupons">
        <view class="coupon">
          <view class="c-val"><text class="c-symbol">¥</text>5</view>
          <view class="c-txt">
            <text>满 50 积分可用</text>
            <text>全品类通用券</text>
          </view>
        </view>
        <view class="coupon c2">
          <view class="c-val"><text class="c-symbol">¥</text>12</view>
          <view class="c-txt">
            <text>满 100 积分可用</text>
            <text>限时领取中</text>
          </view>
        </view>
        <view class="coupon-more">
          <text class="coupon-more-icon">🎫</text>
          <text>领券中心</text>
        </view>
      </view>

      <view class="king" :style="kingGridStyle">
        <view
          v-for="(item, idx) in kingCategories"
          :key="item.id"
          class="k"
          :class="{ 'k-active': categoryId === item.id }"
          @click="selectCategory(item.id)"
        >
          <view class="k-icon" :style="kingIconStyle(idx)">
            <text class="k-icon-text">{{ categoryEmoji(item.name) }}</text>
          </view>
          <text class="k-label">{{ item.name }}</text>
        </view>
      </view>

      <view class="flash">
        <view class="sec-title">
          <text class="sec-h2">限时秒杀</text>
          <view class="count">
            <text>距结束</text>
            <text class="cd-num">{{ flashCountdown.hours }}</text>
            <text>:</text>
            <text class="cd-num">{{ flashCountdown.minutes }}</text>
            <text>:</text>
            <text class="cd-num">{{ flashCountdown.seconds }}</text>
          </view>
        </view>
        <scroll-view class="flash-scroll" scroll-x :show-scrollbar="false">
          <view v-for="(product, idx) in flashProducts" :key="product.id" class="f-item" @click="openProduct(product.id)">
            <image v-if="cover(product)" class="f-img" :src="cover(product)" mode="aspectFill" />
            <view v-else class="f-img placeholder"><text>{{ product.name.slice(0, 1) }}</text></view>
            <text class="f-name">{{ product.name }}</text>
            <view class="f-price">
              <text class="now">{{ lowestPrice(product) }}</text>
              <text class="old">{{ highestPrice(product) }}</text>
            </view>
            <view class="f-bar">
              <view class="f-bar-inner" :style="{ width: flashProgress(idx) }" />
              <text class="f-bar-text">已抢 {{ flashProgress(idx) }}</text>
            </view>
          </view>
        </scroll-view>
      </view>

      <view class="rank">
        <view class="sec-title">
          <text class="sec-h2">积分热卖榜</text>
          <view class="more">
            <text>完整榜单</text>
            <text class="arrow">›</text>
          </view>
        </view>
        <view v-for="(product, idx) in rankProducts" :key="product.id" class="r-row" @click="openProduct(product.id)">
          <view class="r-no" :class="'r-no-' + (idx + 1)">{{ idx + 1 }}</view>
          <image v-if="cover(product)" class="r-img" :src="cover(product)" mode="aspectFill" />
          <view v-else class="r-img placeholder"><text>{{ product.name.slice(0, 1) }}</text></view>
          <view class="r-main">
            <text class="r-name">{{ product.name }}</text>
            <text class="r-meta">本月已兑 {{ rankSold(product) }} 件</text>
          </view>
          <view class="r-pts">{{ lowestPrice(product) }}</view>
        </view>
      </view>

      <view class="sec-title sec-title-goods" id="product-list">
        <text class="sec-h2">为你推荐</text>
        <view class="more" @click="load">
          <text>换一批</text>
        </view>
      </view>

      <view v-if="loading" class="goods">
        <view v-for="index in 4" :key="index" class="g-card">
          <view class="g-cover tm-skeleton" />
          <view class="g-body">
            <view class="tm-skeleton" style="height: 28rpx; margin-bottom: 10rpx; border-radius: 6rpx;" />
            <view class="tm-skeleton" style="height: 28rpx; width: 60%; border-radius: 6rpx;" />
          </view>
        </view>
      </view>
      <view v-else-if="loadError" class="load-error tm-panel">
        <text class="empty-title">商品列表加载失败</text>
        <text>{{ loadError }}</text>
        <button @click="load">重新加载</button>
      </view>
      <view v-else-if="filteredProducts.length" class="goods">
        <view v-for="(product, idx) in filteredProducts" :key="product.id" class="g-card" @click="openProduct(product.id)">
          <image v-if="cover(product)" class="g-cover" :src="cover(product)" mode="aspectFill" />
          <view v-else class="g-cover placeholder"><text>{{ product.name.slice(0, 1) }}</text></view>
          <view class="g-body">
            <text class="g-name">{{ product.name }}</text>
            <view class="g-tags">
              <text v-if="hasFreeShipping(product)" class="tag tag-orange">包邮</text>
              <text v-else-if="idx % 2 === 0" class="tag tag-green">新品</text>
              <text v-else class="tag tag-red">HOT</text>
            </view>
            <view class="g-foot">
              <text class="g-price">{{ lowestPrice(product) }}</text>
              <text v-if="productSold(product)" class="g-sold">{{ productSold(product) }}</text>
            </view>
          </view>
        </view>
      </view>
      <view v-else class="empty tm-panel">
        <text class="empty-title">没有找到匹配商品</text>
        <text>换个关键词或分类试试</text>
      </view>
    </template>
    <AppTabBar current-target="products" />
  </view>
</template>

<script setup lang="ts">
import { onPullDownRefresh, onShow } from '@dcloudio/uni-app'
import { computed, onUnmounted, ref } from 'vue'
import AppTabBar from '@/components/AppTabBar.vue'
import { loadAppearanceContent, type MallAppearance } from '@/api/appearance'
import { getCart } from '@/api/cart'
import { resolveApiAssetUrl } from '@/api/http'
import { listProductCategories, listProducts, type MallProduct, type ProductCategory, type PurchaseOption } from '@/api/products'
import { useAuthStore } from '@/stores/auth'
import { createTopSafeAreaStyle } from '@/utils/safeArea'
import ModulePlaceholder from '@/components/ModulePlaceholder.vue'
import DecorationRenderer from '@/components/decoration/DecorationRenderer.vue'
import { loadPageDecoration, type DecorationPageConfig } from '@/api/decoration'
import { useModulesStore } from '@/stores/modules'

const modules = useModulesStore()
const moduleEnabled = computed(() => modules.isEnabled('module_mall'))

const topSafeAreaStyle = createTopSafeAreaStyle()
const products = ref<MallProduct[]>([])
const decoration = ref<DecorationPageConfig>({ background_url: '', sections: [] })
const categories = ref<ProductCategory[]>([])
const loading = ref(false)
const loadError = ref('')
const keyword = ref('')
const searchFocused = ref(false)

function focusSearch() {
  searchFocused.value = true
}
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

const flashProducts = computed(() => products.value.slice(0, 3))
const rankProducts = computed(() => products.value.slice(0, 3))
// 金刚区直接展示后端返回的真实分类（含在售商品才会返回），最多 5 个，按数量自适应列宽。
const kingCategories = computed(() => categories.value.slice(0, 5))
const kingGridStyle = computed(() => ({ gridTemplateColumns: `repeat(${Math.max(1, kingCategories.value.length)}, 1fr)` }))

// 限时秒杀：真实倒计时到当日 24 点结束，每秒刷新。
const flashRemainSeconds = ref(0)
function syncFlashRemain() {
  const now = new Date()
  const end = new Date()
  end.setHours(24, 0, 0, 0)
  flashRemainSeconds.value = Math.max(0, Math.floor((end.getTime() - now.getTime()) / 1000))
}
syncFlashRemain()
const flashTimer = setInterval(syncFlashRemain, 1000)
onUnmounted(() => clearInterval(flashTimer))
const flashCountdown = computed(() => {
  const total = flashRemainSeconds.value
  const pad = (value: number) => String(value).padStart(2, '0')
  return {
    hours: pad(Math.floor(total / 3600)),
    minutes: pad(Math.floor((total % 3600) / 60)),
    seconds: pad(total % 60),
  }
})

onShow(async () => {
  await modules.ensureReady()
  if (!moduleEnabled.value) return
  await load()
})
onPullDownRefresh(async () => { try { await modules.ensureReady(); if (!moduleEnabled.value) return; await load() } finally { uni.stopPullDownRefresh() } })

async function load() {
  loading.value = true
  loadError.value = ''
  try {
    const [productResult, categoryResult, appearanceResult, cartResult, decorationResult] = await Promise.all([
      listProducts(categoryId.value || undefined),
      listProductCategories(),
      loadAppearanceContent().catch(() => null),
      auth.isAuthenticated ? getCart(auth.accessToken).catch(() => null) : Promise.resolve(null),
      loadPageDecoration(),
    ])
    products.value = productResult.items
    categories.value = categoryResult.items
    if (appearanceResult) mallAppearance.value = appearanceResult.mall
    decoration.value = decorationResult.pages.mall
    cartItemCount.value = cartResult?.items.reduce((count, item) => count + item.quantity, 0) || 0
  }
  catch (error) { loadError.value = error instanceof Error ? error.message : '请稍后重试。'; uni.showToast({ title: loadError.value, icon: 'none' }) }
  finally { loading.value = false }
}

function allOptions(product: MallProduct) { return product.skus.flatMap((sku) => sku.purchase_options.filter((option) => option.enabled)) }
// 点击金刚区分类按分类筛选；再次点击同一个分类则取消筛选回到全部商品。
async function selectCategory(id: string) {
  const next = categoryId.value === id ? '' : id
  if (next === categoryId.value) return
  categoryId.value = next
  await load()
}
function cover(product: MallProduct) { return resolveApiAssetUrl(product.gallery_images?.[0]?.url) || '' }
// 虚拟券/无需物流的商品真实包邮，实体物流商品不显示包邮标签。
function hasFreeShipping(product: MallProduct) {
  return product.skus.some((sku) => sku.fulfillment_type === 'virtual_delivery' || sku.fulfillment_type === 'no_logistics')
}
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
function highestPrice(product: MallProduct) {
  const options = allOptions(product)
  if (!options.length) return ''
  const cash = options.filter((option) => option.purchase_mode === 'cash').sort((a, b) => b.cash_amount - a.cash_amount)[0]
  return optionPrice(cash || options[options.length - 1])
}
function purchaseModesText(product: MallProduct) {
  const labels: Record<string, string> = { cash: '现金', points: '积分', cash_points: '现金 + 积分' }
  const modes = [...new Set(allOptions(product).map((option) => labels[option.purchase_mode] || option.purchase_mode))]
  return modes.length ? modes.join(' / ') : '暂无可购买方式'
}
function openProduct(id: string) { uni.navigateTo({ url: `/pages/product-detail/index?id=${encodeURIComponent(id)}` }) }
function handleDecorationLink(link: string) {
  if (link.startsWith('tasks:')) {
    const categoryValue = link.slice('tasks:'.length)
    if (categoryValue) uni.setStorageSync('task_mall_hall_category', categoryValue)
    return uni.navigateTo({ url: '/pages/tasks/index' })
  }
  const routes: Record<string, string> = {
    tasks: '/pages/tasks/index',
    products: '/pages/products/index',
    community: '/pages/community/index',
    notifications: '/pages/notifications/index',
    orders: '/pages/orders/index',
    assets: '/pages/assets/index',
    push: '/pages/push-center/index',
    second_hand: '/pages/second-hand/index',
    secondhand: '/pages/second-hand/index',
    material: '/pages/material/index',
    express_pickup: '/pages-express/index',
  }
  const url = routes[link]
  if (url) uni.navigateTo({ url })
}
function openCart() { uni.navigateTo({ url: '/pages/cart/index' }) }
function scrollToProducts() { uni.pageScrollTo({ selector: '#product-list', duration: 260 }) }

function flashProgress(index: number) {
  const defaults = ['82%', '64%', '45%']
  return defaults[index % defaults.length]
}
function rankSold(product: MallProduct) {
  const count = (product as any).sales_count || (product as any).exchanged_count || (product as any).sold_count || 0
  if (count >= 1000) return `${(count / 1000).toFixed(1)}k`
  return String(count)
}
function productSold(product: MallProduct) {
  const count = (product as any).sales_count || (product as any).exchanged_count || (product as any).sold_count || 0
  return count > 0 ? `已兑 ${count}` : ''
}
function categoryEmoji(name: string) {
  const map: Record<string, string> = {
    '零食饮料': '🥤', '数码配件': '🎧', '学习用品': '📖', '生活日用': '🧴', '卡券中心': '🎫',
    '食品': '🍔', '饮料': '🥤', '电子': '💻', '图书': '📖', '美妆': '💄', '运动': '⚽',
  }
  for (const key in map) {
    if (name.includes(key)) return map[key]
  }
  return name.slice(0, 1)
}
function kingIconStyle(index: number) {
  const palettes = [
    { bg: '#FFF2E5', color: '#F2620A' },
    { bg: '#EAF2FF', color: '#3B82F6' },
    { bg: '#E7F9EF', color: '#0BBF63' },
    { bg: '#F1EAFF', color: '#8B5CF6' },
    { bg: '#FFEAF1', color: '#F43F7F' },
  ]
  const p = palettes[index % palettes.length]
  return { backgroundColor: p.bg, color: p.color }
}
</script>

<style scoped>
.page { min-height: 100vh; padding-top: calc(var(--tm-custom-header-top, calc(env(safe-area-inset-top) + 8px)) + 18rpx); padding-bottom: calc(152rpx + env(safe-area-inset-bottom)); background: #F4F6F4; color: #191B1F; }
.page-head { display: flex; align-items: center; justify-content: space-between; padding: 4rpx 32rpx 0; }
.page-title { font-size: 40rpx; font-weight: 800; }
.cart-btn { position: relative; width: 68rpx; height: 68rpx; border-radius: 50%; background: #fff; border: 1rpx solid #EDEFEF; display: flex; align-items: center; justify-content: center; color: #191B1F; font-size: 28rpx; }
.cart-btn::after { border: 0; }
.cart-icon { font-size: 28rpx; }
.cart-badge { position: absolute; top: -6rpx; right: -6rpx; min-width: 30rpx; height: 30rpx; border-radius: 16rpx; background: #FF3B30; color: #fff; font-size: 18rpx; font-weight: 700; display: flex; align-items: center; justify-content: center; padding: 0 6rpx; }
.search-full { display: flex; align-items: center; gap: 12rpx; height: 72rpx; margin: 20rpx 24rpx 0; padding: 0 28rpx; border-radius: 36rpx; background: #fff; border: 1rpx solid #EDEFEF; color: #AEB3BB; font-size: 24rpx; }
.search-full input { flex: 1; height: 72rpx; color: #191B1F; font-size: 24rpx; }
.search-icon { color: #AEB3BB; font-size: 30rpx; }
.coupons { display: flex; gap: 16rpx; margin: 20rpx 24rpx 0; }
.coupon { flex: 1; height: 124rpx; border-radius: 24rpx; background: linear-gradient(135deg, #FF9A3D, #F2620A); color: #fff; display: flex; align-items: center; padding: 0 24rpx; gap: 16rpx; position: relative; overflow: hidden; }
.coupon.c2 { background: linear-gradient(135deg, #FF5C6C, #E8384F); }
.coupon::before { content: ''; position: absolute; left: 104rpx; top: -12rpx; width: 24rpx; height: 24rpx; border-radius: 50%; background: #F4F6F4; }
.coupon::after { content: ''; position: absolute; left: 104rpx; bottom: -12rpx; width: 24rpx; height: 24rpx; border-radius: 50%; background: #F4F6F4; }
.c-val { font-size: 40rpx; font-weight: 800; line-height: 1; }
.c-symbol { font-size: 20rpx; font-weight: 500; }
.c-txt { font-size: 20rpx; opacity: 0.92; border-left: 1rpx dashed rgba(255,255,255,0.5); padding-left: 16rpx; margin-left: 4rpx; line-height: 1.5; display: flex; flex-direction: column; }
.coupon-more { flex: 0 0 112rpx; display: flex; flex-direction: column; align-items: center; justify-content: center; background: #fff; border-radius: 24rpx; font-size: 20rpx; color: #767C85; gap: 6rpx; }
.coupon-more-icon { font-size: 36rpx; }
.king { background: #fff; border-radius: 32rpx; margin: 20rpx 24rpx 0; padding: 32rpx 20rpx 24rpx; display: grid; row-gap: 28rpx; box-shadow: 0 1rpx 2rpx rgba(23,26,23,0.03), 0 12rpx 40rpx rgba(23,26,23,0.05); }
.k { display: flex; flex-direction: column; align-items: center; gap: 12rpx; font-size: 22rpx; color: #3A3D43; }
.k-icon { width: 84rpx; height: 84rpx; border-radius: 50%; display: flex; align-items: center; justify-content: center; transition: box-shadow 0.2s ease, transform 0.2s ease; }
.k-icon-text { font-size: 36rpx; }
.k-label { font-size: 22rpx; }
.k-active .k-icon { box-shadow: 0 0 0 4rpx rgba(11,191,99,0.22); transform: translateY(-2rpx); }
.k-active .k-label { color: #08A152; font-weight: 700; }
.flash { background: #fff; border-radius: 32rpx; margin: 20rpx 24rpx 0; padding-bottom: 24rpx; box-shadow: 0 1rpx 2rpx rgba(23,26,23,0.03), 0 12rpx 40rpx rgba(23,26,23,0.05); }
.sec-title { display: flex; align-items: center; justify-content: space-between; padding: 28rpx 28rpx 20rpx; }
.sec-title.sec-title-goods { padding-left: 32rpx; padding-right: 24rpx; }
.sec-h2 { font-size: 32rpx; font-weight: 700; display: flex; align-items: center; gap: 14rpx; }
.sec-h2::before { content: ''; width: 8rpx; height: 28rpx; border-radius: 4rpx; background: #0BBF63; }
.flash .sec-title .sec-h2 { color: #F2620A; }
.flash .sec-title .sec-h2::before { background: #FF7A1A; }
.rank .sec-title .sec-h2::before { background: #FFB020; }
.count { display: flex; align-items: center; gap: 6rpx; font-size: 22rpx; color: #767C85; }
.cd-num { min-width: 40rpx; height: 40rpx; border-radius: 10rpx; background: #2B2E33; color: #fff; font-size: 22rpx; display: flex; align-items: center; justify-content: center; font-variant-numeric: tabular-nums; padding: 0 4rpx; }
.flash-scroll { white-space: nowrap; padding: 4rpx 28rpx 0; }
.f-item { display: inline-block; width: 232rpx; margin-right: 18rpx; vertical-align: top; }
.f-img { width: 232rpx; height: 232rpx; border-radius: 20rpx; display: block; }
.f-name { font-size: 24rpx; font-weight: 600; margin-top: 14rpx; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; display: block; }
.f-price { display: flex; align-items: baseline; gap: 10rpx; margin-top: 6rpx; }
.f-price .now { font-size: 30rpx; font-weight: 800; color: #FF3B30; }
.f-price .old { font-size: 20rpx; color: #AEB3BB; text-decoration: line-through; }
.f-bar { height: 24rpx; border-radius: 12rpx; background: #FFE9D9; margin-top: 12rpx; position: relative; overflow: hidden; }
.f-bar-inner { position: absolute; left: 0; top: 0; bottom: 0; border-radius: 12rpx; background: linear-gradient(135deg, #FF9A3D, #F2620A); }
.f-bar-text { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; font-size: 16rpx; color: #fff; font-weight: 700; letter-spacing: 1rpx; text-shadow: 0 1rpx 2rpx rgba(150,60,0,0.45); }
.rank { background: #fff; border-radius: 32rpx; margin: 20rpx 24rpx 0; padding-bottom: 16rpx; box-shadow: 0 1rpx 2rpx rgba(23,26,23,0.03), 0 12rpx 40rpx rgba(23,26,23,0.05); }
.r-row { display: flex; align-items: center; gap: 20rpx; padding: 16rpx 28rpx; }
.r-no { width: 44rpx; height: 44rpx; border-radius: 14rpx; display: flex; align-items: center; justify-content: center; font-size: 24rpx; font-weight: 800; color: #AEB3BB; background: #F2F4F3; flex: none; }
.r-no-1 { background: linear-gradient(135deg, #FFD666, #F5A623); color: #7A4A00; }
.r-no-2 { background: linear-gradient(135deg, #E8ECF1, #B9C2CC); color: #4A5568; }
.r-no-3 { background: linear-gradient(135deg, #F5C09A, #D98E5F); color: #6B3A1A; }
.r-img { width: 104rpx; height: 104rpx; border-radius: 18rpx; flex: none; display: block; }
.r-main { flex: 1; min-width: 0; }
.r-name { font-size: 26rpx; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; display: block; }
.r-meta { font-size: 22rpx; color: #AEB3BB; margin-top: 6rpx; display: block; }
.r-pts { font-size: 30rpx; font-weight: 800; color: #FF3B30; flex: none; }
.goods { display: grid; grid-template-columns: 1fr 1fr; gap: 18rpx; padding: 20rpx 24rpx 0; }
.g-card { background: #fff; border-radius: 24rpx; overflow: hidden; box-shadow: 0 1rpx 2rpx rgba(23,26,23,0.03), 0 12rpx 40rpx rgba(23,26,23,0.05); }
.g-cover { width: 100%; aspect-ratio: 1; display: block; }
.g-body { padding: 18rpx 20rpx 22rpx; }
.g-name { font-size: 26rpx; font-weight: 600; line-height: 1.35; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; min-height: 70rpx; }
.g-tags { margin-top: 10rpx; }
.g-foot { display: flex; align-items: baseline; justify-content: space-between; margin-top: 12rpx; }
.g-price { font-size: 32rpx; font-weight: 800; color: #FF3B30; }
.g-price small { font-size: 20rpx; font-weight: 500; }
.g-sold { font-size: 20rpx; color: #AEB3BB; }
.tag { display: inline-flex; align-items: center; height: 36rpx; padding: 0 12rpx; border-radius: 10rpx; font-size: 20rpx; font-weight: 500; background: #F2F4F3; color: #767C85; margin-right: 8rpx; }
.tag-red { background: #FFEEEA; color: #FF3B30; }
.tag-orange { background: #FFF2E5; color: #F2620A; }
.tag-green { background: #E7F9EF; color: #08A152; }
.more { font-size: 24rpx; color: #AEB3BB; display: flex; align-items: center; gap: 4rpx; }
.arrow { font-size: 28rpx; }
.placeholder { display: flex; align-items: center; justify-content: center; background: linear-gradient(145deg, #E7F9EF, #EAF2FF); color: #0BBF63; font-size: 56rpx; font-weight: 900; }
.load-error { margin: 25rpx 24rpx; padding: 50rpx 30rpx; text-align: center; border: 1rpx solid #ffc9c1; border-radius: 24rpx; background: #fff7f5; color: #b42318; }
.load-error text { display: block; }
.load-error button { height: 68rpx; margin: 22rpx auto 0; padding: 0 28rpx; border: 1rpx solid #e7a79f; border-radius: 16rpx; background: #fff; color: #b42318; line-height: 66rpx; }
.load-error button::after { border: 0; }
.empty { margin: 24rpx; padding: 78rpx 30rpx; text-align: center; border: 1rpx solid #EDEFEF; border-radius: 28rpx; background: rgba(255,255,255,0.9); color: #AEB3BB; }
.empty-title { margin-bottom: 10rpx; color: #191B1F; font-size: 28rpx; font-weight: 900; display: block; }
.empty text { display: block; }
</style>
