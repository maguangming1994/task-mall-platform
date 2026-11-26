<template>
  <view v-if="visibleSections.length" class="deco">
    <view v-for="section in visibleSections" :key="section.id" class="deco-section">
      <swiper
        v-if="section.type === 'banner'"
        class="deco-banner"
        :autoplay="boolValue(section.props.autoplay, true)"
        :interval="numberValue(section.props.interval_ms, 4500)"
        circular
      >
        <swiper-item v-for="(item, index) in listValue(section.props.items)" :key="index">
          <view class="deco-banner-item" @click="navigate(stringValue(item.link_type))">
            <image v-if="stringValue(item.image_url)" :src="assetUrl(stringValue(item.image_url))" mode="aspectFill" class="deco-banner-img" />
            <view v-else class="deco-banner-img deco-placeholder">轮播图</view>
            <view class="deco-banner-mask">
              <text v-if="stringValue(item.title)" class="deco-banner-title">{{ stringValue(item.title) }}</text>
              <text v-if="stringValue(item.subtitle)" class="deco-banner-sub">{{ stringValue(item.subtitle) }}</text>
              <text v-if="stringValue(item.button_text)" class="deco-banner-btn">{{ stringValue(item.button_text) }}</text>
            </view>
          </view>
        </swiper-item>
      </swiper>

      <view v-else-if="section.type === 'announcement'" class="deco-announce" @click="navigate(stringValue(section.props.link_type))">
        <view class="deco-announce-icon">
          <image v-if="stringValue(section.props.icon_url)" :src="assetUrl(stringValue(section.props.icon_url))" mode="aspectFit" class="deco-announce-icon-img" />
          <text v-else>{{ stringValue(section.props.icon_text) || '公' }}</text>
        </view>
        <view class="deco-announce-main">
          <text class="deco-announce-title">{{ stringValue(section.props.title) || '平台公告' }}</text>
          <rich-text v-if="stringValue(section.props.content)" class="deco-announce-content" :nodes="stringValue(section.props.content)" />
        </view>
        <text class="deco-announce-arrow">›</text>
      </view>

      <view v-else-if="section.type === 'quick_grid'" class="deco-grid">
        <view
          v-for="(item, index) in listValue(section.props.items).filter((entry) => entry.enabled !== false)"
          :key="index"
          class="deco-grid-item"
          :style="{ width: `${100 / numberValue(section.props.columns, 4)}%` }"
          @click="navigate(stringValue(item.link_type))"
        >
          <image v-if="stringValue(item.icon_url)" :src="assetUrl(stringValue(item.icon_url))" mode="aspectFit" class="deco-grid-icon" />
          <view v-else class="deco-grid-icon deco-grid-mark">{{ stringValue(item.mark) || stringValue(item.label).slice(0, 1) }}</view>
          <text class="deco-grid-label">{{ stringValue(item.label) }}</text>
        </view>
      </view>

      <view
        v-else-if="section.type === 'image_banner'"
        class="deco-image-banner"
        :style="{ height: `${numberValue(section.props.height_rpx, 200)}rpx` }"
        @click="navigate(stringValue(section.props.link_type))"
      >
        <image v-if="stringValue(section.props.image_url)" :src="assetUrl(stringValue(section.props.image_url))" mode="aspectFill" class="deco-image-banner-img" />
        <view v-else class="deco-image-banner-img deco-placeholder">横幅图片</view>
      </view>

      <view
        v-else-if="section.type === 'image_text'"
        class="deco-image-text"
        :class="{ 'is-right': stringValue(section.props.layout) === 'right' }"
        @click="navigate(stringValue(section.props.link_type))"
      >
        <image v-if="stringValue(section.props.image_url)" :src="assetUrl(stringValue(section.props.image_url))" mode="aspectFill" class="deco-image-text-img" />
        <view v-else class="deco-image-text-img deco-placeholder">图片</view>
        <view class="deco-image-text-main">
          <text class="deco-image-text-title">{{ stringValue(section.props.title) }}</text>
          <text v-if="stringValue(section.props.description)" class="deco-image-text-desc">{{ stringValue(section.props.description) }}</text>
        </view>
      </view>

      <view v-else-if="section.type === 'product_grid'" class="deco-list">
        <view class="deco-list-head">
          <text class="deco-list-title">{{ stringValue(section.props.title) || '精选商品' }}</text>
          <text class="deco-list-more" @click="navigate('products')">查看全部 ›</text>
        </view>
        <view class="deco-products">
          <view v-for="product in products.slice(0, numberValue(section.props.limit, 6))" :key="product.id" class="deco-product" @click="openProduct(product.id)">
            <image v-if="productCover(product)" :src="productCover(product)" mode="aspectFill" class="deco-product-img" />
            <view v-else class="deco-product-img deco-placeholder">{{ product.name.slice(0, 1) }}</view>
            <text class="deco-product-name">{{ product.name }}</text>
          </view>
        </view>
      </view>

      <view v-else-if="section.type === 'post_list'" class="deco-list">
        <view class="deco-list-head">
          <text class="deco-list-title">{{ stringValue(section.props.title) || '社区热帖' }}</text>
          <text class="deco-list-more" @click="navigate('community')">进入社区 ›</text>
        </view>
        <view v-for="post in posts.slice(0, numberValue(section.props.limit, 6))" :key="post.id" class="deco-post" @click="openPost(post.id)">
          <text class="deco-post-title">{{ post.title }}</text>
          <text class="deco-post-meta">{{ post.author?.nickname || '同学' }} · {{ post.comment_count }} 条评论</text>
        </view>
      </view>

      <view v-else-if="section.type === 'task_list'" class="deco-list">
        <view class="deco-list-head">
          <text class="deco-list-title">{{ stringValue(section.props.title) || '推荐任务' }}</text>
          <text class="deco-list-more" @click="navigate('tasks')">去任务大厅 ›</text>
        </view>
        <view v-for="task in tasks.slice(0, numberValue(section.props.limit, 4))" :key="task.id" class="deco-task" @click="openTask(task.id)">
          <text class="deco-task-title">{{ task.title }}</text>
          <text class="deco-task-desc">{{ task.description_text || task.description }}</text>
        </view>
      </view>

      <view v-else-if="section.type === 'rich_text'" class="deco-rich">
        <rich-text :nodes="stringValue(section.props.html)" />
      </view>

      <view v-else-if="section.type === 'spacer'" :style="{ height: `${numberValue(section.props.height_rpx, 24)}rpx` }" />
    </view>
  </view>
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { resolveApiAssetUrl } from '@/api/http'
import { listProducts, type MallProduct } from '@/api/products'
import { listCommunityPosts, type CommunityPost } from '@/api/community'
import { listHallTasks, type HallTask } from '@/api/tasks'
import type { DecorationSection } from '@/api/decoration'

const KNOWN_TYPES = ['banner', 'announcement', 'quick_grid', 'image_banner', 'image_text', 'product_grid', 'post_list', 'task_list', 'rich_text', 'spacer']

const props = defineProps<{ sections: DecorationSection[] }>()
const emit = defineEmits<{ (event: 'navigate', link: string): void }>()

const products = ref<MallProduct[]>([])
const posts = ref<CommunityPost[]>([])
const tasks = ref<HallTask[]>([])
let productsLoaded = false
let postsLoaded = false
let tasksLoaded = false

const visibleSections = computed(() => props.sections.filter((section) => section.enabled !== false && KNOWN_TYPES.includes(section.type)))
const needsProducts = computed(() => visibleSections.value.some((section) => section.type === 'product_grid'))
const needsPosts = computed(() => visibleSections.value.some((section) => section.type === 'post_list'))
const needsTasks = computed(() => visibleSections.value.some((section) => section.type === 'task_list'))

async function loadProducts() {
  if (productsLoaded) return
  productsLoaded = true
  try { products.value = (await listProducts()).items.slice(0, 20) } catch { productsLoaded = false }
}
async function loadPosts() {
  if (postsLoaded) return
  postsLoaded = true
  try { posts.value = (await listCommunityPosts(1, 20)).items } catch { postsLoaded = false }
}
async function loadTasks() {
  if (tasksLoaded) return
  tasksLoaded = true
  try { tasks.value = (await listHallTasks()).items.slice(0, 10) } catch { tasksLoaded = false }
}

watch(needsProducts, (value) => { if (value) void loadProducts() }, { immediate: true })
watch(needsPosts, (value) => { if (value) void loadPosts() }, { immediate: true })
watch(needsTasks, (value) => { if (value) void loadTasks() }, { immediate: true })

function stringValue(value: unknown): string {
  return typeof value === 'string' ? value : ''
}
function numberValue(value: unknown, fallback: number): number {
  return typeof value === 'number' ? value : fallback
}
function boolValue(value: unknown, fallback: boolean): boolean {
  return typeof value === 'boolean' ? value : fallback
}
function listValue(value: unknown): Record<string, unknown>[] {
  return Array.isArray(value) ? value as Record<string, unknown>[] : []
}
function assetUrl(url: string): string {
  return resolveApiAssetUrl(url)
}
function productCover(product: MallProduct): string {
  const image = product.gallery_images?.[0]?.url
  return image ? resolveApiAssetUrl(image) : ''
}
function navigate(link: string) {
  if (!link || link === 'none') return
  emit('navigate', link)
}
function openProduct(id: string) { uni.navigateTo({ url: `/pages/product-detail/index?id=${encodeURIComponent(id)}` }) }
function openPost(id: string) { uni.navigateTo({ url: `/pages/community-detail/index?id=${encodeURIComponent(id)}` }) }
function openTask(id: string) { uni.navigateTo({ url: `/pages/task-detail/index?id=${encodeURIComponent(id)}` }) }
</script>

<style scoped>
.deco { display: flex; flex-direction: column; gap: 20rpx; margin: 20rpx 0; }
.deco-section { width: 100%; }
.deco-placeholder { display: flex; align-items: center; justify-content: center; background: #eef2f9; color: #9aa6bc; font-size: 24rpx; }
.deco-banner { width: 100%; height: 280rpx; border-radius: 24rpx; overflow: hidden; position: relative; }
.deco-banner-item { position: relative; width: 100%; height: 100%; }
.deco-banner-img { width: 100%; height: 280rpx; }
.deco-banner-mask { position: absolute; left: 0; right: 0; bottom: 0; padding: 24rpx; background: linear-gradient(180deg, rgba(0,0,0,0) 30%, rgba(0,0,0,.55)); display: flex; flex-direction: column; gap: 6rpx; }
.deco-banner-title { color: #fff; font-size: 32rpx; font-weight: 700; }
.deco-banner-sub { color: rgba(255,255,255,.9); font-size: 24rpx; }
.deco-banner-btn { align-self: flex-start; margin-top: 8rpx; padding: 8rpx 24rpx; border-radius: 999rpx; background: #fff; color: #2f67df; font-size: 24rpx; font-weight: 600; }
.deco-announce { display: flex; align-items: center; gap: 16rpx; padding: 20rpx 24rpx; border-radius: 20rpx; background: #fff8e8; }
.deco-announce-icon { width: 48rpx; height: 48rpx; border-radius: 12rpx; background: #ffd43b; color: #8a5c00; font-size: 24rpx; font-weight: 700; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.deco-announce-icon-img { width: 48rpx; height: 48rpx; border-radius: 12rpx; }
.deco-announce-main { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 4rpx; }
.deco-announce-title { font-size: 28rpx; font-weight: 600; color: #5c4300; }
.deco-announce-content { font-size: 24rpx; color: #8a6a1f; max-height: 80rpx; overflow: hidden; }
.deco-announce-arrow { color: #b79a52; font-size: 32rpx; }
.deco-grid { display: flex; flex-wrap: wrap; padding: 20rpx 8rpx; background: #fff; border-radius: 24rpx; }
.deco-grid-item { display: flex; flex-direction: column; align-items: center; gap: 10rpx; padding: 10rpx 0; }
.deco-grid-icon { width: 84rpx; height: 84rpx; border-radius: 24rpx; }
.deco-grid-mark { display: flex; align-items: center; justify-content: center; background: #eaf1ff; color: #2f67df; font-size: 32rpx; font-weight: 700; }
.deco-grid-label { font-size: 24rpx; color: #3c4961; }
.deco-image-banner { width: 100%; border-radius: 24rpx; overflow: hidden; }
.deco-image-banner-img { width: 100%; height: 100%; }
.deco-image-text { display: flex; gap: 20rpx; padding: 20rpx; background: #fff; border-radius: 24rpx; align-items: center; }
.deco-image-text.is-right { flex-direction: row-reverse; }
.deco-image-text-img { width: 160rpx; height: 160rpx; border-radius: 16rpx; flex-shrink: 0; }
.deco-image-text-main { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 8rpx; }
.deco-image-text-title { font-size: 28rpx; font-weight: 600; color: #1f2937; }
.deco-image-text-desc { font-size: 24rpx; color: #7a859b; }
.deco-list { padding: 20rpx; background: #fff; border-radius: 24rpx; }
.deco-list-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 16rpx; }
.deco-list-title { font-size: 30rpx; font-weight: 700; color: #1f2937; }
.deco-list-more { font-size: 24rpx; color: #7a859b; }
.deco-products { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16rpx; }
.deco-product { display: flex; flex-direction: column; gap: 8rpx; }
.deco-product-img { width: 100%; height: 200rpx; border-radius: 16rpx; }
.deco-product-name { font-size: 24rpx; color: #3c4961; }
.deco-post, .deco-task { display: flex; flex-direction: column; gap: 6rpx; padding: 16rpx 0; border-top: 1rpx solid #f0f2f7; }
.deco-post-title, .deco-task-title { font-size: 28rpx; color: #1f2937; font-weight: 600; }
.deco-post-meta, .deco-task-desc { font-size: 24rpx; color: #7a859b; }
.deco-rich { padding: 20rpx; background: #fff; border-radius: 24rpx; font-size: 26rpx; color: #3c4961; line-height: 1.7; }
</style>
