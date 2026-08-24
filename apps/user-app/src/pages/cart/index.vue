<template>
  <view class="page">
    <view class="commerce-hero" aria-label="购物车结算引导">
      <image class="commerce-hero-art" src="/static/home/hero-campus-reward.png" mode="aspectFill" />
      <view class="commerce-hero-copy">
        <text class="commerce-hero-kicker">CHECKOUT BAG</text>
        <text class="commerce-hero-title">购物车</text>
        <text class="commerce-hero-sub">先确认商品与购买方式，再锁定库存、价格和现金运费</text>
      </view>
    </view>
    <view class="heading"><text class="eyebrow">CHECKOUT BAG</text><text class="title">购物车</text><text class="sub">先确认商品与购买方式，结算时再锁定库存、价格和现金运费</text></view>
    <view v-if="loading && !cart.items.length" class="state-card"><text class="state-title">正在加载购物车</text><text class="state-copy">正在同步商品、地址和支付能力…</text></view>
    <view v-else-if="loadError" class="state-card error-state"><text class="state-title">购物车暂时无法加载</text><text class="state-copy">{{ loadError }}</text><button class="state-action" @click="load">重新加载</button></view>
    <view class="flow-strip"><view class="flow active"><text>1</text><text>确认商品</text></view><view class="flow-line"/><view class="flow"><text>2</text><text>结算预览</text></view><view class="flow-line"/><view class="flow"><text>3</text><text>完成支付</text></view></view>
    <view v-for="item in cart.items" :key="item.id" class="card" :class="{ invalid: !item.valid }">
      <view class="row"><text class="name">{{ item.product_name }}</text><switch :checked="item.selected" color="#2F67DF" @change="toggle(item, $event)" /></view>
      <text class="meta">{{ item.sku_name }} · {{ modeText(item.purchase_mode) }}</text>
      <text class="price">{{ priceText(item) }}</text>
      <text v-if="!item.valid" class="warning">当前不可结算：{{ reasonText(item.invalid_reason) }}</text>
      <view class="row actions"><view><button class="qty" @click="changeQuantity(item, -1)">−</button><text class="count">{{ item.quantity }}</text><button class="qty" @click="changeQuantity(item, 1)">+</button></view><button class="delete" @click="remove(item)">删除</button></view>
    </view>
    <view v-if="cart.items.length" class="quote-card">
      <picker :range="addressLabels" @change="selectAddress"><view class="address">{{ selectedAddress ? `配送到：${selectedAddress.province}${selectedAddress.city}${selectedAddress.district}` : '选择收货地址试算运费' }}</view></picker>
      <text v-if="quote" class="shipping">预估现金运费 ¥{{ (quote.shipping_cash_amount / 100).toFixed(2) }}</text>
      <view v-if="quoteError" class="quote-error"><text>{{ quoteError }}</text><button class="retry-link" @click="loadQuote">重新试算</button></view>
      <text class="shipping-tip">运费不参与积分和优惠券抵扣，始终以现金结算</text>
      <picker v-if="paymentLabels.length > 1" :range="paymentLabels" :value="paymentIndex" @change="selectPayment"><view class="address payment">现金部分：{{ paymentLabels[paymentIndex] }} ›</view></picker>
      <view v-else-if="paymentLabels.length === 1" class="address payment">现金部分：{{ paymentLabels[0] }}</view>
      <view v-else-if="requiresCashPayment" class="payment-unavailable"><text>暂无可用现金支付方式</text><small>{{ paymentUnavailableText }}</small></view>
      <button class="checkout" :disabled="!canCheckout" @click="openCheckout">去结算（{{ cart.summary.selected_quantity }}）</button>
    </view>
    <view v-if="preview" class="preview-card">
      <view class="row"><text class="preview-title">将拆为 {{ preview.groups.length }} 个订单</text><text class="expires">5 分钟内有效</text></view>
      <view v-for="group in preview.groups" :key="group.group_key" class="group">
        <text>{{ fulfillmentText(group.fulfillment_type) }}</text><text>{{ amountText(group.payable_cash_amount, group.payable_points) }}</text>
      </view>
      <view class="row total"><text>合计</text><text>{{ amountText(preview.payable_cash_amount, preview.payable_points) }}</text></view>
      <text v-if="preview.shipping_amount" class="shipping">其中现金运费 ¥{{ (preview.shipping_amount / 100).toFixed(2) }}</text>
      <button class="confirm" :loading="submitting" :disabled="submitting" @click="createOrder">确认创建订单</button>
    </view>
    <view v-if="!loading && !loadError && !cart.items.length" class="empty-card"><view class="empty-icon">袋</view><text class="empty-title">购物车还是空的</text><text class="empty-copy">去商城看看支持现金、积分和组合购买的精选商品</text><button class="empty-action" @click="openProducts">去逛商城</button></view>
    <view v-if="!loading && !loadError && !cart.items.length" class="empty-rules" aria-label="空购物车结算规则">
      <view class="empty-rules-head"><text>结算规则</text><text>下单前会再次校验</text></view>
      <view class="empty-rule"><text class="empty-rule-mark">价</text><view><text>价格与库存</text><small>以提交结算时的最新状态为准</small></view></view>
      <view class="empty-rule"><text class="empty-rule-mark cash">运</text><view><text>现金运费</text><small>按收货地址实时试算，不支持积分抵扣</small></view></view>
      <view class="empty-rule"><text class="empty-rule-mark points">兑</text><view><text>积分与现金</text><small>按商品配置组合使用，订单拆分后分别支付</small></view></view>
    </view>
    <view v-if="!loading && !loadError && !cart.items.length" class="empty-total-bar" aria-disabled="true">
      <view><text class="empty-total-label">合计</text><text class="empty-total-note">选择商品后显示应付金额</text></view>
      <text class="empty-total-amount">¥0.00</text>
      <button class="empty-total-button" disabled>去结算</button>
    </view>
  </view>
</template>

<script setup lang="ts">
import { onShow } from '@dcloudio/uni-app'
import { computed, ref } from 'vue'
import { deleteCartItem, getCart, quoteShipping, updateCartItem, type Cart, type CartItem, type ShippingQuote } from '@/api/cart'
import { listAddresses, type UserAddress } from '@/api/account'
import { createOrders, previewCheckout, type CashPaymentMethod, type CheckoutInput, type CheckoutPreview, type FulfillmentType } from '@/api/orders'
import { loadClientCapabilities, type ClientCapabilities } from '@/platform/capabilities'
import { useAuthStore } from '@/stores/auth'
import { saveCheckoutDraft } from '@/utils/checkoutDraft'
import { navigateToLogin } from '@/utils/authRedirect'

const auth = useAuthStore(); const cart = ref<Cart>({ items: [], summary: { item_count: 0, selected_quantity: 0, all_valid: true } }); const addresses = ref<UserAddress[]>([]); const selectedAddress = ref<UserAddress | null>(null); const quote = ref<ShippingQuote | null>(null)
const preview = ref<CheckoutPreview | null>(null); const previewInput = ref<CheckoutInput | null>(null); const submitting = ref(false); const paymentIndex = ref(0); const loading = ref(false); const loadError = ref(''); const quoteError = ref(''); const createOrderKey = ref('')
const capabilities = ref<ClientCapabilities | null>(null)
const addressLabels = computed(() => addresses.value.map((item) => `${item.recipient_name} · ${item.province}${item.city}${item.district}`))
const paymentOptions = computed(() => (capabilities.value?.payment_methods || []).filter((item) => item.enabled))
const paymentLabels = computed(() => paymentOptions.value.map((item) => item.label))
const selectedPaymentMethod = computed<CashPaymentMethod>(() => paymentOptions.value[paymentIndex.value]?.code || 'wechat')
const selectedItems = computed(() => cart.value.items.filter((item) => item.selected && item.valid))
const requiresCashPayment = computed(() => selectedItems.value.some((item) => (item.unit_cash_amount || 0) > 0 || item.fulfillment_type === 'logistics'))
const paymentUnavailableText = computed(() => capabilities.value?.payment_unavailable_message || '支付能力正在加载，请稍后重试。')
const canCheckout = computed(() => { const selected = cart.value.items.filter((item) => item.selected); const hasLogistics = selected.some((item) => item.fulfillment_type === 'logistics'); return selected.length > 0 && selected.every((item) => item.valid) && (!hasLogistics || !!selectedAddress.value) && (!hasLogistics || !!quote.value) && !quoteError.value && (!requiresCashPayment.value || paymentOptions.value.length > 0) })
onShow(async () => { if (!auth.accessToken) return navigateToLogin(); await load() })
async function load() {
  if (!auth.accessToken) return
  loading.value = true
  loadError.value = ''
  try {
    const [nextCart, nextAddresses, nextCapabilities] = await Promise.all([getCart(auth.accessToken), listAddresses(auth.accessToken), loadClientCapabilities()])
    cart.value = nextCart
    addresses.value = nextAddresses
    capabilities.value = nextCapabilities
    paymentIndex.value = 0
    selectedAddress.value = addresses.value.find((item) => item.is_default) || addresses.value[0] || null
    await loadQuote()
  } catch (error) {
    quote.value = null
    loadError.value = error instanceof Error ? error.message : '请稍后重试。'
  } finally { loading.value = false }
}
async function toggle(item: CartItem, event: Event) {
  if (!auth.accessToken || loading.value) return
  const previous = cart.value
  invalidatePreview()
  const selected = (event as CustomEvent<{ value: boolean }>).detail.value
  try { cart.value = await updateCartItem(auth.accessToken, item.id, { selected }); await loadQuote() }
  catch (error) { cart.value = previous; await loadQuote(); uni.showToast({ title: error instanceof Error ? error.message : '更新购物车失败，请重试', icon: 'none' }) }
}
async function changeQuantity(item: CartItem, delta: number) {
  if (!auth.accessToken || loading.value) return
  const quantity = item.quantity + delta
  if (quantity < 1) return remove(item)
  const previous = cart.value
  invalidatePreview()
  try { cart.value = await updateCartItem(auth.accessToken, item.id, { quantity }); await loadQuote() }
  catch (error) { cart.value = previous; await loadQuote(); uni.showToast({ title: error instanceof Error ? error.message : '数量更新失败，请重试', icon: 'none' }) }
}
async function remove(item: CartItem) {
  if (!auth.accessToken || loading.value) return
  const previous = cart.value
  invalidatePreview()
  try { await deleteCartItem(auth.accessToken, item.id); await load() }
  catch (error) { cart.value = previous; await loadQuote(); uni.showToast({ title: error instanceof Error ? error.message : '删除失败，请重试', icon: 'none' }) }
}
async function selectAddress(event: { detail: { value: number } }) { invalidatePreview(); selectedAddress.value = addresses.value[Number(event.detail.value)] || null; await loadQuote() }
function selectPayment(event: { detail: { value: number } }) { invalidatePreview(); paymentIndex.value = Number(event.detail.value) }
async function loadQuote() {
  quote.value = null
  quoteError.value = ''
  if (!auth.accessToken || !selectedAddress.value || !cart.value.items.some((item) => item.selected && item.fulfillment_type === 'logistics')) return
  try { quote.value = await quoteShipping(auth.accessToken, selectedAddress.value.id) }
  catch (error) { quoteError.value = error instanceof Error ? error.message : '运费试算失败，请重试'; uni.showToast({ title: quoteError.value, icon: 'none' }) }
}
function buildInput(): CheckoutInput {
  const items = cart.value.items.filter((item) => item.selected && item.valid)
  const paymentMethod = selectedPaymentMethod.value
  const fulfillmentTypes = [...new Set(items.map((item) => item.fulfillment_type as FulfillmentType))]
  return { capability_version: 1, items: items.map((item) => ({ sku_id: item.sku_id, quantity: item.quantity, purchase_mode: item.purchase_mode })), group_options: fulfillmentTypes.map((type) => {
    const group = items.filter((item) => item.fulfillment_type === type)
    const hasCash = group.some((item) => (item.unit_cash_amount || 0) > 0) || type === 'logistics'
    return { fulfillment_type: type, ...(type === 'logistics' && selectedAddress.value ? { address_id: selectedAddress.value.id } : {}), ...(hasCash ? { cash_payment_method: paymentMethod } : {}) }
  }) }
}
async function previewOrder() { if (!auth.accessToken || !canCheckout.value) return; submitting.value = true; try { const input = buildInput(); preview.value = await previewCheckout(auth.accessToken, input); previewInput.value = input; createOrderKey.value = `checkout-${Date.now()}-${Math.random().toString(36).slice(2)}` } catch (error) { uni.showToast({ title: error instanceof Error ? error.message : '结算预览失败', icon: 'none' }) } finally { submitting.value = false } }
async function createOrder() {
  if (!auth.accessToken || !preview.value || !previewInput.value) return
  submitting.value = true
  try {
    const input = { ...previewInput.value, preview_token: preview.value.preview_token }
    const batch = await createOrders(auth.accessToken, input, createOrderKey.value || `checkout-${Date.now()}-${Math.random().toString(36).slice(2)}`)
    const orderIds = batch.orders.map((order) => order.id).filter(Boolean)
    const pendingOrders = batch.orders.filter((order) => order.status === 'pending_payment')
    uni.showToast({ title: pendingOrders.length ? '订单已创建，请继续支付' : '订单创建成功', icon: 'success' })
    setTimeout(() => {
      if (pendingOrders.length && orderIds.length) uni.redirectTo({ url: `/pages/orders/index?ids=${encodeURIComponent(orderIds.join(','))}` })
      else if (orderIds.length) uni.redirectTo({ url: `/pages/payment-result/index?orderIds=${encodeURIComponent(orderIds.join(','))}&status=success&payment=none` })
      else uni.redirectTo({ url: '/pages/orders/index' })
    }, 500)
  } catch (error) { invalidatePreview(); uni.showToast({ title: error instanceof Error ? error.message : '创建订单失败，请重新预览', icon: 'none' }) }
  finally { submitting.value = false }
}
function invalidatePreview() { preview.value = null; previewInput.value = null; createOrderKey.value = '' }
function modeText(mode: string) { return ({ cash: '现金', points: '积分', cash_points: '现金 + 积分' } as Record<string, string>)[mode] || mode }
function priceText(item: CartItem) { const cash = item.unit_cash_amount ? `¥${(item.unit_cash_amount / 100).toFixed(2)}` : ''; const points = item.unit_points ? `${item.unit_points} 积分` : ''; return [cash, points].filter(Boolean).join(' + ') }
function reasonText(reason: string | null) { return ({ product_off_shelf: '商品已下架', sku_inactive: '规格已停用', purchase_mode_unavailable: '购买方式已关闭', insufficient_stock: '库存不足' } as Record<string, string>)[reason || ''] || '规则已变化' }
function fulfillmentText(type: string) { return ({ logistics: '物流配送', no_logistics: '无需物流', virtual_delivery: '虚拟交付' } as Record<string, string>)[type] || type }
function amountText(cash: number, points: number) { return [cash ? `¥${(cash / 100).toFixed(2)}` : '', points ? `${points} 积分` : ''].filter(Boolean).join(' + ') || '无需支付' }
function openProducts() { uni.reLaunch({ url: '/pages/products/index' }) }
function openCheckout() {
  if (!canCheckout.value) return
  saveCheckoutDraft(auth.user?.id, {
    source: 'cart',
    addressId: selectedAddress.value?.id,
    paymentMethod: selectedPaymentMethod.value,
    selectedItems: selectedItems.value.map((item) => ({ skuId: item.sku_id, quantity: item.quantity, purchaseMode: item.purchase_mode })),
  })
  uni.navigateTo({ url: '/pages/checkout/index' })
}
</script>

<style scoped>
.page{min-height:100vh;padding:32rpx 28rpx 90rpx;background:var(--tm-canvas);box-sizing:border-box}.heading{padding:22rpx 8rpx 16rpx}.eyebrow,.title,.sub,.meta,.price,.warning,.shipping,.shipping-tip,.empty-title,.empty-copy,.state-title,.state-copy{display:block}.eyebrow{color:var(--tm-brand-600);font-size:13px;font-weight:800;letter-spacing:2.5rpx}.title{margin-top:10rpx;font-size:24px;font-weight:850}.sub{margin-top:10rpx;color:var(--tm-ink-500);font-size:14px;line-height:1.6}.state-card{margin-top:20rpx;padding:38rpx 28rpx;text-align:center;border:1rpx solid var(--tm-line);border-radius:26rpx;background:#fff}.state-title{font-size:17px;font-weight:850}.state-copy{margin-top:9rpx;color:var(--tm-ink-500);font-size:14px;line-height:1.55}.state-action,.retry-link{height:64rpx;margin:20rpx auto 0;border:0;border-radius:15rpx;background:var(--tm-brand-600);color:#fff;font-size:14px;line-height:64rpx}.state-action::after,.retry-link::after{border:0}.error-state{border-color:#f3b1a5;background:#fff7f5}.quote-error{display:flex;align-items:center;justify-content:space-between;gap:12rpx;margin-top:16rpx;padding:15rpx 17rpx;border-radius:14rpx;background:#fff4f2;color:#b42318;font-size:13px}.retry-link{flex:none;height:48rpx;margin:0;padding:0 14rpx;background:#fff;color:#b42318;border:1rpx solid #e9a99e;line-height:46rpx}.flow-strip{display:flex;align-items:center;margin:12rpx 0 6rpx;padding:22rpx;border:1rpx solid var(--tm-line);border-radius:24rpx;background:#fff}.flow{display:flex;align-items:center;gap:8rpx;color:var(--tm-ink-500);font-size:13px}.flow text:first-child{display:flex;align-items:center;justify-content:center;width:34rpx;height:34rpx;border-radius:50%;background:#edf0f5}.flow.active{color:var(--tm-brand-700);font-weight:750}.flow.active text:first-child{background:var(--tm-brand-600);color:#fff}.flow-line{height:1rpx;flex:1;margin:0 10rpx;background:var(--tm-line)}.card,.quote-card,.preview-card{margin-top:20rpx;padding:28rpx;border:1rpx solid var(--tm-line);border-radius:26rpx;background:#fff;box-shadow:var(--tm-shadow-sm)}.card.invalid{border-color:#fdb7a0}.row{display:flex;justify-content:space-between;align-items:center}.name{font-size:17px;font-weight:800}.meta{margin-top:10rpx;color:var(--tm-ink-500);font-size:14px}.price{margin-top:14rpx;color:var(--tm-reward-600);font-size:17px;font-weight:850}.warning{margin-top:12rpx;color:#d92d20;font-size:14px}.actions{margin-top:22rpx}.qty,.delete{display:inline-block;margin:0;padding:0;border:0;border-radius:14rpx;background:#f2f4f7;font-size:15px}.qty{width:56rpx;height:56rpx;line-height:56rpx}.count{display:inline-block;width:64rpx;text-align:center}.delete{padding:0 22rpx;height:56rpx;line-height:56rpx;color:#d92d20}.qty::after,.delete::after{border:0}.address{padding:21rpx;border-radius:16rpx;background:#f6f8fc;color:var(--tm-ink-700)}.payment{margin-top:14rpx}.payment-unavailable{margin-top:14rpx;padding:20rpx;border:1rpx solid #f2b8ae;border-radius:16rpx;background:#fff4f2;color:#b42318}.payment-unavailable text,.payment-unavailable small{display:block}.payment-unavailable text{font-size:14px;font-weight:850}.payment-unavailable small{margin-top:7rpx;font-size:13px;line-height:1.5}.shipping{margin-top:18rpx;color:var(--tm-reward-600);font-weight:800}.shipping-tip{margin-top:7rpx;color:var(--tm-ink-500);font-size:13px}.checkout,.confirm{height:80rpx;margin-top:22rpx;border-radius:18rpx;background:var(--tm-brand-600);color:#fff;font-weight:800;line-height:80rpx}.preview-title{font-weight:800}.expires{color:var(--tm-ink-500);font-size:14px}.group{display:flex;justify-content:space-between;margin-top:18rpx;padding:18rpx;background:#f6f8fc;border-radius:14rpx}.total{margin-top:22rpx;font-weight:800}.empty-card{margin-top:34rpx;padding:66rpx 34rpx;text-align:center;border:1rpx solid var(--tm-line);border-radius:28rpx;background:#fff}.empty-icon{display:flex;align-items:center;justify-content:center;width:88rpx;height:88rpx;margin:0 auto;border-radius:28rpx;background:var(--tm-brand-100);color:var(--tm-brand-700);font-size:19px;font-weight:850}.empty-title{margin-top:22rpx;font-size:17px;font-weight:800}.empty-copy{margin-top:10rpx;color:var(--tm-ink-500);font-size:14px;line-height:1.6}.empty-action{width:260rpx;height:72rpx;margin-top:24rpx;border-radius:18rpx;background:var(--tm-brand-600);color:#fff;font-size:14px;line-height:72rpx}
/* V09 campus commerce surface */
.commerce-hero{position:relative;min-height:250rpx;overflow:hidden;margin-bottom:18rpx;border:1rpx solid var(--tm-line);border-radius:var(--tm-radius-lg);background:linear-gradient(135deg,#e7fff1 0,#dff8fb 58%,#eef6ff 100%);box-shadow:var(--tm-shadow-sm)}
.commerce-hero-art{position:absolute;right:-16rpx;top:0;width:68%;height:100%;opacity:.88;object-position:right center}
.commerce-hero::after{content:'';position:absolute;inset:0;background:linear-gradient(90deg,rgba(231,255,241,.98) 0,rgba(231,255,241,.9) 34%,rgba(231,255,241,.12) 78%,rgba(231,255,241,0) 100%)}
.commerce-hero-copy{position:relative;z-index:1;width:57%;padding:28rpx 24rpx}.commerce-hero-kicker,.commerce-hero-title,.commerce-hero-sub{display:block}.commerce-hero-kicker{color:var(--tm-brand-700);font-size:13px;font-weight:850;letter-spacing:2.4rpx}.commerce-hero-title{margin-top:10rpx;color:var(--tm-ink-900);font-size:22px;font-weight:950;line-height:1.2}.commerce-hero-sub{margin-top:10rpx;color:var(--tm-ink-500);font-size:13px;line-height:1.55}
.page > .heading{display:none}
.page{background:linear-gradient(180deg,var(--tm-campus-cyan-soft) 0,var(--tm-canvas) 560rpx);color:var(--tm-ink-900)}
.heading{margin-bottom:18rpx;padding:26rpx 24rpx;border:1rpx solid var(--tm-line);border-radius:var(--tm-radius-lg);background:var(--tm-surface);box-shadow:var(--tm-shadow-sm)}
.eyebrow,.title{color:var(--tm-ink-900)}.sub{color:var(--tm-ink-500);font-weight:500}
.state-card,.flow-strip,.card,.quote-card,.preview-card,.empty-card{border:1rpx solid var(--tm-line);border-radius:var(--tm-radius-lg);box-shadow:var(--tm-shadow-sm);background:var(--tm-surface)}
.flow-strip{background:var(--tm-surface)}.flow.active text:first-child{border:0;background:var(--tm-brand-600);color:#fff}
.card.invalid{border-color:rgba(209,67,67,.34);background:var(--tm-campus-coral-soft)}
.qty,.delete,.address,.group{border:1rpx solid var(--tm-line);background:var(--tm-surface)}
.qty:active,.delete:active{transform:scale(.96)}
.quote-card{background:var(--tm-surface)}.preview-card{background:var(--tm-reward-100)}
.checkout,.confirm,.empty-action,.state-action{border:1rpx solid transparent;box-shadow:none}
.empty-icon{border:0;background:var(--tm-brand-100);color:var(--tm-brand-700)}
.payment-unavailable,.quote-error,.error-state{border-color:rgba(209,67,67,.22);background:var(--tm-campus-coral-soft)}
.card button[disabled],.checkout[disabled],.confirm[disabled]{opacity:.46;filter:saturate(.55)}
.flow-strip,.quote-card,.preview-card{padding-top:22rpx;padding-bottom:22rpx}
.empty-rules{margin-top:16rpx;padding:24rpx;border:1rpx solid var(--tm-line);border-radius:var(--tm-radius-lg);background:var(--tm-surface-soft)}.empty-rules-head{display:flex;align-items:center;justify-content:space-between;gap:14rpx}.empty-rules-head text:first-child{color:var(--tm-ink-900);font-size:15px;font-weight:850}.empty-rules-head text:last-child{color:var(--tm-ink-500);font-size:13px}.empty-rule{display:flex;align-items:center;gap:13rpx;margin-top:16rpx;padding-top:16rpx;border-top:1rpx solid var(--tm-line)}.empty-rule-mark{display:flex;align-items:center;justify-content:center;width:48rpx;height:48rpx;flex:none;border-radius:15rpx;background:var(--tm-brand-100);color:var(--tm-brand-700);font-size:13px;font-weight:850}.empty-rule-mark.cash{background:var(--tm-reward-100);color:var(--tm-reward-600)}.empty-rule-mark.points{background:var(--tm-growth-100);color:var(--tm-growth-700)}.empty-rule view{min-width:0}.empty-rule view>text,.empty-rule small{display:block}.empty-rule view>text{color:var(--tm-ink-700);font-size:14px;font-weight:750}.empty-rule small{margin-top:4rpx;color:var(--tm-ink-500);font-size:13px;line-height:1.45}.empty-total-bar{display:flex;align-items:center;gap:14rpx;margin-top:16rpx;padding:19rpx 20rpx;border:1rpx solid var(--tm-line);border-radius:22rpx;background:var(--tm-surface);box-sizing:border-box}.empty-total-bar>view{min-width:0;flex:1}.empty-total-label,.empty-total-note{display:block}.empty-total-label{color:var(--tm-ink-700);font-size:14px;font-weight:800}.empty-total-note{margin-top:3rpx;color:var(--tm-ink-500);font-size:12px}.empty-total-amount{color:var(--tm-ink-500);font-size:15px;font-weight:850}.empty-total-button{width:148rpx;height:58rpx;margin:0;padding:0;border:0;border-radius:15rpx;background:#dfe7e8;color:#93a0a4;font-size:13px;line-height:58rpx}.empty-total-button::after{border:0}.empty-total-button[disabled]{opacity:1}
.empty-card{padding:72rpx 34rpx 54rpx}.empty-icon{position:relative;width:154rpx;height:118rpx;margin:0 auto;border-radius:34rpx;background:linear-gradient(145deg,#e2f6f8,#edf7ff);font-size:0}.empty-icon::before{content:'';position:absolute;left:28rpx;top:28rpx;width:88rpx;height:56rpx;border:5rpx solid #4bb7cd;border-radius:11rpx 11rpx 18rpx 18rpx;background:#fff;box-sizing:border-box}.empty-icon::after{content:'⌕';position:absolute;right:8rpx;bottom:4rpx;display:flex;align-items:center;justify-content:center;width:48rpx;height:48rpx;border:5rpx solid #1268c9;border-radius:50%;background:#fff;color:#1268c9;font-size:21px;font-weight:900;box-sizing:border-box}
@media (prefers-reduced-motion: reduce){.qty:active,.delete:active{transform:none}}
</style>
