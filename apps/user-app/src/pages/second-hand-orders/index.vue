<template>
  <view class="page">
    <view class="tabs">
      <view :class="['tab', { active: role === 'buyer' }]" @click="role = 'buyer'; load()">我买到的</view>
      <view :class="['tab', { active: role === 'seller' }]" @click="role = 'seller'; load()">我卖出的</view>
    </view>

    <view v-if="loading" class="list">
      <view v-for="i in 3" :key="i" class="card tm-skeleton" style="height: 240rpx; margin: 20rpx 24rpx; border-radius: 32rpx;" />
    </view>
    <view v-else-if="orders.length" class="list">
      <view v-for="o in orders" :key="o.id" class="card" @click="openDetail(o.id)">
        <view class="card-head">
          <text class="order-no">订单号 {{ o.order_no }}</text>
          <text :class="['tag', statusClass(o.status)]">{{ statusText(o.status) }}</text>
        </view>
        <view class="card-body">
          <image v-if="o.cover" class="cover" :src="resolveCover(o.cover)" mode="aspectFill" />
          <view v-else class="cover placeholder"><text>{{ o.title.slice(0, 1) }}</text></view>
          <view class="info">
            <text class="title">{{ o.title }}</text>
            <text class="sub">{{ role === 'buyer' ? '卖家' : '买家' }} {{ o.counterparty?.nickname || '' }} · {{ tradeText(o.trade_type) }}</text>
            <text class="price">¥{{ o.price }}</text>
          </view>
        </view>
        <view class="card-foot">
          <text class="time">{{ formatTime(o.created_at) }}</text>
          <text class="detail-link">查看详情 ›</text>
        </view>
      </view>
    </view>
    <view v-else class="empty"><text>暂无订单</text></view>
  </view>
</template>

<script setup lang="ts">
import { onLoad, onShow } from '@dcloudio/uni-app'
import { ref } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { listSecondHandOrders, type SecondHandOrder } from '@/api/second-hand'
import { resolveApiAssetUrl } from '@/api/http'
import { formatDate } from '@/utils/dateTime'

const auth = useAuthStore()
const role = ref<'buyer' | 'seller'>('buyer')
const orders = ref<SecondHandOrder[]>([])
const loading = ref(false)

function resolveCover(id: string | null) { return resolveApiAssetUrl(id) }
function formatTime(s: string | null) { return formatDate(s, '') }

function statusText(s: string) {
  return ({ pending_payment: '待付款', paid: '待发货', shipped: '待收货', completed: '已完成', cancelled: '已取消' } as Record<string, string>)[s] || s
}

function statusClass(s: string) {
  if (s === 'pending_payment') return 'tag-orange'
  if (s === 'completed') return 'tag-green'
  return ''
}

function tradeText(t: string) {
  return ({ meetup: '校内面交', mail: '邮寄', both: '面交/邮寄' } as Record<string, string>)[t] || t
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

onLoad((options?: Record<string, string | undefined>) => {
  if (options?.role === 'seller' || options?.role === 'buyer') role.value = options.role
})
onShow(() => load())
</script>

<style lang="scss" scoped>
.page { min-height: 100vh; background: #F4F6F4; }

/* Tab */
.tabs { display: flex; background: #fff; border-bottom: 1rpx solid #EDEFEF; position: sticky; top: 0; z-index: 10; }
.tab { flex: 1; text-align: center; padding: 26rpx 0; font-size: 28rpx; color: #767C85; position: relative; }
.tab.active { font-size: 30rpx; font-weight: 800; color: #191B1F; }
.tab.active::after { content: ''; position: absolute; left: 50%; bottom: 0; transform: translateX(-50%); width: 44rpx; height: 6rpx; border-radius: 3rpx; background: #0BBF63; }

/* 订单卡 */
.list { padding: 20rpx 0; }
.card { background: #fff; margin: 0 24rpx 20rpx; border-radius: 32rpx; padding: 26rpx 26rpx 22rpx; }
.card-head { display: flex; align-items: center; }
.order-no { font-size: 22rpx; color: #AEB3BB; }
.tag { margin-left: auto; display: inline-flex; align-items: center; height: 36rpx; padding: 0 12rpx; border-radius: 10rpx; font-size: 20rpx; font-weight: 500; background: #F2F4F3; color: #767C85; }
.tag-orange { background: #FFF2E5; color: #FF7A1A; }
.tag-green { background: #E7F9EF; color: #08A152; }
.tag-blue { background: #EAF2FF; color: #3B82F6; }
.card-body { display: flex; gap: 20rpx; margin-top: 22rpx; }
.cover { width: 132rpx; height: 132rpx; border-radius: 20rpx; background: #EDEFEF; flex: none; }
.cover.placeholder { display: flex; align-items: center; justify-content: center; color: #AEB3BB; font-size: 40rpx; }
.info { flex: 1; min-width: 0; }
.title { font-size: 26rpx; color: #191B1F; line-height: 1.45; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
.sub { display: block; font-size: 22rpx; color: #AEB3BB; margin-top: 8rpx; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.price { display: block; font-size: 30rpx; color: #FF2442; font-weight: 800; margin-top: 10rpx; }
.card-foot { display: flex; align-items: center; margin-top: 20rpx; padding-top: 18rpx; border-top: 1rpx solid #F5F6F6; }
.time { font-size: 22rpx; color: #AEB3BB; }
.detail-link { margin-left: auto; font-size: 24rpx; color: #08A152; font-weight: 600; }
.empty { text-align: center; padding: 160rpx 0; color: #AEB3BB; font-size: 26rpx; }
</style>
