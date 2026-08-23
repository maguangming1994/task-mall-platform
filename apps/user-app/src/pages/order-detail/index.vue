<template>
  <view class="page tm-page-shell">
    <view class="commerce-hero" aria-label="订单详情">
      <image class="commerce-hero-art" src="/static/home/hero-campus-reward.png" mode="aspectFill" />
      <view class="commerce-hero-copy">
        <text class="commerce-hero-kicker">ORDER DETAIL</text>
        <text class="commerce-hero-title">订单详情</text>
        <text class="commerce-hero-sub">从支付到履约，商品状态与售后权益持续可追踪</text>
      </view>
    </view>

    <view v-if="loading" class="loading-card tm-panel tm-skeleton" />
    <template v-else-if="order">
      <view class="status-hero tm-panel">
        <view class="status-icon">{{ statusIcon(order.status) }}</view>
        <view class="status-copy"><text>{{ statusText(order.status) }}</text><text>{{ statusDescription(order) }}</text></view>
      </view>

      <view v-if="order.fulfillment_type === 'logistics' && addressText" class="address-card tm-panel">
        <view class="section-icon">址</view><view><text class="section-title">收货信息</text><text class="section-copy">{{ addressText }}</text></view>
      </view>

      <view class="section tm-panel">
        <view class="section-head"><text>商品信息</text><text>{{ order.items.length }} 项</text></view>
        <view v-for="item in order.items" :key="item.id" class="product-row">
          <view class="product-mark">{{ item.product_name.slice(0,1) }}</view>
          <view class="product-main"><text class="product-name">{{ item.product_name }}</text><text class="product-meta">{{ item.sku_name }} · {{ modeText(item.purchase_mode) }} · ×{{ item.quantity }}</text><text class="product-price">{{ amountText(item.paid_cash_amount, item.paid_points) }}</text><text class="after-sale-note">{{ item.after_sale_workflow.summary }}</text></view>
          <button v-if="item.after_sale_workflow.allowed" class="mini-action" @click="applyAfterSale(item)">申请售后</button>
        </view>
      </view>

      <view v-if="order.fulfillments.length" class="section tm-panel">
        <view class="section-head"><text>履约进度</text><text>{{ fulfillmentText(order.fulfillment_type) }}</text></view>
        <view v-for="fulfillment in order.fulfillments" :key="fulfillment.id" class="fulfillment-row">
          <view class="timeline-dot" />
          <view><text class="product-name">{{ fulfillmentStatusText(fulfillment.status) }}</text><text v-if="fulfillment.method === 'logistics'" class="section-copy">{{ fulfillment.carrier_name || fulfillment.carrier_code || '承运商' }} · {{ fulfillment.tracking_no || '待录入单号' }}</text><text v-else-if="fulfillment.method === 'no_logistics'" class="section-copy">{{ fulfillment.no_logistics_reason || '该订单无需物流' }}</text><text v-else class="section-copy">虚拟内容已通过订单安全交付</text></view>
        </view>
      </view>

      <view class="section tm-panel">
        <view class="section-head"><text>费用明细</text><text>运费仅收现金</text></view>
        <view class="kv"><text>商品现金</text><text>¥{{ money(order.items_cash_amount || 0) }}</text></view>
        <view class="kv"><text>商品积分</text><text>{{ order.items_points || 0 }} 积分</text></view>
        <view v-if="order.cash_discount_amount" class="kv discount"><text>优惠券抵扣现金</text><text>-¥{{ money(order.cash_discount_amount) }}</text></view>
        <view v-if="order.points_discount" class="kv discount"><text>优惠券抵扣积分</text><text>-{{ order.points_discount }} 积分</text></view>
        <view class="kv"><text>现金运费</text><text>¥{{ money(order.shipping_amount) }}</text></view>
        <view class="kv total"><text>实付 / 应付</text><text>{{ amountText(order.paid_wallet_amount + order.paid_external_amount || order.payable_cash_amount, order.paid_points || order.payable_points) }}</text></view>
      </view>

      <view class="section tm-panel order-info">
        <view class="section-head"><text>订单信息</text><text>{{ paymentText(order.cash_payment_method) }}</text></view>
        <view class="kv"><text>订单编号</text><text selectable>{{ order.order_no }}</text></view>
        <view class="kv"><text>下单时间</text><text>{{ formatTime(order.created_at) }}</text></view>
        <view v-if="order.auto_complete_at" class="kv"><text>自动完成</text><text>{{ formatTime(order.auto_complete_at) }}</text></view>
      </view>

      <view class="bottom-actions"><button class="secondary" @click="goOrders">全部订单</button><button v-if="order.status === 'pending_payment'" class="primary" :loading="working" :disabled="working" @click="payOrder">去支付</button><button v-else-if="order.status === 'fulfilled'" class="primary" :loading="working" @click="confirmOrder">确认完成</button><button v-else class="primary" @click="goProducts">继续逛逛</button></view>
    </template>
    <view v-else-if="loadError" class="empty-card error-card tm-panel"><text>订单详情加载失败</text><small>{{ loadError }}</small><button class="primary" :loading="loading" :disabled="loading" @click="load">重新加载</button><button class="secondary" @click="goOrders">返回订单列表</button></view>
    <view v-else class="empty-card tm-panel"><text>没有找到该订单</text><button class="primary" @click="goOrders">返回订单列表</button></view>
  </view>
</template>

<script setup lang="ts">
import { onLoad } from '@dcloudio/uni-app'
import { computed, ref } from 'vue'
import { completeOrder, createPayment, getOrder, getPayment, type Order, type OrderItem, type Payment } from '@/api/orders'
import { useAuthStore } from '@/stores/auth'
import { redirectToLogin } from '@/utils/authRedirect'

const auth = useAuthStore()
const order = ref<Order | null>(null)
const loading = ref(true)
const loadError = ref('')
const orderId = ref('')
const working = ref(false)
const resumePaymentId = ref('')
const resumeOrderId = ref('')
const pendingPaymentStorageKey = 'task_mall_pending_payment'
const addressText = computed(() => {
  const value = order.value?.address
  if (!value) return ''
  return [value.recipient_name, value.phone, value.province, value.city, value.district, value.detail].filter(Boolean).map(String).join(' ')
})

onLoad((query) => {
  if (!auth.accessToken) return redirectToLogin()
  orderId.value = typeof query?.id === 'string' ? query.id : ''
  resumePaymentId.value = typeof query?.resumePayment === 'string' ? query.resumePayment : typeof query?.paymentId === 'string' ? query.paymentId : ''
  resumeOrderId.value = typeof query?.resumeOrder === 'string' ? query.resumeOrder : resumePaymentId.value ? orderId.value : ''
  if (resumePaymentId.value && resumeOrderId.value) uni.setStorageSync(pendingPaymentStorageKey, { paymentId: resumePaymentId.value, orderId: resumeOrderId.value })
  void (async () => {
    await load()
    if (resumePaymentId.value && resumeOrderId.value) await pollPayment(resumePaymentId.value, resumeOrderId.value)
  })()
})

async function load() {
  if (!auth.accessToken) return
  loading.value = true
  loadError.value = ''
  order.value = null
  if (!orderId.value) { loading.value = false; loadError.value = '缺少订单编号。'; return }
  try { order.value = await getOrder(auth.accessToken, orderId.value) }
  catch (error) { loadError.value = error instanceof Error ? error.message : '订单加载失败，请稍后重试'; uni.showToast({ title: loadError.value, icon: 'none' }) }
  finally { loading.value = false }
}

async function payOrder() {
  if (!auth.accessToken || !order.value || order.value.status !== 'pending_payment' || working.value) return
  working.value = true
  try {
    const payment = await createPayment(auth.accessToken, order.value.id, `payment-${order.value.id}-${Date.now()}`)
    if (payment.status === 'succeeded') {
      order.value = await getOrder(auth.accessToken, order.value.id)
      return openPaymentResult(payment)
    }
    rememberPendingPayment(payment)
    const redirected = await invokeWechat(payment)
    if (!redirected) await pollPayment(payment.id, payment.order_id)
  } catch (error) {
    const message = error instanceof Error ? error.message : '支付发起失败'
    if (order.value.cash_payment_method === 'wallet' && message.includes('余额不足')) {
      const result = await uni.showModal({ title: '余额不足', content: '当前余额无法支付该订单，是否先去充值？', confirmText: '去充值' })
      if (result.confirm) uni.navigateTo({ url: '/pages/wallet-recharge/index' })
    } else uni.showToast({ title: message, icon: 'none' })
  } finally { working.value = false }
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

function rememberPendingPayment(payment: Payment) { uni.setStorageSync(pendingPaymentStorageKey, { paymentId: payment.id, orderId: payment.order_id }) }
function clearPendingPayment(orderId: string) {
  const stored = uni.getStorageSync(pendingPaymentStorageKey) as { orderId?: string } | null
  if (stored?.orderId === orderId) uni.removeStorageSync(pendingPaymentStorageKey)
}
async function pollPayment(paymentId: string, targetOrderId: string) {
  if (!auth.accessToken) return
  for (let attempt = 0; attempt < 12; attempt += 1) {
    try {
      const payment = await getPayment(auth.accessToken, paymentId)
      order.value = await getOrder(auth.accessToken, targetOrderId)
      if (payment.status === 'succeeded') {
        clearPendingPayment(targetOrderId)
        return openPaymentResult(payment)
      }
      if (['failed', 'closed', 'cancelled'].includes(payment.status)) {
        clearPendingPayment(targetOrderId)
        uni.showToast({ title: payment.status === 'failed' ? '支付未成功，请重新发起' : '支付单已关闭', icon: 'none' })
        return
      }
    } catch {
      // 临时网络失败继续查询，避免用户重复创建支付单。
    }
    if (attempt < 11) await new Promise((resolve) => setTimeout(resolve, 1500))
  }
  uni.showToast({ title: '支付结果仍在确认中，请稍后刷新', icon: 'none' })
}

function money(value: number) { return (value / 100).toFixed(2) }
function amountText(cash: number, points: number) { return [cash ? `¥${money(cash)}` : '', points ? `${points} 积分` : ''].filter(Boolean).join(' + ') || '无需支付' }
function formatTime(value: string | null) { return value ? new Date(value).toLocaleString('zh-CN', { hour12: false }) : '—' }
function statusText(value: string) { return ({ pending_payment:'等待支付',pending_fulfillment:'商家准备中',fulfilled:'等待确认完成',completed:'订单已完成',partially_refunded:'部分退款完成',refunded:'退款完成',cancelled:'订单已取消',closed:'订单已关闭' } as Record<string,string>)[value] || '订单处理中' }
function statusIcon(value: string) { return ({ pending_payment:'付',pending_fulfillment:'备',fulfilled:'收',completed:'✓',partially_refunded:'退',refunded:'退',cancelled:'停',closed:'关' } as Record<string,string>)[value] || '单' }
function statusDescription(value: Order) { if (value.status === 'pending_payment') return `请在有效期内完成${paymentText(value.cash_payment_method)}`; if (value.status === 'pending_fulfillment') return value.fulfillment_type === 'virtual_delivery' ? '虚拟内容正在准备交付' : '订单已支付，正在准备履约'; if (value.status === 'fulfilled') return '履约已完成，请核对后确认'; if (value.status === 'completed') return '交易流程已完成，感谢你的支持'; return '订单状态和资产变化均可追踪' }
function fulfillmentText(value: string) { return ({logistics:'物流配送',no_logistics:'无需物流',virtual_delivery:'虚拟交付'} as Record<string,string>)[value] || '履约方式待确认' }
function fulfillmentStatusText(value: string) { return ({pending:'待履约',fulfilled:'已履约',shipped:'运输中',delivered:'已送达',completed:'已完成'} as Record<string,string>)[value] || '履约处理中' }
function paymentText(value: string) { return ({wallet:'余额全额支付',wechat:'微信现金支付',none:'无需现金支付'} as Record<string,string>)[value] || '支付方式待确认' }
function modeText(value: string) { return ({cash:'现金购买',points:'积分兑换',cash_points:'现金 + 积分'} as Record<string,string>)[value] || '购买方式待确认' }
function applyAfterSale(item: OrderItem) { if (order.value && item.after_sale_workflow.recommended_service_type) uni.navigateTo({ url: `/pages/after-sale-apply/index?orderId=${encodeURIComponent(order.value.id)}&itemId=${encodeURIComponent(item.id)}&type=${encodeURIComponent(item.after_sale_workflow.recommended_service_type)}` }) }
async function confirmOrder() { if (!auth.accessToken || !order.value) return; working.value = true; try { await completeOrder(auth.accessToken, order.value.id); order.value = await getOrder(auth.accessToken, order.value.id); uni.showToast({ title:'订单已完成', icon:'success' }) } catch (error) { uni.showToast({ title:error instanceof Error ? error.message : '确认失败', icon:'none' }) } finally { working.value = false } }
function goOrders() { uni.redirectTo({ url:'/pages/orders/index' }) }
function goProducts() { uni.reLaunch({ url:'/pages/products/index' }) }
function openPaymentResult(payment: Payment) { uni.navigateTo({ url: `/pages/payment-result/index?orderId=${encodeURIComponent(payment.order_id)}&paymentId=${encodeURIComponent(payment.id)}&status=success` }) }
</script>

<style scoped>
.commerce-hero{position:relative;min-height:220rpx;overflow:hidden;margin-bottom:18rpx;border:1rpx solid var(--tm-line);border-radius:var(--tm-radius-lg);background:linear-gradient(135deg,#e7f4ff 0,#e3f9f6 58%,#edf7ff 100%);box-shadow:var(--tm-shadow-sm)}
.commerce-hero-art{position:absolute;right:-16rpx;top:0;width:68%;height:100%;opacity:.78;object-position:right center}.commerce-hero::after{content:'';position:absolute;inset:0;background:linear-gradient(90deg,rgba(231,244,255,.98) 0,rgba(231,244,255,.9) 34%,rgba(231,244,255,.12) 78%,rgba(231,244,255,0) 100%)}
.commerce-hero-copy{position:relative;z-index:1;width:57%;padding:26rpx 24rpx}.commerce-hero-kicker,.commerce-hero-title,.commerce-hero-sub{display:block}.commerce-hero-kicker{color:var(--tm-brand-700);font-size:19rpx;font-weight:850;letter-spacing:2.4rpx}.commerce-hero-title{margin-top:10rpx;color:var(--tm-ink-900);font-size:36rpx;font-weight:950;line-height:1.2}.commerce-hero-sub{margin-top:10rpx;color:var(--tm-ink-500);font-size:18rpx;line-height:1.55}

.page{padding-bottom:150rpx;background:linear-gradient(180deg,var(--tm-campus-cyan-soft),var(--tm-canvas) 430rpx)}.loading-card{height:360rpx}.status-hero{display:flex;align-items:center;gap:22rpx;padding:30rpx;background:linear-gradient(135deg,#fff,var(--tm-campus-yellow-soft))}.status-icon,.section-icon,.product-mark{display:flex;align-items:center;justify-content:center;flex:none}.status-icon{width:88rpx;height:88rpx;border-radius:27rpx;background:var(--tm-brand-600);box-shadow:0 8rpx 20rpx rgba(23,105,255,.22);color:#fff;font-size:35rpx;font-weight:900}.status-copy text{display:block}.status-copy text:first-child{font-size:31rpx;font-weight:900}.status-copy text:last-child{margin-top:7rpx;color:var(--tm-ink-500);font-size:21rpx}.address-card{display:flex;gap:18rpx;margin-top:18rpx;padding:24rpx}.section-icon{width:60rpx;height:60rpx;border-radius:19rpx;background:var(--tm-campus-lime-soft);color:#257c1c;font-weight:900}.section-title,.section-copy{display:block}.section-title{font-size:24rpx;font-weight:900}.section-copy{margin-top:6rpx;color:var(--tm-ink-500);font-size:20rpx;line-height:1.55}.section{margin-top:18rpx;padding:26rpx}.section-head,.kv{display:flex;align-items:center;justify-content:space-between;gap:20rpx}.section-head{padding-bottom:18rpx;border-bottom:1rpx solid var(--tm-line);font-size:25rpx;font-weight:900}.section-head text:last-child{color:var(--tm-ink-500);font-size:18rpx;font-weight:650}.product-row{display:flex;align-items:center;gap:17rpx;padding:22rpx 0;border-bottom:1rpx solid var(--tm-line)}.product-row:last-child{border-bottom:0}.product-mark{width:88rpx;height:88rpx;border-radius:23rpx;background:var(--tm-campus-yellow-soft);color:#8c6410;font-size:30rpx;font-weight:900}.product-main{min-width:0;flex:1}.product-name,.product-meta,.product-price{display:block}.product-name{font-size:23rpx;font-weight:900}.product-meta{margin-top:6rpx;color:var(--tm-ink-500);font-size:18rpx}.product-price{margin-top:8rpx;color:var(--tm-reward-600);font-size:21rpx;font-weight:900}.mini-action{height:54rpx;margin:0;padding:0 17rpx;border:1rpx solid var(--tm-brand-600);border-radius:999rpx;background:#fff;color:var(--tm-brand-700);font-size:18rpx;line-height:52rpx}.fulfillment-row{display:flex;gap:17rpx;padding-top:22rpx}.timeline-dot{width:18rpx;height:18rpx;margin-top:9rpx;border:5rpx solid #fff;border-radius:50%;background:var(--tm-campus-cyan);box-shadow:0 0 0 2rpx var(--tm-campus-cyan)}.kv{padding:13rpx 0;color:var(--tm-ink-700);font-size:21rpx}.kv text:last-child{text-align:right}.kv.discount{color:#bf5a13}.kv.total{margin-top:8rpx;padding-top:20rpx;border-top:1rpx solid var(--tm-line);color:var(--tm-ink-900);font-size:25rpx;font-weight:900}.order-info{margin-bottom:12rpx}.bottom-actions{position:fixed;z-index:20;right:0;bottom:0;left:0;display:grid;grid-template-columns:1fr 1.6fr;gap:14rpx;padding:18rpx 28rpx calc(18rpx + env(safe-area-inset-bottom));border-top:1rpx solid var(--tm-line);background:rgba(255,253,248,.96);backdrop-filter:blur(16px)}.bottom-actions button,.empty-card button{height:78rpx;margin:0;border-radius:20rpx;font-size:23rpx;font-weight:900;line-height:76rpx}.secondary{border:1rpx solid var(--tm-line);background:#fff;color:var(--tm-ink-900)}.primary{background:var(--tm-brand-600);color:#fff}.empty-card{padding:80rpx 30rpx;text-align:center}.empty-card text{display:block;margin-bottom:24rpx;font-size:26rpx;font-weight:900}.empty-card .primary{width:280rpx;margin:0 auto}.tm-panel{border-color:rgba(7,26,51,.09);border-radius:28rpx;box-shadow:0 12rpx 34rpx rgba(7,26,51,.07)}
.after-sale-note{display:block;margin-top:8rpx;color:var(--tm-ink-500);font-size:17rpx;line-height:1.45}
.page{background:linear-gradient(180deg,var(--tm-campus-cyan-soft) 0,var(--tm-canvas) 560rpx);color:var(--tm-ink-900)}
.tm-panel,.empty-card{border:1rpx solid var(--tm-line);border-radius:var(--tm-radius-lg);box-shadow:var(--tm-shadow-sm);background:var(--tm-surface)}
.status-hero{border-color:rgba(47,103,223,.12);background:linear-gradient(145deg,var(--tm-brand-700),var(--tm-brand-500));box-shadow:var(--tm-shadow-card)}
.address-card{background:var(--tm-surface)}.section{background:var(--tm-surface)}.product-mark{background:var(--tm-reward-100);color:var(--tm-reward-600)}
.mini-action{border:1rpx solid var(--tm-brand-500);background:var(--tm-brand-50);color:var(--tm-brand-700)}
.timeline-dot{border-color:var(--tm-surface);background:var(--tm-campus-cyan);box-shadow:0 0 0 2rpx var(--tm-campus-cyan)}
.bottom-actions{z-index:var(--tm-z-bottom-action);border-top:1rpx solid var(--tm-line);background:rgba(255,255,255,.96);box-shadow:0 -8rpx 24rpx rgba(7,26,51,.06);padding-bottom:calc(18rpx + env(safe-area-inset-bottom))}
.bottom-actions button{border:1rpx solid transparent;box-shadow:none}.secondary{border-color:var(--tm-line);background:var(--tm-surface);color:var(--tm-ink-900)}.primary{background:var(--tm-brand-600);color:#fff}.primary[disabled],.mini-action[disabled]{opacity:.46;filter:saturate(.55)}
.error-card{border-color:rgba(209,67,67,.22);background:var(--tm-campus-coral-soft)}.error-card small{display:block;margin:10rpx 0 20rpx;color:var(--tm-ink-500);font-size:19rpx}
</style>
