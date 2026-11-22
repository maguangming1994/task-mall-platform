<template>
  <view class="page">
    <view v-if="!activity" class="tip">活动已失效，请返回列表重新选择</view>
    <template v-else>
      <view class="card">
        <view class="head">
          <text class="title">{{ activity.title }}</text>
          <text class="tag">{{ statusText(activity.status) }}</text>
        </view>
        <text class="meta">{{ activity.category_label }} · {{ formatDateTime(activity.starts_at) }}</text>
        <text class="meta">{{ activity.location }} · {{ activity.participant_count }}/{{ activity.participant_limit }} 人</text>
        <text class="meta">发起人 {{ activity.creator?.nickname || '—' }}</text>
        <text class="details">{{ activity.details }}</text>
      </view>

      <view v-if="activity.participants?.length" class="card">
        <text class="section-title">参与人</text>
        <view v-for="participant in joinedParticipants" :key="participant.participant_id ?? participant.user_id ?? participant.nickname" class="participant">
          <view class="p-main">
            <text class="p-name">{{ participant.nickname }}</text>
            <text class="p-role">{{ participant.role === 'creator' ? '发起人' : '参与者' }}</text>
          </view>
          <text
            v-if="participant.participant_id"
            class="p-remove"
            @click="openSheet('remove', participant.participant_id)"
          >移出</text>
        </view>
      </view>

      <view v-if="actions.length" class="action-bar">
        <button
          v-for="action in actions"
          :key="action.key"
          :class="['action', action.tone]"
          @click="openSheet(action.key)"
        >{{ action.label }}</button>
      </view>
    </template>

    <AdminReviewSheet
      v-model:visible="sheetVisible"
      :title="sheetTitle"
      :decisions="sheetDecisions"
      :require-note="mode !== 'approve'"
      :submitting="submitting"
      :note-placeholder="mode === 'approve' ? '可填写审核备注' : '请填写原因（必填）'"
      @confirm="submit"
    />
  </view>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue'
import { ApiError } from '@/api/http'
import {
  closeCommunityActivity, removeActivityParticipant, reviewCommunityActivity,
} from '@/api/admin/community'
import AdminReviewSheet from '@/components/admin/AdminReviewSheet.vue'
import { useAdminGuard } from '@/composables/use-admin-guard'
import { useAdminReviewSelection } from '@/stores/admin-review-selection'
import { formatDateTime } from '@/utils/dateTime'

type SheetMode = 'approve' | 'reject' | 'close' | 'remove'

const guard = useAdminGuard()
const selection = useAdminReviewSelection()
const activity = computed(() => selection.activity)

const sheetVisible = ref(false)
const mode = ref<SheetMode>('approve')
const sheetTitle = ref('')
const sheetDecisions = ref<Array<{ key: string; label: string; tone?: 'green' | 'red' | 'orange' | 'blue' | 'purple' }>>([])
const submitting = ref(false)
const removingParticipantId = ref<number | null>(null)

const joinedParticipants = computed(() => (activity.value?.participants || []).filter((item) => item.status === 'joined'))

const actions = computed(() => {
  if (!activity.value) return []
  if (activity.value.status === 'pending_review') {
    return [
      { key: 'reject' as SheetMode, label: '驳回活动', tone: 'red' },
      { key: 'approve' as SheetMode, label: '通过审核', tone: 'green' },
    ]
  }
  if (['recruiting', 'ongoing'].includes(activity.value.status)) {
    return [{ key: 'close' as SheetMode, label: '关闭活动', tone: 'orange' }]
  }
  return []
})

function statusText(value: string) {
  return ({
    pending_review: '待审核', recruiting: '招募中', ongoing: '进行中', finished: '已结束',
    cancelled: '已关闭', rejected: '未通过',
  } as Record<string, string>)[value] || value
}

function openSheet(next: SheetMode, participantId?: number) {
  mode.value = next
  removingParticipantId.value = participantId ?? null
  if (next === 'approve') {
    sheetTitle.value = '通过组团活动'
    sheetDecisions.value = [{ key: 'approve', label: '确认通过', tone: 'green' }]
  } else if (next === 'reject') {
    sheetTitle.value = '驳回组团活动'
    sheetDecisions.value = [{ key: 'reject', label: '确认驳回', tone: 'red' }]
  } else if (next === 'close') {
    sheetTitle.value = '关闭组团活动'
    sheetDecisions.value = [{ key: 'close', label: '确认关闭', tone: 'orange' }]
  } else {
    sheetTitle.value = '移出参与人'
    sheetDecisions.value = [{ key: 'remove', label: '确认移出', tone: 'red' }]
  }
  sheetVisible.value = true
}

async function submit(payload: { decision: string; note: string }) {
  const current = activity.value
  if (!current || submitting.value) return
  if (mode.value !== 'approve' && !payload.note.trim()) {
    uni.showToast({ title: '请填写原因', icon: 'none' })
    return
  }

  submitting.value = true
  try {
    if (mode.value === 'remove') {
      const participantId = removingParticipantId.value
      if (!participantId) return
      selection.activity = await removeActivityParticipant(current.id, participantId, payload.note)
      uni.showToast({ title: '已移出', icon: 'success' })
      sheetVisible.value = false
      return
    }

    if (mode.value === 'close') {
      selection.activity = await closeCommunityActivity(current.id, payload.note)
      uni.showToast({ title: '活动已关闭', icon: 'success' })
      sheetVisible.value = false
      return
    }

    await reviewCommunityActivity(
      current.id,
      mode.value === 'approve' ? 'approve' : 'reject',
      payload.note || (mode.value === 'approve' ? '活动内容合规，审核通过。' : '活动内容不符合社区规范。'),
      mode.value === 'reject' ? 'content_not_compliant' : undefined,
    )
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
.head{display:flex;align-items:flex-start;justify-content:space-between;gap:20rpx;margin-bottom:12rpx}
.title{flex:1;color:#191B1F;font-size:32rpx;font-weight:800;line-height:1.5}
.tag{flex:none;padding:6rpx 16rpx;border-radius:999rpx;background:#EAF2FF;color:#1D4ED8;font-size:20rpx}
.meta{display:block;margin-top:8rpx;color:#767C85;font-size:24rpx}
.details{display:block;margin-top:20rpx;padding-top:20rpx;border-top:1rpx solid #F2F4F3;color:#191B1F;font-size:26rpx;line-height:1.7}
.section-title{display:block;margin-bottom:12rpx;color:#191B1F;font-size:28rpx;font-weight:700}
.participant{display:flex;align-items:center;justify-content:space-between;padding:18rpx 0;border-bottom:1rpx solid #F5F6F6}
.participant:last-child{border-bottom:0}
.p-main{display:flex;align-items:center;gap:14rpx}
.p-name{color:#191B1F;font-size:26rpx}
.p-role{color:#AEB3BB;font-size:20rpx}
.p-remove{padding:8rpx 18rpx;border-radius:999rpx;background:#FFECEA;color:#C0261D;font-size:22rpx}
.action-bar{position:fixed;right:0;bottom:0;left:0;display:flex;gap:20rpx;padding:20rpx 24rpx calc(20rpx + env(safe-area-inset-bottom));background:#fff;box-shadow:0 -8rpx 24rpx rgba(23,26,23,.06)}
.action{flex:1;height:88rpx;border-radius:44rpx;font-size:28rpx;font-weight:700;line-height:88rpx}
.action.green{background:linear-gradient(135deg,#12D978,#08A152);color:#fff}
.action.red{background:#FFECEA;color:#C0261D}
.action.orange{background:#FFF2E5;color:#B74A05}
.action::after{border:0}
</style>
