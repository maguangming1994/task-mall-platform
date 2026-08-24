<template>
  <view class="page withdraw-page">
    <view class="page-header"><button class="back-button" aria-label="返回" @click="goBack">‹</button><text class="page-title">提现与手续费</text><view class="header-space" /></view>

    <view v-if="loading && !wallet" class="state-card" role="status" aria-live="polite"><text>正在加载可提现余额…</text></view>
    <view v-if="loadError" class="state-card error-state" role="alert"><text>{{ loadError }}</text><button @click="load">重新加载</button></view>

    <view class="balance-hero">
      <view class="hero-main"><text class="hero-label">可用余额（元） <text class="eye">◉</text></text><text class="hero-balance">{{ wallet ? `¥${money(wallet.withdrawable_amount)}` : '—' }}</text><text class="hero-note">由奖励金、永久有效</text></view>
      <view class="hero-asset"><text class="hero-asset-icon">¥</text><text>校园钱包</text></view>
      <view class="hero-divider" />
      <view class="hero-stat"><text>即将到期积分</text><strong>{{ expiringPoints }}</strong></view><view class="hero-stat frozen-stat"><text>冻结额（元） <text class="help">?</text></text><strong>{{ wallet ? `¥${money(wallet.frozen_amount)}` : '—' }}</strong><small>处理中暂不可用</small></view>
    </view>

    <view class="section-card rules-card"><view class="section-head"><text class="section-title">提现规则</text><text class="rule-link">规则说明　›</text></view><view class="rule-grid"><view class="rule-item"><view class="rule-icon minimum">$</view><view><text>最低提现（元）</text><strong>{{ minimumYuan }}</strong><small>单次最低提现金额</small></view></view><view class="rule-item"><view class="rule-icon maximum">$</view><view><text>最高提现（元）</text><strong>{{ maximumYuan }}</strong><small>单次最高提现金额</small></view></view></view><view v-if="insufficient" class="rule-warning"><text class="warning-icon">!</text><view><text>余额不足，暂不可提现</text><small>最低提现金额为 ¥{{ minimumYuan }}</small></view></view></view>

    <view class="section-card fee-card"><view class="section-head"><text class="section-title">手续费说明</text><text class="rule-link">如何计算　?</text></view><view class="fee-equation"><view><text>提现金额</text><strong class="blue">¥{{ quote ? money(quote.requested_amount) : '0.00' }}</strong></view><text class="operator">−</text><view><text>手续费（%）</text><strong class="blue">{{ quote ? feeRateText : '—' }}</strong></view><text class="operator">=</text><view><text>手续费金额</text><strong class="orange">¥{{ quote ? money(quote.fee_amount) : '0.00' }}</strong></view><text class="operator">=</text><view class="received-cell"><text>实际到账金额</text><strong class="green">¥{{ quote ? money(quote.received_amount) : '0.00' }}</strong></view></view><text class="fee-note">ⓘ 以提交时计算为准，手续费将从提现金额中扣除</text></view>

    <view class="section-card info-row"><view class="info-icon clock">◷</view><view class="info-main"><text class="info-title">到账时间</text><text class="info-note">工作日到账，周末及节假日顺延，请耐心等待</text></view><text class="info-side">预计1-3个工作日</text></view>
    <view class="section-card info-row"><view class="info-icon account">▣</view><view class="info-main"><text class="info-title">收款账户</text><text class="info-note">将提现至微信支付绑定的实名账户</text></view><text class="info-side">微信钱包　›</text></view>

    <view class="section-card amount-card"><view class="section-head"><text class="section-title">提现金额</text><text class="info-side">最多可提现 ¥{{ wallet ? money(wallet.withdrawable_amount) : '—' }}</text></view><view class="amount-input"><text>¥</text><input v-model="withdrawYuan" type="digit" placeholder="请输入提现金额" @input="onAmountInput" /><button @click="fillAll">全部提现</button></view><text class="amount-range">可提现范围：{{ minimumYuan }} ~ {{ maximumYuan }} 元</text></view>

    <label class="consent"><checkbox value="withdraw-consent" :checked="withdrawAgreed" color="#0f8a61" @change="onConsentChange" /><text>我已阅读并同意 <text class="link">《用户协议》</text> 和 <text class="link">《隐私政策》</text></text></label>
    <button class="withdraw-button" :disabled="!withdrawAgreed || withdrawing" :loading="withdrawing" @click="handleWithdraw">{{ quote ? '确认提现' : '提现' }}</button>
    <view class="safety-note">♢ 平台保障资金安全，禁止任何形式的线下交易或提现代办行为</view>

    <view v-if="actionError" class="action-error" role="alert"><text>{{ actionError }}</text><button @click="actionError = ''">知道了</button></view>
    <view v-if="withdrawals.length" class="history-card section-card"><view class="section-head"><text class="section-title">提现记录</text><text class="rule-link">最近记录</text></view><view v-for="item in withdrawals" :key="item.id" class="history-row"><view><text>{{ item.withdrawal_no }}</text><small>{{ formatDate(item.created_at) }} · {{ item.workflow?.next_action || '请稍后刷新处理状态' }}</small></view><view><text>¥{{ money(item.requested_amount) }}</text><small>{{ item.workflow?.status_label || statusText(item.status) }}</small></view></view></view>
  </view>
</template>

<script setup lang="ts">
import { onShow } from '@dcloudio/uni-app'
import { computed, ref } from 'vue'
import { createWithdrawal, getPointAccount, getWalletAccount, getWithdrawalQuote, listWithdrawals, type PointAccount, type WalletAccount, type Withdrawal, type WithdrawalQuote } from '@/api/assets'
import { useAuthStore } from '@/stores/auth'
import { navigateToLogin } from '@/utils/authRedirect'

const auth = useAuthStore()
const wallet = ref<WalletAccount | null>(null)
const points = ref<PointAccount | null>(null)
const withdrawals = ref<Withdrawal[]>([])
const withdrawYuan = ref('')
const quote = ref<WithdrawalQuote | null>(null)
const withdrawAgreed = ref(false)
const loading = ref(false)
const withdrawing = ref(false)
const loadError = ref('')
const actionError = ref('')
const fallbackMinimum = 100
const fallbackMaximum = 500000
const minimumCents = computed(() => quote.value?.minimum_amount || fallbackMinimum)
const maximumCents = computed(() => quote.value?.maximum_amount || fallbackMaximum)
const minimumYuan = computed(() => money(minimumCents.value))
const maximumYuan = computed(() => money(maximumCents.value))
const expiringPoints = computed(() => points.value?.expiring_points ?? '—')
const insufficient = computed(() => Boolean(wallet.value && wallet.value.withdrawable_amount < minimumCents.value))
const feeRateText = computed(() => quote.value ? `${(quote.value.fee_rate / 10000).toFixed(2)}%` : '—')

onShow(load)

async function load() {
  if (!auth.accessToken) return navigateToLogin()
  loading.value = true
  loadError.value = ''
  try {
    const [account, pointAccount, list] = await Promise.all([getWalletAccount(auth.accessToken), getPointAccount(auth.accessToken), listWithdrawals(auth.accessToken)])
    wallet.value = account
    points.value = pointAccount
    withdrawals.value = list.items
  } catch (error) {
    loadError.value = errorMessage(error, '提现信息加载失败，请稍后重试')
    uni.showToast({ title: loadError.value, icon: 'none' })
  } finally {
    loading.value = false
  }
}

function goBack() { const pages = getCurrentPages(); if (pages.length > 1) uni.navigateBack({ delta: 1 }); else uni.redirectTo({ url: '/pages/assets/index' }) }
function money(cents: number) { return (cents / 100).toFixed(2) }
function formatDate(value: string) { const date = new Date(value); return Number.isNaN(date.getTime()) ? value : date.toLocaleString() }
function statusText(value: string) { return ({ reviewing: '审核中', submitted: '待审核', approved: '待打款', processing: '处理中', succeeded: '已到账', completed: '已完成', rejected: '已拒绝', failed: '打款失败', cancelled: '已取消' } as Record<string, string>)[value] || '状态同步中' }
function errorMessage(error: unknown, fallback: string) { return error instanceof Error ? error.message : fallback }
function onAmountInput() { quote.value = null; actionError.value = '' }
function fillAll() { if (wallet.value) { withdrawYuan.value = money(wallet.value.withdrawable_amount); quote.value = null } }
function onConsentChange(event: { detail: { value: string[] } }) { withdrawAgreed.value = event.detail.value.includes('withdraw-consent') }
function amountCents() { const cents = Math.round(Number(withdrawYuan.value) * 100); if (!Number.isFinite(cents) || cents < 1) throw new Error('请输入正确的提现金额'); return cents }

async function handleWithdraw() {
  actionError.value = ''
  if (!withdrawAgreed.value) return uni.showToast({ title: '请先阅读并同意相关协议', icon: 'none' })
  let cents: number
  try { cents = amountCents() } catch (error) { actionError.value = errorMessage(error, '请输入正确的提现金额'); uni.showToast({ title: actionError.value, icon: 'none' }); return }
  if (!quote.value || quote.value.requested_amount !== cents) return calculateQuote(cents)
  const confirmed = await uni.showModal({ title: '确认提现', content: `手续费 ¥${money(quote.value.fee_amount)}，预计到账 ¥${money(quote.value.received_amount)}。提交后将冻结申请金额。` })
  if (!confirmed.confirm) return
  withdrawing.value = true
  try {
    await createWithdrawal(auth.accessToken, cents)
    quote.value = null
    withdrawYuan.value = ''
    await load()
    uni.showToast({ title: '已提交审核', icon: 'success' })
  } catch (error) {
    actionError.value = errorMessage(error, '提现申请失败，请稍后重试')
    uni.showToast({ title: actionError.value, icon: 'none' })
  } finally { withdrawing.value = false }
}

async function calculateQuote(cents: number) {
  withdrawing.value = true
  try { quote.value = await getWithdrawalQuote(auth.accessToken, cents); uni.showToast({ title: '手续费已试算', icon: 'none' }) }
  catch (error) { actionError.value = errorMessage(error, '手续费试算失败，请稍后重试'); uni.showToast({ title: actionError.value, icon: 'none' }) }
  finally { withdrawing.value = false }
}
</script>

<style scoped>
.page{min-height:100vh;box-sizing:border-box;padding:0 28rpx 54rpx;background:linear-gradient(180deg,#fff 0,#f7fbff 26%,#f5f9fd 100%);color:#10213a}.page-header{display:flex;height:96rpx;align-items:center;justify-content:space-between;margin:0 -10rpx}.page-title{font-size:21px;font-weight:900}.header-space{width:70rpx}.back-button{width:70rpx;height:70rpx;margin:0;padding:0;border:0;background:transparent;color:#10213a;font-size:35px;line-height:58rpx}.balance-hero{position:relative;overflow:hidden;margin:0 4rpx;padding:36rpx 32rpx 26rpx;border-radius:29rpx;background:linear-gradient(135deg,#2778e9,#1472dc 62%,#3192f0);box-shadow:0 18rpx 36rpx rgba(36,111,216,.22);color:#fff}.balance-hero::after{content:'¥';position:absolute;right:32rpx;top:30rpx;color:rgba(255,255,255,.13);font-size:90px;font-weight:900;line-height:1}.hero-main{position:relative;z-index:1}.hero-label{display:block;font-size:14px}.eye{margin-left:10rpx;font-size:14px}.hero-balance{display:block;margin-top:12rpx;font-size:34px;font-weight:900;letter-spacing:1rpx}.hero-note{display:block;margin-top:8rpx;color:rgba(255,255,255,.86);font-size:13px}.hero-asset{position:absolute;top:33rpx;right:36rpx;z-index:1;display:flex;align-items:center;gap:8rpx;color:rgba(255,255,255,.8);font-size:12px}.hero-asset-icon{display:flex;align-items:center;justify-content:center;width:45rpx;height:45rpx;border-radius:14rpx;background:rgba(255,255,255,.24);font-size:15px;font-weight:900}.hero-divider{height:1rpx;margin:35rpx -32rpx 20rpx;background:rgba(255,255,255,.2)}.hero-stat{display:inline-flex;width:47%;box-sizing:border-box;flex-direction:column;padding-left:48rpx;border-right:1rpx solid rgba(255,255,255,.25)}.hero-stat:first-of-type{padding-left:12rpx}.hero-stat:last-child{border-right:0}.hero-stat>text{font-size:14px}.hero-stat strong{display:block;margin-top:8rpx;font-size:19px}.hero-stat small{display:block;margin-top:6rpx;color:rgba(255,255,255,.82);font-size:13px}.help{display:inline-flex;align-items:center;justify-content:center;width:25rpx;height:25rpx;border:2rpx solid #fff;border-radius:50%;font-size:12px}.state-card{margin:18rpx 4rpx;padding:26rpx;border:1rpx solid #dce9ef;border-radius:20rpx;background:#fff;color:#748099;text-align:center;font-size:13px}.error-state{color:#b42318;background:#fff5f2}.state-card button{display:block;height:56rpx;margin:16rpx auto 0;padding:0 20rpx;border:1rpx solid currentColor;border-radius:999rpx;background:#fff;color:inherit;font-size:13px;line-height:54rpx}.section-card{margin:20rpx 4rpx 0;padding:27rpx;border:1rpx solid #e1eaf3;border-radius:26rpx;background:rgba(255,255,255,.98);box-shadow:0 10rpx 26rpx rgba(31,56,92,.05)}.section-head{display:flex;align-items:center;justify-content:space-between;gap:12rpx}.section-title{font-size:17px;font-weight:900}.rule-link{color:#2475da;font-size:14px;white-space:nowrap}.rule-grid{display:grid;grid-template-columns:1fr 1fr;gap:12rpx;margin-top:26rpx}.rule-item{display:flex;align-items:flex-start;gap:14rpx}.rule-icon{display:flex;align-items:center;justify-content:center;width:58rpx;height:58rpx;flex:none;border-radius:50%;font-size:17px;font-weight:900}.minimum{background:#fff2da;color:#f6a600}.maximum{background:#e6f8ef;color:#0fa265}.rule-item text,.rule-item strong,.rule-item small{display:block}.rule-item text{color:#52627a;font-size:13px}.rule-item strong{margin-top:5rpx;color:#10213a;font-size:17px}.rule-item small{margin-top:4rpx;color:#8492a8;font-size:12px;line-height:1.35}.rule-warning{display:flex;align-items:flex-start;gap:13rpx;margin-top:25rpx;padding:19rpx;border:1rpx solid #ffd9d8;border-radius:17rpx;background:#fff7f7;color:#df2c2c}.warning-icon{display:flex;align-items:center;justify-content:center;width:30rpx;height:30rpx;border:3rpx solid currentColor;border-radius:50%;font-size:13px;font-weight:900}.rule-warning text,.rule-warning small{display:block}.rule-warning text{font-size:14px}.rule-warning small{margin-top:6rpx;color:#64738a;font-size:13px}.fee-equation{display:flex;align-items:stretch;justify-content:space-between;gap:8rpx;margin-top:28rpx}.fee-equation>view{display:flex;min-width:0;flex:1;align-items:center;justify-content:center;flex-direction:column;padding:18rpx 5rpx;border:1rpx solid #e4ebf2;border-radius:17rpx;background:#fbfdff;text-align:center}.fee-equation>view>text{font-size:13px;color:#52627a;white-space:nowrap}.fee-equation strong{display:block;margin-top:8rpx;font-size:15px}.operator{display:flex;align-items:center;color:#10213a;font-size:19px;font-weight:700}.blue{color:#2674db}.orange{color:#f5a900}.green{color:#0ca568}.received-cell{border-color:#d7f0e5!important;background:#f4fcf8!important}.fee-note{display:block;margin-top:24rpx;color:#748099;font-size:13px;line-height:1.5}.info-row{display:flex;align-items:center;gap:16rpx;padding:20rpx 25rpx}.info-icon{display:flex;align-items:center;justify-content:center;width:50rpx;height:50rpx;flex:none;border-radius:15rpx;background:#e7f2ff;color:#2875dc;font-size:19px;font-weight:800}.account{background:#e9f5ff}.info-main{min-width:0;flex:1}.info-title{display:block;font-size:15px;font-weight:850}.info-note{display:block;margin-top:5rpx;overflow:hidden;color:#748099;font-size:13px;text-overflow:ellipsis;white-space:nowrap}.info-side{color:#6f7f98;font-size:13px;white-space:nowrap}.amount-card{padding-bottom:23rpx}.amount-input{display:flex;height:75rpx;align-items:center;margin-top:23rpx;border:1rpx solid #d3dfed;border-radius:17rpx;background:#fff;overflow:hidden}.amount-input>text{padding:0 18rpx;color:#10213a;font-size:19px;font-weight:900}.amount-input input{min-width:0;flex:1;height:75rpx;font-size:14px}.amount-input button{height:75rpx;margin:0;padding:0 17rpx;border:0;background:transparent;color:#2475da;font-size:13px;line-height:75rpx}.amount-range{display:block;margin:14rpx 3rpx 0;color:#748099;font-size:13px}.consent{display:flex;align-items:flex-start;gap:6rpx;margin:22rpx 10rpx 0;color:#748099;font-size:13px;line-height:1.5}.consent checkbox{transform:scale(.82);transform-origin:left top}.link{color:#0f9b61}.withdraw-button{height:76rpx;margin:17rpx 10rpx 0;border:0;border-radius:999rpx;background:linear-gradient(135deg,#e9edf3,#dce2eb);color:#aeb8c8;font-size:17px;font-weight:850;line-height:76rpx}.withdraw-button:not([disabled]){background:linear-gradient(135deg,#2378e2,#1db8a7);box-shadow:0 10rpx 22rpx rgba(37,123,209,.18);color:#fff}.withdraw-button[disabled]{opacity:1}.safety-note{margin:16rpx 10rpx 0;color:#a1aec0;text-align:center;font-size:12px}.action-error{display:flex;align-items:center;justify-content:space-between;gap:12rpx;margin:18rpx 4rpx 0;padding:16rpx;border:1rpx solid #f1c7c0;border-radius:15rpx;background:#fff5f2;color:#b42318;font-size:13px}.action-error text{min-width:0}.action-error button{height:48rpx;flex:none;margin:0;padding:0 12rpx;border:1rpx solid #b42318;border-radius:10rpx;background:#fff;color:#b42318;font-size:12px;line-height:46rpx}.history-card{padding-bottom:15rpx}.history-row{display:flex;align-items:center;justify-content:space-between;gap:12rpx;padding:17rpx 0;border-bottom:1rpx solid #e5ecf3}.history-row:last-child{border-bottom:0}.history-row>view:last-child{text-align:right}.history-row text,.history-row small{display:block}.history-row text{font-size:13px;font-weight:750}.history-row small{margin-top:5rpx;color:#748099;font-size:12px}@media screen and (max-width:360px){.page{padding-left:20rpx;padding-right:20rpx}.balance-hero{margin-left:0;margin-right:0;padding-left:24rpx;padding-right:24rpx}.section-card{margin-left:0;margin-right:0}.fee-equation{gap:3rpx}.fee-equation>view{padding-left:2rpx;padding-right:2rpx}.fee-equation>view>text{font-size:12px}.fee-equation strong{font-size:14px}.operator{font-size:15px}}
.back-button{border:0!important;background:transparent!important;box-shadow:none!important}
.back-button::after{display:none}
</style>
