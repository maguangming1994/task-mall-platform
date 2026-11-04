<template>
  <view class="page asset-page">
    <ModulePlaceholder v-if="modules.resolved && !moduleEnabled" code="module_wallet" />
    <view v-else-if="!modules.resolved" class="mp-skeleton">
      <view class="tm-skeleton" style="height: 200rpx; border-radius: 24rpx; margin: 24rpx 28rpx;" />
      <view class="tm-skeleton" style="height: 320rpx; border-radius: 24rpx; margin: 20rpx 28rpx;" />
    </view>
    <template v-else>
    <view v-if="loading && !points && !wallet" class="module-state" role="status" aria-live="polite"><text>正在加载资产信息…</text></view>

    <!-- 钱包 · wallet 设计屏 -->
    <view class="wallet-head">
      <text class="wh-lab">钱包余额（元）</text>
      <view class="wh-row">
        <text class="wh-num">{{ wallet ? money(wallet.available_amount) : '—' }}</text>
        <view class="wh-actions">
          <button v-if="modules.isEnabled('module_wallet_recharge')" class="wh-pill solid" @click="openRecharge">充值</button>
        </view>
      </view>
      <view class="wh-foot">
        <text>冻结余额 <text class="wh-b">¥{{ wallet ? money(wallet.frozen_amount) : '—' }}</text></text>
        <text class="wh-link" @click="scrollToWallet">余额明细 ›</text>
      </view>
    </view>

    <view v-if="pointsError" class="module-error"><text>积分资产加载失败：{{ pointsError }}</text><button @click="load">重试</button></view>
    <view v-if="walletError" class="module-error"><text>钱包流水加载失败：{{ walletError }}</text><button @click="load">重试</button></view>
    <view v-if="couponsError" class="module-error"><text>优惠券加载失败：{{ couponsError }}</text><button @click="load">重试</button></view>
    <view v-if="points?.debt_points" class="warning">存在 {{ points.debt_points }} 积分欠额，新获得积分将优先偿还，清零前不能消费积分。</view>

    <!-- 积分 · points 设计屏 -->
    <view class="pt-hero">
      <text class="pt-lab">当前可用积分</text>
      <text class="pt-num">{{ points ? points.available_points : '—' }}</text>
      <view class="pt-r3">
        <view><text class="pt-b">{{ points ? points.expiring_points : '—' }}</text><text>即将到期</text></view>
        <view><text class="pt-b">{{ points ? points.expiring_7_days : '—' }}</text><text>7 天内到期</text></view>
        <view><text class="pt-b">{{ couponsError ? '—' : coupons.length }}</text><text>可用优惠券</text></view>
      </view>
    </view>

    <view class="card expiry-card">
      <view class="sec-title"><text class="sec-h">积分到期计划</text><text class="sec-more">{{ points?.next_expiration_at ? `${formatDate(points.next_expiration_at)} 到期` : '暂无到期批次' }}</text></view>
      <view class="expiry-window">
        <view><text class="ew-lab">30 天内到期</text><text class="ew-val">{{ points ? points.expiring_30_days : '—' }}<text v-if="points"> 积分</text></text></view>
        <view><text class="ew-lab">最近到期日</text><text class="ew-val">{{ points?.next_expiration_at ? formatDate(points.next_expiration_at) : (pointsError ? '加载失败' : '暂无') }}</text></view>
      </view>
      <view v-for="batch in pointBatches" :key="batch.id" class="pt-row">
        <view class="pt-ic" :class="batch.days_remaining !== null && batch.days_remaining <= 7 ? 'ic-orange' : 'ic-green'">{{ sourceText(batch.source_code).slice(0, 1) }}</view>
        <view class="pt-main"><text class="pt-title">{{ sourceText(batch.source_code) }}</text><text class="pt-note">获得 {{ batch.issued_points }} · 剩余 {{ batch.remaining_points }}</text></view>
        <view class="expiry-date"><text :class="{ urgent: batch.days_remaining !== null && batch.days_remaining <= 7 }">{{ batch.days_remaining === null ? '长期有效' : `${batch.days_remaining} 天后到期` }}</text><text>{{ batch.expires_at ? formatDate(batch.expires_at) : '无到期日' }}</text></view>
      </view>
      <text v-if="!pointsError && pointBatches.length === 0" class="empty">当前没有待到期的积分批次</text>
    </view>

    <text class="pt-day">积分明细 · 来源与到账时间</text>
    <view class="card pt-ledger">
      <view v-for="item in pointTransactions" :key="item.id" class="pt-row">
        <view class="pt-ic" :class="item.change_points >= 0 ? 'ic-green' : 'ic-gray'">{{ pointTypeText(item.type).slice(0, 1) }}</view>
        <view class="pt-main"><text class="pt-title">{{ pointTypeText(item.type) }} · {{ sourceText(item.source_code) }}</text><text class="pt-note">{{ formatTime(item.created_at) }}</text></view>
        <text class="pt-amt" :class="item.change_points >= 0 ? 'in' : 'out'">{{ signed(item.change_points) }}</text>
      </view>
      <text v-if="!pointsError && pointTransactions.length === 0" class="empty">暂无积分流水</text>
    </view>

    <text class="pt-day">收支明细 · 余额无有效期</text>
    <view id="wallet-ledger" class="card pt-ledger">
      <view v-for="item in walletTransactions" :key="item.id" class="pt-row">
        <view class="pt-ic" :class="item.change_amount >= 0 ? 'ic-green' : 'ic-orange'">{{ walletTypeText(item.type).slice(0, 1) }}</view>
        <view class="pt-main"><text class="pt-title">{{ walletTypeText(item.type) }}</text><text class="pt-note">{{ formatTime(item.created_at) }} · {{ item.remark }}</text></view>
        <text class="pt-amt" :class="item.change_amount >= 0 ? 'in' : 'out'">{{ signedMoney(item.change_amount) }}</text>
      </view>
      <text v-if="!walletError && walletTransactions.length === 0" class="empty">暂无余额流水或尚未同意钱包规则</text>
    </view>

    <!-- 优惠券 · coupons 设计屏 -->
    <text class="pt-day">优惠券 · 可用 {{ couponsError ? '—' : coupons.length }} 张</text>
    <view v-for="item in coupons" :key="item.id" class="cp" :class="{ 'c-orange': item.points_discount && !item.cash_discount_amount }">
      <view class="cp-left">
        <text class="cp-val" v-if="item.cash_discount_amount"><text class="cp-unit">¥</text>{{ money(item.cash_discount_amount) }}</text>
        <text class="cp-val" v-else-if="item.points_discount">{{ item.points_discount }}<text class="cp-unit"> 积分</text></text>
        <text class="cp-val" v-else>券</text>
        <text class="cp-type">{{ item.cash_discount_amount ? '现金券' : item.points_discount ? '积分券' : '优惠券' }}</text>
      </view>
      <view class="cp-sep" />
      <view class="cp-mid">
        <text class="cp-name">{{ item.name }}</text>
        <text class="cp-time">有效至 {{ formatTime(item.valid_to) }}</text>
      </view>
    </view>
    <view v-if="!couponsError && coupons.length === 0" class="card"><text class="empty">暂无可用优惠券</text></view>

    </template>
    <AppTabBar current-target="account" />
  </view>
</template>

<script setup lang="ts">
import { formatDate, formatDateTime as formatTime } from '@/utils/dateTime'
import { onShow } from '@dcloudio/uni-app'
import { computed, ref } from 'vue'
import { getPointAccount, getWalletAccount, listCoupons, listPointBatches, listPointTransactions, listWalletTransactions, type PointAccount, type PointBatch, type PointTransaction, type UserCoupon, type WalletAccount, type WalletTransaction } from '@/api/assets'
import AppTabBar from '@/components/AppTabBar.vue'
import { useAuthStore } from '@/stores/auth'
import { navigateToLogin } from '@/utils/authRedirect'
import ModulePlaceholder from '@/components/ModulePlaceholder.vue'
import { useModulesStore } from '@/stores/modules'

const modules = useModulesStore()
const moduleEnabled = computed(() => modules.isEnabled('module_wallet'))

const auth = useAuthStore()
const points = ref<PointAccount | null>(null)
const wallet = ref<WalletAccount | null>(null)
const coupons = ref<UserCoupon[]>([])
const pointTransactions = ref<PointTransaction[]>([])
const pointBatches = ref<PointBatch[]>([])
const walletTransactions = ref<WalletTransaction[]>([])
const loading = ref(false)
const pointsError = ref('')
const walletError = ref('')
const couponsError = ref('')

onShow(load)

async function load() {
  await modules.ensureReady()
  if (!moduleEnabled.value) return
  if (!auth.isAuthenticated) return navigateToLogin()
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

    const walletResults = await Promise.allSettled([getWalletAccount(auth.accessToken), listWalletTransactions(auth.accessToken)])
    const [walletAccount, walletLedger] = walletResults
    if (walletAccount.status === 'fulfilled') wallet.value = walletAccount.value
    else { wallet.value = null; walletError.value = errorMessage(walletAccount.reason, '钱包账户加载失败') }
    if (walletLedger.status === 'fulfilled') walletTransactions.value = walletLedger.value.items
    else { walletTransactions.value = []; walletError.value ||= errorMessage(walletLedger.reason, '余额流水加载失败') }

    try { coupons.value = await listCoupons(auth.accessToken) } catch (error) { coupons.value = []; couponsError.value = errorMessage(error, '优惠券加载失败') }
  } finally {
    loading.value = false
  }
}

function errorMessage(error: unknown, fallback: string) { return error instanceof Error ? error.message : fallback }
function money(cents: number) { return (cents / 100).toFixed(2) }
function signed(value: number) { return `${value >= 0 ? '+' : ''}${value}` }
function signedMoney(value: number) { return `${value >= 0 ? '+' : '-'}¥${money(Math.abs(value))}` }
function pointTypeText(value: string) { return ({ grant: '获得积分', consume: '使用积分', expire: '积分到期', refund: '退款返还', adjust: '后台调整', reverse: '业务冲正' } as Record<string, string>)[value] || '积分变动' }
function walletTypeText(value: string) { return ({ grant: '余额奖励', consume: '余额支付', refund: '退款返还', withdrawal: '余额提现', adjust: '后台调整', reverse: '业务冲正', recharge_card: '卡密充值', recharge_online: '线上充值', recharge_offline: '线下充值' } as Record<string, string>)[value] || '余额变动' }
function sourceText(value?: string | null) { return ({ signin: '签到', daily_check_in: '每日签到', post: '发布帖子', comment: '发表评论', profile: '完善资料', referral: '推荐新人', referral_new_user: '推荐新人', task: '完成任务', manual: '平台发放' } as Record<string, string>)[value || ''] || '其他来源' }
function openRecharge() { uni.navigateTo({ url: '/pages/wallet-recharge/index' }) }
function scrollToWallet() { uni.pageScrollTo({ selector: '#wallet-ledger', duration: 300 }) }
</script>

<style scoped>
.page{min-height:100vh;box-sizing:border-box;padding:0 24rpx 120rpx;background:#F4F6F4;color:#191B1F}
.module-state{margin-top:20rpx;padding:40rpx;border-radius:32rpx;background:#fff;color:#767C85;text-align:center;font-size:26rpx}
/* 钱包余额卡 · wallet 屏 */
.wallet-head{position:relative;overflow:hidden;margin:20rpx 0 0;padding:40rpx 36rpx 32rpx;border-radius:36rpx;background:linear-gradient(135deg,#12D978,#08A152);color:#fff;box-shadow:0 20rpx 48rpx rgba(11,191,99,.28)}
.wallet-head::before{content:"";position:absolute;top:-160rpx;right:-80rpx;width:360rpx;height:360rpx;border-radius:50%;background:rgba(255,255,255,.10)}
.wh-lab{position:relative;display:block;font-size:24rpx;opacity:.85}
.wh-row{position:relative;display:flex;align-items:flex-end;justify-content:space-between;margin-top:16rpx}
.wh-num{font-size:72rpx;font-weight:800;line-height:1.1}
.wh-actions{display:flex;gap:18rpx}
.wh-pill{height:64rpx;margin:0;padding:0 32rpx;border-radius:32rpx;font-size:24rpx;font-weight:700;line-height:64rpx}
.wh-pill.ghost{border:2rpx solid rgba(255,255,255,.7);background:transparent;color:#fff}
.wh-pill.solid{border:0;background:#fff;color:#08A152}
.wh-pill::after{border:0}
.wh-foot{position:relative;display:flex;gap:32rpx;margin-top:28rpx;padding-top:24rpx;border-top:1rpx solid rgba(255,255,255,.25);font-size:22rpx}
.wh-b{font-size:26rpx;font-weight:700}
.wh-link{margin-left:auto;color:rgba(255,255,255,.9)}
/* 积分卡 · points 屏 */
.pt-hero{position:relative;overflow:hidden;margin:20rpx 0 0;padding:36rpx;border-radius:36rpx;background:linear-gradient(135deg,#12D978,#08A152);color:#fff;box-shadow:0 20rpx 48rpx rgba(11,191,99,.28)}
.pt-hero::before{content:"";position:absolute;top:-200rpx;right:-100rpx;width:400rpx;height:400rpx;border-radius:50%;background:rgba(255,255,255,.10)}
.pt-lab{position:relative;display:block;font-size:24rpx;opacity:.85}
.pt-num{position:relative;display:block;margin-top:8rpx;font-size:76rpx;font-weight:800;line-height:1.1}
.pt-r3{position:relative;display:flex;margin-top:28rpx}
.pt-r3>view{flex:1;font-size:22rpx;opacity:.9}
.pt-r3 .pt-b{display:block;margin-bottom:4rpx;font-size:32rpx;font-weight:800}
/* 通用卡片与分节 */
.card{margin:20rpx 0 0;border-radius:32rpx;background:#fff;overflow:hidden;box-shadow:0 2rpx 4rpx rgba(23,26,23,.03),0 12rpx 40rpx rgba(23,26,23,.05)}
.sec-title{display:flex;align-items:center;justify-content:space-between;padding:28rpx 28rpx 20rpx}
.sec-h{display:flex;align-items:center;font-size:30rpx;font-weight:700}
.sec-h::before{content:"";width:8rpx;height:28rpx;margin-right:14rpx;border-radius:4rpx;background:#0BBF63}
.sec-more{font-size:24rpx;color:#AEB3BB}
.pt-day{display:block;padding:28rpx 8rpx 8rpx;font-size:22rpx;color:#AEB3BB}
/* 流水行 */
.pt-row{display:flex;align-items:center;gap:22rpx;padding:24rpx 30rpx;background:#fff}
.pt-row+.pt-row{border-top:1rpx solid #F5F6F6}
.pt-ic{display:flex;align-items:center;justify-content:center;width:76rpx;height:76rpx;flex:none;border-radius:24rpx;font-size:28rpx;font-weight:800}
.pt-ic.ic-green{background:#E7F9EF;color:#08A152}
.pt-ic.ic-orange{background:#FFF2E5;color:#F2620A}
.pt-ic.ic-gray{background:#F2F4F3;color:#767C85}
.pt-main{flex:1;min-width:0}
.pt-title{display:block;font-size:27rpx;font-weight:600;color:#191B1F}
.pt-note{display:block;margin-top:6rpx;font-size:22rpx;color:#AEB3BB;line-height:1.4}
.pt-amt{font-size:32rpx;font-weight:800;flex:none}
.pt-amt.in{color:#08A152}
.pt-amt.out{color:#191B1F}
/* 到期计划 */
.expiry-card{padding-bottom:8rpx}
.expiry-window{display:grid;grid-template-columns:1fr 1fr;gap:16rpx;margin:0 28rpx 8rpx}
.expiry-window>view{padding:24rpx;border-radius:24rpx;background:#F7F9F8}
.ew-lab{display:block;font-size:24rpx;color:#767C85}
.ew-val{display:block;margin-top:12rpx;font-size:30rpx;font-weight:800;color:#191B1F}
.expiry-date{flex:none;text-align:right}
.expiry-date>text{display:block;font-size:22rpx;color:#767C85}
.expiry-date>text:first-child{font-weight:700;color:#191B1F}
.expiry-date .urgent{color:#F2620A}
/* 优惠券 · coupons 屏 */
.cp{position:relative;display:flex;height:196rpx;margin:20rpx 0 0;border-radius:28rpx;overflow:hidden;color:#fff;background:linear-gradient(120deg,#14C96E,#08A152);box-shadow:0 2rpx 4rpx rgba(23,26,23,.03),0 12rpx 40rpx rgba(23,26,23,.05)}
.cp.c-orange{background:linear-gradient(120deg,#FF9A3D,#F2620A)}
.cp-left{display:flex;flex-direction:column;align-items:center;justify-content:center;width:216rpx;flex:none}
.cp-val{font-size:52rpx;font-weight:900;line-height:1}
.cp-unit{font-size:26rpx;font-weight:700}
.cp-type{margin-top:8rpx;font-size:20rpx;opacity:.9}
.cp-sep{position:relative;width:0;margin:20rpx 0;border-left:3rpx dashed rgba(255,255,255,.65)}
.cp-sep::before{content:"";position:absolute;left:-13rpx;top:-32rpx;width:22rpx;height:22rpx;border-radius:50%;background:#F4F6F4}
.cp-sep::after{content:"";position:absolute;left:-13rpx;bottom:-32rpx;width:22rpx;height:22rpx;border-radius:50%;background:#F4F6F4}
.cp-mid{flex:1;min-width:0;padding:28rpx 16rpx 24rpx 28rpx}
.cp-name{display:block;font-size:28rpx;font-weight:800}
.cp-time{display:block;margin-top:14rpx;font-size:20rpx;opacity:.75}
/* 状态与错误 */
.empty{display:block;padding:40rpx 0;color:#AEB3BB;text-align:center;font-size:26rpx}
.module-error{display:flex;align-items:center;justify-content:space-between;gap:16rpx;margin-top:20rpx;padding:20rpx 24rpx;border-radius:24rpx;background:#FFEEEA;color:#FF2442;font-size:24rpx}
.module-error text{min-width:0;line-height:1.45}
.module-error button{height:56rpx;flex:none;margin:0;padding:0 24rpx;border:2rpx solid #FF2442;border-radius:28rpx;background:#fff;color:#FF2442;font-size:24rpx;line-height:52rpx}
.module-error button::after{border:0}
.warning{margin-top:20rpx;padding:24rpx;border-radius:24rpx;background:#FFF2E5;color:#F2620A;font-size:24rpx;line-height:1.5}
</style>
