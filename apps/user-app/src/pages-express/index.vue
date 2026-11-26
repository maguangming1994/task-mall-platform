<template>
  <view class="page">
    <view class="actions">
      <view class="action primary" @tap="goCreate">
        <text class="action-icon">＋</text>
        <text class="action-title">发布代取</text>
        <text class="action-desc">填写取件码与酬劳</text>
      </view>
      <view class="action" @tap="goHall">
        <text class="action-icon">◔</text>
        <text class="action-title">跑腿大厅</text>
        <text class="action-desc">顺路接单赚酬劳</text>
      </view>
      <view class="action" @tap="goRunner">
        <text class="action-icon">◎</text>
        <text class="action-title">我的跑腿</text>
        <text class="action-desc">认证、接单与收益</text>
      </view>
    </view>

    <view class="filters">
      <view v-for="item in filters" :key="item.value" class="chip" :class="{ active: status === item.value }" @tap="selectFilter(item.value)">
        {{ item.label }}
      </view>
    </view>

    <view v-if="loading" class="empty">加载中…</view>
    <view v-else-if="orders.length === 0" class="empty">还没有代取需求，点上方「发布代取」试试。</view>
    <view v-else class="list">
      <view v-for="order in orders" :key="order.id" class="card" @tap="openDetail(order.id)">
        <view class="card-top">
          <text class="card-no">{{ order.order_no }}</text>
          <text class="status" :class="order.status">{{ expressStatusLabel(order.status) }}</text>
        </view>
        <view class="row"><text class="label">取件</text><text class="value">{{ order.pickup_site }}</text></view>
        <view class="row"><text class="label">送达</text><text class="value">{{ order.deliver_building }} {{ order.deliver_address }}</text></view>
        <view class="card-bottom">
          <text class="reward">￥{{ expressMoney(order.total_cents) }}</text>
          <text class="time">{{ order.created_at ? order.created_at.slice(0, 16).replace('T', ' ') : '' }}</text>
        </view>
      </view>
    </view>
  </view>
</template>

<script setup lang="ts">
import { onShow } from '@dcloudio/uni-app'
import { ref } from 'vue'
import {
  expressMoney,
  expressStatusLabel,
  listMyExpressPickupOrders,
  type ExpressPickupOrder,
} from '@/api/express-pickup'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const orders = ref<ExpressPickupOrder[]>([])
const loading = ref(false)
const status = ref('')
const filters = [
  { value: '', label: '全部' },
  { value: 'pending_payment', label: '待支付' },
  { value: 'pending_accept', label: '待接单' },
  { value: 'accepted', label: '进行中' },
  { value: 'delivered', label: '待确认' },
  { value: 'completed', label: '已完成' },
]

const load = async () => {
  if (!auth.accessToken) return
  loading.value = true
  try {
    const result = await listMyExpressPickupOrders(auth.accessToken, status.value || undefined)
    orders.value = result.items
  } catch (error) {
    uni.showToast({ title: error instanceof Error ? error.message : '加载失败', icon: 'none' })
  } finally {
    loading.value = false
  }
}

const selectFilter = (value: string) => {
  status.value = value
  void load()
}

const requireLogin = () => {
  if (auth.accessToken) return true
  uni.navigateTo({ url: '/pages/login/index' })
  return false
}

const goCreate = () => { if (requireLogin()) uni.navigateTo({ url: '/pages-express/create' }) }
const goHall = () => { if (requireLogin()) uni.navigateTo({ url: '/pages-express/runner-hall' }) }
const goRunner = () => { if (requireLogin()) uni.navigateTo({ url: '/pages-express/runner-center' }) }
const openDetail = (id: string) => uni.navigateTo({ url: `/pages-express/detail?id=${id}` })

onShow(() => {
  if (auth.accessToken) void load()
})
</script>

<style scoped>
.page { min-height: 100vh; background: #f5f6f8; padding: 24rpx 24rpx 60rpx; box-sizing: border-box; }
.actions { display: flex; gap: 16rpx; }
.action { flex: 1; background: #fff; border-radius: 20rpx; padding: 24rpx 16rpx; display: flex; flex-direction: column; align-items: center; box-shadow: 0 4rpx 16rpx rgba(17, 24, 39, 0.04); }
.action.primary { background: linear-gradient(140deg, #2f6bff, #5b8dff); }
.action-icon { font-size: 40rpx; color: #2f6bff; }
.action.primary .action-icon, .action.primary .action-title, .action.primary .action-desc { color: #fff; }
.action-title { margin-top: 8rpx; font-size: 28rpx; font-weight: 600; color: #111827; }
.action-desc { margin-top: 4rpx; font-size: 22rpx; color: #6b7280; }
.filters { display: flex; flex-wrap: wrap; gap: 12rpx; margin: 24rpx 0 16rpx; }
.chip { padding: 10rpx 24rpx; border-radius: 999rpx; background: #fff; font-size: 24rpx; color: #4b5563; }
.chip.active { background: #2f6bff; color: #fff; }
.empty { padding: 120rpx 0; text-align: center; color: #9ca3af; font-size: 26rpx; }
.list { display: flex; flex-direction: column; gap: 16rpx; }
.card { background: #fff; border-radius: 20rpx; padding: 24rpx; }
.card-top { display: flex; justify-content: space-between; align-items: center; margin-bottom: 12rpx; }
.card-no { font-size: 26rpx; color: #111827; font-weight: 600; }
.status { font-size: 22rpx; padding: 4rpx 16rpx; border-radius: 999rpx; background: #eef2ff; color: #2f6bff; }
.status.cancelled { background: #f3f4f6; color: #9ca3af; }
.status.completed { background: #ecfdf5; color: #059669; }
.row { display: flex; font-size: 24rpx; margin-top: 6rpx; }
.label { width: 72rpx; color: #9ca3af; }
.value { flex: 1; color: #374151; }
.card-bottom { display: flex; justify-content: space-between; align-items: center; margin-top: 16rpx; }
.reward { font-size: 30rpx; color: #ef4444; font-weight: 600; }
.time { font-size: 22rpx; color: #9ca3af; }
</style>
