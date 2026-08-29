<template>
  <view class="campus-selector">
    <view class="campus-trigger" role="button" aria-label="选择学校" @click="openSelector">
      <view class="campus-pin"><view /></view>
      <text>{{ campus.selected?.name || '选择学校' }}</text>
      <view class="campus-chevron" />
    </view>

    <view v-if="visible" class="campus-mask" @click.self="visible = false">
      <view class="campus-sheet">
        <view class="campus-sheet-handle" />
        <view class="campus-sheet-head">
          <view>
            <text class="campus-sheet-title">选择学校</text>
            <text class="campus-sheet-note">任务、商城和社区内容将按学校展示</text>
          </view>
          <view class="campus-close" role="button" aria-label="关闭" @click="visible = false">×</view>
        </view>

        <view class="campus-search">
          <view class="campus-search-icon" />
          <input v-model="keyword" confirm-type="search" placeholder="搜索学校、城市或地区" @confirm="search" />
          <text v-if="keyword" @click="keyword = ''; search()">清除</text>
        </view>

        <button class="campus-locate" :loading="locating" @click="locate">
          <view class="campus-locate-icon"><view /></view>
          <view><text>定位附近学校</text><small>仅用于计算距离，不保存精确位置</small></view>
          <text>›</text>
        </button>

        <scroll-view class="campus-list" scroll-y>
          <view v-if="campus.loading" class="campus-state">正在加载学校…</view>
          <view v-else-if="!campus.items.length" class="campus-state">没有找到匹配的学校</view>
          <view v-for="item in campus.items" v-else :key="item.id" class="campus-option" :class="{ active: campus.selected?.id === item.id }" @click="choose(item)">
            <view class="campus-option-mark">{{ item.name.slice(0, 1) }}</view>
            <view class="campus-option-copy">
              <text>{{ item.name }}</text>
              <small>{{ locationText(item) }}</small>
            </view>
            <text v-if="item.distance_km !== null" class="campus-distance">{{ item.distance_km }} km</text>
            <view v-if="campus.selected?.id === item.id" class="campus-check">✓</view>
          </view>
        </scroll-view>
      </view>
    </view>
  </view>
</template>

<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'
import type { Campus } from '@/api/campuses'
import { useCampusStore } from '@/stores/campus'

const props = defineProps<{ accessToken?: string; accountCampus?: Campus | null }>()
const emit = defineEmits<{ change: [campus: Campus] }>()
const campus = useCampusStore()
const visible = ref(false)
const keyword = ref('')
const locating = ref(false)

onMounted(async () => {
  campus.syncFromAccount(props.accountCampus)
  if (!campus.items.length) await campus.load().catch(() => undefined)
})

watch(() => props.accountCampus, (value) => campus.syncFromAccount(value))

async function openSelector() {
  visible.value = true
  if (!campus.items.length) await campus.load().catch(() => uni.showToast({ title: '学校列表加载失败', icon: 'none' }))
}

async function search() {
  await campus.load({ keyword: keyword.value.trim() }).catch(() => uni.showToast({ title: '搜索失败，请稍后重试', icon: 'none' }))
}

async function locate() {
  locating.value = true
  try {
    const position = await new Promise<UniApp.GetLocationSuccess>((resolve, reject) => {
      uni.getLocation({ type: 'gcj02', success: resolve, fail: reject })
    })
    const items = await campus.load({ latitude: position.latitude, longitude: position.longitude })
    if (items[0]?.distance_km !== null) uni.showToast({ title: `已找到附近学校`, icon: 'none' })
  } catch {
    uni.showModal({ title: '无法获取位置', content: '请在系统设置中允许位置权限，或直接搜索学校名称。', showCancel: false })
  } finally {
    locating.value = false
  }
}

async function choose(item: Campus) {
  try {
    await campus.select(item, props.accessToken || '')
    visible.value = false
    emit('change', item)
    uni.showToast({ title: `已切换到${item.name}`, icon: 'none' })
  } catch {
    uni.showToast({ title: '账号学校同步失败，请重试', icon: 'none' })
  }
}

function locationText(item: Campus) {
  return [item.province, item.city, item.district].filter(Boolean).join(' · ') || item.address || '学校地址待完善'
}
</script>

<style scoped>
.campus-selector{position:relative;z-index:60}.campus-trigger{display:inline-flex;max-width:440rpx;height:66rpx;align-items:center;gap:13rpx;padding:0 20rpx;border:1rpx solid rgba(16,33,58,.12);border-radius:22rpx;background:rgba(255,255,255,.94);box-shadow:0 8rpx 24rpx rgba(16,33,58,.08)}.campus-trigger>text{min-width:0;overflow:hidden;color:var(--tm-ink-900);font-size:15px;font-weight:850;text-overflow:ellipsis;white-space:nowrap}.campus-pin{position:relative;width:25rpx;height:30rpx;flex:none;border:4rpx solid var(--tm-growth-600);border-radius:50% 50% 50% 0;transform:rotate(-45deg)}.campus-pin view{position:absolute;top:6rpx;left:6rpx;width:7rpx;height:7rpx;border-radius:50%;background:var(--tm-growth-600)}.campus-chevron{width:12rpx;height:12rpx;flex:none;border-right:3rpx solid #6e7b8c;border-bottom:3rpx solid #6e7b8c;transform:rotate(45deg) translateY(-3rpx)}
.campus-mask{position:fixed;z-index:1300;inset:0;display:flex;align-items:flex-end;background:rgba(9,25,43,.48);backdrop-filter:blur(5px)}.campus-sheet{width:100%;max-height:82vh;padding:13rpx 28rpx calc(28rpx + env(safe-area-inset-bottom));border-radius:34rpx 34rpx 0 0;background:#f8fbfd;box-shadow:0 -20rpx 60rpx rgba(9,25,43,.18)}.campus-sheet-handle{width:72rpx;height:8rpx;margin:0 auto 20rpx;border-radius:999rpx;background:#cfd8e2}.campus-sheet-head{display:flex;align-items:center;justify-content:space-between}.campus-sheet-head>view:first-child text{display:block}.campus-sheet-title{font-size:22px;font-weight:950}.campus-sheet-note{margin-top:6rpx;color:var(--tm-ink-500);font-size:13px}.campus-close{display:flex;width:58rpx;height:58rpx;align-items:center;justify-content:center;border:1rpx solid var(--tm-line);border-radius:50%;background:#fff;color:#5f6e80;font-size:25px}
.campus-search{display:flex;height:82rpx;align-items:center;gap:14rpx;margin-top:24rpx;padding:0 20rpx;border:1rpx solid #d9e4ec;border-radius:20rpx;background:#fff}.campus-search input{min-width:0;flex:1;font-size:15px}.campus-search>text{color:var(--tm-growth-700);font-size:13px;font-weight:800}.campus-search-icon{width:23rpx;height:23rpx;border:4rpx solid #6f8193;border-radius:50%}.campus-search-icon::after{content:'';display:block;width:12rpx;height:4rpx;margin:18rpx 0 0 17rpx;border-radius:4rpx;background:#6f8193;transform:rotate(45deg)}
.campus-locate{display:flex;height:auto;min-height:100rpx;align-items:center;gap:18rpx;margin:18rpx 0 0;padding:17rpx 20rpx;border:1rpx solid #cde9dd;border-radius:22rpx;background:#ecfaf4;color:var(--tm-ink-900);line-height:1.2;text-align:left}.campus-locate::after{border:0}.campus-locate>view:nth-child(2){min-width:0;flex:1}.campus-locate text,.campus-locate small{display:block}.campus-locate view>text{font-size:15px;font-weight:850}.campus-locate small{margin-top:7rpx;color:#5e766c;font-size:11px}.campus-locate>text{color:var(--tm-growth-700);font-size:24px}.campus-locate-icon{position:relative;width:52rpx;height:52rpx;flex:none;border:4rpx solid var(--tm-growth-600);border-radius:50%}.campus-locate-icon::before,.campus-locate-icon::after{content:'';position:absolute;background:var(--tm-growth-600)}.campus-locate-icon::before{top:-10rpx;bottom:-10rpx;left:22rpx;width:4rpx}.campus-locate-icon::after{top:22rpx;right:-10rpx;left:-10rpx;height:4rpx}.campus-locate-icon view{position:absolute;z-index:2;inset:14rpx;border-radius:50%;background:var(--tm-growth-600)}
.campus-list{max-height:50vh;margin-top:15rpx}.campus-option{display:flex;min-height:92rpx;align-items:center;gap:16rpx;margin-top:11rpx;padding:14rpx 18rpx;border:1rpx solid #e1e8ee;border-radius:20rpx;background:#fff}.campus-option.active{border-color:#6bc7a2;background:#effbf5}.campus-option-mark{display:flex;width:54rpx;height:54rpx;align-items:center;justify-content:center;flex:none;border-radius:17rpx;background:var(--tm-brand-100);color:var(--tm-brand-700);font-size:16px;font-weight:950}.campus-option-copy{min-width:0;flex:1}.campus-option-copy text,.campus-option-copy small{display:block}.campus-option-copy text{font-size:15px;font-weight:850}.campus-option-copy small{overflow:hidden;margin-top:5rpx;color:var(--tm-ink-500);font-size:12px;text-overflow:ellipsis;white-space:nowrap}.campus-distance{flex:none;color:var(--tm-growth-700);font-size:12px}.campus-check{display:flex;width:38rpx;height:38rpx;align-items:center;justify-content:center;flex:none;border-radius:50%;background:var(--tm-growth-600);color:#fff;font-size:13px;font-weight:900}.campus-state{padding:80rpx 20rpx;color:var(--tm-ink-500);font-size:14px;text-align:center}
</style>
