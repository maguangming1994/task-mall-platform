<template>
  <view class="page">
    <view class="tabs">
      <view :class="['tab', { active: role === 'buyer' }]" @click="role = 'buyer'; load()">我买到的</view>
      <view :class="['tab', { active: role === 'seller' }]" @click="role = 'seller'; load()">我卖出的</view>
    </view>

    <view v-if="loading" class="list"><view v-for="i in 3" :key="i" class="card tm-skeleton" style="height: 160rpx; margin: 20rpx 28rpx; border-radius: 16rpx;" /></view>
    <view v-else-if="orders.length" class="list">
      <view v-for="o in orders" :key="o.id" class="card" @click="openDetail(o.id)">
        <view class="card-head">
          <text class="status">{{ statusText(o.status) }}</text>
          <text class="order-no">{{ o.order_no }}</text>
        </view>
        <view class="card-body">
          <image v-if="o.cover" class="cover" :src="resolveCover(o.cover)" mode="aspectFill" />
          <view v-else class="cover placeholder"><text>{{ o.title.slice(0, 1) }}</text></view>
          <view class="info">
            <text class="title">{{ o.title }}</text>
            <text class="price">¥{{ o.price }}</text>
          </view>
        </view>
        <view class="card-foot">
          <text class="counterparty">{{ o.counterparty?.nickname || '' }}</text>
          <text class="time">{{ formatTime(o.created_at) }}</text>
        </view>
      </view>
    </view>
    <view v-else class="empty"><text>暂无订单</text></view>
  </view>
</template>

<script setup lang="ts">
import { onShow } from '@dcloudio/uni-app'
import { ref } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { listSecondHandOrders, type SecondHandOrder } from '@/api/second-hand'
import { resolveApiAssetUrl } from '@/api/http'

const auth = useAuthStore()
const role = ref<'buyer' | 'seller'>('buyer')
const orders = ref<SecondHandOrder[]>([])
const loading = ref(false)

function resolveCover(id: string | null) { return resolveApiAssetUrl(id) }
function formatTime(s: string | null) { return s ? new Date(s).toLocaleDateString('zh-CN') : '' }

function statusText(s: string) {
  return ({ pending_payment: '待付款', paid: '待发货', shipped: '待收货', completed: '已完成', cancelled: '已取消' } as Record<string, string>)[s] || s
}

async function load() {
  if (!auth.accessToken) return
  loading.value = true
  try {
    const res = await listSecondHandOrders(auth.accessToken, { role: role.value })
    orders.value = res.items
  } catch { uni.showToast({ title: '加载失败', icon: 'none' }) } finally { loading.value = false }
}

function openDetail(id: string) { uni.navigateTo({ url: `/pages/second-hand-order-detail/index?id=${id}` }) }

onShow(() => load())
</script>

<style lang="scss" scoped>
.page { min-height: 100vh; background: #f6f6f8; }
.tabs { display: flex; background: #fff; position: sticky; top: 0; z-index: 10; }
.tab { flex: 1; text-align: center; padding: 28rpx 0; font-size: 30rpx; color: #6e6e73; }
.tab.active { color: #07c160; font-weight: 600; border-bottom: 4rpx solid #07c160; }
.list { padding: 20rpx 0; }
.card { background: #fff; margin: 0 28rpx 20rpx; border-radius: 16rpx; padding: 24rpx; }
.card-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16rpx; }
.status { font-size: 26rpx; color: #07c160; font-weight: 600; }
.order-no { font-size: 22rpx; color: #a0a0a5; }
.card-body { display: flex; gap: 20rpx; }
.cover { width: 120rpx; height: 120rpx; border-radius: 12rpx; background: #eee; }
.cover.placeholder { display: flex; align-items: center; justify-content: center; color: #ccc; font-size: 36rpx; }
.info { flex: 1; }
.title { font-size: 28rpx; color: #1a1a1b; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
.price { font-size: 32rpx; color: #ff2442; font-weight: 700; margin-top: 12rpx; display: block; }
.card-foot { display: flex; justify-content: space-between; margin-top: 16rpx; font-size: 22rpx; color: #a0a0a5; }
.empty { text-align: center; padding: 160rpx 0; color: #a0a0a5; font-size: 28rpx; }
</style>
