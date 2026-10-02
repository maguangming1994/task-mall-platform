<template>
  <view class="page">
    <view class="subnav">
      <view class="back" @click="goBack">‹</view>
      <text class="nav-title">{{ conv?.other_user?.nickname || '聊天' }}</text>
      <view class="nav-right"><text class="more">•••</text></view>
    </view>

    <view v-if="conv?.item" class="chat-ctx" @click="openItem">
      <image class="ctx-cover" :src="resolveCover(conv.item.cover)" mode="aspectFill" />
      <view class="ctx-info">
        <text class="ctx-title">{{ conv.item.title }}</text>
        <text class="ctx-price">¥{{ conv.item.price }}</text>
      </view>
      <view class="ctx-btn">立即购买</view>
    </view>

    <scroll-view scroll-y class="chat-wrap" :scroll-into-view="scrollToId" scroll-with-animation>
      <text class="bubble-time">今天 {{ todayTime }}</text>
      <view v-for="m in messages" :key="m.id" :id="`msg-${m.id}`" :class="['bubble', { me: m.mine }]">{{ m.content }}</view>
      <view v-if="sending" class="bubble me sending">发送中...</view>
      <view class="chat-tip">
        <text class="tip-icon">✓</text>
        <text>交易提醒：请勿脱离平台私下转账，托管支付更安心</text>
      </view>
    </scroll-view>

    <scroll-view scroll-x class="quick-chips" :show-scrollbar="false">
      <text v-for="p in quickPhrases" :key="p" class="chip" @click="applyPhrase(p)">{{ p }}</text>
    </scroll-view>

    <view class="chat-bar">
      <view class="plus">＋</view>
      <input v-model="input" class="input" placeholder="说点什么…" confirm-type="send" @confirm="send" />
      <view class="send" @click="send">↑</view>
    </view>
  </view>
</template>

<script setup lang="ts">
import { onHide, onLoad, onShow, onUnload } from '@dcloudio/uni-app'
import { computed, nextTick, onMounted, ref } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { listChatMessages, sendChatMessage, type ChatConversation, type ChatMessage } from '@/api/chat'
import { resolveApiAssetUrl } from '@/api/http'
import { formatDateTime } from '@/utils/dateTime'
import { getReverbClient } from '@/utils/reverb'

const auth = useAuthStore()
const convId = ref('')
const conv = ref<ChatConversation | null>(null)
const messages = ref<ChatMessage[]>([])
const input = ref('')
const sending = ref(false)
const scrollToId = ref('')
let timer: number | null = null
let reverbOff: (() => void) | null = null

const myAvatar = computed(() => resolveApiAssetUrl(auth.user?.avatar_file_id || null))
const todayTime = computed(() => formatDateTime(new Date().toISOString()).slice(11, 16))
const quickPhrases = ['还在吗？', '能刀一点吗？', '什么时候方便面交？', '可以看看实物吗？']

function applyPhrase(p: string) { input.value = p; send() }

function resolveCover(id: string | null) { return resolveApiAssetUrl(id) }

onLoad((q) => { convId.value = q?.id || '' })

onMounted(async () => {
  await load(true)
  startPolling()
  startRealtime()
})

onShow(() => { startRealtime() })
onHide(() => { stopPolling(); stopRealtime() })
onUnload(() => { stopPolling(); stopRealtime() })

function startPolling() {
  stopPolling()
  // 兜底：WebSocket 失效时仍然每 3s 轮询一次
  timer = setInterval(() => load(false), 3000) as unknown as number
}
function stopPolling() { if (timer) { clearInterval(timer); timer = null } }

function startRealtime() {
  if (!auth.accessToken || !convId.value) return
  stopRealtime()
  const client = getReverbClient(() => auth.accessToken)
  reverbOff = client.onMessage((payload) => {
    if (payload.conversation.id !== convId.value) return
    const msg = payload.message
    if (messages.value.some((m) => m.id === msg.id)) return
    messages.value.push({
      id: msg.id,
      sender_id: msg.sender_id,
      mine: msg.sender_id === auth.user?.id,
      type: msg.type,
      content: msg.content,
      created_at: msg.created_at,
    })
    scrollBottom()
  })
  client.connect(convId.value)
}

function stopRealtime() {
  if (reverbOff) { reverbOff(); reverbOff = null }
}

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
.page { height: 100vh; display: flex; flex-direction: column; background: #F4F6F4; }
.subnav { height: 88rpx; background: #fff; display: flex; align-items: center; padding: 0 24rpx; position: relative; }
.back { width: 60rpx; font-size: 48rpx; color: #191B1F; }
.nav-title { position: absolute; left: 120rpx; right: 120rpx; text-align: center; font-size: 32rpx; font-weight: 700; color: #191B1F; }
.nav-right { margin-left: auto; width: 60rpx; text-align: right; }
.more { font-size: 36rpx; color: #191B1F; letter-spacing: 2rpx; }
.chat-ctx { display: flex; align-items: center; gap: 18rpx; background: #fff; margin: 16rpx 24rpx 0; border-radius: 24rpx; padding: 16rpx 20rpx; box-shadow: 0 4rpx 16rpx rgba(23,26,23,.04); }
.ctx-cover { width: 84rpx; height: 84rpx; border-radius: 16rpx; background: #eee; flex: none; }
.ctx-info { flex: 1; min-width: 0; }
.ctx-title { font-size: 24rpx; color: #191B1F; display: block; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.ctx-price { font-size: 26rpx; color: #FF2442; font-weight: 800; margin-top: 4rpx; display: inline-block; }
.ctx-btn { height: 54rpx; padding: 0 24rpx; border-radius: 27rpx; background: #E7F9EF; color: #08A152; font-size: 22rpx; font-weight: 700; display: flex; align-items: center; flex: none; }
.chat-wrap { flex: 1; padding: 28rpx 24rpx 12rpx; display: flex; flex-direction: column; box-sizing: border-box; }
.bubble-time { align-self: center; font-size: 20rpx; color: #AEB3BB; background: #E9ECE9; border-radius: 18rpx; padding: 6rpx 18rpx; margin-bottom: 22rpx; }
.bubble { max-width: 72%; background: #fff; border-radius: 8rpx 28rpx 28rpx 28rpx; padding: 20rpx 24rpx; font-size: 27rpx; line-height: 1.55; color: #191B1F; box-shadow: 0 2rpx 4rpx rgba(23,26,23,.04); align-self: flex-start; width: fit-content; margin-bottom: 22rpx; word-break: break-all; }
.bubble.me { background: linear-gradient(135deg,#12D978,#08A152); color: #fff; border-radius: 28rpx 8rpx 28rpx 28rpx; align-self: flex-end; box-shadow: 0 10rpx 24rpx rgba(11,191,99,.25); }
.bubble.sending { opacity: .6; }
.chat-tip { align-self: center; display: flex; align-items: center; gap: 10rpx; font-size: 20rpx; color: #AEB3BB; background: #F0F2F0; border-radius: 18rpx; padding: 10rpx 20rpx; margin-top: 4rpx; }
.tip-icon { width: 24rpx; height: 24rpx; border-radius: 50%; background: #0BBF63; color: #fff; font-size: 16rpx; display: flex; align-items: center; justify-content: center; }
.quick-chips { display: flex; gap: 16rpx; padding: 12rpx 24rpx 16rpx; white-space: nowrap; }
.chip { flex: none; height: 60rpx; padding: 0 26rpx; border-radius: 30rpx; background: #fff; border: 1rpx solid #EDEFEF; font-size: 24rpx; color: #767C85; display: inline-flex; align-items: center; margin-right: 16rpx; }
.chat-bar { background: #fff; border-top: 1rpx solid #EDEFEF; display: flex; align-items: center; gap: 18rpx; padding: 18rpx 24rpx calc(28rpx + env(safe-area-inset-bottom)); }
.plus { width: 68rpx; height: 68rpx; border-radius: 50%; background: #F2F4F3; display: flex; align-items: center; justify-content: center; color: #767C85; font-size: 36rpx; flex: none; }
.input { flex: 1; height: 72rpx; border-radius: 36rpx; background: #F2F4F3; padding: 0 28rpx; font-size: 26rpx; color: #191B1F; }
.send { width: 76rpx; height: 76rpx; border-radius: 50%; background: linear-gradient(135deg,#12D978,#08A152); display: flex; align-items: center; justify-content: center; color: #fff; font-size: 32rpx; box-shadow: 0 10rpx 24rpx rgba(11,191,99,.3); flex: none; }
</style>
