<template>
  <view class="page">
    <view v-if="!session" class="empty">请先从「我的店铺」进入门店。</view>
    <template v-else>
      <view class="hero">
        <view class="hero-top">
          <view>
            <text class="hero-name">{{ overview?.store.name || session.storeName }}</text>
            <text class="hero-role">{{ profile?.member.role_label }} · {{ overview?.store.status === 'active' ? '营业中' : '已停用' }}</text>
          </view>
          <text class="hero-rate">平台抽成 {{ ((overview?.store.commission_rate_bps || 0) / 100).toFixed(2) }}%</text>
        </view>
        <view v-if="campus" class="campus-line">
          <text class="campus-label">经营校区</text>
          <picker v-if="campuses.length > 1" class="campus-picker" :range="campusNames" :value="campusIndex" @change="onCampusChange">
            <view class="campus-value">{{ campus.campus_name }}<text class="campus-switch">切换</text></view>
          </picker>
          <text v-else class="campus-value">{{ campus.campus_name }}</text>
        </view>
        <view class="hero-stats">
          <view class="stat"><b>{{ money(overview?.today.revenue_amount || 0) }}</b><span>今日营业额</span></view>
          <view class="stat"><b>{{ overview?.today.paid_order_count || 0 }}</b><span>今日成交订单</span></view>
          <view class="stat"><b>{{ overview?.pending_fulfillment_count || 0 }}</b><span>待履约订单</span></view>
        </view>
      </view>

      <view class="panel">
        <view class="panel-head">
          <text class="panel-title">待结算</text>
          <text class="panel-link" @tap="goSettlements">查看账单 ›</text>
        </view>
        <view class="panel-row">
          <text class="amount">{{ money(overview?.unbilled.net_amount || 0) }}</text>
          <text class="panel-sub">{{ overview?.unbilled.item_count || 0 }} 笔订单 · 抽成 {{ money(overview?.unbilled.commission_amount || 0) }}</text>
        </view>
      </view>

      <view class="grid">
        <view v-for="item in entries" :key="item.label" class="entry" @tap="item.action">
          <text class="entry-mark">{{ item.mark }}</text>
          <text class="entry-label">{{ item.label }}</text>
          <text class="entry-note">{{ item.note }}</text>
        </view>
      </view>

      <view class="mini-row">
        <view class="mini"><text class="mini-value">{{ overview?.product_counts.on_sale || 0 }}</text><text class="mini-label">在售商品</text></view>
        <view class="mini"><text class="mini-value">{{ overview?.product_counts.draft || 0 }}</text><text class="mini-label">草稿</text></view>
        <view class="mini warn"><text class="mini-value">{{ overview?.low_stock_sku_count || 0 }}</text><text class="mini-label">库存预警</text></view>
      </view>

      <button class="leave" @tap="leave">退出门店工作台</button>
    </template>
  </view>
</template>

<script setup lang="ts">
import { onPullDownRefresh, onShow } from '@dcloudio/uni-app'
import { computed, ref } from 'vue'
import {
  closeStoreManagerSession,
  getStoreManagerProfile,
  getStoreOverview,
  type StoreCampus,
  type StoreManagerProfile,
  type StoreOverview,
} from '@/api/stores'
import { useAuthStore } from '@/stores/auth'
import {
  clearStoreSession,
  getSelectedStoreCampus,
  getStoreSession,
  saveSelectedStoreCampus,
  syncStoreCampuses,
} from '@/utils/storeSession'

const auth = useAuthStore()
const session = ref(getStoreSession())
const overview = ref<StoreOverview | null>(null)
const profile = ref<StoreManagerProfile | null>(null)
const campus = ref<StoreCampus | null>(session.value ? getSelectedStoreCampus(session.value.storeId) : null)
const campuses = computed(() => profile.value?.store.campuses || session.value?.campuses || [])
const campusNames = computed(() => campuses.value.map((item) => item.campus_name))
const campusIndex = computed(() => Math.max(0, campuses.value.findIndex((item) => item.campus_id === campus.value?.campus_id)))

const money = (cents: number) => `¥${(cents / 100).toFixed(2)}`
const toast = (title: string) => uni.showToast({ title, icon: 'none' })

const entries = computed(() => [
  { mark: '品', label: '商品管理', note: '上下架与编辑', action: () => uni.navigateTo({ url: '/pages-store/products' }) },
  { mark: '库', label: '库存管理', note: '补货与预警', action: () => uni.navigateTo({ url: '/pages-store/inventory' }) },
  { mark: '单', label: '门店订单', note: '查看成交明细', action: () => uni.navigateTo({ url: '/pages-store/orders' }) },
  { mark: '账', label: '结算账单', note: '周期结算记录', action: () => uni.navigateTo({ url: '/pages-store/settlements' }) },
  { mark: '店', label: '门店设置', note: '资料与成员', action: () => uni.navigateTo({ url: '/pages-store/settings' }) },
  { mark: '客', label: '门店主页', note: '查看对外展示', action: () => uni.navigateTo({ url: `/pages-store/store-home?id=${session.value?.storeId}` }) },
])

const load = async () => {
  if (!session.value) return
  if (!auth.accessToken) {
    uni.navigateTo({ url: '/pages/login/index' })
    return
  }
  try {
    campus.value = getSelectedStoreCampus(session.value.storeId)
    const profileData = await getStoreManagerProfile(session.value.token)
    profile.value = profileData
    campus.value = syncStoreCampuses(session.value.storeId, profileData.store.campuses || [])
    overview.value = await getStoreOverview(session.value.token, campus.value?.campus_id)
  } catch (error) {
    toast(error instanceof Error ? error.message : '加载失败')
    if (error instanceof Error && /登录已过期|重新进入/.test(error.message)) {
      clearStoreSession()
      uni.redirectTo({ url: '/pages-store/index' })
    }
  } finally {
    uni.stopPullDownRefresh()
  }
}

const onCampusChange = async (event: any) => {
  if (!session.value) return
  const selected = campuses.value[Number(event.detail.value)]
  if (!selected || selected.campus_id === campus.value?.campus_id) return
  saveSelectedStoreCampus(session.value.storeId, selected)
  campus.value = selected
  try {
    overview.value = await getStoreOverview(session.value.token, selected.campus_id)
  } catch (error) {
    toast(error instanceof Error ? error.message : '校区切换失败')
  }
}

const goSettlements = () => uni.navigateTo({ url: '/pages-store/settlements' })

const leave = () => {
  uni.showModal({
    title: '退出门店工作台',
    content: '退出后需要重新从「我的店铺」进入。',
    success: async (result) => {
      if (!result.confirm) return
      if (session.value) {
        try { await closeStoreManagerSession(session.value.token) } catch { /* 忽略退出接口异常 */ }
      }
      clearStoreSession()
      uni.redirectTo({ url: '/pages-store/index' })
    },
  })
}

onShow(() => { session.value = getStoreSession(); void load() })
onPullDownRefresh(() => { void load() })
</script>

<style scoped>
.page { min-height: 100vh; background: #f5f6f8; padding: 24rpx 24rpx 80rpx; box-sizing: border-box; }
.empty { padding: 160rpx 0; text-align: center; color: #9ca3af; font-size: 26rpx; }
.hero { background: linear-gradient(135deg, #2f6bff, #6d5efc); border-radius: 24rpx; padding: 28rpx; color: #fff; }
.hero-top { display: flex; justify-content: space-between; align-items: flex-start; }
.hero-name { font-size: 34rpx; font-weight: 700; display: block; }
.hero-role { font-size: 22rpx; opacity: 0.8; margin-top: 6rpx; display: block; }
.hero-rate { font-size: 20rpx; background: rgba(255, 255, 255, 0.18); padding: 6rpx 14rpx; border-radius: 999rpx; }
.campus-line { display: flex; align-items: center; justify-content: space-between; gap: 16rpx; margin-top: 20rpx; padding-top: 20rpx; border-top: 1rpx solid rgba(255, 255, 255, 0.2); font-size: 22rpx; }
.campus-picker { flex: 1; }
.campus-value { display: block; text-align: right; }
.campus-switch { margin-left: 12rpx; font-size: 20rpx; opacity: 0.75; }
.hero-stats { display: flex; margin-top: 28rpx; }
.stat { flex: 1; }
.stat b { display: block; font-size: 32rpx; }
.stat span { display: block; font-size: 20rpx; opacity: 0.78; margin-top: 6rpx; }
.panel { background: #fff; border-radius: 20rpx; padding: 24rpx; margin-top: 20rpx; }
.panel-head { display: flex; justify-content: space-between; align-items: center; }
.panel-title { font-size: 26rpx; color: #374151; font-weight: 600; }
.panel-link { font-size: 22rpx; color: #2f6bff; }
.panel-row { margin-top: 16rpx; display: flex; align-items: baseline; gap: 16rpx; }
.amount { font-size: 40rpx; font-weight: 700; color: #111827; }
.panel-sub { font-size: 22rpx; color: #9ca3af; }
.grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 16rpx; margin-top: 20rpx; }
.entry { background: #fff; border-radius: 20rpx; padding: 24rpx 16rpx; text-align: center; }
.entry-mark { display: inline-flex; width: 64rpx; height: 64rpx; border-radius: 18rpx; background: #eef2ff; color: #2f6bff; font-size: 28rpx; align-items: center; justify-content: center; font-weight: 700; }
.entry-label { display: block; margin-top: 12rpx; font-size: 24rpx; color: #111827; font-weight: 600; }
.entry-note { display: block; margin-top: 4rpx; font-size: 20rpx; color: #9ca3af; }
.mini-row { display: flex; gap: 16rpx; margin-top: 20rpx; }
.mini { flex: 1; background: #fff; border-radius: 20rpx; padding: 22rpx; text-align: center; }
.mini-value { display: block; font-size: 34rpx; font-weight: 700; color: #111827; }
.mini-label { display: block; font-size: 20rpx; color: #9ca3af; margin-top: 4rpx; }
.mini.warn .mini-value { color: #d97706; }
.leave { margin-top: 32rpx; background: #fff; color: #6b7280; border-radius: 999rpx; font-size: 26rpx; }
</style>
