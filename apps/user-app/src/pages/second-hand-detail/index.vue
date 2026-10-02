<template>
  <view class="page" v-if="item">
    <scroll-view scroll-y class="scroll">
      <swiper class="gallery" :indicator-dots="item.images.length > 1" circular>
        <swiper-item v-for="(img, idx) in allImages" :key="idx">
          <image class="gallery-img" :src="resolveCover(img)" mode="aspectFill" />
        </swiper-item>
      </swiper>

      <view class="price-block">
        <text class="price">¥{{ item.price }}</text>
        <text class="cond-tag">{{ item.condition_label }}</text>
      </view>

      <view class="title-block">
        <text class="title">{{ item.title }}</text>
        <view class="meta">
          <text>{{ item.location || '校内交易' }}</text>
          <text>{{ viewText }}</text>
        </view>
      </view>

      <view class="desc-block" v-if="item.description">
        <text class="block-title">宝贝描述</text>
        <text class="desc">{{ item.description }}</text>
      </view>

      <view class="seller-block" v-if="item.seller">
        <view class="seller-info">
          <image class="avatar" :src="resolveCover(item.seller.avatar)" mode="aspectFill" />
          <view>
            <text class="seller-name">{{ item.seller.nickname }}</text>
            <text class="seller-sub">卖家</text>
          </view>
        </view>
        <view class="seller-action" @click="goChat">聊一聊</view>
      </view>
    </scroll-view>

    <view class="bottom-bar">
      <view class="bar-chat" @click="goChat">
        <text class="bar-icon">💬</text>
        <text>聊天</text>
      </view>
      <view class="bar-buy" @click="buy">立即购买</view>
    </view>
  </view>
</template>

<script setup lang="ts">
import { onLoad } from '@dcloudio/uni-app'
import { computed, ref } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { getSecondHandItem, createSecondHandOrder, type SecondHandItem } from '@/api/second-hand'
import { ensureChatConversation } from '@/api/chat'
import { resolveApiAssetUrl } from '@/api/http'

const auth = useAuthStore()
const item = ref<SecondHandItem | null>(null)
const itemId = ref('')

const allImages = computed(() => {
  if (!item.value) return []
  const imgs = item.value.images || []
  if (item.value.cover && !imgs.includes(item.value.cover)) return [item.value.cover, ...imgs]
  return imgs.length ? imgs : (item.value.cover ? [item.value.cover] : [])
})

const viewText = computed(() => `${item.value?.view_count || 0} 次浏览`)

function resolveCover(id: string | null) {
  return resolveApiAssetUrl(id)
}

onLoad((q) => {
  itemId.value = q?.id || ''
  load()
})

async function load() {
  if (!auth.accessToken || !itemId.value) return
  try {
    item.value = await getSecondHandItem(auth.accessToken, itemId.value)
  } catch {
    uni.showToast({ title: '加载失败', icon: 'none' })
  }
}

async function goChat() {
  if (!auth.isAuthenticated) { uni.navigateTo({ url: '/pages/login/index' }); return }
  if (!item.value?.seller?.id) return
  try {
    const conv = await ensureChatConversation(auth.accessToken, { user_id: item.value.seller.id, item_id: item.value.id })
    uni.navigateTo({ url: `/pages/chat-detail/index?id=${conv.id}` })
  } catch {
    uni.showToast({ title: '发起聊天失败', icon: 'none' })
  }
}

async function buy() {
  if (!auth.isAuthenticated) { uni.navigateTo({ url: '/pages/login/index' }); return }
  if (!item.value) return
  uni.showModal({
    title: '确认购买',
    content: `以 ¥${item.value.price} 购买「${item.value.title}」？`,
    success: async (r) => {
      if (!r.confirm) return
      try {
        const order = await createSecondHandOrder(auth.accessToken, { item_id: item.value!.id }, `sh-${Date.now()}`)
        uni.redirectTo({ url: `/pages/second-hand-order-detail/index?id=${order.id}` })
      } catch (e: any) {
        uni.showToast({ title: e.message || '下单失败', icon: 'none' })
      }
    },
  })
}
</script>

<style lang="scss" scoped>
.page { height: 100vh; display: flex; flex-direction: column; background: #f6f6f8; }
.scroll { flex: 1; }
.gallery { width: 100%; height: 750rpx; }
.gallery-img { width: 100%; height: 100%; }
.price-block { background: #fff; padding: 28rpx; display: flex; align-items: center; gap: 16rpx; }
.price { font-size: 48rpx; color: #ff2442; font-weight: 700; }
.cond-tag { background: #f0f0f0; color: #6e6e73; padding: 6rpx 16rpx; border-radius: 8rpx; font-size: 22rpx; }
.title-block { background: #fff; padding: 0 28rpx 28rpx; margin-top: 1rpx; }
.title { font-size: 32rpx; color: #1a1a1b; font-weight: 600; }
.meta { display: flex; gap: 24rpx; margin-top: 16rpx; font-size: 24rpx; color: #a0a0a5; }
.desc-block, .seller-block { background: #fff; margin-top: 16rpx; padding: 28rpx; }
.block-title { font-size: 28rpx; font-weight: 600; color: #1a1a1b; display: block; margin-bottom: 16rpx; }
.desc { font-size: 28rpx; color: #4a4a4a; line-height: 1.6; }
.seller-info { display: flex; align-items: center; gap: 20rpx; }
.avatar { width: 80rpx; height: 80rpx; border-radius: 50%; background: #eee; }
.seller-name { font-size: 30rpx; color: #1a1a1b; font-weight: 600; display: block; }
.seller-sub { font-size: 22rpx; color: #a0a0a5; }
.seller-block { display: flex; justify-content: space-between; align-items: center; }
.seller-action { background: #07c160; color: #fff; padding: 14rpx 32rpx; border-radius: 32rpx; font-size: 26rpx; }
.bottom-bar { display: flex; height: 100rpx; background: #fff; border-top: 1rpx solid #ececee; }
.bar-chat { flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: center; font-size: 22rpx; color: #6e6e73; }
.bar-icon { font-size: 32rpx; }
.bar-buy { flex: 2; background: #ff2442; color: #fff; display: flex; align-items: center; justify-content: center; font-size: 32rpx; font-weight: 600; }
</style>
