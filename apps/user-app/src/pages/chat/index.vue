<template>
  <view class="page">
    <ModulePlaceholder v-if="modules.resolved && !moduleEnabled" code="module_second_hand" />
    <view v-else-if="!modules.resolved" class="mp-skeleton">
      <view class="tm-skeleton" style="height: 200rpx; border-radius: 24rpx; margin: 24rpx 28rpx;" />
      <view class="tm-skeleton" style="height: 320rpx; border-radius: 24rpx; margin: 20rpx 28rpx;" />
    </view>
    <template v-else>
    <view class="head"><text class="title">消息</text></view>

    <view v-if="loading && !items.length" class="list">
      <view v-for="i in 3" :key="i" class="conv tm-skeleton" style="height: 140rpx;" />
    </view>
    <view v-else-if="items.length" class="list">
      <view v-for="c in items" :key="c.id" class="conv" @click="openChat(c.id)">
        <image class="avatar" :src="resolveCover(c.other_user?.avatar ?? null)" mode="aspectFill" />
        <view class="main">
          <view class="row1">
            <text class="name">{{ c.other_user?.nickname || '用户' }}</text>
            <text class="time">{{ formatTime(c.last_message_at) }}</text>
          </view>
          <view class="row2">
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
    <view v-else class="empty"><text>暂无会话</text><text class="sub">去闲置市场逛逛，和卖家聊聊吧</text></view>
    </template>
  </view>
</template>

<script setup lang="ts">
import { onShow } from '@dcloudio/uni-app'
import { computed, ref } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { listChatConversations, type ChatConversation } from '@/api/chat'
import { resolveApiAssetUrl } from '@/api/http'
import { formatDate, formatDateTime } from '@/utils/dateTime'
import ModulePlaceholder from '@/components/ModulePlaceholder.vue'
import { useModulesStore } from '@/stores/modules'

const modules = useModulesStore()
const moduleEnabled = computed(() => modules.isEnabled('module_second_hand'))

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
  await modules.ensureReady()
  if (!moduleEnabled.value) return
  if (!auth.accessToken) return
  loading.value = true
  try {
    const res = await listChatConversations(auth.accessToken)
    items.value = res.items
  } catch { /* ignore */ } finally { loading.value = false }
}

function openChat(id: string) { uni.navigateTo({ url: `/pages/chat-detail/index?id=${id}` }) }
function openItem(id: string) { uni.navigateTo({ url: `/pages/second-hand-detail/index?id=${id}` }) }

onShow(load)
</script>

<style lang="scss" scoped>
.page { min-height: 100vh; background: #F4F6F4; }
.head { padding: 32rpx 28rpx 16rpx; background: #fff; }
.title { font-size: 40rpx; font-weight: 800; color: #191B1F; }
.list { margin: 16rpx 24rpx; border-radius: 28rpx; overflow: hidden; background: #fff; box-shadow: 0 12rpx 40rpx rgba(23,26,23,.05); }
.conv { display: flex; gap: 20rpx; padding: 24rpx 28rpx; background: #fff; align-items: center; border-bottom: 1rpx solid #F2F4F3; }
.conv:last-child { border-bottom: 0; }
.avatar { width: 96rpx; height: 96rpx; border-radius: 50%; background: #E7F9EF; flex-shrink: 0; }
.main { flex: 1; min-width: 0; }
.row1 { display: flex; justify-content: space-between; align-items: center; }
.name { font-size: 30rpx; color: #191B1F; font-weight: 700; }
.time { font-size: 22rpx; color: #AEB3BB; }
.row2 { display: flex; justify-content: space-between; align-items: center; margin-top: 10rpx; }
.last { font-size: 26rpx; color: #767C85; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 400rpx; }
.badge { background: #FF3B30; color: #fff; border-radius: 20rpx; min-width: 36rpx; height: 36rpx; line-height: 36rpx; text-align: center; font-size: 22rpx; padding: 0 8rpx; }
.item-preview { display: flex; gap: 12rpx; align-items: center; border-left: 1rpx solid #EDEFEF; padding-left: 16rpx; flex-shrink: 0; }
.item-cover { width: 72rpx; height: 72rpx; border-radius: 12rpx; background: #E7F9EF; }
.item-info { display: flex; flex-direction: column; }
.item-title { font-size: 22rpx; color: #767C85; max-width: 140rpx; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.item-price { font-size: 22rpx; color: #FF3B30; font-weight: 700; }
.empty { text-align: center; padding: 160rpx 0; color: #AEB3BB; font-size: 28rpx; display: flex; flex-direction: column; gap: 12rpx; }
.empty .sub { font-size: 24rpx; }
</style>
