<template>
  <view class="page">
    <view v-if="!item" class="tip">交单记录已失效，请返回列表重新选择</view>
    <template v-else>
      <view class="card">
        <view class="head">
          <text class="title">{{ item.task?.title || '地推任务' }}</text>
          <text class="tag">待审核</text>
        </view>
        <view class="row"><text class="label">提交人</text><text class="value">{{ item.user?.nickname || '—' }}</text></view>
        <view class="row"><text class="label">等级</text><text class="value">{{ item.level_code }}</text></view>
        <view class="row"><text class="label">佣金</text><text class="value price">¥{{ item.commission }}</text></view>
        <view class="row"><text class="label">团队奖励</text><text class="value">¥{{ item.team_bonus }}</text></view>
        <view class="row"><text class="label">联系人</text><text class="value">{{ item.contact_name || '—' }} {{ item.contact_phone || '' }}</text></view>
        <view v-if="item.remark" class="row"><text class="label">备注</text><text class="value">{{ item.remark }}</text></view>
        <view class="row"><text class="label">提交时间</text><text class="value">{{ formatDateTime(item.created_at) }}</text></view>
      </view>

      <view v-if="imageUrls.length" class="card">
        <text class="section-title">推广凭证</text>
        <view class="images">
          <image
            v-for="(url, index) in imageUrls"
            :key="url"
            class="shot"
            :src="url"
            mode="aspectFill"
            @click="preview(index)"
          />
        </view>
      </view>

      <view class="action-bar">
        <button class="reject" @click="openSheet('reject')">驳回</button>
        <button class="approve" @click="openSheet('approve')">通过</button>
      </view>
    </template>

    <AdminReviewSheet
      v-model:visible="sheetVisible"
      :title="decision === 'approve' ? '通过交单' : '驳回交单'"
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
import { ApiError, resolveApiAssetUrl } from '@/api/http'
import { reviewPushSubmission } from '@/api/admin/push'
import AdminReviewSheet from '@/components/admin/AdminReviewSheet.vue'
import { useAdminGuard } from '@/composables/use-admin-guard'
import { useAdminReviewSelection } from '@/stores/admin-review-selection'
import { formatDateTime } from '@/utils/dateTime'

const guard = useAdminGuard()
const selection = useAdminReviewSelection()
const item = computed(() => selection.pushSubmission)
const imageUrls = computed(() => (item.value?.images || []).map((fileId) => resolveApiAssetUrl(fileId)).filter(Boolean) as string[])

const sheetVisible = ref(false)
const decision = ref<'approve' | 'reject'>('approve')
const submitting = ref(false)

const sheetDecisions = computed(() => (decision.value === 'approve'
  ? [{ key: 'approve', label: '确认通过', tone: 'green' as const }]
  : [{ key: 'reject', label: '确认驳回', tone: 'red' as const }]))

function preview(index: number) {
  uni.previewImage({ urls: imageUrls.value, current: imageUrls.value[index] })
}

function openSheet(next: 'approve' | 'reject') {
  decision.value = next
  sheetVisible.value = true
}

async function submit(payload: { decision: string; note: string }) {
  if (!item.value || submitting.value) return
  submitting.value = true
  try {
    await reviewPushSubmission(item.value.id, {
      decision: payload.decision as 'approve' | 'reject',
      reason: payload.note || undefined,
    })
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
.page{min-height:100vh;background:#F4F6F4;padding:24rpx 24rpx 200rpx;box-sizing:border-box}
.tip{padding:160rpx 0;color:#AEB3BB;font-size:26rpx;text-align:center}
.card{margin-bottom:20rpx;padding:32rpx 30rpx;border-radius:28rpx;background:#fff}
.head{display:flex;align-items:center;justify-content:space-between;gap:20rpx;margin-bottom:16rpx}
.title{flex:1;color:#191B1F;font-size:32rpx;font-weight:800;line-height:1.5}
.tag{flex:none;padding:6rpx 16rpx;border-radius:999rpx;background:#FFF2E5;color:#B74A05;font-size:20rpx}
.row{display:flex;justify-content:space-between;gap:24rpx;padding:18rpx 0;border-bottom:1rpx solid #F2F4F3}
.row:last-child{border-bottom:0}
.label{flex:none;color:#767C85;font-size:26rpx}
.value{flex:1;color:#191B1F;font-size:26rpx;text-align:right;word-break:break-all}
.value.price{color:#FF3B30;font-weight:800}
.section-title{display:block;margin-bottom:16rpx;color:#191B1F;font-size:28rpx;font-weight:700}
.images{display:flex;flex-wrap:wrap;gap:16rpx}
.shot{width:200rpx;height:200rpx;border-radius:16rpx;background:#F4F6F4}
.action-bar{position:fixed;right:0;bottom:0;left:0;display:flex;gap:20rpx;padding:20rpx 24rpx calc(20rpx + env(safe-area-inset-bottom));background:#fff;box-shadow:0 -8rpx 24rpx rgba(23,26,23,.06)}
.reject{flex:1;height:88rpx;border-radius:44rpx;background:#FFECEA;color:#C0261D;font-size:28rpx;font-weight:700;line-height:88rpx}
.approve{flex:2;height:88rpx;border-radius:44rpx;background:linear-gradient(135deg,#12D978,#08A152);color:#fff;font-size:28rpx;font-weight:700;line-height:88rpx}
.reject::after,.approve::after{border:0}
</style>
