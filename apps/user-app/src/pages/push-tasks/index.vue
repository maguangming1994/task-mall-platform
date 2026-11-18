<template>
  <view class="push-page">
    <view class="banner">
      <text class="banner-title">推广任务</text>
      <text class="banner-sub">领取推广位后按任务要求完成拉新，提交凭证审核通过后按等级结算佣金。</text>
    </view>

    <view v-if="loading" class="tip">加载中…</view>
    <view v-else-if="!tasks.length" class="empty">
      <text class="empty-title">当前没有进行中的推广任务</text>
      <text class="empty-desc">运营发布新任务后会出现在这里，可稍后再来看看。</text>
    </view>

    <view v-for="task in tasks" :key="task.id" class="task-card" @tap="openTask(task.id)">
      <view class="task-top">
        <view class="cover">{{ task.cover_text || '推' }}</view>
        <view class="task-main">
          <text class="task-title">{{ task.title }}</text>
          <text class="desc">{{ task.description || '按任务说明完成校园推广' }}</text>
        </view>
        <view class="reward">
          <text class="reward-amount">¥{{ task.reward_amount }}</text>
          <text class="reward-label">单价</text>
        </view>
      </view>
      <view class="task-foot">
        <view class="chips">
          <text class="chip">剩余 {{ task.remaining === null ? '不限' : task.remaining }}</text>
          <text class="chip">每人限领 {{ task.per_user_limit }} 次</text>
          <text v-if="task.ends_at" class="chip">{{ formatDate(task.ends_at) }} 截止</text>
        </view>
        <text class="go">去领取 ›</text>
      </view>
    </view>
  </view>
</template>

<script setup lang="ts">
import { onReachBottom, onShow } from '@dcloudio/uni-app'
import { ref } from 'vue'
import { listPushTasks, type PushTask } from '@/api/push'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const tasks = ref<PushTask[]>([])
const page = ref(1)
const total = ref(0)
const loading = ref(false)

async function load(reset = false) {
  if (!auth.accessToken) {
    uni.navigateTo({ url: '/pages/login/index' })
    return
  }
  if (reset) {
    page.value = 1
    tasks.value = []
  }
  loading.value = true
  try {
    const result = await listPushTasks(auth.accessToken, page.value)
    tasks.value = reset ? result.items : [...tasks.value, ...result.items]
    total.value = result.pagination.total
  } catch (error) {
    uni.showToast({ title: error instanceof Error ? error.message : '任务加载失败', icon: 'none' })
  } finally {
    loading.value = false
  }
}

function openTask(id: string) { uni.navigateTo({ url: `/pages/push-task-detail/index?id=${encodeURIComponent(id)}` }) }
function formatDate(value: string) { return new Date(value).toLocaleDateString('zh-CN') }

onShow(() => { void load(true) })
onReachBottom(() => {
  if (tasks.value.length >= total.value || loading.value) return
  page.value += 1
  void load()
})
</script>

<style scoped>
.push-page { min-height: 100vh; background: #f4f6f4; padding: 12px 12px 32px; box-sizing: border-box; }
.banner { background: linear-gradient(135deg, #12d978, #08a152); border-radius: 16px; padding: 16px; color: #fff; margin-bottom: 12px; }
.banner-title { font-size: 18px; font-weight: 800; }
.banner-sub { display: block; font-size: 12px; margin-top: 6px; line-height: 1.6; opacity: .9; }
.tip { text-align: center; color: #aeb3bb; font-size: 12px; padding: 30px 0; }
.empty { background: #fff; border-radius: 16px; padding: 28px 18px; display: flex; flex-direction: column; align-items: center; gap: 6px; }
.empty-title { font-size: 15px; font-weight: 700; }
.empty-desc { font-size: 12px; color: #767c85; text-align: center; }
.task-card { background: #fff; border-radius: 16px; padding: 14px; margin-bottom: 10px; }
.task-top { display: flex; gap: 10px; align-items: flex-start; }
.cover { width: 44px; height: 44px; border-radius: 12px; background: #fff2e5; color: #f2620a; display: flex; align-items: center; justify-content: center; font-weight: 800; }
.task-main { flex: 1; }
.task-title { font-size: 15px; font-weight: 700; color: #191b1f; }
.desc { display: block; font-size: 12px; color: #767c85; margin-top: 4px; line-height: 1.5; }
.reward { text-align: right; }
.reward-amount { display: block; font-size: 18px; font-weight: 800; color: #ff3b30; }
.reward-label { font-size: 10px; color: #aeb3bb; }
.task-foot { display: flex; align-items: center; justify-content: space-between; margin-top: 12px; padding-top: 10px; border-top: 1px solid #f2f4f3; }
.chips { display: flex; gap: 6px; flex-wrap: wrap; }
.chip { font-size: 10px; color: #767c85; background: #f4f6f4; border-radius: 4px; padding: 2px 6px; }
.go { font-size: 12px; color: #08a152; font-weight: 700; }
</style>
