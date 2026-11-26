<template>
  <view class="page">
    <view v-if="loading" class="empty">加载中…</view>
    <template v-else>
      <view v-if="runnerStatus !== 'active'" class="notice">
        <text class="notice-title">还不是跑腿员</text>
        <text class="notice-desc">通过资格审核后才能抢单，接单完成即可获得跑腿酬劳。</text>
        <button class="notice-btn" @tap="goApply">{{ runnerStatus === 'pending' ? '查看申请进度' : '申请成为跑腿' }}</button>
      </view>
      <template v-else>
        <view v-if="orders.length === 0" class="empty">暂无待接单需求，稍后再来看看。</view>
        <view v-else class="list">
          <view v-for="order in orders" :key="order.id" class="card">
            <view class="card-top">
              <text class="card-no">{{ order.order_no }}</text>
              <text class="reward">￥{{ expressMoney(order.total_cents) }}</text>
            </view>
            <view class="row"><text class="label">取件</text><text class="value">{{ order.pickup_site }}</text></view>
            <view class="row"><text class="label">送达</text><text class="value">{{ order.deliver_building }} {{ order.deliver_address }}</text></view>
            <view class="row"><text class="label">包裹</text><text class="value">{{ order.package_info || '—' }} × {{ order.package_count }}</text></view>
            <view class="row" v-if="order.expected_at"><text class="label">期望</text><text class="value">{{ order.expected_at }}</text></view>
            <button class="accept" :loading="busyId === order.id" :disabled="busyId === order.id" @tap="accept(order.id)">接单</button>
          </view>
        </view>
      </template>
    </template>
  </view>
</template>

<script setup lang="ts">
import { onShow } from '@dcloudio/uni-app'
import { ref } from 'vue'
import {
  acceptExpressPickupOrder,
  expressMoney,
  getExpressPickupRunnerProfile,
  listRunnerHall,
  type ExpressPickupOrder,
} from '@/api/express-pickup'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const orders = ref<ExpressPickupOrder[]>([])
const runnerStatus = ref<string>('none')
const loading = ref(false)
const busyId = ref<string | null>(null)

const toast = (title: string) => uni.showToast({ title, icon: 'none' })

const load = async () => {
  if (!auth.accessToken) {
    uni.navigateTo({ url: '/pages/login/index' })
    return
  }
  loading.value = true
  try {
    const profile = await getExpressPickupRunnerProfile(auth.accessToken)
    runnerStatus.value = profile.runner?.status ?? 'none'
    if (runnerStatus.value === 'active') {
      const hall = await listRunnerHall(auth.accessToken)
      orders.value = hall.items
    }
  } catch (error) {
    toast(error instanceof Error ? error.message : '加载失败')
  } finally {
    loading.value = false
  }
}

const accept = async (id: string) => {
  if (!auth.accessToken) return
  busyId.value = id
  try {
    await acceptExpressPickupOrder(auth.accessToken, id)
    toast('接单成功，请尽快取件')
    await load()
  } catch (error) {
    toast(error instanceof Error ? error.message : '接单失败')
  } finally {
    busyId.value = null
  }
}

const goApply = () => uni.navigateTo({ url: '/pages-express/runner-apply' })

onShow(() => { void load() })
</script>

<style scoped>
.page { min-height: 100vh; background: #f5f6f8; padding: 24rpx 24rpx 60rpx; box-sizing: border-box; }
.empty { padding: 160rpx 0; text-align: center; color: #9ca3af; font-size: 26rpx; }
.notice { background: #fff; border-radius: 20rpx; padding: 40rpx 32rpx; display: flex; flex-direction: column; align-items: center; }
.notice-title { font-size: 30rpx; font-weight: 600; color: #111827; }
.notice-desc { font-size: 24rpx; color: #6b7280; margin-top: 12rpx; text-align: center; line-height: 1.6; }
.notice-btn { margin-top: 24rpx; background: #2f6bff; color: #fff; border-radius: 999rpx; font-size: 28rpx; }
.list { display: flex; flex-direction: column; gap: 16rpx; }
.card { background: #fff; border-radius: 20rpx; padding: 24rpx; }
.card-top { display: flex; justify-content: space-between; align-items: center; margin-bottom: 12rpx; }
.card-no { font-size: 26rpx; color: #111827; font-weight: 600; }
.reward { font-size: 32rpx; color: #ef4444; font-weight: 700; }
.row { display: flex; font-size: 24rpx; margin-top: 6rpx; }
.label { width: 72rpx; color: #9ca3af; }
.value { flex: 1; color: #374151; }
.accept { margin-top: 20rpx; background: #2f6bff; color: #fff; border-radius: 999rpx; font-size: 28rpx; }
</style>
