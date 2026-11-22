<template>
  <view class="page">
    <view v-if="!item" class="tip">记录已失效，请返回列表重新选择</view>
    <template v-else>
      <view class="card">
        <view class="head">
          <text class="title">{{ item.user?.nickname || '用户' }}</text>
          <text :class="['tag', item.status]">{{ statusText(item.status) }}</text>
        </view>
        <view class="row"><text class="label">姓名</text><text class="value">{{ item.real_name_masked || '—' }}</text></view>
        <view class="row"><text class="label">证件号</text><text class="value">{{ item.identity_number_masked || '—' }}</text></view>
        <view class="row"><text class="label">认证渠道</text><text class="value">{{ item.provider }}</text></view>
        <view class="row"><text class="label">提交时间</text><text class="value">{{ formatDateTime(item.submitted_at) }}</text></view>
        <view v-if="item.verified_at" class="row"><text class="label">核验时间</text><text class="value">{{ formatDateTime(item.verified_at) }}</text></view>
        <view v-if="item.failure_code" class="row"><text class="label">失败原因</text><text class="value">{{ item.failure_code }}</text></view>
        <view v-if="item.review_note" class="row"><text class="label">审核备注</text><text class="value">{{ item.review_note }}</text></view>
        <view v-if="item.reviewed_by_name" class="row"><text class="label">审核人</text><text class="value">{{ item.reviewed_by_name }}</text></view>
      </view>

      <text class="pii-tip">实名信息以脱敏形式展示，请勿截图外传。</text>

      <view v-if="item.status === 'pending'" class="action-bar">
        <button class="reject" @click="openSheet('reject')">驳回</button>
        <button class="approve" @click="openSheet('approve')">通过</button>
      </view>
    </template>

    <AdminReviewSheet
      v-model:visible="sheetVisible"
      :title="decision === 'approve' ? '确认通过实名认证' : '驳回实名认证'"
      :decisions="sheetDecisions"
      :require-note="decision === 'reject'"
      :submitting="submitting"
      :note-placeholder="decision === 'reject' ? '请填写驳回原因（必填）' : '可填写审核备注'"
      @confirm="submit"
    />
  </view>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue'
import { ApiError } from '@/api/http'
import { reviewIdentityVerification } from '@/api/admin/identity'
import AdminReviewSheet from '@/components/admin/AdminReviewSheet.vue'
import { useAdminGuard } from '@/composables/use-admin-guard'
import { useAdminReviewSelection } from '@/stores/admin-review-selection'
import { formatDateTime } from '@/utils/dateTime'

const guard = useAdminGuard()
const selection = useAdminReviewSelection()
const item = computed(() => selection.identity)

const sheetVisible = ref(false)
const decision = ref<'approve' | 'reject'>('approve')
const submitting = ref(false)

const sheetDecisions = computed(() => (decision.value === 'approve'
  ? [{ key: 'approve', label: '确认通过', tone: 'green' as const }]
  : [{ key: 'reject', label: '确认驳回', tone: 'red' as const }]))

function statusText(value: string) {
  return ({ pending: '待审核', verified: '已通过', failed: '未通过', provider_error: '渠道异常' } as Record<string, string>)[value] || value
}

function openSheet(next: 'approve' | 'reject') {
  decision.value = next
  sheetVisible.value = true
}

async function submit(payload: { decision: string; note: string }) {
  if (!item.value || submitting.value) return
  submitting.value = true
  try {
    await reviewIdentityVerification(item.value.id, {
      decision: payload.decision as 'approve' | 'reject',
      note: payload.note || undefined,
    })
    sheetVisible.value = false
    uni.showToast({ title: payload.decision === 'approve' ? '已通过' : '已驳回', icon: 'success' })
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
.page{min-height:100vh;background:#F4F6F4;padding:24rpx 24rpx 200rpx;box-sizing:border-box}
.tip{padding:160rpx 0;color:#AEB3BB;font-size:26rpx;text-align:center}
.card{padding:32rpx 30rpx;border-radius:28rpx;background:#fff}
.head{display:flex;align-items:center;justify-content:space-between;margin-bottom:20rpx}
.title{color:#191B1F;font-size:32rpx;font-weight:800}
.tag{padding:6rpx 16rpx;border-radius:999rpx;font-size:20rpx;background:#EAF2FF;color:#1D4ED8}
.tag.verified{background:#E7F9EF;color:#08A152}
.tag.failed{background:#FFECEA;color:#C0261D}
.tag.provider_error{background:#FFF2E5;color:#B74A05}
.row{display:flex;justify-content:space-between;gap:24rpx;padding:20rpx 0;border-bottom:1rpx solid #F2F4F3}
.row:last-child{border-bottom:0}
.label{flex:none;color:#767C85;font-size:26rpx}
.value{flex:1;color:#191B1F;font-size:26rpx;text-align:right;word-break:break-all}
.pii-tip{display:block;margin-top:20rpx;color:#AEB3BB;font-size:22rpx;text-align:center}
.action-bar{position:fixed;right:0;bottom:0;left:0;display:flex;gap:20rpx;padding:20rpx 24rpx calc(20rpx + env(safe-area-inset-bottom));background:#fff;box-shadow:0 -8rpx 24rpx rgba(23,26,23,.06)}
.reject{flex:1;height:88rpx;border-radius:44rpx;background:#FFECEA;color:#C0261D;font-size:28rpx;font-weight:700;line-height:88rpx}
.approve{flex:2;height:88rpx;border-radius:44rpx;background:linear-gradient(135deg,#12D978,#08A152);color:#fff;font-size:28rpx;font-weight:700;line-height:88rpx}
.reject::after,.approve::after{border:0}
</style>
