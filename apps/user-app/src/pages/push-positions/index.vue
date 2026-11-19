<template>
  <view class="push-page">
    <view class="tabs">
      <view :class="['tab', tab === 'positions' ? 'on' : '']" @tap="tab = 'positions'">我的推广位</view>
      <view :class="['tab', tab === 'submissions' ? 'on' : '']" @tap="switchSubmissions">交单记录</view>
    </view>

    <view v-if="loading" class="tip">加载中…</view>

    <template v-else-if="tab === 'positions'">
      <view v-if="!positions.length" class="empty">
        <text class="empty-title">还没有领取推广位</text>
        <text class="empty-desc">先去推广任务列表领取一个推广位，再回来交单。</text>
        <button class="primary-btn" @tap="goTasks">去领取推广位</button>
      </view>
      <view v-for="position in positions" :key="position.id" class="card">
        <view class="card-head">
          <text class="card-title">{{ position.task?.title || '推广任务' }}</text>
          <text :class="['status', isExpired(position) ? '' : 'active']">{{ isExpired(position) ? '已过期' : '进行中' }}</text>
        </view>
        <text class="muted">关键词：{{ position.keyword || '未填写' }}</text>
        <text class="muted">领取时间：{{ formatTime(position.claimed_at) }} · 截止 {{ formatTime(position.expires_at) }}</text>
        <text v-if="position.latest_submission?.status === 'pending'" class="muted">交单状态：待审核</text>
        <text v-else-if="position.latest_submission?.status === 'approved'" class="muted">交单状态：已通过</text>
        <text v-else-if="position.latest_submission?.status === 'rejected'" class="reject">上次未通过：{{ position.latest_submission.reject_reason || '凭证不符合要求' }}</text>
        <view class="card-foot">
          <text class="money">单价 ¥{{ position.task?.reward_amount || '0.00' }}</text>
          <view class="foot-actions">
            <button v-if="!isExpired(position) && !isSubmitted(position)" class="ghost-mini" @tap="releasePosition(position)">放弃</button>
            <button v-if="!isExpired(position) && !isSubmitted(position)" class="primary-mini" @tap="goSubmit(position.id)">
              {{ position.latest_submission?.status === 'rejected' ? '重新交单' : '去交单' }}
            </button>
          </view>
        </view>
      </view>
    </template>

    <template v-else>
      <view v-if="!submissions.length" class="empty">
        <text class="empty-title">还没有交单记录</text>
        <text class="empty-desc">完成推广后上传凭证，审核通过即会计入佣金。</text>
      </view>
      <view v-for="submission in submissions" :key="submission.id" class="card">
        <view class="card-head">
          <text class="card-title">{{ submission.task?.title || '地推交单' }}</text>
          <text :class="['status', submission.status === 'approved' ? 'active' : submission.status === 'rejected' ? 'rejected' : '']">{{ statusText(submission.status) }}</text>
        </view>
        <text class="muted">{{ formatTime(submission.created_at) }} 提交 · {{ submission.level_code }} 等级</text>
        <text v-if="submission.status === 'approved'" class="muted">佣金 ¥{{ submission.commission }} · {{ submission.settled ? '已结算' : '待结算' }}</text>
        <text v-if="submission.status === 'rejected'" class="reject">驳回原因：{{ submission.reject_reason || '凭证不符合要求' }}</text>
      </view>
    </template>
  </view>
</template>

<script setup lang="ts">
import { onShow } from '@dcloudio/uni-app'
import { ref } from 'vue'
import { listPushPositions, listPushSubmissions, releasePushPosition, type PushPosition, type PushSubmission } from '@/api/push'
import { useAuthStore } from '@/stores/auth'
import { formatDateTime } from '@/utils/dateTime'

const auth = useAuthStore()
const tab = ref<'positions' | 'submissions'>('positions')
const positions = ref<PushPosition[]>([])
const submissions = ref<PushSubmission[]>([])
const loading = ref(false)

async function load() {
  if (!auth.accessToken) {
    uni.navigateTo({ url: '/pages/login/index' })
    return
  }
  loading.value = true
  try {
    const [positionResult, submissionResult] = await Promise.all([
      listPushPositions(auth.accessToken),
      listPushSubmissions(auth.accessToken),
    ])
    positions.value = positionResult.items
    submissions.value = submissionResult.items
  } catch (error) {
    uni.showToast({ title: error instanceof Error ? error.message : '推广位加载失败', icon: 'none' })
  } finally {
    loading.value = false
  }
}

function switchSubmissions() { tab.value = 'submissions'; void load() }
function goTasks() { uni.navigateTo({ url: '/pages/push-tasks/index' }) }
function goSubmit(id: string) { uni.navigateTo({ url: `/pages/push-submit/index?id=${encodeURIComponent(id)}` }) }
function releasePosition(position: PushPosition) {
  uni.showModal({
    title: '放弃推广位',
    content: '放弃后该推广位名额会退回，需重新领取。',
    success: async (result) => {
      if (!result.confirm) return
      try {
        await releasePushPosition(auth.accessToken!, position.id)
        uni.showToast({ title: '已放弃', icon: 'none' })
        await load()
      } catch (error) {
        uni.showToast({ title: error instanceof Error ? error.message : '操作失败', icon: 'none' })
      }
    },
  })
}
function formatTime(value: string | null) { return formatDateTime(value) }
function isExpired(position: PushPosition) {
  if (position.status !== 'active') return true
  if (position.task !== null && position.task.status !== 'published') return true
  const deadline = position.expires_at || position.task?.ends_at || null
  return deadline !== null && new Date(deadline).getTime() <= Date.now()
}
function isSubmitted(position: PushPosition) {
  return position.latest_submission?.status === 'pending' || position.latest_submission?.status === 'approved'
}
function statusText(status: string) { return ({ pending: '审核中', approved: '已通过', rejected: '已驳回' } as Record<string, string>)[status] || status }

onShow(() => { void load() })
</script>

<style scoped>
.push-page { min-height: 100vh; background: #f4f6f4; padding: 12px 12px 32px; box-sizing: border-box; }
.tabs { display: flex; gap: 8px; margin-bottom: 12px; }
.tab { padding: 7px 16px; border-radius: 16px; background: #fff; color: #767c85; font-size: 12px; }
.tab.on { background: #e7f9ef; color: #08a152; font-weight: 700; }
.tip { text-align: center; color: #aeb3bb; font-size: 12px; padding: 40px 0; }
.empty { background: #fff; border-radius: 16px; padding: 28px 18px; display: flex; flex-direction: column; align-items: center; gap: 6px; }
.empty-title { font-size: 15px; font-weight: 700; }
.empty-desc { font-size: 12px; color: #767c85; text-align: center; }
.primary-btn { margin-top: 8px; height: 40px; line-height: 40px; padding: 0 22px; border-radius: 20px; background: linear-gradient(135deg, #12d978, #08a152); color: #fff; font-size: 13px; font-weight: 700; }
.card { background: #fff; border-radius: 16px; padding: 14px; margin-bottom: 10px; }
.card-head { display: flex; align-items: center; justify-content: space-between; }
.card-title { font-size: 14px; font-weight: 700; }
.status { font-size: 11px; color: #aeb3bb; background: #f4f6f4; border-radius: 4px; padding: 2px 6px; }
.status.active { color: #08a152; background: #e7f9ef; }
.status.rejected { color: #b91c1c; background: #fff5f5; }
.muted { display: block; color: #767c85; font-size: 12px; margin-top: 6px; }
.reject { display: block; color: #b91c1c; font-size: 12px; margin-top: 6px; }
.card-foot { display: flex; align-items: center; justify-content: space-between; margin-top: 12px; padding-top: 10px; border-top: 1px solid #f2f4f3; }
.foot-actions { display: flex; gap: 8px; }
.ghost-mini { height: 30px; line-height: 30px; padding: 0 14px; border-radius: 15px; background: #f4f6f4; color: #767c85; font-size: 12px; font-weight: 700; }
.money { font-size: 12px; color: #f2620a; font-weight: 700; }
.primary-mini { height: 30px; line-height: 30px; padding: 0 16px; border-radius: 15px; background: linear-gradient(135deg, #12d978, #08a152); color: #fff; font-size: 12px; font-weight: 700; }
</style>
