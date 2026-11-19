<template>
  <view class="page">
    <ModulePlaceholder v-if="modules.resolved && !moduleEnabled" code="module_material" />
    <view v-else-if="!modules.resolved" class="skeleton-wrap">
      <view class="tm-skeleton skel-hero" />
      <view class="tm-skeleton skel-card" />
    </view>
    <template v-else>
      <view class="head">
        <view class="head-back" @click="goBack">‹</view>
        <text class="head-title">资料共享</text>
        <view class="head-record" @click="openMyRecords">
          <text class="record-ic">◷</text>
          <text>我的记录</text>
        </view>
      </view>

      <view class="hero">
        <view class="hero-main">
          <view class="hero-tag"><text class="hero-tag-dot">✦</text><text>同学互助 · 学习资料共享</text></view>
          <text class="hero-title">{{ campusName }}学习资料</text>
          <text class="hero-desc">一人分享，万人受益 · 学长学姐带你稳过</text>
        </view>
        <view class="hero-stats">
          <view class="hero-stat">
            <text class="stat-value">{{ stats.total }}</text>
            <text class="stat-label">学习资料</text>
          </view>
          <view class="hero-stat quota">
            <text class="stat-value">{{ quotaText }}</text>
            <text class="stat-label">{{ quotaLabel }}</text>
          </view>
        </view>
      </view>

      <view class="search-box" @click="focusSearch">
        <text class="search-ic">⌕</text>
        <input v-model="keyword" confirm-type="search" placeholder="搜索课程名、真题试卷、实验报告..." :focus="searchFocused" @blur="searchFocused = false" @confirm="loadList()" />
        <view class="search-btn" @click.stop="loadList()">搜索</view>
      </view>

      <scroll-view class="cat-scroll" scroll-x :show-scrollbar="false">
        <view class="cats">
          <view :class="['cat', { active: !categoryId }]" @click="selectCategory('')">全部资料</view>
          <view v-for="c in categories" :key="c.id" :class="['cat', { active: categoryId === c.id }]" @click="selectCategory(c.id)">
            {{ c.name }}
          </view>
        </view>
      </scroll-view>

      <view class="section-head">
        <text class="section-title">最新投喂</text>
        <text class="section-count">{{ total }} 份资源</text>
        <view class="section-refresh" @click="loadList">
          <text class="refresh-ic">↻</text>
          <text>刷新</text>
        </view>
      </view>

      <view v-if="loading" class="grid">
        <view v-for="i in 4" :key="i" class="m-card">
          <view class="m-icon tm-skeleton" />
          <view class="skel-line tm-skeleton" />
          <view class="skel-line short tm-skeleton" />
        </view>
      </view>
      <view v-else-if="items.length" class="grid">
        <view v-for="item in items" :key="item.id" class="m-card" @click="openDetail(item.id)">
          <view class="m-top">
            <text class="m-netdisk">{{ item.netdisk_label }}</text>
            <text v-if="item.category" class="m-cat">{{ item.category.name }}</text>
          </view>
          <view class="m-body">
            <view :class="['file-badge', `f-${item.file_type}`]">{{ fileBadge(item.file_type) }}</view>
            <text class="m-title">{{ item.title }}</text>
          </view>
          <view class="m-foot">
            <text class="m-uploader">♙ {{ item.uploader_name }}</text>
            <text class="m-get">获取 ›</text>
          </view>
        </view>
      </view>
      <view v-else class="empty">
        <text class="empty-title">还没有匹配的资料</text>
        <text class="empty-sub">换个关键词，或发起求资料让学长学姐帮你找</text>
      </view>

      <view class="fab-bar">
        <view v-if="requestEnabled" class="fab-ghost" @click="openRequest">求资料</view>
        <view v-if="postEnabled" class="fab-solid" @click="openPost">投喂资料</view>
      </view>
    </template>
  </view>
</template>

<script setup lang="ts">
import { onShow } from '@dcloudio/uni-app'
import { computed, ref } from 'vue'
import { getMaterialMeta, listMaterials, type MaterialCategory, type MaterialItem, type MaterialMeta } from '@/api/material'
import ModulePlaceholder from '@/components/ModulePlaceholder.vue'
import { useAuthStore } from '@/stores/auth'
import { useModulesStore } from '@/stores/modules'

const auth = useAuthStore()
const modules = useModulesStore()
const moduleEnabled = computed(() => modules.isEnabled('module_material'))
const postEnabled = computed(() => modules.isEnabled('module_material_post'))
const requestEnabled = computed(() => modules.isEnabled('module_material_request'))

const campusName = computed(() => auth.user?.campus?.name || '本校')
const categories = ref<MaterialCategory[]>([])
const items = ref<MaterialItem[]>([])
const meta = ref<MaterialMeta | null>(null)
const loading = ref(false)
const keyword = ref('')
const searchFocused = ref(false)

function focusSearch() {
  searchFocused.value = true
}
const categoryId = ref('')
const total = ref(0)

const stats = computed(() => ({ total: meta.value?.stats.total ?? total.value }))
const quotaText = computed(() => {
  if (!auth.isAuthenticated) return '登录'
  const q = meta.value?.quota
  if (!q) return '—'
  return `${q.daily_used}/${q.daily_limit}`
})
const quotaLabel = computed(() => {
  if (!auth.isAuthenticated) return '查看额度'
  const q = meta.value?.quota
  if (!q) return '今日额度'
  return `今日额度，剩余 ${q.total_remaining} 次`
})

async function loadMeta() {
  try {
    meta.value = await getMaterialMeta(auth.accessToken || undefined)
    categories.value = meta.value.categories
  } catch {
    /* 静默失败，列表仍可浏览 */
  }
}

async function loadList() {
  loading.value = true
  try {
    const res = await listMaterials(auth.accessToken || undefined, {
      keyword: keyword.value || undefined,
      category_id: categoryId.value || undefined,
    })
    items.value = res.items
    total.value = res.pagination.total
  } catch {
    uni.showToast({ title: '加载失败，请稍后重试', icon: 'none' })
  } finally {
    loading.value = false
  }
}

function selectCategory(id: string) {
  categoryId.value = id
  void loadList()
}

function fileBadge(type: string) {
  const map: Record<string, string> = { pdf: 'PDF', word: 'W', excel: 'X', ppt: 'P', video: '▶', archive: 'Z' }
  return map[type] ?? '文'
}

function openDetail(id: string) {
  uni.navigateTo({ url: `/pages/material-detail/index?id=${encodeURIComponent(id)}` })
}

function openRequest() {
  uni.navigateTo({ url: '/pages/material-request/index' })
}

function openPost() {
  uni.navigateTo({ url: '/pages/material-post/index' })
}

function openMyRecords() {
  uni.navigateTo({ url: '/pages/material-post/index?tab=mine' })
}

function goBack() {
  const pages = getCurrentPages()
  if (pages.length > 1) uni.navigateBack()
  else uni.reLaunch({ url: '/pages/index/index' })
}

onShow(async () => {
  await modules.ensureReady()
  if (!moduleEnabled.value) return
  void loadMeta()
  void loadList()
})
</script>

<style lang="scss" scoped>
.page { min-height: 100vh; background: #F4F6F4; padding-bottom: 220rpx; }

.head { display: flex; align-items: center; gap: 16rpx; padding: calc(var(--status-bar-height) + 16rpx) 24rpx 12rpx; }
.head-back { width: 60rpx; height: 60rpx; border-radius: 30rpx; background: #fff; color: #191B1F; font-size: 40rpx; line-height: 54rpx; text-align: center; }
.head-title { font-size: 34rpx; font-weight: 800; color: #191B1F; }
.head-record { margin-left: auto; display: flex; align-items: center; gap: 6rpx; height: 56rpx; padding: 0 20rpx; border-radius: 28rpx; background: #fff; color: #4B5563; font-size: 22rpx; }
.record-ic { color: #08A152; }

.hero { margin: 8rpx 24rpx 20rpx; padding: 28rpx; border-radius: 32rpx; background: linear-gradient(135deg, #0E8F58, #0A6B42); color: #fff; display: flex; gap: 18rpx; }
.hero-main { flex: 1; min-width: 0; }
.hero-tag { display: inline-flex; align-items: center; gap: 6rpx; padding: 6rpx 14rpx; border-radius: 999rpx; background: rgba(255, 255, 255, .16); font-size: 20rpx; }
.hero-tag-dot { font-size: 18rpx; }
.hero-title { display: block; margin-top: 16rpx; font-size: 36rpx; font-weight: 800; line-height: 1.35; }
.hero-desc { display: block; margin-top: 10rpx; font-size: 22rpx; color: rgba(255, 255, 255, .78); }
.hero-stats { display: flex; flex-direction: column; gap: 12rpx; }
.hero-stat { min-width: 150rpx; padding: 14rpx 12rpx; border-radius: 20rpx; background: rgba(255, 255, 255, .14); text-align: center; }
.hero-stat.quota { background: rgba(255, 255, 255, .22); }
.stat-value { display: block; font-size: 30rpx; font-weight: 800; }
.stat-label { display: block; margin-top: 4rpx; font-size: 18rpx; color: rgba(255, 255, 255, .8); }

.search-box { margin: 0 24rpx 16rpx; height: 84rpx; padding: 0 10rpx 0 24rpx; border-radius: 42rpx; background: #fff; display: flex; align-items: center; gap: 10rpx; box-shadow: 0 6rpx 20rpx rgba(17, 24, 39, .04); }
.search-ic { font-size: 30rpx; color: #AEB3BB; }
.search-box input { flex: 1; font-size: 25rpx; color: #191B1F; }
.search-btn { height: 64rpx; line-height: 64rpx; padding: 0 30rpx; border-radius: 32rpx; background: linear-gradient(135deg, #12D978, #08A152); color: #fff; font-size: 25rpx; font-weight: 700; }

.cat-scroll { white-space: nowrap; }
.cats { display: inline-flex; gap: 16rpx; padding: 4rpx 24rpx 16rpx; }
.cat { flex: none; height: 64rpx; line-height: 64rpx; padding: 0 26rpx; border-radius: 32rpx; background: #fff; border: 1rpx solid #EDEFEF; font-size: 24rpx; color: #4B5563; }
.cat.active { background: #E7F9EF; border-color: #0BBF63; color: #08A152; font-weight: 700; }

.section-head { display: flex; align-items: baseline; gap: 12rpx; padding: 8rpx 28rpx 16rpx; }
.section-title { font-size: 30rpx; font-weight: 800; color: #191B1F; }
.section-count { font-size: 22rpx; color: #AEB3BB; }
.section-refresh { margin-left: auto; display: flex; align-items: center; gap: 4rpx; font-size: 22rpx; color: #08A152; }
.refresh-ic { font-size: 24rpx; }

.grid { display: flex; flex-wrap: wrap; gap: 20rpx; padding: 0 24rpx; }
.m-card { width: calc((100% - 20rpx) / 2); padding: 20rpx; border-radius: 26rpx; background: #fff; box-sizing: border-box; }
.m-top { display: flex; align-items: center; gap: 8rpx; }
.m-netdisk { padding: 4rpx 10rpx; border-radius: 8rpx; background: #F2F4F3; color: #6B7280; font-size: 18rpx; }
.m-cat { margin-left: auto; padding: 4rpx 10rpx; border-radius: 8rpx; background: #E7F9EF; color: #08A152; font-size: 18rpx; }
.m-body { display: flex; align-items: flex-start; gap: 12rpx; margin-top: 16rpx; }
.file-badge { flex: none; width: 60rpx; height: 60rpx; border-radius: 14rpx; display: flex; align-items: center; justify-content: center; font-size: 22rpx; font-weight: 800; color: #fff; background: #4F7DF3; }
.file-badge.f-pdf { background: #F04438; }
.file-badge.f-word { background: #2B6CE6; }
.file-badge.f-excel { background: #1E9E5A; }
.file-badge.f-ppt { background: #E06A1B; }
.file-badge.f-video { background: #7A5AF8; }
.file-badge.f-archive { background: #6B7280; }
.m-title { font-size: 25rpx; font-weight: 600; color: #191B1F; line-height: 1.42; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
.m-foot { display: flex; align-items: center; margin-top: 18rpx; }
.m-uploader { font-size: 20rpx; color: #AEB3BB; }
.m-get { margin-left: auto; font-size: 22rpx; font-weight: 700; color: #08A152; }

.skel-hero { height: 220rpx; border-radius: 32rpx; margin: 24rpx; }
.skel-card { height: 300rpx; border-radius: 26rpx; margin: 20rpx 24rpx; }
.skel-line { height: 26rpx; border-radius: 8rpx; margin-top: 16rpx; }
.skel-line.short { width: 60%; }

.empty { display: flex; flex-direction: column; align-items: center; padding: 120rpx 48rpx; }
.empty-title { font-size: 28rpx; font-weight: 700; color: #191B1F; }
.empty-sub { margin-top: 12rpx; font-size: 23rpx; color: #AEB3BB; text-align: center; line-height: 1.6; }

.fab-bar { position: fixed; right: 28rpx; bottom: calc(48rpx + env(safe-area-inset-bottom)); z-index: 30; display: flex; gap: 16rpx; }
.fab-ghost, .fab-solid { height: 86rpx; line-height: 86rpx; padding: 0 32rpx; border-radius: 43rpx; font-size: 26rpx; font-weight: 700; }
.fab-ghost { background: #fff; color: #08A152; box-shadow: 0 10rpx 26rpx rgba(17, 24, 39, .12); }
.fab-solid { background: linear-gradient(135deg, #12D978, #08A152); color: #fff; box-shadow: 0 12rpx 28rpx rgba(11, 191, 99, .35); }
</style>
