<template>
  <view class="page">
    <view v-if="!mall && !secondHand" class="tip">售后单已失效，请返回列表重新选择</view>

    <template v-else>
      <view class="card">
        <view class="head">
          <text class="title">{{ mall ? mall.refund_no : `订单 ${secondHand?.order_id}` }}</text>
          <text :class="['tag', statusTone]">{{ statusLabel }}</text>
        </view>

        <template v-if="mall">
          <view class="row"><text class="label">订单号</text><text class="value">{{ mall.order_no || '—' }}</text></view>
          <view class="row"><text class="label">申请用户</text><text class="value">{{ mall.user?.nickname || '—' }}</text></view>
          <view class="row"><text class="label">售后类型</text><text class="value">{{ mall.service_type.includes('return') ? '退货退款' : '仅退款' }}</text></view>
          <view class="row"><text class="label">退款金额</text><text class="value price">¥{{ mall.refund_cash_amount.toFixed(2) }}</text></view>
          <view v-if="mall.refund_points" class="row"><text class="label">退回积分</text><text class="value">{{ mall.refund_points }}</text></view>
          <view class="row"><text class="label">申请原因</text><text class="value">{{ mall.reason_name }}</text></view>
          <view class="row"><text class="label">责任方</text><text class="value">{{ mall.responsibility_party }}</text></view>
          <view class="row"><text class="label">申请时间</text><text class="value">{{ formatDateTime(mall.created_at) }}</text></view>
          <view v-if="mall.review_note" class="row"><text class="label">审核备注</text><text class="value">{{ mall.review_note }}</text></view>
        </template>

        <template v-else-if="secondHand">
          <view class="row"><text class="label">退款金额</text><text class="value price">¥{{ secondHand.amount.toFixed(2) }}</text></view>
          <view class="row"><text class="label">买家</text><text class="value">{{ secondHand.buyer?.nickname || '—' }}</text></view>
          <view class="row"><text class="label">卖家</text><text class="value">{{ secondHand.seller?.nickname || '—' }}</text></view>
          <view class="row"><text class="label">退款原因</text><text class="value">{{ secondHand.reason }}</text></view>
          <view class="row"><text class="label">卖家说明</text><text class="value">{{ secondHand.seller_note || '未回应' }}</text></view>
          <view class="row"><text class="label">申请时间</text><text class="value">{{ formatDateTime(secondHand.created_at) }}</text></view>
          <view v-if="secondHand.auto_approve_at" class="row"><text class="label">自动退款</text><text class="value">{{ formatDateTime(secondHand.auto_approve_at) }}</text></view>
          <view v-if="secondHand.admin_note" class="row"><text class="label">仲裁备注</text><text class="value">{{ secondHand.admin_note }}</text></view>
        </template>
      </view>

      <text v-if="mall" class="hint">同意退款后买家需按约定寄回商品；收到退货后请及时确认，避免影响结算。</text>

      <view v-if="actions.length" class="action-bar">
        <button
          v-for="action in actions"
          :key="action.key"
          :class="['action', action.tone]"
          @click="openSheet(action.key, action.label)"
        >{{ action.label }}</button>
      </view>
    </template>

    <AdminReviewSheet
      v-model:visible="sheetVisible"
      :title="sheetTitle"
      :decisions="sheetDecisions"
      require-note
      :submitting="submitting"
      note-placeholder="请填写处理说明（必填）"
      @confirm="submit"
    />
  </view>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue'
import { ApiError } from '@/api/http'
import {
  arbitrateSecondHandRefund, receiveManagedReturn, reviewManagedRefund,
} from '@/api/admin/after-sale'
import AdminReviewSheet from '@/components/admin/AdminReviewSheet.vue'
import { useAdminGuard } from '@/composables/use-admin-guard'
import { useAdminReviewSelection } from '@/stores/admin-review-selection'
import { formatDateTime } from '@/utils/dateTime'

type ActionKey = 'approve' | 'reject' | 'receive-return'

interface ActionItem {
  key: ActionKey
  label: string
  tone: 'green' | 'red' | 'blue'
}

const guard = useAdminGuard()
const selection = useAdminReviewSelection()
const mall = computed(() => selection.refund)
const secondHand = computed(() => selection.secondHandRefund)

const sheetVisible = ref(false)
const pendingAction = ref<ActionKey>('approve')
const sheetTitle = ref('确认操作')
const submitting = ref(false)

const isSecondHand = computed(() => Boolean(secondHand.value))

const statusLabel = computed(() => {
  if (mall.value) {
    return ({ submitted: '待处理', awaiting_return: '待退货', succeeded: '已完成', rejected: '已拒绝', closed: '已关闭', cancelled: '已取消' } as Record<string, string>)[mall.value.status] || mall.value.status
  }
  if (secondHand.value) {
    return ({ pending: '卖家待响应', seller_rejected: '卖家拒绝待仲裁', arbitrating: '仲裁中', approved: '已退款', rejected: '已驳回', cancelled: '已取消' } as Record<string, string>)[secondHand.value.status] || secondHand.value.status
  }
  return ''
})

const statusTone = computed(() => {
  const status = mall.value?.status || secondHand.value?.status || ''
  if (status === 'submitted' || status === 'arbitrating' || status === 'seller_rejected') return 'orange'
  if (status === 'rejected') return 'red'
  if (status === 'succeeded' || status === 'approved') return 'green'
  return 'blue'
})

const actions = computed<ActionItem[]>(() => {
  if (mall.value) {
    if (mall.value.status === 'submitted') {
      return [
        { key: 'reject', label: '拒绝退款', tone: 'red' },
        { key: 'approve', label: '同意退款', tone: 'green' },
      ]
    }
    if (['awaiting_return', 'return_in_transit'].includes(mall.value.status)) {
      return [{ key: 'receive-return', label: '确认收到退货', tone: 'blue' }]
    }
    return []
  }
  if (secondHand.value && ['arbitrating', 'seller_rejected'].includes(secondHand.value.status)) {
    return [
      { key: 'reject', label: '支持卖家', tone: 'red' },
      { key: 'approve', label: '支持买家退款', tone: 'green' },
    ]
  }
  return []
})

const sheetDecisions = computed(() => {
  if (pendingAction.value === 'receive-return') {
    return [{ key: 'receive-return', label: '确认收货', tone: 'blue' as const }]
  }
  if (pendingAction.value === 'approve') {
    return [{ key: 'approve', label: isSecondHand.value ? '确认退款给买家' : '确认同意退款', tone: 'green' as const }]
  }
  return [{ key: 'reject', label: '确认拒绝', tone: 'red' as const }]
})

function openSheet(key: ActionKey, label: string) {
  pendingAction.value = key
  sheetTitle.value = label
  sheetVisible.value = true
}

async function submit(payload: { decision: string; note: string }) {
  if (submitting.value) return
  if (!payload.note.trim()) {
    uni.showToast({ title: '请填写处理说明', icon: 'none' })
    return
  }

  submitting.value = true
  try {
    if (mall.value) {
      if (pendingAction.value === 'receive-return') {
        await receiveManagedReturn(mall.value.id, payload.note)
      } else {
        await reviewManagedRefund(mall.value.id, {
          decision: pendingAction.value === 'approve' ? 'approve' : 'reject',
          note: payload.note,
          ...(pendingAction.value === 'approve'
            ? { responsibility_party: 'buyer', return_shipping_payer: 'buyer', return_method: 'logistics' }
            : {}),
        })
      }
    } else if (secondHand.value) {
      await arbitrateSecondHandRefund(secondHand.value.id, {
        decision: pendingAction.value === 'approve' ? 'approve' : 'reject',
        note: payload.note,
      })
    } else {
      return
    }

    sheetVisible.value = false
    uni.showToast({ title: '处理成功', icon: 'success' })
    setTimeout(() => uni.navigateBack(), 600)
  } catch (error) {
    if (guard.handleAuthError(error)) return
    uni.showToast({ title: error instanceof ApiError ? error.message : '提交失败', icon: 'none' })
  } finally {
    submitting.value = false
  }
}
</script>

<style scoped>
.page{min-height:100vh;background:#F4F6F4;padding:24rpx 24rpx 220rpx;box-sizing:border-box}
.tip{padding:160rpx 0;color:#AEB3BB;font-size:26rpx;text-align:center}
.card{padding:32rpx 30rpx;border-radius:28rpx;background:#fff}
.head{display:flex;align-items:center;justify-content:space-between;gap:20rpx;margin-bottom:20rpx}
.title{flex:1;color:#191B1F;font-size:32rpx;font-weight:800;word-break:break-all}
.tag{flex:none;padding:6rpx 16rpx;border-radius:999rpx;font-size:20rpx;background:#EAF2FF;color:#1D4ED8}
.tag.orange{background:#FFF2E5;color:#B74A05}
.tag.green{background:#E7F9EF;color:#08A152}
.tag.red{background:#FFECEA;color:#C0261D}
.row{display:flex;justify-content:space-between;gap:24rpx;padding:20rpx 0;border-bottom:1rpx solid #F2F4F3}
.row:last-child{border-bottom:0}
.label{flex:none;color:#767C85;font-size:26rpx}
.value{flex:1;color:#191B1F;font-size:26rpx;text-align:right;word-break:break-all}
.value.price{color:#FF3B30;font-weight:800}
.hint{display:block;margin-top:20rpx;color:#AEB3BB;font-size:22rpx;line-height:1.7;padding:0 8rpx}
.action-bar{position:fixed;right:0;bottom:0;left:0;display:flex;gap:20rpx;padding:20rpx 24rpx calc(20rpx + env(safe-area-inset-bottom));background:#fff;box-shadow:0 -8rpx 24rpx rgba(23,26,23,.06)}
.action{flex:1;height:88rpx;border-radius:44rpx;font-size:28rpx;font-weight:700;line-height:88rpx}
.action.green{background:linear-gradient(135deg,#12D978,#08A152);color:#fff}
.action.red{background:#FFECEA;color:#C0261D}
.action.blue{background:#EAF2FF;color:#1D4ED8}
.action::after{border:0}
</style>
