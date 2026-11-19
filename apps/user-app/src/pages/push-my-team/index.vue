<template>
  <view class="push-page">
    <view v-if="loading" class="tip">加载中…</view>
    <view v-else-if="!data?.membership" class="empty">
      <text class="empty-title">你还没有加入地推团队</text>
      <text class="empty-desc">加入团队后可领取推广位、交单并结算佣金。</text>
      <button class="primary-btn" @tap="goPlaza">去团队广场</button>
    </view>

    <view v-else class="wrap">
      <view class="hero">
        <view class="hero-main">
          <text class="team-name">{{ data.team?.name }}</text>
          <text class="muted">{{ data.is_leader ? '我是团长' : '团队成员' }} · {{ data.membership.level_code }} · 本月 {{ data.membership.month_orders }} 单</text>
        </view>
        <text class="tag">{{ data.team?.member_count }} 人</text>
      </view>

      <view v-if="data.team && !teamOperational" class="notice">
        <text class="notice-title">团队{{ teamStatusText }}</text>
        <text class="notice-desc">当前无法领取推广位或交单，如有疑问请联系运营。</text>
      </view>

      <view class="quick-grid">
        <view class="quick" @tap="go('/pages/push-tasks/index')"><text class="q-ic">推</text><text>推广任务</text></view>
        <view class="quick" @tap="go('/pages/push-positions/index')"><text class="q-ic">位</text><text>我的推广位</text></view>
        <view class="quick" @tap="go('/pages/push-earnings/index')"><text class="q-ic">收</text><text>收益明细</text></view>
      </view>

      <view v-if="data.is_leader && teamOperational" class="card">
        <view class="card-head">
          <text class="card-title">待审入团申请</text>
          <text class="count">{{ data.applications.length }} 条</text>
        </view>
        <view v-if="!data.applications.length" class="muted pad">暂无待审申请</view>
        <view v-for="application in data.applications" :key="application.id" class="application">
          <view class="app-main">
            <text class="app-name">{{ application.user?.nickname || '用户' }}</text>
            <text class="muted">{{ application.message || '未填写申请说明' }}</text>
          </view>
          <view class="app-actions">
            <button class="mini primary" @tap="review(application.id, 'approve')">通过</button>
            <button class="mini ghost" @tap="review(application.id, 'reject')">驳回</button>
          </view>
        </view>
      </view>

      <view class="card">
        <view class="card-head">
          <text class="card-title">团队成员</text>
          <text class="count">{{ data.members.length }} 人</text>
        </view>
        <view v-for="member in data.members" :key="member.user_id || member.nickname" class="member">
          <view class="avatar">{{ member.nickname.slice(0, 1) }}</view>
          <view class="member-main">
            <text class="member-name">{{ member.nickname }}<text v-if="member.role === 'leader'" class="leader-tag">团长</text></text>
            <text class="muted">{{ member.level_code }} · 本月 {{ member.month_orders }} 单</text>
          </view>
          <text class="money">¥{{ member.month_commission }}</text>
        </view>
      </view>

      <button v-if="!data.is_leader" class="leave-btn" @tap="leave">退出团队</button>
    </view>
  </view>
</template>

<script setup lang="ts">
import { onShow } from '@dcloudio/uni-app'
import { computed, ref } from 'vue'
import { getPushMyTeam, leavePushTeam, reviewPushApplication, type PushMyTeam } from '@/api/push'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const data = ref<PushMyTeam | null>(null)
const loading = ref(false)
const teamOperational = computed(() => ['recruiting', 'active'].includes(data.value?.team?.status || ''))
const teamStatusText = computed(() => ({
  recruiting: '招募中',
  active: '运营中',
  suspended: '已暂停',
  closed: '已关闭',
} as Record<string, string>)[data.value?.team?.status || ''] || '')

async function load() {
  if (!auth.accessToken) {
    uni.navigateTo({ url: '/pages/login/index' })
    return
  }
  loading.value = true
  try {
    data.value = await getPushMyTeam(auth.accessToken)
  } catch (error) {
    uni.showToast({ title: error instanceof Error ? error.message : '团队加载失败', icon: 'none' })
  } finally {
    loading.value = false
  }
}

async function review(id: string, decision: 'approve' | 'reject') {
  if (!auth.accessToken) return
  let note = ''
  if (decision === 'reject') {
    const result = await uni.showModal({ title: '驳回入团申请', editable: true, placeholderText: '请填写驳回原因', content: '' })
    if (!result.confirm) return
    note = result.content || ''
  } else {
    const result = await uni.showModal({ title: '通过入团申请', content: `确认让该用户加入「${data.value?.team?.name || '团队'}」？` })
    if (!result.confirm) return
  }
  try {
    await reviewPushApplication(auth.accessToken, id, decision, note || undefined)
    uni.showToast({ title: decision === 'approve' ? '已通过' : '已驳回', icon: 'success' })
    await load()
  } catch (error) {
    uni.showToast({ title: error instanceof Error ? error.message : '审核失败', icon: 'none' })
  }
}

async function leave() {
  if (!auth.accessToken) return
  const result = await uni.showModal({ title: '退出团队', content: '退出后不能继续领取推广位，历史佣金不受影响。' })
  if (!result.confirm) return
  try {
    await leavePushTeam(auth.accessToken)
    uni.showToast({ title: '已退出团队', icon: 'success' })
    await load()
  } catch (error) {
    uni.showToast({ title: error instanceof Error ? error.message : '操作失败', icon: 'none' })
  }
}

function go(url: string) { uni.navigateTo({ url }) }
function goPlaza() { go('/pages/push-teams/index') }

onShow(() => { void load() })
</script>

<style scoped>
.push-page { min-height: 100vh; background: #f4f6f4; padding: 12px 12px 32px; box-sizing: border-box; }
.wrap { display: flex; flex-direction: column; gap: 12px; }
.tip { text-align: center; color: #aeb3bb; font-size: 12px; padding: 40px 0; }
.empty { background: #fff; border-radius: 16px; padding: 32px 18px; display: flex; flex-direction: column; align-items: center; gap: 8px; }
.empty-title { font-size: 15px; font-weight: 700; }
.empty-desc { font-size: 12px; color: #767c85; text-align: center; }
.primary-btn { margin-top: 8px; height: 40px; line-height: 40px; padding: 0 22px; border-radius: 20px; background: linear-gradient(135deg, #12d978, #08a152); color: #fff; font-size: 13px; font-weight: 700; }
.hero { background: linear-gradient(135deg, #12d978, #08a152); border-radius: 16px; padding: 16px; color: #fff; display: flex; align-items: center; }
.hero-main { flex: 1; }
.team-name { font-size: 17px; font-weight: 800; display: block; }
.muted { display: block; color: #767c85; font-size: 12px; margin-top: 3px; }
.hero .muted { color: rgba(255, 255, 255, .82); }
.tag { font-size: 11px; background: rgba(255, 255, 255, .2); border-radius: 999px; padding: 3px 10px; }
.quick-grid { display: flex; gap: 8px; }
.notice { background: #fff7ed; border: 1px solid #fed7aa; border-radius: 12px; padding: 10px 12px; }
.notice-title { display: block; font-size: 13px; font-weight: 700; color: #b45309; }
.notice-desc { display: block; font-size: 11px; color: #b45309; margin-top: 3px; }
.quick { flex: 1; background: #fff; border-radius: 14px; padding: 12px 0; display: flex; flex-direction: column; align-items: center; gap: 5px; font-size: 12px; color: #191b1f; }
.q-ic { width: 30px; height: 30px; border-radius: 10px; background: #e7f9ef; color: #08a152; display: flex; align-items: center; justify-content: center; font-weight: 800; }
.card { background: #fff; border-radius: 16px; padding: 14px; }
.card-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 6px; }
.card-title { font-size: 15px; font-weight: 700; }
.count { font-size: 12px; color: #aeb3bb; }
.pad { padding: 10px 0; }
.application { display: flex; align-items: center; gap: 10px; padding: 10px 0; border-bottom: 1px solid #f2f4f3; }
.application:last-child { border-bottom: 0; }
.app-main { flex: 1; }
.app-name { font-size: 13px; font-weight: 600; }
.app-actions { display: flex; gap: 6px; }
.mini { height: 28px; line-height: 28px; padding: 0 12px; border-radius: 14px; font-size: 11px; }
.mini.primary { background: #e7f9ef; color: #08a152; font-weight: 700; }
.mini.ghost { background: #fff; color: #767c85; border: 1px solid #e5e7eb; }
.member { display: flex; align-items: center; gap: 10px; padding: 10px 0; border-bottom: 1px solid #f2f4f3; }
.member:last-child { border-bottom: 0; }
.avatar { width: 36px; height: 36px; border-radius: 50%; background: #e7f9ef; color: #08a152; display: flex; align-items: center; justify-content: center; font-weight: 700; }
.member-main { flex: 1; }
.member-name { font-size: 13px; font-weight: 600; }
.leader-tag { font-size: 9px; color: #f2620a; background: #fff2e5; border-radius: 4px; padding: 1px 4px; margin-left: 5px; }
.money { font-size: 12px; font-weight: 700; color: #08a152; }
.leave-btn { height: 40px; line-height: 40px; border-radius: 20px; background: #fff; color: #b91c1c; font-size: 13px; border: 1px solid #ffd7d2; }
</style>
