<template>
  <view class="page tm-page-shell">
    <ModulePlaceholder v-if="modules.resolved && !moduleEnabled" code="module_mall_trade" />
    <view v-else-if="!modules.resolved" class="mp-skeleton">
      <view class="tm-skeleton" style="height: 200rpx; border-radius: 24rpx; margin: 24rpx 28rpx;" />
      <view class="tm-skeleton" style="height: 320rpx; border-radius: 24rpx; margin: 20rpx 28rpx;" />
    </view>
    <template v-else>
    <view class="commerce-hero" aria-label="支付结果">
      <image class="commerce-hero-art" src="/static/home/hero-campus-reward.png" mode="aspectFill" />
      <view class="commerce-hero-copy">
        <text class="commerce-hero-kicker">PAYMENT RESULT</text>
        <text class="commerce-hero-title">支付结果</text>
        <text class="commerce-hero-sub">支付状态、订单编号与资产变动均可追踪</text>
      </view>
    </view>

    <view v-if="loading" class="result-state tm-panel"><view class="result-spinner tm-skeleton" /><text>正在同步支付结果</text><small>请稍候，正在获取订单状态。</small></view>
    <view v-else-if="loadError" class="result-state error-state tm-panel"><text class="result-state-title">订单信息加载失败</text><small>{{ loadError }}</small><button class="primary" :loading="loading" :disabled="loading" @click="loadOrder">重新加载</button><button class="secondary" :disabled="!orderIds.length" @click="goOrderEntry">返回订单</button></view>
    <view v-else class="result-card tm-panel">
      <view class="success-mark" :class="{ failure: !isSuccess }">{{ isSuccess ? '✓' : '!' }}</view>
      <text class="result-title">{{ title }}</text>
      <text class="result-copy">{{ copy }}</text>
      <text v-if="orders.length" class="result-amount">{{ totalAmountText }}</text>
    </view>
    <view v-if="!loading && !loadError && orders.length" class="detail-card tm-panel">
      <view v-for="item in orders" :key="item.id" class="order-result">
        <view class="kv"><text>支付方式</text><text>{{ paymentText(item.cash_payment_method) }}</text></view>
        <view class="kv"><text>订单编号</text><text selectable>{{ item.order_no }}</text></view>
        <view class="kv"><text>现金运费</text><text>¥{{ money(item.shipping_amount) }}</text></view>
        <view class="kv"><text>当前状态</text><text>{{ statusText(item.status) }}</text></view>
      </view>
    </view>
    <view v-if="!loading && !loadError && hasPoints" class="asset-note tm-panel"><view class="note-icon">积</view><view><text>积分变动已记入资产流水</text><text>退款时会根据实际退回时间重新计算积分有效期。</text></view></view>
    <view v-if="!loading && !loadError" class="actions"><button class="secondary" @click="goHome">返回首页</button><button class="primary" :disabled="!orderIds.length" @click="goOrderEntry">{{ isSuccess ? '查看订单' : resultStatus === 'pending' ? '返回订单继续确认' : '返回订单重试支付' }}</button></view>
    </template>
  </view>
</template>

<script setup lang="ts">
import { onLoad } from '@dcloudio/uni-app'
import { computed, ref } from 'vue'
import { getOrder, getPayment, type Order, type Payment } from '@/api/orders'
import { useAuthStore } from '@/stores/auth'
import ModulePlaceholder from '@/components/ModulePlaceholder.vue'
import { useModulesStore } from '@/stores/modules'

const modules = useModulesStore()
const moduleEnabled = computed(() => modules.isEnabled('module_mall_trade'))

const auth = useAuthStore()
const orders = ref<Order[]>([])
const orderId = ref('')
const orderIds = ref<string[]>([])
const paymentId = ref('')
const loading = ref(false)
const loadError = ref('')
const noCashPayment = ref(false)
const resultStatus = ref<'success' | 'failed' | 'cancelled' | 'pending'>('success')
const isSuccess = computed(() => resultStatus.value === 'success')
const hasPoints = computed(() => orders.value.some((item) => item.payable_points > 0))
const totalAmountText = computed(() => amountText(
  orders.value.reduce((sum, item) => sum + item.payable_cash_amount, 0),
  orders.value.reduce((sum, item) => sum + item.payable_points, 0),
))
const title = computed(() => !isSuccess.value ? (resultStatus.value === 'cancelled' ? '支付已取消' : '支付未完成') : (noCashPayment.value ? '兑换成功' : '支付成功'))
const copy = computed(() => !isSuccess.value ? (resultStatus.value === 'pending' ? '支付结果仍在确认，订单已保留，可返回订单继续查询。' : '订单仍保留在待支付列表，可返回订单页重新发起支付。') : orders.value.some((item) => item.fulfillment_type === 'virtual_delivery') ? '虚拟商品正在准备交付' : orders.value.every((item) => item.fulfillment_type === 'no_logistics') ? '订单已创建，无需等待物流' : '订单已支付，商家正在准备发货')

onLoad(async (query) => {
  const rawOrderIds = typeof query?.orderIds === 'string' ? query.orderIds : typeof query?.orderId === 'string' ? query.orderId : ''
  orderIds.value = decodeURIComponent(rawOrderIds).split(',').filter(Boolean)
  orderId.value = orderIds.value[0] || ''
  paymentId.value = typeof query?.paymentId === 'string' ? query.paymentId : ''
  noCashPayment.value = query?.payment === 'none'
  const status = typeof query?.status === 'string' ? query.status : ''
  resultStatus.value = status === 'failed' || status === 'cancelled' || status === 'pending' ? status : 'success'
  await modules.ensureReady()
  if (!moduleEnabled.value) return
  void loadOrder()
})

async function loadOrder() {
  if (!auth.accessToken || !orderIds.value.length) { loading.value = false; loadError.value = '缺少订单编号。'; return }
  loading.value = true
  loadError.value = ''
  orders.value = []
  try {
    let payment: Payment | null = null
    for (let attempt = 0; attempt < (paymentId.value ? 8 : 1); attempt += 1) {
      orders.value = await Promise.all(orderIds.value.map((id) => getOrder(auth.accessToken as string, id)))
      if (paymentId.value) {
        payment = await getPayment(auth.accessToken, paymentId.value)
        if (payment.order_id !== orderId.value) throw new Error('支付单与订单不匹配，未能确认支付结果。')
      }
      const resolved = resolveResult(payment)
      resultStatus.value = resolved
      if (resolved !== 'pending' || attempt === 7) break
      await new Promise((resolve) => setTimeout(resolve, 1500))
    }
  }
  catch (error) { orders.value = []; loadError.value = error instanceof Error ? error.message : '订单信息加载失败，请稍后重试'; uni.showToast({ title: loadError.value, icon:'none' }) }
  finally { loading.value = false }
}

function resolveResult(payment: Payment | null): 'success' | 'failed' | 'cancelled' | 'pending' {
  if (payment) {
    if (payment.status === 'succeeded') return orders.value.every((item) => isSettledOrderStatus(item.status)) ? 'success' : orders.value.some((item) => ['cancelled', 'closed'].includes(item.status)) ? 'failed' : 'pending'
    if (payment.status === 'cancelled' || payment.status === 'closed') return 'cancelled'
    if (payment.status === 'failed') return 'failed'
    if (orders.value.every((item) => isSettledOrderStatus(item.status))) return 'success'
    return 'pending'
  }
  if (orders.value.every((item) => isSettledOrderStatus(item.status))) return 'success'
  if (orders.value.some((item) => ['cancelled', 'closed'].includes(item.status))) return 'cancelled'
  if (resultStatus.value === 'cancelled') return 'cancelled'
  if (resultStatus.value === 'failed') return 'failed'
  return 'pending'
}
function isSettledOrderStatus(status: string) { return ['pending_fulfillment', 'fulfilled', 'completed', 'after_sale', 'partially_refunded', 'refunded'].includes(status) }

function money(value:number){return (value/100).toFixed(2)}
function amountText(cash:number,points:number){return [cash?`¥${money(cash)}`:'',points?`${points} 积分`:''].filter(Boolean).join(' + ')||'无需支付'}
function paymentText(value:string){return ({wallet:'余额全额支付',wechat:'微信现金支付',none:'积分兑换 / 无现金支付'} as Record<string,string>)[value]||'支付方式待确认'}
function statusText(value:string){return ({pending_payment:'待支付',pending_fulfillment:'待履约',fulfilled:'待确认',completed:'已完成',partially_refunded:'部分退款',refunded:'已退款',cancelled:'已取消'} as Record<string,string>)[value]||'处理中'}
function goOrderEntry(){
  if (orderIds.value.length > 1) return uni.redirectTo({ url: `/pages/orders/index?ids=${encodeURIComponent(orderIds.value.join(','))}` })
  if (orderId.value) uni.redirectTo({url:`/pages/order-detail/index?id=${encodeURIComponent(orderId.value)}`})
}
function goHome(){uni.reLaunch({url:'/pages/index/index'})}
</script>

<style scoped>
.commerce-hero{position:relative;min-height:220rpx;overflow:hidden;margin-bottom:18rpx;border:1rpx solid var(--tm-line);border-radius:var(--tm-radius-lg);background:linear-gradient(135deg,#e7fff1 0,#dff8fb 58%,#eef6ff 100%);box-shadow:var(--tm-shadow-sm)}
.commerce-hero-art{position:absolute;right:-16rpx;top:0;width:68%;height:100%;opacity:.78;object-position:right center}.commerce-hero::after{content:'';position:absolute;inset:0;background:linear-gradient(90deg,rgba(231,255,241,.98) 0,rgba(231,255,241,.9) 34%,rgba(231,255,241,.12) 78%,rgba(231,255,241,0) 100%)}
.commerce-hero-copy{position:relative;z-index:1;width:57%;padding:26rpx 24rpx}.commerce-hero-kicker,.commerce-hero-title,.commerce-hero-sub{display:block}.commerce-hero-kicker{color:var(--tm-brand-700);font-size:13px;font-weight:850;letter-spacing:2.4rpx}.commerce-hero-title{margin-top:10rpx;color:var(--tm-ink-900);font-size:22px;font-weight:950;line-height:1.2}.commerce-hero-sub{margin-top:10rpx;color:var(--tm-ink-500);font-size:13px;line-height:1.55}

.page{min-height:100vh;padding-top:70rpx;background:radial-gradient(circle at 85% 4%,rgba(118,223,99,.32),transparent 250rpx),linear-gradient(180deg,var(--tm-campus-cyan-soft),var(--tm-canvas) 560rpx)}.result-card{padding:50rpx 30rpx;text-align:center}.success-mark{display:flex;align-items:center;justify-content:center;width:112rpx;height:112rpx;margin:0 auto;border-radius:38rpx;background:linear-gradient(145deg,var(--tm-brand-600),var(--tm-campus-cyan));box-shadow:0 14rpx 32rpx rgba(23,105,255,.22);color:#fff;font-size:30px;font-weight:900}.result-title,.result-copy,.result-amount{display:block}.result-title{margin-top:26rpx;font-size:22px;font-weight:950}.result-copy{margin-top:10rpx;color:var(--tm-ink-500);font-size:14px}.result-amount{margin-top:24rpx;color:var(--tm-reward-600);font-size:21px;font-weight:950}.detail-card{margin-top:20rpx;padding:24rpx}.order-result + .order-result{margin-top:18rpx;padding-top:8rpx;border-top:1rpx solid var(--tm-line)}.kv{display:flex;justify-content:space-between;gap:20rpx;padding:16rpx 0;border-bottom:1rpx solid var(--tm-line);font-size:14px}.kv:last-child{border-bottom:0}.kv text:last-child{max-width:65%;text-align:right;font-weight:800}.asset-note{display:flex;gap:18rpx;margin-top:20rpx;padding:24rpx;background:var(--tm-campus-yellow-soft)}.note-icon{display:flex;align-items:center;justify-content:center;width:62rpx;height:62rpx;flex:none;border-radius:20rpx;background:var(--tm-campus-yellow);color:#7a5600;font-weight:950}.asset-note text{display:block}.asset-note text:first-child{font-size:14px;font-weight:900}.asset-note text:last-child{margin-top:6rpx;color:var(--tm-ink-500);font-size:13px;line-height:1.5}.actions{display:grid;grid-template-columns:1fr 1.4fr;gap:15rpx;margin-top:26rpx}.actions button{height:82rpx;margin:0;border-radius:21rpx;font-size:14px;font-weight:900;line-height:80rpx}.secondary{border:1rpx solid var(--tm-line);background:#fff;color:var(--tm-ink-900)}.primary{background:var(--tm-brand-600);color:#fff}.tm-panel{border-color:rgba(7,26,51,.09);border-radius:30rpx;box-shadow:0 14rpx 38rpx rgba(7,26,51,.07)}
.success-mark.failure{background:linear-gradient(145deg,#f15b63,#ff9c70);box-shadow:0 14rpx 32rpx rgba(208,55,65,.2)}
.page{background:linear-gradient(180deg,var(--tm-campus-cyan-soft) 0,var(--tm-canvas) 620rpx);color:var(--tm-ink-900)}
.tm-panel,.result-state{border:1rpx solid var(--tm-line);border-radius:var(--tm-radius-lg);box-shadow:var(--tm-shadow-sm);background:var(--tm-surface)}
.result-card{padding:50rpx 30rpx}.success-mark{box-shadow:var(--tm-shadow-card)}.success-mark.failure{box-shadow:var(--tm-shadow-card)}
.result-state{margin:0 0 20rpx;padding:54rpx 28rpx;text-align:center}.result-spinner{width:72rpx;height:72rpx;margin:0 auto;border-radius:24rpx}.result-state>text,.result-state>small{display:block}.result-state>text{margin-top:18rpx;font-size:17px;font-weight:900}.result-state>small{margin-top:9rpx;color:var(--tm-ink-500);font-size:13px;line-height:1.5}.result-state.error-state{border-color:rgba(209,67,67,.22);background:var(--tm-campus-coral-soft)}.result-state-title{color:var(--tm-danger)}
.result-state .primary{height:70rpx;margin-top:22rpx;border:1rpx solid transparent;border-radius:18rpx;background:var(--tm-brand-600);color:#fff;line-height:68rpx}.result-state .primary[disabled]{opacity:.46}
.result-state .secondary{height:70rpx;margin-top:12rpx;border:1rpx solid var(--tm-line);border-radius:18rpx;background:var(--tm-surface);color:var(--tm-ink-900);line-height:68rpx}.result-state .secondary[disabled]{opacity:.46}
.secondary{border:1rpx solid var(--tm-line);box-shadow:none}.primary{border:1rpx solid transparent;box-shadow:none}.primary[disabled]{opacity:.46;filter:saturate(.55)}
</style>
