<template>
  <view class="page" v-if="order">
    <view class="status-card">
      <text class="status-text">{{ statusText }}</text>
      <text class="status-sub">{{ statusSub }}</text>
    </view>

    <view class="item-card" @click="openItem">
      <image v-if="order.cover" class="cover" :src="resolveCover(order.cover)" mode="aspectFill" />
      <view v-else class="cover placeholder"><text>{{ order.title.slice(0, 1) }}</text></view>
      <view class="item-info">
        <text class="item-title">{{ order.title }}</text>
        <text class="item-price">¥{{ order.price }}</text>
      </view>
    </view>

    <view class="info-card">
      <view class="row"><text class="k">订单编号</text><text class="v">{{ order.order_no }}</text></view>
      <view class="row"><text class="k">交易方式</text><text class="v">{{ tradeTypeText }}</text></view>
      <view class="row"><text class="k">下单时间</text><text class="v">{{ formatTime(order.created_at) }}</text></view>
      <view v-if="order.paid_at" class="row"><text class="k">付款时间</text><text class="v">{{ formatTime(order.paid_at) }}</text></view>
      <view v-if="order.shipped_at" class="row"><text class="k">发货时间</text><text class="v">{{ formatTime(order.shipped_at) }}</text></view>
      <view v-if="order.completed_at" class="row"><text class="k">完成时间</text><text class="v">{{ formatTime(order.completed_at) }}</text></view>
    </view>

    <view class="party-card" v-if="order.counterparty">
      <image class="avatar" :src="resolveCover(order.counterparty.avatar)" mode="aspectFill" />
      <text class="party-name">{{ order.counterparty.nickname }}</text>
      <view class="chat-btn" @click="goChat">联系{{ order.role === 'buyer' ? '卖家' : '买家' }}</view>
    </view>

    <view class="action-bar">
      <button v-if="canCancel" class="btn ghost" @click="cancel">取消订单</button>
      <button v-if="canPay" class="btn primary" @click="pay">立即支付</button>
      <button v-if="canShip" class="btn primary" @click="ship">{{ order.trade_type === 'meetup' ? '确认交付' : '发货' }}</button>
      <button v-if="canComplete" class="btn primary" @click="complete">确认收货</button>
    </view>
  </view>
</template>

<script setup lang="ts">
import { onLoad } from '@dcloudio/uni-app'
import { computed, ref } from 'vue'
import { useAuthStore } from '@/stores/auth'
import {
  getSecondHandOrder, paySecondHandOrder, shipSecondHandOrder,
  completeSecondHandOrder, cancelSecondHandOrder, type SecondHandOrder,
} from '@/api/second-hand'
import { ensureChatConversation } from '@/api/chat'
import { resolveApiAssetUrl } from '@/api/http'
import { formatDateTime } from '@/utils/dateTime'

const auth = useAuthStore()
const order = ref<SecondHandOrder | null>(null)
const orderId = ref('')

const statusMap: Record<string, { text: string; sub: string }> = {
  pending_payment: { text: '待付款', sub: '请在 30 分钟内完成支付' },
  paid: { text: '待发货', sub: '等待卖家发货' },
  shipped: { text: '待收货', sub: '卖家已发货，请确认收货' },
  completed: { text: '已完成', sub: '交易已完成' },
  cancelled: { text: '已取消', sub: '订单已取消' },
}

const statusText = computed(() => statusMap[order.value?.status || '']?.text || order.value?.status)
const statusSub = computed(() => statusMap[order.value?.status || '']?.sub || '')
const tradeTypeText = computed(() => ({ meetup: '当面交易', mail: '邮寄', both: '均可' }[order.value?.trade_type || ''] || order.value?.trade_type))

const canPay = computed(() => order.value?.role === 'buyer' && order.value?.status === 'pending_payment')
const canShip = computed(() => order.value?.role === 'seller' && order.value?.status === 'paid')
const canComplete = computed(() => order.value?.role === 'buyer' && ['paid', 'shipped'].includes(order.value?.status || ''))
const canCancel = computed(() => ['pending_payment', 'paid'].includes(order.value?.status || ''))

function resolveCover(id: string | null) { return resolveApiAssetUrl(id) }
function formatTime(s: string | null) { return formatDateTime(s, '') }

onLoad((q) => { orderId.value = q?.id || ''; load() })

async function load() {
  if (!auth.accessToken || !orderId.value) return
  try { order.value = await getSecondHandOrder(auth.accessToken, orderId.value) } catch { uni.showToast({ title: '加载失败', icon: 'none' }) }
}

function openItem() { if (order.value?.item_id) uni.navigateTo({ url: `/pages/second-hand-detail/index?id=${order.value.item_id}` }) }

async function goChat() {
  if (!order.value?.counterparty?.id) return
  try {
    const conv = await ensureChatConversation(auth.accessToken, { user_id: order.value.counterparty.id, item_id: order.value.item_id || undefined, order_id: order.value.id })
    uni.navigateTo({ url: `/pages/chat-detail/index?id=${conv.id}` })
  } catch { uni.showToast({ title: '发起聊天失败', icon: 'none' }) }
}

async function pay() {
  if (!order.value) return
  try {
    uni.showLoading({ title: '支付中' })
    order.value = await paySecondHandOrder(auth.accessToken, order.value.id)
    uni.showToast({ title: '支付成功', icon: 'success' })
  } catch (e: any) { uni.showToast({ title: e.message || '支付失败', icon: 'none' }) } finally { uni.hideLoading() }
}

async function ship() {
  if (!order.value) return
  try { order.value = await shipSecondHandOrder(auth.accessToken, order.value.id); uni.showToast({ title: '已发货', icon: 'success' }) } catch (e: any) { uni.showToast({ title: e.message || '操作失败', icon: 'none' }) }
}

async function complete() {
  if (!order.value) return
  uni.showModal({ title: '确认收货', content: '确认已收到商品？', success: async (r) => {
    if (!r.confirm) return
    try { order.value = await completeSecondHandOrder(auth.accessToken, order.value!.id); uni.showToast({ title: '交易完成', icon: 'success' }) } catch (e: any) { uni.showToast({ title: e.message || '操作失败', icon: 'none' }) }
  } })
}

async function cancel() {
  if (!order.value) return
  uni.showModal({ title: '取消订单', content: '确定取消该订单？', success: async (r) => {
    if (!r.confirm) return
    try { order.value = await cancelSecondHandOrder(auth.accessToken, order.value!.id); uni.showToast({ title: '已取消', icon: 'success' }) } catch (e: any) { uni.showToast({ title: e.message || '操作失败', icon: 'none' }) }
  } })
}
</script>

<style lang="scss" scoped>
.page { min-height: 100vh; background: #f6f6f8; padding-bottom: 160rpx; }
.status-card { background: #fff; padding: 40rpx 28rpx; }
.status-text { font-size: 40rpx; font-weight: 700; color: #07c160; display: block; }
.status-sub { font-size: 26rpx; color: #6e6e73; margin-top: 8rpx; display: block; }
.item-card, .info-card, .party-card { background: #fff; margin-top: 16rpx; padding: 24rpx 28rpx; }
.item-card { display: flex; gap: 20rpx; align-items: center; }
.cover { width: 140rpx; height: 140rpx; border-radius: 12rpx; background: #eee; }
.cover.placeholder { display: flex; align-items: center; justify-content: center; color: #ccc; font-size: 40rpx; }
.item-info { flex: 1; }
.item-title { font-size: 28rpx; color: #1a1a1b; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
.item-price { font-size: 32rpx; color: #ff2442; font-weight: 700; margin-top: 12rpx; display: block; }
.row { display: flex; justify-content: space-between; padding: 16rpx 0; font-size: 26rpx; }
.k { color: #6e6e73; }
.v { color: #1a1a1b; }
.party-card { display: flex; align-items: center; gap: 16rpx; }
.avatar { width: 64rpx; height: 64rpx; border-radius: 50%; background: #eee; }
.party-name { flex: 1; font-size: 28rpx; color: #1a1a1b; }
.chat-btn { background: #07c160; color: #fff; padding: 12rpx 28rpx; border-radius: 28rpx; font-size: 24rpx; }
.action-bar { position: fixed; bottom: 0; left: 0; right: 0; padding: 20rpx 28rpx; background: #fff; border-top: 1rpx solid #ececee; display: flex; gap: 20rpx; justify-content: flex-end; }
.btn { height: 72rpx; line-height: 72rpx; border-radius: 36rpx; font-size: 28rpx; padding: 0 40rpx; }
.btn.ghost { background: #f5f5f7; color: #6e6e73; }
.btn.primary { background: #ff2442; color: #fff; }
</style>
