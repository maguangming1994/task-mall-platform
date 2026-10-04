<template>
  <view class="page">
    <view class="subnav">
      <view class="back" @click="goBack"><text>‹</text></view>
      <text class="nav-title">领券中心</text>
      <text class="nav-side" @click="openMyCoupons">我的券</text>
    </view>

    <view class="hero">
      <text class="hero-title">校园福利券</text>
      <text class="hero-sub">下单自动匹配最优券 · 每人每种限领一张</text>
    </view>

    <view v-if="loading" class="list">
      <view v-for="i in 3" :key="i" class="cp skel-card">
        <view class="cp-left tm-skeleton" />
        <view class="cp-mid">
          <view class="skel-line tm-skeleton" />
          <view class="skel-line short tm-skeleton" />
        </view>
      </view>
    </view>

    <view v-else-if="items.length" class="list">
      <view v-for="c in items" :key="c.id" class="cp" :class="{ disabled: c.claimed || c.remaining <= 0 }">
        <view class="cp-left" :class="isCash(c) ? 'cash' : 'points'">
          <text class="amount">{{ amountText(c) }}</text>
          <text class="kind">{{ isCash(c) ? '现金券' : '积分券' }}</text>
        </view>
        <view class="cp-sep" />
        <view class="cp-mid">
          <text class="cp-name">{{ c.name }}</text>
          <text class="cp-desc">{{ thresholdText(c) }}</text>
          <view class="stock-row">
            <view class="stock-bar"><view class="stock-fill" :style="{ width: stockPercent(c) + '%' }" /></view>
            <text class="stock-text">剩 {{ c.remaining }}/{{ c.total_quantity }} 张</text>
          </view>
        </view>
        <view class="cp-right">
          <button
            v-if="!c.claimed && c.remaining > 0"
            class="claim-btn"
            :loading="claimingId === c.id"
            :disabled="!!claimingId"
            @click="claim(c)"
          >立即领取</button>
          <text v-else-if="c.claimed" class="stale-text">已领取</text>
          <text v-else class="stale-text">已抢光</text>
        </view>
      </view>

      <view class="tip">
        <text class="tip-icon">!</text>
        <text class="tip-copy">优惠券在领取后按券面天数计算有效期，下单时自动匹配最优方案，积分券与现金券不可叠加使用。</text>
      </view>
    </view>

    <view v-else class="empty">
      <text class="empty-mark">🎁</text>
      <text class="empty-title">暂无可领取的优惠券</text>
      <text class="empty-sub">新的活动券会在这里放出，记得常回来看看</text>
      <button class="empty-btn" @click="goMall">去逛逛</button>
    </view>
  </view>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { onShow } from '@dcloudio/uni-app'
import { listCouponCenter, claimCoupon, type CouponCenterItem } from '@/api/assets'
import { useAuthStore } from '@/stores/auth'
import { navigateToLogin } from '@/utils/authRedirect'

const auth = useAuthStore()
const items = ref<CouponCenterItem[]>([])
const loading = ref(true)
const claimingId = ref<string | null>(null)

onShow(() => {
  if (!auth.isAuthenticated) return navigateToLogin()
  load()
})

async function load() {
  if (!auth.accessToken) return
  loading.value = true
  try {
    const res = await listCouponCenter(auth.accessToken)
    items.value = res.items
  } catch (error) {
    uni.showToast({ title: error instanceof Error ? error.message : '加载失败', icon: 'none' })
  } finally {
    loading.value = false
  }
}

async function claim(c: CouponCenterItem) {
  if (!auth.accessToken || claimingId.value) return
  claimingId.value = c.id
  try {
    await claimCoupon(auth.accessToken, c.id)
    c.claimed = true
    c.remaining = Math.max(0, c.remaining - 1)
    uni.showToast({ title: '领取成功', icon: 'success' })
  } catch (error) {
    uni.showToast({ title: error instanceof Error ? error.message : '领取失败', icon: 'none' })
    await load()
  } finally {
    claimingId.value = null
  }
}

function isCash(c: CouponCenterItem) {
  return c.cash_discount_amount > 0
}
function amountText(c: CouponCenterItem) {
  if (isCash(c)) return `¥${(c.cash_discount_amount / 100).toFixed(0)}`
  return `${c.points_discount}`
}
function thresholdText(c: CouponCenterItem) {
  const rules: string[] = []
  if (c.minimum_cash_amount > 0) rules.push(`满 ¥${(c.minimum_cash_amount / 100).toFixed(0)} 可用`)
  if (c.minimum_points > 0) rules.push(`满 ${c.minimum_points} 积分可用`)
  rules.push(`领取后 ${c.valid_days} 天内有效`)
  return rules.join(' · ')
}
function stockPercent(c: CouponCenterItem) {
  if (c.total_quantity <= 0) return 0
  return Math.round((c.remaining / c.total_quantity) * 100)
}
function goBack() {
  uni.navigateBack({ fail: () => uni.redirectTo({ url: '/pages/coupons/index' }) })
}
function openMyCoupons() {
  uni.redirectTo({ url: '/pages/coupons/index' })
}
function goMall() {
  uni.reLaunch({ url: '/pages/products/index' })
}
</script>

<style scoped>
.page { min-height: 100vh; background: #F4F6F4; padding-bottom: calc(40rpx + env(safe-area-inset-bottom)); }

.subnav { display: flex; align-items: center; height: 88rpx; padding: 0 24rpx; padding-top: var(--status-bar-height, 0); background: #fff; }
.back { width: 64rpx; height: 64rpx; display: flex; align-items: center; justify-content: center; }
.back text { font-size: 52rpx; line-height: 1; color: #191B1F; margin-top: -6rpx; }
.nav-title { flex: 1; text-align: center; font-size: 32rpx; font-weight: 800; color: #191B1F; }
.nav-side { width: 120rpx; text-align: right; font-size: 24rpx; color: #08A152; font-weight: 600; }

.hero { margin: 20rpx 24rpx 0; padding: 36rpx 32rpx; border-radius: 24rpx; background: linear-gradient(135deg, #12D978, #08A152); box-shadow: 0 12rpx 26rpx rgba(11, 191, 99, 0.25); display: flex; flex-direction: column; gap: 12rpx; }
.hero-title { font-size: 38rpx; font-weight: 800; color: #fff; }
.hero-sub { font-size: 23rpx; color: rgba(255, 255, 255, 0.88); }

.list { padding: 20rpx 24rpx 0; }
.cp { display: flex; align-items: stretch; background: #fff; border-radius: 24rpx; margin-bottom: 20rpx; overflow: hidden; box-shadow: 0 4rpx 16rpx rgba(25, 27, 31, 0.05); }
.cp-left { width: 190rpx; flex: none; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 8rpx; color: #fff; }
.cp-left.cash { background: linear-gradient(135deg, #FF8A3D, #FF6A1A); }
.cp-left.points { background: linear-gradient(135deg, #12D978, #08A152); }
.amount { font-size: 44rpx; font-weight: 800; line-height: 1; }
.kind { font-size: 22rpx; opacity: 0.92; }
.cp-sep { width: 2rpx; margin: 20rpx 0; border-left: 2rpx dashed #D8DDDA; }
.cp-mid { flex: 1; min-width: 0; padding: 24rpx 16rpx 24rpx 22rpx; display: flex; flex-direction: column; justify-content: center; gap: 10rpx; }
.cp-name { font-size: 28rpx; font-weight: 700; color: #191B1F; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.cp-desc { font-size: 22rpx; color: #AEB3BB; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.stock-row { display: flex; align-items: center; gap: 12rpx; }
.stock-bar { flex: 1; height: 8rpx; border-radius: 4rpx; background: #F0F2F0; overflow: hidden; }
.stock-fill { height: 100%; border-radius: 4rpx; background: linear-gradient(90deg, #12D978, #0BBF63); }
.stock-text { font-size: 20rpx; color: #AEB3BB; flex: none; }
.cp-right { width: 150rpx; flex: none; display: flex; align-items: center; justify-content: center; padding-right: 10rpx; }
.claim-btn { margin: 0; padding: 0 10rpx; width: 130rpx; height: 64rpx; line-height: 64rpx; border-radius: 32rpx; background: linear-gradient(135deg, #12D978, #08A152); color: #fff; font-size: 24rpx; font-weight: 700; }
.claim-btn::after { border: 0; }
.claim-btn[disabled] { opacity: 0.6; }
.stale-text { font-size: 24rpx; color: #AEB3BB; }
.cp.disabled .cp-left { background: linear-gradient(135deg, #C7CCC8, #AEB3BB) !important; }
.cp.disabled .cp-name { color: #767C85; }

.tip { display: flex; align-items: flex-start; gap: 14rpx; margin: 8rpx 0 0; padding: 22rpx 26rpx; background: #E7F9EF; border-radius: 20rpx; }
.tip-icon { width: 32rpx; height: 32rpx; flex: none; border-radius: 50%; background: #08A152; color: #fff; font-size: 22rpx; text-align: center; line-height: 32rpx; font-weight: 800; }
.tip-copy { font-size: 22rpx; color: #08A152; line-height: 1.6; }

.empty { padding: 140rpx 48rpx; text-align: center; display: flex; flex-direction: column; align-items: center; }
.empty-mark { font-size: 88rpx; }
.empty-title { margin-top: 28rpx; font-size: 30rpx; font-weight: 700; color: #191B1F; }
.empty-sub { margin-top: 12rpx; font-size: 24rpx; color: #AEB3BB; }
.empty-btn { margin-top: 40rpx; width: 320rpx; height: 80rpx; line-height: 80rpx; border-radius: 40rpx; background: linear-gradient(135deg, #12D978, #08A152); color: #fff; font-size: 28rpx; font-weight: 700; }
.empty-btn::after { border: 0; }

.skel-card { height: 188rpx; align-items: center; }
.skel-card .cp-left { border-radius: 0; }
.skel-line { height: 28rpx; width: 240rpx; border-radius: 8rpx; margin-bottom: 14rpx; }
.skel-line.short { width: 150rpx; }
</style>
