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
import { useIconConfigStore } from '@/stores/icons'

const props = defineProps<{ currentTarget: NavigationTarget; variant?: 'floating' | 'flat' }>()
const emit = defineEmits<{ compose: [] }>()
const navigation = useNavigationStore()
const icons = useIconConfigStore()
const failedIcons = ref(new Set<string>())
const fallbackIcons: Record<NavigationTarget, { normal: string; active: string }> = {
  home: { normal: '/static/icons/tabbar-home.svg', active: '/static/icons/tabbar-home-active.svg' },
  tasks: { normal: '/static/icons/tabbar-tasks.svg', active: '/static/icons/tabbar-tasks-active.svg' },
  products: { normal: '/static/icons/tabbar-mall.svg', active: '/static/icons/tabbar-mall-active.svg' },
  community: { normal: '/static/icons/tabbar-community.svg', active: '/static/icons/tabbar-community-active.svg' },
  account: { normal: '/static/icons/tabbar-account.svg', active: '/static/icons/tabbar-account-active.svg' },
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

onMounted(() => {
  void navigation.load()
  void icons.load()
})

function itemStyle(target: NavigationTarget) {
  return { color: target === props.currentTarget ? navigation.config.style.active_color : navigation.config.style.text_color }
}

function isFeatured(index: number) {
  return index === featuredIndex.value
}

function iconSource(item: BottomNavigationItem) {
  const active = item.target === props.currentTarget
  const key = `${item.id}:${active ? 'active' : 'normal'}`
  const localFallback = active ? fallbackIcons[item.target].active : fallbackIcons[item.target].normal
  // 加载失败后直接回退包内图，不再走后台远程配置，避免失败循环。
  if (failedIcons.value.has(key)) return localFallback
  // 优先级：导航装修远程图 > 图标后台配置 > 包内静态图。
  const remote = active ? item.active_icon_url : item.icon_url
  return icons.resolve(remote || localFallback)
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
/* ============ v13 凸起 TabBar ============ */
.tabbar-spacer{height:calc(172rpx + env(safe-area-inset-bottom))}
.tabbar{position:fixed;z-index:var(--tm-z-bottom-nav,950);right:0;bottom:0;left:0;display:flex;align-items:flex-start;height:calc(152rpx + env(safe-area-inset-bottom));padding:16rpx 8rpx 0;border-top:1rpx solid #EDEFEF;background:#fff;box-sizing:border-box}
.tabbar-item{position:relative;z-index:0;display:flex;min-width:0;flex:1;align-items:center;flex-direction:column;gap:4rpx;padding-top:8rpx;color:var(--tabbar-muted,#AEB3BB);transition:color var(--tm-motion-fast,160ms) ease}
.tabbar-item.active{color:var(--tabbar-active,#0BBF63);font-weight:600}
.tabbar-icon-wrap{display:flex;align-items:center;justify-content:center;width:46rpx;height:46rpx;border-radius:16rpx;transition:transform var(--tm-motion-fast,160ms) var(--tm-ease,cubic-bezier(.2,.75,.25,1))}
.tabbar-icon{display:block;width:40rpx;height:40rpx}
.tabbar-label{overflow:hidden;max-width:100%;font-size:20rpx;line-height:1.2;text-overflow:ellipsis;white-space:nowrap}

/* 商城中间项：绿色渐变圆形凸起按钮 */
.tabbar-item.featured{padding-top:0}
.tabbar-item.featured .tabbar-icon-wrap{width:96rpx;height:96rpx;margin-top:-48rpx;border:8rpx solid #fff;border-radius:50%;background:linear-gradient(135deg,#12D978,#08A152);box-shadow:0 12rpx 32rpx rgba(11,191,99,.35)}
.tabbar-item.featured .tabbar-icon{width:48rpx;height:48rpx}
.tabbar-item.featured .tabbar-label{margin-top:4rpx;font-weight:400}
.tabbar-item.featured.active .tabbar-label{font-weight:600}
.tabbar-item.featured:active .tabbar-icon-wrap{transform:scale(.94)}

/* flat 与 v13 底栏形态一致，仅保留兼容入口 */
.tabbar-shell.flat .tabbar-spacer{height:calc(172rpx + env(safe-area-inset-bottom))}
.tabbar-shell.flat .tabbar{right:0;left:0;border-right:0;border-bottom:0;border-left:0;border-radius:0;background:#fff;box-shadow:none;backdrop-filter:none}

@media (prefers-reduced-motion: reduce){.tabbar-item,.tabbar-icon-wrap{transition:none}.tabbar-item:active .tabbar-icon-wrap{transform:none}}
@media screen and (min-width:768px){.tabbar{right:auto;left:50%;width:750rpx;transform:translateX(-50%)}}
</style>
