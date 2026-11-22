<template>
  <view class="page">
    <view v-if="!item" class="tip">资料已失效，请返回列表重新选择</view>
    <template v-else>
      <view class="card">
        <view class="head">
          <text class="title">{{ item.title }}</text>
          <text :class="['tag', item.status]">{{ statusText(item.status) }}</text>
        </view>
        <text v-if="item.description" class="desc">{{ item.description }}</text>
        <view class="row"><text class="label">分类</text><text class="value">{{ item.category?.name || '未分类' }}</text></view>
        <view class="row"><text class="label">网盘</text><text class="value">{{ item.netdisk_label }}</text></view>
        <view class="row"><text class="label">文件类型</text><text class="value">{{ item.file_type }}</text></view>
        <view class="row"><text class="label">上传者</text><text class="value">{{ item.uploader_name }}</text></view>
        <view class="row"><text class="label">提交时间</text><text class="value">{{ formatDateTime(item.created_at) }}</text></view>
        <view v-if="item.reject_reason" class="row"><text class="label">驳回原因</text><text class="value">{{ item.reject_reason }}</text></view>

        <view class="link-box" @click="copyLink">
          <text class="link-label">下载链接</text>
          <text class="link">{{ item.share_url }}</text>
          <text v-if="item.extract_code" class="link-code">提取码 {{ item.extract_code }}</text>
          <text class="copy-tip">点击复制</text>
        </view>
      </view>

      <view v-if="item.status === 'pending'" class="action-bar">
        <button class="reject" @click="openSheet('reject')">驳回</button>
        <button class="approve" @click="openSheet('approve')">通过</button>
      </view>
      <view v-else-if="item.status === 'approved'" class="action-bar single">
        <button class="reject" @click="openSheet('offline')">下架资料</button>
      </view>
    </template>

    <AdminReviewSheet
      v-model:visible="sheetVisible"
      :title="sheetTitle"
      :decisions="sheetDecisions"
      :require-note="decision === 'reject' || decision === 'offline'"
      :submitting="submitting"
      :note-placeholder="decision === 'reject' || decision === 'offline' ? '请填写原因（必填）' : '可填写审核备注'"
      @confirm="submit"
    />
  </view>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue'
import { ApiError } from '@/api/http'
import { reviewManagedMaterial } from '@/api/admin/material'
import AdminReviewSheet from '@/components/admin/AdminReviewSheet.vue'
import { useAdminGuard } from '@/composables/use-admin-guard'
import { useAdminReviewSelection } from '@/stores/admin-review-selection'
import { formatDateTime } from '@/utils/dateTime'

const guard = useAdminGuard()
const selection = useAdminReviewSelection()
const item = computed(() => selection.material)

const sheetVisible = ref(false)
const decision = ref<'approve' | 'reject' | 'offline'>('approve')
const submitting = ref(false)

const sheetTitle = computed(() => {
  if (decision.value === 'approve') return '确认通过资料'
  if (decision.value === 'reject') return '驳回资料'
  return '下架资料'
})

const sheetDecisions = computed(() => {
  if (decision.value === 'approve') return [{ key: 'approve', label: '确认通过', tone: 'green' as const }]
  if (decision.value === 'reject') return [{ key: 'reject', label: '确认驳回', tone: 'red' as const }]
  return [{ key: 'offline', label: '确认下架', tone: 'orange' as const }]
})

function statusText(value: string) {
  return ({ pending: '待审核', approved: '已通过', rejected: '未通过', offline: '已下架' } as Record<string, string>)[value] || value
}

function openSheet(next: 'approve' | 'reject' | 'offline') {
  decision.value = next
  sheetVisible.value = true
}

function copyLink() {
  if (!item.value) return
  const text = item.value.extract_code
    ? `${item.value.share_url} 提取码：${item.value.extract_code}`
    : item.value.share_url
  uni.setClipboardData({ data: text, success: () => uni.showToast({ title: '已复制', icon: 'success' }) })
}

async function submit(payload: { decision: string; note: string }) {
  if (!item.value || submitting.value) return
  submitting.value = true
  try {
    await reviewManagedMaterial(item.value.id, {
      decision: payload.decision as 'approve' | 'reject' | 'offline',
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
.card{padding:32rpx 30rpx;border-radius:28rpx;background:#fff}
.head{display:flex;align-items:flex-start;justify-content:space-between;gap:20rpx}
.title{flex:1;color:#191B1F;font-size:32rpx;font-weight:800;line-height:1.5;word-break:break-all}
.tag{flex:none;padding:6rpx 16rpx;border-radius:999rpx;background:#EAF2FF;color:#1D4ED8;font-size:20rpx}
.tag.approved{background:#E7F9EF;color:#08A152}
.tag.rejected{background:#FFECEA;color:#C0261D}
.tag.offline{background:#F1F3F1;color:#767C85}
.desc{display:block;margin:18rpx 0 8rpx;color:#767C85;font-size:26rpx;line-height:1.7}
.row{display:flex;justify-content:space-between;gap:24rpx;padding:20rpx 0;border-bottom:1rpx solid #F2F4F3}
.label{flex:none;color:#767C85;font-size:26rpx}
.value{flex:1;color:#191B1F;font-size:26rpx;text-align:right;word-break:break-all}
.link-box{margin-top:24rpx;padding:24rpx;border-radius:20rpx;background:#F7F9F8}
.link-label{display:block;color:#767C85;font-size:22rpx}
.link{display:block;margin-top:10rpx;color:#1D4ED8;font-size:24rpx;word-break:break-all}
.link-code{display:block;margin-top:8rpx;color:#191B1F;font-size:24rpx}
.copy-tip{display:block;margin-top:12rpx;color:#08A152;font-size:22rpx;text-align:right}
.action-bar{position:fixed;right:0;bottom:0;left:0;display:flex;gap:20rpx;padding:20rpx 24rpx calc(20rpx + env(safe-area-inset-bottom));background:#fff;box-shadow:0 -8rpx 24rpx rgba(23,26,23,.06)}
.reject{flex:1;height:88rpx;border-radius:44rpx;background:#FFECEA;color:#C0261D;font-size:28rpx;font-weight:700;line-height:88rpx}
.approve{flex:2;height:88rpx;border-radius:44rpx;background:linear-gradient(135deg,#12D978,#08A152);color:#fff;font-size:28rpx;font-weight:700;line-height:88rpx}
.action-bar.single .reject{flex:1}
.reject::after,.approve::after{border:0}
</style>
