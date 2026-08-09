<template>
  <view class="page">
    <view class="heading"><text class="title">我的资产</text><text class="subtitle">每一笔积分、余额和优惠券变化都可以追溯</text></view>
    <view class="asset-grid">
      <view class="asset"><text class="value">{{ points?.available_points ?? 0 }}</text><text>可用积分</text></view>
      <view class="asset"><text class="value">¥{{ money(wallet?.available_amount ?? 0) }}</text><text>可用余额</text></view>
      <view class="asset"><text class="value">{{ coupons.length }}</text><text>可用优惠券</text></view>
    </view>
    <view v-if="points?.debt_points" class="warning">存在 {{ points.debt_points }} 积分欠额，新获得积分将优先偿还，清零前不能消费积分。</view>
    <view class="card"><text class="section-title">积分明细</text><view v-for="item in pointTransactions" :key="item.id" class="row"><view><text class="name">{{ item.type }} · {{ item.source_code || '系统' }}</text><text class="time">{{ formatTime(item.created_at) }}</text></view><text :class="item.change_points >= 0 ? 'plus' : 'minus'">{{ signed(item.change_points) }}</text></view><text v-if="pointTransactions.length === 0" class="empty">暂无积分流水</text></view>
    <view class="card"><text class="section-title">余额明细</text><view v-for="item in walletTransactions" :key="item.id" class="row"><view><text class="name">{{ item.type }}</text><text class="time">{{ formatTime(item.created_at) }} · {{ item.remark }}</text></view><text :class="item.change_amount >= 0 ? 'plus' : 'minus'">{{ signedMoney(item.change_amount) }}</text></view><text v-if="walletTransactions.length === 0" class="empty">暂无余额流水或尚未同意钱包规则</text></view>
    <view class="card"><text class="section-title">余额提现</text><input v-model="withdrawYuan" class="input" type="digit" placeholder="输入提现金额（元）" /><button class="primary" :loading="withdrawing" @click="quoteWithdrawal">计算手续费</button><view v-if="quote" class="quote"><text>申请 ¥{{ money(quote.requested_amount) }}</text><text>手续费 ¥{{ money(quote.fee_amount) }}（{{ (quote.fee_rate / 10000).toFixed(2) }}%）</text><text class="received">预计到账 ¥{{ money(quote.received_amount) }}</text><button class="primary" :loading="withdrawing" @click="submitWithdrawal">确认提交审核</button></view><view v-for="item in withdrawals" :key="item.id" class="row"><view><text class="name">{{ item.withdrawal_no }}</text><text class="time">{{ formatTime(item.created_at) }}</text></view><view class="discount"><text>¥{{ money(item.requested_amount) }}</text><text>{{ item.status }}</text></view></view></view>
    <view class="card"><text class="section-title">优惠券</text><view v-for="item in coupons" :key="item.id" class="coupon"><view><text class="name">{{ item.name }}</text><text class="time">有效至 {{ formatTime(item.valid_to) }}</text></view><view class="discount"><text v-if="item.cash_discount_amount">减 ¥{{ money(item.cash_discount_amount) }}</text><text v-if="item.points_discount">减 {{ item.points_discount }} 积分</text></view></view><text v-if="coupons.length === 0" class="empty">暂无可用优惠券</text></view>
  </view>
</template>

<script setup lang="ts">
import { onShow } from '@dcloudio/uni-app'
import { ref } from 'vue'
import { createWithdrawal, getPointAccount, getWalletAccount, getWithdrawalQuote, listCoupons, listPointTransactions, listWalletTransactions, listWithdrawals, type PointAccount, type PointTransaction, type UserCoupon, type WalletAccount, type WalletTransaction, type Withdrawal, type WithdrawalQuote } from '@/api/assets'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const points = ref<PointAccount | null>(null)
const wallet = ref<WalletAccount | null>(null)
const coupons = ref<UserCoupon[]>([])
const pointTransactions = ref<PointTransaction[]>([])
const walletTransactions = ref<WalletTransaction[]>([])
const withdrawals = ref<Withdrawal[]>([])
const withdrawYuan = ref('')
const quote = ref<WithdrawalQuote | null>(null)
const withdrawing = ref(false)

onShow(load)
async function load() {
  if (!auth.accessToken) return uni.navigateTo({ url: '/pages/login/index' })
  try { points.value = await getPointAccount(auth.accessToken); pointTransactions.value = (await listPointTransactions(auth.accessToken)).items } catch { /* account consent page handles the gate */ }
  try { wallet.value = await getWalletAccount(auth.accessToken); walletTransactions.value = (await listWalletTransactions(auth.accessToken)).items; withdrawals.value = (await listWithdrawals(auth.accessToken)).items } catch { /* wallet consent can be completed separately */ }
  try { coupons.value = await listCoupons(auth.accessToken) } catch { coupons.value = [] }
}
function money(cents: number) { return (cents / 100).toFixed(2) }
function signed(value: number) { return `${value >= 0 ? '+' : ''}${value}` }
function signedMoney(value: number) { return `${value >= 0 ? '+' : '-'}¥${money(Math.abs(value))}` }
function formatTime(value: string) { return new Date(value).toLocaleString() }
async function quoteWithdrawal() {
  const cents = Math.round(Number(withdrawYuan.value) * 100)
  if (!Number.isFinite(cents) || cents < 1) return uni.showToast({ title: '请输入正确金额', icon: 'none' })
  withdrawing.value = true
  try { quote.value = await getWithdrawalQuote(auth.accessToken, cents) }
  catch (error) { uni.showToast({ title: error instanceof Error ? error.message : '报价失败', icon: 'none' }) }
  finally { withdrawing.value = false }
}
async function submitWithdrawal() {
  if (!quote.value) return
  const confirmed = await uni.showModal({ title: '确认提现', content: `手续费 ¥${money(quote.value.fee_amount)}，预计到账 ¥${money(quote.value.received_amount)}。提交后将冻结全部申请金额。` })
  if (!confirmed.confirm) return
  withdrawing.value = true
  try { await createWithdrawal(auth.accessToken, quote.value.requested_amount); quote.value = null; withdrawYuan.value = ''; withdrawals.value = (await listWithdrawals(auth.accessToken)).items; wallet.value = await getWalletAccount(auth.accessToken); uni.showToast({ title: '已提交审核', icon: 'success' }) }
  catch (error) { uni.showToast({ title: error instanceof Error ? error.message : '提现申请失败', icon: 'none' }) }
  finally { withdrawing.value = false }
}
</script>

<style scoped>
.page{min-height:100vh;padding:36rpx 28rpx 80rpx;box-sizing:border-box;background:#f5f7fb}.heading{margin:12rpx 4rpx 28rpx}.title,.subtitle,.value,.section-title,.name,.time,.empty,.discount text,.quote text{display:block}.title{font-size:44rpx;font-weight:750}.subtitle{margin-top:8rpx;color:#667085}.asset-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:14rpx}.asset{padding:24rpx 8rpx;text-align:center;border-radius:20rpx;background:#fff;color:#667085;font-size:22rpx}.value{margin-bottom:8rpx;color:#1d2939;font-size:31rpx;font-weight:750}.warning{margin-top:20rpx;padding:22rpx;border-radius:16rpx;background:#fff2e8;color:#b54708;font-size:24rpx;line-height:1.55}.card{margin-top:24rpx;padding:28rpx;border-radius:24rpx;background:#fff}.section-title{font-size:30rpx;font-weight:700}.row,.coupon{display:flex;justify-content:space-between;align-items:center;gap:20rpx;padding:24rpx 0;border-bottom:1rpx solid #eef0f4}.name{font-weight:650}.time{margin-top:8rpx;color:#98a2b3;font-size:21rpx}.plus{color:#067647}.minus{color:#d92d20}.plus,.minus{font-weight:700}.discount{text-align:right;color:#3157d5}.empty{padding:34rpx 0;text-align:center;color:#98a2b3}.input{height:76rpx;margin-top:18rpx;padding:0 20rpx;border:1rpx solid #dfe3eb;border-radius:14rpx;box-sizing:border-box;background:#fafbfc}.primary{height:76rpx;margin-top:16rpx;border-radius:16rpx;background:#3157d5;color:#fff;line-height:76rpx}.primary::after{border:0}.quote{margin-top:18rpx;padding:20rpx;border-radius:16rpx;background:#f5f7fb;color:#667085;line-height:1.7}.received{color:#1d2939;font-size:28rpx;font-weight:700}
</style>
