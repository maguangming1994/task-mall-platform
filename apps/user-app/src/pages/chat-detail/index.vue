<template>
  <view class="page">
    <view class="nav-bar">
      <view class="back" @click="goBack">‹</view>
      <text class="nav-title">{{ conv?.other_user?.nickname || '聊天' }}</text>
      <view class="nav-right" />
    </view>

    <view v-if="conv?.item" class="item-bar" @click="openItem">
      <image class="item-cover" :src="resolveCover(conv.item.cover)" mode="aspectFill" />
      <view class="item-info">
        <text class="item-title">{{ conv.item.title }}</text>
        <text class="item-price">¥{{ conv.item.price }}</text>
      </view>
      <text class="item-go">查看 ›</text>
    </view>

    <scroll-view scroll-y class="msg-list" :scroll-into-view="scrollToId" scroll-with-animation>
      <view v-for="m in messages" :key="m.id" :id="`msg-${m.id}`" :class="['msg', { mine: m.mine }]">
        <image v-if="!m.mine" class="msg-avatar" :src="resolveCover(conv?.other_user?.avatar ?? null)" mode="aspectFill" />
        <view :class="['bubble', m.type]">{{ m.content }}</view>
        <image v-if="m.mine" class="msg-avatar mine-avatar" :src="myAvatar" mode="aspectFill" />
      </view>
      <view v-if="sending" class="msg mine"><view class="bubble text sending">发送中...</view></view>
    </scroll-view>

    <view class="input-bar">
      <input v-model="input" class="input" placeholder="说点什么..." confirm-type="send" @confirm="send" />
      <view class="send-btn" :class="{ active: input.trim() }" @click="send">发送</view>
    </view>
  </view>
</template>

<script setup lang="ts">
import { onHide, onLoad, onUnload } from '@dcloudio/uni-app'
import { computed, nextTick, onMounted, ref } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { listChatMessages, sendChatMessage, type ChatConversation, type ChatMessage } from '@/api/chat'
import { resolveApiAssetUrl } from '@/api/http'

const auth = useAuthStore()
const convId = ref('')
const conv = ref<ChatConversation | null>(null)
const messages = ref<ChatMessage[]>([])
const input = ref('')
const sending = ref(false)
const scrollToId = ref('')
let timer: number | null = null

const myAvatar = computed(() => resolveApiAssetUrl(auth.user?.avatar_file_id || null))

function resolveCover(id: string | null) { return resolveApiAssetUrl(id) }

onLoad((q) => { convId.value = q?.id || '' })

onMounted(() => {
  load(true)
  startPolling()
})

onHide(() => { stopPolling() })
onUnload(() => { stopPolling() })

function startPolling() {
  stopPolling()
  timer = setInterval(() => load(false), 3000) as unknown as number
}
function stopPolling() { if (timer) { clearInterval(timer); timer = null } }

async function load(full = false) {
  if (!auth.accessToken || !convId.value) return
  try {
    const res = await listChatMessages(auth.accessToken, convId.value, { page: 1, page_size: 50 })
    conv.value = res.conversation as unknown as ChatConversation
    messages.value = res.items
    if (full) scrollBottom()
  } catch { /* ignore */ }
}

function scrollBottom() {
  nextTick(() => {
    const last = messages.value[messages.value.length - 1]
    if (last) scrollToId.value = `msg-${last.id}`
  })
}

async function send() {
  const text = input.value.trim()
  if (!text || !auth.accessToken || !convId.value) return
  sending.value = true
  input.value = ''
  try {
    await sendChatMessage(auth.accessToken, convId.value, { content: text })
    await load(false)
  } catch (e: any) {
    uni.showToast({ title: e.message || '发送失败', icon: 'none' })
  } finally {
    sending.value = false
  }
}

function openItem() { if (conv.value?.item?.id) uni.navigateTo({ url: `/pages/second-hand-detail/index?id=${conv.value.item.id}` }) }
function goBack() { uni.navigateBack() }
</script>

<style lang="scss" scoped>
.page { height: 100vh; display: flex; flex-direction: column; background: #ededed; }
.nav-bar { height: 88rpx; background: #fff; display: flex; align-items: center; padding: 0 24rpx; border-bottom: 1rpx solid #ececee; }
.back { width: 60rpx; font-size: 48rpx; color: #1a1a1b; }
.nav-title { flex: 1; text-align: center; font-size: 32rpx; font-weight: 600; color: #1a1a1b; }
.nav-right { width: 60rpx; }
.item-bar { display: flex; align-items: center; gap: 16rpx; padding: 16rpx 24rpx; background: #fff; border-bottom: 1rpx solid #ececee; }
.item-cover { width: 72rpx; height: 72rpx; border-radius: 8rpx; background: #eee; }
.item-info { flex: 1; }
.item-title { font-size: 26rpx; color: #1a1a1b; display: -webkit-box; -webkit-line-clamp: 1; -webkit-box-orient: vertical; overflow: hidden; }
.item-price { font-size: 26rpx; color: #ff2442; font-weight: 600; }
.item-go { font-size: 24rpx; color: #07c160; }
.msg-list { flex: 1; padding: 24rpx; }
.msg { display: flex; gap: 16rpx; margin-bottom: 28rpx; align-items: flex-start; }
.msg.mine { flex-direction: row-reverse; }
.msg-avatar { width: 64rpx; height: 64rpx; border-radius: 50%; background: #ddd; flex-shrink: 0; }
.bubble { max-width: 480rpx; padding: 18rpx 24rpx; border-radius: 16rpx; font-size: 30rpx; line-height: 1.5; word-break: break-all; }
.bubble.text { background: #fff; color: #1a1a1b; }
.msg.mine .bubble.text { background: #95ec69; }
.bubble.sending { opacity: 0.6; }
.input-bar { display: flex; gap: 16rpx; padding: 16rpx 24rpx; background: #f7f7f7; border-top: 1rpx solid #ececee; padding-bottom: calc(16rpx + env(safe-area-inset-bottom)); }
.input { flex: 1; background: #fff; border-radius: 32rpx; padding: 0 28rpx; height: 72rpx; font-size: 30rpx; }
.send-btn { background: #07c160; color: #fff; padding: 0 32rpx; border-radius: 32rpx; height: 72rpx; line-height: 72rpx; font-size: 28rpx; opacity: 0.5; }
.send-btn.active { opacity: 1; }
</style>
