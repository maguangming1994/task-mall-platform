<template>
  <view class="page">
    <ModulePlaceholder v-if="modules.resolved && !moduleEnabled" code="module_after_sale" />
    <view v-else-if="!modules.resolved" class="mp-skeleton">
      <view class="tm-skeleton" style="height: 200rpx; border-radius: 24rpx; margin: 24rpx 28rpx;" />
      <view class="tm-skeleton" style="height: 320rpx; border-radius: 24rpx; margin: 20rpx 28rpx;" />
    </view>
    <template v-else>
    <view class="commerce-hero" aria-label="售后服务">
      <image class="commerce-hero-art" src="/static/home/hero-campus-reward.png" mode="aspectFill" />
      <view class="commerce-hero-copy">
        <text class="commerce-hero-kicker">AFTER SALES</text>
        <text class="commerce-hero-title">售后服务</text>
        <text class="commerce-hero-sub">退款、退货、换货，处理进度与责任规则一目了然</text>
      </view>
    </view>
    <view class="heading"><text class="eyebrow">AFTER SALES</text><text class="title">售后服务</text><text class="sub">按订单项申请退款、退货或同 SKU 换货，责任、寄回方式与运费承担按原因逐单裁决。</text></view>
    <view class="rule-strip"><view><text>未发货</text><text>无需填写退货物流</text></view><view><text>已发货</text><text>数量与原因必填</text></view><view><text>超时</text><text>7 天未操作自动关闭</text></view></view>

    <view v-if="loading" class="loading-state"><view v-for="index in 3" :key="index" class="loading-line tm-skeleton" /></view>
    <view v-else-if="loadError" class="error-state"><text>售后信息加载失败</text><small>{{ loadError }}</small><button @click="load">重新加载</button></view>

    <view v-if="!loading && !loadError" class="section-head"><text>可申请售后</text><text>{{ eligibleOrders.length }} 个订单</text></view>
    <view v-if="!loading && !loadError" v-for="order in eligibleOrders" :key="order.id" class="card">
      <view class="row"><text class="number">{{ order.order_no }}</text><text class="status" :class="{ pending: order.status === 'pending_fulfillment', success: ['fulfilled', 'completed'].includes(order.status) }">{{ orderStatusText(order.status) }}</text></view>
      <view v-for="item in eligibleItems(order)" :key="item.id" class="item">
        <text>{{ item.product_name }} · {{ item.sku_name }} × {{ item.quantity }}</text>
        <text class="remaining">剩余可退 {{ item.remaining_refundable_quantity }} 件 · 可换 {{ item.remaining_exchangeable_quantity }} 件</text>
        <view class="actions">
          <button v-if="item.remaining_refundable_quantity > 0" size="mini" @click="openApplication(order, item, 'refund_only')">仅退款</button>
          <button v-if="order.status !== 'pending_fulfillment' && item.remaining_refundable_quantity > 0" size="mini" @click="openApplication(order, item, 'return_and_refund')">退货退款</button>
          <button v-if="['fulfilled','completed'].includes(order.status) && item.remaining_exchangeable_quantity > 0" size="mini" @click="openApplication(order, item, 'exchange')">同 SKU 换货</button>
        </view>
      </view>
    </view>
    <view v-if="!loading && !loadError && !eligibleOrders.length" class="empty-inline"><text>暂无可申请售后的订单</text><text>订单完成后仍可在规则期限内发起申请</text></view>

    <view v-if="!loading && !loadError" class="section-head second"><text>退款 / 退货进度</text><text>{{ refunds.length }} 条</text></view>
    <view v-if="!loading && !loadError" v-for="item in refunds" :key="item.id" class="card" @click="openDetail(item.id, 'refund')">
      <view class="row"><text>{{ item.refund_no }}</text><text class="status" :class="{ pending: !['succeeded', 'rejected', 'closed', 'cancelled'].includes(item.status), success: item.status === 'succeeded', danger: ['rejected', 'closed', 'cancelled'].includes(item.status) }">{{ statusText(item.status) }}</text></view>
      <text class="meta">{{ item.reason_name }} · {{ partyText(item.responsibility_party) }}承担责任</text>
      <text class="meta">退款 ¥{{ money(item.refund_cash_amount) }} + {{ item.refund_points }} 积分</text>
      <button class="primary" @click.stop="openDetail(item.id, 'refund')">{{ item.status === 'awaiting_return' ? '填写退货物流' : '查看售后进度' }}</button>
    </view>

    <view v-if="!loading && !loadError" class="section-head second"><text>换货进度</text><text>{{ exchanges.length }} 条</text></view>
    <view v-if="!loading && !loadError" v-for="item in exchanges" :key="item.id" class="card" @click="openDetail(item.id, 'exchange')">
      <view class="row"><text>{{ item.exchange_no }}</text><text class="status" :class="{ pending: !['completed', 'rejected', 'closed', 'cancelled'].includes(item.status), success: item.status === 'completed', danger: ['rejected', 'closed', 'cancelled'].includes(item.status) }">{{ statusText(item.status) }}</text></view>
      <text class="meta">{{ item.reason_name }} · 数量 {{ item.quantity }}</text>
      <text class="meta">寄回运费：{{ partyText(item.return_shipping_payer) }} · 补发运费：{{ partyText(item.replacement_shipping_payer) }}</text>
      <button class="primary" @click.stop="openDetail(item.id, 'exchange')">{{ item.status === 'waiting_return' ? '填写寄回物流' : item.status === 'replacement_shipped' ? '跟踪并确认收货' : '查看换货进度' }}</button>
    </view>
    <view v-if="!loading && !loadError && !refunds.length && !exchanges.length" class="empty-inline"><text>暂无售后记录</text><text>提交后可在这里查看审核、寄回、补发、退款及补偿进度</text></view>
    </template>
  </view>
</template>

<script setup lang="ts">
import { onShow } from '@dcloudio/uni-app'
import { computed, ref } from 'vue'
import { listExchanges, listRefunds, type Exchange, type Refund } from '@/api/after-sales'
import { listOrders, type Order, type OrderItem } from '@/api/orders'
import { useAuthStore } from '@/stores/auth'
import { navigateToLogin } from '@/utils/authRedirect'
import ModulePlaceholder from '@/components/ModulePlaceholder.vue'
import { useModulesStore } from '@/stores/modules'

const modules = useModulesStore()
const moduleEnabled = computed(() => modules.isEnabled('module_after_sale'))

const auth = useAuthStore()
const orders = ref<Order[]>([])
const refunds = ref<Refund[]>([])
const exchanges = ref<Exchange[]>([])
const loading = ref(false)
const loadError = ref('')
const eligibleOrders = computed(() => orders.value.filter(order => ['pending_fulfillment', 'fulfilled', 'completed'].includes(order.status) && eligibleItems(order).length > 0))
onShow(async () => {
  await modules.ensureReady()
  if (!moduleEnabled.value) return
  await load()
})
async function load() {
  if (!auth.isAuthenticated) return navigateToLogin()
  loading.value = true; loadError.value = ''
  try {
    const [orderResult, refundResult, exchangeResult] = await Promise.all([listOrders(auth.accessToken), listRefunds(auth.accessToken), listExchanges(auth.accessToken)])
    orders.value = orderResult.items; refunds.value = refundResult.items; exchanges.value = exchangeResult.items
  } catch (error) { loadError.value = error instanceof Error ? error.message : '请检查网络后重试' }
  finally { loading.value = false }
}
function openApplication(order: Order, item: OrderItem, type: 'refund_only' | 'return_and_refund' | 'exchange') { uni.navigateTo({ url: `/pages/after-sale-apply/index?orderId=${encodeURIComponent(order.id)}&itemId=${encodeURIComponent(item.id)}&type=${type}` }) }
function eligibleItems(order: Order) { return order.items.filter(item => item.remaining_refundable_quantity > 0 || (['fulfilled', 'completed'].includes(order.status) && item.remaining_exchangeable_quantity > 0)) }
function openDetail(id: string, type: 'refund' | 'exchange') { uni.navigateTo({ url: `/pages/after-sale-detail/index?id=${encodeURIComponent(id)}&type=${type}` }) }
function toast(error: unknown) { uni.showToast({ title: error instanceof Error ? error.message : '操作失败', icon: 'none' }) }
function money(cents: number) { return (cents / 100).toFixed(2) }
function statusText(status: string) { return ({ submitted: '待审核', awaiting_return: '待寄回', waiting_return: '待寄回', return_in_transit: '寄回运输中', replacement_pending: '待补发', replacement_shipped: '补发运输中', processing: '退款处理中', succeeded: '已完成', completed: '已完成', rejected: '已拒绝', cancelled: '已撤销', closed: '已关闭' } as Record<string, string>)[status] || '售后处理中' }
function orderStatusText(status: string) { return ({ pending_fulfillment: '待履约', fulfilled: '待确认', completed: '已完成' } as Record<string, string>)[status] || '订单处理中' }
function partyText(value: string) { return ({ buyer: '买家', seller: '卖家', carrier: '物流方', pending: '待裁决', negotiated: '协商确定' } as Record<string, string>)[value] || '待平台确认' }
</script>

<style scoped>
.page{min-height:100vh;padding:32rpx 28rpx 90rpx;background:var(--tm-canvas);box-sizing:border-box}.heading{padding:22rpx 8rpx 16rpx}.eyebrow,.title,.sub,.meta,.remaining,.empty-inline text{display:block}.eyebrow{color:var(--tm-brand-600);font-size:13px;font-weight:800;letter-spacing:2.5rpx}.title{margin-top:10rpx;font-size:24px;font-weight:850}.sub{margin-top:10rpx;color:var(--tm-ink-500);font-size:14px;line-height:1.6}.rule-strip{display:grid;grid-template-columns:repeat(3,1fr);gap:10rpx;margin-top:8rpx}.rule-strip view{padding:20rpx 10rpx;text-align:center;border-radius:20rpx;background:#eef4ff}.rule-strip text{display:block}.rule-strip text:first-child{color:var(--tm-brand-700);font-size:14px;font-weight:800}.rule-strip text:last-child{margin-top:6rpx;color:var(--tm-ink-500);font-size:12px}.section-head{display:flex;align-items:center;justify-content:space-between;margin:30rpx 4rpx 4rpx;font-size:17px;font-weight:850}.section-head text:last-child{color:var(--tm-ink-500);font-size:13px;font-weight:500}.second{margin-top:38rpx}.card{margin-top:18rpx;padding:28rpx;border:1rpx solid var(--tm-line);border-radius:26rpx;background:#fff;box-shadow:var(--tm-shadow-sm)}.row{display:flex;justify-content:space-between;gap:20rpx}.item{display:block;margin-top:20rpx;padding-top:20rpx;border-top:1rpx solid var(--tm-line)}.number{font-size:14px;font-weight:750}.status{padding:6rpx 12rpx;border-radius:10rpx;background:var(--tm-reward-100);color:var(--tm-reward-600);font-size:13px;font-weight:750}.meta{margin-top:10rpx;color:var(--tm-ink-500);font-size:14px}.remaining{margin-top:8rpx;color:var(--tm-brand-700);font-size:13px;font-weight:750}.actions{display:flex;gap:12rpx;margin-top:18rpx;flex-wrap:wrap}.actions button{margin:0;border-radius:14rpx;border-color:#dbe3f4;background:#f7f9fd;color:var(--tm-brand-700)}.primary{height:72rpx;margin-top:20rpx;border-radius:16rpx;background:var(--tm-brand-600);color:#fff;line-height:72rpx}.empty-inline{margin-top:18rpx;padding:38rpx 28rpx;text-align:center;border:1rpx dashed #d9dfeb;border-radius:24rpx;background:rgba(255,255,255,.72)}.empty-inline text:first-child{font-size:15px;font-weight:750}.empty-inline text:last-child{margin-top:8rpx;color:var(--tm-ink-500);font-size:13px;line-height:1.5}
 .loading-state{margin-top:24rpx}.loading-line{height:180rpx;margin-top:18rpx;border-radius:26rpx}.error-state{margin-top:24rpx;padding:50rpx 28rpx;text-align:center;border:2rpx solid var(--tm-ink-900);border-radius:26rpx;background:var(--tm-campus-coral-soft);box-shadow:0 7rpx 0 rgba(22,22,22,.13)}.error-state text,.error-state small{display:block}.error-state text{font-size:17px;font-weight:900}.error-state small{margin-top:9rpx;color:var(--tm-ink-500);font-size:13px}.error-state button{height:66rpx;margin:22rpx auto 0;border:2rpx solid var(--tm-ink-900);border-radius:999rpx;background:var(--tm-campus-yellow);font-weight:850;line-height:62rpx}.error-state button::after{border:0}
/* V09 campus after-sales surface */
.commerce-hero{position:relative;min-height:250rpx;overflow:hidden;margin-bottom:18rpx;border:1rpx solid var(--tm-line);border-radius:var(--tm-radius-lg);background:linear-gradient(135deg,#eaffef 0,#e3f7ff 58%,#fff4e8 100%);box-shadow:var(--tm-shadow-sm)}
.commerce-hero-art{position:absolute;right:-16rpx;top:0;width:68%;height:100%;opacity:.84;object-position:right center}.commerce-hero::after{content:'';position:absolute;inset:0;background:linear-gradient(90deg,rgba(234,255,239,.98) 0,rgba(234,255,239,.9) 34%,rgba(234,255,239,.12) 78%,rgba(234,255,239,0) 100%)}
.commerce-hero-copy{position:relative;z-index:1;width:57%;padding:28rpx 24rpx}.commerce-hero-kicker,.commerce-hero-title,.commerce-hero-sub{display:block}.commerce-hero-kicker{color:var(--tm-brand-700);font-size:13px;font-weight:850;letter-spacing:2.4rpx}.commerce-hero-title{margin-top:10rpx;color:var(--tm-ink-900);font-size:22px;font-weight:950;line-height:1.2}.commerce-hero-sub{margin-top:10rpx;color:var(--tm-ink-500);font-size:13px;line-height:1.55}.page > .heading{display:none}
.page{background:linear-gradient(180deg,var(--tm-campus-coral-soft) 0,var(--tm-canvas) 520rpx);color:var(--tm-ink-900)}
.heading{padding:26rpx 24rpx;border:1rpx solid var(--tm-line);border-radius:var(--tm-radius-lg);background:var(--tm-surface);box-shadow:var(--tm-shadow-sm)}
.eyebrow,.title{color:var(--tm-ink-900)}.sub{color:var(--tm-ink-500);font-weight:500}
.rule-strip view{border:1rpx solid var(--tm-line);box-shadow:none}.rule-strip view:nth-child(1){background:var(--tm-growth-100)}.rule-strip view:nth-child(2){background:var(--tm-reward-100)}.rule-strip view:nth-child(3){background:var(--tm-campus-cyan-soft)}
.rule-strip text:first-child{color:var(--tm-ink-900)}
.card,.empty-inline,.error-state{border:1rpx solid var(--tm-line);box-shadow:var(--tm-shadow-sm);background:var(--tm-surface)}
.status{border:0;background:var(--tm-reward-100);color:var(--tm-reward-600)}.status.pending{background:var(--tm-reward-100);color:var(--tm-reward-600)}.status.success{background:var(--tm-growth-100);color:var(--tm-growth-700)}.status.danger{background:var(--tm-campus-coral-soft);color:var(--tm-danger)}
.actions button{border:1rpx solid var(--tm-line);background:var(--tm-brand-50);color:var(--tm-brand-700);box-shadow:none}.primary{border:1rpx solid transparent;background:var(--tm-brand-600);box-shadow:none}
.empty-inline{background:var(--tm-surface-soft)}
.empty-inline{position:relative;padding:48rpx 28rpx 40rpx}.empty-inline::before{content:'';display:block;width:128rpx;height:88rpx;margin:0 auto 20rpx;border-radius:28rpx;background:linear-gradient(145deg,#e2f6f8,#edf7ff);box-shadow:inset 0 0 0 4rpx rgba(75,183,205,.18)}.empty-inline::after{content:'⌕';display:flex;align-items:center;justify-content:center;width:42rpx;height:42rpx;margin:-52rpx auto 18rpx;border:5rpx solid #1268c9;border-radius:50%;background:#fff;color:#1268c9;font-size:19px;font-weight:900;box-sizing:border-box}.empty-inline text:first-child{position:relative;z-index:1}
.error-state{border-color:rgba(209,67,67,.22);background:var(--tm-campus-coral-soft)}
</style>
