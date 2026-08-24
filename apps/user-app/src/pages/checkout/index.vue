<template>
  <view class="page tm-page-shell">
    <view class="commerce-hero" aria-label="结算预览">
      <image class="commerce-hero-art" src="/static/home/hero-campus-reward.png" mode="aspectFill" />
      <view class="commerce-hero-copy">
        <text class="commerce-hero-kicker">CHECKOUT PREVIEW</text>
        <text class="commerce-hero-title">结算预览</text>
        <text class="commerce-hero-sub">确认商品、优惠与现金运费后，再选择一种全额支付方式</text>
      </view>
    </view>

    <view class="flow-strip tm-panel">
      <view class="flow done"><text>✓</text><small>确认商品</small></view><view class="line" />
      <view class="flow active"><text>2</text><small>结算预览</small></view><view class="line" />
      <view class="flow"><text>3</text><small>完成支付</small></view>
    </view>

    <view v-if="loading && !selectedItems.length" class="empty-state tm-panel"><view class="empty-mark">…</view><text class="empty-title">正在准备结算</text><text class="empty-copy">正在校验商品、库存和运费，请稍候。</text></view>
    <view v-else-if="loadError" class="empty-state error-state tm-panel"><view class="empty-mark">!</view><text class="empty-title">结算信息加载失败</text><text class="empty-copy">{{ loadError }}</text><button class="empty-button" @click="load">重新加载</button></view>
    <view v-else-if="!cart.items.length || !selectedItems.length" class="empty-state tm-panel">
      <view class="empty-mark">袋</view>
      <text class="empty-title">{{ cart.items.length ? '没有可结算的商品' : '购物车还是空的' }}</text>
      <text class="empty-copy">{{ cart.items.length ? '请返回购物车选择有效商品，或调整已下架/库存不足的商品。' : '去商城挑选支持现金、积分和组合购买的校园好物。' }}</text>
      <button class="empty-button" @click="openCart">返回购物车</button>
    </view>

    <view v-for="item in selectedItems" :key="item.id" class="item-card tm-panel">
      <view class="item-mark">{{ item.product_name.slice(0, 1) }}</view>
      <view class="item-main"><text class="item-name">{{ item.product_name }}</text><text class="item-meta">{{ item.sku_name }} · {{ modeText(item.purchase_mode) }}</text><view class="item-foot"><text class="tm-money">{{ priceText(item) }}</text><text>× {{ item.quantity }}</text></view></view>
    </view>

    <view class="checkout-card tm-panel">
      <picker :range="addressLabels" @change="selectAddress"><view class="setting-row"><text>收货地址</text><text>{{ addressText }} ›</text></view></picker>
      <view v-if="paymentLabels.length" class="payment-section"><view class="payment-heading"><text>现金支付方式</text><text>请选择一种全额支付</text></view><view v-for="(option, index) in paymentOptions" :key="option.code" class="payment-choice" :class="{ selected: paymentIndex === index, insufficient: option.code === 'wallet' && walletInsufficient }" @click="choosePayment(index)"><view class="payment-mark">{{ option.code === 'wallet' ? '余' : '微' }}</view><view class="payment-copy"><text>{{ option.label }}</text><small v-if="option.code === 'wallet'">可用 ¥{{ wallet ? money(wallet.available_amount) : '—' }}{{ walletInsufficient ? ' · 余额不足' : '' }}</small><small v-else>使用微信支付现金与运费</small></view><text class="payment-check">{{ paymentIndex === index ? '✓' : '' }}</text></view><view v-if="selectedPaymentMethod === 'wallet' && walletInsufficient" class="recharge-link" @click="openRecharge">当前余额不足，先去充值 ›</view></view>
      <view v-else-if="requiresCashPayment" class="payment-unavailable"><text>暂无可用现金支付方式</text><small>{{ paymentUnavailableText }}</small></view>
      <view class="divider" />
      <view class="amount-row"><text>商品现金</text><text>¥{{ money(itemsCashAmount) }}</text></view>
      <view class="amount-row"><text>商品积分</text><text>{{ itemsPoints.toLocaleString() }} 积分</text></view>
      <view v-if="preview?.cash_discount_amount" class="amount-row discount"><text>优惠券抵扣现金</text><text>-¥{{ money(preview.cash_discount_amount) }}</text></view>
      <view v-if="preview?.points_discount" class="amount-row discount"><text>优惠券抵扣积分</text><text>-{{ preview.points_discount.toLocaleString() }} 积分</text></view>
      <view class="amount-row"><text>现金运费</text><text>{{ quoteError ? '试算失败' : `¥${money(preview?.shipping_amount || quote?.shipping_cash_amount || 0)}` }}</text></view>
      <view class="amount-row total"><text>应付合计</text><text class="tm-money">{{ preview ? amountText(preview.payable_cash_amount, preview.payable_points) : amountText(itemsCashAmount + (quote?.shipping_cash_amount || 0), itemsPoints) }}</text></view>
    </view>

    <view v-if="preview" class="coupon-section tm-panel">
      <view class="coupon-heading"><view><text class="coupon-title">按拆单选择优惠券</text><text class="coupon-tip">每张券只能用于一个交付拆单，运费不参与抵扣</text></view><text v-if="couponSelectionDirty" class="dirty-tag">待重新预览</text></view>
      <view v-for="group in preview.groups" :key="group.group_key" class="coupon-group">
        <view class="group-heading"><text>{{ fulfillmentText(group.fulfillment_type) }}</text><text>{{ groupCouponSummary(group) }}</text></view>
        <view class="coupon-choice" :class="{ selected: !selectedCoupons[group.fulfillment_type] }" @click="chooseCoupon(group.fulfillment_type, null)">
          <view><text class="coupon-name">不使用优惠券</text><text class="coupon-detail">按原价结算该拆单</text></view><text class="select-mark">{{ !selectedCoupons[group.fulfillment_type] ? '✓' : '' }}</text>
        </view>
        <view v-for="coupon in group.coupon_options" :key="coupon.id" class="coupon-choice" :class="{ selected: selectedCoupons[group.fulfillment_type] === coupon.id, disabled: !coupon.eligible || usedByOtherGroup(coupon.id, group.fulfillment_type) }" @click="chooseCoupon(group.fulfillment_type, coupon)">
          <view class="coupon-copy"><text class="coupon-name">{{ coupon.name }}</text><text class="coupon-detail">{{ couponValueText(coupon) }} · {{ couponThresholdText(coupon) }}</text><text v-if="coupon.eligible && !usedByOtherGroup(coupon.id, group.fulfillment_type)" class="coupon-saving">预计抵扣 {{ couponEstimatedText(coupon) }}</text><text v-else class="coupon-reason">{{ usedByOtherGroup(coupon.id, group.fulfillment_type) ? '已用于其他拆单' : coupon.unavailable_reason }}</text></view>
          <text class="select-mark">{{ selectedCoupons[group.fulfillment_type] === coupon.id ? '✓' : '' }}</text>
        </view>
        <view v-if="!group.coupon_options.length" class="empty-coupons">账户暂无可用于结算的优惠券</view>
      </view>
    </view>

    <view class="rule-note">本单现金部分仅可选择微信或余额全额支付，两者不可组合；运费始终使用现金。</view>
    <view v-if="quoteError" class="quote-error"><text>运费试算失败，暂不可提交订单</text><button @click="loadQuote">重新试算</button></view>
    <button class="tm-primary-button submit" :loading="submitting" :disabled="submitting || !canCheckout" @click="submit">{{ submitText }}</button>
    <button class="cancel-checkout" :disabled="submitting" @click="openCart">取消结算并返回购物车</button>
  </view>
</template>

<script setup lang="ts">
import { onLoad, onShow } from '@dcloudio/uni-app'
import { computed, ref } from 'vue'
import { getCart, quoteShipping, type Cart, type CartItem, type ShippingQuote } from '@/api/cart'
import { listAddresses, type UserAddress } from '@/api/account'
import { createOrders, previewCheckout, type CashPaymentMethod, type CheckoutCouponOption, type CheckoutGroup, type CheckoutInput, type CheckoutPreview, type FulfillmentType } from '@/api/orders'
import { getProduct } from '@/api/products'
import { loadClientCapabilities, type ClientCapabilities } from '@/platform/capabilities'
import { getWalletAccount, type WalletAccount } from '@/api/assets'
import { useAuthStore } from '@/stores/auth'
import { navigateToLogin } from '@/utils/authRedirect'
import { clearCheckoutDraft, readCheckoutDraft, saveCheckoutDraft, type CheckoutDraft, type CheckoutDraftSource } from '@/utils/checkoutDraft'

const auth = useAuthStore()
const cart = ref<Cart>({ items: [], summary: { item_count: 0, selected_quantity: 0, all_valid: true } })
const addresses = ref<UserAddress[]>([])
const selectedAddress = ref<UserAddress | null>(null)
const quote = ref<ShippingQuote | null>(null)
const preview = ref<CheckoutPreview | null>(null)
const previewInput = ref<CheckoutInput | null>(null)
const selectedCoupons = ref<Partial<Record<FulfillmentType, string>>>({})
const paymentIndex = ref(0)
const submitting = ref(false)
const capabilities = ref<ClientCapabilities | null>(null)
const loading = ref(false)
const loadError = ref('')
const quoteError = ref('')
const createOrderKey = ref('')
const directBuy = ref<{ productId: string; skuId: string; purchaseMode: 'cash' | 'points' | 'cash_points' } | null>(null)
const wallet = ref<WalletAccount | null>(null)
const initialized = ref(false)
const refreshAfterRecharge = ref(false)
const paymentOptions = computed(() => (capabilities.value?.payment_methods || []).filter((item) => item.enabled))
const paymentLabels = computed(() => paymentOptions.value.map((item) => item.label))
const selectedPaymentMethod = computed<CashPaymentMethod>(() => paymentOptions.value[paymentIndex.value]?.code || 'wechat')
const selectedItems = computed(() => cart.value.items.filter((item) => item.selected && item.valid))
const hasLogistics = computed(() => selectedItems.value.some((item) => item.fulfillment_type === 'logistics'))
const addressLabels = computed(() => addresses.value.map((item) => `${item.recipient_name} · ${item.province}${item.city}${item.district}${item.detail}`))
const addressText = computed(() => selectedAddress.value ? `${selectedAddress.value.province}${selectedAddress.value.city}${selectedAddress.value.district}` : (hasLogistics.value ? '请选择' : '无需地址'))
const itemsCashAmount = computed(() => selectedItems.value.reduce((sum, item) => sum + (item.unit_cash_amount || 0) * item.quantity, 0))
const itemsPoints = computed(() => selectedItems.value.reduce((sum, item) => sum + (item.unit_points || 0) * item.quantity, 0))
const requiresCashPayment = computed(() => itemsCashAmount.value > 0 || hasLogistics.value)
const currentPayableCash = computed(() => preview.value?.payable_cash_amount ?? (itemsCashAmount.value + (quote.value?.shipping_cash_amount || 0)))
const walletInsufficient = computed(() => currentPayableCash.value > (wallet.value?.available_amount || 0))
const walletSelectionBlocked = computed(() => !!preview.value && selectedPaymentMethod.value === 'wallet' && walletInsufficient.value)
const paymentUnavailableText = computed(() => capabilities.value?.payment_unavailable_message || '支付能力正在加载，请稍后重试。')
const canCheckout = computed(() => selectedItems.value.length > 0 && (!hasLogistics.value || !!selectedAddress.value) && (!hasLogistics.value || !!quote.value) && !quoteError.value && (!requiresCashPayment.value || paymentOptions.value.length > 0) && !walletSelectionBlocked.value)
const couponSelectionDirty = computed(() => !!preview.value?.groups.some((group) => (selectedCoupons.value[group.fulfillment_type] || null) !== group.selected_coupon_id))
const submitText = computed(() => walletSelectionBlocked.value ? '余额不足，请先充值' : !preview.value ? '校验价格并查看优惠券' : couponSelectionDirty.value ? '重新预览优惠与应付金额' : '提交订单并支付')

onLoad((query) => {
  const productId = typeof query?.productId === 'string' ? query.productId : ''
  const skuId = typeof query?.skuId === 'string' ? query.skuId : ''
  const purchaseMode = query?.purchaseMode
  if (query?.directBuy === '1' && productId && skuId && (purchaseMode === 'cash' || purchaseMode === 'points' || purchaseMode === 'cash_points')) directBuy.value = { productId, skuId, purchaseMode }
})
onShow(async () => {
  if (!auth.accessToken) return navigateToLogin()
  if (!initialized.value) return load()
  if (refreshAfterRecharge.value) await refreshCheckoutAfterRecharge()
})
function checkoutSource(): CheckoutDraftSource { return directBuy.value ? 'direct_buy' : 'cart' }
function isCurrentDirectBuyDraft(draft: CheckoutDraft | null) {
  if (!draft || !directBuy.value) return draft
  const saved = draft.directBuy
  return saved && saved.productId === directBuy.value.productId && saved.skuId === directBuy.value.skuId && saved.purchaseMode === directBuy.value.purchaseMode ? draft : null
}
function restoreCartSelection(draft: CheckoutDraft | null, nextCart: Cart) {
  if (!draft) return
  const selectedKeys = new Set(draft.selectedItems.map((item) => `${item.skuId}:${item.purchaseMode}`))
  nextCart.items = nextCart.items.map((item) => ({ ...item, selected: selectedKeys.has(`${item.sku_id}:${item.purchase_mode}`) }))
  nextCart.summary.selected_quantity = nextCart.items.filter((item) => item.selected).reduce((sum, item) => sum + item.quantity, 0)
}
function paymentIndexFor(method?: CashPaymentMethod) {
  const index = method ? paymentOptions.value.findIndex((option) => option.code === method) : -1
  return index >= 0 ? index : 0
}
function persistDraft() {
  if (!auth.user?.id || !selectedItems.value.length) return
  saveCheckoutDraft(auth.user.id, {
    source: checkoutSource(),
    addressId: selectedAddress.value?.id,
    paymentMethod: requiresCashPayment.value ? selectedPaymentMethod.value : undefined,
    selectedItems: selectedItems.value.map((item) => ({ skuId: item.sku_id, quantity: item.quantity, purchaseMode: item.purchase_mode })),
    selectedCoupons: selectedCoupons.value,
    directBuy: directBuy.value ? { ...directBuy.value } : undefined,
  })
}
async function refreshCheckoutAfterRecharge() {
  refreshAfterRecharge.value = false
  loading.value = true
  try {
    wallet.value = await getWalletAccount(auth.accessToken)
    await loadQuote()
    persistDraft()
  } catch (error) {
    toast(error, '余额刷新失败，请重试')
  } finally { loading.value = false }
}
async function load() {
  if (!auth.accessToken) return navigateToLogin()
  loading.value = true
  loadError.value = ''
  try {
    const firstLoad = !initialized.value
    const draft = firstLoad ? isCurrentDirectBuyDraft(readCheckoutDraft(auth.user?.id, checkoutSource())) : null
    const previousPaymentMethod = selectedPaymentMethod.value
    const [cartResult, nextAddresses, nextCapabilities, nextWallet, directProduct] = await Promise.all([
      directBuy.value ? Promise.resolve(null) : getCart(auth.accessToken),
      listAddresses(auth.accessToken),
      loadClientCapabilities(),
      getWalletAccount(auth.accessToken).catch(() => null),
      directBuy.value ? getProduct(directBuy.value.productId) : Promise.resolve(null),
    ])
    if (directBuy.value && directProduct) {
      const sku = directProduct.skus.find((item) => item.id === directBuy.value?.skuId)
      const option = sku?.purchase_options.find((item) => item.purchase_mode === directBuy.value?.purchaseMode && item.enabled)
      if (!sku || !option || sku.stock < 1) throw new Error('商品规格已售罄或购买方式已关闭，请返回商品详情重试。')
      const directItem: CartItem = { id: `direct-${sku.id}`, product_id: directProduct.id, product_name: directProduct.name, sku_id: sku.id, sku_name: sku.name, specs: sku.specs, fulfillment_type: sku.fulfillment_type, purchase_mode: option.purchase_mode, quantity: 1, selected: true, unit_cash_amount: option.cash_amount, unit_points: option.points, available_quantity: sku.stock, valid: true, invalid_reason: null }
      cart.value = { items: [directItem], summary: { item_count: 1, selected_quantity: 1, all_valid: true } }
    } else if (cartResult) {
      cart.value = cartResult
      if (firstLoad) restoreCartSelection(draft, cart.value)
    }
    addresses.value = nextAddresses
    capabilities.value = nextCapabilities
    wallet.value = nextWallet
    if (firstLoad) {
      selectedCoupons.value = draft?.selectedCoupons || {}
      paymentIndex.value = paymentIndexFor(draft?.paymentMethod)
      selectedAddress.value = addresses.value.find((item) => item.id === draft?.addressId) || addresses.value.find((item) => item.is_default) || addresses.value[0] || null
    } else {
      paymentIndex.value = paymentIndexFor(previousPaymentMethod)
      selectedAddress.value = addresses.value.find((item) => item.id === selectedAddress.value?.id) || selectedAddress.value || addresses.value.find((item) => item.is_default) || addresses.value[0] || null
    }
    await loadQuote()
    initialized.value = true
    persistDraft()
  } catch (error) { quote.value = null; loadError.value = error instanceof Error ? error.message : '请稍后重试。'; toast(error, '结算信息加载失败') }
  finally { loading.value = false }
}
async function selectAddress(event: { detail: { value: number } }) { invalidate(); selectedAddress.value = addresses.value[Number(event.detail.value)] || null; persistDraft(); await loadQuote() }
function selectPayment(event: { detail: { value: number } }) { paymentIndex.value = Number(event.detail.value); invalidate(); persistDraft() }
function choosePayment(index: number) { paymentIndex.value = index; invalidate(); persistDraft() }
function openRecharge() { persistDraft(); refreshAfterRecharge.value = true; uni.navigateTo({ url: '/pages/wallet-recharge/index?returnTo=checkout' }) }
function openCart() { clearCheckoutDraft(auth.user?.id, checkoutSource()); uni.redirectTo({ url: '/pages/cart/index' }) }
async function loadQuote() {
  quote.value = null
  quoteError.value = ''
  if (!auth.accessToken || !selectedAddress.value || !hasLogistics.value) return
  try { quote.value = await quoteShipping(auth.accessToken, selectedAddress.value.id) }
  catch (error) { quoteError.value = error instanceof Error ? error.message : '运费试算失败，请重试'; toast(error, '运费试算失败') }
}
function buildInput(): CheckoutInput {
  const paymentMethod = selectedPaymentMethod.value
  const fulfillmentTypes = [...new Set(selectedItems.value.map((item) => item.fulfillment_type as FulfillmentType))]
  return {
    capability_version: 1,
    items: selectedItems.value.map((item) => ({ sku_id: item.sku_id, quantity: item.quantity, purchase_mode: item.purchase_mode })),
    group_options: fulfillmentTypes.map((type) => {
      const group = selectedItems.value.filter((item) => item.fulfillment_type === type)
      const hasCash = group.some((item) => (item.unit_cash_amount || 0) > 0) || type === 'logistics'
      const couponId = selectedCoupons.value[type]
      return { fulfillment_type: type, ...(type === 'logistics' && selectedAddress.value ? { address_id: selectedAddress.value.id } : {}), ...(couponId ? { coupon_id: couponId } : {}), ...(hasCash ? { cash_payment_method: paymentMethod } : {}) }
    }),
  }
}
async function submit() {
  if (!auth.accessToken || !canCheckout.value) return
  const needsPreview = !preview.value || couponSelectionDirty.value
  submitting.value = true
  persistDraft()
  try {
    if (needsPreview) {
      const input = buildInput()
      const result = await previewCheckout(auth.accessToken, input)
       preview.value = result
       previewInput.value = input
       createOrderKey.value = `checkout-${Date.now()}-${Math.random().toString(36).slice(2)}`
      selectedCoupons.value = Object.fromEntries(result.groups.filter((group) => group.selected_coupon_id).map((group) => [group.fulfillment_type, group.selected_coupon_id as string]))
      uni.showToast({ title: '优惠与应付金额已更新', icon: 'success' })
      return
    }
    const currentPreview = preview.value
    const currentInput = previewInput.value
    if (!currentPreview || !currentInput) return
    const batch = await createOrders(auth.accessToken, { ...currentInput, preview_token: currentPreview.preview_token }, createOrderKey.value || `checkout-${Date.now()}-${Math.random().toString(36).slice(2)}`)
    clearCheckoutDraft(auth.user?.id, checkoutSource())
    const orderIds = batch.orders.map((order) => order.id).filter(Boolean)
    const pendingOrders = batch.orders.filter((order) => order.status === 'pending_payment')
    uni.showToast({ title: pendingOrders.length ? '订单已创建，请继续支付' : '订单创建成功', icon: 'success' })
    setTimeout(() => {
      if (pendingOrders.length && orderIds.length) uni.redirectTo({ url: `/pages/orders/index?ids=${encodeURIComponent(orderIds.join(','))}` })
      else if (orderIds.length) uni.redirectTo({ url: `/pages/payment-result/index?orderIds=${encodeURIComponent(orderIds.join(','))}&status=success&payment=none` })
      else uni.redirectTo({ url: '/pages/orders/index' })
    }, 450)
  } catch (error) { if (!needsPreview) invalidate(); toast(error, needsPreview ? '优惠预览失败，请调整后重试' : '订单提交失败，请重新预览') }
  finally { submitting.value = false }
}
function invalidate() { preview.value = null; previewInput.value = null; createOrderKey.value = '' }
function chooseCoupon(group: FulfillmentType, coupon: CheckoutCouponOption | null) {
  if (coupon && (!coupon.eligible || usedByOtherGroup(coupon.id, group))) {
    return uni.showToast({ title: usedByOtherGroup(coupon.id, group) ? '该券已用于其他拆单' : coupon.unavailable_reason || '该券不可用', icon: 'none' })
  }
  selectedCoupons.value = { ...selectedCoupons.value, [group]: coupon?.id || undefined }
  persistDraft()
}
function usedByOtherGroup(couponId: string, current: FulfillmentType) { return Object.entries(selectedCoupons.value).some(([group, id]) => group !== current && id === couponId) }
function fulfillmentText(type: FulfillmentType) { return ({ logistics: '物流配送商品', no_logistics: '无需物流商品', virtual_delivery: '虚拟交付商品' } as Record<FulfillmentType, string>)[type] }
function groupCouponSummary(group: CheckoutGroup) { return group.selected_coupon_id ? `已抵扣 ${amountText(group.cash_discount_amount, group.points_discount)}` : '未使用优惠券' }
function couponValueText(coupon: CheckoutCouponOption) { return amountText(coupon.cash_discount_amount, coupon.points_discount) }
function couponEstimatedText(coupon: CheckoutCouponOption) { return amountText(coupon.estimated_cash_discount, coupon.estimated_points_discount) }
function couponThresholdText(coupon: CheckoutCouponOption) { const parts = [coupon.minimum_cash_amount ? `现金满 ¥${money(coupon.minimum_cash_amount)}` : '', coupon.minimum_points ? `积分满 ${coupon.minimum_points}` : ''].filter(Boolean); return parts.join(' 且 ') || '无门槛' }
function toast(error: unknown, fallback: string) { uni.showToast({ title: error instanceof Error ? error.message : fallback, icon: 'none' }) }
function money(cents: number) { return (cents / 100).toFixed(2) }
function modeText(mode: string) { return ({ cash: '现金购买', points: '积分兑换', cash_points: '现金 + 积分' } as Record<string, string>)[mode] || mode }
function priceText(item: CartItem) { return amountText((item.unit_cash_amount || 0) * item.quantity, (item.unit_points || 0) * item.quantity) }
function amountText(cash: number, points: number) { return [cash ? `¥${money(cash)}` : '', points ? `${points.toLocaleString()} 积分` : ''].filter(Boolean).join(' + ') || '无需支付' }
</script>

<style scoped>
.commerce-hero{position:relative;min-height:250rpx;overflow:hidden;margin-bottom:18rpx;border:1rpx solid var(--tm-line);border-radius:var(--tm-radius-lg);background:linear-gradient(135deg,#e7fff1 0,#dff8fb 58%,#eef6ff 100%);box-shadow:var(--tm-shadow-sm)}
.commerce-hero-art{position:absolute;right:-16rpx;top:0;width:68%;height:100%;opacity:.88;object-position:right center}.commerce-hero::after{content:'';position:absolute;inset:0;background:linear-gradient(90deg,rgba(231,255,241,.98) 0,rgba(231,255,241,.9) 34%,rgba(231,255,241,.12) 78%,rgba(231,255,241,0) 100%)}
.commerce-hero-copy{position:relative;z-index:1;width:57%;padding:28rpx 24rpx}.commerce-hero-kicker,.commerce-hero-title,.commerce-hero-sub{display:block}.commerce-hero-kicker{color:var(--tm-brand-700);font-size:13px;font-weight:850;letter-spacing:2.4rpx}.commerce-hero-title{margin-top:10rpx;color:var(--tm-ink-900);font-size:22px;font-weight:950;line-height:1.2}.commerce-hero-sub{margin-top:10rpx;color:var(--tm-ink-500);font-size:13px;line-height:1.55}

  .page{padding-bottom:80rpx}.flow-strip{display:flex;align-items:center;padding:24rpx 28rpx}.flow{display:flex;align-items:center;flex-direction:column;gap:8rpx;color:var(--tm-ink-500)}.flow text{display:flex;align-items:center;justify-content:center;width:38rpx;height:38rpx;border-radius:50%;background:#e8eeee;font-size:13px}.flow small{font-size:13px;white-space:nowrap}.flow.active{color:var(--tm-brand-700);font-weight:850}.flow.active text{background:var(--tm-brand-600);color:#fff}.flow.done text{background:var(--tm-brand-100);color:var(--tm-brand-700)}.line{height:1rpx;flex:1;margin:0 14rpx;background:var(--tm-line)}.item-card{display:flex;gap:20rpx;margin-top:20rpx;padding:24rpx}.item-mark{display:flex;align-items:center;justify-content:center;width:104rpx;height:104rpx;flex:none;border-radius:18rpx;background:linear-gradient(145deg,#dff3f1,#fff0dc);color:var(--tm-brand-700);font-size:22px;font-weight:950}.item-main{min-width:0;flex:1}.item-name,.item-meta{display:block}.item-name{overflow:hidden;font-size:15px;font-weight:900;text-overflow:ellipsis;white-space:nowrap}.item-meta{margin-top:8rpx;color:var(--tm-ink-500);font-size:13px}.item-foot{display:flex;justify-content:space-between;margin-top:16rpx;font-size:14px}.checkout-card{margin-top:20rpx;padding:12rpx 24rpx 24rpx}.setting-row,.amount-row{display:flex;justify-content:space-between;gap:24rpx;padding:22rpx 0;border-bottom:1rpx solid var(--tm-line);font-size:14px}.setting-row text:last-child{max-width:66%;overflow:hidden;color:var(--tm-ink-500);text-align:right;text-overflow:ellipsis;white-space:nowrap}.divider{height:16rpx}.amount-row{padding:12rpx 0;border:0;color:var(--tm-ink-700)}.amount-row.total{margin-top:8rpx;padding-top:20rpx;border-top:1rpx solid var(--tm-line);color:var(--tm-ink-900);font-size:17px;font-weight:900}.rule-note{margin-top:20rpx;padding:20rpx;border-radius:16rpx;background:var(--tm-reward-100);color:#89500c;font-size:13px;line-height:1.55}.quote-error{display:flex;align-items:center;justify-content:space-between;gap:14rpx;margin-top:18rpx;padding:16rpx 18rpx;border-radius:14rpx;background:#fff4f2;color:#b42318;font-size:13px}.quote-error button{flex:none;height:52rpx;margin:0;padding:0 14rpx;border:1rpx solid #e9a99e;border-radius:12rpx;background:#fff;color:#b42318;font-size:13px;line-height:50rpx}.quote-error button::after{border:0}.error-state{border-color:#f3b1a5;background:#fff7f5}.submit{margin-top:26rpx}.cancel-checkout{height:62rpx;margin-top:14rpx;border:2rpx solid var(--tm-ink-900);border-radius:999rpx;background:#fff;color:var(--tm-ink-900);font-size:13px;line-height:58rpx}
.empty-state{margin-top:20rpx;padding:52rpx 28rpx;text-align:center;background:var(--tm-campus-coral-soft)}.empty-mark{display:flex;align-items:center;justify-content:center;width:84rpx;height:84rpx;margin:0 auto;border:2rpx solid var(--tm-ink-900);border-radius:26rpx;background:var(--tm-campus-yellow);font-size:17px;font-weight:950}.empty-title,.empty-copy{display:block}.empty-title{margin-top:18rpx;font-size:17px;font-weight:950}.empty-copy{margin:8rpx auto 0;max-width:560rpx;color:var(--tm-ink-500);font-size:13px;line-height:1.55}.empty-button{height:66rpx;margin-top:22rpx;border:2rpx solid var(--tm-ink-900);border-radius:999rpx;background:var(--tm-brand-600);color:#fff;font-weight:900;line-height:62rpx}.empty-button::after{border:0}
.item-mark{background:var(--tm-brand-100)}
.amount-row.discount{color:#c56518}.coupon-section{margin-top:20rpx;padding:26rpx}.coupon-heading,.group-heading,.coupon-choice{display:flex;align-items:center;justify-content:space-between;gap:20rpx}.coupon-title,.coupon-tip{display:block}.coupon-title{font-size:17px;font-weight:950}.coupon-tip{margin-top:6rpx;color:var(--tm-ink-500);font-size:13px}.dirty-tag{padding:7rpx 12rpx;border-radius:999rpx;background:#fff0d9;color:#9b5c08;font-size:13px;font-weight:800}.coupon-group{margin-top:24rpx;padding-top:22rpx;border-top:1rpx solid var(--tm-line)}.group-heading{margin-bottom:14rpx;font-size:14px;font-weight:850}.group-heading text:last-child{color:var(--tm-brand-700);font-size:13px}.coupon-choice{margin-top:12rpx;padding:18rpx;border:2rpx solid transparent;border-radius:16rpx;background:#f6f8f8}.coupon-choice.selected{border-color:var(--tm-brand-500);background:var(--tm-brand-100)}.coupon-choice.disabled{opacity:.55}.coupon-copy{min-width:0;flex:1}.coupon-name,.coupon-detail,.coupon-saving,.coupon-reason{display:block}.coupon-name{font-size:14px;font-weight:900}.coupon-detail{margin-top:5rpx;color:var(--tm-ink-500);font-size:13px}.coupon-saving{margin-top:7rpx;color:#c56518;font-size:13px;font-weight:800}.coupon-reason{margin-top:7rpx;color:#9a4d43;font-size:13px}.select-mark{width:34rpx;color:var(--tm-brand-700);font-size:15px;font-weight:950;text-align:center}.empty-coupons{padding:22rpx 0;color:var(--tm-ink-500);font-size:13px;text-align:center}.payment-unavailable{margin:10rpx 0;padding:20rpx;border:1rpx solid #f2b8ae;border-radius:16rpx;background:#fff4f2;color:#b42318}.payment-unavailable text,.payment-unavailable small{display:block}.payment-unavailable text{font-size:14px;font-weight:850}.payment-unavailable small{margin-top:7rpx;font-size:13px;line-height:1.5}
/* V09 campus commerce surface */
.page{background:linear-gradient(180deg,var(--tm-campus-cyan-soft) 0,var(--tm-canvas) 520rpx);color:var(--tm-ink-900)}
.flow-strip,.item-card,.checkout-card,.coupon-section{border:1rpx solid var(--tm-line);border-radius:var(--tm-radius-lg);box-shadow:var(--tm-shadow-sm);background:var(--tm-surface)}
.flow-strip{background:var(--tm-surface)}
.flow.active text{border:0;background:var(--tm-brand-600);color:#fff}.flow.done text{border:0;background:var(--tm-brand-100);color:var(--tm-brand-700)}
.item-mark{border:0;background:var(--tm-brand-100);color:var(--tm-brand-700)}
.checkout-card{background:var(--tm-surface)}.coupon-section{background:var(--tm-surface-soft)}
.coupon-choice{border:1rpx solid transparent;background:var(--tm-surface);box-shadow:none}
.coupon-choice.selected{border-color:var(--tm-brand-500);background:var(--tm-brand-50)}
.coupon-choice.disabled{opacity:.48}
.rule-note{border:1rpx solid rgba(183,123,18,.18);background:var(--tm-reward-100);color:#89500c}
.submit{border:1rpx solid transparent;box-shadow:none}
.cancel-checkout{border:1rpx solid var(--tm-line);color:var(--tm-ink-700)}
.payment-section{padding:14rpx 0}.payment-heading{display:flex;align-items:center;justify-content:space-between;padding:10rpx 0 14rpx}.payment-heading text:first-child{font-size:14px;font-weight:900}.payment-heading text:last-child{color:var(--tm-ink-500);font-size:13px}.payment-choice{display:flex;align-items:center;gap:14rpx;margin-top:12rpx;padding:17rpx;border:1rpx solid var(--tm-line);border-radius:var(--tm-radius-md);background:var(--tm-surface);box-shadow:none}.payment-choice.selected{border-color:var(--tm-brand-500);background:var(--tm-brand-50)}.payment-choice.insufficient{opacity:.62}.payment-mark{display:flex;align-items:center;justify-content:center;width:48rpx;height:48rpx;border-radius:14rpx;background:var(--tm-brand-600);color:#fff;font-size:13px;font-weight:900}.payment-copy{min-width:0;flex:1}.payment-copy text,.payment-copy small{display:block}.payment-copy text{font-size:14px;font-weight:850}.payment-copy small{margin-top:4rpx;color:var(--tm-ink-500);font-size:12px}.payment-check{width:30rpx;color:var(--tm-brand-700);font-size:15px;font-weight:950}.recharge-link{margin-top:12rpx;padding:15rpx;border-radius:14rpx;background:var(--tm-reward-100);color:#995900;font-size:13px;font-weight:800;text-align:center}
.empty-state,.error-state,.payment-unavailable,.quote-error{border-color:var(--tm-line);box-shadow:var(--tm-shadow-sm)}
.error-state,.payment-unavailable,.quote-error{background:var(--tm-campus-coral-soft)}
.submit[disabled],.payment-choice[disabled]{opacity:.46;filter:saturate(.55)}
</style>
