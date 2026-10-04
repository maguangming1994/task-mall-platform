<template>
  <view class="page" v-if="order">
    <view class="status-card">
      <text class="status-text">{{ statusText }}</text>
      <text class="status-sub">{{ statusSub }}</text>
    </view>

    <view class="timeline-card">
      <view class="tl-head"><text class="tl-head-title">订单进度</text><text class="tl-head-sub">{{ order.order_no }}</text></view>
      <view class="tl">
        <view v-for="(row, idx) in steps" :key="idx" class="tl-row" :class="row.state">
          <view class="tl-dot" />
          <view class="tl-main">
            <text class="tl-title">{{ row.title }}</text>
            <text v-if="row.desc" class="tl-desc">{{ row.desc }}</text>
          </view>
        </view>
      </view>
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
      <button class="btn ghost support-btn" @click="contactSupport">客服</button>
      <button v-if="canCancel" class="btn ghost" @click="cancel">取消订单</button>
      <button v-if="canRefund" class="btn ghost" @click="goRefund">申请退款</button>
      <button v-else-if="showRefundEntry" class="btn ghost" @click="goRefund">退款/售后</button>
      <button v-if="canPay" class="btn primary" @click="pay">立即支付</button>
      <button v-if="canShip" class="btn primary" @click="goShip">{{ order.trade_type === 'meetup' ? '确认交付' : '发货' }}</button>
      <button v-if="canComplete" class="btn primary" @click="complete">确认收货</button>
      <button v-if="canRate" class="btn primary" @click="goRate">评价</button>
    </view>
  </view>
</template>

<script setup lang="ts">
import { onLoad, onShow } from '@dcloudio/uni-app'
import { computed, ref } from 'vue'
import { useAuthStore } from '@/stores/auth'
import {
  getSecondHandOrder, paySecondHandOrder,
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
  refunded: { text: '已退款', sub: '退款已退回钱包余额' },
}

const statusText = computed(() => statusMap[order.value?.status || '']?.text || order.value?.status)
const statusSub = computed(() => statusMap[order.value?.status || '']?.sub || '')
const tradeTypeText = computed(() => ({ meetup: '当面交易', mail: '邮寄', both: '均可' }[order.value?.trade_type || ''] || order.value?.trade_type))

const canPay = computed(() => order.value?.role === 'buyer' && order.value?.status === 'pending_payment')
const canShip = computed(() => order.value?.role === 'seller' && order.value?.status === 'paid')
const canComplete = computed(() => order.value?.role === 'buyer' && ['paid', 'shipped'].includes(order.value?.status || ''))
const canCancel = computed(() => ['pending_payment', 'paid'].includes(order.value?.status || ''))
const canRefund = computed(() => order.value?.role === 'buyer' && ['paid', 'shipped'].includes(order.value?.status || '') && !order.value?.has_refund)
const showRefundEntry = computed(() => order.value?.has_refund === true)
const canRate = computed(() => order.value?.status === 'completed' && !order.value?.has_rated)

type StepState = 'done' | 'doing' | 'cancel' | 'todo'
interface OrderStep { title: string; desc: string; state: StepState }
const steps = computed<OrderStep[]>(() => {
  const o = order.value
  if (!o) return []
  const shipLabel = o.trade_type === 'meetup' ? '卖家确认交付' : '卖家已发货'
  const rows: OrderStep[] = [{ title: '订单创建', desc: `${formatTime(o.created_at)} · 二手闲置交易`, state: 'done' }]
  switch (o.status) {
    case 'pending_payment':
      rows.push({ title: '等待买家支付', desc: '请在 30 分钟内完成支付', state: 'doing' })
      break
    case 'paid':
      rows.push({ title: '买家已支付', desc: `${formatTime(o.paid_at)} · 钱包托管 ¥${o.price}`, state: 'done' })
      rows.push({ title: o.trade_type === 'meetup' ? '等待卖家确认交付' : '等待卖家发货', desc: '货款由平台托管，确认收货后结算给卖家', state: 'doing' })
      break
    case 'shipped':
      rows.push({ title: '买家已支付', desc: `${formatTime(o.paid_at)} · 钱包托管 ¥${o.price}`, state: 'done' })
      rows.push({ title: shipLabel, desc: formatTime(o.shipped_at), state: 'done' })
      rows.push({ title: '等待买家确认收货', desc: '收到商品并确认无误后点击确认收货', state: 'doing' })
      break
    case 'completed':
      rows.push({ title: '买家已支付', desc: `${formatTime(o.paid_at)} · 钱包托管 ¥${o.price}`, state: 'done' })
      if (o.shipped_at) rows.push({ title: shipLabel, desc: formatTime(o.shipped_at), state: 'done' })
      rows.push({ title: '交易完成', desc: `${formatTime(o.completed_at)} · 货款已结算给卖家`, state: 'done' })
      break
    case 'cancelled':
      rows.push({ title: '订单已取消', desc: '交易关闭', state: 'cancel' })
      break
    case 'refunded':
      if (o.paid_at) rows.push({ title: '买家已支付', desc: `${formatTime(o.paid_at)} · 钱包托管 ¥${o.price}`, state: 'done' })
      if (o.shipped_at) rows.push({ title: shipLabel, desc: formatTime(o.shipped_at), state: 'done' })
      rows.push({ title: '退款完成', desc: '款项已退回买家钱包余额', state: 'cancel' })
      break
    default:
      rows.push({ title: '订单处理中', desc: o.status, state: 'doing' })
  }
  return rows.reverse()
})

function contactSupport() { uni.navigateTo({ url: '/pages/support/index' }) }

function resolveCover(id: string | null) { return resolveApiAssetUrl(id) }
function formatTime(s: string | null) { return formatDateTime(s, '') }

onLoad((q) => { orderId.value = q?.id || '' })
onShow(() => { if (orderId.value) load() })

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

function goShip() { if (order.value) uni.navigateTo({ url: `/pages/second-hand-ship/index?id=${order.value.id}` }) }
function goRate() { if (order.value) uni.navigateTo({ url: `/pages/second-hand-rate/index?id=${order.value.id}` }) }
function goRefund() { if (order.value) uni.navigateTo({ url: `/pages/second-hand-refund/index?id=${order.value.id}` }) }

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
.support-btn { margin-right: auto; color: #07c160; background: #e9f9f0; }
.timeline-card { background: #fff; margin-top: 16rpx; padding: 28rpx 28rpx 12rpx; }
.tl-head { display: flex; align-items: baseline; justify-content: space-between; margin-bottom: 12rpx; }
.tl-head-title { font-size: 30rpx; font-weight: 700; color: #1a1a1b; }
.tl-head-sub { font-size: 22rpx; color: #9aa0a6; }
.tl-row { display: flex; gap: 22rpx; position: relative; padding-bottom: 32rpx; }
.tl-row:last-child { padding-bottom: 8rpx; }
.tl-row::before { content: ''; position: absolute; left: 13rpx; top: 32rpx; bottom: 0; width: 4rpx; background: #e8ebe9; }
.tl-row:last-child::before { display: none; }
.tl-dot { width: 28rpx; height: 28rpx; border-radius: 50%; background: #fff; border: 4rpx solid #d6dad8; flex: none; margin-top: 4rpx; position: relative; z-index: 1; }
.tl-row.done .tl-dot { border-color: #07c160; background: #07c160; }
.tl-row.doing .tl-dot { border-color: #ff7a1a; background: #fff; box-shadow: 0 0 0 8rpx #fff2e5; }
.tl-row.cancel .tl-dot { border-color: #9aa0a6; background: #9aa0a6; }
.tl-main { flex: 1; min-width: 0; }
.tl-title { font-size: 27rpx; font-weight: 600; color: #1a1a1b; display: block; }
.tl-row.doing .tl-title { color: #f2620a; }
.tl-row.cancel .tl-title { color: #6e6e73; }
.tl-desc { font-size: 22rpx; color: #9aa0a6; margin-top: 6rpx; line-height: 1.5; display: block; }
.btn.ghost { background: #f5f5f7; color: #6e6e73; }
.btn.primary { background: #ff2442; color: #fff; }
</style>
