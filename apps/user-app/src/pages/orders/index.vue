<template>
  <view class="page">
    <ModulePlaceholder v-if="modules.resolved && !moduleEnabled" code="module_mall_trade" />
    <view v-else-if="!modules.resolved" class="mp-skeleton">
      <view class="tm-skeleton" style="height: 200rpx; border-radius: 24rpx; margin: 24rpx 28rpx;" />
      <view class="tm-skeleton" style="height: 320rpx; border-radius: 24rpx; margin: 20rpx 28rpx;" />
    </view>
    <template v-else>
    <view class="subnav"><text class="nav-title">订单与任务</text></view>

    <scroll-view class="tabs" scroll-x>
      <view v-for="tab in tabs" :key="tab.value" :class="['tab', { active: activeStatus === tab.value }]" @click="activeStatus = tab.value">
        {{ tab.label }}<text v-if="tabCount(tab.value)" class="tab-count">{{ tabCount(tab.value) }}</text>
      </view>
    </scroll-view>

    <button class="after-sale" @click="openAfterSales">
      <text class="as-ic">售</text>
      <view class="as-main"><text class="as-title">售后服务</text><text class="as-sub">退款、退货与换货进度</text></view>
      <text class="as-arrow">›</text>
    </button>

    <view v-if="loading" class="skeleton-list"><view v-for="index in 3" :key="index" class="skeleton-card tm-skeleton" /></view>
    <view v-else-if="loadError" class="state-card error">
      <text class="state-title">订单暂时无法加载</text>
      <text class="state-copy">{{ loadError }}</text>
      <button class="btn-main" @click="reloadOrders">重新加载</button>
    </view>

    <view v-for="order in filteredOrders" :key="order.id" class="card" @click="openOrder(order)">
      <view class="row"><text class="number">{{ order.order_no }}</text><text class="status-tag" :class="{ pending: order.status === 'pending_payment', success: ['fulfilled', 'completed'].includes(order.status), danger: ['cancelled', 'closed', 'refunded'].includes(order.status) }">{{ statusText(order.status) }}</text></view>
      <text class="meta">{{ fulfillmentText(order.fulfillment_type) }} · {{ paymentText(order.cash_payment_method) }}</text>
      <view v-for="item in order.items" :key="item.id" class="item-row">
        <view class="item-mark">{{ item.product_name.slice(0, 1) }}</view>
        <view class="item-main">
          <text class="item-name">{{ item.product_name }}</text>
          <text class="item-sku">{{ item.sku_name }} · {{ modeText(item.purchase_mode) }}</text>
          <text class="item-qty">×{{ item.quantity }}</text>
        </view>
      </view>
      <view class="total"><text>应付</text><text class="total-val">{{ amountText(order.payable_cash_amount, order.payable_points) }}</text></view>
      <text v-if="order.shipping_amount" class="shipping">含现金运费 ¥{{ money(order.shipping_amount) }}</text>
      <view v-for="fulfillment in order.fulfillments || []" :key="fulfillment.id" class="delivery">
        <text class="delivery-title">{{ fulfillmentText(fulfillment.method) }}</text>
        <text v-if="fulfillment.method === 'logistics'">{{ fulfillment.carrier_name || fulfillment.carrier_code || '承运商' }} · {{ fulfillment.tracking_no }}</text>
        <text v-else-if="fulfillment.method === 'no_logistics'">无需物流原因：{{ fulfillment.no_logistics_reason }}</text>
        <text v-else>虚拟交付内容：{{ deliveryText(fulfillment.virtual_delivery) }}</text>
      </view>
      <view class="card-entry"><text>查看订单详情</text><text>›</text></view>
      <button v-if="order.status === 'pending_payment'" class="pay" :loading="payingOrderId === order.id || isPaymentPolling(order.id)" :disabled="!!payingOrderId || isPaymentPolling(order.id)" @click.stop="pay(order)">{{ isPaymentPolling(order.id) ? '支付结果确认中…' : (order.cash_payment_method === 'wallet' ? '余额全额支付' : '在线支付') }}</button>
      <button v-if="order.status === 'fulfilled'" class="pay" :loading="payingOrderId === order.id" @click.stop="confirmComplete(order)">确认完成</button>
    </view>

    <view v-if="!loading && !loadError && !filteredOrders.length" class="state-card">
      <view class="state-icon">单</view>
      <text class="state-title">{{ activeStatus === 'all' ? '还没有订单' : '当前状态暂无订单' }}</text>
      <text class="state-copy">商城支持现金、积分和现金＋积分三种购买方式</text>
      <button class="btn-main" @click="openProducts">去逛商城</button>
    </view>
    </template>
    <AppTabBar current-target="account" />
  </view>
</template>

<script setup lang="ts">
import { onLoad, onShow } from '@dcloudio/uni-app'
import { computed, ref } from 'vue'
import { completeOrder, createPayment, getPayment, listAllOrders, type Order, type Payment } from '@/api/orders'
import AppTabBar from '@/components/AppTabBar.vue'
import { useAuthStore } from '@/stores/auth'
import { navigateToLogin } from '@/utils/authRedirect'
import ModulePlaceholder from '@/components/ModulePlaceholder.vue'
import { useModulesStore } from '@/stores/modules'
import { invokeWechatJsapiPayment } from '@/platform/wechatPay'

const modules = useModulesStore()
const moduleEnabled = computed(() => modules.isEnabled('module_mall_trade'))

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
  await modules.ensureReady()
  if (!moduleEnabled.value) return
  if (!auth.isAuthenticated) return navigateToLogin()
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
  if (!auth.isAuthenticated) return navigateToLogin()
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
function paymentText(method: string) { return ({ wallet: '余额全额', wechat: '在线支付', none: '无现金支付' } as Record<string, string>)[method] || method }
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
    // 取消或失败时清除待支付标记，避免按钮停在“支付确认中”无法重试。
    clearPendingPayment(order.id)
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
  // #ifdef H5
  // 公众号内：kind 为 jsapi，先唤起微信收银台，再由下方轮询确认到账。
  if (payload.kind === 'jsapi') await invokeWechatJsapiPayment(payload)
  // #endif
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
async function refreshOrders() { if (auth.accessToken) orders.value = await listAllOrders(auth.accessToken) }
async function confirmComplete(order: Order) { if (!auth.accessToken) return; payingOrderId.value = order.id; try { await completeOrder(auth.accessToken, order.id); await refreshOrders(); uni.showToast({ title: '订单已完成', icon: 'success' }) } catch (error) { uni.showToast({ title: error instanceof Error ? error.message : '确认失败', icon: 'none' }) } finally { payingOrderId.value = '' } }
function openAfterSales() { uni.navigateTo({ url: '/pages/after-sales/index' }) }
function openProducts() { uni.reLaunch({ url: '/pages/products/index' }) }
function openOrder(order: Pick<Order, 'id'>) { uni.navigateTo({ url: `/pages/order-detail/index?id=${encodeURIComponent(order.id)}` }) }
function openPaymentResult(orderId: string, paymentId: string) { uni.navigateTo({ url: `/pages/payment-result/index?orderId=${encodeURIComponent(orderId)}&paymentId=${encodeURIComponent(paymentId)}&status=success` }) }
function tabCount(status: string) { return status === 'all' ? visibleOrders.value.length : visibleOrders.value.filter(item => item.status === status).length }
</script>

<style scoped>
.page{min-height:100vh;padding-bottom:120rpx;background:#F4F6F4;color:#191B1F}
.subnav{display:flex;align-items:center;justify-content:center;height:88rpx;background:#fff}
.nav-title{font-size:32rpx;font-weight:700}
.tabs{display:flex;white-space:nowrap;border-bottom:1rpx solid #EDEFEF;background:#fff;padding:0 16rpx}
.tab{position:relative;display:inline-flex;align-items:center;gap:8rpx;padding:24rpx 24rpx;color:#767C85;font-size:26rpx}
.tab.active{color:#08A152;font-size:28rpx;font-weight:800}
.tab.active::after{position:absolute;right:24rpx;bottom:0;left:24rpx;height:6rpx;border-radius:3rpx;background:linear-gradient(135deg,#12D978,#08A152);content:''}
.tab-count{color:#AEB3BB;font-size:22rpx}
.tab.active .tab-count{color:#08A152}
.after-sale{display:flex;align-items:center;width:auto;margin:20rpx 24rpx 0;padding:24rpx 28rpx;border-radius:32rpx;background:#fff;text-align:left}
.after-sale::after{border:0}
.as-ic{display:flex;align-items:center;justify-content:center;width:72rpx;height:72rpx;flex:none;border-radius:22rpx;background:#E7F9EF;color:#08A152;font-size:28rpx;font-weight:800}
.as-main{min-width:0;flex:1;margin-left:22rpx}
.as-title{display:block;font-size:28rpx;font-weight:700}
.as-sub{display:block;margin-top:6rpx;color:#767C85;font-size:24rpx}
.as-arrow{color:#AEB3BB;font-size:34rpx}
.skeleton-list{margin:0 24rpx}
.skeleton-card{height:320rpx;margin-top:20rpx;border-radius:32rpx}
.card{margin:20rpx 24rpx 0;padding:28rpx;border-radius:32rpx;background:#fff;box-shadow:0 2rpx 4rpx rgba(23,26,23,.03),0 12rpx 40rpx rgba(23,26,23,.05)}
.row{display:flex;align-items:center;justify-content:space-between;gap:20rpx}
.number{font-size:26rpx;font-weight:700}
.status-tag{display:inline-flex;align-items:center;height:40rpx;padding:0 16rpx;border-radius:12rpx;background:#F2F4F3;color:#767C85;font-size:22rpx}
.status-tag.pending{background:#FFF2E5;color:#F2620A}
.status-tag.success{background:#E7F9EF;color:#08A152}
.status-tag.danger{background:#FFEEEA;color:#FF3B30}
.meta{display:block;margin-top:10rpx;color:#AEB3BB;font-size:24rpx}
.item-row{display:flex;gap:20rpx;margin-top:22rpx;padding-top:22rpx;border-top:1rpx solid #F5F6F6}
.item-mark{display:flex;align-items:center;justify-content:center;width:132rpx;height:132rpx;flex:none;border-radius:20rpx;background:#E7F9EF;color:#08A152;font-size:40rpx;font-weight:900}
.item-main{min-width:0;flex:1}
.item-name{display:block;font-size:26rpx;font-weight:600;line-height:1.45}
.item-sku{display:block;margin-top:8rpx;color:#AEB3BB;font-size:22rpx}
.item-qty{display:block;margin-top:8rpx;color:#767C85;font-size:22rpx}
.total{display:flex;align-items:baseline;justify-content:space-between;margin-top:22rpx;font-size:26rpx;color:#767C85}
.total-val{color:#FF3B30;font-size:32rpx;font-weight:800}
.shipping{display:block;margin-top:10rpx;color:#AEB3BB;font-size:24rpx}
.delivery{margin-top:16rpx;padding:20rpx 22rpx;border-radius:20rpx;background:#F7F9F8;color:#767C85;font-size:24rpx}
.delivery text{display:block}
.delivery-title{margin-bottom:6rpx;color:#191B1F;font-weight:700}
.card-entry{display:flex;justify-content:space-between;margin-top:20rpx;padding-top:20rpx;border-top:1rpx solid #F5F6F6;color:#08A152;font-size:26rpx;font-weight:700}
.pay{height:80rpx;margin-top:20rpx;border-radius:999rpx;background:linear-gradient(135deg,#12D978,#08A152);color:#fff;font-size:28rpx;font-weight:700;line-height:80rpx;box-shadow:0 12rpx 28rpx rgba(11,191,99,.3)}
.pay::after{border:0}
.pay[disabled]{opacity:.5}
.state-card{margin:20rpx 24rpx 0;padding:80rpx 40rpx;border-radius:32rpx;background:#fff;text-align:center}
.state-icon{display:flex;align-items:center;justify-content:center;width:120rpx;height:120rpx;margin:0 auto;border-radius:32rpx;background:#E7F9EF;color:#08A152;font-size:34rpx;font-weight:800}
.state-title{display:block;margin-top:24rpx;font-size:30rpx;font-weight:700}
.state-copy{display:block;margin:12rpx auto 0;max-width:560rpx;color:#767C85;font-size:24rpx;line-height:1.6}
.state-card.error .state-title{color:#FF3B30}
.btn-main{height:80rpx;margin:32rpx auto 0;padding:0 48rpx;border-radius:999rpx;background:linear-gradient(135deg,#12D978,#08A152);color:#fff;font-size:28rpx;font-weight:700;line-height:80rpx}
.btn-main::after{border:0}
</style>
