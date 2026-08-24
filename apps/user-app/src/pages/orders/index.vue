<template>
  <view class="page">
    <view class="commerce-hero" aria-label="订单旅程">
      <image class="commerce-hero-art" src="/static/home/hero-campus-reward.png" mode="aspectFill" />
      <view class="commerce-hero-copy">
        <text class="commerce-hero-kicker">ORDER JOURNEY</text>
        <text class="commerce-hero-title">我的订单</text>
        <text class="commerce-hero-sub">支付、履约、确认与售后，每一步都有清晰记录</text>
      </view>
    </view>
    <view class="heading"><text class="eyebrow">ORDER JOURNEY</text><text class="title">我的订单</text><text class="sub">每个拆分订单独立支付、履约和售后，状态变化清晰可追踪</text></view>
    <view class="order-tools"><button class="after-sale" @click="openAfterSales"><text>售</text><view><strong>售后服务</strong><small>退款、退货与换货进度</small></view><b>›</b></button></view>
    <scroll-view class="tabs" scroll-x><view v-for="tab in tabs" :key="tab.value" :class="['tab',{active:activeStatus===tab.value}]" @click="activeStatus=tab.value">{{ tab.label }}<text v-if="tabCount(tab.value)">{{ tabCount(tab.value) }}</text></view></scroll-view>
    <view v-if="loading" class="skeleton-list"><view v-for="index in 3" :key="index" class="skeleton-card tm-skeleton" /></view>
    <view v-else-if="loadError" class="error-state"><text class="error-title">订单暂时无法加载</text><text class="error-copy">{{ loadError }}</text><button class="error-action" @click="reloadOrders">重新加载</button></view>
    <view v-for="order in filteredOrders" :key="order.id" class="card" @click="openOrder(order)">
      <view class="row"><text class="number">{{ order.order_no }}</text><text class="status" :class="{ pending: order.status === 'pending_payment', success: ['fulfilled', 'completed'].includes(order.status), danger: ['cancelled', 'closed', 'refunded'].includes(order.status) }">{{ statusText(order.status) }}</text></view>
      <text class="meta">{{ fulfillmentText(order.fulfillment_type) }} · {{ paymentText(order.cash_payment_method) }}</text>
      <view v-for="item in order.items" :key="item.id" class="item"><text>{{ item.product_name }} · {{ item.sku_name }} × {{ item.quantity }}</text><text>{{ modeText(item.purchase_mode) }}</text></view>
      <view class="total"><text>应付</text><text>{{ amountText(order.payable_cash_amount, order.payable_points) }}</text></view>
      <text v-if="order.shipping_amount" class="shipping">含现金运费 ¥{{ money(order.shipping_amount) }}</text>
      <view v-for="fulfillment in order.fulfillments || []" :key="fulfillment.id" class="delivery">
        <text class="delivery-title">{{ fulfillmentText(fulfillment.method) }}</text>
        <text v-if="fulfillment.method === 'logistics'">{{ fulfillment.carrier_name || fulfillment.carrier_code || '承运商' }} · {{ fulfillment.tracking_no }}</text>
        <text v-else-if="fulfillment.method === 'no_logistics'">无需物流原因：{{ fulfillment.no_logistics_reason }}</text>
        <text v-else>虚拟交付内容：{{ deliveryText(fulfillment.virtual_delivery) }}</text>
      </view>
      <view class="card-entry"><text>查看订单详情</text><text>›</text></view>
      <button v-if="order.status === 'pending_payment'" class="pay" :loading="payingOrderId === order.id || isPaymentPolling(order.id)" :disabled="!!payingOrderId || isPaymentPolling(order.id)" @click.stop="pay(order)">{{ isPaymentPolling(order.id) ? '支付结果确认中…' : (order.cash_payment_method === 'wallet' ? '余额全额支付' : '微信支付') }}</button>
      <button v-if="order.status === 'fulfilled'" class="pay" :loading="payingOrderId === order.id" @click.stop="confirmComplete(order)">确认完成</button>
    </view>
    <view v-if="!loading && !loadError && !filteredOrders.length" class="empty-card"><view class="empty-icon">单</view><text class="empty-title">{{ activeStatus === 'all' ? '还没有订单' : '当前状态暂无订单' }}</text><text class="empty-copy">商城支持现金、积分和现金＋积分三种购买方式</text><button class="empty-action" @click="openProducts">去逛商城</button></view>
    <AppTabBar current-target="account" />
  </view>
</template>

<script setup lang="ts">
import { onLoad, onShow } from '@dcloudio/uni-app'
import { computed, ref } from 'vue'
import { completeOrder, createPayment, getPayment, listOrders, type Order, type Payment } from '@/api/orders'
import AppTabBar from '@/components/AppTabBar.vue'
import { useAuthStore } from '@/stores/auth'
import { navigateToLogin } from '@/utils/authRedirect'

const auth = useAuthStore()
const orders = ref<Order[]>([])
const loading = ref(false)
const loadError = ref('')
const payingOrderId = ref('')
const pendingPaymentByOrder = ref<Record<string, string>>({})
const pendingPaymentStorageKey = 'task_mall_pending_payment'
const activeStatus = ref('all')
const requestedOrderId = ref('')
const requestedOrderIds = ref<string[]>([])
const openedRequestedOrder = ref(false)
const tabs = [
  { label: '全部', value: 'all' }, { label: '待支付', value: 'pending_payment' },
  { label: '待履约', value: 'pending_fulfillment' }, { label: '待确认', value: 'fulfilled' },
  { label: '已完成', value: 'completed' },
]
const visibleOrders = computed(() => requestedOrderIds.value.length ? orders.value.filter((item) => requestedOrderIds.value.includes(item.id)) : orders.value)
const filteredOrders = computed(() => activeStatus.value === 'all' ? visibleOrders.value : visibleOrders.value.filter(item => item.status === activeStatus.value))

onLoad((options?: Record<string, string | undefined>) => {
  requestedOrderId.value = typeof options?.id === 'string' ? options.id : ''
  requestedOrderIds.value = typeof options?.ids === 'string'
    ? decodeURIComponent(options.ids).split(',').filter(Boolean)
    : requestedOrderId.value ? [requestedOrderId.value] : []
  const statusMap: Record<string, string> = { pending: 'pending_payment', reviewing: 'pending_fulfillment', completed: 'completed' }
  const accountStatus = options?.status
  if (accountStatus) activeStatus.value = statusMap[accountStatus] || (tabs.some((tab) => tab.value === accountStatus) ? accountStatus : 'all')
  const resumePaymentId = typeof options?.resumePayment === 'string' ? options.resumePayment : typeof options?.paymentId === 'string' ? options.paymentId : ''
  const resumeOrderId = typeof options?.resumeOrder === 'string' ? options.resumeOrder : typeof options?.orderId === 'string' ? options.orderId : ''
  if (resumePaymentId && resumeOrderId) {
    pendingPaymentByOrder.value = { ...pendingPaymentByOrder.value, [resumeOrderId]: resumePaymentId }
    uni.setStorageSync(pendingPaymentStorageKey, { paymentId: resumePaymentId, orderId: resumeOrderId })
  }
})

onShow(async () => {
  if (!auth.accessToken) return navigateToLogin()
  loading.value = true
  loadError.value = ''
  try {
    await refreshOrders()
    await resumeStoredPayment()
    if (requestedOrderId.value && !openedRequestedOrder.value) {
      openedRequestedOrder.value = true
      openOrder({ id: requestedOrderId.value })
    }
  }
  catch (error) { loadError.value = error instanceof Error ? error.message : '订单加载失败，请稍后重试'; uni.showToast({ title: loadError.value, icon: 'none' }) }
  finally { loading.value = false }
})

async function reloadOrders() {
  if (!auth.accessToken) return navigateToLogin()
  loading.value = true
  loadError.value = ''
  try { await refreshOrders() }
  catch (error) { loadError.value = error instanceof Error ? error.message : '订单加载失败，请稍后重试' }
  finally { loading.value = false }
}

function money(cents: number) { return (cents / 100).toFixed(2) }
function amountText(cash: number, points: number) { return [cash ? `¥${money(cash)}` : '', points ? `${points} 积分` : ''].filter(Boolean).join(' + ') || '无需支付' }
function statusText(status: string) { return ({ pending_payment: '待支付', pending_fulfillment: '待履约', fulfilled: '待确认', completed: '已完成', partially_refunded: '部分退款', refunded: '已退款', cancelled: '已取消', closed: '已关闭' } as Record<string, string>)[status] || '状态更新中' }
function fulfillmentText(type: string) { return ({ logistics: '物流配送', no_logistics: '无需物流', virtual_delivery: '虚拟交付' } as Record<string, string>)[type] || type }
function paymentText(method: string) { return ({ wallet: '余额全额', wechat: '微信支付', none: '无现金支付' } as Record<string, string>)[method] || method }
function modeText(mode: string) { return ({ cash: '现金', points: '积分', cash_points: '现金 + 积分' } as Record<string, string>)[mode] || mode }
function deliveryText(value: Record<string, unknown> | null) { return value ? Object.entries(value).map(([key, item]) => `${key}：${String(item)}`).join('；') : '已完成虚拟交付' }
async function pay(order: Order) {
  if (!auth.accessToken || isPaymentPolling(order.id)) return
  payingOrderId.value = order.id
  try {
    const payment = await createPayment(auth.accessToken, order.id, `payment-${order.id}-${Date.now()}`)
    if (payment.status === 'succeeded') {
      return openPaymentResult(order.id, payment.id)
    }
    rememberPendingPayment(payment)
    const redirected = await invokeWechat(payment)
    if (!redirected) await pollPayment(payment.id, order.id)
  } catch (error) {
    const message = error instanceof Error ? error.message : '支付发起失败'
    if (order.cash_payment_method === 'wallet' && message.includes('余额不足')) {
      const result = await uni.showModal({ title: '余额不足', content: '当前余额无法支付该订单，是否先去充值？', confirmText: '去充值' })
      if (result.confirm) uni.navigateTo({ url: '/pages/wallet-recharge/index' })
    } else uni.showToast({ title: message, icon: 'none' })
  }
  finally { payingOrderId.value = '' }
}
async function invokeWechat(payment: Payment): Promise<boolean> {
  const payload = payment.client_payload
  if (!payload || payload.kind === 'wallet') return false
  if (payload.sandbox) { uni.showToast({ title: '本地模拟支付单已创建，正在查询结果', icon: 'none' }); return false }
  if (payload.kind === 'mweb') {
    // #ifdef H5
    const returnUrl = new URL(window.location.href)
    returnUrl.searchParams.set('resumePayment', payment.id)
    returnUrl.searchParams.set('resumeOrder', payment.order_id)
    returnUrl.searchParams.set('paymentId', payment.id)
    const separator = payload.mweb_url.includes('?') ? '&' : '?'
    window.location.assign(`${payload.mweb_url}${separator}redirect_url=${encodeURIComponent(returnUrl.toString())}`)
    // #endif
    return true
  }
  // #ifdef MP-WEIXIN
  await uni.requestPayment({ provider: 'wxpay', timeStamp: payload.timeStamp, nonceStr: payload.nonceStr, package: payload.package, signType: 'RSA', paySign: payload.paySign })
  // #endif
  uni.showToast({ title: '支付结果确认中', icon: 'none' })
  return false
}
function rememberPendingPayment(payment: Payment) {
  pendingPaymentByOrder.value = { ...pendingPaymentByOrder.value, [payment.order_id]: payment.id }
  uni.setStorageSync(pendingPaymentStorageKey, { paymentId: payment.id, orderId: payment.order_id })
}
function clearPendingPayment(orderId: string) {
  const next = { ...pendingPaymentByOrder.value }
  delete next[orderId]
  pendingPaymentByOrder.value = next
  const stored = uni.getStorageSync(pendingPaymentStorageKey) as { orderId?: string } | null
  if (stored?.orderId === orderId) uni.removeStorageSync(pendingPaymentStorageKey)
}
function isPaymentPolling(orderId: string) { return !!pendingPaymentByOrder.value[orderId] }
async function pollPayment(paymentId: string, orderId: string) {
  if (!auth.accessToken) return
  pendingPaymentByOrder.value = { ...pendingPaymentByOrder.value, [orderId]: paymentId }
  for (let attempt = 0; attempt < 12; attempt += 1) {
    try {
      const payment = await getPayment(auth.accessToken, paymentId)
      await refreshOrders()
      if (payment.status === 'succeeded') {
        clearPendingPayment(orderId)
        return openPaymentResult(orderId, payment.id)
      }
      if (['failed', 'closed', 'cancelled'].includes(payment.status)) {
        clearPendingPayment(orderId)
        uni.showToast({ title: payment.status === 'failed' ? '支付未成功，请重新发起' : '支付单已关闭', icon: 'none' })
        return
      }
    } catch {
      // 临时网络失败继续查询，避免用户重复创建支付单。
    }
    await new Promise((resolve) => setTimeout(resolve, 1500))
  }
  uni.showToast({ title: '支付结果仍在确认中，请稍后返回查看', icon: 'none' })
}
async function resumeStoredPayment() {
  const stored = uni.getStorageSync(pendingPaymentStorageKey) as { paymentId?: string; orderId?: string } | null
  if (!stored?.paymentId || !stored.orderId) return
  const order = orders.value.find((item) => item.id === stored.orderId)
  if (!order || order.status !== 'pending_payment') return clearPendingPayment(stored.orderId)
  await pollPayment(stored.paymentId, stored.orderId)
}
async function refreshOrders() { if (auth.accessToken) orders.value = (await listOrders(auth.accessToken)).items }
async function confirmComplete(order: Order) { if (!auth.accessToken) return; payingOrderId.value = order.id; try { await completeOrder(auth.accessToken, order.id); await refreshOrders(); uni.showToast({ title: '订单已完成', icon: 'success' }) } catch (error) { uni.showToast({ title: error instanceof Error ? error.message : '确认失败', icon: 'none' }) } finally { payingOrderId.value = '' } }
function openAfterSales() { uni.navigateTo({ url: '/pages/after-sales/index' }) }
function openProducts() { uni.reLaunch({ url: '/pages/products/index' }) }
function openOrder(order: Pick<Order, 'id'>) { uni.navigateTo({ url: `/pages/order-detail/index?id=${encodeURIComponent(order.id)}` }) }
function openPaymentResult(orderId: string, paymentId: string) { uni.navigateTo({ url: `/pages/payment-result/index?orderId=${encodeURIComponent(orderId)}&paymentId=${encodeURIComponent(paymentId)}&status=success` }) }
function tabCount(status: string) { return status === 'all' ? visibleOrders.value.length : visibleOrders.value.filter(item => item.status === status).length }
</script>

<style scoped>
.page{min-height:100vh;padding:32rpx 28rpx 90rpx;background:var(--tm-canvas);box-sizing:border-box}.heading{padding:22rpx 8rpx 16rpx}.eyebrow,.title,.sub,.meta,.shipping,.empty-title,.empty-copy{display:block}.eyebrow{color:var(--tm-brand-600);font-size:13px;font-weight:800;letter-spacing:2.5rpx}.title{margin-top:10rpx;font-size:24px;font-weight:850}.sub{margin-top:10rpx;color:var(--tm-ink-500);font-size:14px;line-height:1.6}.after-sale{display:flex;align-items:center;width:100%;height:auto;margin:8rpx 0 18rpx;padding:22rpx 24rpx;text-align:left;border:1rpx solid #dce6ff;border-radius:24rpx;background:#eef4ff;color:var(--tm-ink-900);line-height:1.3}.after-sale::after{border:0}.after-sale>text{display:flex;align-items:center;justify-content:center;width:60rpx;height:60rpx;margin-right:18rpx;border-radius:18rpx;background:#fff;color:var(--tm-brand-700);font-weight:850}.after-sale view{flex:1}.after-sale strong,.after-sale small{display:block}.after-sale strong{font-size:15px}.after-sale small{margin-top:5rpx;color:var(--tm-ink-500);font-size:13px}.after-sale b{color:var(--tm-brand-600);font-size:22px}.tabs{width:100%;white-space:nowrap}.tab{display:inline-flex;align-items:center;gap:7rpx;margin-right:12rpx;padding:14rpx 22rpx;border:1rpx solid var(--tm-line);border-radius:999rpx;background:#fff;color:var(--tm-ink-500);font-size:14px}.tab.active{border-color:var(--tm-brand-600);background:var(--tm-brand-600);color:#fff}.tab text{font-size:13px}.card{margin-top:20rpx;padding:28rpx;border:1rpx solid var(--tm-line);border-radius:26rpx;background:#fff;box-shadow:var(--tm-shadow-sm)}.row,.item,.total{display:flex;justify-content:space-between;gap:20rpx}.number{font-size:14px;font-weight:750}.status{padding:6rpx 12rpx;border-radius:10rpx;background:var(--tm-reward-100);color:var(--tm-reward-600);font-size:13px;font-weight:750}.meta{margin-top:10rpx;color:var(--tm-ink-500);font-size:14px}.item{margin-top:20rpx;padding-top:20rpx;border-top:1rpx solid var(--tm-line);font-size:15px}.total{margin-top:22rpx;font-size:17px;font-weight:800}.shipping{margin-top:10rpx;color:var(--tm-ink-500);font-size:14px}.pay{height:76rpx;margin-top:22rpx;border-radius:18rpx;background:var(--tm-brand-600);color:#fff;font-weight:800;line-height:76rpx}.skeleton-card{height:290rpx;margin-top:20rpx;border-radius:26rpx}.empty-card{margin-top:26rpx;padding:70rpx 34rpx;text-align:center;border:1rpx solid var(--tm-line);border-radius:28rpx;background:#fff}.empty-icon{display:flex;align-items:center;justify-content:center;width:88rpx;height:88rpx;margin:0 auto;border-radius:28rpx;background:var(--tm-brand-100);color:var(--tm-brand-700);font-size:19px;font-weight:850}.empty-title{margin-top:22rpx;font-size:17px;font-weight:800}.empty-copy{margin-top:10rpx;color:var(--tm-ink-500);font-size:14px;line-height:1.6}.empty-action{width:260rpx;height:72rpx;margin-top:24rpx;border-radius:18rpx;background:var(--tm-brand-600);color:#fff;font-size:14px;line-height:72rpx}
.after-sale{border-color:#d6e9e7;background:#f2f9f8}
.delivery{margin-top:14rpx;padding:16rpx 18rpx;border-radius:14rpx;background:#f3f7f7;color:var(--tm-ink-500);font-size:13px}.delivery text{display:block}.delivery-title{margin-bottom:6rpx;color:var(--tm-ink-900);font-weight:800}
.card-entry{display:flex;justify-content:space-between;margin-top:20rpx;padding-top:18rpx;border-top:1rpx solid var(--tm-line);color:var(--tm-brand-700);font-size:14px;font-weight:850}
/* V09 campus commerce surface */
.commerce-hero{position:relative;min-height:250rpx;overflow:hidden;margin-bottom:18rpx;border:1rpx solid var(--tm-line);border-radius:var(--tm-radius-lg);background:linear-gradient(135deg,#e7f4ff 0,#e3f9f6 58%,#edf7ff 100%);box-shadow:var(--tm-shadow-sm)}
.commerce-hero-art{position:absolute;right:-16rpx;top:0;width:68%;height:100%;opacity:.84;object-position:right center}.commerce-hero::after{content:'';position:absolute;inset:0;background:linear-gradient(90deg,rgba(231,244,255,.98) 0,rgba(231,244,255,.9) 34%,rgba(231,244,255,.12) 78%,rgba(231,244,255,0) 100%)}
.commerce-hero-copy{position:relative;z-index:1;width:57%;padding:28rpx 24rpx}.commerce-hero-kicker,.commerce-hero-title,.commerce-hero-sub{display:block}.commerce-hero-kicker{color:var(--tm-brand-700);font-size:13px;font-weight:850;letter-spacing:2.4rpx}.commerce-hero-title{margin-top:10rpx;color:var(--tm-ink-900);font-size:22px;font-weight:950;line-height:1.2}.commerce-hero-sub{margin-top:10rpx;color:var(--tm-ink-500);font-size:13px;line-height:1.55}.page > .heading{display:none}
.page{background:linear-gradient(180deg,var(--tm-campus-cyan-soft) 0,var(--tm-canvas) 520rpx);color:var(--tm-ink-900)}
.heading{margin:0 0 16rpx;padding:26rpx 24rpx;border:1rpx solid var(--tm-line);border-radius:var(--tm-radius-lg);background:var(--tm-surface);box-shadow:var(--tm-shadow-sm)}
.eyebrow{color:var(--tm-brand-600)}.sub{color:var(--tm-ink-500);font-weight:500}
.after-sale,.card,.empty-card,.error-state{border:1rpx solid var(--tm-line);box-shadow:var(--tm-shadow-sm);background:var(--tm-surface)}
.after-sale{background:var(--tm-campus-cyan-soft)}.after-sale>text{border:0;background:var(--tm-surface)}
.tab{border:1rpx solid var(--tm-line);color:var(--tm-ink-500);box-shadow:none;background:var(--tm-surface)}
.tab.active{border-color:var(--tm-brand-500);background:var(--tm-brand-600);color:#fff;box-shadow:none}
.status{border:0;background:var(--tm-reward-100);color:var(--tm-reward-600)}
.status.pending{background:var(--tm-reward-100);color:var(--tm-reward-600)}.status.success{background:var(--tm-growth-100);color:var(--tm-growth-700)}.status.danger{background:var(--tm-campus-coral-soft);color:var(--tm-danger)}
.delivery{border:0;background:var(--tm-surface-soft)}
.pay,.empty-action,.error-action{border:1rpx solid transparent;background:var(--tm-brand-600);box-shadow:none}
.empty-icon{border:0;background:var(--tm-brand-100)}
.error-state{margin-top:22rpx;padding:48rpx 28rpx;text-align:center;background:var(--tm-campus-coral-soft)}.error-title,.error-copy{display:block}.error-title{font-size:17px;font-weight:900}.error-copy{margin-top:10rpx;color:var(--tm-ink-500);font-size:13px;line-height:1.5}.error-action{height:68rpx;margin:22rpx auto 0;padding:0 26rpx;border-radius:18rpx;color:#fff;line-height:68rpx}.error-action::after{border:0}
.pay[disabled],.empty-action[disabled],.error-action[disabled]{opacity:.46;filter:saturate(.55)}
.empty-card{padding:72rpx 34rpx 54rpx}.empty-icon{position:relative;width:154rpx;height:118rpx;margin:0 auto;border-radius:34rpx;background:linear-gradient(145deg,#e2f6f8,#edf7ff);font-size:0}.empty-icon::before{content:'';position:absolute;left:28rpx;top:28rpx;width:88rpx;height:56rpx;border:5rpx solid #4bb7cd;border-radius:11rpx 11rpx 18rpx 18rpx;background:#fff;box-sizing:border-box}.empty-icon::after{content:'⌕';position:absolute;right:8rpx;bottom:4rpx;display:flex;align-items:center;justify-content:center;width:48rpx;height:48rpx;border:5rpx solid #1268c9;border-radius:50%;background:#fff;color:#1268c9;font-size:21px;font-weight:900;box-sizing:border-box}
</style>
