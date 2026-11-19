<template>
  <view class="push-page">
    <view class="search-row" @click="focusSearch">
      <input v-model="keyword" class="search-input" placeholder="搜索团队名称或标语" confirm-type="search" :focus="searchFocused" @blur="searchFocused = false" @confirm="reload" />
      <button class="search-btn" @tap.stop="reload">搜索</button>
    </view>
    <view class="tabs">
      <view v-for="item in sorts" :key="item.value" :class="['tab', sort === item.value ? 'on' : '']" @tap="changeSort(item.value)">{{ item.label }}</view>
    </view>

    <view v-if="loading" class="tip">加载中…</view>
    <view v-else-if="!teams.length" class="empty">
      <text class="empty-title">本校区暂无招募中的地推团队</text>
      <text class="empty-desc">可以申请成为团长，由平台运营审核后创建团队。</text>
      <button class="primary-btn" @tap="applyLeader">申请成为团长</button>
    </view>

    <view v-for="team in teams" :key="team.id" class="team-card" @tap="openTeam(team.id)">
      <view class="team-head">
        <view class="avatar">{{ team.name.slice(0, 1) }}</view>
        <view class="team-main">
          <view class="name-row">
            <text class="team-name">{{ team.name }}</text>
            <text class="tag">{{ team.level_code }}</text>
          </view>
          <text class="muted">{{ team.slogan || '校园地推团队' }}</text>
        </view>
        <text class="arrow">›</text>
      </view>
      <view class="meta">
        <text>团长 {{ team.leader?.nickname || '—' }}</text>
        <text>{{ team.member_count }}/{{ team.member_limit }} 人</text>
        <text>本月 {{ team.month_orders }} 单</text>
      </view>
      <view class="footer">
        <text class="bonus">团队加成 {{ (team.team_bonus_rate * 100).toFixed(1) }}%</text>
        <text class="cycle">{{ team.settlement_cycle === 'weekly' ? '周结' : '月结' }} · {{ team.review_hours }}h 内审核</text>
      </view>
    </view>
  </view>
</template>

<script setup lang="ts">
import { onShow } from '@dcloudio/uni-app'
import { ref } from 'vue'
import { applyPushLeader, listPushTeams, type PushTeamSummary } from '@/api/push'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const keyword = ref('')
const searchFocused = ref(false)

function focusSearch() {
  searchFocused.value = true
}
const sort = ref<'default' | 'commission' | 'members'>('default')
const teams = ref<PushTeamSummary[]>([])
const loading = ref(false)
const sorts = [
  { value: 'default' as const, label: '推荐' },
  { value: 'members' as const, label: '人数最多' },
  { value: 'commission' as const, label: '佣金最高' },
]

async function load() {
  if (!auth.accessToken) {
    uni.navigateTo({ url: '/pages/login/index' })
    return
  }
  loading.value = true
  try {
    const result = await listPushTeams(auth.accessToken, { keyword: keyword.value, sort: sort.value })
    teams.value = result.items
  } catch (error) {
    uni.showToast({ title: error instanceof Error ? error.message : '团队加载失败', icon: 'none' })
  } finally {
    loading.value = false
  }
}

function reload() { void load() }
function changeSort(value: 'default' | 'commission' | 'members') { sort.value = value; void load() }
function openTeam(id: string) { uni.navigateTo({ url: `/pages/push-team-detail/index?id=${encodeURIComponent(id)}` }) }

async function applyLeader() {
  if (!auth.accessToken) return
  const result = await uni.showModal({ title: '申请成为团长', editable: true, placeholderText: '请输入期望团队名称', content: '' })
  if (!result.confirm) return
  try {
    await applyPushLeader(auth.accessToken, { team_name: result.content || undefined })
    uni.showToast({ title: '已提交，等待平台审核', icon: 'none' })
  } catch (error) {
    uni.showToast({ title: error instanceof Error ? error.message : '提交失败', icon: 'none' })
  }
}

onShow(() => { void load() })
</script>

<style scoped>
.push-page { min-height: 100vh; background: #f4f6f4; padding: 12px 12px 32px; box-sizing: border-box; }
.search-row { display: flex; gap: 8px; }
.search-input { flex: 1; height: 38px; background: #fff; border-radius: 19px; padding: 0 14px; font-size: 13px; }
.search-btn { width: 64px; height: 38px; line-height: 38px; border-radius: 19px; background: linear-gradient(135deg, #12d978, #08a152); color: #fff; font-size: 13px; }
.tabs { display: flex; gap: 8px; margin: 12px 0; }
.tab { padding: 6px 14px; border-radius: 16px; background: #fff; color: #767c85; font-size: 12px; }
.tab.on { background: #e7f9ef; color: #08a152; font-weight: 700; }
.tip { text-align: center; color: #aeb3bb; font-size: 12px; padding: 40px 0; }
.empty { background: #fff; border-radius: 16px; padding: 28px 18px; display: flex; flex-direction: column; align-items: center; gap: 8px; }
.empty-title { font-size: 15px; font-weight: 700; color: #191b1f; }
.empty-desc { font-size: 12px; color: #767c85; text-align: center; }
.primary-btn { margin-top: 8px; height: 40px; line-height: 40px; padding: 0 22px; border-radius: 20px; background: linear-gradient(135deg, #12d978, #08a152); color: #fff; font-size: 13px; font-weight: 700; }
.team-card { background: #fff; border-radius: 16px; padding: 14px; margin-bottom: 10px; }
.team-head { display: flex; align-items: center; gap: 10px; }
.avatar { width: 44px; height: 44px; border-radius: 14px; background: #e7f9ef; color: #08a152; display: flex; align-items: center; justify-content: center; font-size: 18px; font-weight: 800; }
.team-main { flex: 1; }
.name-row { display: flex; align-items: center; gap: 6px; }
.team-name { font-size: 15px; font-weight: 700; color: #191b1f; }
.tag { font-size: 10px; color: #08a152; background: #e7f9ef; border-radius: 5px; padding: 1px 6px; }
.muted { display: block; color: #767c85; font-size: 12px; margin-top: 3px; }
.arrow { color: #aeb3bb; font-size: 20px; }
.meta { display: flex; gap: 14px; margin-top: 12px; font-size: 12px; color: #42526a; }
.footer { display: flex; justify-content: space-between; margin-top: 10px; padding-top: 10px; border-top: 1px solid #f2f4f3; font-size: 11px; }
.bonus { color: #f2620a; }
.cycle { color: #aeb3bb; }
</style>
