<template>
  <view class="page">
    <view v-if="campus" class="campus-bar"><text>当前校区</text><text class="campus-name">{{ campus.campus_name }}</text></view>
    <view class="summary">
      <text class="summary-label">待结算金额</text>
      <text class="summary-value">{{ money(unbilled?.net_amount || 0) }}</text>
      <text class="summary-sub">{{ unbilled?.item_count || 0 }} 笔订单 · 商品金额 {{ money(unbilled?.gross_amount || 0) }} · 平台抽成 {{ money(unbilled?.commission_amount || 0) }}</text>
    </view>

    <scroll-view scroll-x class="chips">
      <view v-for="item in filters" :key="item.value" class="chip" :class="{ active: status === item.value }" @tap="selectStatus(item.value)">{{ item.label }}</view>
    </scroll-view>

    <view v-if="loading" class="empty">加载中…</view>
    <view v-else-if="settlements.length === 0" class="empty">暂无结算批次，平台会按周期生成账单。</view>
    <view v-else class="list">
      <view v-for="settlement in settlements" :key="settlement.id" class="card">
        <view class="card-top">
          <text class="batch">{{ settlement.batch_no }}</text>
          <text class="status" :class="settlement.status">{{ storeSettlementStatusLabel(settlement.status) }}</text>
        </view>
        <text class="period"><text v-if="settlement.campus_name">校区 {{ settlement.campus_name }} · </text>结算周期 {{ settlement.period_start }} 至 {{ settlement.period_end }}</text>
        <view class="numbers">
          <view class="number"><text class="value">{{ money(settlement.gross_amount) }}</text><text class="label">商品金额</text></view>
          <view class="number"><text class="value">{{ money(settlement.refunded_amount) }}</text><text class="label">退款</text></view>
          <view class="number"><text class="value">{{ money(settlement.commission_amount) }}</text><text class="label">平台抽成</text></view>
          <view class="number"><text class="value highlight">{{ money(settlement.net_amount) }}</text><text class="label">应结金额</text></view>
        </view>
        <view class="foot">
          <text class="foot-text">{{ settlement.item_count }} 笔 · {{ settlement.status === 'paid' ? `打款于 ${settlement.paid_at?.slice(0, 10)}` : settlement.status === 'confirmed' ? '平台已确认，等待打款' : '等待平台确认' }}</text>
        </view>
        <text v-if="settlement.remark" class="remark">备注：{{ settlement.remark }}</text>
      </view>
    </view>
    <view v-if="hasMore" class="more" @tap="loadMore">加载更多</view>
  </view>
</template>

<script setup lang="ts">
import { onShow } from '@dcloudio/uni-app'
import { ref } from 'vue'
import { listManagerSettlements, storeSettlementStatusLabel, type StoreCampus, type StoreSettlementView } from '@/api/stores'
import { getSelectedStoreCampus, requireStoreSession } from '@/utils/storeSession'

const session = requireStoreSession()
const campus = ref<StoreCampus | null>(null)
const settlements = ref<StoreSettlementView[]>([])
const unbilled = ref<{ item_count: number; gross_amount: number; refunded_amount: number; commission_amount: number; net_amount: number } | null>(null)
const loading = ref(false)
const status = ref('')
const page = ref(1)
const hasMore = ref(false)
const filters = [
  { value: '', label: '全部' },
  { value: 'pending', label: '待确认' },
  { value: 'confirmed', label: '已确认' },
  { value: 'paid', label: '已打款' },
]

const toast = (title: string) => uni.showToast({ title, icon: 'none' })
const money = (cents: number) => `¥${(cents / 100).toFixed(2)}`

const load = async (reset = true) => {
  if (!session) return
  if (reset) { page.value = 1; settlements.value = [] }
  loading.value = true
  try {
    const result = await listManagerSettlements(session.token, { status: status.value, campus_id: campus.value?.campus_id, page: page.value, page_size: 20 })
    settlements.value = reset ? result.items : [...settlements.value, ...result.items]
    unbilled.value = result.unbilled
    hasMore.value = page.value < result.pagination.last_page
  } catch (error) {
    toast(error instanceof Error ? error.message : '加载失败')
  } finally {
    loading.value = false
  }
}
const selectStatus = (value: string) => { status.value = value; void load(true) }
const loadMore = () => { page.value += 1; void load(false) }

onShow(() => {
  if (session) campus.value = getSelectedStoreCampus(session.storeId)
  void load(true)
})
</script>

<style scoped>
.page { min-height: 100vh; background: #f5f6f8; padding: 24rpx 24rpx 80rpx; box-sizing: border-box; }
.campus-bar { display: flex; gap: 12rpx; margin-bottom: 16rpx; padding: 0 4rpx; font-size: 22rpx; color: #6b7280; }
.campus-name { color: #2f6bff; font-weight: 600; }
.summary { background: linear-gradient(135deg, #111827, #374151); border-radius: 24rpx; padding: 32rpx; color: #fff; }
.summary-label { font-size: 22rpx; opacity: 0.7; }
.summary-value { display: block; margin-top: 8rpx; font-size: 52rpx; font-weight: 700; }
.summary-sub { display: block; margin-top: 8rpx; font-size: 20rpx; opacity: 0.65; }
.chips { white-space: nowrap; margin-top: 20rpx; }
.chip { display: inline-block; padding: 10rpx 26rpx; border-radius: 999rpx; background: #fff; color: #6b7280; font-size: 24rpx; margin-right: 12rpx; }
.chip.active { background: #2f6bff; color: #fff; }
.empty { padding: 140rpx 0; text-align: center; color: #9ca3af; font-size: 26rpx; }
.list { margin-top: 16rpx; }
.card { background: #fff; border-radius: 20rpx; padding: 22rpx; margin-bottom: 16rpx; }
.card-top { display: flex; justify-content: space-between; align-items: center; }
.batch { font-size: 24rpx; font-weight: 600; color: #111827; }
.status { font-size: 20rpx; padding: 4rpx 12rpx; border-radius: 999rpx; background: #fff7ed; color: #d97706; }
.status.confirmed { background: #eef2ff; color: #2f6bff; }
.status.paid { background: #ecfdf5; color: #059669; }
.period { display: block; margin-top: 8rpx; font-size: 21rpx; color: #9ca3af; }
.numbers { display: flex; margin-top: 20rpx; }
.number { flex: 1; }
.number .value { display: block; font-size: 24rpx; font-weight: 600; color: #374151; }
.number .value.highlight { color: #2f6bff; font-size: 28rpx; }
.number .label { display: block; margin-top: 4rpx; font-size: 18rpx; color: #9ca3af; }
.foot { margin-top: 16rpx; padding-top: 16rpx; border-top: 1rpx solid #f3f4f6; }
.foot-text { font-size: 20rpx; color: #9ca3af; }
.remark { display: block; margin-top: 8rpx; font-size: 20rpx; color: #6b7280; }
.more { text-align: center; color: #2f6bff; font-size: 24rpx; padding: 20rpx; }
</style>
