<template>
  <view class="page">
    <ModulePlaceholder v-if="modules.resolved && !moduleEnabled" code="module_mall_trade" />
    <view v-else-if="!modules.resolved" class="mp-skeleton">
      <view class="tm-skeleton" style="height: 200rpx; border-radius: 24rpx; margin: 24rpx 28rpx;" />
      <view class="tm-skeleton" style="height: 320rpx; border-radius: 24rpx; margin: 20rpx 28rpx;" />
    </view>
    <template v-else>
    <view class="subnav">
      <button class="nav-back" aria-label="返回" @click="goBack">‹</button>
      <text class="nav-title">确认订单</text>
    </view>

    <view v-if="loading && !selectedItems.length" class="state-card"><view class="state-spinner" /><text class="state-title">正在准备结算</text><text class="state-copy">正在校验商品、库存和运费，请稍候。</text></view>
    <view v-else-if="loadError" class="state-card"><text class="state-title">结算信息加载失败</text><text class="state-copy">{{ loadError }}</text><button class="btn-main" @click="load">重新加载</button></view>
    <view v-else-if="!cart.items.length || !selectedItems.length" class="state-card">
      <text class="state-title">{{ cart.items.length ? '没有可结算的商品' : '购物车还是空的' }}</text>
      <text class="state-copy">{{ cart.items.length ? '请返回购物车选择有效商品，或调整已下架/库存不足的商品。' : '去商城挑选支持现金、积分和组合购买的校园好物。' }}</text>
      <button class="btn-main" @click="openCart">返回购物车</button>
    </view>

    <template v-else>
    <picker :range="addressLabels" @change="selectAddress">
      <view class="addr-card">
        <view class="addr-ic">址</view>
        <view class="addr-main">
          <text class="addr-name">{{ selectedAddress ? `${selectedAddress.recipient_name} · ${selectedAddress.phone}` : (hasLogistics ? '请选择收货地址' : '无需收货地址') }}</text>
          <text class="addr-sub">{{ selectedAddress ? `${selectedAddress.province}${selectedAddress.city}${selectedAddress.district}${selectedAddress.detail}` : (hasLogistics ? '点击选择地址' : '该订单无需物流配送') }}</text>
        </view>
        <text class="addr-arrow">›</text>
      </view>
    </picker>

    <view class="card goods-card">
      <view v-for="item in selectedItems" :key="item.id" class="goods-row">
        <view class="goods-mark">{{ item.product_name.slice(0, 1) }}</view>
        <view class="goods-main">
          <text class="goods-name">{{ item.product_name }}</text>
          <text class="goods-meta">{{ item.sku_name }} · {{ modeText(item.purchase_mode) }}</text>
          <view class="goods-foot"><text class="goods-price">{{ priceText(item) }}</text><text class="goods-qty">×{{ item.quantity }}</text></view>
        </view>
      </view>
    </view>

    <view class="form-card">
      <view class="frow"><text class="f-label">配送方式</text><text class="spacer" /><text class="f-val">{{ hasLogistics ? '物流配送' : '无需物流' }}{{ hasLogistics && !quoteError ? ` · 运费 ¥${money(preview?.shipping_amount || quote?.shipping_cash_amount || 0)}` : '' }}</text></view>
      <view v-if="quoteError" class="frow"><text class="f-label">运费试算</text><text class="spacer" /><text class="f-val error" @click="loadQuote">试算失败，点击重试</text></view>
      <view class="frow"><text class="f-label">优惠说明</text><text class="f-val dim">每张券只能用于一个交付拆单，运费不参与抵扣</text></view>
    </view>

    <view v-if="paymentLabels.length" class="form-card">
      <view class="frow head-row"><text class="f-label">支付方式</text><text class="spacer" /><text class="f-val dim">请选择一种全额支付</text></view>
      <view v-for="(option, index) in paymentOptions" :key="option.code" class="pay-choice" :class="{ selected: paymentIndex === index, insufficient: option.code === 'wallet' && walletInsufficient }" @click="choosePayment(index)">
        <view class="pay-mark">{{ option.code === 'wallet' ? '余' : '微' }}</view>
        <view class="pay-copy">
          <text class="pay-name">{{ option.label }}</text>
          <text v-if="option.code === 'wallet'" class="pay-sub">可用 ¥{{ wallet ? money(wallet.available_amount) : '—' }}{{ walletInsufficient ? ' · 余额不足' : '' }}</text>
          <text v-else class="pay-sub">使用在线支付现金与运费</text>
        </view>
        <view class="pay-check" :class="{ on: paymentIndex === index }">{{ paymentIndex === index ? '✓' : '' }}</view>
      </view>
      <view v-if="selectedPaymentMethod === 'wallet' && walletInsufficient" class="recharge-link" @click="openRecharge">当前余额不足，先去充值 ›</view>
    </view>
    <view v-else-if="requiresCashPayment" class="payment-unavailable"><text>暂无可用现金支付方式</text><small>{{ paymentUnavailableText }}</small></view>

    <view v-if="preview" class="coupon-section">
      <view class="coupon-heading">
        <view><text class="coupon-title">优惠券</text><text class="coupon-tip">按拆单选择优惠券</text></view>
        <text v-if="couponSelectionDirty" class="dirty-tag">待重新预览</text>
      </view>
      <view v-for="group in preview.groups" :key="group.group_key" class="coupon-group">
        <view class="group-heading"><text>{{ fulfillmentText(group.fulfillment_type) }}</text><text>{{ groupCouponSummary(group) }}</text></view>
        <view class="coupon-choice" :class="{ selected: !selectedCoupons[group.fulfillment_type] }" @click="chooseCoupon(group.fulfillment_type, null)">
          <view><text class="coupon-name">不使用优惠券</text><text class="coupon-detail">按原价结算该拆单</text></view>
          <view class="pay-check" :class="{ on: !selectedCoupons[group.fulfillment_type] }">{{ !selectedCoupons[group.fulfillment_type] ? '✓' : '' }}</view>
        </view>
        <view v-for="coupon in group.coupon_options" :key="coupon.id" class="coupon-choice" :class="{ selected: selectedCoupons[group.fulfillment_type] === coupon.id, disabled: !coupon.eligible || usedByOtherGroup(coupon.id, group.fulfillment_type) }" @click="chooseCoupon(group.fulfillment_type, coupon)">
          <view class="coupon-copy">
            <text class="coupon-name">{{ coupon.name }}</text>
            <text class="coupon-detail">{{ couponValueText(coupon) }} · {{ couponThresholdText(coupon) }}</text>
            <text v-if="coupon.eligible && !usedByOtherGroup(coupon.id, group.fulfillment_type)" class="coupon-saving">预计抵扣 {{ couponEstimatedText(coupon) }}</text>
            <text v-else class="coupon-reason">{{ usedByOtherGroup(coupon.id, group.fulfillment_type) ? '已用于其他拆单' : coupon.unavailable_reason }}</text>
          </view>
          <view class="pay-check" :class="{ on: selectedCoupons[group.fulfillment_type] === coupon.id }">{{ selectedCoupons[group.fulfillment_type] === coupon.id ? '✓' : '' }}</view>
        </view>
        <view v-if="!group.coupon_options.length" class="empty-coupons">账户暂无可用于结算的优惠券</view>
      </view>
    </view>

    <view class="card amount-card">
      <view class="amount-row"><text>商品现金</text><text>¥{{ money(itemsCashAmount) }}</text></view>
      <view class="amount-row"><text>商品积分</text><text>{{ itemsPoints.toLocaleString() }} 积分</text></view>
      <view v-if="preview?.cash_discount_amount" class="amount-row discount"><text>优惠券抵扣现金</text><text>-¥{{ money(preview.cash_discount_amount) }}</text></view>
      <view v-if="preview?.points_discount" class="amount-row discount"><text>优惠券抵扣积分</text><text>-{{ preview.points_discount.toLocaleString() }} 积分</text></view>
      <view class="amount-row"><text>现金运费</text><text>{{ quoteError ? '试算失败' : `¥${money(preview?.shipping_amount || quote?.shipping_cash_amount || 0)}` }}</text></view>
      <view class="amount-row total"><text>应付合计</text><text class="total-val">{{ preview ? amountText(preview.payable_cash_amount, preview.payable_points) : amountText(itemsCashAmount + (quote?.shipping_cash_amount || 0), itemsPoints) }}</text></view>
    </view>

    <view class="rule-note" v-if="!reviewing">本单现金部分仅可选择在线支付或余额全额支付，两者不可组合；运费始终使用现金。</view>

    <view class="action-bar">
      <view class="total-box">
        <text class="total-label">合计</text>
        <text class="total-amount">{{ preview ? amountText(preview.payable_cash_amount, preview.payable_points) : amountText(itemsCashAmount + (quote?.shipping_cash_amount || 0), itemsPoints) }}</text>
      </view>
      <button class="ab-main" :loading="submitting" :disabled="submitting || !canCheckout" @click="submit">{{ submitText }}</button>
    </view>
    <button class="cancel-checkout" :disabled="submitting" @click="openCart">取消结算并返回购物车</button>
    </template>
    </template>
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
import ModulePlaceholder from '@/components/ModulePlaceholder.vue'
import { useModulesStore } from '@/stores/modules'

const modules = useModulesStore()
const moduleEnabled = computed(() => modules.isEnabled('module_mall_trade'))

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
const reviewing = computed(() => capabilities.value?.review_status === 'reviewing')
const loading = ref(false)
const loadError = ref('')
const quoteError = ref('')
const createOrderKey = ref('')
const directBuy = ref<{ productId: string; skuId: string; purchaseMode: 'cash' | 'points' | 'cash_points' } | null>(null)
const wallet = ref<WalletAccount | null>(null)
const initialized = ref(false)
const refreshAfterRecharge = ref(false)
const paymentOptions = computed(() => (capabilities.value?.payment_methods || [])
  .filter((item) => item.enabled)
  // 小程序审核期不展示钱包（余额）支付
  .filter((item) => !reviewing.value || item.code !== 'wallet'))
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
const paymentUnavailableText = computed(() => reviewing.value
  ? '当前暂未开放在线支付，请稍后再试。'
  : (capabilities.value?.payment_unavailable_message || '支付能力正在加载，请稍后重试。'))
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
  await modules.ensureReady()
  if (!moduleEnabled.value) return
  if (!auth.isAuthenticated) return navigateToLogin()
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
  if (!auth.isAuthenticated) return navigateToLogin()
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
function goBack() { uni.navigateBack({ fail: () => openCart() }) }
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
.page{min-height:100vh;padding-bottom:260rpx;background:#F4F6F4;color:#191B1F}
.subnav{display:flex;align-items:center;justify-content:center;height:88rpx;position:relative;background:#0BBF63}
.nav-back{position:absolute;left:16rpx;top:14rpx;display:flex;align-items:center;justify-content:center;width:60rpx;height:60rpx;margin:0;padding:0;border-radius:50%;color:#fff;font-size:40rpx;line-height:56rpx}
.nav-back::after{border:0}
.nav-title{color:#fff;font-size:32rpx;font-weight:700}
.card,.form-card{margin:20rpx 24rpx 0;border-radius:24rpx;background:#fff}
.addr-card{display:flex;align-items:center;gap:22rpx;margin:20rpx 24rpx 0;padding:28rpx;border-radius:24rpx;background:#fff}
.addr-ic{display:flex;align-items:center;justify-content:center;width:76rpx;height:76rpx;flex:none;border-radius:22rpx;background:#E7F9EF;color:#08A152;font-size:30rpx;font-weight:800}
.addr-main{min-width:0;flex:1}
.addr-name{display:block;font-size:28rpx;font-weight:700}
.addr-sub{display:block;margin-top:8rpx;color:#767C85;font-size:24rpx;line-height:1.5}
.addr-arrow{color:#AEB3BB;font-size:30rpx}
.goods-card{padding:28rpx}
.goods-row{display:flex;gap:22rpx}
.goods-row+.goods-row{margin-top:24rpx;padding-top:24rpx;border-top:1rpx solid #F5F6F6}
.goods-mark{display:flex;align-items:center;justify-content:center;width:152rpx;height:152rpx;flex:none;border-radius:20rpx;background:#E7F9EF;color:#08A152;font-size:44rpx;font-weight:900}
.goods-main{min-width:0;flex:1}
.goods-name{display:block;font-size:26rpx;font-weight:600;line-height:1.4}
.goods-meta{display:block;margin-top:10rpx;color:#AEB3BB;font-size:22rpx}
.goods-foot{display:flex;align-items:center;margin-top:14rpx}
.goods-price{color:#FF2442;font-size:32rpx;font-weight:800}
.goods-qty{margin-left:auto;color:#767C85;font-size:24rpx}
.form-card{padding:4rpx 28rpx}
.frow{display:flex;align-items:center;gap:20rpx;min-height:104rpx;padding:24rpx 0;border-bottom:1rpx solid #F5F6F6}
.frow:last-child{border-bottom:0}
.f-label{width:128rpx;flex:none;font-size:28rpx;font-weight:600}
.spacer{flex:1}
.f-val{font-size:26rpx;color:#08A152;font-weight:600;text-align:right}
.f-val.dim{color:#767C85;font-weight:400}
.f-val.error{color:#FF2442}
.head-row{min-height:88rpx;padding-bottom:8rpx}
.pay-choice{display:flex;align-items:center;gap:20rpx;margin:0 0 20rpx;padding:22rpx;border:2rpx solid #EDEFEF;border-radius:24rpx;background:#fff}
.pay-choice.selected{border-color:#0BBF63;background:#E7F9EF}
.pay-choice.insufficient{opacity:.62}
.pay-mark{display:flex;align-items:center;justify-content:center;width:72rpx;height:72rpx;flex:none;border-radius:20rpx;background:linear-gradient(135deg,#12D978,#08A152);color:#fff;font-size:26rpx;font-weight:800}
.pay-copy{min-width:0;flex:1}
.pay-name{display:block;font-size:28rpx;font-weight:600}
.pay-sub{display:block;margin-top:6rpx;color:#767C85;font-size:24rpx}
.pay-check{display:flex;align-items:center;justify-content:center;width:36rpx;height:36rpx;flex:none;border:2rpx solid #EDEFEF;border-radius:50%;color:#fff;font-size:22rpx}
.pay-check.on{border-color:#0BBF63;background:#0BBF63}
.recharge-link{margin:4rpx 0 24rpx;padding:22rpx;border-radius:20rpx;background:#FFF2E5;color:#F2620A;font-size:26rpx;font-weight:700;text-align:center}
.payment-unavailable{margin:20rpx 24rpx 0;padding:28rpx;border-radius:24rpx;background:#FFEEEA;color:#FF2442}
.payment-unavailable text,.payment-unavailable small{display:block}
.payment-unavailable text{font-size:28rpx;font-weight:600}
.payment-unavailable small{margin-top:8rpx;color:#767C85;font-size:24rpx;line-height:1.5}
.coupon-section{margin:20rpx 24rpx 0;padding:28rpx;border-radius:24rpx;background:#fff}
.coupon-heading,.group-heading,.coupon-choice{display:flex;align-items:center;justify-content:space-between;gap:20rpx}
.coupon-title,.coupon-tip{display:block}
.coupon-title{font-size:30rpx;font-weight:700}
.coupon-tip{margin-top:8rpx;color:#767C85;font-size:24rpx}
.dirty-tag{padding:8rpx 16rpx;border-radius:999rpx;background:#FFF2E5;color:#F2620A;font-size:22rpx;font-weight:700}
.coupon-group{margin-top:24rpx;padding-top:22rpx;border-top:1rpx solid #F5F6F6}
.group-heading{margin-bottom:16rpx;font-size:26rpx;font-weight:600}
.group-heading text:last-child{color:#08A152;font-size:24rpx}
.coupon-choice{margin-top:14rpx;padding:22rpx;border:2rpx solid #EDEFEF;border-radius:24rpx;background:#fff}
.coupon-choice.selected{border-color:#0BBF63;background:#E7F9EF}
.coupon-choice.disabled{opacity:.5}
.coupon-copy{min-width:0;flex:1}
.coupon-name,.coupon-detail,.coupon-saving,.coupon-reason{display:block}
.coupon-name{font-size:26rpx;font-weight:600}
.coupon-detail{margin-top:6rpx;color:#767C85;font-size:24rpx}
.coupon-saving{margin-top:8rpx;color:#F2620A;font-size:24rpx;font-weight:700}
.coupon-reason{margin-top:8rpx;color:#FF2442;font-size:24rpx}
.empty-coupons{padding:28rpx 0;color:#AEB3BB;font-size:24rpx;text-align:center}
.amount-card{padding:8rpx 28rpx 24rpx}
.amount-row{display:flex;justify-content:space-between;gap:24rpx;padding:18rpx 0;color:#767C85;font-size:26rpx}
.amount-row.discount{color:#F2620A}
.amount-row.total{margin-top:10rpx;padding-top:24rpx;border-top:1rpx solid #F5F6F6;color:#191B1F;font-size:30rpx;font-weight:800}
.total-val{color:#FF2442}
.rule-note{margin:20rpx 24rpx 0;padding:24rpx;border-radius:24rpx;background:#FFF2E5;color:#F2620A;font-size:24rpx;line-height:1.55}
.action-bar{position:fixed;z-index:50;right:0;bottom:0;left:0;display:flex;align-items:center;gap:20rpx;padding:16rpx 24rpx calc(16rpx + env(safe-area-inset-bottom));border-top:1rpx solid #EDEFEF;background:#fff}
.total-box{display:flex;flex:1;align-items:baseline;gap:12rpx;padding-left:8rpx}
.total-label{color:#767C85;font-size:24rpx}
.total-amount{color:#FF2442;font-size:34rpx;font-weight:800}
.ab-main{flex:none;width:340rpx;height:84rpx;margin:0;border-radius:999rpx;background:linear-gradient(135deg,#12D978,#08A152);color:#fff;font-size:28rpx;font-weight:700;line-height:84rpx;box-shadow:0 12rpx 28rpx rgba(11,191,99,.3)}
.ab-main::after{border:0}
.ab-main[disabled]{opacity:.5}
.cancel-checkout{position:fixed;z-index:49;right:0;bottom:150rpx;left:0;margin:0;color:#AEB3BB;font-size:22rpx;text-align:center;background:transparent}
.cancel-checkout::after{border:0}
.state-card{margin:20rpx 24rpx 0;padding:80rpx 40rpx;border-radius:24rpx;background:#fff;text-align:center}
.state-spinner{width:56rpx;height:56rpx;margin:0 auto;border:6rpx solid #E7F9EF;border-top-color:#0BBF63;border-radius:50%;animation:ck-spin .8s linear infinite}
.state-title{display:block;margin-top:24rpx;font-size:30rpx;font-weight:700}
.state-copy{display:block;margin:12rpx auto 0;max-width:560rpx;color:#767C85;font-size:24rpx;line-height:1.6}
.btn-main{height:80rpx;margin:32rpx auto 0;padding:0 48rpx;border-radius:999rpx;background:linear-gradient(135deg,#12D978,#08A152);color:#fff;font-size:28rpx;font-weight:700;line-height:80rpx}
.btn-main::after{border:0}
@keyframes ck-spin{to{transform:rotate(360deg)}}
</style>
