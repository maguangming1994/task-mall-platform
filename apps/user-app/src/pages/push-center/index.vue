<template>
  <view class="push-page">
    <view class="hero">
      <view class="hero-top">
        <view>
          <text class="hero-label">地推收益</text>
          <view class="hero-amount"><text class="cny">¥</text>{{ center?.wallet.available_amount || '0.00' }}</view>
        </view>
        <view class="level-chip">{{ center?.wallet.level_code || 'A1' }} · {{ center?.wallet.level_name || '新手' }}</view>
      </view>
      <view class="hero-stats">
        <view class="stat"><text class="num">¥{{ center?.wallet.pending_amount || '0.00' }}</text><text class="lab">待结算</text></view>
        <view class="stat"><text class="num">¥{{ center?.wallet.settled_amount || '0.00' }}</text><text class="lab">已结算</text></view>
        <view class="stat"><text class="num">{{ center?.wallet.month_orders || 0 }}</text><text class="lab">本月有效单</text></view>
      </view>
    </view>

    <view v-if="loadError" class="error-card">
      <text class="err-title">地推数据加载失败</text>
      <text class="err-desc">{{ loadError }}</text>
      <button class="ghost-btn" @tap="load">重新加载</button>
    </view>

    <view class="entry-grid">
      <view class="entry" @tap="go('/pages/push-my-team/index')">
        <text class="entry-ic">团</text><text class="entry-name">我的团队</text>
        <text class="entry-sub">{{ center?.membership ? center.membership.team_name : '未加入' }}</text>
      </view>
      <view class="entry" @tap="go('/pages/push-tasks/index')">
        <text class="entry-ic">推</text><text class="entry-name">推广任务</text>
        <text class="entry-sub">领取推广位</text>
      </view>
      <view class="entry" @tap="go('/pages/push-positions/index')">
        <text class="entry-ic">位</text><text class="entry-name">我的推广位</text>
        <text class="entry-sub">{{ center?.counts.positions || 0 }} 个进行中</text>
      </view>
      <view class="entry" @tap="go('/pages/push-earnings/index')">
        <text class="entry-ic">收</text><text class="entry-name">收益明细</text>
        <text class="entry-sub">佣金与结算</text>
      </view>
    </view>

    <view v-if="center?.team" class="card team-card" @tap="openMyTeam">
      <view class="card-head">
        <text class="card-title">{{ center.team.name }}</text>
        <text class="tag">{{ center.team.level_code }} 团队</text>
      </view>
      <text class="muted">{{ center.team.slogan || '和队友一起做校园推广' }}</text>
      <view class="team-stats">
        <text>{{ center.team.member_count }} 名成员</text>
        <text>本月 {{ center.team.month_orders }} 单</text>
        <text>团长 {{ center.team.leader?.nickname || '—' }}</text>
      </view>
      <view v-if="center.is_leader && (center.counts.pending_applications > 0)" class="notice-line">
        有 {{ center.counts.pending_applications }} 条入团申请待你审核
      </view>
    </view>

    <view v-else class="card empty-team">
      <text class="card-title">还没有加入地推团队</text>
      <text class="muted">加入团队后可领取推广位、交单结算佣金；也可以申请成为团长。</text>
      <view class="row-btns">
        <button class="primary-btn" @tap="go('/pages/push-teams/index')">浏览团队广场</button>
        <button class="ghost-btn" @tap="applyLeader">申请成为团长</button>
      </view>
    </view>

    <view v-if="center?.tasks?.length" class="card">
      <view class="card-head">
        <text class="card-title">正在招募的推广任务</text>
        <text class="more" @tap="go('/pages/push-tasks/index')">全部</text>
      </view>
      <view v-for="task in center.tasks" :key="task.id" class="task-row" @tap="openTask(task.id)">
        <view class="task-cover">{{ task.cover_text || '推' }}</view>
        <view class="task-main">
          <text class="task-title">{{ task.title }}</text>
          <text class="muted">单价 ¥{{ task.reward_amount }} · 剩余 {{ task.remaining === null ? '不限' : task.remaining }} 个推广位</text>
        </view>
        <text class="arrow">›</text>
      </view>
    </view>

    <view v-if="center?.levels?.length" class="card">
      <text class="card-title">等级与佣金规则</text>
      <view v-for="level in center.levels" :key="level.code" class="level-row">
        <text class="level-code">{{ level.code }}</text>
        <text class="level-name">{{ level.name }}</text>
        <text class="muted">系数 ×{{ level.coefficient.toFixed(3) }} · 团队加成 {{ (level.team_bonus_rate * 100).toFixed(1) }}%</text>
      </view>
      <text class="tiny">团队加成由平台承担；佣金与任务积分分账，提现沿用现有提现审核。</text>
    </view>
  </view>
</template>

<script setup lang="ts">
import { onShow } from '@dcloudio/uni-app'
import { ref } from 'vue'
import { getPushCenter, applyPushLeader, type PushCenter } from '@/api/push'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const center = ref<PushCenter | null>(null)
const loadError = ref('')

async function load() {
  if (!auth.accessToken) {
    uni.navigateTo({ url: '/pages/login/index' })
    return
  }
  loadError.value = ''
  try {
    center.value = await getPushCenter(auth.accessToken)
  } catch (error) {
    loadError.value = error instanceof Error ? error.message : '请检查网络后重试。'
  }
}

function go(url: string) {
  uni.navigateTo({ url })
}

function openTeam(id: string) {
  uni.navigateTo({ url: `/pages/push-team-detail/index?id=${encodeURIComponent(id)}` })
}

function openMyTeam() {
  if (center.value?.team) openTeam(center.value.team.id)
}

function openTask(id: string) {
  uni.navigateTo({ url: `/pages/push-task-detail/index?id=${encodeURIComponent(id)}` })
}

async function applyLeader() {
  if (!auth.accessToken) return
  const result = await uni.showModal({
    title: '申请成为团长',
    editable: true,
    placeholderText: '请输入期望团队名称',
    content: '',
  })
  if (!result.confirm) return
  try {
    await applyPushLeader(auth.accessToken, { team_name: result.content || undefined })
    uni.showToast({ title: '已提交，等待平台审核', icon: 'none' })
    await load()
  } catch (error) {
    uni.showToast({ title: error instanceof Error ? error.message : '提交失败', icon: 'none' })
  }
}

onShow(() => { void load() })
</script>

<style scoped>
.push-page { min-height: 100vh; padding: 12px 12px 32px; background: #f4f6f4; box-sizing: border-box; }
.hero { border-radius: 18px; padding: 18px; background: linear-gradient(135deg, #12d978, #08a152); color: #fff; box-shadow: 0 8px 20px rgba(11, 191, 99, .24); }
.hero-top { display: flex; align-items: flex-start; justify-content: space-between; }
.hero-label { font-size: 12px; opacity: .85; }
.hero-amount { font-size: 32px; font-weight: 800; margin-top: 4px; }
.cny { font-size: 18px; margin-right: 2px; }
.level-chip { font-size: 11px; background: rgba(255, 255, 255, .2); border-radius: 999px; padding: 4px 10px; }
.hero-stats { display: flex; margin-top: 16px; }
.stat { flex: 1; display: flex; flex-direction: column; }
.num { font-size: 15px; font-weight: 700; }
.lab { font-size: 11px; opacity: .8; margin-top: 2px; }
.card { background: #fff; border-radius: 16px; padding: 14px; margin-top: 12px; }
.card-head { display: flex; align-items: center; justify-content: space-between; }
.card-title { font-size: 15px; font-weight: 700; color: #191b1f; }
.muted { display: block; color: #767c85; font-size: 12px; margin-top: 4px; }
.tiny { display: block; color: #aeb3bb; font-size: 10px; margin-top: 8px; line-height: 1.5; }
.tag { font-size: 10px; color: #08a152; background: #e7f9ef; border-radius: 5px; padding: 2px 6px; }
.team-stats { display: flex; gap: 14px; margin-top: 10px; font-size: 12px; color: #42526a; }
.notice-line { margin-top: 10px; padding: 8px 10px; border-radius: 8px; background: #fff8ec; color: #8a5a15; font-size: 12px; }
.entry-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; margin-top: 12px; }
.entry { background: #fff; border-radius: 14px; padding: 12px 6px; display: flex; flex-direction: column; align-items: center; }
.entry-ic { width: 34px; height: 34px; border-radius: 11px; background: #e7f9ef; color: #08a152; display: flex; align-items: center; justify-content: center; font-weight: 800; }
.entry-name { font-size: 12px; font-weight: 600; margin-top: 6px; color: #191b1f; }
.entry-sub { font-size: 10px; color: #aeb3bb; margin-top: 2px; text-align: center; }
.task-row { display: flex; align-items: center; gap: 10px; padding: 10px 0; border-bottom: 1px solid #f2f4f3; }
.task-row:last-child { border-bottom: 0; }
.task-cover { width: 40px; height: 40px; border-radius: 11px; background: #fff2e5; color: #f2620a; display: flex; align-items: center; justify-content: center; font-weight: 700; }
.task-main { flex: 1; display: flex; flex-direction: column; }
.task-title { font-size: 13px; font-weight: 600; color: #191b1f; }
.arrow { color: #aeb3bb; font-size: 18px; }
.level-row { display: flex; align-items: center; gap: 8px; padding: 8px 0; border-bottom: 1px solid #f2f4f3; flex-wrap: wrap; }
.level-row:last-of-type { border-bottom: 0; }
.level-code { font-size: 12px; font-weight: 800; color: #08a152; }
.level-name { font-size: 12px; font-weight: 600; color: #191b1f; }
.row-btns { display: flex; gap: 10px; margin-top: 12px; }
.primary-btn, .ghost-btn { flex: 1; height: 38px; line-height: 38px; border-radius: 19px; font-size: 13px; font-weight: 600; }
.primary-btn { background: linear-gradient(135deg, #12d978, #08a152); color: #fff; }
.ghost-btn { background: #fff; color: #08a152; border: 1px solid #0bbf63; }
.error-card { background: #fff5f5; border: 1px solid #ffd7d2; border-radius: 14px; padding: 14px; margin-top: 12px; display: flex; flex-direction: column; gap: 6px; }
.err-title { color: #b91c1c; font-weight: 700; font-size: 14px; }
.err-desc { color: #b91c1c; font-size: 12px; }
.more { font-size: 12px; color: #aeb3bb; }
</style>
