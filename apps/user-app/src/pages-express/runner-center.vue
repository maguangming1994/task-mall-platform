<template>
  <view class="page">
    <view class="profile">
      <text class="profile-title">{{ runner ? runner.real_name : '暂未申请跑腿' }}</text>
      <text class="profile-status">{{ runner ? statusText : '通过认证后即可接单赚钱' }}</text>
      <view class="profile-actions">
        <button v-if="!runner || runner.status === 'rejected'" class="mini primary" @tap="goApply">申请跑腿</button>
        <button v-if="runner?.status === 'active'" class="mini primary" @tap="goHall">去接单</button>
      </view>
    </view>

    <view class="filters">
      <view v-for="item in filters" :key="item.value" class="chip" :class="{ active: status === item.value }" @tap="selectFilter(item.value)">
        {{ item.label }}
      </view>
    </view>

    <view v-if="loading" class="empty">加载中…</view>
    <view v-else-if="orders.length === 0" class="empty">还没有接单记录。</view>
    <view v-else class="list">
      <view v-for="order in orders" :key="order.id" class="card">
        <view class="card-top">
          <text class="card-no">{{ order.order_no }}</text>
          <text class="status">{{ expressStatusLabel(order.status) }}</text>
        </view>
        <view class="row"><text class="label">取件</text><text class="value">{{ order.pickup_site }}（{{ order.pickup_code }}）</text></view>
        <view class="row"><text class="label">送达</text><text class="value">{{ order.deliver_building }} {{ order.deliver_address }}</text></view>
        <view class="row"><text class="label">联系</text><text class="value">{{ order.contact_name }} {{ order.contact_phone }}</text></view>
        <view class="card-bottom">
          <text class="reward">￥{{ expressMoney(order.total_cents) }}</text>
          <view class="ops">
            <button v-if="order.status === 'accepted'" class="mini" :loading="busyId === order.id" @tap="act(order.id, 'pickup')">确认取件</button>
            <button v-else-if="order.status === 'picked_up'" class="mini" :loading="busyId === order.id" @tap="act(order.id, 'deliver')">标记送达</button>
            <text v-else-if="order.status === 'delivered'" class="hint">等待用户确认</text>
            <text v-else-if="order.status === 'completed'" class="hint done">已完成</text>
          </view>
        </view>
      </view>
    </view>
  </view>
</template>

<script setup lang="ts">
import { onShow } from '@dcloudio/uni-app'
import { computed, ref } from 'vue'
import {
  deliverExpressPickupOrder,
  expressMoney,
  expressStatusLabel,
  getExpressPickupRunnerProfile,
  listMyRunnerOrders,
  pickupExpressPickupOrder,
  type ExpressPickupOrder,
  type ExpressPickupRunnerProfile,
} from '@/api/express-pickup'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const runner = ref<ExpressPickupRunnerProfile | null>(null)
const orders = ref<ExpressPickupOrder[]>([])
const loading = ref(false)
const busyId = ref<string | null>(null)
const status = ref('')
const filters = [
  { value: '', label: '全部' },
  { value: 'accepted', label: '待取件' },
  { value: 'picked_up', label: '配送中' },
  { value: 'delivered', label: '待确认' },
  { value: 'completed', label: '已完成' },
]

const statusText = computed(() => {
  switch (runner.value?.status) {
    case 'active': return '跑腿资格已启用'
    case 'pending': return '申请审核中'
    case 'rejected': return runner.value.reject_reason || '申请未通过'
    case 'suspended': return '资格已挂起'
    default: return ''
  }
})

const toast = (title: string) => uni.showToast({ title, icon: 'none' })

const load = async () => {
  if (!auth.accessToken) {
    uni.navigateTo({ url: '/pages/login/index' })
    return
  }
  loading.value = true
  try {
    const profile = await getExpressPickupRunnerProfile(auth.accessToken)
    runner.value = profile.runner
    const result = await listMyRunnerOrders(auth.accessToken, status.value || undefined)
    orders.value = result.items
  } catch (error) {
    toast(error instanceof Error ? error.message : '加载失败')
  } finally {
    loading.value = false
  }
}

const selectFilter = (value: string) => {
  status.value = value
  void load()
}

const act = async (id: string, action: 'pickup' | 'deliver') => {
  if (!auth.accessToken) return
  busyId.value = id
  try {
    if (action === 'pickup') await pickupExpressPickupOrder(auth.accessToken, id)
    else await deliverExpressPickupOrder(auth.accessToken, id)
    toast(action === 'pickup' ? '已确认取件' : '已标记送达')
    await load()
  } catch (error) {
    toast(error instanceof Error ? error.message : '操作失败')
  } finally {
    busyId.value = null
  }
}

const goApply = () => uni.navigateTo({ url: '/pages-express/runner-apply' })
const goHall = () => uni.navigateTo({ url: '/pages-express/runner-hall' })

onShow(() => { void load() })
</script>

<style scoped>
.page { min-height: 100vh; background: #f5f6f8; padding: 24rpx 24rpx 60rpx; box-sizing: border-box; }
.profile { background: linear-gradient(140deg, #111827, #374151); border-radius: 24rpx; padding: 32rpx; color: #fff; }
.profile-title { font-size: 34rpx; font-weight: 700; display: block; }
.profile-status { font-size: 24rpx; opacity: 0.8; margin-top: 8rpx; display: block; }
.profile-actions { margin-top: 20rpx; }
.mini.primary { background: #2f6bff; color: #fff; border-radius: 999rpx; font-size: 24rpx; display: inline-block; padding: 0 32rpx; line-height: 64rpx; height: 64rpx; }
.filters { display: flex; flex-wrap: wrap; gap: 12rpx; margin: 24rpx 0 16rpx; }
.chip { padding: 10rpx 24rpx; border-radius: 999rpx; background: #fff; font-size: 24rpx; color: #4b5563; }
.chip.active { background: #2f6bff; color: #fff; }
.empty { padding: 120rpx 0; text-align: center; color: #9ca3af; font-size: 26rpx; }
.list { display: flex; flex-direction: column; gap: 16rpx; }
.card { background: #fff; border-radius: 20rpx; padding: 24rpx; }
.card-top { display: flex; justify-content: space-between; align-items: center; margin-bottom: 12rpx; }
.card-no { font-size: 26rpx; color: #111827; font-weight: 600; }
.status { font-size: 22rpx; padding: 4rpx 16rpx; border-radius: 999rpx; background: #eef2ff; color: #2f6bff; }
.row { display: flex; font-size: 24rpx; margin-top: 6rpx; }
.label { width: 72rpx; color: #9ca3af; }
.value { flex: 1; color: #374151; }
.card-bottom { display: flex; justify-content: space-between; align-items: center; margin-top: 16rpx; }
.reward { font-size: 30rpx; color: #ef4444; font-weight: 600; }
.ops { display: flex; align-items: center; }
.mini { background: #2f6bff; color: #fff; border-radius: 999rpx; font-size: 24rpx; line-height: 60rpx; height: 60rpx; padding: 0 28rpx; }
.hint { font-size: 22rpx; color: #9ca3af; }
.hint.done { color: #059669; }
</style>
