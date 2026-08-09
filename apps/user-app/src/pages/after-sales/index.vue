<template>
  <view class="page">
    <view class="heading"><text class="title">申请售后</text><text class="sub">按订单项选择退款、退货或同 SKU 换货</text></view>
    <view v-for="order in eligibleOrders" :key="order.id" class="card">
      <view class="row"><text class="number">{{ order.order_no }}</text><text class="status">{{ order.status }}</text></view>
      <view v-for="item in order.items" :key="item.id" class="item">
        <text>{{ item.product_name }} · {{ item.sku_name }} × {{ item.quantity }}</text>
        <view class="actions">
          <button size="mini" @click="applyRefund(order, item, false)">仅退款</button>
          <button v-if="order.status !== 'pending_fulfillment'" size="mini" @click="applyRefund(order, item, true)">退货退款</button>
          <button v-if="order.status === 'fulfilled' || order.status === 'completed'" size="mini" @click="applyExchange(item, order.fulfillment_type)">同 SKU 换货</button>
        </view>
      </view>
    </view>
    <view class="heading second"><text class="title small">售后进度</text></view>
    <view v-for="refund in refunds" :key="refund.id" class="card">
      <view class="row"><text>{{ refund.refund_no }}</text><text class="status">{{ statusText(refund.status) }}</text></view>
      <text class="meta">{{ refund.reason_name }} · {{ refund.responsibility_party }}承担</text>
      <text class="meta">退款 ¥{{ money(refund.refund_cash_amount) }} + {{ refund.refund_points }} 积分</text>
      <button v-if="refund.status === 'awaiting_return'" class="primary" @click="submitTracking(refund)">填写退货物流</button>
    </view>
  </view>
</template>

<script setup lang="ts">
import { onShow } from '@dcloudio/uni-app'
import { computed, ref } from 'vue'
import { createExchange, createRefund, listRefunds, submitRefundReturn, type Refund } from '@/api/after-sales'
import { listOrders, type Order, type OrderItem } from '@/api/orders'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const orders = ref<Order[]>([])
const refunds = ref<Refund[]>([])
const eligibleOrders = computed(() => orders.value.filter((order) => ['pending_fulfillment', 'fulfilled', 'completed'].includes(order.status)))
onShow(load)
async function load() { if (!auth.accessToken) return uni.navigateTo({ url: '/pages/login/index' }); try { [orders.value, refunds.value] = [(await listOrders(auth.accessToken)).items, (await listRefunds(auth.accessToken)).items] } catch (error) { toast(error) } }
async function chooseReason(): Promise<string | null> { return await new Promise((resolve) => uni.showActionSheet({ itemList: ['不喜欢/不想要了', '选错规格/买错商品', '其他/已与客服协商'], success: ({ tapIndex }) => resolve(['no_longer_wanted', 'ordered_wrong_item', 'other_negotiated'][tapIndex]), fail: () => resolve(null) })) }
async function applyRefund(order: Order, item: OrderItem, returns: boolean) { if (!auth.accessToken) return; const reason = await chooseReason(); if (!reason) return; try { await createRefund(auth.accessToken, { order_id: order.id, service_type: returns ? 'return_and_refund' : 'refund_only', reason_code: reason, return_method: returns ? (order.fulfillment_type === 'logistics' ? 'logistics' : 'no_logistics') : undefined, items: [{ order_item_id: item.id, quantity: 1 }] }); uni.showToast({ title: '申请已提交', icon: 'success' }); await load() } catch (error) { toast(error) } }
async function applyExchange(item: OrderItem, fulfillment: string) { if (!auth.accessToken) return; const reason = await chooseReason(); if (!reason) return; try { await createExchange(auth.accessToken, { order_item_id: item.id, quantity: 1, reason_code: reason, return_method: fulfillment === 'logistics' ? 'logistics' : 'no_logistics' }); uni.showToast({ title: '换货已提交', icon: 'success' }) } catch (error) { toast(error) } }
async function submitTracking(refund: Refund) { if (!auth.accessToken) return; const tracking = await prompt('请输入退货物流单号'); if (!tracking) return; const carrier = await prompt('请输入承运商代码，如 sf'); if (!carrier) return; try { await submitRefundReturn(auth.accessToken, refund.id, { carrier_code: carrier, tracking_no: tracking, paid_shipping_amount: 0 }); uni.showToast({ title: '物流已提交', icon: 'success' }); await load() } catch (error) { toast(error) } }
function prompt(title: string): Promise<string | null> { return new Promise((resolve) => uni.showModal({ title, editable: true, success: (result) => resolve(result.confirm ? result.content || null : null) })) }
function toast(error: unknown) { uni.showToast({ title: error instanceof Error ? error.message : '操作失败', icon: 'none' }) }
function money(cents: number) { return (cents / 100).toFixed(2) }
function statusText(status: string) { return ({ submitted: '待审核', awaiting_return: '待寄回', return_in_transit: '退货运输中', processing: '退款处理中', succeeded: '已完成', rejected: '已拒绝', closed: '已关闭' } as Record<string, string>)[status] || status }
</script>

<style scoped>
.page{min-height:100vh;padding:32rpx;background:#f5f7fb;box-sizing:border-box}.heading{padding:18rpx 8rpx}.second{margin-top:28rpx}.title,.sub,.meta{display:block}.title{font-size:42rpx;font-weight:750}.title.small{font-size:34rpx}.sub,.meta{margin-top:10rpx;color:#667085;font-size:24rpx}.card{margin-top:20rpx;padding:28rpx;background:#fff;border-radius:22rpx}.row,.item{display:flex;justify-content:space-between;gap:20rpx}.item{display:block;margin-top:20rpx;padding-top:20rpx;border-top:1rpx solid #eef0f3}.number{font-weight:700}.status{color:#d94b17}.actions{display:flex;gap:12rpx;margin-top:18rpx;flex-wrap:wrap}.actions button{margin:0}.primary{margin-top:20rpx;background:#ef6c35;color:#fff}
</style>
