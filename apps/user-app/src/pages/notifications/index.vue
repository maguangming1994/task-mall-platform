<template>
  <view class="page">
    <view class="heading"><text class="title">消息中心</text><text class="sub">交易、安全和资产消息始终保留站内记录</text></view>
    <view v-if="preference" class="card settings">
      <view class="row"><text>微信通知</text><switch :checked="preference.wechat_enabled" @change="changePreference('wechat_enabled', $event)" /></view>
      <view class="row"><text>短信通知</text><switch :checked="preference.sms_enabled" @change="changePreference('sms_enabled', $event)" /></view>
      <view class="row"><text>营销订阅</text><switch :checked="preference.marketing_subscribed" @change="changePreference('marketing_subscribed', $event)" /></view>
      <text class="meta">免打扰 {{ preference.quiet_hours_start }}～{{ preference.quiet_hours_end }}；支付、退款、提现和安全消息不延迟。</text>
    </view>
    <view v-for="item in items" :key="item.id" class="card notice" :class="{ unread: !item.read_at }" @click="read(item)">
      <view class="row"><text class="notice-title">{{ item.title }}</text><text class="meta">{{ categoryText(item.category) }}</text></view><text class="body">{{ item.body }}</text><text class="meta">{{ item.created_at.replace('T', ' ').slice(0, 16) }}</text>
    </view>
    <text v-if="!items.length" class="empty">暂无消息</text>
  </view>
</template>
<script setup lang="ts">
import { onShow } from '@dcloudio/uni-app'
import { ref } from 'vue'
import { getNotificationPreference, listNotifications, markNotificationRead, updateNotificationPreference, type AppNotification, type NotificationPreference } from '@/api/notifications'
import { useAuthStore } from '@/stores/auth'
const auth = useAuthStore(); const items = ref<AppNotification[]>([]); const preference = ref<NotificationPreference | null>(null)
onShow(load)
async function load() { if (!auth.accessToken) return uni.navigateTo({ url: '/pages/login/index' }); try { items.value = (await listNotifications(auth.accessToken)).items; preference.value = await getNotificationPreference(auth.accessToken) } catch (error) { toast(error) } }
async function read(item: AppNotification) { if (!auth.accessToken || item.read_at) return; try { await markNotificationRead(auth.accessToken, item.id); item.read_at = new Date().toISOString() } catch (error) { toast(error) } }
async function save() { if (!auth.accessToken || !preference.value) return; try { preference.value = await updateNotificationPreference(auth.accessToken, preference.value); uni.showToast({ title: '偏好已保存', icon: 'success' }) } catch (error) { toast(error) } }
function changePreference(key: 'wechat_enabled' | 'sms_enabled' | 'marketing_subscribed', event: Event) { if (!preference.value) return; preference.value[key] = Boolean((event as CustomEvent<{ value: boolean }>).detail.value); void save() }
function categoryText(value: string) { return ({ task: '任务', asset: '资产', order: '订单', after_sale: '售后', community: '社区', system: '系统' } as Record<string, string>)[value] || value }
function toast(error: unknown) { uni.showToast({ title: error instanceof Error ? error.message : '操作失败', icon: 'none' }) }
</script>
<style scoped>
.page{min-height:100vh;padding:32rpx;background:#f5f7fb;box-sizing:border-box}.heading{padding:18rpx 8rpx}.title,.sub,.body,.meta,.empty{display:block}.title{font-size:42rpx;font-weight:750}.sub,.meta{margin-top:8rpx;color:#667085;font-size:24rpx}.card{margin-top:20rpx;padding:28rpx;background:#fff;border-radius:22rpx}.row{display:flex;justify-content:space-between;align-items:center;gap:18rpx}.settings .row{padding:12rpx 0}.notice{border-left:8rpx solid transparent}.notice.unread{border-left-color:#3157d5}.notice-title{font-weight:750}.body{margin:14rpx 0;line-height:1.6}.empty{padding:120rpx;text-align:center;color:#98a2b3}
</style>
