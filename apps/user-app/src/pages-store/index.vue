<template>
  <view class="page">
    <view v-if="loading" class="empty">加载中…</view>
    <template v-else>
      <view v-if="stores.length === 0" class="empty-card">
        <text class="empty-title">还没有属于你的门店</text>
        <text class="empty-copy">提交开店申请，审核通过后即可在手机上管理商品、库存、订单与结算。</text>
      </view>

      <view v-for="store in stores" :key="store.id" class="store-card">
        <view class="store-head">
          <image v-if="store.logo_url" class="store-logo" :src="store.logo_url" mode="aspectFill" />
          <view v-else class="store-logo placeholder">{{ store.name.slice(0, 1) }}</view>
          <view class="store-main">
            <view class="store-title-row">
              <text class="store-name">{{ store.name }}</text>
              <text class="store-status" :class="store.status">{{ storeStatusLabel(store.status) }}</text>
            </view>
            <text class="store-sub">{{ store.role_label }} · {{ store.business_hours || '营业时间未设置' }}</text>
            <text v-if="store.reject_reason" class="store-reject">驳回原因：{{ store.reject_reason }}</text>
          </view>
        </view>
        <view class="store-actions">
          <button v-if="store.status === 'active'" class="btn primary" :loading="enteringId === store.id" @tap="enter(store)">进入门店工作台</button>
          <button v-if="store.status === 'rejected' && store.is_owner" class="btn" @tap="goApply(store.id)">修改后重新申请</button>
          <text v-if="store.status === 'pending'" class="hint">平台审核中，通过后可进入工作台</text>
          <text v-if="store.status === 'suspended'" class="hint">门店已暂停，请联系平台运营</text>
        </view>
      </view>

      <button class="create" @tap="goApply">＋ 我要开店</button>
      <text class="foot-tip">同一账号最多同时运营 3 家门店；门店商品由平台统一收款并按周期结算。</text>
    </template>
  </view>
</template>

<script setup lang="ts">
import { onShow } from '@dcloudio/uni-app'
import { ref } from 'vue'
import { listMyStores, openStoreManagerSession, storeStatusLabel, type MyStore } from '@/api/stores'
import { useAuthStore } from '@/stores/auth'
import { saveStoreSession } from '@/utils/storeSession'

const auth = useAuthStore()
const stores = ref<MyStore[]>([])
const loading = ref(false)
const enteringId = ref('')

const toast = (title: string) => uni.showToast({ title, icon: 'none' })

const load = async () => {
  if (!auth.accessToken) {
    uni.navigateTo({ url: '/pages/login/index' })
    return
  }
  loading.value = true
  try {
    stores.value = (await listMyStores(auth.accessToken)).items
  } catch (error) {
    toast(error instanceof Error ? error.message : '加载失败')
  } finally {
    loading.value = false
  }
}

const enter = async (store: MyStore) => {
  if (!auth.accessToken || enteringId.value) return
  enteringId.value = store.id
  try {
    const session = await openStoreManagerSession(auth.accessToken, store.id)
    saveStoreSession(session)
    uni.navigateTo({ url: '/pages-store/workbench' })
  } catch (error) {
    toast(error instanceof Error ? error.message : '进入门店失败')
  } finally {
    enteringId.value = ''
  }
}

const goApply = (storeId?: string) => uni.navigateTo({ url: `/pages-store/apply${storeId ? `?id=${storeId}` : ''}` })

onShow(() => { void load() })
</script>

<style scoped>
.page { min-height: 100vh; background: #f5f6f8; padding: 24rpx 24rpx 80rpx; box-sizing: border-box; }
.empty { padding: 160rpx 0; text-align: center; color: #9ca3af; font-size: 26rpx; }
.empty-card { background: #fff; border-radius: 20rpx; padding: 48rpx 32rpx; margin-bottom: 20rpx; }
.empty-title { display: block; font-size: 30rpx; font-weight: 700; color: #111827; }
.empty-copy { display: block; margin-top: 12rpx; font-size: 24rpx; color: #6b7280; line-height: 1.7; }
.store-card { background: #fff; border-radius: 20rpx; padding: 24rpx; margin-bottom: 20rpx; }
.store-head { display: flex; gap: 20rpx; }
.store-logo { width: 92rpx; height: 92rpx; border-radius: 16rpx; background: #eef2ff; }
.store-logo.placeholder { display: flex; align-items: center; justify-content: center; font-size: 36rpx; color: #2f6bff; font-weight: 700; }
.store-main { flex: 1; min-width: 0; }
.store-title-row { display: flex; align-items: center; gap: 12rpx; }
.store-name { font-size: 30rpx; font-weight: 700; color: #111827; }
.store-status { font-size: 20rpx; padding: 4rpx 12rpx; border-radius: 999rpx; background: #eef2ff; color: #2f6bff; }
.store-status.active { background: #ecfdf5; color: #059669; }
.store-status.rejected, .store-status.suspended { background: #fef2f2; color: #dc2626; }
.store-sub { display: block; margin-top: 8rpx; font-size: 22rpx; color: #9ca3af; }
.store-reject { display: block; margin-top: 8rpx; font-size: 22rpx; color: #dc2626; }
.store-actions { margin-top: 20rpx; display: flex; align-items: center; justify-content: flex-end; }
.hint { font-size: 22rpx; color: #9ca3af; }
.btn { margin: 0; font-size: 24rpx; border-radius: 999rpx; background: #f3f4f6; color: #374151; padding: 0 28rpx; height: 60rpx; line-height: 60rpx; }
.btn.primary { background: #2f6bff; color: #fff; }
.create { margin-top: 16rpx; background: #2f6bff; color: #fff; border-radius: 999rpx; font-size: 28rpx; }
.foot-tip { display: block; margin-top: 24rpx; font-size: 22rpx; color: #9ca3af; line-height: 1.6; text-align: center; }
</style>
