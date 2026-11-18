<template>
  <view class="push-page">
    <view v-if="loading" class="tip">加载中…</view>
    <view v-else-if="detail" class="wrap">
      <view class="hero">
        <view class="cover">{{ detail.task.cover_text || '推' }}</view>
        <text class="title">{{ detail.task.title }}</text>
        <view class="reward-row">
          <text class="reward">¥{{ detail.task.reward_amount }}</text>
          <text class="reward-label">/ 单（按等级系数结算）</text>
        </view>
        <view class="chips">
          <text class="chip">剩余 {{ detail.task.remaining === null ? '不限' : detail.task.remaining }}</text>
          <text class="chip">每人限领 {{ detail.task.per_user_limit }} 次</text>
          <text v-if="detail.task.ends_at" class="chip">{{ formatDate(detail.task.ends_at) }} 截止</text>
        </view>
      </view>

      <view class="card">
        <text class="card-title">任务说明</text>
        <text class="desc">{{ detail.task.description || '按运营要求完成校园推广，提交现场凭证等待审核。' }}</text>
      </view>

      <view class="card">
        <text class="card-title">我的推广位</text>
        <view v-if="!detail.my_positions.length" class="muted">还没有领取推广位</view>
        <view v-for="position in detail.my_positions" :key="position.id" class="position-row" @tap="openPosition(position.id)">
          <view class="pos-main">
            <text class="pos-status">{{ position.status === 'active' ? '进行中' : '已结束' }}</text>
            <text class="muted">{{ position.keyword || '未填写推广关键词' }} · {{ formatDate(position.claimed_at) }} 领取</text>
          </view>
          <text class="arrow">›</text>
        </view>
      </view>

      <view class="bottom-bar">
        <button v-if="!detail.is_member" class="primary-btn" @tap="goJoin">先加入团队</button>
        <button v-else-if="activePosition" class="primary-btn" @tap="openPosition(activePosition.id)">去交单</button>
        <button v-else class="primary-btn" :disabled="!canClaim" @tap="claim">{{ canClaim ? '领取推广位' : '暂不可领取' }}</button>
      </view>
    </view>
  </view>
</template>

<script setup lang="ts">
import { onLoad, onShow } from '@dcloudio/uni-app'
import { computed, ref } from 'vue'
import { claimPushPosition, getPushTask, type PushPosition, type PushTask } from '@/api/push'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const taskId = ref('')
const detail = ref<{ task: PushTask; is_member: boolean; my_positions: PushPosition[] } | null>(null)
const loading = ref(false)

const activePosition = computed(() => detail.value?.my_positions.find((item) => item.status === 'active'))
const canClaim = computed(() => {
  if (!detail.value) return false
  if (detail.value.task.status !== 'published') return false
  if (detail.value.task.remaining !== null && detail.value.task.remaining <= 0) return false
  return detail.value.my_positions.length < detail.value.task.per_user_limit
})

async function load() {
  if (!auth.accessToken) {
    uni.navigateTo({ url: '/pages/login/index' })
    return
  }
  if (!taskId.value) return
  loading.value = true
  try {
    detail.value = await getPushTask(auth.accessToken, taskId.value)
  } catch (error) {
    uni.showToast({ title: error instanceof Error ? error.message : '任务加载失败', icon: 'none' })
  } finally {
    loading.value = false
  }
}

async function claim() {
  if (!auth.accessToken || !detail.value) return
  const result = await uni.showModal({ title: '填写推广关键词', editable: true, placeholderText: '如：快手极速版校园拉新', content: '' })
  if (!result.confirm) return
  try {
    const position = await claimPushPosition(auth.accessToken, taskId.value, result.content || undefined)
    uni.showToast({ title: '推广位已领取', icon: 'success' })
    detail.value.my_positions.unshift(position)
    openPosition(position.id)
  } catch (error) {
    uni.showToast({ title: error instanceof Error ? error.message : '领取失败', icon: 'none' })
  }
}

function openPosition(id: string) {
  uni.navigateTo({ url: `/pages/push-positions/index?highlight=${encodeURIComponent(id)}` })
}
function goJoin() { uni.navigateTo({ url: '/pages/push-my-team/index' }) }
function formatDate(value: string | null) { return value ? new Date(value).toLocaleDateString('zh-CN') : '—' }

onLoad((options) => { taskId.value = String(options?.id || '') })
onShow(() => { void load() })
</script>

<style scoped>
.push-page { min-height: 100vh; background: #f4f6f4; padding: 12px 12px 90px; box-sizing: border-box; }
.wrap { display: flex; flex-direction: column; gap: 12px; }
.tip { text-align: center; color: #aeb3bb; font-size: 12px; padding: 40px 0; }
.hero { background: #fff; border-radius: 16px; padding: 18px; display: flex; flex-direction: column; align-items: center; }
.cover { width: 60px; height: 60px; border-radius: 18px; background: #fff2e5; color: #f2620a; display: flex; align-items: center; justify-content: center; font-size: 22px; font-weight: 800; }
.title { font-size: 18px; font-weight: 800; color: #191b1f; margin-top: 10px; text-align: center; }
.reward-row { display: flex; align-items: baseline; margin-top: 6px; }
.reward { font-size: 26px; font-weight: 800; color: #ff3b30; }
.reward-label { font-size: 11px; color: #aeb3bb; margin-left: 4px; }
.chips { display: flex; gap: 6px; flex-wrap: wrap; justify-content: center; margin-top: 10px; }
.chip { font-size: 10px; color: #767c85; background: #f4f6f4; border-radius: 4px; padding: 3px 8px; }
.card { background: #fff; border-radius: 16px; padding: 14px; }
.card-title { font-size: 15px; font-weight: 700; color: #191b1f; display: block; margin-bottom: 8px; }
.desc { font-size: 13px; color: #42526a; line-height: 1.7; }
.muted { display: block; color: #767c85; font-size: 12px; margin-top: 3px; }
.position-row { display: flex; align-items: center; padding: 10px 0; border-bottom: 1px solid #f2f4f3; }
.position-row:last-child { border-bottom: 0; }
.pos-main { flex: 1; }
.pos-status { font-size: 13px; font-weight: 600; }
.arrow { color: #aeb3bb; font-size: 18px; }
.bottom-bar { position: fixed; left: 0; right: 0; bottom: 0; background: #fff; padding: 10px 14px calc(10px + env(safe-area-inset-bottom)); border-top: 1px solid #edefef; }
.primary-btn { width: 100%; height: 44px; line-height: 44px; border-radius: 22px; background: linear-gradient(135deg, #12d978, #08a152); color: #fff; font-size: 15px; font-weight: 700; }
.primary-btn[disabled] { background: #c9cfcb; }
</style>
