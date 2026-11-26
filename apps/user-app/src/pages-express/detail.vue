<template>
  <view class="page">
    <view v-if="loading" class="empty">加载中…</view>
    <template v-else-if="order">
      <view class="hero">
        <text class="hero-status">{{ expressStatusLabel(order.status) }}</text>
        <text class="hero-no">{{ order.order_no }}</text>
        <text class="hero-reward">￥{{ expressMoney(order.total_cents) }}</text>
        <text class="hero-tip">{{ statusTip }}</text>
      </view>

      <view class="section">
        <view class="row"><text class="label">取件点</text><text class="value">{{ order.pickup_site }}</text></view>
        <view class="row"><text class="label">取件码</text><text class="value strong">{{ order.pickup_code }}</text></view>
        <view class="row"><text class="label">包裹</text><text class="value">{{ order.package_info || '—' }} × {{ order.package_count }}</text></view>
        <view class="row"><text class="label">送达</text><text class="value">{{ order.deliver_building }} {{ order.deliver_address }}</text></view>
        <view class="row"><text class="label">联系人</text><text class="value">{{ order.contact_name }} {{ order.contact_phone }}</text></view>
        <view class="row"><text class="label">期望送达</text><text class="value">{{ order.expected_at || '不限' }}</text></view>
        <view class="row" v-if="order.remark"><text class="label">备注</text><text class="value">{{ order.remark }}</text></view>
        <view class="row" v-if="order.runner_name"><text class="label">跑腿</text><text class="value">{{ order.runner_name }}</text></view>
      </view>

      <view class="section" v-if="order.events && order.events.length">
        <text class="section-title">流转记录</text>
        <view v-for="(event, index) in order.events" :key="index" class="event">
          <text class="event-dot">·</text>
          <text class="event-text">{{ expressStatusLabel(event.to_status) }}{{ event.reason ? ` · ${event.reason}` : '' }}</text>
        </view>
      </view>

      <view class="footer">
        <template v-if="order.status === 'pending_payment'">
          <button class="btn ghost" @tap="cancel">取消</button>
          <button class="btn plain" :loading="paying" @tap="pay('wechat')">微信支付</button>
          <button class="btn primary" :loading="paying" @tap="pay('wallet')">钱包支付</button>
        </template>
        <template v-else-if="order.status === 'pending_accept'">
          <button class="btn ghost" @tap="cancel">取消需求</button>
        </template>
        <template v-else-if="order.status === 'delivered'">
          <button class="btn primary" :loading="busy" @tap="confirm">确认完成</button>
        </template>
      </view>
    </template>
  </view>
</template>

<script setup lang="ts">
import { onLoad, onShow } from '@dcloudio/uni-app'
import { computed, ref } from 'vue'
import {
  cancelExpressPickupOrder,
  confirmExpressPickupOrder,
  expressMoney,
  expressStatusLabel,
  getExpressPickupOrder,
  payExpressPickupOrder,
  type ExpressPickupOrderDetail,
} from '@/api/express-pickup'
import { useAuthStore } from '@/stores/auth'
import { invokeWechatJsapiPayment, type WechatJsapiPayParams } from '@/platform/wechatPay'

const auth = useAuthStore()
const orderId = ref('')
const order = ref<ExpressPickupOrderDetail | null>(null)
const loading = ref(false)
const paying = ref(false)
const busy = ref(false)

const statusTip = computed(() => {
  switch (order.value?.status) {
    case 'pending_payment': return '完成支付后跑腿即可接单。'
    case 'pending_accept': return '正在等待跑腿接单，可随时取消并原路退回。'
    case 'accepted': return '跑腿已接单，正在前往取件。'
    case 'picked_up': return '跑腿已取件，正在送往你的地址。'
    case 'delivered': return '跑腿已送达，请确认后完成订单。'
    case 'completed': return '订单已完成，感谢使用。'
    case 'cancelled': return '订单已取消。'
    default: return ''
  }
})

const toast = (title: string) => uni.showToast({ title, icon: 'none' })

const load = async () => {
  if (!auth.accessToken || !orderId.value) return
  loading.value = true
  try {
    order.value = await getExpressPickupOrder(auth.accessToken, orderId.value)
  } catch (error) {
    toast(error instanceof Error ? error.message : '订单加载失败')
  } finally {
    loading.value = false
  }
}

const pay = async (method: 'wallet' | 'wechat') => {
  if (!auth.accessToken || !order.value) return
  paying.value = true
  try {
    const result = await payExpressPickupOrder(auth.accessToken, order.value.id, method)
    if (method === 'wallet') {
      toast('支付成功，已通知跑腿接单')
      await load()
      return
    }
    const payload = result.payment?.client_payload as unknown as WechatJsapiPayParams | null
    if (!payload) {
      toast('未获取到微信支付参数')
      return
    }
    // #ifdef MP-WEIXIN
    await uni.requestPayment({
      provider: 'wxpay',
      timeStamp: String(payload.timeStamp ?? ''),
      nonceStr: String(payload.nonceStr ?? ''),
      package: String(payload.package ?? ''),
      signType: 'RSA',
      paySign: String(payload.paySign ?? ''),
    })
    // #endif
    // #ifdef H5
    await invokeWechatJsapiPayment(payload)
    // #endif
    toast('支付结果确认中')
    await load()
  } catch (error) {
    toast(error instanceof Error ? error.message : '支付失败')
  } finally {
    paying.value = false
  }
}

const cancel = () => {
  if (!auth.accessToken || !order.value) return
  uni.showModal({
    title: '取消代取需求',
    content: '已支付的金额会退回钱包余额。',
    success: async (result) => {
      if (!result.confirm || !order.value || !auth.accessToken) return
      busy.value = true
      try {
        await cancelExpressPickupOrder(auth.accessToken, order.value.id, '用户主动取消')
        toast('已取消')
        await load()
      } catch (error) {
        toast(error instanceof Error ? error.message : '取消失败')
      } finally {
        busy.value = false
      }
    },
  })
}

const confirm = async () => {
  if (!auth.accessToken || !order.value) return
  busy.value = true
  try {
    await confirmExpressPickupOrder(auth.accessToken, order.value.id)
    toast('已确认完成')
    await load()
  } catch (error) {
    toast(error instanceof Error ? error.message : '确认失败')
  } finally {
    busy.value = false
  }
}

onLoad((query) => {
  orderId.value = String(query?.id ?? '')
})
onShow(() => { void load() })
</script>

<style scoped>
.page { min-height: 100vh; background: #f5f6f8; padding: 24rpx 24rpx 140rpx; box-sizing: border-box; }
.empty { padding: 160rpx 0; text-align: center; color: #9ca3af; font-size: 26rpx; }
.hero { background: linear-gradient(140deg, #2f6bff, #5b8dff); border-radius: 24rpx; padding: 32rpx; display: flex; flex-direction: column; color: #fff; }
.hero-status { font-size: 26rpx; opacity: 0.9; }
.hero-no { font-size: 24rpx; opacity: 0.8; margin-top: 8rpx; }
.hero-reward { font-size: 56rpx; font-weight: 700; margin-top: 16rpx; }
.hero-tip { font-size: 24rpx; opacity: 0.9; margin-top: 8rpx; }
.section { background: #fff; border-radius: 20rpx; padding: 16rpx 24rpx; margin-top: 16rpx; }
.row { display: flex; padding: 16rpx 0; font-size: 26rpx; border-bottom: 1rpx solid #f3f4f6; }
.row:last-child { border-bottom: none; }
.label { width: 160rpx; color: #9ca3af; }
.value { flex: 1; color: #111827; }
.value.strong { font-weight: 700; letter-spacing: 2rpx; }
.section-title { font-size: 26rpx; font-weight: 600; color: #111827; display: block; padding-top: 12rpx; }
.event { display: flex; padding: 8rpx 0; }
.event-dot { color: #2f6bff; margin-right: 8rpx; }
.event-text { font-size: 24rpx; color: #6b7280; }
.footer { position: fixed; left: 0; right: 0; bottom: 0; padding: 20rpx 24rpx 40rpx; background: #fff; display: flex; gap: 16rpx; box-shadow: 0 -4rpx 16rpx rgba(17, 24, 39, 0.05); }
.btn { flex: 1; border-radius: 999rpx; font-size: 28rpx; line-height: 84rpx; height: 84rpx; padding: 0; }
.btn.primary { background: #2f6bff; color: #fff; }
.btn.plain { background: #eef2ff; color: #2f6bff; }
.btn.ghost { background: #f3f4f6; color: #6b7280; }
</style>
