<template>
  <view class="page">
    <view class="tabs">
      <text :class="['tab', { on: tab === 'applications' }]" @click="switchTab('applications')">入队申请</text>
      <text :class="['tab', { on: tab === 'submissions' }]" @click="switchTab('submissions')">交单审核</text>
    </view>

    <view v-if="loading" class="tip">加载中…</view>

    <template v-else-if="tab === 'applications'">
      <view v-if="!applications.length" class="tip">暂无待处理入队申请</view>
      <view v-else class="list">
        <view v-for="item in applications" :key="item.id" class="card">
          <view class="card-head">
            <text class="title">{{ item.user?.nickname || '用户' }}</text>
            <text class="tag">{{ item.type === 'leader' ? '队长申请' : '入队申请' }}</text>
          </view>
          <text class="meta">目标团队：{{ item.team_name || item.team?.name || '未指定' }}</text>
          <text v-if="item.message" class="meta">留言：{{ item.message }}</text>
          <text class="meta">提交时间：{{ formatDateTime(item.created_at) }}</text>
          <view class="actions">
            <button class="act reject" @click="openApplication(item, 'reject')">拒绝</button>
            <button class="act approve" @click="openApplication(item, 'approve')">通过</button>
          </view>
        </view>
      </view>
    </template>

    <template v-else>
      <view v-if="!submissions.length" class="tip">暂无待审核交单</view>
      <view v-else class="list">
        <view v-for="item in submissions" :key="item.id" class="card" @click="openSubmission(item)">
          <view class="card-head">
            <text class="title">{{ item.task?.title || '地推任务' }}</text>
            <text class="tag orange">待审核</text>
          </view>
          <text class="meta">提交人 {{ item.user?.nickname || '—' }} · {{ item.level_code }}</text>
          <text class="meta">佣金 ¥{{ item.commission }} · 截图 {{ item.images?.length || 0 }} 张</text>
          <text class="meta">提交时间：{{ formatDateTime(item.created_at) }}</text>
          <text class="more">查看凭证与审核 ›</text>
        </view>
      </view>
    </template>

    <AdminReviewSheet
      v-model:visible="sheetVisible"
      :title="decision === 'approve' ? '通过入队申请' : '拒绝入队申请'"
      :decisions="sheetDecisions"
      :require-note="decision === 'reject'"
      :submitting="submitting"
      :note-placeholder="decision === 'reject' ? '请填写拒绝原因（必填）' : '可填写审核备注'"
      @confirm="submitApplication"
    />
  </view>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue'
import { onLoad } from '@dcloudio/uni-app'
import { ApiError } from '@/api/http'
import {
  listPushApplications, listPushSubmissions, reviewPushApplication,
  type AdminPushApplication, type AdminPushSubmission,
} from '@/api/admin/push'
import AdminReviewSheet from '@/components/admin/AdminReviewSheet.vue'
import { useAdminGuard } from '@/composables/use-admin-guard'
import { useAdminReviewSelection } from '@/stores/admin-review-selection'
import { formatDateTime } from '@/utils/dateTime'

const guard = useAdminGuard()
const selection = useAdminReviewSelection()

const tab = ref<'applications' | 'submissions'>('applications')
const loading = ref(true)
const submitting = ref(false)
const applications = ref<AdminPushApplication[]>([])
const submissions = ref<AdminPushSubmission[]>([])

const sheetVisible = ref(false)
const decision = ref<'approve' | 'reject'>('approve')
const activeApplication = ref<AdminPushApplication | null>(null)

const sheetDecisions = computed(() => (decision.value === 'approve'
  ? [{ key: 'approve', label: '确认通过', tone: 'green' as const }]
  : [{ key: 'reject', label: '确认拒绝', tone: 'red' as const }]))

onLoad(() => {
  if (!guard.requireSession()) return
  void load()
})

function switchTab(next: 'applications' | 'submissions') {
  if (tab.value === next) return
  tab.value = next
  void load()
}

async function load() {
  loading.value = true
  try {
    if (tab.value === 'applications') applications.value = (await listPushApplications('pending')).items
    else submissions.value = (await listPushSubmissions('pending')).items
  } catch (error) {
    if (guard.handleAuthError(error)) return
    uni.showToast({ title: error instanceof ApiError ? error.message : '加载失败', icon: 'none' })
  } finally {
    loading.value = false
  }
}

function openApplication(item: AdminPushApplication, next: 'approve' | 'reject') {
  activeApplication.value = item
  decision.value = next
  sheetVisible.value = true
}

async function submitApplication(payload: { decision: string; note: string }) {
  const target = activeApplication.value
  if (!target || submitting.value) return
  submitting.value = true
  try {
    await reviewPushApplication(target.id, {
      decision: payload.decision as 'approve' | 'reject',
      note: payload.note || undefined,
    })
    applications.value = applications.value.filter((item) => item.id !== target.id)
    sheetVisible.value = false
    uni.showToast({ title: '处理成功', icon: 'success' })
  } catch (error) {
    if (guard.handleAuthError(error)) return
    uni.showToast({ title: error instanceof ApiError ? error.message : '提交失败', icon: 'none' })
  } finally {
    submitting.value = false
  }
}

function openSubmission(item: AdminPushSubmission) {
  selection.pushSubmission = item
  uni.navigateTo({ url: '/pages/admin/push-submission-detail/index' })
}
</script>

<style scoped>
.page{min-height:100vh;background:#F4F6F4;padding:20rpx 24rpx 48rpx;box-sizing:border-box}
.tabs{display:flex;gap:16rpx;margin-bottom:20rpx}
.tab{flex:1;padding:20rpx 0;border-radius:18rpx;background:#fff;color:#767C85;font-size:26rpx;text-align:center;border:2rpx solid transparent}
.tab.on{background:#E7F9EF;border-color:#08A152;color:#087A43;font-weight:700}
.tip{padding:120rpx 0;color:#AEB3BB;font-size:26rpx;text-align:center}
.card{margin-bottom:20rpx;padding:28rpx;border-radius:28rpx;background:#fff}
.card-head{display:flex;align-items:center;justify-content:space-between;gap:20rpx;margin-bottom:12rpx}
.title{flex:1;color:#191B1F;font-size:30rpx;font-weight:700;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.tag{flex:none;padding:6rpx 16rpx;border-radius:999rpx;background:#EAF2FF;color:#1D4ED8;font-size:20rpx}
.tag.orange{background:#FFF2E5;color:#B74A05}
.meta{display:block;margin-top:8rpx;color:#767C85;font-size:24rpx}
.actions{display:flex;gap:16rpx;margin-top:22rpx}
.act{flex:1;height:72rpx;border-radius:36rpx;font-size:26rpx;font-weight:700;line-height:72rpx}
.act.approve{background:linear-gradient(135deg,#12D978,#08A152);color:#fff}
.act.reject{background:#FFECEA;color:#C0261D}
.act::after{border:0}
.more{display:block;margin-top:16rpx;color:#0BBF63;font-size:24rpx;text-align:right}
</style>
