<template>
  <view class="page withdraw-page">
    <ModulePlaceholder v-if="modules.resolved && !moduleEnabled" code="module_withdraw" />
    <view v-else-if="!modules.resolved" class="mp-skeleton">
      <view class="tm-skeleton" style="height: 200rpx; border-radius: 24rpx; margin: 24rpx 28rpx;" />
      <view class="tm-skeleton" style="height: 320rpx; border-radius: 24rpx; margin: 20rpx 28rpx;" />
    </view>
    <template v-else>
    <view class="page-header"><button class="back-button" aria-label="返回" @click="goBack">‹</button><text class="page-title">余额提现</text><view class="header-space" /></view>

    <view v-if="loading && !wallet" class="state-card" role="status" aria-live="polite"><text>正在加载可提现余额…</text></view>
    <view v-if="loadError" class="state-card error-state" role="alert"><text>{{ loadError }}</text><button @click="load">重新加载</button></view>

    <view class="balance-hero">
      <view class="hero-main"><text class="hero-label">可提现余额（元）</text><text class="hero-balance">{{ wallet ? `¥${money(wallet.withdrawable_amount)}` : '—' }}</text><text class="hero-note">余额永久有效，确认收货结算后可提</text></view>
      <view class="hero-asset"><text class="hero-asset-icon">¥</text><text>校园钱包</text></view>
      <view class="hero-divider" />
      <view class="hero-stat"><text>即将到期积分</text><strong>{{ expiringPoints }}</strong></view><view class="hero-stat frozen-stat"><text>冻结额（元）</text><strong>{{ wallet ? `¥${money(wallet.frozen_amount)}` : '—' }}</strong><small>处理中暂不可用</small></view>
    </view>

    <view class="section-card amount-card">
      <view class="section-head"><text class="section-title">提现金额</text><text class="info-side">最多可提现 ¥{{ wallet ? money(wallet.withdrawable_amount) : '—' }}</text></view>
      <view class="amount-input"><text>¥</text><input v-model="withdrawYuan" type="digit" placeholder="0.00" @input="onAmountInput" /><button @click="fillAll">全部提现</button></view>
      <text class="amount-range">可提现范围：{{ minimumYuan }} ~ {{ maximumYuan }} 元</text>
    </view>

    <view class="section-card rules-card"><view class="section-head"><text class="section-title">提现规则</text><text class="rule-link">规则说明 ›</text></view><view class="rule-grid"><view class="rule-item"><view class="rule-icon minimum">¥</view><view><text>最低提现（元）</text><strong>{{ minimumYuan }}</strong><small>单次最低提现金额</small></view></view><view class="rule-item"><view class="rule-icon maximum">¥</view><view><text>最高提现（元）</text><strong>{{ maximumYuan }}</strong><small>单次最高提现金额</small></view></view></view><view v-if="insufficient" class="rule-warning"><text class="warning-icon">!</text><view><text>余额不足，暂不可提现</text><small>最低提现金额为 ¥{{ minimumYuan }}</small></view></view></view>

    <view class="section-card fee-card"><view class="section-head"><text class="section-title">手续费说明</text><text class="rule-link">如何计算 ?</text></view><view class="fee-equation"><view><text>提现金额</text><strong class="dark">¥{{ quote ? money(quote.requested_amount) : '0.00' }}</strong></view><text class="operator">−</text><view><text>手续费率</text><strong class="dark">{{ quote ? feeRateText : '—' }}</strong></view><text class="operator">=</text><view><text>手续费</text><strong class="orange">¥{{ quote ? money(quote.fee_amount) : '0.00' }}</strong></view><text class="operator">→</text><view class="received-cell"><text>实际到账</text><strong class="green">¥{{ quote ? money(quote.received_amount) : '0.00' }}</strong></view></view><text class="fee-note">以提交时计算为准，手续费将从提现金额中扣除</text></view>

    <view class="section-card info-row"><view class="info-icon clock">◷</view><view class="info-main"><text class="info-title">到账时间</text><text class="info-note">工作日到账，周末及节假日顺延，请耐心等待</text></view><text class="info-side">预计1-3个工作日</text></view>
    <view class="section-card info-row"><view class="info-icon account">▣</view><view class="info-main"><text class="info-title">收款账户</text><text class="info-note">将提现至微信支付绑定的实名账户</text></view><text class="info-side">微信零钱 ›</text></view>

    <label class="consent"><checkbox value="withdraw-consent" :checked="withdrawAgreed" color="#0BBF63" @change="onConsentChange" /><text>我已阅读并同意 <text class="link">《用户协议》</text> 和 <text class="link">《隐私政策》</text></text></label>
    <button class="withdraw-button" :disabled="!withdrawAgreed || withdrawing" :loading="withdrawing" @click="handleWithdraw">{{ quote ? '确认提现' : '提现' }}</button>
    <view class="safety-note">平台保障资金安全，禁止任何形式的线下交易或提现代办行为</view>

    <view v-if="actionError" class="action-error" role="alert"><text>{{ actionError }}</text><button @click="actionError = ''">知道了</button></view>
    <view v-if="withdrawals.length" class="history-card section-card"><view class="section-head"><text class="section-title">提现记录</text><text class="rule-link">最近记录</text></view><view v-for="item in withdrawals" :key="item.id" class="history-row"><view><text>{{ item.withdrawal_no }}</text><small>{{ formatDate(item.created_at) }} · {{ item.workflow?.next_action || '请稍后刷新处理状态' }}</small></view><view><text>¥{{ money(item.requested_amount) }}</text><small>{{ item.workflow?.status_label || statusText(item.status) }}</small></view></view></view>
    </template>
  </view>
</template>

<script setup lang="ts">
import { formatDateTime as formatDate } from '@/utils/dateTime'
import { onShow } from '@dcloudio/uni-app'
import { computed, ref } from 'vue'
import { createWithdrawal, getPointAccount, getWalletAccount, getWithdrawalQuote, listWithdrawals, type PointAccount, type WalletAccount, type Withdrawal, type WithdrawalQuote } from '@/api/assets'
import { useAuthStore } from '@/stores/auth'
import { navigateToLogin } from '@/utils/authRedirect'
import ModulePlaceholder from '@/components/ModulePlaceholder.vue'
import { useModulesStore } from '@/stores/modules'

const modules = useModulesStore()
const moduleEnabled = computed(() => modules.isEnabled('module_withdraw'))

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
  await modules.ensureReady()
  if (!moduleEnabled.value) return
  if (!auth.isAuthenticated) return navigateToLogin()
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
.page{min-height:100vh;box-sizing:border-box;padding:0 28rpx 96rpx;background:#F4F6F4;color:#191B1F}.page-header{display:flex;height:88rpx;align-items:center;justify-content:space-between}.page-title{font-size:36rpx;font-weight:800}.header-space{width:64rpx}.back-button{width:64rpx;height:64rpx;margin:0;padding:0;border:0;background:transparent;color:#191B1F;font-size:48rpx;line-height:56rpx}.back-button::after{display:none}
/* 余额英雄卡 */
.balance-hero{position:relative;overflow:hidden;margin-top:8rpx;padding:40rpx 36rpx 28rpx;border-radius:36rpx;background:linear-gradient(135deg,#12D978,#08A152);box-shadow:0 20rpx 48rpx rgba(11,191,99,.28);color:#fff}.balance-hero::after{content:'¥';position:absolute;right:36rpx;top:28rpx;color:rgba(255,255,255,.13);font-size:180rpx;font-weight:900;line-height:1}.hero-main{position:relative;z-index:1}.hero-label{display:block;font-size:24rpx;opacity:.85}.hero-balance{display:block;margin-top:16rpx;font-size:68rpx;font-weight:800;letter-spacing:1rpx}.hero-note{display:block;margin-top:10rpx;color:rgba(255,255,255,.86);font-size:24rpx}.hero-asset{position:absolute;top:36rpx;right:40rpx;z-index:1;display:flex;align-items:center;gap:10rpx;color:rgba(255,255,255,.8);font-size:22rpx}.hero-asset-icon{display:flex;align-items:center;justify-content:center;width:44rpx;height:44rpx;border-radius:14rpx;background:rgba(255,255,255,.24);font-size:26rpx;font-weight:900}.hero-divider{height:1rpx;margin:32rpx -36rpx 20rpx;background:rgba(255,255,255,.25)}.hero-stat{display:inline-flex;width:47%;box-sizing:border-box;flex-direction:column;padding-left:44rpx;border-right:1rpx solid rgba(255,255,255,.25)}.hero-stat:first-of-type{padding-left:8rpx}.hero-stat:last-child{border-right:0}.hero-stat>text{font-size:24rpx}.hero-stat strong{display:block;margin-top:10rpx;font-size:34rpx}.hero-stat small{display:block;margin-top:6rpx;color:rgba(255,255,255,.82);font-size:22rpx}
/* 通用卡片 */
.section-card{margin:20rpx 0 0;padding:28rpx;border-radius:32rpx;background:#fff;box-shadow:0 2rpx 4rpx rgba(23,26,23,.03),0 12rpx 40rpx rgba(23,26,23,.05)}.section-head{display:flex;align-items:center;justify-content:space-between;gap:16rpx}.section-title{font-size:30rpx;font-weight:700;display:flex;align-items:center}.section-title::before{content:"";width:8rpx;height:28rpx;margin-right:14rpx;border-radius:4rpx;background:#0BBF63}.rule-link{color:#AEB3BB;font-size:24rpx;white-space:nowrap}
/* 提现金额 */
.amount-card{padding-bottom:28rpx}.amount-input{display:flex;height:96rpx;align-items:center;margin-top:24rpx;border-bottom:2rpx solid #EDEFEF}.amount-input>text{padding:0 12rpx 0 0;color:#191B1F;font-size:48rpx;font-weight:800}.amount-input input{min-width:0;flex:1;height:96rpx;font-size:56rpx;font-weight:800;color:#191B1F}.amount-input button{height:56rpx;margin:0;padding:0 24rpx;border:0;border-radius:28rpx;background:#E7F9EF;color:#08A152;font-size:24rpx;font-weight:700;line-height:56rpx}.amount-input button::after{border:0}.amount-range{display:block;margin:16rpx 2rpx 0;color:#AEB3BB;font-size:24rpx}
/* 规则 */
.rule-grid{display:grid;grid-template-columns:1fr 1fr;gap:16rpx;margin-top:24rpx}.rule-item{display:flex;align-items:flex-start;gap:16rpx;padding:20rpx;border-radius:24rpx;background:#F7F9F8}.rule-icon{display:flex;align-items:center;justify-content:center;width:56rpx;height:56rpx;flex:none;border-radius:50%;font-size:26rpx;font-weight:900}.rule-icon.minimum{background:#FFF2E5;color:#F2620A}.rule-icon.maximum{background:#E7F9EF;color:#08A152}.rule-item text,.rule-item strong,.rule-item small{display:block}.rule-item text{color:#767C85;font-size:24rpx}.rule-item strong{margin-top:6rpx;color:#191B1F;font-size:32rpx}.rule-item small{margin-top:4rpx;color:#AEB3BB;font-size:22rpx;line-height:1.35}.rule-warning{display:flex;align-items:flex-start;gap:16rpx;margin-top:20rpx;padding:20rpx;border-radius:24rpx;background:#FFEEEA;color:#FF2442}.warning-icon{display:flex;align-items:center;justify-content:center;width:32rpx;height:32rpx;flex:none;border:4rpx solid currentColor;border-radius:50%;font-size:22rpx;font-weight:900}.rule-warning text,.rule-warning small{display:block}.rule-warning text{font-size:26rpx}.rule-warning small{margin-top:6rpx;color:#767C85;font-size:24rpx}
/* 手续费 */
.fee-equation{display:flex;align-items:stretch;justify-content:space-between;gap:8rpx;margin-top:24rpx}.fee-equation>view{display:flex;min-width:0;flex:1;align-items:center;justify-content:center;flex-direction:column;padding:18rpx 4rpx;border-radius:20rpx;background:#F7F9F8;text-align:center}.fee-equation>view>text{font-size:22rpx;color:#767C85;white-space:nowrap}.fee-equation strong{display:block;margin-top:8rpx;font-size:28rpx}.operator{display:flex;align-items:center;color:#AEB3BB;font-size:32rpx;font-weight:700}.dark{color:#191B1F}.orange{color:#F2620A}.green{color:#08A152}.received-cell{background:#E7F9EF!important}.fee-note{display:block;margin-top:20rpx;color:#AEB3BB;font-size:24rpx;line-height:1.5}
/* 信息行 */
.info-row{display:flex;align-items:center;gap:20rpx;padding:24rpx 28rpx}.info-icon{display:flex;align-items:center;justify-content:center;width:64rpx;height:64rpx;flex:none;border-radius:20rpx;background:#E7F9EF;color:#08A152;font-size:32rpx;font-weight:800}.info-icon.account{background:#FFF2E5;color:#F2620A}.info-main{min-width:0;flex:1}.info-title{display:block;font-size:28rpx;font-weight:700}.info-note{display:block;margin-top:6rpx;overflow:hidden;color:#767C85;font-size:24rpx;text-overflow:ellipsis;white-space:nowrap}.info-side{color:#AEB3BB;font-size:24rpx;white-space:nowrap}
/* 协议与按钮 */
.consent{display:flex;align-items:flex-start;gap:8rpx;margin:24rpx 8rpx 0;color:#767C85;font-size:24rpx;line-height:1.5}.consent checkbox{transform:scale(.8);transform-origin:left top}.link{color:#08A152}.withdraw-button{height:88rpx;margin:20rpx 0 0;border:0;border-radius:44rpx;background:#F2F4F3;color:#AEB3BB;font-size:32rpx;font-weight:800;line-height:88rpx}.withdraw-button:not([disabled]){background:linear-gradient(135deg,#12D978,#08A152);box-shadow:0 12rpx 28rpx rgba(11,191,99,.3);color:#fff}.withdraw-button[disabled]{opacity:1}.withdraw-button::after{border:0}.safety-note{margin:20rpx 8rpx 0;color:#AEB3BB;text-align:center;font-size:22rpx}
/* 状态卡 */
.state-card{margin:20rpx 0 0;padding:40rpx;border-radius:32rpx;background:#fff;color:#767C85;text-align:center;font-size:26rpx;box-shadow:0 2rpx 4rpx rgba(23,26,23,.03),0 12rpx 40rpx rgba(23,26,23,.05)}.error-state{color:#FF2442;background:#FFEEEA}.state-card button{display:block;height:56rpx;margin:20rpx auto 0;padding:0 24rpx;border:2rpx solid currentColor;border-radius:28rpx;background:#fff;color:inherit;font-size:24rpx;line-height:52rpx}.state-card button::after{border:0}
/* 错误与记录 */
.action-error{display:flex;align-items:center;justify-content:space-between;gap:14rpx;margin:20rpx 0 0;padding:20rpx;border-radius:24rpx;background:#FFEEEA;color:#FF2442;font-size:24rpx}.action-error text{min-width:0}.action-error button{height:52rpx;flex:none;margin:0;padding:0 20rpx;border:2rpx solid #FF2442;border-radius:16rpx;background:#fff;color:#FF2442;font-size:22rpx;line-height:48rpx}.action-error button::after{border:0}.history-card{padding-bottom:12rpx}.history-row{display:flex;align-items:center;justify-content:space-between;gap:16rpx;padding:20rpx 0;border-bottom:1rpx solid #F5F6F6}.history-row:last-child{border-bottom:0}.history-row>view:last-child{text-align:right}.history-row text,.history-row small{display:block}.history-row text{font-size:26rpx;font-weight:700;color:#191B1F}.history-row small{margin-top:6rpx;color:#AEB3BB;font-size:22rpx}
</style>
