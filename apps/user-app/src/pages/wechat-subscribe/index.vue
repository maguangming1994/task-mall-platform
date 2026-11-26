<template>
  <view class="page">
    <view class="card">
      <text class="title">公众号订阅提醒</text>
      <text class="desc">订阅后，帖子收到评论、评论收到回复等消息会通过公众号推送给你。一次订阅对应一次通知，可随时再次订阅。</text>

      <view v-if="!externalNotificationsEnabled" class="hint">外部订阅提醒当前未开放，站内消息仍会正常保留。</view>
      <template v-else>
        <!-- #ifdef H5 -->
        <view v-if="loading" class="hint">正在读取可用模板…</view>
        <view v-else-if="!inWechat" class="hint">请在微信中打开本页面完成订阅。</view>
        <view v-else-if="!templates.length" class="hint">公众号订阅模板尚未配置，请联系管理员。</view>
        <view v-else class="subscribe-area">
          <wx-open-subscribe
            v-if="sdkReady"
            :template="templateIds"
            @success="onSubscribed"
            @error="onFailed"
          >
            <button class="subscribe-btn">一键订阅提醒</button>
          </wx-open-subscribe>
          <view v-else class="hint">微信订阅组件加载中…</view>
          <text v-if="message" class="message">{{ message }}</text>
        </view>
        <!-- #endif -->

        <!-- #ifndef H5 -->
        <view class="hint">请在微信公众号内打开本页面完成订阅。</view>
        <!-- #endif -->
      </template>
    </view>
  </view>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue'
import { onLoad } from '@dcloudio/uni-app'
import { getWechatOaSubscriptionTemplates, type WechatOaSubscriptionTemplate } from '@/api/notifications'
import { configureWechatOpenTag } from '@/platform/wechatJssdk'
import { useAuthStore } from '@/stores/auth'
import { navigateToLogin } from '@/utils/authRedirect'
import { useModulesStore } from '@/stores/modules'

const modules = useModulesStore()
const externalNotificationsEnabled = computed(() => modules.externalNotifications)
const auth = useAuthStore()
const loading = ref(true)
const sdkReady = ref(false)
const templates = ref<WechatOaSubscriptionTemplate[]>([])
const message = ref('')
const inWechat = typeof navigator !== 'undefined' && /MicroMessenger/i.test(navigator.userAgent)
const templateIds = computed(() => templates.value.map((item) => item.template_id).join(','))

onLoad(async () => {
  await modules.ensureReady()
  if (!externalNotificationsEnabled.value) {
    loading.value = false
    return
  }
  // #ifndef H5
  loading.value = false
  return
  // #endif
  // #ifdef H5
  if (!auth.isAuthenticated) { navigateToLogin(); loading.value = false; return }
  try {
    const result = await getWechatOaSubscriptionTemplates(auth.accessToken)
    templates.value = result.templates
    if (templates.value.length && inWechat) {
      sdkReady.value = await configureWechatOpenTag(['wx-open-subscribe'])
    }
  } catch (caught) {
    const detail = (caught as { message?: unknown } | null)?.message
    message.value = String(detail || '').trim() || '订阅模板加载失败，请稍后重试。'
  } finally {
    loading.value = false
  }
  // #endif
})

function onSubscribed() {
  message.value = '订阅成功，后续消息会通过公众号提醒你。'
}

function onFailed() {
  message.value = '订阅未完成，请重试；若持续失败，请联系管理员检查公众号模板配置。'
}
</script>

<style scoped>
.page{min-height:100vh;padding:24rpx;background:#F4F6F4}
.card{padding:36rpx 28rpx;border-radius:28rpx;background:#fff;text-align:center}
.title{display:block;color:#191B1F;font-size:34rpx;font-weight:700}
.desc{display:block;margin-top:12rpx;color:#767C85;font-size:24rpx;line-height:1.7;text-align:left}
.hint{display:block;margin-top:32rpx;padding:28rpx;border-radius:20rpx;background:#F7F9F8;color:#767C85;font-size:24rpx;line-height:1.7;text-align:left}
.subscribe-area{margin-top:32rpx}
.subscribe-btn{height:88rpx;border:0;border-radius:44rpx;background:#0BBF63;color:#fff;font-size:30rpx;font-weight:700;line-height:88rpx}
.subscribe-btn::after{border:0}
.message{display:block;margin-top:20rpx;color:#0AA057;font-size:24rpx;text-align:left}
</style>
