<template>
  <view class="page">
    <view class="subnav">
      <view class="back" @click="goBack">‹</view>
      <text class="nav-title">私信</text>
      <view class="nav-right"><text class="right-text">全部已读</text></view>
    </view>

    <view class="safety-card">
      <view class="safety-icon">✓</view>
      <text class="safety-text">交易沟通请留在平台内，脱离平台支付不受保护</text>
    </view>

    <view v-if="loading && !items.length" class="card">
      <view v-for="i in 3" :key="i" class="msg-row tm-skeleton" style="height: 120rpx;" />
    </view>

    <view v-else-if="items.length" class="card">
      <view v-for="c in items" :key="c.id" class="msg-row" @click="openChat(c.id)">
        <image class="av" :src="resolveCover(c.other_user?.avatar ?? null)" mode="aspectFill" />
        <view class="msg-main">
          <view class="l1">
            <text class="name">{{ c.other_user?.nickname || '用户' }}</text>
            <text class="time">{{ formatTime(c.last_message_at) }}</text>
          </view>
          <view class="l2">
            <text class="last">{{ c.last_message || '暂无消息' }}</text>
            <view v-if="c.unread" class="badge">{{ c.unread }}</view>
          </view>
        </view>
        <view v-if="c.item" class="item-preview" @click.stop="openItem(c.item.id)">
          <image class="item-cover" :src="resolveCover(c.item.cover)" mode="aspectFill" />
          <view class="item-info">
            <text class="item-title">{{ c.item.title }}</text>
            <text class="item-price">¥{{ c.item.price }}</text>
          </view>
        </view>
      </view>
    </view>

    <view v-else class="empty">
      <text class="empty-title">暂无会话</text>
      <text class="empty-sub">去闲置市场逛逛，和卖家聊聊吧</text>
    </view>
  </view>
</template>

<script setup lang="ts">
import { onShow } from '@dcloudio/uni-app'
import { ref } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { listChatConversations, type ChatConversation } from '@/api/chat'
import { resolveApiAssetUrl } from '@/api/http'
import { formatDate, formatDateTime } from '@/utils/dateTime'

const auth = useAuthStore()
const items = ref<ChatConversation[]>([])
const loading = ref(false)

function resolveCover(id: string | null) { return resolveApiAssetUrl(id) }
function formatTime(s: string | null) {
  if (!s) return ''
  const today = formatDate(new Date().toISOString())
  const day = formatDate(s)
  return day === today ? formatDateTime(s).slice(11) : day
}

async function load() {
  if (!auth.accessToken) return
  loading.value = true
  try {
    const res = await listChatConversations(auth.accessToken)
    items.value = res.items
  } catch { /* ignore */ } finally { loading.value = false }
}

function openChat(id: string) { uni.navigateTo({ url: `/pages/chat-detail/index?id=${id}` }) }
function openItem(id: string) { uni.navigateTo({ url: `/pages/second-hand-detail/index?id=${id}` }) }
function goBack() { uni.navigateBack() }

onShow(() => load())
</script>

<style lang="scss" scoped>
.page { min-height: 100vh; background: #F4F6F4; }
.subnav { height: 88rpx; background: #fff; display: flex; align-items: center; padding: 0 24rpx; position: relative; }
.back { width: 60rpx; font-size: 48rpx; color: #191B1F; }
.nav-title { position: absolute; left: 80rpx; right: 80rpx; text-align: center; font-size: 32rpx; font-weight: 700; color: #191B1F; }
.nav-right { margin-left: auto; width: 80rpx; text-align: right; }
.right-text { font-size: 24rpx; color: #AEB3BB; }
.safety-card { margin: 24rpx; padding: 22rpx 28rpx; background: #E7F9EF; border-radius: 24rpx; display: flex; align-items: center; gap: 18rpx; }
.safety-icon { width: 64rpx; height: 64rpx; border-radius: 20rpx; background: #0BBF63; color: #fff; display: flex; align-items: center; justify-content: center; font-size: 28rpx; flex: none; }
.safety-text { font-size: 24rpx; color: #08A152; line-height: 1.5; }
.card { background: #fff; border-radius: 24rpx; margin: 24rpx; overflow: hidden; }
.msg-row { display: flex; align-items: center; gap: 22rpx; padding: 26rpx 30rpx; }
.msg-row + .msg-row { border-top: 1rpx solid #F5F6F6; }
.av { width: 92rpx; height: 92rpx; border-radius: 50%; background: #eee; flex-shrink: 0; }
.msg-main { flex: 1; min-width: 0; }
.l1 { display: flex; align-items: baseline; justify-content: space-between; }
.name { font-size: 28rpx; font-weight: 700; color: #191B1F; }
.time { font-size: 22rpx; color: #AEB3BB; }
.l2 { display: flex; justify-content: space-between; align-items: center; margin-top: 8rpx; }
.last { font-size: 26rpx; color: #767C85; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 380rpx; }
.badge { background: #FF2442; color: #fff; border-radius: 20rpx; min-width: 36rpx; height: 36rpx; line-height: 36rpx; text-align: center; font-size: 22rpx; padding: 0 10rpx; }
.item-preview { display: flex; gap: 12rpx; align-items: center; border-left: 1rpx solid #EDEFEF; padding-left: 20rpx; flex-shrink: 0; }
.item-cover { width: 72rpx; height: 72rpx; border-radius: 16rpx; background: #eee; }
.item-info { display: flex; flex-direction: column; }
.item-title { font-size: 22rpx; color: #767C85; max-width: 140rpx; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.item-price { font-size: 22rpx; color: #FF2442; font-weight: 700; }
.empty { text-align: center; padding: 160rpx 0; display: flex; flex-direction: column; gap: 12rpx; }
.empty-title { font-size: 28rpx; color: #767C85; }
.empty-sub { font-size: 24rpx; color: #AEB3BB; }
</style>
