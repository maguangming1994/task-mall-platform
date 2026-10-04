<template>
  <view class="page" v-if="item">
    <scroll-view scroll-y class="scroll">
      <swiper class="gallery" :indicator-dots="item.images.length > 1" circular indicator-color="rgba(255,255,255,.45)" indicator-active-color="#fff">
        <swiper-item v-for="(img, idx) in allImages" :key="idx">
          <image class="gallery-img" :src="resolveCover(img)" mode="aspectFill" />
        </swiper-item>
      </swiper>

      <view class="title-card">
        <view class="price-row">
          <text class="price">¥{{ item.price }}</text>
          <text class="stat">{{ viewText }} · 收藏 {{ item.favorite_count || 0 }}</text>
        </view>
        <text class="title">{{ item.title }}</text>
        <view class="tags">
          <text class="tag tag-green">{{ item.condition_label }}</text>
          <text class="tag">{{ tradeTypeText }}</text>
        </view>
      </view>

      <view class="card">
        <view class="info-row">
          <text class="info-k">成色</text>
          <text class="info-v">{{ item.condition_label }}</text>
        </view>
        <view class="info-row">
          <text class="info-k">交易方式</text>
          <text class="info-v">{{ tradeTypeText }}<text v-if="item.location"> · {{ item.location }}</text></text>
        </view>
        <view class="info-row">
          <text class="info-k">发布时间</text>
          <text class="info-v">{{ formatTime(item.created_at) }}</text>
        </view>
      </view>

      <view class="card" v-if="item.description">
        <view class="sec-title"><text class="sec-h">描述</text></view>
        <text class="desc">{{ item.description }}</text>
      </view>

      <view class="card" v-if="item.seller">
        <view class="publisher" @click="goSeller">
          <image class="avatar" :src="resolveCover(item.seller.avatar)" mode="aspectFill" />
          <view class="pub-main">
            <text class="pub-name">{{ item.seller.nickname }}</text>
            <text class="pub-sub">卖家 · {{ item.location || '校内' }}</text>
          </view>
          <view class="pub-btn" @click.stop="goChat">聊一聊</view>
        </view>
      </view>

      <view class="bottom-space" />
    </scroll-view>

    <view class="action-bar">
      <view class="ab-ic" @click="goChat">
        <text class="ab-ic-glyph">✎</text>
        <text class="ab-ic-text">聊一聊</text>
      </view>
      <button class="ab-main" @click="buy">立即购买 ¥{{ item.price }}</button>
    </view>
  </view>
</template>

<script setup lang="ts">
import { onLoad } from '@dcloudio/uni-app'
import { computed, ref } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { getSecondHandItem, type SecondHandItem } from '@/api/second-hand'
import { ensureChatConversation } from '@/api/chat'
import { resolveApiAssetUrl } from '@/api/http'
import { formatDate } from '@/utils/dateTime'

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

const tradeTypeText = computed(() => ({ meetup: '校内面交', mail: '邮寄', both: '面交/邮寄均可' } as Record<string, string>)[item.value?.trade_type || ''] || '校内交易')

function resolveCover(id: string | null) {
  return resolveApiAssetUrl(id)
}

function formatTime(s: string | null) {
  return formatDate(s, '')
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

function goSeller() {
  if (!item.value?.seller?.id) return
  uni.navigateTo({ url: `/pages/sh-user/index?id=${item.value.seller.id}` })
}

async function buy() {
  if (!auth.isAuthenticated) { uni.navigateTo({ url: '/pages/login/index' }); return }
  if (!item.value) return
  uni.navigateTo({ url: `/pages/second-hand-buy/index?id=${encodeURIComponent(item.value.id)}` })
}
</script>

<style lang="scss" scoped>
.page { height: 100vh; display: flex; flex-direction: column; background: #F4F6F4; }
.scroll { flex: 1; }

/* 图廊 */
.gallery { width: 100%; height: 560rpx; }
.gallery-img { width: 100%; height: 100%; }

/* 价格标题卡 */
.title-card { background: #fff; margin: -32rpx 24rpx 0; border-radius: 32rpx; padding: 30rpx 30rpx 26rpx; position: relative; z-index: 3; box-shadow: 0 2rpx 4rpx rgba(23, 26, 23, .03), 0 12rpx 40rpx rgba(23, 26, 23, .05); }
.price-row { display: flex; align-items: baseline; }
.price { font-size: 56rpx; font-weight: 800; color: #FF2442; line-height: 1; }
.stat { margin-left: auto; font-size: 22rpx; color: #AEB3BB; }
.title { display: block; font-size: 34rpx; font-weight: 800; color: #191B1F; line-height: 1.45; margin-top: 18rpx; }
.tags { margin-top: 18rpx; display: flex; flex-wrap: wrap; gap: 8rpx; }
.tag { display: inline-flex; align-items: center; height: 36rpx; padding: 0 12rpx; border-radius: 10rpx; font-size: 20rpx; font-weight: 500; background: #F2F4F3; color: #767C85; }
.tag-green { background: #E7F9EF; color: #08A152; }

/* 通用卡片 */
.card { background: #fff; border-radius: 32rpx; margin: 20rpx 24rpx 0; overflow: hidden; }
.info-row { display: flex; align-items: center; padding: 24rpx 30rpx; font-size: 26rpx; color: #3A3D43; border-bottom: 1rpx solid #F5F6F6; }
.info-row:last-child { border-bottom: 0; }
.info-k { flex: none; }
.info-v { margin-left: auto; color: #767C85; font-size: 24rpx; }
.sec-title { padding: 26rpx 30rpx 16rpx; }
.sec-h { font-size: 30rpx; font-weight: 700; color: #191B1F; display: flex; align-items: center; }
.sec-h::before { content: ''; display: inline-block; width: 8rpx; height: 28rpx; border-radius: 4rpx; background: #0BBF63; margin-right: 14rpx; }
.desc { display: block; padding: 0 30rpx 28rpx; font-size: 28rpx; line-height: 1.75; color: #3A3D43; }

/* 卖家 */
.publisher { display: flex; align-items: center; gap: 20rpx; padding: 26rpx 30rpx; }
.avatar { width: 84rpx; height: 84rpx; border-radius: 50%; background: #EDEFEF; flex: none; }
.pub-main { flex: 1; min-width: 0; }
.pub-name { display: block; font-size: 28rpx; font-weight: 600; color: #191B1F; }
.pub-sub { display: block; font-size: 22rpx; color: #AEB3BB; margin-top: 6rpx; }
.pub-btn { height: 60rpx; line-height: 60rpx; padding: 0 30rpx; border-radius: 30rpx; background: #E7F9EF; color: #08A152; font-size: 24rpx; font-weight: 700; }

.bottom-space { height: 24rpx; }

/* 底部操作栏 */
.action-bar { background: #fff; border-top: 1rpx solid #EDEFEF; display: flex; align-items: center; padding: 12rpx 24rpx calc(12rpx + env(safe-area-inset-bottom)); gap: 12rpx; }
.ab-ic { width: 96rpx; display: flex; flex-direction: column; align-items: center; gap: 4rpx; }
.ab-ic-glyph { font-size: 36rpx; color: #767C85; line-height: 1; }
.ab-ic-text { font-size: 20rpx; color: #767C85; }
.ab-main { flex: 1; height: 84rpx; line-height: 84rpx; border-radius: 42rpx; background: linear-gradient(135deg, #12D978, #08A152); color: #fff; font-size: 30rpx; font-weight: 700; box-shadow: 0 12rpx 28rpx rgba(11, 191, 99, .3); margin: 0; }
.ab-main::after { border: 0; }
</style>
