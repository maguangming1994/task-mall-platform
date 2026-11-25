<template>
  <view class="page withdraw-page">
    <ModulePlaceholder v-if="modules.resolved && (!moduleEnabled || modules.reviewing)" code="module_withdraw" />
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
      <text class="amount-range">{{ rangeText }}</text>
    </view>

    <view class="section-card rules-card"><view class="section-head"><text class="section-title">提现规则</text><text class="rule-link" @click="showRules = true">规则说明 ›</text></view><view class="rule-grid"><view class="rule-item"><view class="rule-icon minimum">¥</view><view><text>最低提现（元）</text><strong>{{ noMinimum ? '无门槛' : minimumYuan }}</strong><small>{{ noMinimum ? '不设最低提现金额' : '单次最低提现金额' }}</small></view></view><view class="rule-item"><view class="rule-icon maximum">¥</view><view><text>最高提现（元）</text><strong>{{ maximumYuan }}</strong><small>单次最高提现金额</small></view></view><view class="rule-item"><view class="rule-icon times">次</view><view><text>每日提现次数</text><strong>{{ rules ? rules.daily_count_limit : '—' }}</strong><small>今日剩余 {{ rules ? rules.daily_count_remaining : '—' }} 次</small></view></view><view class="rule-item"><view class="rule-icon limit">额</view><view><text>每日累计上限（元）</text><strong>{{ rules ? money(rules.daily_amount_limit) : '—' }}</strong><small>今日已提 ¥{{ rules ? money(rules.today_amount) : '0.00' }}</small></view></view></view><view v-if="rules && !rules.enabled" class="rule-warning"><text class="warning-icon">!</text><view><text>提现功能当前未开放</text><small>平台暂时关闭了提现，请稍后再试或联系客服</small></view></view><view v-else-if="insufficient" class="rule-warning"><text class="warning-icon">!</text><view><text>可提现余额不足</text><small>余额累计满 ¥{{ minimumYuan }} 后即可发起提现，余额永久有效</small></view></view></view>

    <view class="section-card fee-card"><view class="section-head"><text class="section-title">手续费说明</text><text class="rule-link" @click="showRules = true">如何计算 ?</text></view><view class="fee-equation"><view><text>提现金额</text><strong class="dark">¥{{ quote ? money(quote.requested_amount) : '0.00' }}</strong></view><text class="operator">−</text><view><text>手续费率</text><strong class="dark">{{ feeRateText }}</strong></view><text class="operator">=</text><view><text>手续费</text><strong class="orange">¥{{ quote ? money(quote.fee_amount) : '0.00' }}</strong></view><text class="operator">→</text><view class="received-cell"><text>实际到账</text><strong class="green">¥{{ quote ? money(quote.received_amount) : '0.00' }}</strong></view></view><text class="fee-note">费率 {{ feeRateText }}，手续费从提现金额中扣除；实算不足 0.01 元时免收，以提交时计算为准</text></view>

    <view class="section-card info-row"><view class="info-icon clock">◷</view><view class="info-main"><text class="info-title">到账时间</text><text class="info-note">{{ rules?.arrival_text || '提交后平台审核，工作日到账，周末及节假日顺延' }}</text></view><text class="info-side">1-3 个工作日</text></view>
    <view class="section-card info-row"><view class="info-icon account">审</view><view class="info-main"><text class="info-title">审核与退回</text><text class="info-note">提交即冻结申请金额，平台审核通过后出款；审核驳回或打款失败，金额自动退回余额</text></view><text class="info-side" @click="showRules = true">流程详情 ›</text></view>

    <view class="section-card channel-card">
      <view class="section-head"><text class="section-title">到账方式</text><text class="rule-link" @click="goBankCards">银行卡管理 ›</text></view>
      <view class="channel-option" :class="{ on: channel === 'wechat_transfer' }" @click="channel = 'wechat_transfer'">
        <view class="channel-ic wechat">微</view>
        <view class="channel-main"><text class="channel-name">微信零钱</text><text class="channel-note">审核通过后转账至微信支付实名账户</text></view>
        <view class="channel-radio" :class="{ on: channel === 'wechat_transfer' }" />
      </view>
      <view class="channel-option" :class="{ on: channel === 'bank_card' }" @click="chooseBankChannel">
        <view class="channel-ic bank">卡</view>
        <view class="channel-main">
          <text class="channel-name">银行卡</text>
          <text class="channel-note">{{ selectedCard ? `${selectedCard.bank_name} ${selectedCard.card_masked}` : '绑定本人储蓄卡，财务审核后人工转账' }}</text>
        </view>
        <view v-if="cards.length" class="channel-radio" :class="{ on: channel === 'bank_card' }" />
        <text v-else class="channel-bind">去绑定 ›</text>
      </view>
    </view>

    <label class="consent"><checkbox value="withdraw-consent" :checked="withdrawAgreed" color="#0BBF63" @change="onConsentChange" /><text>我已阅读并同意 <text class="link">《用户协议》</text> 和 <text class="link">《隐私政策》</text></text></label>
    <button class="withdraw-button" :disabled="!withdrawAgreed || withdrawing || (rules !== null && !rules.enabled)" :loading="withdrawing" @click="handleWithdraw">{{ rules && !rules.enabled ? '提现暂未开放' : (quote ? '确认提现' : '提现') }}</button>
    <view class="safety-note">平台保障资金安全，禁止任何形式的线下交易或提现代办行为</view>

    <view v-if="actionError" class="action-error" role="alert"><text>{{ actionError }}</text><button @click="actionError = ''">知道了</button></view>
    <view v-if="withdrawals.length" class="history-card section-card"><view class="section-head"><text class="section-title">提现记录</text><text class="rule-link">最近记录</text></view><view v-for="item in withdrawals" :key="item.id" class="history-row"><view><text>{{ item.withdrawal_no }}</text><small>{{ formatDate(item.created_at) }} · {{ item.channel_label || '微信零钱' }} · {{ item.workflow?.next_action || '请稍后刷新处理状态' }}</small></view><view><text>¥{{ money(item.requested_amount) }}</text><small>{{ item.workflow?.status_label || statusText(item.status) }}</small></view></view></view>

    <view v-if="showRules" class="rules-mask" @click="showRules = false">
      <view class="rules-sheet" @click.stop>
        <view class="sheet-head"><text class="sheet-title">提现规则说明</text><text class="sheet-close" @click="showRules = false">×</text></view>
        <scroll-view scroll-y class="sheet-body">
          <view class="rule-block"><text class="block-title">1. 提现金额</text><text class="block-text">{{ noMinimum ? `不设单笔最低提现门槛，0.01 元起即可发起提现，单笔最高 ${maximumYuan} 元。` : `单笔最低提现 ${minimumYuan} 元，最高 ${maximumYuan} 元；输入金额需在此范围内。` }}</text></view>
          <view class="rule-block"><text class="block-title">2. 每日限额</text><text class="block-text">每日最多可提现 {{ rules?.daily_count_limit ?? '—' }} 次，累计金额不超过 {{ rules ? money(rules.daily_amount_limit) : '—' }} 元；次数与额度按自然日 0:00 重置，审核驳回或失败的提现不占用限额。</text></view>
          <view class="rule-block"><text class="block-title">3. 手续费</text><text class="block-text">手续费率 {{ feeRateText }}，手续费 = 提现金额 × 费率并向上取整到 0.01 元，从提现金额中扣除；当应计手续费达到或超过提现金额时（如 0.01 元的极小额提现）免收手续费。实际到账 = 提现金额 − 手续费，以提交时试算结果为准。</text></view>
          <view class="rule-block"><text class="block-title">4. 提现流程</text><text class="block-text">提交申请后，申请金额将立即从余额冻结；平台对提现资格与金额进行人工审核，审核通过后按所选方式出款。处理中的金额可在页面顶部「冻结额」查看。</text></view>
          <view class="rule-block"><text class="block-title">5. 到账时间</text><text class="block-text">{{ rules?.arrival_text || '提交后平台审核，预计 1-3 个工作日到账，周末及节假日顺延。' }}</text></view>
          <view class="rule-block"><text class="block-title">6. 到账方式</text><text class="block-text">微信零钱：审核通过后转账至微信支付实名账户；银行卡：审核通过后由财务在工作日人工转账至本人储蓄卡，可在「银行卡管理」中维护收款卡。</text></view>
          <view class="rule-block"><text class="block-title">7. 失败与退回</text><text class="block-text">审核未通过或出款失败时，冻结的申请金额将自动退回可提现余额，不收取任何费用，可按规则重新发起提现。</text></view>
          <view class="rule-block"><text class="block-title">8. 余额来源</text><text class="block-text">任务佣金、交易收入等在确认收货并完成结算后计入可提现余额，余额永久有效；平台不收取提现手续费之外的任何费用。</text></view>
          <view class="rule-block"><text class="block-title">9. 安全提示</text><text class="block-text">请使用本人实名账户收款，禁止任何形式的线下交易或提现代办行为；如有疑问请联系平台客服。</text></view>
        </scroll-view>
        <button class="sheet-confirm" @click="showRules = false">我已知晓</button>
      </view>
    </view>
    </template>
  </view>
</template>

<script setup lang="ts">
import { formatDateTime as formatDate } from '@/utils/dateTime'
import { onShow } from '@dcloudio/uni-app'
import { computed, ref } from 'vue'
import { createWithdrawal, getPointAccount, getWalletAccount, getWithdrawalQuote, getWithdrawalRules, listWithdrawals, type PointAccount, type WalletAccount, type Withdrawal, type WithdrawalChannel, type WithdrawalQuote, type WithdrawalRules } from '@/api/assets'
import { listBankCards, type BankCard } from '@/api/bank-cards'
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
const rules = ref<WithdrawalRules | null>(null)
const showRules = ref(false)
const withdrawAgreed = ref(false)
const loading = ref(false)
const withdrawing = ref(false)
const loadError = ref('')
const actionError = ref('')
const channel = ref<WithdrawalChannel>('wechat_transfer')
const cards = ref<BankCard[]>([])
const selectedCardId = ref('')
const selectedCard = computed(() => cards.value.find((c) => c.id === selectedCardId.value) || null)
const fallbackMinimum = 0
const fallbackMaximum = 500000
const minimumCents = computed(() => rules.value?.minimum_amount ?? quote.value?.minimum_amount ?? fallbackMinimum)
const maximumCents = computed(() => rules.value?.maximum_amount ?? quote.value?.maximum_amount ?? fallbackMaximum)
const noMinimum = computed(() => minimumCents.value <= 0)
const minimumYuan = computed(() => money(minimumCents.value))
const maximumYuan = computed(() => money(maximumCents.value))
const rangeText = computed(() => noMinimum.value ? `无最低提现门槛，单笔最多可提现 ${maximumYuan.value} 元` : `可提现范围：${minimumYuan.value} ~ ${maximumYuan.value} 元`)
const expiringPoints = computed(() => points.value?.expiring_points ?? '—')
const insufficient = computed(() => Boolean(wallet.value && wallet.value.withdrawable_amount < minimumCents.value))
const feeRatePpm = computed(() => quote.value?.fee_rate ?? rules.value?.fee_rate ?? null)
const feeRateText = computed(() => feeRatePpm.value === null ? '—' : `${(feeRatePpm.value / 10000).toFixed(2)}%`)

onShow(load)

async function load() {
  await modules.ensureReady()
  if (!moduleEnabled.value || modules.reviewing) return
  if (!auth.isAuthenticated) return navigateToLogin()
  loading.value = true
  loadError.value = ''
  try {
    const [account, pointAccount, list, cardRes, ruleRes] = await Promise.all([
      getWalletAccount(auth.accessToken),
      getPointAccount(auth.accessToken),
      listWithdrawals(auth.accessToken),
      listBankCards(auth.accessToken).catch(() => ({ items: [] as BankCard[] })),
      getWithdrawalRules(auth.accessToken).catch(() => null),
    ])
    wallet.value = account
    points.value = pointAccount
    withdrawals.value = list.items
    cards.value = cardRes.items
    rules.value = ruleRes
    if (!selectedCardId.value || !cards.value.some((c) => c.id === selectedCardId.value)) {
      selectedCardId.value = cards.value.find((c) => c.is_default)?.id || cards.value[0]?.id || ''
    }
    if (channel.value === 'bank_card' && !selectedCardId.value) channel.value = 'wechat_transfer'
  } catch (error) {
    loadError.value = errorMessage(error, '提现信息加载失败，请稍后重试')
    uni.showToast({ title: loadError.value, icon: 'none' })
  } finally {
    loading.value = false
  }
}

function goBack() { const pages = getCurrentPages(); if (pages.length > 1) uni.navigateBack({ delta: 1 }); else uni.redirectTo({ url: '/pages/assets/index' }) }
function goBankCards() { uni.navigateTo({ url: '/pages/bank-cards/index' }) }

async function chooseBankChannel() {
  if (!cards.value.length) {
    const r = await uni.showModal({ title: '绑定银行卡', content: '提现到银行卡需先绑定本人储蓄卡，信息须与实名认证一致。', confirmText: '去绑定', cancelText: '取消' })
    if (r.confirm) goBankCards()
    return
  }
  if (cards.value.length === 1) {
    channel.value = 'bank_card'
    selectedCardId.value = cards.value[0].id
    return
  }
  try {
    const labels = cards.value.map((c) => `${c.bank_name} ${c.card_masked}${c.is_default ? '（默认）' : ''}`)
    labels.push('银行卡管理')
    const r = await uni.showActionSheet({ itemList: labels })
    if (r.tapIndex === cards.value.length) { goBankCards(); return }
    channel.value = 'bank_card'
    selectedCardId.value = cards.value[r.tapIndex].id
  } catch { /* 用户取消 */ }
}
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
  if (channel.value === 'bank_card' && !selectedCard.value) {
    actionError.value = '请先选择或绑定提现银行卡'
    return uni.showToast({ title: actionError.value, icon: 'none' })
  }
  const destination = channel.value === 'bank_card' && selectedCard.value
    ? `${selectedCard.value.bank_name} ${selectedCard.value.card_masked}`
    : '微信零钱'
  const confirmed = await uni.showModal({ title: '确认提现', content: `到账方式：${destination}\n手续费 ¥${money(quote.value.fee_amount)}，预计到账 ¥${money(quote.value.received_amount)}。提交后将冻结申请金额。` })
  if (!confirmed.confirm) return
  withdrawing.value = true
  try {
    await createWithdrawal(auth.accessToken, cents, channel.value, selectedCardId.value || undefined)
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
/* 到账方式 */
.channel-card{padding-bottom:8rpx}
.channel-option{display:flex;align-items:center;gap:20rpx;margin-top:20rpx;padding:24rpx;border:2rpx solid #EDF0ED;border-radius:24rpx}
.channel-option.on{border-color:#0BBF63;background:#F3FCF7}
.channel-ic{display:flex;align-items:center;justify-content:center;width:64rpx;height:64rpx;flex:none;border-radius:20rpx;font-size:26rpx;font-weight:800;color:#fff}
.channel-ic.wechat{background:#0BBF63}
.channel-ic.bank{background:#F2620A}
.channel-main{min-width:0;flex:1}
.channel-name{display:block;font-size:28rpx;font-weight:700;color:#191B1F}
.channel-note{display:block;margin-top:6rpx;overflow:hidden;color:#767C85;font-size:22rpx;text-overflow:ellipsis;white-space:nowrap}
.channel-radio{width:34rpx;height:34rpx;flex:none;border:3rpx solid #C9CEC9;border-radius:50%;box-sizing:border-box}
.channel-radio.on{border:10rpx solid #0BBF63}
.channel-bind{flex:none;color:#08A152;font-size:24rpx;font-weight:700}
/* 协议与按钮 */
.consent{display:flex;align-items:flex-start;gap:8rpx;margin:24rpx 8rpx 0;color:#767C85;font-size:24rpx;line-height:1.5}.consent checkbox{transform:scale(.8);transform-origin:left top}.link{color:#08A152}.withdraw-button{height:88rpx;margin:20rpx 0 0;border:0;border-radius:44rpx;background:#F2F4F3;color:#AEB3BB;font-size:32rpx;font-weight:800;line-height:88rpx}.withdraw-button:not([disabled]){background:linear-gradient(135deg,#12D978,#08A152);box-shadow:0 12rpx 28rpx rgba(11,191,99,.3);color:#fff}.withdraw-button[disabled]{opacity:1}.withdraw-button::after{border:0}.safety-note{margin:20rpx 8rpx 0;color:#AEB3BB;text-align:center;font-size:22rpx}
/* 状态卡 */
.state-card{margin:20rpx 0 0;padding:40rpx;border-radius:32rpx;background:#fff;color:#767C85;text-align:center;font-size:26rpx;box-shadow:0 2rpx 4rpx rgba(23,26,23,.03),0 12rpx 40rpx rgba(23,26,23,.05)}.error-state{color:#FF2442;background:#FFEEEA}.state-card button{display:block;height:56rpx;margin:20rpx auto 0;padding:0 24rpx;border:2rpx solid currentColor;border-radius:28rpx;background:#fff;color:inherit;font-size:24rpx;line-height:52rpx}.state-card button::after{border:0}
/* 错误与记录 */
.action-error{display:flex;align-items:center;justify-content:space-between;gap:14rpx;margin:20rpx 0 0;padding:20rpx;border-radius:24rpx;background:#FFEEEA;color:#FF2442;font-size:24rpx}.action-error text{min-width:0}.action-error button{height:52rpx;flex:none;margin:0;padding:0 20rpx;border:2rpx solid #FF2442;border-radius:16rpx;background:#fff;color:#FF2442;font-size:22rpx;line-height:48rpx}.action-error button::after{border:0}.history-card{padding-bottom:12rpx}.history-row{display:flex;align-items:center;justify-content:space-between;gap:16rpx;padding:20rpx 0;border-bottom:1rpx solid #F5F6F6}.history-row:last-child{border-bottom:0}.history-row>view:last-child{text-align:right}.history-row text,.history-row small{display:block}.history-row text{font-size:26rpx;font-weight:700;color:#191B1F}.history-row small{margin-top:6rpx;color:#AEB3BB;font-size:22rpx}
/* 规则图标补充 */
.rule-icon.times{background:#EAF2FF;color:#2B6FE6}.rule-icon.limit{background:#F3EEFF;color:#7A4DE8}
/* 规则说明弹层 */
.rules-mask{position:fixed;z-index:999;inset:0;display:flex;align-items:flex-end;justify-content:center;background:rgba(20,24,20,.5)}.rules-sheet{display:flex;max-height:82vh;width:100%;flex-direction:column;border-radius:32rpx 32rpx 0 0;background:#fff;padding:32rpx 28rpx calc(40rpx + env(safe-area-inset-bottom))}.sheet-head{display:flex;align-items:center;justify-content:space-between;padding-bottom:20rpx;border-bottom:1rpx solid #F0F2F0}.sheet-title{font-size:32rpx;font-weight:800;color:#191B1F}.sheet-close{width:56rpx;height:56rpx;border-radius:50%;background:#F2F4F3;color:#767C85;font-size:40rpx;line-height:52rpx;text-align:center}.sheet-body{max-height:60vh;padding:8rpx 4rpx}.rule-block{padding:20rpx 4rpx;border-bottom:1rpx solid #F5F6F6}.rule-block:last-child{border-bottom:0}.block-title,.block-text{display:block}.block-title{font-size:27rpx;font-weight:700;color:#191B1F}.block-text{margin-top:10rpx;color:#5C625C;font-size:25rpx;line-height:1.65}.sheet-confirm{height:88rpx;margin-top:24rpx;border:0;border-radius:44rpx;background:linear-gradient(135deg,#12D978,#08A152);color:#fff;font-size:30rpx;font-weight:800;line-height:88rpx}.sheet-confirm::after{border:0}
</style>
