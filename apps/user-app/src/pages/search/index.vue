<template>
  <view class="page">
    <view class="head">
      <view class="back" @click="goBack"><text class="back-ic">‹</text></view>
      <view class="search-box" @click="focusSearch">
        <text class="search-ic">⌕</text>
        <input
          v-model.trim="keyword"
          class="search-input"
          placeholder="搜任务 / 搜商品 / 搜闲置"
          confirm-type="search"
          :focus="searchFocused"
          @blur="searchFocused = false"
          @confirm="doSearch"
        />
        <text v-if="keyword" class="search-clear" @click="clearKeyword">×</text>
      </view>
      <text class="search-btn" @click="doSearch">搜索</text>
    </view>

    <scroll-view scroll-x class="tab-scroll" :show-scrollbar="false">
      <view class="tabs">
        <view v-for="t in tabs" :key="t.value" :class="['tab', { on: tab === t.value }]" @click="tab = t.value">{{ t.label }}</view>
      </view>
    </scroll-view>

    <!-- 未搜索：历史 + 热门 -->
    <template v-if="!searched">
      <view v-if="history.length" class="card">
        <view class="sec-title">
          <text class="sec-h">搜索历史</text>
          <text class="sec-clear" @click="clearHistory">清空</text>
        </view>
        <view class="kw-wrap">
          <text v-for="h in history" :key="h" class="kw" @click="applyKeyword(h)">{{ h }}</text>
        </view>
      </view>
      <view class="card">
        <view class="sec-title"><text class="sec-h">热门搜索</text></view>
        <view class="kw-wrap">
          <text v-for="(h, i) in hotKeywords" :key="h" class="kw hot" @click="applyKeyword(h)">
            <text v-if="i < 3" class="kw-rank">{{ i + 1 }}</text>{{ h }}
          </text>
        </view>
      </view>
    </template>

    <!-- 搜索结果 -->
    <template v-else>
      <view class="result-count">共 {{ filteredResults.length }} 条结果</view>
      <view v-if="loading" class="card">
        <view v-for="i in 3" :key="i" class="r-item">
          <view class="r-cover tm-skeleton" />
          <view class="r-main"><view class="skel-line tm-skeleton" /><view class="skel-line short tm-skeleton" /></view>
        </view>
      </view>
      <view v-else-if="filteredResults.length" class="card">
        <view v-for="r in filteredResults" :key="r.kind + r.id" class="r-item" @click="openResult(r)">
          <image v-if="r.cover" class="r-cover" :src="r.cover" mode="aspectFill" />
          <view v-else class="r-cover placeholder"><text>{{ r.title.slice(0, 1) }}</text></view>
          <view class="r-main">
            <view class="r-title">
              <text class="r-title-text">{{ r.title }}</text>
              <text class="tag" :class="r.tagClass">{{ r.tagLabel }}</text>
            </view>
            <text class="r-meta">{{ r.meta }}</text>
          </view>
          <text class="r-price" :class="{ points: r.kind === 'task' }">{{ r.priceText }}</text>
        </view>
      </view>
      <view v-else class="empty">
        <text class="empty-title">没有找到「{{ searchedKeyword }}」相关内容</text>
        <text class="empty-copy">换个关键词试试，或去任务大厅逛逛</text>
        <button class="empty-btn" @click="goTasks">去看任务</button>
      </view>
    </template>
  </view>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue'
import { onLoad } from '@dcloudio/uni-app'
import { useAuthStore } from '@/stores/auth'
import { listHallTasks, type HallTask } from '@/api/tasks'
import { listProducts, type MallProduct } from '@/api/products'
import { listSecondHandItems, type SecondHandItem } from '@/api/second-hand'
import { resolveApiAssetUrl } from '@/api/http'

const auth = useAuthStore()

type TabValue = 'all' | 'task' | 'product' | 'sh'
const tabs: Array<{ value: TabValue; label: string }> = [
  { value: 'all', label: '综合' },
  { value: 'task', label: '任务' },
  { value: 'product', label: '商品' },
  { value: 'sh', label: '闲置' },
]

interface SearchResult {
  kind: 'task' | 'product' | 'sh'
  id: string
  title: string
  cover: string
  meta: string
  priceText: string
  tagLabel: string
  tagClass: string
}

const keyword = ref('')
const searchFocused = ref(true)

function focusSearch() {
  searchFocused.value = true
}
const searchedKeyword = ref('')
const tab = ref<TabValue>('all')
const searched = ref(false)
const loading = ref(false)
const history = ref<string[]>([])
const hotKeywords = ['取快递', '键盘', '帆布包', '答疑', '台灯', '毕业照']

const tasks = ref<HallTask[]>([])
const products = ref<MallProduct[]>([])
const shItems = ref<SecondHandItem[]>([])

const HISTORY_KEY = 'search_history'

onLoad((query) => {
  try {
    history.value = uni.getStorageSync(HISTORY_KEY) || []
  } catch { history.value = [] }
  if (query?.keyword) {
    keyword.value = decodeURIComponent(query.keyword)
    doSearch()
  }
})

const filteredResults = computed<SearchResult[]>(() => {
  if (!searched.value) return []
  const list: SearchResult[] = []
  if (tab.value === 'all' || tab.value === 'task') {
    for (const t of tasks.value) {
      const pts = t.rewards?.find((r) => r.type === 'points')?.points
      list.push({
        kind: 'task',
        id: t.id,
        title: t.title,
        cover: t.cover_image_url || '',
        meta: `${t.publisher?.display_name || '校园任务官'} · 剩余 ${t.remaining_reward_slots} 名额`,
        priceText: pts ? `+${pts}` : '奖励',
        tagLabel: '任务',
        tagClass: 'tag-green',
      })
    }
  }
  if (tab.value === 'all' || tab.value === 'product') {
    for (const p of products.value) {
      const opt = p.skus?.[0]?.purchase_options?.find((o) => o.enabled)
      const priceText = opt ? (opt.purchase_mode === 'points' ? `${opt.points}积分` : `¥${opt.cash_amount}`) : '—'
      list.push({
        kind: 'product',
        id: p.id,
        title: p.name,
        cover: p.cover_file_id ? resolveApiAssetUrl(p.cover_file_id) : '',
        meta: `${p.category?.name || '商城'} · 库存 ${p.skus?.[0]?.stock ?? 0}`,
        priceText,
        tagLabel: '商品',
        tagClass: 'tag-blue',
      })
    }
  }
  if (tab.value === 'all' || tab.value === 'sh') {
    for (const s of shItems.value) {
      list.push({
        kind: 'sh',
        id: s.id,
        title: s.title,
        cover: s.cover ? resolveApiAssetUrl(s.cover) : '',
        meta: `${s.seller?.nickname || '同学'} · ${s.location || '校内'}`,
        priceText: `¥${s.price}`,
        tagLabel: '闲置',
        tagClass: 'tag-orange',
      })
    }
  }
  return list
})

async function doSearch() {
  if (!keyword.value) return
  searchedKeyword.value = keyword.value
  searched.value = true
  saveHistory(keyword.value)
  loading.value = true
  try {
    const kw = searchedKeyword.value
    const jobs: Array<Promise<void>> = [
      listHallTasks({ keyword: kw }).then((res) => { tasks.value = res.items }).catch(() => { tasks.value = [] }),
      listProducts(undefined, kw).then((res) => { products.value = res.items }).catch(() => { products.value = [] }),
    ]
    jobs.push(
      listSecondHandItems(auth.accessToken || undefined, { keyword: searchedKeyword.value, page_size: 50 })
        .then((res) => { shItems.value = res.items })
        .catch(() => { shItems.value = [] })
    )
    await Promise.all(jobs)
  } finally {
    loading.value = false
  }
}

function saveHistory(kw: string) {
  const list = [kw, ...history.value.filter((h) => h !== kw)].slice(0, 10)
  history.value = list
  try { uni.setStorageSync(HISTORY_KEY, list) } catch { /* noop */ }
}

function clearHistory() {
  history.value = []
  try { uni.removeStorageSync(HISTORY_KEY) } catch { /* noop */ }
}

function applyKeyword(kw: string) {
  keyword.value = kw
  doSearch()
}

function clearKeyword() {
  keyword.value = ''
  searched.value = false
}

function openResult(r: SearchResult) {
  if (r.kind === 'task') uni.navigateTo({ url: `/pages/task-detail/index?id=${r.id}` })
  else if (r.kind === 'product') uni.navigateTo({ url: `/pages/product-detail/index?id=${encodeURIComponent(r.id)}` })
  else uni.navigateTo({ url: `/pages/second-hand-detail/index?id=${r.id}` })
}

function goTasks() { uni.switchTab({ url: '/pages/tasks/index' }) }
function goBack() { uni.navigateBack({ delta: 1 }) }
</script>

<style scoped>
.page{min-height:100vh;background:#F4F6F4;padding-bottom:40rpx}
.head{display:flex;align-items:center;gap:16rpx;padding:20rpx 24rpx;background:#fff;position:sticky;top:0;z-index:10}
.back{width:56rpx;height:56rpx;display:flex;align-items:center;justify-content:center}
.back-ic{font-size:48rpx;color:#191B1F;line-height:1}
.search-box{flex:1;height:68rpx;border-radius:34rpx;background:#F4F6F4;display:flex;align-items:center;gap:10rpx;padding:0 24rpx}
.search-ic{font-size:28rpx;color:#AEB3BB}
.search-input{flex:1;font-size:26rpx;color:#191B1F}
.search-clear{font-size:36rpx;color:#AEB3BB;padding:0 8rpx}
.search-btn{font-size:28rpx;color:#08A152;font-weight:600}
.tab-scroll{background:#fff;border-bottom:1rpx solid #EDEFEF}
.tabs{display:flex;gap:12rpx;padding:16rpx 24rpx}
.tab{padding:10rpx 28rpx;border-radius:28rpx;background:#F4F6F4;font-size:26rpx;color:#767C85;white-space:nowrap}
.tab.on{background:#E7F9EF;color:#08A152;font-weight:600}
.card{background:#fff;border-radius:24rpx;margin:20rpx 24rpx 0;padding:24rpx}
.sec-title{display:flex;align-items:center;justify-content:space-between;margin-bottom:16rpx}
.sec-h{font-size:28rpx;font-weight:700;color:#191B1F}
.sec-clear{font-size:24rpx;color:#AEB3BB}
.kw-wrap{display:flex;flex-wrap:wrap;gap:16rpx}
.kw{padding:12rpx 24rpx;border-radius:28rpx;background:#F4F6F4;font-size:24rpx;color:#191B1F}
.kw.hot{background:#FFF8F2}
.kw-rank{color:#F2620A;font-weight:800;margin-right:6rpx}
.result-count{padding:20rpx 24rpx 0;font-size:24rpx;color:#AEB3BB}
.r-item{display:flex;align-items:center;gap:20rpx;padding:20rpx 0;border-bottom:1rpx solid #F5F6F6}
.r-item:last-child{border-bottom:0}
.r-cover{width:112rpx;height:112rpx;border-radius:16rpx;flex:none;background:#F4F6F4}
.r-cover.placeholder{display:flex;align-items:center;justify-content:center;font-size:36rpx;color:#AEB3BB}
.r-main{flex:1;min-width:0}
.r-title{display:flex;align-items:center;gap:8rpx}
.r-title-text{font-size:26rpx;font-weight:600;color:#191B1F;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:360rpx}
.tag{font-size:20rpx;padding:4rpx 12rpx;border-radius:8rpx;flex:none}
.tag-green{background:#E7F9EF;color:#08A152}
.tag-blue{background:#EAF2FF;color:#3B82F6}
.tag-orange{background:#FFF2E5;color:#F2620A}
.r-meta{font-size:22rpx;color:#AEB3BB;margin-top:8rpx;display:block}
.r-price{font-size:30rpx;font-weight:800;color:#F2620A;flex:none}
.r-price.points{color:#08A152}
.empty{display:flex;flex-direction:column;align-items:center;padding:120rpx 48rpx;gap:16rpx}
.empty-title{font-size:28rpx;font-weight:600;color:#191B1F}
.empty-copy{font-size:24rpx;color:#AEB3BB}
.empty-btn{margin-top:16rpx;padding:0 48rpx;height:72rpx;line-height:72rpx;border-radius:36rpx;background:linear-gradient(135deg,#12D978,#08A152);color:#fff;font-size:26rpx;font-weight:600}
.skel-line{height:28rpx;border-radius:8rpx;margin:10rpx 0}
.skel-line.short{width:60%}
.tm-skeleton{background:linear-gradient(90deg,#F0F2F0 25%,#E8EBE8 50%,#F0F2F0 75%);background-size:400% 100%;animation:sk 1.2s ease infinite}
@keyframes sk{0%{background-position:100% 0}100%{background-position:-100% 0}}
</style>
