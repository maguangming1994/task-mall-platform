<template>
  <view class="page">
    <ModulePlaceholder v-if="modules.resolved && !moduleEnabled" code="module_mall_trade" />
    <view v-else-if="!modules.resolved" class="mp-skeleton">
      <view class="tm-skeleton" style="height: 200rpx; border-radius: 24rpx; margin: 24rpx 28rpx;" />
      <view class="tm-skeleton" style="height: 320rpx; border-radius: 24rpx; margin: 20rpx 28rpx;" />
    </view>
    <template v-else>
    <view v-if="loading" class="state">
      <view class="state-spinner" />
      <text class="state-title">正在同步支付结果</text>
      <text class="state-copy">请稍候，正在获取订单状态。</text>
    </view>
    <view v-else-if="loadError" class="state error">
      <text class="state-title">订单信息加载失败</text>
      <text class="state-copy">{{ loadError }}</text>
      <button class="btn-main" :loading="loading" :disabled="loading" @click="loadOrder">重新加载</button>
      <button class="btn-ghost" :disabled="!orderIds.length" @click="goOrderEntry">返回订单</button>
    </view>
    <template v-else>
      <view class="result">
        <view class="result-icon" :class="{ failure: !isSuccess }">{{ isSuccess ? '✓' : '!' }}</view>
        <text class="result-title">{{ title }}</text>
        <text class="result-copy">{{ copy }}</text>
        <text v-if="orders.length" class="result-amount">{{ totalAmountText }}</text>
      </view>

      <view v-if="orders.length" class="info-card">
        <view v-for="item in orders" :key="item.id" class="info-group">
          <view class="info-row"><text>支付方式</text><text>{{ paymentText(item.cash_payment_method) }}</text></view>
          <view class="info-row"><text>订单编号</text><text selectable>{{ item.order_no }}</text></view>
          <view class="info-row"><text>现金运费</text><text>¥{{ money(item.shipping_amount) }}</text></view>
          <view class="info-row"><text>当前状态</text><text>{{ statusText(item.status) }}</text></view>
        </view>
      </view>

      <view v-if="!loading && !loadError && hasPoints" class="tips-card">
        <view class="tips-ic">积</view>
        <view class="tips-body">
          <text class="tips-title">积分变动已记入资产流水</text>
          <text class="tips-sub">退款时会根据实际退回时间重新计算积分有效期。</text>
        </view>
      </view>

      <view class="actions">
        <button class="btn-ghost" @click="goHome">继续逛逛</button>
        <button class="btn-main" :disabled="!orderIds.length" @click="goOrderEntry">{{ isSuccess ? '查看订单' : resultStatus === 'pending' ? '返回订单继续确认' : '返回订单重试支付' }}</button>
      </view>

      <view v-if="orders.some((item) => item.fulfillment_type === 'logistics')" class="ship-card">
        <view class="ship-ic">物</view>
        <text class="ship-copy">发货后可在「我的 - 订单记录」查看物流，到货记得来确认收货哦</text>
      </view>
    </template>
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
function paymentText(value:string){return ({wallet:'余额全额支付',wechat:'在线现金支付',none:'积分兑换 / 无现金支付'} as Record<string,string>)[value]||'支付方式待确认'}
function statusText(value:string){return ({pending_payment:'待支付',pending_fulfillment:'待履约',fulfilled:'待确认',completed:'已完成',partially_refunded:'部分退款',refunded:'已退款',cancelled:'已取消'} as Record<string,string>)[value]||'处理中'}
function goOrderEntry(){
  if (orderIds.value.length > 1) return uni.redirectTo({ url: `/pages/orders/index?ids=${encodeURIComponent(orderIds.value.join(','))}` })
  if (orderId.value) uni.redirectTo({url:`/pages/order-detail/index?id=${encodeURIComponent(orderId.value)}`})
}
function goHome(){uni.reLaunch({url:'/pages/index/index'})}
</script>

<style scoped>
.page{min-height:100vh;padding:48rpx 24rpx 120rpx;background:#F4F6F4;color:#191B1F}
.state{display:flex;flex-direction:column;align-items:center;padding:140rpx 48rpx 60rpx;text-align:center}
.state-spinner{width:72rpx;height:72rpx;margin:0 auto;border:6rpx solid #E7F9EF;border-top-color:#0BBF63;border-radius:50%;animation:res-spin .8s linear infinite}
.state-title{display:block;margin-top:28rpx;font-size:32rpx;font-weight:700}
.state-copy{display:block;margin-top:10rpx;color:#767C85;font-size:26rpx;line-height:1.6}
.state .btn-main,.state .btn-ghost{width:320rpx;height:80rpx;border-radius:999rpx;font-size:28rpx;line-height:80rpx}
.state .btn-main{margin-top:32rpx;background:linear-gradient(135deg,#12D978,#08A152);color:#fff;font-weight:700}
.state .btn-ghost{margin-top:16rpx;border:1rpx solid #EDEFEF;background:#fff;color:#767C85}
.state .btn-main::after,.state .btn-ghost::after{border:0}
.state.error .state-title{color:#FF2442}
.result{display:flex;flex-direction:column;align-items:center;padding:80rpx 48rpx 20rpx;text-align:center}
.result-icon{display:flex;align-items:center;justify-content:center;width:184rpx;height:184rpx;margin:0 auto;border-radius:50%;background:linear-gradient(135deg,#12D978,#08A152);box-shadow:0 28rpx 60rpx rgba(11,191,99,.32);color:#fff;font-size:52rpx;font-weight:900}
.result-icon.failure{background:linear-gradient(135deg,#FF9A3D,#F2620A);box-shadow:0 28rpx 60rpx rgba(242,98,10,.28)}
.result-title{display:block;margin-top:44rpx;font-size:44rpx;font-weight:800}
.result-copy{display:block;margin-top:16rpx;color:#767C85;font-size:26rpx;line-height:1.6}
.result-amount{display:block;margin-top:40rpx;color:#FF2442;font-size:38rpx;font-weight:800}
.info-card{margin:24rpx 0 0;padding:8rpx 28rpx;border-radius:24rpx;background:#fff}
.info-group+.info-group{margin-top:10rpx;padding-top:10rpx;border-top:1rpx solid #F5F6F6}
.info-row{display:flex;justify-content:space-between;gap:24rpx;padding:20rpx 0;border-bottom:1rpx solid #F5F6F6;font-size:26rpx}
.info-row:last-child{border-bottom:0}
.info-row text:last-child{max-width:65%;text-align:right;font-weight:600}
.tips-card{display:flex;align-items:center;gap:22rpx;margin:24rpx 0 0;padding:28rpx;border-radius:24rpx;background:#fff}
.tips-ic{display:flex;align-items:center;justify-content:center;width:76rpx;height:76rpx;flex:none;border-radius:24rpx;background:#E7F9EF;color:#08A152;font-size:30rpx;font-weight:800}
.tips-body{min-width:0;flex:1}
.tips-title{display:block;font-size:28rpx;font-weight:600}
.tips-sub{display:block;margin-top:6rpx;color:#767C85;font-size:24rpx;line-height:1.5}
.ship-card{display:flex;align-items:center;gap:20rpx;margin:24rpx 0 0;padding:28rpx;border-radius:24rpx;background:#fff}
.ship-ic{display:flex;align-items:center;justify-content:center;width:68rpx;height:68rpx;flex:none;border-radius:20rpx;background:#FFF6DE;color:#D99400;font-size:28rpx;font-weight:800}
.ship-copy{min-width:0;flex:1;color:#767C85;font-size:24rpx;line-height:1.5}
.actions{display:flex;gap:24rpx;margin-top:40rpx;padding:0 8rpx}
.actions button{flex:1;height:88rpx;margin:0;border-radius:999rpx;font-size:30rpx;font-weight:700;line-height:88rpx}
.actions button::after{border:0}
.btn-ghost{border:1rpx solid #EDEFEF;background:#F2F4F3;color:#767C85}
.btn-main{background:linear-gradient(135deg,#12D978,#08A152);color:#fff;box-shadow:0 16rpx 36rpx rgba(11,191,99,.3)}
.btn-main[disabled]{opacity:.5}
@keyframes res-spin{to{transform:rotate(360deg)}}
</style>
