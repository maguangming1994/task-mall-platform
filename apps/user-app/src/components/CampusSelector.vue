<template>
  <view class="campus-selector">
    <view class="campus-access-bar">
      <view class="campus-trigger" role="button" aria-label="搜索并选择当前学校" @click="openCampusSelector">
        <template v-if="campus.selected">
          <view :class="['campus-brand-mark', 'campus-trigger-mark', campusFallbackTone(campus.selected)]">
            <image
              v-if="showCampusEmblem(campus.selected)"
              class="campus-brand-image"
              :src="campusEmblemSource(campus.selected)"
              mode="aspectFit"
              @error="handleCampusEmblemError(campus.selected)"
            />
            <text v-else>{{ campusInitials(campus.selected.name) }}</text>
          </view>
        </template>
        <view v-else class="campus-pin"><view /></view>
        <text class="campus-trigger-name">{{ campus.selected?.name || '选择学校' }}</text>
        <view class="campus-chevron" />
      </view>
    </view>

    <view v-if="visible" class="campus-mask" @click.self="closeSelector" @touchmove.stop.prevent>
      <view class="campus-sheet" :style="sheetStyle" @touchmove.stop.prevent>
        <view class="campus-sheet-handle" />
        <view class="campus-sheet-head">
          <view>
            <text class="campus-sheet-title">选择学校</text>
            <text class="campus-sheet-note">任务、商城和社区内容将按学校展示</text>
          </view>
          <view class="campus-close" role="button" aria-label="关闭" @click="closeSelector">×</view>
        </view>

        <view class="campus-search">
          <view class="campus-search-icon" />
          <input
            v-model="keyword"
            :focus="searchFocused"
            :adjust-position="false"
            :cursor-spacing="16"
            confirm-type="search"
            placeholder="搜索学校、城市或地区"
            @blur="searchFocused = false"
            @confirm="search"
          />
          <text v-if="keyword" @click="clearSearch">清除</text>
        </view>

        <button class="campus-locate" :disabled="locating" :loading="locating" @click="locate">
          <view class="campus-locate-icon"><view /></view>
          <view><text>定位附近学校</text><text class="campus-locate-note">仅用于计算距离，不保存精确位置</text></view>
          <text>›</text>
        </button>

        <scroll-view class="campus-list" scroll-y @touchmove.stop>
          <view v-if="campus.loading" class="campus-state">
            <text class="campus-state-title">正在加载学校…</text>
          </view>
          <view v-else-if="campus.error" class="campus-state campus-state-error">
            <text class="campus-state-title">学校列表加载失败</text>
            <text class="campus-state-detail">{{ campus.error }}</text>
            <button class="campus-state-action" @click="retryLoad">重新加载</button>
          </view>
          <view v-else-if="!campus.items.length" class="campus-state">
            <text class="campus-state-title">{{ emptyStateTitle }}</text>
            <text class="campus-state-detail">{{ emptyStateDetail }}</text>
            <button v-if="loadMode === 'search'" class="campus-state-action" @click="clearSearch">查看全部学校</button>
          </view>
          <view v-else class="campus-list-content">
            <view v-for="item in campus.items" :key="item.id" class="campus-option" :class="{ active: campus.selected?.id === item.id, pending: selectingId === item.id }" @click="choose(item)">
              <view :class="['campus-brand-mark', 'campus-option-mark', campusFallbackTone(item)]">
                <image
                  v-if="showCampusEmblem(item)"
                  class="campus-brand-image"
                  :src="campusEmblemSource(item)"
                  mode="aspectFit"
                  @error="handleCampusEmblemError(item)"
                />
                <text v-else>{{ campusInitials(item.name) }}</text>
              </view>
              <view class="campus-option-copy">
                <text>{{ item.name }}</text>
                <text class="campus-option-location">{{ locationText(item) }}</text>
              </view>
              <text v-if="distanceText(item)" class="campus-distance">{{ distanceText(item) }}</text>
              <view v-if="campus.selected?.id === item.id" class="campus-check">✓</view>
            </view>
          </view>
        </scroll-view>
      </view>
    </view>
  </view>
</template>

<script setup lang="ts">
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue'
import type { Campus } from '@/api/campuses'
import { useCampusStore } from '@/stores/campus'

const props = defineProps<{ accessToken?: string; accountCampus?: Campus | null }>()
const emit = defineEmits<{ change: [campus: Campus] }>()
const campus = useCampusStore()
const visible = ref(false)
const searchFocused = ref(false)
const keyword = ref('')
const locating = ref(false)
const selectingId = ref('')
const failedEmblems = ref<Record<string, boolean>>({})
const loadMode = ref<'all' | 'search' | 'location'>('all')
const keyboardHeight = ref(0)

watch(visible, (v) => {
  if (v) {
    // 阻止底层页面滚动
    uni.pageScrollTo({ scrollTop: 0, duration: 0 })
  }
})

const sheetStyle = computed(() => {
  if (!keyboardHeight.value) return {}
  return {
    marginBottom: `${keyboardHeight.value}px`,
    maxHeight: `calc(82vh - ${keyboardHeight.value}px)`,
  }
})

function onKeyboardChange(res: { height: number }) {
  keyboardHeight.value = res.height || 0
}

const fallbackSeparators = /[\s\-_.·/]+/

function campusEmblemSource(item: Campus) {
  return [item.emblem_url?.trim(), item.logo_url?.trim()]
    .find(source => source && !failedEmblems.value[`${item.id}:${source}`]) || ''
}

function showCampusEmblem(item: Campus) {
  const source = campusEmblemSource(item)
  return Boolean(source)
}

function handleCampusEmblemError(item: Campus) {
  const source = campusEmblemSource(item)
  if (!source) return
  failedEmblems.value = { ...failedEmblems.value, [`${item.id}:${source}`]: true }
}

function campusInitials(name: string) {
  const normalized = name.trim()
  if (!normalized) return '校'
  const words = normalized.split(fallbackSeparators).filter(Boolean)
  if (words.length > 1 && words.every(word => /^[a-z0-9]+$/i.test(word))) {
    return words.slice(0, 2).map(word => word.charAt(0)).join('').toUpperCase()
  }
  return Array.from(normalized.split(fallbackSeparators).join('')).slice(0, 2).join('').toUpperCase()
}

function campusFallbackTone(item: Campus) {
  const seed = `${item.id}:${item.code}:${item.name}`
  let hash = 0
  for (let index = 0; index < seed.length; index += 1) {
    hash = (hash * 31 + seed.charCodeAt(index)) >>> 0
  }
  return `tone-${hash % 6}`
}

const emptyStateTitle = computed(() => {
  if (loadMode.value === 'location') return '定位成功，但附近暂无可选学校'
  if (loadMode.value === 'search') return '没有找到匹配的学校'
  return '暂无可选学校'
})

const emptyStateDetail = computed(() => {
  if (loadMode.value === 'location') return '可以尝试搜索学校、城市或地区名称。'
  if (loadMode.value === 'search') return keyword.value.trim()
    ? `没有与“${keyword.value.trim()}”相关的结果。`
    : '可以尝试输入学校、城市或地区名称。'
  return '学校数据可能尚未启用，请稍后重试。'
})

onMounted(async () => {
  campus.syncFromAccount(props.accountCampus)
  if (!campus.items.length && !campus.loading) {
    await campus.load().catch(() => {
      uni.showToast({ title: '学校列表加载失败，请点击重试', icon: 'none' })
    })
  }
})

watch(() => props.accountCampus, (value) => campus.syncFromAccount(value))

async function openCampusSelector() {
  await openSelector(true)
}

async function openSelector(focusSearch: boolean) {
  visible.value = true
  searchFocused.value = false
  keyboardHeight.value = 0
  uni.onKeyboardHeightChange(onKeyboardChange)
  await nextTick()
  searchFocused.value = focusSearch
  if (!campus.items.length && !campus.loading && !campus.error) {
    await campus.load().catch(() => uni.showToast({ title: '学校列表加载失败，请点击重试', icon: 'none' }))
  }
}

function closeSelector() {
  searchFocused.value = false
  visible.value = false
  keyboardHeight.value = 0
  uni.offKeyboardHeightChange(onKeyboardChange)
}

onUnmounted(() => {
  uni.offKeyboardHeightChange(onKeyboardChange)
})

async function search() {
  const query = keyword.value.trim()
  loadMode.value = query ? 'search' : 'all'
  await campus.load(query ? { keyword: query } : {}).catch(() => {
    uni.showToast({ title: '搜索失败，请点击重试', icon: 'none' })
  })
}

async function clearSearch() {
  keyword.value = ''
  await search()
}

async function retryLoad() {
  try {
    const items = await campus.retry()
    if (loadMode.value === 'location') showLocationResult(items)
  } catch {
    uni.showToast({ title: '仍然无法加载，请检查网络', icon: 'none' })
  }
}

async function locate() {
  if (locating.value) return
  locating.value = true
  let position: UniApp.GetLocationSuccess
  try {
    position = await new Promise<UniApp.GetLocationSuccess>((resolve, reject) => {
      uni.getLocation({ type: 'wgs84', success: resolve, fail: reject })
    })
  } catch (error) {
    if (isLocationPermissionDenied(error)) {
      promptLocationPermission()
    } else {
      uni.showModal({
        title: '暂时无法定位',
        content: '未能获取当前位置，请检查系统定位服务和网络，或直接搜索学校名称。',
        showCancel: false,
      })
    }
    locating.value = false
    return
  }

  loadMode.value = 'location'
  keyword.value = ''
  try {
    const items = await campus.loadNearby({ latitude: position.latitude, longitude: position.longitude })
    showLocationResult(items)
  } catch {
    uni.showToast({ title: '位置已获取，但学校列表加载失败', icon: 'none' })
  } finally {
    locating.value = false
  }
}

async function choose(item: Campus) {
  if (selectingId.value) return
  selectingId.value = item.id
  try {
    const selected = await campus.select(item, props.accessToken || '')
    closeSelector()
    emit('change', selected)
    uni.showToast({ title: `已切换到${selected.name}`, icon: 'none' })
  } catch {
    uni.showToast({ title: '账号学校同步失败，请重试', icon: 'none' })
  } finally {
    selectingId.value = ''
  }
}

function showLocationResult(items: Campus[]) {
  if (!items.length) {
    uni.showToast({ title: '定位成功，但附近暂无可选学校', icon: 'none' })
    return
  }
  if (!items.some(hasComparableDistance)) {
    uni.showToast({ title: '定位成功，但学校坐标尚未完善', icon: 'none' })
    return
  }
  uni.showToast({ title: '已按距离列出附近学校', icon: 'none' })
}

function hasComparableDistance(item: Campus) {
  return typeof item.distance_km === 'number' && Number.isFinite(item.distance_km)
    && ['campus', 'reference'].includes(normalizedAccuracy(item))
}

function normalizedAccuracy(item: Campus) {
  if (item.location_accuracy === 'campus' || item.location_accuracy === 'reference' || item.location_accuracy === 'city' || item.location_accuracy === 'unavailable') {
    return item.location_accuracy
  }
  return item.distance_km === null ? 'unavailable' : 'campus'
}

function distanceText(item: Campus) {
  if (normalizedAccuracy(item) === 'city') return '\u540c\u57ce\u5019\u9009'
  if (!hasComparableDistance(item)) return ''
  const prefix = normalizedAccuracy(item) === 'reference' ? '约 ' : ''
  return `${prefix}${item.distance_km} km`
}

function isLocationPermissionDenied(error: unknown) {
  const message = typeof error === 'object' && error !== null && 'errMsg' in error
    ? String(error.errMsg).toLowerCase()
    : error instanceof Error ? error.message.toLowerCase() : ''
  return message.includes('auth deny')
    || message.includes('auth denied')
    || message.includes('authorize denied')
    || message.includes('user denied')
    || /permission\s*(is\s*)?(deny|denied)/.test(message)
}

function promptLocationPermission() {
  uni.showModal({
    title: '需要位置权限',
    content: '请允许获取位置以查找附近学校，也可以暂不授权并直接搜索学校。',
    confirmText: '去设置',
    cancelText: '暂不',
    success(result) {
      if (!result.confirm) return
      uni.openSetting({
        success(settings) {
          const enabled = settings.authSetting?.['scope.userLocation']
          uni.showToast({
            title: enabled ? '位置权限已开启，请重新定位' : '位置权限仍未开启',
            icon: 'none',
          })
        },
        fail() {
          uni.showToast({ title: '无法打开设置，请在系统设置中授权', icon: 'none' })
        },
      })
    },
  })
}

function locationText(item: Campus) {
  if (item.location_accuracy === 'reference') {
    return [item.location_name !== item.name ? item.location_name : '', item.address].filter(Boolean).join(' · ')
      || '公开校址参考位置'
  }
  return [item.province, item.city, item.district].filter(Boolean).join(' · ') || item.address || '学校地址待完善'
}
</script>

<style scoped>
.campus-selector{position:relative;width:100%;box-sizing:border-box}.campus-access-bar{display:flex;width:100%;min-width:0}.campus-trigger{display:inline-flex;height:42px;max-width:100%;min-width:0;box-sizing:border-box;align-items:center;gap:8px;padding:0 13px;border:1px solid #dde7e8;border-radius:999rpx;background:#fff;box-shadow:0 4rpx 14rpx rgba(21,62,55,.06)}.campus-trigger-name{max-width:320rpx;min-width:0;overflow:hidden;color:var(--tm-ink-900);font-size:14px;font-weight:700;text-overflow:ellipsis;white-space:nowrap}.campus-pin{position:relative;width:13px;height:16px;flex:none;box-sizing:border-box;border:2px solid var(--tm-growth-600);border-radius:50% 50% 50% 0;transform:rotate(-45deg)}.campus-pin view{position:absolute;top:3px;left:3px;width:3px;height:3px;border-radius:50%;background:var(--tm-growth-600)}.campus-brand-mark{display:flex;overflow:hidden;align-items:center;justify-content:center;flex:none;box-sizing:border-box;border:1rpx solid rgba(21,41,55,.08);background:#e8f2ff;color:#255b88;font-weight:900;letter-spacing:0}.campus-brand-mark.tone-1{background:#e8f7ee;color:#276a46}.campus-brand-mark.tone-2{background:#fff2dc;color:#8a581b}.campus-brand-mark.tone-3{background:#fdebea;color:#8a3f3a}.campus-brand-mark.tone-4{background:#e6f7f6;color:#216969}.campus-brand-mark.tone-5{background:#edf0f5;color:#4d5d70}.campus-brand-image{width:100%;height:100%;box-sizing:border-box;padding:3rpx;background:#fff}.campus-trigger-mark{width:26px;height:26px;border-radius:7px;font-size:10px}.campus-trigger-mark .campus-brand-image{padding:2px}.campus-chevron{width:7px;height:7px;flex:none;border-right:2px solid #607184;border-bottom:2px solid #607184;transform:rotate(-45deg)}.campus-trigger-search-icon{position:relative;width:14px;height:14px;flex:none;box-sizing:border-box;border:2px solid #617487;border-radius:50%}.campus-trigger-search-icon::after{content:'';position:absolute;width:6px;height:2px;right:-5px;bottom:-2px;border-radius:2px;background:#617487;transform:rotate(45deg)}
.campus-mask{position:fixed;z-index:1300;inset:0;display:flex;align-items:flex-end;background:rgba(9,25,43,.48);backdrop-filter:blur(5px)}.campus-sheet{display:flex;flex-direction:column;width:100%;max-height:82vh;overflow:hidden;box-sizing:border-box;padding:13rpx 28rpx calc(28rpx + env(safe-area-inset-bottom));border-radius:34rpx 34rpx 0 0;background:#f8fbfd;box-shadow:0 -20rpx 60rpx rgba(9,25,43,.18)}.campus-sheet-handle{width:72rpx;height:8rpx;margin:0 auto 20rpx;border-radius:999rpx;background:#cfd8e2}.campus-sheet-head{display:flex;align-items:center;justify-content:space-between}.campus-sheet-head>view:first-child text{display:block}.campus-sheet-title{font-size:22px;font-weight:950}.campus-sheet-note{margin-top:6rpx;color:var(--tm-ink-500);font-size:13px}.campus-close{display:flex;width:58rpx;height:58rpx;align-items:center;justify-content:center;border:1rpx solid var(--tm-line);border-radius:50%;background:#fff;color:#5f6e80;font-size:25px}
.campus-search{display:flex;height:82rpx;align-items:center;gap:14rpx;margin-top:24rpx;padding:0 20rpx;border:1rpx solid #d9e4ec;border-radius:20rpx;background:#fff}.campus-search input{min-width:0;flex:1;font-size:15px}.campus-search>text{color:var(--tm-growth-700);font-size:13px;font-weight:800}.campus-search-icon{width:23rpx;height:23rpx;border:4rpx solid #6f8193;border-radius:50%}.campus-search-icon::after{content:'';display:block;width:12rpx;height:4rpx;margin:18rpx 0 0 17rpx;border-radius:4rpx;background:#6f8193;transform:rotate(45deg)}
.campus-locate{display:flex;height:auto;min-height:100rpx;align-items:center;gap:18rpx;margin:18rpx 0 0;padding:17rpx 20rpx;border:1rpx solid #cde9dd;border-radius:22rpx;background:#ecfaf4;color:var(--tm-ink-900);line-height:1.2;text-align:left}.campus-locate::after{border:0}.campus-locate>view:nth-child(2){min-width:0;flex:1}.campus-locate text{display:block}.campus-locate view>text:first-child{font-size:15px;font-weight:850}.campus-locate .campus-locate-note{margin-top:7rpx;color:#5e766c;font-size:11px;font-weight:400}.campus-locate>text{color:var(--tm-growth-700);font-size:24px}.campus-locate-icon{position:relative;width:52rpx;height:52rpx;flex:none;border:4rpx solid var(--tm-growth-600);border-radius:50%}.campus-locate-icon::before,.campus-locate-icon::after{content:'';position:absolute;background:var(--tm-growth-600)}.campus-locate-icon::before{top:-10rpx;bottom:-10rpx;left:22rpx;width:4rpx}.campus-locate-icon::after{top:22rpx;right:-10rpx;left:-10rpx;height:4rpx}.campus-locate-icon view{position:absolute;z-index:2;inset:14rpx;border-radius:50%;background:var(--tm-growth-600)}
.campus-list{flex:1;min-height:300rpx;margin-top:15rpx;overflow:hidden}.campus-option{display:flex;min-height:92rpx;align-items:center;gap:16rpx;margin-top:11rpx;padding:14rpx 18rpx;border:1rpx solid #e1e8ee;border-radius:20rpx;background:#fff}.campus-option.active{border-color:#6bc7a2;background:#effbf5}.campus-option.pending{opacity:.58;pointer-events:none}.campus-option-mark{width:54rpx;height:54rpx;border-radius:14rpx;font-size:13px}.campus-option-copy{min-width:0;flex:1}.campus-option-copy text{display:block}.campus-option-copy>text:first-child{font-size:15px;font-weight:850}.campus-option-copy .campus-option-location{overflow:hidden;margin-top:5rpx;color:var(--tm-ink-500);font-size:12px;font-weight:400;text-overflow:ellipsis;white-space:nowrap}.campus-distance{flex:none;color:var(--tm-growth-700);font-size:12px}.campus-check{display:flex;width:38rpx;height:38rpx;align-items:center;justify-content:center;flex:none;border-radius:50%;background:var(--tm-growth-600);color:#fff;font-size:13px;font-weight:900}.campus-state{display:flex;min-height:280rpx;align-items:center;justify-content:center;flex-direction:column;padding:58rpx 20rpx;color:var(--tm-ink-500);text-align:center}.campus-state-title{color:var(--tm-ink-800);font-size:15px;font-weight:850}.campus-state-detail{max-width:560rpx;margin-top:12rpx;color:var(--tm-ink-500);font-size:12px;line-height:1.55}.campus-state-error .campus-state-title{color:#bd3b3b}.campus-state-action{height:62rpx;margin:24rpx 0 0;padding:0 28rpx;border-radius:18rpx;background:var(--tm-growth-600);color:#fff;font-size:13px;font-weight:850;line-height:62rpx}.campus-state-action::after{border:0}
@media screen and (max-width:360px){.campus-access-bar{gap:7px}.campus-trigger{padding:0 9px}.campus-search-trigger{padding:0 10px}}
</style>
