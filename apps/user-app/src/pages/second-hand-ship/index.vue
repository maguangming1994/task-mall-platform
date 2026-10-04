<template>
  <view class="page" v-if="order">
    <!-- 订单与买家 -->
    <view class="card">
      <view class="order-line">
        <text class="order-no">订单 {{ order.order_no }}</text>
        <text class="tag">待发货</text>
      </view>
      <view class="goods" @click="openItem">
        <image v-if="order.cover" class="cover" :src="resolveCover(order.cover)" mode="aspectFill" />
        <view v-else class="cover placeholder"><text>{{ order.title.slice(0, 1) }}</text></view>
        <view class="goods-main">
          <text class="goods-title">{{ order.title }}</text>
          <text v-if="order.counterparty" class="goods-sub">买家 {{ order.counterparty.nickname }}</text>
        </view>
        <text class="goods-price">¥{{ order.price }}</text>
      </view>
      <view class="kv"><text class="k">交易方式</text><text class="v">{{ tradeTypeText }}</text></view>
      <view v-if="addressText" class="kv"><text class="k">收货地址</text><text class="v">{{ addressText }}</text></view>
    </view>

    <!-- 结算提示 -->
    <view class="tip">
      <view class="tip-ic"><text>✓</text></view>
      <text class="tip-text">买家确认收货后，货款 ¥{{ order.price }} 将立即结算到你的钱包余额。</text>
    </view>

    <view class="action-bar">
      <button class="ab-ghost" :disabled="submitting" @click="contact">联系买家</button>
      <button class="ab-main" :disabled="submitting" @click="confirmShip">{{ order.trade_type === 'meetup' ? '确认交付' : '确认发货' }}</button>
    </view>
  </view>
</template>

<script setup lang="ts">
import { onLoad } from '@dcloudio/uni-app'
import { computed, ref } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { getSecondHandOrder, shipSecondHandOrder, type SecondHandOrder } from '@/api/second-hand'
import { ensureChatConversation } from '@/api/chat'
import { resolveApiAssetUrl } from '@/api/http'

const auth = useAuthStore()
const order = ref<SecondHandOrder | null>(null)
const orderId = ref('')
const submitting = ref(false)

const tradeTypeText = computed(() => ({ meetup: '校内面交', mail: '邮寄快递', both: '面交/邮寄' }[order.value?.trade_type || ''] || order.value?.trade_type))
const addressText = computed(() => {
  const addr = order.value?.address
  if (!addr) return ''
  const who = [addr['name'], addr['phone']].filter(Boolean).join(' ')
  const parts = ['province', 'city', 'district', 'detail'].map((k) => addr[k]).filter(Boolean)
  return [...(who ? [who] : []), ...parts].join('，')
})

function resolveCover(id: string | null) { return resolveApiAssetUrl(id) }

onLoad((q) => { orderId.value = q?.id || ''; load() })

async function load() {
  if (!auth.accessToken || !orderId.value) return
  try {
    const data = await getSecondHandOrder(auth.accessToken, orderId.value)
    if (data.role !== 'seller' || data.status !== 'paid') {
      uni.showToast({ title: '当前订单无需发货', icon: 'none' })
      setTimeout(() => uni.navigateBack(), 600)
      return
    }
    order.value = data
  } catch { uni.showToast({ title: '加载失败', icon: 'none' }) }
}

function openItem() { if (order.value?.item_id) uni.navigateTo({ url: `/pages/second-hand-detail/index?id=${order.value.item_id}` }) }

async function contact() {
  if (!order.value?.counterparty?.id) return
  try {
    const conv = await ensureChatConversation(auth.accessToken, { user_id: order.value.counterparty.id, item_id: order.value.item_id || undefined, order_id: order.value.id })
    uni.navigateTo({ url: `/pages/chat-detail/index?id=${conv.id}` })
  } catch { uni.showToast({ title: '发起聊天失败', icon: 'none' }) }
}

function confirmShip() {
  if (!order.value || submitting.value) return
  const meetup = order.value.trade_type === 'meetup'
  uni.showModal({
    title: meetup ? '确认交付' : '确认发货',
    content: meetup ? '确认已与买家当面交付商品？' : '确认商品已发出？',
    success: async (r) => {
      if (!r.confirm || !order.value) return
      submitting.value = true
      try {
        await shipSecondHandOrder(auth.accessToken, order.value.id)
        uni.showToast({ title: meetup ? '已确认交付' : '已发货', icon: 'success' })
        setTimeout(() => uni.navigateBack(), 600)
      } catch (e: any) { uni.showToast({ title: e.message || '操作失败', icon: 'none' }); submitting.value = false }
    },
  })
}
</script>

<style scoped>
.page { --green:#0BBF63; --green-deep:#08A152; --green-grad:linear-gradient(135deg,#12D978,#08A152); --green-soft:#E7F9EF; --orange-soft:#FFF2E5; --orange-deep:#F2620A; --t1:#191B1F; --t2:#767C85; --t3:#AEB3BB; --line:#EDEFEF; --price:#FF2442; min-height:100vh; background:#F4F6F4; padding-bottom:180rpx; }
.card { background:#fff; border-radius:24rpx; margin:20rpx 24rpx 0; padding:0 28rpx 8rpx; }
.order-line { display:flex; align-items:center; padding:24rpx 0 0; }
.order-no { font-size:24rpx; color:var(--t3); }
.tag { margin-left:auto; font-size:22rpx; color:var(--orange-deep); background:var(--orange-soft); padding:6rpx 16rpx; border-radius:20rpx; }
.goods { display:flex; gap:22rpx; align-items:center; padding:22rpx 0; }
.cover { width:108rpx; height:108rpx; border-radius:20rpx; background:#F2F4F3; flex:none; }
.cover.placeholder { display:flex; align-items:center; justify-content:center; color:var(--t3); font-size:40rpx; }
.goods-main { flex:1; min-width:0; }
.goods-title { font-size:26rpx; color:var(--t1); line-height:1.4; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; }
.goods-sub { font-size:22rpx; color:var(--t3); margin-top:8rpx; display:block; }
.goods-price { font-size:30rpx; color:var(--price); font-weight:800; flex:none; }
.kv { display:flex; justify-content:space-between; gap:24rpx; border-top:2rpx solid #F5F6F6; padding:22rpx 0; font-size:24rpx; }
.k { color:var(--t2); flex:none; }
.v { color:var(--t1); text-align:right; flex:1; }
.tip { display:flex; align-items:center; gap:18rpx; background:var(--green-soft); border-radius:24rpx; margin:20rpx 24rpx 0; padding:24rpx 28rpx; }
.tip-ic { width:60rpx; height:60rpx; border-radius:18rpx; background:var(--green); color:#fff; display:flex; align-items:center; justify-content:center; font-size:30rpx; flex:none; }
.tip-text { font-size:22rpx; color:var(--green-deep); line-height:1.5; }
.action-bar { position:fixed; left:0; right:0; bottom:0; background:#fff; border-top:2rpx solid var(--line); display:flex; gap:16rpx; padding:16rpx 24rpx calc(16rpx + env(safe-area-inset-bottom)); }
.action-bar button { margin:0; }
.action-bar button::after { border:0; }
.ab-ghost { flex:1; height:84rpx; line-height:84rpx; border-radius:42rpx; background:#F2F4F3; color:var(--green-deep); font-size:28rpx; font-weight:700; }
.ab-main { flex:1.6; height:84rpx; line-height:84rpx; border-radius:42rpx; background:var(--green-grad); color:#fff; font-size:30rpx; font-weight:700; box-shadow:0 12rpx 28rpx rgba(11,191,99,.3); }
.ab-main[disabled] { opacity:.6; }
</style>
