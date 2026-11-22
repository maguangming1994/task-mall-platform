<template>
  <view class="page">
    <view class="tabs">
      <text :class="['tab', { on: tab === 'appeals' }]" @click="switchTab('appeals')">任务申诉</text>
      <text :class="['tab', { on: tab === 'risks' }]" @click="switchTab('risks')">风险案件</text>
    </view>

    <view v-if="loading" class="tip">加载中…</view>

    <template v-else-if="tab === 'appeals'">
      <view v-if="!appeals.length" class="tip">暂无待处理申诉</view>
      <view v-else class="list">
        <view v-for="appeal in appeals" :key="appeal.id" class="card">
          <view class="card-head">
            <text class="title">第 {{ appeal.appeal_no }} 次申诉</text>
            <text class="tag orange">待复核</text>
          </view>
          <text class="meta">申诉理由：{{ appeal.reason }}</text>
          <text class="meta">提交时间：{{ formatDateTime(appeal.created_at) }}</text>
          <view class="actions">
            <button class="act reject" @click="openSheet('appeal-reject', appeal)">驳回申诉</button>
            <button class="act approve" @click="openSheet('appeal-approve', appeal)">支持申诉</button>
          </view>
        </view>
      </view>
    </template>

    <template v-else>
      <view v-if="!risks.length" class="tip">暂无进行中的风险案件</view>
      <view v-else class="list">
        <view v-for="risk in risks" :key="risk.id" class="card">
          <view class="card-head">
            <text class="title">{{ risk.source }} · {{ risk.risk_level }}</text>
            <text :class="['tag', risk.overdue ? 'red' : 'orange']">{{ risk.overdue ? '已逾期' : '处理中' }}</text>
          </view>
          <text class="meta">任务 ID：{{ risk.task_id }}</text>
          <text class="meta">奖励{{ risk.reward_frozen ? '已冻结' : '未冻结' }} · 回复截止 {{ formatDateTime(risk.response_due_at) }}</text>
          <view class="actions">
            <button class="act reject" @click="openSheet('risk-violation', risk)">确认违规</button>
            <button class="act approve" @click="openSheet('risk-clear', risk)">解除风险</button>
          </view>
        </view>
      </view>
    </template>

    <AdminReviewSheet
      v-model:visible="sheetVisible"
      :title="sheetTitle"
      :decisions="sheetDecisions"
      :require-note="requireNote"
      :submitting="submitting"
      :note-placeholder="requireNote ? '请填写处理说明（必填）' : '可填写处理说明'"
      @confirm="submit"
    />
  </view>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { onLoad } from '@dcloudio/uni-app'
import { ApiError } from '@/api/http'
import {
  listTaskAppeals, listTaskRiskCases, reviewTaskAppeal, reviewTaskRiskCase,
  type AdminTaskAppeal, type AdminTaskRiskCase,
} from '@/api/admin/task-cases'
import AdminReviewSheet from '@/components/admin/AdminReviewSheet.vue'
import { useAdminGuard } from '@/composables/use-admin-guard'
import { formatDateTime } from '@/utils/dateTime'

type SheetMode = 'appeal-approve' | 'appeal-reject' | 'risk-clear' | 'risk-violation'

const guard = useAdminGuard()
const tab = ref<'appeals' | 'risks'>('appeals')
const loading = ref(true)
const submitting = ref(false)
const appeals = ref<AdminTaskAppeal[]>([])
const risks = ref<AdminTaskRiskCase[]>([])

const sheetVisible = ref(false)
const sheetMode = ref<SheetMode>('appeal-approve')
const sheetTitle = ref('')
const requireNote = ref(false)
const activeAppeal = ref<AdminTaskAppeal | null>(null)
const activeRisk = ref<AdminTaskRiskCase | null>(null)
const sheetDecisions = ref<Array<{ key: string; label: string; tone?: 'green' | 'red' | 'orange' | 'blue' | 'purple' }>>([])

onLoad(() => {
  if (!guard.requireSession()) return
  void load()
})

function switchTab(next: 'appeals' | 'risks') {
  if (tab.value === next) return
  tab.value = next
  void load()
}

async function load() {
  loading.value = true
  try {
    if (tab.value === 'appeals') appeals.value = (await listTaskAppeals()).items
    else risks.value = (await listTaskRiskCases()).items
  } catch (error) {
    if (guard.handleAuthError(error)) return
    uni.showToast({ title: error instanceof ApiError ? error.message : '加载失败', icon: 'none' })
  } finally {
    loading.value = false
  }
}

function openSheet(mode: SheetMode, target: AdminTaskAppeal | AdminTaskRiskCase) {
  sheetMode.value = mode
  if (mode.startsWith('appeal')) {
    activeAppeal.value = target as AdminTaskAppeal
    activeRisk.value = null
  } else {
    activeRisk.value = target as AdminTaskRiskCase
    activeAppeal.value = null
  }

  requireNote.value = mode === 'appeal-reject' || mode === 'risk-violation'
  if (mode === 'appeal-approve') {
    sheetTitle.value = '支持申诉并恢复'
    sheetDecisions.value = [{ key: 'approve', label: '确认支持', tone: 'green' }]
  } else if (mode === 'appeal-reject') {
    sheetTitle.value = '驳回申诉'
    sheetDecisions.value = [{ key: 'reject', label: '确认驳回', tone: 'red' }]
  } else if (mode === 'risk-clear') {
    sheetTitle.value = '解除风险案件'
    sheetDecisions.value = [{ key: 'clear', label: '确认解除', tone: 'green' }]
  } else {
    sheetTitle.value = '确认违规并处置'
    sheetDecisions.value = [{ key: 'confirm_violation', label: '确认违规', tone: 'red' }]
  }
  sheetVisible.value = true
}

async function submit(payload: { decision: string; note: string }) {
  if (submitting.value) return
  submitting.value = true
  try {
    if (activeAppeal.value) {
      const appeal = activeAppeal.value
      await reviewTaskAppeal(
        appeal.id,
        payload.decision === 'approve' ? 'approve' : 'reject',
        payload.note || (payload.decision === 'approve' ? '申诉理由成立，撤销原处置。' : '申诉理由不成立，维持原处置。'),
      )
      appeals.value = appeals.value.filter((item) => item.id !== appeal.id)
    } else if (activeRisk.value) {
      const risk = activeRisk.value
      if (payload.decision === 'clear') {
        await reviewTaskRiskCase(risk.id, 'clear', ['restore'], payload.note || '人工核查未发现违规')
      } else {
        await reviewTaskRiskCase(
          risk.id,
          'confirm_violation',
          ['stop_claiming', 'freeze_reward'],
          payload.note,
        )
      }
      risks.value = risks.value.filter((item) => item.id !== risk.id)
    }

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
.tabs{display:flex;gap:16rpx;margin-bottom:20rpx}
.tab{flex:1;padding:20rpx 0;border-radius:18rpx;background:#fff;color:#767C85;font-size:26rpx;text-align:center;border:2rpx solid transparent}
.tab.on{background:#E7F9EF;border-color:#08A152;color:#087A43;font-weight:700}
.tip{padding:120rpx 0;color:#AEB3BB;font-size:26rpx;text-align:center}
.card{margin-bottom:20rpx;padding:28rpx;border-radius:28rpx;background:#fff}
.card-head{display:flex;align-items:center;justify-content:space-between;gap:20rpx;margin-bottom:14rpx}
.title{flex:1;color:#191B1F;font-size:30rpx;font-weight:700}
.tag{flex:none;padding:6rpx 16rpx;border-radius:999rpx;font-size:20rpx;background:#FFF2E5;color:#B74A05}
.tag.red{background:#FFECEA;color:#C0261D}
.meta{display:block;margin-top:8rpx;color:#767C85;font-size:24rpx;line-height:1.6}
.actions{display:flex;gap:16rpx;margin-top:22rpx}
.act{flex:1;height:72rpx;border-radius:36rpx;font-size:26rpx;font-weight:700;line-height:72rpx}
.act.approve{background:linear-gradient(135deg,#12D978,#08A152);color:#fff}
.act.reject{background:#FFECEA;color:#C0261D}
.act::after{border:0}
</style>
