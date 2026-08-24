<template>
  <view :class="['tabbar-shell', { flat: variant === 'flat' }]">
    <view class="tabbar-spacer" />
    <view class="tabbar" :style="barStyle">
      <view
        v-for="(item, index) in navigation.visibleItems"
        :key="item.id"
        class="tabbar-item"
        :class="{ active: item.target === currentTarget, featured: isFeatured(index) }"
        :style="itemStyle(item.target)"
        @click="open(item.target)"
      >
        <view class="tabbar-icon-wrap">
          <image class="tabbar-icon" :src="iconSource(item)" mode="aspectFit" @error="markIconFailed(item)" />
        </view>
        <text class="tabbar-label">{{ item.label }}</text>
      </view>
    </view>
  </view>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { navigationRoutes, type BottomNavigationItem, type NavigationTarget } from '@/api/navigation'
import { useNavigationStore } from '@/stores/navigation'

const props = defineProps<{ currentTarget: NavigationTarget; variant?: 'floating' | 'flat' }>()
const emit = defineEmits<{ compose: [] }>()
const navigation = useNavigationStore()
const failedIcons = ref(new Set<string>())
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
  '--tabbar-active': navigation.config.style.active_color,
  '--tabbar-muted': navigation.config.style.text_color,
  '--tabbar-featured': navigation.config.style.active_color,
}))

const featuredIndex = computed(() => {
  return navigation.visibleItems.findIndex((item) => item.target === 'products')
})

onMounted(() => void navigation.load())

function itemStyle(target: NavigationTarget) {
  return { color: target === props.currentTarget ? navigation.config.style.active_color : navigation.config.style.text_color }
}

function isFeatured(index: number) {
  return index === featuredIndex.value
}

function iconSource(item: BottomNavigationItem) {
  const active = item.target === props.currentTarget
  const key = `${item.id}:${active ? 'active' : 'normal'}`
  if (failedIcons.value.has(key)) return active ? fallbackIcons[item.target].active : fallbackIcons[item.target].normal
  if (active) return item.active_icon_url || fallbackIcons[item.target].active
  return item.icon_url || fallbackIcons[item.target].normal
}

function markIconFailed(item: BottomNavigationItem) {
  const active = item.target === props.currentTarget
  const key = `${item.id}:${active ? 'active' : 'normal'}`
  if (failedIcons.value.has(key)) return
  failedIcons.value = new Set([...failedIcons.value, key])
}

function open(target: NavigationTarget) {
  if (target === 'community' && target === props.currentTarget) {
    emit('compose')
    return
  }
  if (target === props.currentTarget) return
  uni.reLaunch({ url: navigationRoutes[target] })
}
</script>

<style scoped>
.tabbar-spacer{height:calc(138rpx + env(safe-area-inset-bottom))}
.tabbar{position:fixed;z-index:var(--tm-z-bottom-nav,950);right:18rpx;bottom:calc(12rpx + env(safe-area-inset-bottom));left:18rpx;display:flex;align-items:stretch;height:112rpx;padding:10rpx 12rpx;border:1rpx solid;border-color:var(--tabbar-border,#e2e9f0);border-radius:30rpx;background-color:var(--tabbar-background,#fff);box-shadow:var(--tm-shadow-float,0 20rpx 48rpx rgba(31,56,92,.14));box-sizing:border-box;backdrop-filter:blur(18rpx)}
.tabbar-item{position:relative;z-index:0;display:flex;min-width:0;flex:1;align-items:center;justify-content:center;flex-direction:column;gap:5rpx;padding:5rpx 4rpx;color:var(--tabbar-muted,#748099);transition:transform var(--tm-motion-fast,160ms) var(--tm-ease,cubic-bezier(.2,.75,.25,1)),color var(--tm-motion-fast,160ms) ease}
.tabbar-item.active{color:var(--tabbar-active,#12a66a);font-weight:800}
.tabbar-item.active::before{content:'';position:absolute;z-index:-1;inset:2rpx 8rpx;border-radius:22rpx;background:#eef8f2}
.tabbar-icon-wrap{display:flex;align-items:center;justify-content:center;width:48rpx;height:48rpx;border-radius:16rpx;transition:transform var(--tm-motion-fast,160ms) var(--tm-ease,cubic-bezier(.2,.75,.25,1))}
.tabbar-icon{display:block;width:38rpx;height:38rpx}
.tabbar-label{overflow:hidden;max-width:100%;font-size:19rpx;font-weight:700;line-height:1.2;text-overflow:ellipsis;white-space:nowrap}
.tabbar-item.featured{z-index:0;padding:5rpx 4rpx}
.tabbar-item.featured .tabbar-icon-wrap{width:48rpx;height:48rpx;margin-top:0;border:0;border-radius:16rpx;background:transparent;box-shadow:none}
.tabbar-item.featured .tabbar-icon{width:38rpx;height:38rpx}
.tabbar-item.featured .tabbar-label{margin-top:0;font-weight:700}
.tabbar-item.featured.active .tabbar-label{font-weight:800}
.tabbar-item.featured.active::before{display:block}
.tabbar-item.featured:active .tabbar-icon-wrap{transform:scale(.94)}
.tabbar-shell.flat .tabbar-spacer{height:calc(116rpx + env(safe-area-inset-bottom))}
.tabbar-shell.flat .tabbar{right:0;bottom:0;left:0;height:calc(108rpx + env(safe-area-inset-bottom));padding:11rpx 20rpx calc(9rpx + env(safe-area-inset-bottom));border-right:0;border-bottom:0;border-left:0;border-radius:0;background:#fff;box-shadow:0 -8rpx 25rpx rgba(36,62,92,.075);backdrop-filter:none}
.tabbar-shell.flat .tabbar-item{gap:4rpx;padding:4rpx}
.tabbar-shell.flat .tabbar-item.active::before{display:none}
.tabbar-shell.flat .tabbar-icon-wrap,.tabbar-shell.flat .tabbar-item.featured .tabbar-icon-wrap{width:45rpx;height:45rpx}
.tabbar-shell.flat .tabbar-icon,.tabbar-shell.flat .tabbar-item.featured .tabbar-icon{width:36rpx;height:36rpx}
.tabbar-shell.flat .tabbar-label{font-size:18rpx}
@media (prefers-reduced-motion: reduce){.tabbar-item,.tabbar-icon-wrap{transition:none}.tabbar-item:active .tabbar-icon-wrap{transform:none}}
@media screen and (min-width:768px){.tabbar{right:auto;left:50%;width:690rpx;transform:translateX(-50%)}.tabbar-shell.flat .tabbar{right:auto;left:50%;width:750rpx;transform:translateX(-50%)}}

/* Keep navigation readable at the same physical scale across phone widths. */
.tabbar-spacer{height:calc(76px + env(safe-area-inset-bottom))}
.tabbar{right:12px;bottom:calc(8px + env(safe-area-inset-bottom));left:12px;height:64px;padding:6px 8px;border-radius:18px}
.tabbar-item{gap:3px;padding:3px 2px}.tabbar-item.active::before{inset:1px 5px;border-radius:14px}
.tabbar-icon-wrap,.tabbar-item.featured .tabbar-icon-wrap{width:28px;height:28px;border-radius:9px}.tabbar-icon,.tabbar-item.featured .tabbar-icon{width:24px;height:24px}
.tabbar-label,.tabbar-shell.flat .tabbar-label{font-size:13px;line-height:1.2}
.tabbar-shell.flat .tabbar-spacer{height:calc(64px + env(safe-area-inset-bottom))}
.tabbar-shell.flat .tabbar{height:calc(64px + env(safe-area-inset-bottom));padding:6px 10px calc(6px + env(safe-area-inset-bottom))}
.tabbar-shell.flat .tabbar-item{gap:3px;padding:3px 2px}.tabbar-shell.flat .tabbar-icon-wrap,.tabbar-shell.flat .tabbar-item.featured .tabbar-icon-wrap{width:28px;height:28px}.tabbar-shell.flat .tabbar-icon,.tabbar-shell.flat .tabbar-item.featured .tabbar-icon{width:24px;height:24px}
.tabbar-shell.flat .tabbar-item.active::before{display:block;z-index:1;top:-6px;right:28%;bottom:auto;left:28%;height:3px;border-radius:999px;background:var(--tabbar-active,#12a66a)}
@media screen and (min-width:768px){.tabbar{right:auto;left:50%;width:690rpx;transform:translateX(-50%)}.tabbar-shell.flat .tabbar{right:auto;left:50%;width:750rpx;transform:translateX(-50%)}}
</style>
