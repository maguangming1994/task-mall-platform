<template>
  <view class="page">
    <ModulePlaceholder v-if="modules.resolved && !moduleEnabled" code="module_second_hand" />
    <view v-else-if="!modules.resolved" class="mp-skeleton">
      <view class="tm-skeleton" style="height: 200rpx; border-radius: 24rpx; margin: 24rpx 28rpx;" />
      <view class="tm-skeleton" style="height: 320rpx; border-radius: 24rpx; margin: 20rpx 28rpx;" />
    </view>
    <template v-else>
    <view class="subnav">
      <view class="back" @click="goBack"><text>‹</text></view>
      <text class="nav-title">我发布的</text>
      <text class="nav-action" @click="goPublish">发布闲置</text>
    </view>

    <view class="chips">
      <text v-for="t in tabs" :key="t.key" class="chip" :class="{ on: active === t.key }" @click="switchTab(t.key)">{{ t.label }}<text v-if="counts[t.key] !== null" class="chip-n">{{ counts[t.key] }}</text></text>
    </view>

    <view v-if="loading" class="card">
      <view v-for="i in 3" :key="i" class="sh-item">
        <view class="cover tm-skeleton" />
        <view class="main"><view class="skel tm-skeleton" /><view class="skel short tm-skeleton" /></view>
      </view>
    </view>

    <view v-else-if="items.length" class="card">
      <view v-for="item in items" :key="item.id" class="sh-item" :class="{ muted: item.audit_status === 'pending' }">
        <image v-if="item.cover" class="cover" :src="resolveCover(item.cover)" mode="aspectFill" @click="openDetail(item)" />
        <view v-else class="cover placeholder" @click="openDetail(item)"><text>{{ item.title.slice(0, 1) }}</text></view>
        <view class="main">
          <text class="title" @click="openDetail(item)">{{ item.title }}</text>
          <view class="tags">
            <text class="tag tag-green">{{ item.condition_label }}</text>
            <text v-if="item.status === 'sold'" class="tag tag-gray">已售</text>
            <text v-else-if="item.status === 'off_shelf'" class="tag tag-gray">已下架</text>
            <text v-else-if="item.audit_status === 'pending'" class="tag tag-red">审核中</text>
            <text v-else-if="item.audit_status === 'rejected'" class="tag tag-red">未通过</text>
            <text v-else class="tag tag-blue">审核通过</text>
          </view>
          <text v-if="item.audit_status === 'pending' && item.status === 'on_sale'" class="meta">预计 10 分钟内完成审核</text>
          <text v-else-if="item.audit_status === 'rejected' && item.status === 'on_sale'" class="meta reject-reason">{{ item.rejected_reason || '未通过审核，可编辑后重新提交' }}</text>
          <text v-else class="meta">浏览 {{ item.view_count }}<text v-if="typeof item.favorite_count === 'number'"> · 想要 {{ item.favorite_count }}</text> · {{ formatDate(item.created_at) }} 发布</text>
          <view class="prow">
            <text class="price">¥{{ item.price }}</text>
            <view class="ops">
              <template v-if="item.status === 'off_shelf'">
                <text class="op main-op" @click="relist(item)">重新上架</text>
              </template>
              <template v-else-if="item.status === 'on_sale' && item.audit_status === 'pending'">
                <text class="op disabled">审核中不可操作</text>
              </template>
              <template v-else-if="item.status === 'on_sale'">
                <text class="op" @click="edit(item)">编辑</text>
                <text class="op danger-op" @click="offShelf(item)">下架</text>
              </template>
            </view>
          </view>
        </view>
      </view>
    </view>

    <view v-else class="empty">
      <text class="empty-title">{{ emptyTitle }}</text>
      <text class="empty-sub">{{ emptySub }}</text>
      <button v-if="active !== 'sold'" class="empty-btn" @click="goPublish">去发布闲置</button>
    </view>

    <text class="foot-tip">下架后宝贝不再展示，可随时重新上架</text>
    </template>
  </view>
</template>

<script setup lang="ts">
import { onShow } from '@dcloudio/uni-app'
import { computed, ref } from 'vue'
import { useAuthStore } from '@/stores/auth'
import {
  mySecondHandItems, deleteSecondHandItem, relistSecondHandItem,
  type SecondHandItem, type SecondHandItemStatus,
} from '@/api/second-hand'
import { resolveApiAssetUrl } from '@/api/http'
import { formatDate } from '@/utils/dateTime'
import ModulePlaceholder from '@/components/ModulePlaceholder.vue'
import { useModulesStore } from '@/stores/modules'

const modules = useModulesStore()
const moduleEnabled = computed(() => modules.isEnabled('module_second_hand'))

const auth = useAuthStore()
const tabs = [
  { key: 'on_sale' as SecondHandItemStatus, label: '在售' },
  { key: 'sold' as SecondHandItemStatus, label: '已售' },
  { key: 'off_shelf' as SecondHandItemStatus, label: '已下架' },
]
const active = ref<SecondHandItemStatus>('on_sale')
const items = ref<SecondHandItem[]>([])
const counts = ref<Record<string, number | null>>({ on_sale: null, sold: null, off_shelf: null })
const loading = ref(false)
const acting = ref(false)

const emptyTitle = computed(() => ({ on_sale: '还没有在售宝贝', reserved: '暂无被预订的宝贝', sold: '还没有卖出记录', off_shelf: '没有已下架的宝贝' }[active.value]))
const emptySub = computed(() => ({ on_sale: '发布闲置，让同学看到你的好物', reserved: '宝贝被预订后会显示在这里', sold: '成交后这里会展示卖出的宝贝', off_shelf: '下架的宝贝可以重新上架' }[active.value]))

function resolveCover(id: string | null) { return resolveApiAssetUrl(id) }

onShow(handleShow)

async function handleShow() {
  await modules.ensureReady()
  if (!moduleEnabled.value) return
  load()
  loadCounts()
}

async function load() {
  if (!auth.accessToken) return
  loading.value = true
  try {
    const res = await mySecondHandItems(auth.accessToken, { status: active.value, page_size: 50 })
    items.value = res.items
  } catch {
    uni.showToast({ title: '加载失败', icon: 'none' })
  } finally {
    loading.value = false
  }
}

async function loadCounts() {
  if (!auth.accessToken) return
  const results = await Promise.allSettled(tabs.map(t => mySecondHandItems(auth.accessToken!, { status: t.key, page_size: 1 })))
  results.forEach((r, i) => {
    counts.value[tabs[i].key] = r.status === 'fulfilled' ? r.value.pagination.total : null
  })
}

function switchTab(key: SecondHandItemStatus) {
  if (active.value === key) return
  active.value = key
  load()
}

function goBack() { uni.navigateBack() }
function goPublish() { uni.navigateTo({ url: '/pages/second-hand-publish/index' }) }
function openDetail(item: SecondHandItem) { uni.navigateTo({ url: `/pages/second-hand-detail/index?id=${item.id}` }) }
function edit(item: SecondHandItem) { uni.navigateTo({ url: `/pages/second-hand-publish/index?id=${item.id}` }) }

function offShelf(item: SecondHandItem) {
  if (acting.value || !auth.accessToken) return
  uni.showModal({
    title: '下架宝贝',
    content: `确认下架「${item.title}」？下架后其他同学将看不到它。`,
    success: async (r) => {
      if (!r.confirm) return
      acting.value = true
      try {
        await deleteSecondHandItem(auth.accessToken!, item.id)
        uni.showToast({ title: '已下架', icon: 'none' })
        await Promise.all([load(), loadCounts()])
      } catch (e: any) { uni.showToast({ title: e.message || '下架失败', icon: 'none' }) }
      finally { acting.value = false }
    },
  })
}

function relist(item: SecondHandItem) {
  if (acting.value || !auth.accessToken) return
  acting.value = true
  relistSecondHandItem(auth.accessToken, item.id)
    .then(async () => {
      uni.showToast({ title: '已重新上架', icon: 'none' })
      await Promise.all([load(), loadCounts()])
    })
    .catch((e: any) => uni.showToast({ title: e.message || '上架失败', icon: 'none' }))
    .finally(() => { acting.value = false })
}
</script>

<style scoped>
.page { --green:#0BBF63; --green-deep:#08A152; --green-soft:#E7F9EF; --red:#D4380D; --red-soft:#FDECEC; --t1:#191B1F; --t2:#767C85; --t3:#AEB3BB; --line:#EDEFEF; --price:#FF2442; min-height:100vh; background:#F4F6F4; padding-bottom:60rpx; }
.subnav { display:flex; align-items:center; height:88rpx; padding:0 20rpx; background:#F4F6F4; }
.back { width:64rpx; height:64rpx; display:flex; align-items:center; justify-content:center; font-size:44rpx; color:var(--t1); }
.nav-title { flex:1; text-align:center; font-size:32rpx; font-weight:800; margin-right:64rpx; }
.nav-action { position:absolute; right:28rpx; font-size:26rpx; color:var(--green-deep); font-weight:700; }
.chips { display:flex; gap:16rpx; padding:8rpx 24rpx 16rpx; }
.chip { height:60rpx; line-height:60rpx; padding:0 28rpx; border-radius:30rpx; background:#fff; font-size:25rpx; color:var(--t2); }
.chip.on { background:var(--green); color:#fff; font-weight:700; }
.chip-n { margin-left:8rpx; font-size:22rpx; opacity:.75; }
.card { background:#fff; border-radius:24rpx; margin:0 24rpx; padding:6rpx 0; }
.sh-item { display:flex; gap:20rpx; padding:22rpx 24rpx; }
.sh-item + .sh-item { border-top:2rpx solid #F5F6F6; }
.sh-item.muted { opacity:.7; }
.cover { width:150rpx; height:150rpx; border-radius:18rpx; background:#F2F4F3; flex:none; }
.cover.placeholder { display:flex; align-items:center; justify-content:center; color:var(--t3); font-size:44rpx; }
.main { flex:1; min-width:0; }
.title { font-size:27rpx; color:var(--t1); font-weight:600; line-height:1.4; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; }
.tags { display:flex; gap:10rpx; margin-top:10rpx; flex-wrap:wrap; }
.tag { font-size:20rpx; padding:4rpx 14rpx; border-radius:8rpx; background:#F2F4F3; color:var(--t2); }
.tag-green { background:var(--green-soft); color:var(--green-deep); }
.tag-blue { background:#EAF2FE; color:#2F7DE1; }
.tag-red { background:var(--red-soft); color:var(--red); }
.tag-gray { background:#F2F4F3; color:var(--t3); }
.meta { font-size:21rpx; color:var(--t3); margin-top:10rpx; display:block; }
.reject-reason { color:var(--red); }
.prow { display:flex; align-items:center; margin-top:10rpx; }
.price { font-size:30rpx; color:var(--price); font-weight:800; }
.ops { margin-left:auto; display:flex; gap:24rpx; }
.op { font-size:24rpx; color:var(--t2); padding:6rpx 4rpx; }
.op.main-op { color:var(--green-deep); font-weight:700; }
.op.danger-op { color:var(--price); }
.op.disabled { color:var(--t3); }
.skel { height:28rpx; border-radius:8rpx; margin-bottom:14rpx; }
.skel.short { width:50%; }
.empty { display:flex; flex-direction:column; align-items:center; padding:140rpx 48rpx; }
.empty-title { font-size:30rpx; font-weight:700; color:var(--t1); }
.empty-sub { font-size:24rpx; color:var(--t3); margin-top:14rpx; }
.empty-btn { margin-top:36rpx; height:76rpx; line-height:76rpx; padding:0 56rpx; border-radius:38rpx; background:linear-gradient(135deg,#12D978,#08A152); color:#fff; font-size:28rpx; font-weight:700; }
.empty-btn::after { border:0; }
.foot-tip { display:block; text-align:center; font-size:21rpx; color:var(--t3); margin-top:32rpx; line-height:1.7; }
</style>
