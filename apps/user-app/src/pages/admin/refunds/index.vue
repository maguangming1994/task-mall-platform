<template>
  <view class="page">
    <view class="tabs">
      <text :class="['tab', { on: tab === 'mall' }]" @click="switchTab('mall')">商城退款</text>
      <text :class="['tab', { on: tab === 'second-hand' }]" @click="switchTab('second-hand')">闲置仲裁</text>
    </view>

    <view class="filters">
      <text
        v-for="option in statusOptions"
        :key="option.key"
        :class="['chip', { on: status === option.key }]"
        @click="switchStatus(option.key)"
      >{{ option.label }}</text>
    </view>

    <view v-if="loading" class="tip">加载中…</view>
    <view v-else-if="!rows.length" class="tip">当前没有需要处理的售后单</view>
    <view v-else class="list">
      <view v-for="row in rows" :key="row.key" class="card" @click="openDetail(row)">
        <view class="card-head">
          <text class="no">{{ row.no }}</text>
          <text :class="['tag', row.tone]">{{ row.statusLabel }}</text>
        </view>
        <text class="title">{{ row.title }}</text>
        <text class="meta">{{ row.party }}</text>
        <view class="foot">
          <text class="amount">¥{{ row.amount }}</text>
          <text class="meta">{{ row.createdAt }}</text>
        </view>
      </view>
    </view>
  </view>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue'
import { onLoad } from '@dcloudio/uni-app'
import { ApiError } from '@/api/http'
import {
  listManagedRefunds, listSecondHandRefunds,
  type AdminRefund, type AdminSecondHandRefund,
} from '@/api/admin/after-sale'
import { useAdminGuard } from '@/composables/use-admin-guard'
import { useAdminReviewSelection } from '@/stores/admin-review-selection'
import { formatDateTime } from '@/utils/dateTime'

interface RefundRow {
  key: string
  no: string
  title: string
  party: string
  amount: string
  statusLabel: string
  tone: 'orange' | 'blue' | 'green' | 'red' | 'gray'
  createdAt: string
  refund?: AdminRefund
  secondHandRefund?: AdminSecondHandRefund
}

const guard = useAdminGuard()
const selection = useAdminReviewSelection()

const tab = ref<'mall' | 'second-hand'>('mall')
const status = ref('')
const loading = ref(true)
const refunds = ref<AdminRefund[]>([])
const secondHandRefunds = ref<AdminSecondHandRefund[]>([])

const statusOptions = computed(() => (tab.value === 'mall'
  ? [
      { key: 'submitted', label: '待处理' },
      { key: 'awaiting_return', label: '处理中' },
      { key: '', label: '全部' },
    ]
  : [
      { key: 'arbitrating', label: '仲裁中' },
      { key: 'seller_rejected', label: '卖家拒绝' },
      { key: '', label: '全部' },
    ]))

const rows = computed<RefundRow[]>(() => (tab.value === 'mall'
  ? refunds.value.map(toMallRow)
  : secondHandRefunds.value.map(toSecondHandRow)))

onLoad(() => {
  if (!guard.requireSession()) return
  status.value = 'submitted'
  void load()
})

function switchTab(next: 'mall' | 'second-hand') {
  if (tab.value === next) return
  tab.value = next
  status.value = next === 'mall' ? 'submitted' : 'arbitrating'
  void load()
}

function switchStatus(next: string) {
  if (status.value === next) return
  status.value = next
  void load()
}

async function load() {
  loading.value = true
  try {
    if (tab.value === 'mall') {
      const result = await listManagedRefunds({ status: status.value || undefined })
      refunds.value = result.items
    } else {
      const result = await listSecondHandRefunds({ status: status.value || undefined, page: 1 })
      secondHandRefunds.value = result.items
    }
  } catch (error) {
    if (guard.handleAuthError(error)) return
    uni.showToast({ title: error instanceof ApiError ? error.message : '加载失败', icon: 'none' })
  } finally {
    loading.value = false
  }
}

function toMallRow(item: AdminRefund): RefundRow {
  return {
    key: `mall-${item.id}`,
    no: item.refund_no,
    title: item.reason_name,
    party: `${item.user?.nickname || '用户'} · ${item.service_type.includes('return') ? '退货退款' : '仅退款'}`,
    amount: item.refund_cash_amount.toFixed(2),
    statusLabel: mallStatusText(item.status),
    tone: mallStatusTone(item.status),
    createdAt: formatDateTime(item.created_at),
    refund: item,
  }
}

function toSecondHandRow(item: AdminSecondHandRefund): RefundRow {
  return {
    key: `sh-${item.id}`,
    no: item.order_id,
    title: item.reason,
    party: `买家 ${item.buyer?.nickname || '—'} · 卖家 ${item.seller?.nickname || '—'}`,
    amount: item.amount.toFixed(2),
    statusLabel: secondHandStatusText(item.status),
    tone: item.status === 'arbitrating' ? 'orange' : item.status === 'seller_rejected' ? 'red' : 'blue',
    createdAt: formatDateTime(item.created_at),
    secondHandRefund: item,
  }
}

function mallStatusText(value: string) {
  return ({ submitted: '待处理', awaiting_return: '待退货', succeeded: '已完成', rejected: '已拒绝', closed: '已关闭', cancelled: '已取消' } as Record<string, string>)[value] || value
}

function mallStatusTone(value: string): RefundRow['tone'] {
  if (value === 'submitted') return 'orange'
  if (value === 'rejected') return 'red'
  if (value === 'succeeded') return 'green'
  if (['closed', 'cancelled'].includes(value)) return 'gray'
  return 'blue'
}

function secondHandStatusText(value: string) {
  return ({ pending: '卖家待响应', seller_rejected: '卖家拒绝待仲裁', arbitrating: '仲裁中', approved: '已退款', rejected: '已驳回', cancelled: '已取消' } as Record<string, string>)[value] || value
}

function openDetail(row: RefundRow) {
  selection.refund = row.refund || null
  selection.secondHandRefund = row.secondHandRefund || null
  uni.navigateTo({ url: '/pages/admin/refund-detail/index' })
}
</script>

<style scoped>
.page{min-height:100vh;background:#F4F6F4;padding:20rpx 24rpx 48rpx;box-sizing:border-box}
.tabs{display:flex;gap:16rpx;margin-bottom:16rpx}
.tab{flex:1;padding:20rpx 0;border-radius:18rpx;background:#fff;color:#767C85;font-size:26rpx;text-align:center;border:2rpx solid transparent}
.tab.on{background:#E7F9EF;border-color:#08A152;color:#087A43;font-weight:700}
.filters{display:flex;gap:12rpx;margin-bottom:20rpx}
.chip{flex:1;padding:14rpx 0;border-radius:14rpx;background:#fff;color:#767C85;font-size:24rpx;text-align:center}
.chip.on{background:#EAF2FF;color:#1D4ED8;font-weight:700}
.tip{padding:120rpx 0;color:#AEB3BB;font-size:26rpx;text-align:center}
.card{margin-bottom:20rpx;padding:28rpx;border-radius:28rpx;background:#fff}
.card-head{display:flex;align-items:center;justify-content:space-between;gap:20rpx;margin-bottom:14rpx}
.no{flex:1;color:#AEB3BB;font-size:22rpx;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.tag{flex:none;padding:6rpx 16rpx;border-radius:999rpx;font-size:20rpx;background:#EAF2FF;color:#1D4ED8}
.tag.orange{background:#FFF2E5;color:#B74A05}
.tag.green{background:#E7F9EF;color:#08A152}
.tag.red{background:#FFECEA;color:#C0261D}
.tag.gray{background:#F1F3F1;color:#767C85}
.title{display:block;color:#191B1F;font-size:30rpx;font-weight:700}
.meta{display:block;margin-top:10rpx;color:#767C85;font-size:24rpx}
.foot{display:flex;align-items:flex-end;justify-content:space-between;margin-top:14rpx}
.amount{color:#FF3B30;font-size:34rpx;font-weight:800}
</style>
