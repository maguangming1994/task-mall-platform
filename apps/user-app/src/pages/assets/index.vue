<template>
  <view class="page asset-page">
    <view class="asset-hero">
      <view class="hero-copy">
        <text class="hero-kicker">ASSET LEDGER</text>
        <text class="hero-title">我的资产</text>
        <text class="hero-subtitle">积分、余额和优惠券独立记账，<br />每一笔来源、有效期与退款变动都可追溯</text>
      </view>
    </view>

    <view v-if="loading && !points && !wallet" class="module-state" role="status" aria-live="polite"><text>正在加载资产信息…</text></view>

    <view class="overview-grid">
      <view class="wallet-card">
        <view class="wallet-top"><view class="asset-icon wallet-icon">¥</view><text class="wallet-label">校园钱包</text></view>
        <text class="wallet-value">{{ wallet ? `¥${money(wallet.available_amount)}` : '—' }}</text>
        <text class="wallet-note">{{ wallet ? '可用余额 · 充值后可用' : '余额信息暂不可用' }}</text>
        <view class="wallet-actions"><button class="recharge-button" aria-label="余额充值" @click="openRecharge">余额充值</button><button class="ledger-button" aria-label="查看余额明细" @click="scrollToWallet">查看明细</button></view>
      </view>
      <view class="mini-assets">
        <view class="mini-asset points-card"><view class="asset-icon points-icon">★</view><view><text class="mini-label">积分</text><text class="mini-value">{{ points ? points.available_points : '—' }}</text><text class="mini-note">可用积分</text></view></view>
        <view class="mini-asset coupon-card"><view class="asset-icon coupon-icon">券</view><view><text class="mini-label">优惠券</text><text class="mini-value">{{ couponsError ? '—' : coupons.length }}</text><text class="mini-note">可用优惠券</text></view></view>
      </view>
    </view>

    <view class="asset-status">
      <view><text class="status-label">即将到期积分</text><text class="status-value">{{ points ? points.expiring_points : '—' }}</text><text class="status-note">{{ points?.next_expiration_at ? `${formatDate(points.next_expiration_at)} 到期` : (pointsError ? '加载失败' : '暂无到期批次') }}</text></view>
      <view><text class="status-label">冻结余额</text><text class="status-value">{{ wallet ? `¥${money(wallet.frozen_amount)}` : '—' }}</text><text class="status-note">{{ walletError ? '加载失败' : '处理中暂不可用' }}</text></view>
      <view><text class="status-label">可提现余额</text><text class="status-value">{{ wallet ? `¥${money(wallet.withdrawable_amount)}` : '—' }}</text><text class="status-note">{{ walletError ? '加载失败' : '以提交时试算为准' }}</text></view>
    </view>

    <view v-if="pointsError" class="module-error"><text>积分资产加载失败：{{ pointsError }}</text><button @click="load">重试</button></view>
    <view v-if="walletError" class="module-error"><text>钱包流水加载失败：{{ walletError }}</text><button @click="load">重试</button></view>
    <view v-if="couponsError" class="module-error"><text>优惠券加载失败：{{ couponsError }}</text><button @click="load">重试</button></view>
    <view v-if="points?.debt_points" class="warning">存在 {{ points.debt_points }} 积分欠额，新获得积分将优先偿还，清零前不能消费积分。</view>

    <view class="section-card expiry-card">
      <view class="section-head"><view><text class="section-title">积分到期计划</text><text class="section-subtitle">按来源与有效期跟踪使用，临近到期批次优先抵扣</text></view><view class="expiry-summary"><text class="expiry-number">{{ points ? points.expiring_7_days : '—' }}</text><text>7 天内到期</text></view></view>
      <view class="expiry-window"><view><text>30 天内到期</text><text class="window-value">{{ points ? points.expiring_30_days : '—' }}<text v-if="points"> 积分</text></text></view><view><text>最近到期日</text><text class="window-value">{{ points?.next_expiration_at ? formatDate(points.next_expiration_at) : (pointsError ? '加载失败' : '暂无') }}</text></view></view>
      <view v-for="batch in pointBatches" :key="batch.id" class="expiry-row"><view class="expiry-source"><text class="source-icon">{{ sourceText(batch.source_code).slice(0, 1) }}</text><view><text class="row-title">{{ sourceText(batch.source_code) }}</text><text class="row-note">获得 {{ batch.issued_points }} · 剩余 {{ batch.remaining_points }}</text></view></view><view class="expiry-date"><text :class="{ urgent: batch.days_remaining !== null && batch.days_remaining <= 7 }">{{ batch.days_remaining === null ? '长期有效' : `${batch.days_remaining} 天后到期` }}</text><text>{{ batch.expires_at ? formatDate(batch.expires_at) : '无到期日' }}</text></view></view>
      <text v-if="!pointsError && pointBatches.length === 0" class="empty">当前没有待到期的积分批次</text>
    </view>

    <view class="section-card ledger-card"><view class="section-head"><text class="section-title">积分明细</text><text class="section-action">来源与到账时间</text></view><view v-for="item in pointTransactions" :key="item.id" class="ledger-row"><view><text class="row-title">{{ pointTypeText(item.type) }} · {{ sourceText(item.source_code) }}</text><text class="row-note">{{ formatTime(item.created_at) }}</text></view><text :class="item.change_points >= 0 ? 'plus' : 'minus'">{{ signed(item.change_points) }}</text></view><text v-if="!pointsError && pointTransactions.length === 0" class="empty">暂无积分流水</text></view>

    <view id="wallet-ledger" class="section-card ledger-card"><view class="section-head"><text class="section-title">余额明细</text><text class="section-action">余额无有效期</text></view><view v-for="item in walletTransactions" :key="item.id" class="ledger-row"><view><text class="row-title">{{ walletTypeText(item.type) }}</text><text class="row-note">{{ formatTime(item.created_at) }} · {{ item.remark }}</text></view><text :class="item.change_amount >= 0 ? 'plus' : 'minus'">{{ signedMoney(item.change_amount) }}</text></view><text v-if="!walletError && walletTransactions.length === 0" class="empty">暂无余额流水或尚未同意钱包规则</text></view>

    <view class="section-card withdrawal-card"><view class="section-head"><text class="section-title">余额提现</text><text class="section-action">手续费以实时试算为准</text></view><text class="withdraw-tip">提现金额提交后将冻结，审核通过后按后台当前费率扣除手续费并打款。</text><input class="input" type="digit" placeholder="输入提现金额（元）" disabled @click="openWithdraw" /><button class="primary" aria-label="打开提现页面" @click="openWithdraw">计算手续费</button><view v-for="item in withdrawals" :key="item.id" class="withdrawal-row"><view><text class="row-title">{{ item.withdrawal_no }}</text><text class="row-note">{{ formatTime(item.created_at) }} · {{ item.workflow?.next_action || '请稍后刷新提现处理状态。' }}</text></view><view class="withdrawal-amount"><text>¥{{ money(item.requested_amount) }}</text><text>{{ item.workflow?.status_label || withdrawalStatusText(item.status) }}</text></view></view><text v-if="!walletError && !loading && withdrawals.length === 0" class="empty withdrawal-empty">暂无提现记录</text></view>

    <view class="section-card coupon-section"><view class="section-head"><text class="section-title">优惠券</text><text class="section-action">可用优惠券</text></view><view v-for="item in coupons" :key="item.id" class="coupon-row"><view><text class="row-title">{{ item.name }}</text><text class="row-note">有效至 {{ formatTime(item.valid_to) }}</text></view><view class="discount"><text v-if="item.cash_discount_amount">减 ¥{{ money(item.cash_discount_amount) }}</text><text v-if="item.points_discount">减 {{ item.points_discount }} 积分</text></view></view><text v-if="!couponsError && coupons.length === 0" class="empty">暂无可用优惠券</text></view>

    <AppTabBar current-target="account" />
  </view>
</template>

<script setup lang="ts">
import { onShow } from '@dcloudio/uni-app'
import { ref } from 'vue'
import { getPointAccount, getWalletAccount, listCoupons, listPointBatches, listPointTransactions, listWalletTransactions, listWithdrawals, type PointAccount, type PointBatch, type PointTransaction, type UserCoupon, type WalletAccount, type WalletTransaction, type Withdrawal } from '@/api/assets'
import AppTabBar from '@/components/AppTabBar.vue'
import { useAuthStore } from '@/stores/auth'
import { navigateToLogin } from '@/utils/authRedirect'

const auth = useAuthStore()
const points = ref<PointAccount | null>(null)
const wallet = ref<WalletAccount | null>(null)
const coupons = ref<UserCoupon[]>([])
const pointTransactions = ref<PointTransaction[]>([])
const pointBatches = ref<PointBatch[]>([])
const walletTransactions = ref<WalletTransaction[]>([])
const withdrawals = ref<Withdrawal[]>([])
const loading = ref(false)
const pointsError = ref('')
const walletError = ref('')
const couponsError = ref('')

onShow(load)

async function load() {
  if (!auth.accessToken) return navigateToLogin()
  loading.value = true
  pointsError.value = ''
  walletError.value = ''
  couponsError.value = ''
  try {
    const pointResults = await Promise.allSettled([getPointAccount(auth.accessToken), listPointTransactions(auth.accessToken), listPointBatches(auth.accessToken)])
    const [pointAccount, pointLedger, pointExpiry] = pointResults
    if (pointAccount.status === 'fulfilled') points.value = pointAccount.value
    else { points.value = null; pointsError.value = errorMessage(pointAccount.reason, '积分账户加载失败') }
    if (pointLedger.status === 'fulfilled') pointTransactions.value = pointLedger.value.items
    else { pointTransactions.value = []; pointsError.value ||= errorMessage(pointLedger.reason, '积分流水加载失败') }
    if (pointExpiry.status === 'fulfilled') pointBatches.value = pointExpiry.value.items
    else { pointBatches.value = []; pointsError.value ||= errorMessage(pointExpiry.reason, '积分到期计划加载失败') }

    const walletResults = await Promise.allSettled([getWalletAccount(auth.accessToken), listWalletTransactions(auth.accessToken), listWithdrawals(auth.accessToken)])
    const [walletAccount, walletLedger, walletList] = walletResults
    if (walletAccount.status === 'fulfilled') wallet.value = walletAccount.value
    else { wallet.value = null; walletError.value = errorMessage(walletAccount.reason, '钱包账户加载失败') }
    if (walletLedger.status === 'fulfilled') walletTransactions.value = walletLedger.value.items
    else { walletTransactions.value = []; walletError.value ||= errorMessage(walletLedger.reason, '余额流水加载失败') }
    if (walletList.status === 'fulfilled') withdrawals.value = walletList.value.items
    else { withdrawals.value = []; walletError.value ||= errorMessage(walletList.reason, '提现记录加载失败') }

    try { coupons.value = await listCoupons(auth.accessToken) } catch (error) { coupons.value = []; couponsError.value = errorMessage(error, '优惠券加载失败') }
  } finally {
    loading.value = false
  }
}

function errorMessage(error: unknown, fallback: string) { return error instanceof Error ? error.message : fallback }
function money(cents: number) { return (cents / 100).toFixed(2) }
function signed(value: number) { return `${value >= 0 ? '+' : ''}${value}` }
function signedMoney(value: number) { return `${value >= 0 ? '+' : '-'}¥${money(Math.abs(value))}` }
function formatTime(value: string) { const date = new Date(value); return Number.isNaN(date.getTime()) ? value : date.toLocaleString() }
function formatDate(value: string) { const date = new Date(value); return Number.isNaN(date.getTime()) ? value : date.toLocaleDateString() }
function pointTypeText(value: string) { return ({ grant: '获得积分', consume: '使用积分', expire: '积分到期', refund: '退款返还', adjust: '后台调整', reverse: '业务冲正' } as Record<string, string>)[value] || '积分变动' }
function walletTypeText(value: string) { return ({ grant: '余额奖励', consume: '余额支付', refund: '退款返还', withdrawal: '余额提现', adjust: '后台调整', reverse: '业务冲正', recharge_card: '卡密充值', recharge_online: '线上充值', recharge_offline: '线下充值' } as Record<string, string>)[value] || '余额变动' }
function sourceText(value?: string | null) { return ({ signin: '签到', daily_check_in: '每日签到', post: '发布帖子', comment: '发表评论', profile: '完善资料', referral: '推荐新人', referral_new_user: '推荐新人', task: '完成任务', manual: '平台发放' } as Record<string, string>)[value || ''] || '其他来源' }
function withdrawalStatusText(value: string) { return ({ reviewing: '审核中', submitted: '待审核', approved: '待打款', processing: '处理中', succeeded: '已到账', completed: '已完成', rejected: '已拒绝', failed: '打款失败', cancelled: '已取消' } as Record<string, string>)[value] || '提现状态同步中' }
function openRecharge() { uni.navigateTo({ url: '/pages/wallet-recharge/index' }) }
function openWithdraw() { uni.navigateTo({ url: '/pages/withdraw/index' }) }
function scrollToWallet() { uni.pageScrollTo({ selector: '#wallet-ledger', duration: 300 }) }
</script>

<style scoped>
.page{min-height:100vh;box-sizing:border-box;padding:0 28rpx 96rpx;background:linear-gradient(180deg,#f2fbff 0,#f9fcfd 720rpx,#f6f9fc 100%);color:#10213a}.asset-hero{position:relative;min-height:278rpx;margin:0 -28rpx 0;padding:50rpx 46rpx 30rpx;box-sizing:border-box;overflow:hidden;background:#e7f8ff url('/static/home/hero-campus-reward.png') no-repeat right bottom;background-size:auto 330rpx}.asset-hero::after{content:'';position:absolute;inset:auto 0 0;height:110rpx;background:linear-gradient(180deg,transparent,rgba(246,251,253,.98))}.hero-copy{position:relative;z-index:1;width:65%}.hero-kicker{display:block;color:#2b72d5;font-size:18rpx;font-weight:850;letter-spacing:3rpx}.hero-title{display:block;margin-top:12rpx;color:#102f5a;font-size:46rpx;font-weight:900;line-height:1.2}.hero-subtitle{display:block;margin-top:14rpx;color:#57749f;font-size:22rpx;line-height:1.65}.module-state{margin-top:18rpx;padding:26rpx;border:1rpx solid #dce9ef;border-radius:22rpx;background:#fff;color:#748099;text-align:center;font-size:21rpx}.overview-grid{position:relative;z-index:2;display:grid;grid-template-columns:1.45fr .85fr;gap:14rpx;margin-top:-22rpx}.wallet-card{display:flex;min-height:242rpx;box-sizing:border-box;flex-direction:column;justify-content:space-between;padding:26rpx;border-radius:28rpx;background:linear-gradient(135deg,#237be2,#25bdc1);box-shadow:0 16rpx 34rpx rgba(32,120,188,.18);color:#fff}.wallet-top{display:flex;align-items:center;gap:14rpx}.asset-icon{display:flex;align-items:center;justify-content:center;width:62rpx;height:62rpx;flex:none;border-radius:20rpx;font-weight:900}.wallet-icon{background:rgba(255,255,255,.9);color:#2f83e6;font-size:30rpx}.wallet-label{font-size:23rpx;font-weight:800}.wallet-value{display:block;margin-top:10rpx;font-size:45rpx;font-weight:900;letter-spacing:.5rpx}.wallet-note{display:block;margin-top:3rpx;color:rgba(255,255,255,.82);font-size:19rpx}.wallet-actions{display:grid;grid-template-columns:1.15fr 1fr;gap:10rpx;margin-top:16rpx}.wallet-actions button{height:58rpx;margin:0;border:0;border-radius:999rpx;font-size:19rpx;font-weight:850;line-height:58rpx}.recharge-button{background:#fff;color:#1674dd}.ledger-button{background:rgba(255,255,255,.95);color:#1674dd}.mini-assets{display:grid;grid-template-rows:1fr 1fr;gap:12rpx}.mini-asset{display:flex;align-items:center;gap:12rpx;padding:18rpx;border:1rpx solid #dfe9f2;border-radius:24rpx;background:rgba(255,255,255,.96);box-shadow:0 8rpx 20rpx rgba(31,56,92,.05)}.points-icon{background:#e8f4ff;color:#2d86e5;font-size:27rpx}.coupon-icon{background:#e8f1ff;color:#3488e7;font-size:22rpx}.mini-label,.mini-value,.mini-note{display:block}.mini-label{font-size:20rpx;font-weight:700}.mini-value{margin-top:3rpx;color:#10213a;font-size:30rpx;font-weight:900}.mini-note{margin-top:2rpx;color:#748099;font-size:17rpx}.asset-status{display:grid;grid-template-columns:repeat(3,1fr);gap:0;margin-top:16rpx;padding:20rpx 12rpx;border:1rpx solid #e2e9f0;border-radius:23rpx;background:#fff;box-shadow:0 8rpx 20rpx rgba(31,56,92,.05)}.asset-status>view{min-width:0;padding:0 12rpx;border-right:1rpx solid #e2e9f0}.asset-status>view:last-child{border-right:0}.status-label,.status-value,.status-note{display:block}.status-label{color:#748099;font-size:18rpx;white-space:nowrap}.status-value{margin-top:8rpx;color:#10213a;font-size:26rpx;font-weight:900}.status-note{margin-top:4rpx;overflow:hidden;color:#748099;font-size:16rpx;line-height:1.35;text-overflow:ellipsis;white-space:nowrap}.module-error{display:flex;align-items:center;justify-content:space-between;gap:14rpx;margin-top:16rpx;padding:16rpx 18rpx;border:1rpx solid #efb4a8;border-radius:16rpx;background:#fff5f2;color:#b42318;font-size:19rpx}.module-error text{min-width:0;line-height:1.45}.module-error button{height:50rpx;flex:none;margin:0;padding:0 14rpx;border:1rpx solid #b42318;border-radius:11rpx;background:#fff;color:#b42318;font-size:18rpx;line-height:48rpx}.warning{margin-top:18rpx;padding:19rpx;border-radius:17rpx;background:#fff5e7;color:#a55d00;font-size:20rpx;line-height:1.5}.section-card{margin-top:22rpx;padding:27rpx;border:1rpx solid #e2e9f0;border-radius:27rpx;background:rgba(255,255,255,.98);box-shadow:0 10rpx 28rpx rgba(31,56,92,.06)}.section-head{display:flex;align-items:flex-start;justify-content:space-between;gap:12rpx}.section-title{display:block;color:#10213a;font-size:29rpx;font-weight:900}.section-subtitle{display:block;margin-top:8rpx;color:#748099;font-size:19rpx;line-height:1.45}.section-action{color:#6b83a6;font-size:18rpx;white-space:nowrap}.expiry-summary{text-align:right}.expiry-number{display:block;color:#f06622;font-size:33rpx;font-weight:900}.expiry-summary>text:last-child{display:block;color:#2f67df;font-size:17rpx}.expiry-window{display:grid;grid-template-columns:1fr 1fr;gap:12rpx;margin-top:20rpx}.expiry-window>view{padding:18rpx;border-radius:18rpx;background:linear-gradient(135deg,#edf9f7,#f1fbfc)}.expiry-window>view>text:first-child{display:block;color:#748099;font-size:18rpx}.window-value{display:block;margin-top:8rpx;color:#10213a;font-size:23rpx;font-weight:850}.expiry-row,.ledger-row,.coupon-row,.withdrawal-row{display:flex;align-items:center;justify-content:space-between;gap:14rpx;padding:20rpx 0;border-bottom:1rpx solid #e8eef3}.expiry-source{display:flex;align-items:center;min-width:0;gap:12rpx}.source-icon{display:flex;align-items:center;justify-content:center;width:48rpx;height:48rpx;flex:none;border-radius:16rpx;background:#e7f7f2;color:#10876a;font-size:19rpx;font-weight:900}.row-title,.row-note{display:block}.row-title{color:#1a2d47;font-size:20rpx;font-weight:750}.row-note{margin-top:6rpx;color:#7a8aa0;font-size:17rpx;line-height:1.4}.expiry-date{flex:none;text-align:right}.expiry-date>text{display:block;color:#748099;font-size:17rpx}.expiry-date>text:first-child{font-weight:750;color:#49617d}.expiry-date .urgent{color:#ef641c}.empty{display:block;padding:28rpx 0;color:#748099;text-align:center;font-size:19rpx}.ledger-card .section-head{align-items:center}.ledger-row:last-of-type,.coupon-row:last-of-type{border-bottom:0}.plus{color:#0f8a61;font-size:22rpx;font-weight:850}.minus{color:#d14343;font-size:22rpx;font-weight:850}.withdrawal-card{background:linear-gradient(145deg,#fff,#f3fbfa)}.withdraw-tip{display:block;margin-top:12rpx;color:#748099;font-size:19rpx;line-height:1.55}.input{width:100%;height:72rpx;margin-top:18rpx;padding:0 20rpx;box-sizing:border-box;border:1rpx solid #ccd9e7;border-radius:17rpx;background:#fbfdff;color:#748099;font-size:21rpx}.input[disabled]{opacity:1}.primary{width:100%;height:72rpx;margin-top:14rpx;border:0;border-radius:999rpx;background:linear-gradient(135deg,#247fe4,#23bcb9);box-shadow:0 10rpx 20rpx rgba(32,119,188,.15);color:#fff;font-size:24rpx;font-weight:850;line-height:72rpx}.withdrawal-row{align-items:flex-start}.withdrawal-row>view:first-child{min-width:0;flex:1}.withdrawal-amount{flex:none;text-align:right;color:#2f67df;font-size:18rpx}.withdrawal-amount text{display:block}.withdrawal-empty{padding-bottom:5rpx}.discount{color:#0f8a61;text-align:right;font-size:18rpx}.discount text{display:block;margin-top:4rpx}.coupon-section{padding-bottom:21rpx}.coupon-row:last-of-type{border-bottom:0}@media screen and (max-width:360px){.page{padding-left:20rpx;padding-right:20rpx}.asset-hero{margin-left:-20rpx;margin-right:-20rpx;padding-left:32rpx;padding-right:32rpx}.overview-grid{grid-template-columns:1fr}.mini-assets{grid-template-columns:1fr 1fr;grid-template-rows:auto}.asset-status{grid-template-columns:1fr}.asset-status>view{padding:12rpx 0;border-right:0;border-bottom:1rpx solid #e2e9f0}.asset-status>view:last-child{border-bottom:0}.status-note{white-space:normal}}
</style>
