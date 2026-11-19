<template>
  <view class="push-page">
    <view v-if="loading" class="tip">加载中…</view>
    <view v-else-if="detail" class="wrap">
      <view class="hero">
        <view class="avatar">{{ detail.team.name.slice(0, 1) }}</view>
        <view class="hero-main">
          <view class="name-row">
            <text class="team-name">{{ detail.team.name }}</text>
            <text class="tag">{{ detail.team.level_code }} · {{ detail.team.level_name }}</text>
          </view>
          <text class="slogan">{{ detail.team.slogan || '校园地推团队' }}</text>
          <text class="campus">{{ detail.team.campus?.name || '本校区' }}</text>
        </view>
      </view>

      <view class="stats">
        <view class="stat"><text class="num">{{ detail.team.member_count }}</text><text class="lab">成员</text></view>
        <view class="stat"><text class="num">{{ detail.team.month_orders }}</text><text class="lab">本月单量</text></view>
        <view class="stat"><text class="num">¥{{ detail.team.month_commission }}</text><text class="lab">本月佣金</text></view>
      </view>

      <view class="card">
        <text class="card-title">团队规则</text>
        <view class="rule-row"><text>团队状态</text><text class="value">{{ teamStatusText }}</text></view>
        <view class="rule-row"><text>团队加成</text><text class="value">{{ (detail.team.team_bonus_rate * 100).toFixed(1) }}%（平台承担）</text></view>
        <view class="rule-row"><text>结算周期</text><text class="value">{{ detail.team.settlement_cycle === 'weekly' ? '周结' : '月结' }}</text></view>
        <view class="rule-row"><text>入团审核</text><text class="value">{{ detail.team.review_hours }} 小时内</text></view>
        <view class="rule-row"><text>团长</text><text class="value">{{ detail.team.leader?.nickname || '—' }}</text></view>
        <text v-if="detail.team.intro" class="intro">{{ detail.team.intro }}</text>
      </view>

      <view v-if="detail.team.ranking?.length" class="card">
        <text class="card-title">本月榜单</text>
        <view v-for="(member, index) in detail.team.ranking" :key="member.user_id || index" class="rank-row">
          <text class="rank-no">{{ index + 1 }}</text>
          <text class="rank-name">{{ member.nickname }}</text>
          <text class="rank-meta">{{ member.level_code }} · {{ member.month_orders }} 单</text>
          <text class="rank-money">¥{{ member.month_commission }}</text>
        </view>
      </view>

      <view class="card">
        <text class="card-title">入团条件</text>
        <view :class="['check-row', detail.conditions.identity_verified ? 'ok' : 'no']">
          <text class="dot">{{ detail.conditions.identity_verified ? '✓' : '!' }}</text>
          <text>已完成实名认证</text>
        </view>
        <view :class="['check-row', detail.conditions.campus_matched ? 'ok' : 'no']">
          <text class="dot">{{ detail.conditions.campus_matched ? '✓' : '!' }}</text>
          <text>与团队校区一致</text>
        </view>
        <view :class="['check-row', detail.conditions.no_other_team ? 'ok' : 'no']">
          <text class="dot">{{ detail.conditions.no_other_team ? '✓' : '!' }}</text>
          <text>未加入其他地推团队</text>
        </view>
        <text v-if="conditionsHint" class="hint">{{ conditionsHint }}</text>
      </view>

      <view class="bottom-bar">
        <button v-if="detail.is_member" class="primary-btn" @tap="goMyTeam">进入我的团队</button>
        <button v-else-if="detail.pending_application" class="ghost-btn" disabled>已申请，等待审核</button>
        <button v-else class="primary-btn" :disabled="!canApply" @tap="apply">{{ canApply ? '申请入团' : '暂不可申请' }}</button>
      </view>
    </view>
  </view>
</template>

<script setup lang="ts">
import { onLoad } from '@dcloudio/uni-app'
import { computed, ref } from 'vue'
import { applyPushTeam, getPushTeam, type PushTeamDetail } from '@/api/push'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const teamId = ref('')
const detail = ref<PushTeamDetail | null>(null)
const loading = ref(false)
const teamStatusText = computed(() => ({
  recruiting: '招募中',
  active: '运营中',
  suspended: '已暂停',
  closed: '已关闭',
} as Record<string, string>)[detail.value?.team.status || ''] || detail.value?.team.status || '—')
const canApply = computed(() => Boolean(
  detail.value && detail.value.team.status === 'recruiting' && detail.value.conditions.can_apply,
))
const conditionsHint = computed(() => {
  const conditions = detail.value?.conditions
  if (!conditions || conditions.can_apply) return ''
  if (conditions.pending_application) return '已提交申请，请等待审核'
  const missing: string[] = []
  if (!conditions.identity_verified) missing.push('完成实名认证')
  if (!conditions.campus_matched) missing.push('切换到团队所在校区')
  if (!conditions.no_other_team) missing.push('退出当前团队')
  return missing.length ? `还需：${missing.join('、')}` : ''
})

async function load() {
  if (!auth.accessToken) {
    uni.navigateTo({ url: '/pages/login/index' })
    return
  }
  if (!teamId.value) return
  loading.value = true
  try {
    detail.value = await getPushTeam(auth.accessToken, teamId.value)
  } catch (error) {
    uni.showToast({ title: error instanceof Error ? error.message : '团队加载失败', icon: 'none' })
  } finally {
    loading.value = false
  }
}

async function apply() {
  if (!auth.accessToken || !detail.value) return
  const result = await uni.showModal({ title: '申请加入团队', editable: true, placeholderText: '简单介绍自己（选填）', content: '' })
  if (!result.confirm) return
  try {
    await applyPushTeam(auth.accessToken, teamId.value, result.content || undefined)
    detail.value.pending_application = true
    uni.showToast({ title: '已提交申请', icon: 'success' })
  } catch (error) {
    uni.showToast({ title: error instanceof Error ? error.message : '申请失败', icon: 'none' })
  }
}

function goMyTeam() { uni.navigateTo({ url: '/pages/push-my-team/index' }) }

onLoad((options) => {
  teamId.value = String(options?.id || '')
  void load()
})
</script>

<style scoped>
.push-page { min-height: 100vh; background: #f4f6f4; padding: 12px 12px 90px; box-sizing: border-box; }
.wrap { display: flex; flex-direction: column; gap: 12px; }
.tip { text-align: center; color: #aeb3bb; font-size: 12px; padding: 40px 0; }
.hero { background: #fff; border-radius: 16px; padding: 16px; display: flex; gap: 12px; align-items: center; }
.avatar { width: 56px; height: 56px; border-radius: 18px; background: #e7f9ef; color: #08a152; display: flex; align-items: center; justify-content: center; font-size: 22px; font-weight: 800; }
.hero-main { flex: 1; }
.name-row { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.team-name { font-size: 17px; font-weight: 800; color: #191b1f; }
.tag { font-size: 10px; color: #08a152; background: #e7f9ef; border-radius: 5px; padding: 2px 6px; }
.slogan { display: block; font-size: 12px; color: #767c85; margin-top: 4px; }
.campus { display: block; font-size: 11px; color: #aeb3bb; margin-top: 4px; }
.stats { display: flex; background: #fff; border-radius: 16px; padding: 14px 0; }
.stat { flex: 1; display: flex; flex-direction: column; align-items: center; }
.num { font-size: 16px; font-weight: 800; color: #191b1f; }
.lab { font-size: 11px; color: #aeb3bb; margin-top: 3px; }
.card { background: #fff; border-radius: 16px; padding: 14px; }
.card-title { font-size: 15px; font-weight: 700; color: #191b1f; display: block; margin-bottom: 6px; }
.rule-row { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #f2f4f3; font-size: 13px; color: #42526a; }
.rule-row:last-of-type { border-bottom: 0; }
.value { color: #191b1f; font-weight: 600; }
.intro { display: block; margin-top: 8px; font-size: 12px; color: #767c85; line-height: 1.6; }
.rank-row { display: flex; align-items: center; gap: 8px; padding: 8px 0; border-bottom: 1px solid #f2f4f3; }
.rank-row:last-child { border-bottom: 0; }
.rank-no { width: 20px; font-weight: 800; color: #f2620a; font-style: italic; }
.rank-name { flex: 1; font-size: 13px; font-weight: 600; }
.rank-meta { font-size: 11px; color: #aeb3bb; }
.rank-money { font-size: 12px; color: #08a152; font-weight: 700; }
.check-row { display: flex; align-items: center; gap: 8px; padding: 7px 0; font-size: 13px; }
.check-row.ok { color: #08a152; }
.check-row.no { color: #b91c1c; }
.dot { width: 20px; height: 20px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 800; background: currentColor; }
.dot { color: #fff; }
.check-row.ok .dot { background: #08a152; }
.check-row.no .dot { background: #dc2626; }
.hint { display: block; margin-top: 6px; font-size: 11px; color: #b45309; }
.bottom-bar { position: fixed; left: 0; right: 0; bottom: 0; background: #fff; padding: 10px 14px calc(10px + env(safe-area-inset-bottom)); border-top: 1px solid #edefef; }
.primary-btn { width: 100%; height: 44px; line-height: 44px; border-radius: 22px; background: linear-gradient(135deg, #12d978, #08a152); color: #fff; font-size: 15px; font-weight: 700; }
.primary-btn[disabled] { background: #c9cfcb; color: #fff; }
.ghost-btn { width: 100%; height: 44px; line-height: 44px; border-radius: 22px; background: #f4f6f4; color: #767c85; font-size: 14px; }
</style>
