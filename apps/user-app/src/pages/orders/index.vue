<template>
  <view class="page">
    <view class="heading"><text class="title">我的订单</text><text class="sub">拆分订单按履约方式独立支付、发货和售后</text></view>
    <button class="after-sale" @click="openAfterSales">申请售后 / 查看进度</button>
    <view v-for="order in orders" :key="order.id" class="card">
      <view class="row"><text class="number">{{ order.order_no }}</text><text class="status">{{ statusText(order.status) }}</text></view>
      <text class="meta">{{ fulfillmentText(order.fulfillment_type) }} · {{ paymentText(order.cash_payment_method) }}</text>
      <view v-for="item in order.items" :key="item.id" class="item"><text>{{ item.product_name }} · {{ item.sku_name }} × {{ item.quantity }}</text><text>{{ modeText(item.purchase_mode) }}</text></view>
      <view class="total"><text>应付</text><text>{{ amountText(order.payable_cash_amount, order.payable_points) }}</text></view>
      <text v-if="order.shipping_amount" class="shipping">含现金运费 ¥{{ money(order.shipping_amount) }}</text>
      <button v-if="order.status === 'pending_payment'" class="pay" :loading="payingOrderId === order.id" :disabled="!!payingOrderId" @click="pay(order)">{{ order.cash_payment_method === 'wallet' ? '余额全额支付' : '微信支付' }}</button>
      <button v-if="order.status === 'fulfilled'" class="pay" :loading="payingOrderId === order.id" @click="confirmComplete(order)">确认完成</button>
    </view>
    <text v-if="!loading && !orders.length" class="empty">暂无订单</text>
  </view>
</template>

<script setup lang="ts">
import { onShow } from '@dcloudio/uni-app'
import { ref } from 'vue'
import { completeOrder, createPayment, listOrders, type Order, type Payment } from '@/api/orders'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const orders = ref<Order[]>([])
const loading = ref(false)
const payingOrderId = ref('')

onShow(async () => {
  if (!auth.accessToken) return uni.navigateTo({ url: '/pages/login/index' })
  loading.value = true
  try { orders.value = (await listOrders(auth.accessToken)).items }
  catch (error) { uni.showToast({ title: error instanceof Error ? error.message : '订单加载失败', icon: 'none' }) }
  finally { loading.value = false }
})

function money(cents: number) { return (cents / 100).toFixed(2) }
function amountText(cash: number, points: number) { return [cash ? `¥${money(cash)}` : '', points ? `${points} 积分` : ''].filter(Boolean).join(' + ') || '无需支付' }
function statusText(status: string) { return ({ pending_payment: '待支付', pending_fulfillment: '待履约', completed: '已完成', closed: '已关闭' } as Record<string, string>)[status] || status }
function fulfillmentText(type: string) { return ({ logistics: '物流配送', no_logistics: '无需物流', virtual_delivery: '虚拟交付' } as Record<string, string>)[type] || type }
function paymentText(method: string) { return ({ wallet: '余额全额', wechat: '微信支付', none: '无现金支付' } as Record<string, string>)[method] || method }
function modeText(mode: string) { return ({ cash: '现金', points: '积分', cash_points: '现金 + 积分' } as Record<string, string>)[mode] || mode }
async function pay(order: Order) {
  if (!auth.accessToken) return
  payingOrderId.value = order.id
  try {
    const payment = await createPayment(auth.accessToken, order.id, `payment-${order.id}-${Date.now()}`)
    if (payment.status === 'succeeded') {
      uni.showToast({ title: '支付成功', icon: 'success' })
      orders.value = (await listOrders(auth.accessToken)).items
      return
    }
    await invokeWechat(payment)
  } catch (error) { uni.showToast({ title: error instanceof Error ? error.message : '支付发起失败', icon: 'none' }) }
  finally { payingOrderId.value = '' }
}
async function invokeWechat(payment: Payment) {
  const payload = payment.client_payload
  if (!payload || payload.kind === 'wallet') return
  if (payload.sandbox) { uni.showToast({ title: '本地模拟支付单已创建', icon: 'none' }); return }
  if (payload.kind === 'mweb') {
    // #ifdef H5
    window.location.assign(payload.mweb_url)
    // #endif
    return
  }
  // #ifdef MP-WEIXIN
  await uni.requestPayment({ provider: 'wxpay', timeStamp: payload.timeStamp, nonceStr: payload.nonceStr, package: payload.package, signType: 'RSA', paySign: payload.paySign })
  // #endif
  uni.showToast({ title: '支付结果确认中', icon: 'none' })
}
async function confirmComplete(order: Order) { if (!auth.accessToken) return; payingOrderId.value = order.id; try { await completeOrder(auth.accessToken, order.id); orders.value = (await listOrders(auth.accessToken)).items; uni.showToast({ title: '订单已完成', icon: 'success' }) } catch (error) { uni.showToast({ title: error instanceof Error ? error.message : '确认失败', icon: 'none' }) } finally { payingOrderId.value = '' } }
function openAfterSales() { uni.navigateTo({ url: '/pages/after-sales/index' }) }
</script>

<style scoped>
.page{min-height:100vh;padding:32rpx;background:#f5f7fb;box-sizing:border-box}.heading{padding:18rpx 8rpx}.title,.sub,.meta,.shipping,.empty{display:block}.title{font-size:42rpx;font-weight:750}.sub{margin-top:10rpx;color:#667085;font-size:24rpx}.after-sale{margin:10rpx 0 18rpx;background:#fff;color:#3157d5}.card{margin-top:20rpx;padding:28rpx;background:#fff;border-radius:22rpx}.row,.item,.total{display:flex;justify-content:space-between;gap:20rpx}.number{font-size:25rpx;font-weight:650}.status{color:#d94b17}.meta{margin-top:10rpx;color:#667085;font-size:24rpx}.item{margin-top:20rpx;padding-top:20rpx;border-top:1rpx solid #eef0f3;font-size:25rpx}.total{margin-top:22rpx;font-weight:700}.shipping{margin-top:10rpx;color:#667085;font-size:23rpx}.pay{margin-top:22rpx;background:#ef6c35;color:#fff}.empty{padding:120rpx 0;text-align:center;color:#98a2b3}
</style>
