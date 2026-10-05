<template>
  <view class="page">
    <ModulePlaceholder v-if="modules.resolved && !moduleEnabled" code="module_after_sale" />
    <view v-else-if="!modules.resolved" class="mp-skeleton">
      <view class="tm-skeleton" style="height: 200rpx; border-radius: 24rpx; margin: 24rpx 28rpx;" />
      <view class="tm-skeleton" style="height: 320rpx; border-radius: 24rpx; margin: 20rpx 28rpx;" />
    </view>
    <template v-else>
      <!-- 顶部横幅 -->
      <view class="hero">
        <view class="hero-copy">
          <text class="hero-kicker">AFTER SALES</text>
          <text class="hero-title">售后服务</text>
          <text class="hero-sub">退款、退货、换货，处理进度与责任规则一目了然</text>
        </view>
        <view class="hero-badge">
          <text class="hero-badge-num">{{ processingCount }}</text>
          <text class="hero-badge-label">处理中</text>
        </view>
      </view>

      <!-- Tabs -->
      <view class="tabs">
        <view :class="['tab', activeTab === 'all' && 'active']" @click="activeTab = 'all'">全部</view>
        <view :class="['tab', activeTab === 'processing' && 'active']" @click="activeTab = 'processing'">处理中</view>
        <view :class="['tab', activeTab === 'finished' && 'active']" @click="activeTab = 'finished'">已完成</view>
      </view>

      <view v-if="loading" class="loading-state"><view v-for="index in 3" :key="index" class="loading-line tm-skeleton" /></view>
      <view v-else-if="loadError" class="error-state">
        <text>售后信息加载失败</text>
        <small>{{ loadError }}</small>
        <button @click="load">重新加载</button>
      </view>

      <template v-else>
        <!-- 售后入口 -->
        <view class="entry-card" @click="showEligible = !showEligible">
          <view class="entry-icon refund">退</view>
          <view class="entry-main">
            <text class="entry-title">申请售后</text>
            <text class="entry-sub">{{ eligibleOrders.length ? `${eligibleOrders.length} 个订单可申请退款/退货/换货` : '暂无可申请售后的订单' }}</text>
          </view>
          <text :class="['entry-arrow', showEligible && 'up']">›</text>
        </view>

        <view v-if="showEligible">
          <view v-for="order in eligibleOrders" :key="order.id" class="card eligible-card">
            <view class="row">
              <text class="number">{{ order.order_no }}</text>
              <text class="status neutral">{{ orderStatusText(order.status) }}</text>
            </view>
            <view v-for="item in eligibleItems(order)" :key="item.id" class="item">
              <text class="item-name">{{ item.product_name }} · {{ item.sku_name }} × {{ item.quantity }}</text>
              <text class="remaining">剩余可退 {{ item.remaining_refundable_quantity }} 件 · 可换 {{ item.remaining_exchangeable_quantity }} 件</text>
              <view class="actions">
                <button v-if="item.remaining_refundable_quantity > 0" size="mini" @click="openApplication(order, item, 'refund_only')">仅退款</button>
                <button v-if="order.status !== 'pending_fulfillment' && item.remaining_refundable_quantity > 0" size="mini" @click="openApplication(order, item, 'return_and_refund')">退货退款</button>
                <button v-if="['fulfilled','completed'].includes(order.status) && item.remaining_exchangeable_quantity > 0" size="mini" @click="openApplication(order, item, 'exchange')">同 SKU 换货</button>
              </view>
            </view>
          </view>
          <view v-if="!eligibleOrders.length" class="empty-inline">
            <text>暂无可申请售后的订单</text>
            <text>订单完成后仍可在规则期限内发起申请</text>
          </view>
        </view>

        <!-- 闲置售后入口 -->
        <view class="entry-card" @click="openSecondHandOrders">
          <view class="entry-icon idle">闲</view>
          <view class="entry-main">
            <text class="entry-title">闲置交易售后</text>
            <text class="entry-sub">二手订单退款协商与平台仲裁入口</text>
          </view>
          <text class="entry-arrow">›</text>
        </view>

        <!-- 统一售后单列表 -->
        <view v-if="visibleRecords.length">
          <view v-for="record in visibleRecords" :key="record.key" class="card record-card" @click="openRecord(record)">
            <view class="record-head">
              <view :class="['record-icon', record.kind]">{{ record.kind === 'refund' ? '退' : '换' }}</view>
              <view class="record-main">
                <view class="row">
                  <text class="record-title">{{ record.typeLabel }}</text>
                  <text :class="['status', record.finished ? (record.bad ? 'danger' : 'success') : 'pending']">{{ record.statusLabel }}</text>
                </view>
                <text class="record-no">{{ record.no }}</text>
                <text class="record-reason">{{ record.reason }}</text>
                <view class="record-foot">
                  <text class="record-money">{{ record.moneyText }}</text>
                  <text class="record-time">{{ formatRelativeTime(record.createdAt) }}</text>
                </view>
              </view>
            </view>
            <view v-if="record.actionText" class="record-action" @click.stop="openRecord(record)">{{ record.actionText }}</view>
          </view>
        </view>
        <view v-else class="empty-inline">
          <text>{{ activeTab === 'processing' ? '暂无处理中的售后' : '暂无售后记录' }}</text>
          <text>提交后可在这里查看审核、寄回、补发、退款及补偿进度</text>
          <text class="empty-retry" @click="load">重新加载</text>
        </view>
      </template>
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
import { formatRelativeTime } from '../admin/format'

type TabKey = 'all' | 'processing' | 'finished'

interface UnifiedRecord {
  key: string
  kind: 'refund' | 'exchange'
  id: string
  no: string
  typeLabel: string
  reason: string
  status: string
  statusLabel: string
  finished: boolean
  bad: boolean
  moneyText: string
  createdAt: string
  actionText: string
}

const modules = useModulesStore()
const moduleEnabled = computed(() => modules.isEnabled('module_after_sale'))

const auth = useAuthStore()
const orders = ref<Order[]>([])
const refunds = ref<Refund[]>([])
const exchanges = ref<Exchange[]>([])
const loading = ref(false)
const loadError = ref('')
const activeTab = ref<TabKey>('all')
const showEligible = ref(false)

const REFUND_FINISHED = ['succeeded', 'rejected', 'closed', 'cancelled']
const EXCHANGE_FINISHED = ['completed', 'rejected', 'closed', 'cancelled']
const BAD_FINISHED = ['rejected', 'closed', 'cancelled']

const eligibleOrders = computed(() => orders.value.filter(order => ['pending_fulfillment', 'fulfilled', 'completed'].includes(order.status) && eligibleItems(order).length > 0))

/** 退款与换货合并，按提交时间倒序 */
const allRecords = computed<UnifiedRecord[]>(() => {
  const refundRecords = refunds.value.map((item): UnifiedRecord => {
    const finished = REFUND_FINISHED.includes(item.status)
    return {
      key: `refund-${item.id}`,
      kind: 'refund',
      id: item.id,
      no: item.refund_no,
      typeLabel: item.service_type === 'return_and_refund' ? '退货退款' : '仅退款',
      reason: item.reason_name,
      status: item.status,
      statusLabel: statusText(item.status),
      finished,
      bad: BAD_FINISHED.includes(item.status),
      moneyText: `退款 ¥${money(item.refund_cash_amount)}${item.refund_points ? ` + ${item.refund_points} 积分` : ''}`,
      createdAt: item.created_at || '',
      actionText: item.status === 'awaiting_return' ? '填写退货物流' : (!finished ? '查看售后进度' : ''),
    }
  })
  const exchangeRecords = exchanges.value.map((item): UnifiedRecord => {
    const finished = EXCHANGE_FINISHED.includes(item.status)
    return {
      key: `exchange-${item.id}`,
      kind: 'exchange',
      id: item.id,
      no: item.exchange_no,
      typeLabel: '换货',
      reason: `${item.reason_name} · 数量 ${item.quantity}`,
      status: item.status,
      statusLabel: statusText(item.status),
      finished,
      bad: BAD_FINISHED.includes(item.status),
      moneyText: `寄回运费：${partyText(item.return_shipping_payer)} · 补发：${partyText(item.replacement_shipping_payer)}`,
      createdAt: item.created_at || '',
      actionText: item.status === 'waiting_return' ? '填写寄回物流' : item.status === 'replacement_shipped' ? '跟踪并确认收货' : (!finished ? '查看换货进度' : ''),
    }
  })
  return refundRecords.concat(exchangeRecords).sort((a, b) => Date.parse(b.createdAt || '0') - Date.parse(a.createdAt || '0'))
})

const processingCount = computed(() => allRecords.value.filter(record => !record.finished).length)

const visibleRecords = computed(() => {
  if (activeTab.value === 'processing') return allRecords.value.filter(record => !record.finished)
  if (activeTab.value === 'finished') return allRecords.value.filter(record => record.finished)
  return allRecords.value
})

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
  } catch (error) {
    loadError.value = error instanceof Error ? error.message : '请检查网络后重试'
  } finally {
    loading.value = false
  }
}

function openApplication(order: Order, item: OrderItem, type: 'refund_only' | 'return_and_refund' | 'exchange') {
  uni.navigateTo({ url: `/pages/after-sale-apply/index?orderId=${encodeURIComponent(order.id)}&itemId=${encodeURIComponent(item.id)}&type=${type}` })
}
function eligibleItems(order: Order) {
  return order.items.filter(item => item.remaining_refundable_quantity > 0 || (['fulfilled', 'completed'].includes(order.status) && item.remaining_exchangeable_quantity > 0))
}
function openRecord(record: UnifiedRecord) {
  uni.navigateTo({ url: `/pages/after-sale-detail/index?id=${encodeURIComponent(record.id)}&type=${record.kind}` })
}
function openSecondHandOrders() {
  uni.navigateTo({ url: '/pages/second-hand-orders/index' })
}
function money(cents: number) { return (cents / 100).toFixed(2) }
function statusText(status: string) {
  return ({ submitted: '待审核', awaiting_return: '待寄回', waiting_return: '待寄回', return_in_transit: '寄回运输中', replacement_pending: '待补发', replacement_shipped: '补发运输中', processing: '退款处理中', succeeded: '已退款', completed: '已完成', rejected: '已拒绝', cancelled: '已撤销', closed: '已关闭' } as Record<string, string>)[status] || '售后处理中'
}
function orderStatusText(status: string) { return ({ pending_fulfillment: '待履约', fulfilled: '待确认', completed: '已完成' } as Record<string, string>)[status] || '订单处理中' }
function partyText(value: string) { return ({ buyer: '买家', seller: '卖家', carrier: '物流方', pending: '待裁决', negotiated: '协商确定' } as Record<string, string>)[value] || '待平台确认' }
</script>

<style scoped>
.page { min-height: 100vh; padding: 24rpx 24rpx 90rpx; background: #F4F6F4; box-sizing: border-box; }

.hero { display: flex; align-items: center; justify-content: space-between; padding: 36rpx 32rpx; border-radius: 28rpx; background: linear-gradient(135deg, #12D978, #08A152); box-shadow: 0 14rpx 34rpx rgba(11,191,99,.26); }
.hero-copy { display: flex; flex-direction: column; }
.hero-kicker { color: rgba(255,255,255,.85); font-size: 20rpx; font-weight: 800; letter-spacing: 3rpx; }
.hero-title { margin-top: 8rpx; color: #fff; font-size: 40rpx; font-weight: 900; }
.hero-sub { margin-top: 10rpx; color: rgba(255,255,255,.88); font-size: 23rpx; line-height: 1.5; }
.hero-badge { display: flex; flex-direction: column; align-items: center; justify-content: center; width: 120rpx; height: 120rpx; border-radius: 28rpx; background: rgba(255,255,255,.2); }
.hero-badge-num { color: #fff; font-size: 44rpx; font-weight: 900; line-height: 1.1; }
.hero-badge-label { margin-top: 4rpx; color: rgba(255,255,255,.9); font-size: 20rpx; }

.tabs { display: flex; margin: 24rpx 0 8rpx; }
.tab { flex: 1; text-align: center; padding: 20rpx 0; margin: 0 8rpx; border-radius: 20rpx; color: #767C85; font-size: 27rpx; font-weight: 600; }
.tab.active { background: #fff; color: #08A152; box-shadow: 0 2rpx 8rpx rgba(8,161,82,.12); }

.entry-card { display: flex; align-items: center; margin-top: 16rpx; padding: 26rpx 28rpx; border: 1rpx solid #EDEFEF; border-radius: 24rpx; background: #fff; box-shadow: 0 2rpx 4rpx rgba(23,26,23,.03), 0 8rpx 28rpx rgba(23,26,23,.04); }
.entry-icon { display: flex; align-items: center; justify-content: center; width: 76rpx; height: 76rpx; flex: none; border-radius: 20rpx; font-size: 32rpx; font-weight: 800; }
.entry-icon.refund { background: #E7F9EF; color: #08A152; }
.entry-icon.idle { background: #FFF2E5; color: #F2620A; }
.entry-main { flex: 1; min-width: 0; margin-left: 20rpx; }
.entry-title { display: block; color: #191B1F; font-size: 28rpx; font-weight: 700; }
.entry-sub { display: block; margin-top: 6rpx; color: #AEB3BB; font-size: 22rpx; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.entry-arrow { color: #C6CBC6; font-size: 40rpx; font-weight: 300; transition: transform .2s; }
.entry-arrow.up { transform: rotate(90deg); }

.card { margin-top: 16rpx; padding: 28rpx; border: 1rpx solid #EDEFEF; border-radius: 24rpx; background: #fff; box-shadow: 0 2rpx 4rpx rgba(23,26,23,.03), 0 8rpx 28rpx rgba(23,26,23,.04); }
.eligible-card .item { display: block; margin-top: 20rpx; padding-top: 20rpx; border-top: 1rpx solid #F2F4F3; }
.item-name { display: block; color: #191B1F; font-size: 26rpx; line-height: 1.5; }
.row { display: flex; align-items: center; justify-content: space-between; gap: 20rpx; }
.number { font-size: 25rpx; font-weight: 700; color: #191B1F; }
.status { padding: 6rpx 16rpx; border-radius: 16rpx; font-size: 20rpx; flex: none; }
.status.pending { background: #EAF2FF; color: #3B82F6; }
.status.success { background: #E7F9EF; color: #08A152; }
.status.danger { background: #FFEEEA; color: #FF3B30; }
.status.neutral { background: #F4F6F4; color: #767C85; }
.remaining { display: block; margin-top: 8rpx; color: #08A152; font-size: 22rpx; font-weight: 600; }
.actions { display: flex; gap: 12rpx; margin-top: 16rpx; flex-wrap: wrap; }
.actions button { margin: 0; border: 1rpx solid #CDE8D2; border-radius: 14rpx; background: #E7F9EF; color: #08A152; font-size: 22rpx; }
.actions button::after { border: 0; }

.record-card { padding: 26rpx 28rpx; }
.record-head { display: flex; align-items: flex-start; }
.record-icon { display: flex; align-items: center; justify-content: center; width: 84rpx; height: 84rpx; flex: none; border-radius: 20rpx; font-size: 34rpx; font-weight: 800; }
.record-icon.refund { background: #E7F9EF; color: #08A152; }
.record-icon.exchange { background: #EAF2FF; color: #3B82F6; }
.record-main { flex: 1; min-width: 0; margin-left: 20rpx; }
.record-title { color: #191B1F; font-size: 28rpx; font-weight: 700; }
.record-no { display: block; margin-top: 8rpx; color: #AEB3BB; font-size: 21rpx; }
.record-reason { display: block; margin-top: 8rpx; color: #50565C; font-size: 24rpx; line-height: 1.5; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.record-foot { display: flex; align-items: center; justify-content: space-between; margin-top: 12rpx; }
.record-money { color: #E54D42; font-size: 24rpx; font-weight: 700; }
.record-time { color: #AEB3BB; font-size: 20rpx; }
.record-action { margin-top: 18rpx; height: 72rpx; border-radius: 999rpx; background: linear-gradient(135deg, #12D978, #08A152); box-shadow: 0 12rpx 28rpx rgba(11,191,99,.22); color: #fff; font-size: 26rpx; font-weight: 700; line-height: 72rpx; text-align: center; }

.empty-inline { margin-top: 16rpx; padding: 60rpx 28rpx; text-align: center; border: 1rpx dashed #DDE3DE; border-radius: 24rpx; background: rgba(255,255,255,.72); }
.empty-inline text { display: block; }
.empty-inline text:first-child { font-size: 28rpx; font-weight: 700; color: #191B1F; }
.empty-inline text:nth-child(2) { margin-top: 10rpx; color: #767C85; font-size: 23rpx; line-height: 1.5; }
.empty-retry { display: inline-block; margin-top: 24rpx; padding: 12rpx 48rpx; border-radius: 36rpx; background: #08A152; color: #fff; font-size: 24rpx; }

.loading-state { margin-top: 8rpx; }
.loading-line { height: 180rpx; margin-top: 16rpx; border-radius: 24rpx; }
.error-state { margin-top: 16rpx; padding: 50rpx 28rpx; text-align: center; border: 1rpx solid rgba(255,59,48,.2); border-radius: 24rpx; background: #FFEEEA; }
.error-state text, .error-state small { display: block; }
.error-state text { font-size: 30rpx; font-weight: 900; color: #FF3B30; }
.error-state small { margin-top: 9rpx; color: #767C85; font-size: 23rpx; }
.error-state button { height: 66rpx; margin: 22rpx auto 0; border: 0; border-radius: 999rpx; background: linear-gradient(135deg,#12D978,#08A152); color: #fff; font-weight: 800; line-height: 66rpx; }
.error-state button::after { border: 0; }
</style>
