<template>
  <view class="tabbar-shell">
    <view class="tabbar-spacer" />
    <view class="tabbar" :style="barStyle">
      <view
        v-for="item in navigation.visibleItems"
        :key="item.id"
        class="tabbar-item"
        :class="{ active: item.target === currentTarget }"
        :style="itemStyle(item.target)"
        @click="open(item.target)"
      >
        <view v-if="item.target === currentTarget" class="active-line" :style="{ backgroundColor: navigation.config.style.active_color }" />
        <image class="tabbar-icon" :src="iconSource(item)" mode="aspectFit" />
        <text class="tabbar-label">{{ item.label }}</text>
      </view>
    </view>
  </view>
</template>

<script setup lang="ts">
import { computed, onMounted } from 'vue'
import { navigationRoutes, type BottomNavigationItem, type NavigationTarget } from '@/api/navigation'
import { useNavigationStore } from '@/stores/navigation'

const props = defineProps<{ currentTarget: NavigationTarget }>()
const navigation = useNavigationStore()
const fallbackIcons: Record<NavigationTarget, { normal: string; active: string }> = {
  home: { normal: '/static/tabbar/home.png', active: '/static/tabbar/home-active.png' },
  tasks: { normal: '/static/tabbar/tasks.png', active: '/static/tabbar/tasks-active.png' },
  products: { normal: '/static/tabbar/mall.png', active: '/static/tabbar/mall-active.png' },
  community: { normal: '/static/tabbar/community.png', active: '/static/tabbar/community-active.png' },
  account: { normal: '/static/tabbar/account.png', active: '/static/tabbar/account-active.png' },
}

const barStyle = computed(() => ({
  backgroundColor: navigation.config.style.background_color,
  borderColor: navigation.config.style.border_color,
}))

onMounted(() => void navigation.load())

function itemStyle(target: NavigationTarget) {
  return { color: target === props.currentTarget ? navigation.config.style.active_color : navigation.config.style.text_color }
}

function iconSource(item: BottomNavigationItem) {
  if (item.target === props.currentTarget) return item.active_icon_url || fallbackIcons[item.target].active
  return item.icon_url || fallbackIcons[item.target].normal
}

function open(target: NavigationTarget) {
  if (target === props.currentTarget) return
  uni.reLaunch({ url: navigationRoutes[target] })
}
</script>

<style scoped>
.tabbar-spacer{height:calc(108rpx + env(safe-area-inset-bottom))}
.tabbar{position:fixed;z-index:950;left:0;right:0;bottom:0;display:flex;align-items:stretch;height:108rpx;padding-bottom:env(safe-area-inset-bottom);border-top:1rpx solid;background:rgba(255,255,255,.98);box-shadow:0 -10rpx 30rpx rgba(29,43,73,.06)}
.tabbar-item{position:relative;display:flex;min-width:0;flex:1;align-items:center;justify-content:center;flex-direction:column;gap:5rpx;padding:11rpx 4rpx 8rpx}
.active-line{position:absolute;top:0;left:50%;width:36rpx;height:5rpx;border-radius:0 0 6rpx 6rpx;transform:translateX(-50%)}
.tabbar-icon{display:block;width:44rpx;height:44rpx}
.tabbar-label{overflow:hidden;max-width:100%;font-size:20rpx;font-weight:650;line-height:1.2;text-overflow:ellipsis;white-space:nowrap}
@media screen and (min-width:768px){.tabbar{left:50%;width:750rpx;box-sizing:border-box;transform:translateX(-50%)}}
</style>
