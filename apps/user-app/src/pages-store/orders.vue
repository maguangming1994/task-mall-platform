<template>
  <view class="page">
    <view v-if="campus" class="campus-bar"><text>当前校区</text><text class="campus-name">{{ campus.campus_name }}</text></view>
    <scroll-view scroll-x class="chips">
      <view v-for="item in filters" :key="item.value" class="chip" :class="{ active: status === item.value }" @tap="selectStatus(item.value)">{{ item.label }}</view>
    </scroll-view>

    <view v-if="loading" class="empty">加载中…</view>
    <view v-else-if="orders.length === 0" class="empty">暂无门店订单。</view>
    <view v-else class="list">
      <view v-for="order in orders" :key="order.id" class="card">
        <view class="card-top">
          <text class="no">{{ order.order_no }}</text>
          <text class="status" :class="order.status">{{ storeOrderStatusLabel(order.status) }}</text>
        </view>
        <text class="time">下单 {{ order.created_at?.slice(0, 16).replace('T', ' ') || '-' }}<text v-if="order.paid_at"> · 支付 {{ order.paid_at.slice(0, 16).replace('T', ' ') }}</text></text>
        <view class="items">
          <view v-for="item in order.items" :key="item.id" class="item">
            <view class="item-main">
              <text class="item-name">{{ item.product_name }}</text>
              <text class="item-sku">{{ item.sku_name }}<text v-if="item.sku_description"> · {{ item.sku_description }}</text></text>
            </view>
            <view class="item-right">
              <text class="qty">×{{ item.quantity }}</text>
              <text class="price">{{ money(item.paid_cash_amount || item.unit_cash_amount * item.quantity) }}</text>
              <text v-if="item.refunded_cash_amount" class="refunded">已退 {{ money(item.refunded_cash_amount) }}</text>
            </view>
          </view>
        </view>
        <view class="foot">
          <text class="foot-label">门店商品金额</text>
          <text class="total">{{ money(order.store_gross_amount) }}</text>
        </view>
      </view>
    </view>
    <view v-if="hasMore" class="more" @tap="loadMore">加载更多</view>
  </view>
</template>

<script setup lang="ts">
import { onShow } from '@dcloudio/uni-app'
import { ref } from 'vue'
import { listManagerOrders, storeOrderStatusLabel, type StoreCampus, type StoreOrderView } from '@/api/stores'
import { getSelectedStoreCampus, requireStoreSession } from '@/utils/storeSession'

const session = requireStoreSession()
const campus = ref<StoreCampus | null>(null)
const orders = ref<StoreOrderView[]>([])
const loading = ref(false)
const status = ref('')
const page = ref(1)
const hasMore = ref(false)
const filters = [
  { value: '', label: '全部' },
  { value: 'pending_payment', label: '待支付' },
  { value: 'pending_fulfillment', label: '待履约' },
  { value: 'completed', label: '已完成' },
  { value: 'cancelled', label: '已取消' },
]

const toast = (title: string) => uni.showToast({ title, icon: 'none' })
const money = (cents: number) => `¥${(cents / 100).toFixed(2)}`

const load = async (reset = true) => {
  if (!session) return
  if (reset) { page.value = 1; orders.value = [] }
  loading.value = true
  try {
    const result = await listManagerOrders(session.token, { status: status.value, campus_id: campus.value?.campus_id, page: page.value, page_size: 20 })
    orders.value = reset ? result.items : [...orders.value, ...result.items]
    hasMore.value = page.value < result.pagination.last_page
  } catch (error) {
    toast(error instanceof Error ? error.message : '加载失败')
  } finally {
    loading.value = false
  }
}
const selectStatus = (value: string) => { status.value = value; void load(true) }
const loadMore = () => { page.value += 1; void load(false) }

onShow(() => {
  if (session) campus.value = getSelectedStoreCampus(session.storeId)
  void load(true)
})
</script>

<style scoped>
.page { min-height: 100vh; background: #f5f6f8; padding: 24rpx 24rpx 80rpx; box-sizing: border-box; }
.campus-bar { display: flex; gap: 12rpx; margin-bottom: 16rpx; padding: 0 4rpx; font-size: 22rpx; color: #6b7280; }
.campus-name { color: #2f6bff; font-weight: 600; }
.chips { white-space: nowrap; }
.chip { display: inline-block; padding: 10rpx 26rpx; border-radius: 999rpx; background: #fff; color: #6b7280; font-size: 24rpx; margin-right: 12rpx; }
.chip.active { background: #2f6bff; color: #fff; }
.empty { padding: 140rpx 0; text-align: center; color: #9ca3af; font-size: 26rpx; }
.list { margin-top: 16rpx; }
.card { background: #fff; border-radius: 20rpx; padding: 22rpx; margin-bottom: 16rpx; }
.card-top { display: flex; justify-content: space-between; align-items: center; }
.no { font-size: 24rpx; color: #374151; font-weight: 600; }
.status { font-size: 20rpx; padding: 4rpx 12rpx; border-radius: 999rpx; background: #eef2ff; color: #2f6bff; }
.status.completed { background: #ecfdf5; color: #059669; }
.status.cancelled { background: #f3f4f6; color: #9ca3af; }
.time { display: block; margin-top: 8rpx; font-size: 20rpx; color: #9ca3af; }
.items { margin-top: 16rpx; border-top: 1rpx solid #f3f4f6; }
.item { display: flex; justify-content: space-between; gap: 16rpx; padding: 16rpx 0; border-bottom: 1rpx solid #f9fafb; }
.item:last-child { border-bottom: none; }
.item-main { flex: 1; min-width: 0; }
.item-name { display: block; font-size: 26rpx; color: #111827; }
.item-sku { display: block; margin-top: 4rpx; font-size: 20rpx; color: #9ca3af; }
.item-right { text-align: right; }
.qty { display: block; font-size: 22rpx; color: #6b7280; }
.price { display: block; margin-top: 4rpx; font-size: 24rpx; color: #111827; }
.refunded { display: block; font-size: 18rpx; color: #dc2626; }
.foot { display: flex; justify-content: space-between; align-items: center; margin-top: 16rpx; padding-top: 16rpx; border-top: 1rpx solid #f3f4f6; }
.foot-label { font-size: 22rpx; color: #6b7280; }
.total { font-size: 30rpx; font-weight: 700; color: #111827; }
.more { text-align: center; color: #2f6bff; font-size: 24rpx; padding: 20rpx; }
</style>
