<template>
  <view class="page">
    <view v-if="loading" class="tip">加载中…</view>
    <view v-else-if="!items.length" class="tip">暂无待处理身份冲突</view>
    <view v-else class="list">
      <view v-for="item in items" :key="item.id" class="card">
        <view class="card-head">
          <text class="title">{{ item.provider }} · {{ item.kind }}</text>
          <text class="tag orange">待处理</text>
        </view>
        <text class="meta">来源账号：{{ item.source_user_id || '—' }}</text>
        <text class="meta">疑似目标：{{ item.target_user_id || '未匹配' }}</text>
        <text class="meta">候选账号数：{{ item.candidate_count }}</text>
        <text class="meta">创建时间：{{ formatDateTime(item.created_at) }}</text>
        <view class="actions">
          <button class="act reject" @click="openSheet(item, 'reject')">驳回冲突</button>
          <button
            v-if="item.target_user_id"
            class="act approve"
            @click="openSheet(item, 'attach_to_target')"
          >归属目标账号</button>
        </view>
      </view>
    </view>

    <AdminReviewSheet
      v-model:visible="sheetVisible"
      :title="resolution === 'attach_to_target' ? '归属到目标账号' : '驳回身份冲突'"
      :decisions="sheetDecisions"
      require-note
      :submitting="submitting"
      note-placeholder="请填写处理依据（必填）"
      @confirm="submit"
    />
  </view>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue'
import { onLoad } from '@dcloudio/uni-app'
import { ApiError } from '@/api/http'
import {
  listIdentityConflicts, resolveIdentityConflict, type AdminIdentityConflict,
} from '@/api/admin/identity-conflicts'
import AdminReviewSheet from '@/components/admin/AdminReviewSheet.vue'
import { useAdminGuard } from '@/composables/use-admin-guard'
import { formatDateTime } from '@/utils/dateTime'

const guard = useAdminGuard()
const loading = ref(true)
const submitting = ref(false)
const items = ref<AdminIdentityConflict[]>([])

const sheetVisible = ref(false)
const resolution = ref<'attach_to_target' | 'reject'>('reject')
const active = ref<AdminIdentityConflict | null>(null)

const sheetDecisions = computed(() => (resolution.value === 'attach_to_target'
  ? [{ key: 'attach_to_target', label: '确认归属', tone: 'green' as const }]
  : [{ key: 'reject', label: '确认驳回', tone: 'red' as const }]))

onLoad(() => {
  if (!guard.requireSession()) return
  void load()
})

async function load() {
  loading.value = true
  try {
    items.value = (await listIdentityConflicts('pending')).items
  } catch (error) {
    if (guard.handleAuthError(error)) return
    uni.showToast({ title: error instanceof ApiError ? error.message : '加载失败', icon: 'none' })
  } finally {
    loading.value = false
  }
}

function openSheet(item: AdminIdentityConflict, next: 'attach_to_target' | 'reject') {
  active.value = item
  resolution.value = next
  sheetVisible.value = true
}

async function submit(payload: { decision: string; note: string }) {
  const target = active.value
  if (!target || submitting.value) return
  submitting.value = true
  try {
    await resolveIdentityConflict(target.id, {
      resolution: resolution.value,
      reason: payload.note,
      ...(resolution.value === 'attach_to_target' && target.target_user_id
        ? { target_user_id: target.target_user_id }
        : {}),
    })
    items.value = items.value.filter((item) => item.id !== target.id)
    sheetVisible.value = false
    uni.showToast({ title: '处理成功', icon: 'success' })
  } catch (error) {
    if (guard.handleAuthError(error)) return
    uni.showToast({ title: error instanceof ApiError ? error.message : '提交失败', icon: 'none' })
  } finally {
    submitting.value = false
  }
}
</script>

<style scoped>
.page{min-height:100vh;background:#F4F6F4;padding:20rpx 24rpx 48rpx;box-sizing:border-box}
.tip{padding:120rpx 0;color:#AEB3BB;font-size:26rpx;text-align:center}
.card{margin-bottom:20rpx;padding:28rpx;border-radius:28rpx;background:#fff}
.card-head{display:flex;align-items:center;justify-content:space-between;gap:20rpx;margin-bottom:12rpx}
.title{flex:1;color:#191B1F;font-size:30rpx;font-weight:700}
.tag{flex:none;padding:6rpx 16rpx;border-radius:999rpx;background:#FFF2E5;color:#B74A05;font-size:20rpx}
.meta{display:block;margin-top:8rpx;color:#767C85;font-size:24rpx;word-break:break-all}
.actions{display:flex;gap:16rpx;margin-top:22rpx}
.act{flex:1;height:72rpx;border-radius:36rpx;font-size:26rpx;font-weight:700;line-height:72rpx}
.act.approve{background:linear-gradient(135deg,#12D978,#08A152);color:#fff}
.act.reject{background:#FFECEA;color:#C0261D}
.act::after{border:0}
</style>
