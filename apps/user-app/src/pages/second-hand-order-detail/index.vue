<template>
  <view class="page" v-if="order">
    <!-- 状态横幅 -->
    <view class="status-banner">
      <text class="status-text">{{ statusText }}</text>
      <text class="status-sub">{{ statusSub }}</text>
    </view>

    <!-- 订单进度 -->
    <view class="card timeline-card">
      <view class="sec-title">
        <text class="sec-h">订单进度</text>
        <text class="sec-more">订单号 {{ order.order_no }}</text>
      </view>
      <view class="tl">
        <view v-for="(t, idx) in timeline" :key="t.label" class="tl-row">
          <view class="tl-rail">
            <view class="tl-dot" />
            <view v-if="idx < timeline.length - 1" class="tl-line" />
          </view>
          <view class="tl-main">
            <text class="tl-title">{{ t.label }}</text>
            <text class="tl-time">{{ t.time }}</text>
          </view>
        </view>
      </view>
    </view>

    <!-- 商品信息 -->
    <view class="card">
      <view class="goods-row" @click="openItem">
        <image v-if="order.cover" class="cover" :src="resolveCover(order.cover)" mode="aspectFill" />
        <view v-else class="cover placeholder"><text>{{ order.title.slice(0, 1) }}</text></view>
        <view class="goods-main">
          <text class="goods-title">{{ order.title }}</text>
          <text class="goods-sub" v-if="order.counterparty">{{ order.role === 'buyer' ? '卖家' : '买家' }} {{ order.counterparty.nickname }}</text>
        </view>
        <text class="goods-price">¥{{ order.price }}</text>
      </view>
      <view class="kv-row"><text class="kv-k">交易方式</text><text class="kv-v">{{ tradeTypeText }}</text></view>
      <view class="kv-row"><text class="kv-k">下单时间</text><text class="kv-v">{{ formatTime(order.created_at) }}</text></view>
      <view v-if="order.paid_at" class="kv-row"><text class="kv-k">付款时间</text><text class="kv-v">{{ formatTime(order.paid_at) }}</text></view>
      <view v-if="order.shipped_at" class="kv-row"><text class="kv-k">发货时间</text><text class="kv-v">{{ formatTime(order.shipped_at) }}</text></view>
      <view v-if="order.completed_at" class="kv-row"><text class="kv-k">完成时间</text><text class="kv-v">{{ formatTime(order.completed_at) }}</text></view>
      <view class="kv-row"><text class="kv-k">实付款</text><text class="kv-price">¥{{ order.price }}</text></view>
    </view>

    <!-- 联系对方 -->
    <view class="card party-card" v-if="order.counterparty" @click="goChat">
      <image class="avatar" :src="resolveCover(order.counterparty.avatar)" mode="aspectFill" />
      <text class="party-name">{{ order.counterparty.nickname }}</text>
      <view class="chat-btn">联系{{ order.role === 'buyer' ? '卖家' : '买家' }}</view>
    </view>

    <!-- 托管提示 -->
    <view class="card tip-card">
      <view class="tip-ic"><text>✓</text></view>
      <text class="tip-text">货款由平台托管，确认收货后结算给卖家；未收到货请勿提前确认。</text>
    </view>

    <view class="action-bar" v-if="canCancel || canPay || canShip || canComplete">
      <button v-if="canCancel" class="ab-duo ghost" @click="cancel">取消订单</button>
      <button v-if="canPay" class="ab-main" @click="pay">立即支付</button>
      <button v-if="canShip" class="ab-main" @click="ship">{{ order.trade_type === 'meetup' ? '确认交付' : '确认发货' }}</button>
      <button v-if="canComplete" class="ab-main" @click="complete">确认收货</button>
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

const timeline = computed(() => {
  const o = order.value
  if (!o) return [] as { label: string; time: string }[]
  const list: { label: string; time: string }[] = []
  if (o.completed_at) list.push({ label: '交易完成', time: formatTime(o.completed_at) })
  if (o.shipped_at) list.push({ label: '卖家已发货', time: formatTime(o.shipped_at) })
  if (o.paid_at) list.push({ label: '买家已支付', time: formatTime(o.paid_at) })
  list.push({ label: '订单创建', time: formatTime(o.created_at) })
  return list
})

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
.page { min-height: 100vh; background: #F4F6F4; padding-bottom: 180rpx; }

/* 状态横幅 */
.status-banner { background: linear-gradient(135deg, #12D978, #08A152); padding: 28rpx 36rpx 44rpx; color: #fff; }
.status-text { display: block; font-size: 36rpx; font-weight: 800; }
.status-sub { display: block; font-size: 24rpx; opacity: .85; margin-top: 10rpx; line-height: 1.6; }

/* 卡片 */
.card { background: #fff; border-radius: 32rpx; margin: 20rpx 24rpx 0; overflow: hidden; }
.timeline-card { margin-top: -24rpx; position: relative; z-index: 2; }
.sec-title { display: flex; align-items: center; padding: 26rpx 30rpx 16rpx; }
.sec-h { font-size: 30rpx; font-weight: 700; color: #191B1F; display: flex; align-items: center; }
.sec-h::before { content: ''; display: inline-block; width: 8rpx; height: 28rpx; border-radius: 4rpx; background: #0BBF63; margin-right: 14rpx; }
.sec-more { margin-left: auto; font-size: 22rpx; color: #AEB3BB; }

/* 时间线 */
.tl { padding: 4rpx 30rpx 28rpx; }
.tl-row { display: flex; gap: 22rpx; }
.tl-rail { display: flex; flex-direction: column; align-items: center; }
.tl-dot { width: 20rpx; height: 20rpx; border-radius: 50%; background: #0BBF63; flex: none; margin-top: 8rpx; }
.tl-line { width: 4rpx; flex: 1; background: #0BBF63; opacity: .3; }
.tl-main { padding-bottom: 28rpx; }
.tl-row:last-child .tl-main { padding-bottom: 4rpx; }
.tl-title { display: block; font-size: 26rpx; font-weight: 600; color: #191B1F; }
.tl-time { display: block; font-size: 22rpx; color: #AEB3BB; margin-top: 6rpx; }

/* 商品 */
.goods-row { display: flex; gap: 22rpx; align-items: center; padding: 26rpx 28rpx; }
.cover { width: 124rpx; height: 124rpx; border-radius: 20rpx; background: #EDEFEF; flex: none; }
.cover.placeholder { display: flex; align-items: center; justify-content: center; color: #AEB3BB; font-size: 40rpx; }
.goods-main { flex: 1; min-width: 0; }
.goods-title { font-size: 26rpx; color: #191B1F; line-height: 1.4; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
.goods-sub { display: block; font-size: 22rpx; color: #AEB3BB; margin-top: 8rpx; }
.goods-price { font-size: 30rpx; color: #FF2442; font-weight: 800; flex: none; }
.kv-row { border-top: 1rpx solid #F5F6F6; padding: 22rpx 28rpx; display: flex; justify-content: space-between; font-size: 24rpx; color: #767C85; }
.kv-v { color: #191B1F; }
.kv-price { color: #FF2442; font-size: 30rpx; font-weight: 800; }

/* 联系对方 */
.party-card { display: flex; align-items: center; gap: 18rpx; padding: 24rpx 28rpx; }
.avatar { width: 72rpx; height: 72rpx; border-radius: 50%; background: #EDEFEF; flex: none; }
.party-name { flex: 1; font-size: 28rpx; color: #191B1F; font-weight: 600; }
.chat-btn { height: 60rpx; line-height: 60rpx; padding: 0 30rpx; border-radius: 30rpx; background: #E7F9EF; color: #08A152; font-size: 24rpx; font-weight: 700; }

/* 托管提示 */
.tip-card { padding: 24rpx 28rpx; display: flex; align-items: center; gap: 18rpx; background: #FFF8F2; }
.tip-ic { width: 60rpx; height: 60rpx; border-radius: 18rpx; background: #FFF2E5; color: #F2620A; display: flex; align-items: center; justify-content: center; flex: none; font-size: 26rpx; font-weight: 700; }
.tip-text { font-size: 22rpx; color: #767C85; line-height: 1.5; }

/* 底部操作栏 */
.action-bar { position: fixed; bottom: 0; left: 0; right: 0; padding: 16rpx 24rpx calc(16rpx + env(safe-area-inset-bottom)); background: #fff; border-top: 1rpx solid #EDEFEF; display: flex; gap: 16rpx; }
.ab-duo { height: 84rpx; line-height: 84rpx; border-radius: 42rpx; font-size: 28rpx; font-weight: 700; flex: 1; margin: 0; }
.ab-duo.ghost { background: #F2F4F3; color: #767C85; }
.ab-main { height: 84rpx; line-height: 84rpx; border-radius: 42rpx; background: linear-gradient(135deg, #12D978, #08A152); color: #fff; font-size: 30rpx; font-weight: 700; flex: 1.4; margin: 0; box-shadow: 0 12rpx 28rpx rgba(11, 191, 99, .3); }
.ab-duo::after, .ab-main::after { border: 0; }
</style>
