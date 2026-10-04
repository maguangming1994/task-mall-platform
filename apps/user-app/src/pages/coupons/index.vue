<template>
  <view class="page">
    <view class="subnav">
      <view class="back" @click="goBack"><text>‹</text></view>
      <text class="nav-title">优惠券</text>
      <text class="nav-side" />
    </view>

    <view class="tabs">
      <text
        v-for="t in tabs"
        :key="t.key"
        class="tab"
        :class="{ on: active === t.key }"
        @click="active = t.key"
      >{{ t.label }}<text v-if="counts[t.key]" class="tab-n"> {{ counts[t.key] }}</text></text>
    </view>

    <view v-if="loading" class="card">
      <view v-for="i in 3" :key="i" class="cp skel-card">
        <view class="cp-left tm-skeleton" />
        <view class="cp-mid">
          <view class="skel-line tm-skeleton" />
          <view class="skel-line short tm-skeleton" />
        </view>
      </view>
    </view>

    <view v-else-if="visibleCoupons.length" class="list">
      <view
        v-for="c in visibleCoupons"
        :key="c.id"
        class="cp"
        :class="{ disabled: active !== 'unused' }"
      >
        <view class="cp-left" :class="kindClass(c)">
          <text class="amount">{{ amountText(c) }}</text>
          <text class="kind">{{ kindText(c) }}</text>
        </view>
        <view class="cp-sep" />
        <view class="cp-mid">
          <text class="cp-name">{{ c.name }}</text>
          <text class="cp-desc">{{ ruleText(c) }}</text>
          <text class="cp-time">有效期至 {{ formatDate(c.valid_to) }}</text>
        </view>
        <view class="cp-right">
          <button v-if="active === 'unused'" class="use-btn" @click="goUse">去使用</button>
          <text v-else-if="active === 'used'" class="stale-text">已使用</text>
          <text v-else class="stale-text">已过期</text>
        </view>
      </view>

      <view v-if="active === 'unused'" class="tip">
        <text class="tip-icon">!</text>
        <text class="tip-copy">优惠券在下单时自动匹配最优方案，积分券与现金券不可叠加使用</text>
      </view>
    </view>

    <view v-else class="empty">
      <text class="empty-mark">{{ active === 'unused' ? '🎫' : '📭' }}</text>
      <text class="empty-title">{{ active === 'unused' ? '暂无可用优惠券' : active === 'used' ? '还没有使用过优惠券' : '没有已过期的优惠券' }}</text>
      <text v-if="active === 'unused'" class="empty-sub">完成任务、每日签到都有机会获得优惠券</text>
      <button v-if="active === 'unused'" class="empty-btn" @click="goUse">去逛逛</button>
    </view>
  </view>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue'
import { onShow } from '@dcloudio/uni-app'
import { listCoupons, type UserCoupon } from '@/api/assets'
import { useAuthStore } from '@/stores/auth'
import { navigateToLogin } from '@/utils/authRedirect'

type TabKey = 'unused' | 'used' | 'expired'
const tabs: Array<{ key: TabKey; label: string }> = [
  { key: 'unused', label: '可用' },
  { key: 'used', label: '已使用' },
  { key: 'expired', label: '已过期' },
]
const auth = useAuthStore()
const active = ref<TabKey>('unused')
const coupons = ref<UserCoupon[]>([])
const loading = ref(true)

const visibleCoupons = computed(() => coupons.value.filter((c) => c.status === active.value))
const counts = computed<Record<TabKey, number>>(() => ({
  unused: coupons.value.filter((c) => c.status === 'unused').length,
  used: coupons.value.filter((c) => c.status === 'used').length,
  expired: coupons.value.filter((c) => c.status === 'expired').length,
}))

onShow(() => {
  if (!auth.isAuthenticated) return navigateToLogin()
  load()
})

async function load() {
  if (!auth.accessToken) return
  loading.value = true
  try {
    coupons.value = await listCoupons(auth.accessToken)
  } catch (error) {
    uni.showToast({ title: error instanceof Error ? error.message : '优惠券加载失败', icon: 'none' })
  } finally {
    loading.value = false
  }
}

function isCashCoupon(c: UserCoupon) {
  return c.cash_discount_amount > 0
}
function kindClass(c: UserCoupon) {
  return isCashCoupon(c) ? 'cash' : 'points'
}
function kindText(c: UserCoupon) {
  return isCashCoupon(c) ? '现金券' : '积分券'
}
function amountText(c: UserCoupon) {
  if (isCashCoupon(c)) return `¥${(c.cash_discount_amount / 100).toFixed(0)}`
  return `${c.points_discount}`
}
function ruleText(c: UserCoupon) {
  return isCashCoupon(c) ? '下单满足使用门槛即可抵扣现金' : '兑换商品时按券面积分抵扣'
}
function formatDate(value: string) {
  const d = new Date(value)
  if (Number.isNaN(d.getTime())) return value.slice(0, 10).replace(/-/g, '.')
  return `${d.getFullYear()}.${String(d.getMonth() + 1).padStart(2, '0')}.${String(d.getDate()).padStart(2, '0')}`
}
function goBack() {
  uni.navigateBack({ fail: () => uni.reLaunch({ url: '/pages/account/index' }) })
}
function goUse() {
  uni.reLaunch({ url: '/pages/products/index' })
}
</script>

<style scoped>
.page { min-height: 100vh; background: #F4F6F4; padding-bottom: calc(40rpx + env(safe-area-inset-bottom)); }

.subnav { display: flex; align-items: center; height: 88rpx; padding: 0 24rpx; padding-top: var(--status-bar-height, 0); background: #fff; }
.back { width: 64rpx; height: 64rpx; display: flex; align-items: center; justify-content: center; }
.back text { font-size: 52rpx; line-height: 1; color: #191B1F; margin-top: -6rpx; }
.nav-title { flex: 1; text-align: center; font-size: 32rpx; font-weight: 800; color: #191B1F; }
.nav-side { width: 64rpx; }

.tabs { display: flex; margin: 20rpx 24rpx 0; padding: 6rpx; background: #fff; border-radius: 20rpx; box-shadow: 0 4rpx 16rpx rgba(25, 27, 31, 0.05); }
.tab { flex: 1; text-align: center; padding: 18rpx 0; font-size: 26rpx; color: #767C85; border-radius: 16rpx; }
.tab.on { background: linear-gradient(135deg, #12D978, #08A152); color: #fff; font-weight: 700; }
.tab-n { font-size: 24rpx; }

.list { padding: 20rpx 24rpx 0; }
.cp { display: flex; align-items: stretch; background: #fff; border-radius: 24rpx; margin-bottom: 20rpx; overflow: hidden; box-shadow: 0 4rpx 16rpx rgba(25, 27, 31, 0.05); }
.cp-left { width: 190rpx; flex: none; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 8rpx; color: #fff; }
.cp-left.cash { background: linear-gradient(135deg, #FF8A3D, #FF6A1A); }
.cp-left.points { background: linear-gradient(135deg, #12D978, #08A152); }
.amount { font-size: 44rpx; font-weight: 800; line-height: 1; }
.kind { font-size: 22rpx; opacity: 0.92; }
.cp-sep { width: 2rpx; margin: 20rpx 0; border-left: 2rpx dashed #D8DDDA; }
.cp-mid { flex: 1; min-width: 0; padding: 24rpx 16rpx 24rpx 22rpx; display: flex; flex-direction: column; justify-content: center; gap: 8rpx; }
.cp-name { font-size: 28rpx; font-weight: 700; color: #191B1F; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.cp-desc { font-size: 22rpx; color: #AEB3BB; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.cp-time { font-size: 20rpx; color: #AEB3BB; }
.cp-right { width: 130rpx; flex: none; display: flex; align-items: center; justify-content: center; padding-right: 10rpx; }
.use-btn { margin: 0; padding: 0 20rpx; height: 60rpx; line-height: 60rpx; border-radius: 30rpx; background: linear-gradient(135deg, #12D978, #08A152); color: #fff; font-size: 24rpx; font-weight: 700; }
.use-btn::after { border: 0; }
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
.skel-line.short { width: 160rpx; }
</style>
