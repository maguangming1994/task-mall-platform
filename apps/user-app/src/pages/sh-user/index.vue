<template>
  <view class="page">
    <view class="subnav">
      <view class="back" @click="goBack"><text>‹</text></view>
      <text class="nav-title">TA 的闲置</text>
      <view class="nav-right" />
    </view>

    <view v-if="profile" class="card seller-card">
      <view class="seller-row">
        <image v-if="profile.avatar" class="avatar" :src="resolveCover(profile.avatar)" mode="aspectFill" />
        <view v-else class="avatar placeholder"><text>{{ profile.nickname.slice(0, 1) }}</text></view>
        <view class="seller-main">
          <view class="name-line">
            <text class="name">{{ profile.nickname }}</text>
            <text v-if="profile.identity_verified" class="badge">已认证</text>
          </view>
          <text class="sub">{{ profile.campus_name || '校园用户' }}{{ profile.rating_average ? ` · 综合评分 ${profile.rating_average}` : '' }}</text>
        </view>
        <button class="chat-btn" @click="contact">私信</button>
      </view>
      <view class="stats">
        <view class="stat"><text class="num">{{ profile.rating_average ?? '—' }}</text><text class="lab">综合评分</text></view>
        <view class="stat"><text class="num">{{ profile.on_sale_count }}</text><text class="lab">在售</text></view>
        <view class="stat"><text class="num">{{ profile.sold_count }}</text><text class="lab">已售</text></view>
        <view class="stat"><text class="num green">{{ profile.good_rate_percent !== null ? `${profile.good_rate_percent}%` : '—' }}</text><text class="lab">好评率</text></view>
      </view>
    </view>

    <view class="chips">
      <text class="chip" :class="{ on: tab === 'on_sale' }" @click="switchTab('on_sale')">在售 {{ profile?.on_sale_count ?? '' }}</text>
      <text class="chip" :class="{ on: tab === 'sold' }" @click="switchTab('sold')">已售 {{ profile?.sold_count ?? '' }}</text>
      <text class="chip" :class="{ on: tab === 'ratings' }" @click="switchTab('ratings')">评价 {{ profile?.rating_count ?? '' }}</text>
    </view>

    <!-- 在售列表 -->
    <view v-if="tab === 'on_sale'">
      <view v-if="loading" class="card">
        <view v-for="i in 3" :key="i" class="sh-item">
          <view class="cover tm-skeleton" />
          <view class="main"><view class="skel tm-skeleton" /><view class="skel short tm-skeleton" /></view>
        </view>
      </view>
      <view v-else-if="items.length" class="card">
        <view v-for="item in items" :key="item.id" class="sh-item" @click="openDetail(item.id)">
          <image v-if="item.cover" class="cover" :src="resolveCover(item.cover)" mode="aspectFill" />
          <view v-else class="cover placeholder"><text>{{ item.title.slice(0, 1) }}</text></view>
          <view class="main">
            <text class="title">{{ item.title }}</text>
            <view class="tags">
              <text class="tag tag-green">{{ item.condition_label }}</text>
              <text v-if="item.category" class="tag">{{ item.category.name }}</text>
            </view>
            <text class="meta">{{ item.location || profile?.campus_name || '校内' }} · {{ relativeTime(item.created_at) }}</text>
            <view class="prow"><text class="price">¥{{ item.price }}</text></view>
          </view>
        </view>
      </view>
      <view v-else class="empty"><text class="empty-title">TA 暂时没有在售宝贝</text></view>
    </view>

    <!-- 已售（仅展示数量，明细不公开） -->
    <view v-else-if="tab === 'sold'" class="empty">
      <text class="empty-title">共成交 {{ profile?.sold_count ?? 0 }} 件</text>
      <text class="empty-sub">为保护交易双方隐私，已售宝贝明细不对外展示</text>
    </view>

    <!-- 评价列表 -->
    <view v-else>
      <view v-if="loading" class="card"><view v-for="i in 3" :key="i" class="rating-skel tm-skeleton" /></view>
      <view v-else-if="ratings.length">
        <view v-for="r in ratings" :key="r.id" class="card rating-card">
          <view class="r-head">
            <image v-if="r.rater?.avatar" class="r-avatar" :src="resolveCover(r.rater.avatar)" mode="aspectFill" />
            <view v-else class="r-avatar placeholder"><text>{{ (r.rater?.nickname || '同').slice(0, 1) }}</text></view>
            <view class="r-main">
              <text class="r-name">{{ r.rater?.nickname || '同学' }}</text>
              <text class="r-time">{{ relativeTime(r.created_at) }}</text>
            </view>
            <view class="r-stars"><text v-for="i in 5" :key="i" :class="{ on: i <= r.score }">★</text></view>
          </view>
          <text v-if="r.content" class="r-content">{{ r.content }}</text>
          <view v-if="r.images.length" class="r-images">
            <image v-for="(img, idx) in r.images" :key="idx" class="r-img" :src="resolveCover(img)" mode="aspectFill" @click="previewRatingImages(r.images, idx)" />
          </view>
          <view v-if="r.reply_content" class="r-reply">
            <text class="r-reply-label">卖家回复：</text>{{ r.reply_content }}
          </view>
        </view>
      </view>
      <view v-else class="empty"><text class="empty-title">还没有收到评价</text></view>
    </view>
  </view>
</template>

<script setup lang="ts">
import { onLoad } from '@dcloudio/uni-app'
import { ref } from 'vue'
import { useAuthStore } from '@/stores/auth'
import {
  getSecondHandSellerProfile, listSecondHandItems, listUserSecondHandRatings,
  type SecondHandItem, type SecondHandRating, type SecondHandSellerProfile,
} from '@/api/second-hand'
import { ensureChatConversation } from '@/api/chat'
import { resolveApiAssetUrl } from '@/api/http'
import { formatDate } from '@/utils/dateTime'

const auth = useAuthStore()
const sellerId = ref('')
const profile = ref<SecondHandSellerProfile | null>(null)
const tab = ref<'on_sale' | 'sold' | 'ratings'>('on_sale')
const items = ref<SecondHandItem[]>([])
const ratings = ref<SecondHandRating[]>([])
const loading = ref(false)

function resolveCover(id: string | null) { return resolveApiAssetUrl(id) }
function relativeTime(s: string) { return formatDate(s, '') }

onLoad((q) => {
  sellerId.value = q?.id || ''
  loadProfile()
  loadItems()
})

async function loadProfile() {
  if (!sellerId.value) return
  try { profile.value = await getSecondHandSellerProfile(sellerId.value, auth.accessToken || undefined) }
  catch { uni.showToast({ title: '用户信息加载失败', icon: 'none' }) }
}

function switchTab(t: 'on_sale' | 'sold' | 'ratings') {
  if (tab.value === t) return
  tab.value = t
  if (t === 'on_sale' && !items.value.length) loadItems()
  if (t === 'ratings' && !ratings.value.length) loadRatings()
}

async function loadItems() {
  if (!auth.accessToken) return
  loading.value = true
  try {
    const res = await listSecondHandItems(auth.accessToken, { seller: sellerId.value, page_size: 50 })
    items.value = res.items
  } catch { uni.showToast({ title: '宝贝列表加载失败', icon: 'none' }) }
  finally { loading.value = false }
}

async function loadRatings() {
  if (!auth.accessToken) return
  loading.value = true
  try {
    const res = await listUserSecondHandRatings(auth.accessToken, sellerId.value, { page_size: 50 })
    ratings.value = res.items
  } catch { uni.showToast({ title: '评价加载失败', icon: 'none' }) }
  finally { loading.value = false }
}

function openDetail(id: string) { uni.navigateTo({ url: `/pages/second-hand-detail/index?id=${id}` }) }
function goBack() { uni.navigateBack() }

async function contact() {
  if (!auth.isAuthenticated) { uni.navigateTo({ url: '/pages/login/index' }); return }
  try {
    const conv = await ensureChatConversation(auth.accessToken, { user_id: sellerId.value })
    uni.navigateTo({ url: `/pages/chat-detail/index?id=${conv.id}` })
  } catch { uni.showToast({ title: '发起聊天失败', icon: 'none' }) }
}

function previewRatingImages(images: string[], idx: number) {
  uni.previewImage({ urls: images.map(i => resolveApiAssetUrl(i)), current: idx })
}
</script>

<style scoped>
.page { --green:#0BBF63; --green-deep:#08A152; --green-soft:#E7F9EF; --amber:#FFB020; --t1:#191B1F; --t2:#767C85; --t3:#AEB3BB; --line:#EDEFEF; --price:#FF2442; min-height:100vh; background:#F4F6F4; padding-bottom:60rpx; }
.subnav { display:flex; align-items:center; height:88rpx; padding:0 20rpx; }
.back { width:64rpx; height:64rpx; display:flex; align-items:center; justify-content:center; font-size:44rpx; color:var(--t1); }
.nav-title { flex:1; text-align:center; font-size:32rpx; font-weight:800; }
.nav-right { width:64rpx; }
.card { background:#fff; border-radius:24rpx; margin:0 24rpx; padding:24rpx 28rpx; }
.seller-card { margin-top:8rpx; }
.seller-row { display:flex; align-items:center; gap:20rpx; }
.avatar { width:104rpx; height:104rpx; border-radius:50%; background:#F2F4F3; flex:none; }
.avatar.placeholder { display:flex; align-items:center; justify-content:center; color:var(--t3); font-size:40rpx; }
.seller-main { flex:1; min-width:0; }
.name-line { display:flex; align-items:center; gap:12rpx; }
.name { font-size:32rpx; font-weight:800; color:var(--t1); }
.badge { font-size:19rpx; font-weight:600; color:var(--green-deep); background:var(--green-soft); border-radius:8rpx; padding:4rpx 12rpx; }
.sub { display:block; font-size:22rpx; color:var(--t3); margin-top:10rpx; }
.chat-btn { margin:0; height:60rpx; line-height:60rpx; padding:0 30rpx; border-radius:30rpx; background:var(--green-soft); color:var(--green-deep); font-size:25rpx; font-weight:700; flex:none; }
.chat-btn::after { border:0; }
.stats { display:flex; margin-top:24rpx; padding-top:22rpx; border-top:2rpx solid #F5F6F6; }
.stat { flex:1; text-align:center; }
.stat .num { display:block; font-size:32rpx; font-weight:800; color:var(--t1); }
.stat .num.green { color:var(--green-deep); }
.stat .lab { display:block; font-size:21rpx; color:var(--t3); margin-top:6rpx; }
.chips { display:flex; gap:16rpx; padding:24rpx; }
.chip { height:60rpx; line-height:60rpx; padding:0 28rpx; border-radius:30rpx; background:#fff; font-size:25rpx; color:var(--t2); }
.chip.on { background:var(--green); color:#fff; font-weight:700; }
.sh-item { display:flex; gap:20rpx; padding:22rpx 0; }
.sh-item + .sh-item { border-top:2rpx solid #F5F6F6; }
.cover { width:150rpx; height:150rpx; border-radius:18rpx; background:#F2F4F3; flex:none; }
.cover.placeholder { display:flex; align-items:center; justify-content:center; color:var(--t3); font-size:44rpx; }
.main { flex:1; min-width:0; }
.title { font-size:27rpx; color:var(--t1); font-weight:600; line-height:1.4; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; }
.tags { display:flex; gap:10rpx; margin-top:10rpx; }
.tag { font-size:20rpx; padding:4rpx 14rpx; border-radius:8rpx; background:#F2F4F3; color:var(--t2); }
.tag-green { background:var(--green-soft); color:var(--green-deep); }
.meta { font-size:21rpx; color:var(--t3); margin-top:10rpx; display:block; }
.prow { margin-top:8rpx; }
.price { font-size:30rpx; color:var(--price); font-weight:800; }
.skel { height:28rpx; border-radius:8rpx; margin:18rpx 0; }
.skel.short { width:50%; }
.rating-card + .rating-card, .card + .rating-card { margin-top:16rpx; }
.rating-skel { height:180rpx; border-radius:24rpx; margin:0 24rpx 16rpx; }
.r-head { display:flex; align-items:center; gap:16rpx; }
.r-avatar { width:72rpx; height:72rpx; border-radius:50%; background:#F2F4F3; flex:none; }
.r-avatar.placeholder { display:flex; align-items:center; justify-content:center; color:var(--t3); font-size:28rpx; }
.r-main { flex:1; min-width:0; }
.r-name { font-size:26rpx; font-weight:700; color:var(--t1); display:block; }
.r-time { font-size:20rpx; color:var(--t3); margin-top:4rpx; display:block; }
.r-stars { font-size:26rpx; color:#D6DAD8; letter-spacing:4rpx; }
.r-stars .on { color:var(--amber); }
.r-content { font-size:26rpx; color:var(--t1); line-height:1.6; margin-top:16rpx; display:block; }
.r-images { display:flex; gap:12rpx; margin-top:16rpx; flex-wrap:wrap; }
.r-img { width:140rpx; height:140rpx; border-radius:14rpx; background:#F2F4F3; }
.r-reply { margin-top:16rpx; padding:18rpx 20rpx; border-radius:16rpx; background:#F7F9F8; font-size:23rpx; color:var(--t2); line-height:1.55; }
.r-reply-label { color:var(--green-deep); font-weight:700; }
.empty { display:flex; flex-direction:column; align-items:center; padding:120rpx 48rpx; }
.empty-title { font-size:28rpx; font-weight:700; color:var(--t1); }
.empty-sub { font-size:23rpx; color:var(--t3); margin-top:12rpx; text-align:center; line-height:1.6; }
</style>
