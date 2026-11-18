<template>
  <view class="push-page">
    <view class="hero">
      <text class="hero-label">可提现余额</text>
      <view class="hero-amount"><text class="cny">¥</text>{{ earnings?.wallet.available_amount || '0.00' }}</view>
      <view class="hero-stats">
        <view class="stat"><text class="num">¥{{ earnings?.wallet.pending_amount || '0.00' }}</text><text class="lab">待结算</text></view>
        <view class="stat"><text class="num">¥{{ earnings?.wallet.settled_amount || '0.00' }}</text><text class="lab">已结算</text></view>
        <view class="stat"><text class="num">{{ earnings?.level.code || 'A1' }}</text><text class="lab">当前等级</text></view>
      </view>
      <button class="withdraw-btn" @tap="goWithdraw">去提现</button>
    </view>

    <view v-if="earnings" class="card rule-card">
      <text class="card-title">当前等级规则</text>
      <text class="muted">{{ earnings.level.name }} · 佣金系数 ×{{ earnings.level.coefficient.toFixed(3) }} · 团队加成 {{ (earnings.level.team_bonus_rate * 100).toFixed(1) }}%（平台承担）</text>
      <text class="tiny">佣金与任务积分分账；提现复用现有提现审核流程。</text>
    </view>

    <view class="card">
      <text class="card-title">佣金明细</text>
      <view v-if="!earnings?.items.length" class="muted pad">暂无佣金记录，完成交单并通过审核后会显示在这里。</view>
      <view v-for="item in earnings?.items" :key="item.id" class="item-row">
        <view class="item-main">
          <text class="item-title">{{ item.task_title || '地推推广' }}</text>
          <text class="muted">{{ formatTime(item.created_at) }} · {{ statusText(item.status) }}</text>
        </view>
        <view class="item-right">
          <text class="money">¥{{ item.commission }}</text>
          <text class="settle">{{ item.settled ? '已结算' : item.status === 'approved' ? '待结算' : '审核中' }}</text>
        </view>
      </view>
    </view>
  </view>
</template>

<script setup lang="ts">
import { onShow } from '@dcloudio/uni-app'
import { ref } from 'vue'
import { getPushEarnings, type PushEarnings } from '@/api/push'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const earnings = ref<PushEarnings | null>(null)

async function load() {
  if (!auth.accessToken) {
    uni.navigateTo({ url: '/pages/login/index' })
    return
  }
  try {
    earnings.value = await getPushEarnings(auth.accessToken)
  } catch (error) {
    uni.showToast({ title: error instanceof Error ? error.message : '收益加载失败', icon: 'none' })
  }
}

function goWithdraw() { uni.navigateTo({ url: '/pages/withdraw/index' }) }
function formatTime(value: string | null) { return value ? new Date(value).toLocaleDateString('zh-CN') : '—' }
function statusText(status: string) { return ({ pending: '审核中', approved: '已通过', rejected: '已驳回' } as Record<string, string>)[status] || status }

onShow(() => { void load() })
</script>

<style scoped>
.push-page { min-height: 100vh; background: #f4f6f4; padding: 12px 12px 32px; box-sizing: border-box; }
.hero { border-radius: 18px; padding: 18px; background: linear-gradient(135deg, #12d978, #08a152); color: #fff; box-shadow: 0 8px 20px rgba(11, 191, 99, .24); }
.hero-label { font-size: 12px; opacity: .85; }
.hero-amount { font-size: 32px; font-weight: 800; margin-top: 4px; }
.cny { font-size: 18px; margin-right: 2px; }
.hero-stats { display: flex; margin-top: 16px; }
.stat { flex: 1; display: flex; flex-direction: column; }
.num { font-size: 15px; font-weight: 700; }
.lab { font-size: 11px; opacity: .8; margin-top: 2px; }
.withdraw-btn { margin-top: 16px; height: 38px; line-height: 38px; border-radius: 19px; background: #fff; color: #08a152; font-size: 13px; font-weight: 700; }
.card { background: #fff; border-radius: 16px; padding: 14px; margin-top: 12px; }
.card-title { font-size: 15px; font-weight: 700; display: block; margin-bottom: 6px; }
.muted { display: block; color: #767c85; font-size: 12px; margin-top: 4px; }
.tiny { display: block; color: #aeb3bb; font-size: 10px; margin-top: 8px; }
.rule-card { background: #f7fdf9; }
.pad { padding: 12px 0; }
.item-row { display: flex; align-items: center; padding: 10px 0; border-bottom: 1px solid #f2f4f3; }
.item-row:last-child { border-bottom: 0; }
.item-main { flex: 1; }
.item-title { font-size: 13px; font-weight: 600; }
.item-right { text-align: right; }
.money { display: block; font-size: 14px; font-weight: 800; color: #08a152; }
.settle { font-size: 10px; color: #aeb3bb; }
</style>
