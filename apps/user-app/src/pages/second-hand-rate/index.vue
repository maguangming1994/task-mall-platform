<template>
  <view>
    <ModulePlaceholder v-if="modules.resolved && !moduleEnabled" code="module_second_hand" />
    <view v-else-if="!modules.resolved" class="mp-skeleton">
      <view class="tm-skeleton" style="height: 200rpx; border-radius: 24rpx; margin: 24rpx 28rpx;" />
      <view class="tm-skeleton" style="height: 320rpx; border-radius: 24rpx; margin: 20rpx 28rpx;" />
    </view>
    <template v-else>
  <view class="page" v-if="order">
    <!-- 商品回顾 -->
    <view class="card goods">
      <image v-if="order.cover" class="cover" :src="resolveCover(order.cover)" mode="aspectFill" />
      <view v-else class="cover placeholder"><text>{{ order.title.slice(0, 1) }}</text></view>
      <view class="goods-main">
        <text class="goods-title">{{ order.title }}</text>
        <text v-if="order.counterparty" class="goods-sub">{{ order.role === 'buyer' ? '卖家' : '买家' }} {{ order.counterparty.nickname }}<text v-if="order.completed_at"> · {{ formatDate(order.completed_at) }} 完成</text></text>
      </view>
    </view>

    <!-- 评分与感受 -->
    <view class="card form">
      <view class="frow">
        <text class="label">总体评价</text>
        <view class="stars">
          <text v-for="i in 5" :key="i" class="star" :class="{ on: i <= score }" @click="score = i">★</text>
        </view>
      </view>
      <view class="frow textarea-row">
        <textarea v-model="content" maxlength="1000" placeholder="宝贝成色如何？交易过程顺利吗？说说你的感受…" auto-height />
      </view>
    </view>

    <!-- 上传图片 -->
    <view class="card form">
      <view class="frow upload-row">
        <text class="label">上传图片</text>
        <view class="upload-grid">
          <view v-for="(img, idx) in images" :key="img.fileId" class="u-item">
            <image :src="img.previewUrl" mode="aspectFill" @click="preview(idx)" />
            <text class="u-del" @click.stop="images.splice(idx, 1)">×</text>
          </view>
          <view v-if="images.length < 9" class="u-item add" @click="upload">
            <text class="u-plus">＋</text>
            <text class="u-text">添加</text>
          </view>
        </view>
      </view>
    </view>

    <view class="action-bar">
      <button class="ab-main full" :disabled="submitting || score === 0" @click="submit">提交评价</button>
    </view>
  </view>
    </template>
  </view>
</template>

<script setup lang="ts">
import { onLoad } from '@dcloudio/uni-app'
import { computed, ref } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { getSecondHandOrder, rateSecondHandOrder, type SecondHandOrder } from '@/api/second-hand'
import { chooseAndUploadPrivateImage } from '@/api/upload'
import { resolveApiAssetUrl } from '@/api/http'
import { formatDate } from '@/utils/dateTime'
import ModulePlaceholder from '@/components/ModulePlaceholder.vue'
import { useModulesStore } from '@/stores/modules'

const modules = useModulesStore()
const moduleEnabled = computed(() => modules.isEnabled('module_second_hand'))

const auth = useAuthStore()
const order = ref<SecondHandOrder | null>(null)
const orderId = ref('')
const score = ref(5)
const content = ref('')
const images = ref<Array<{ fileId: string; previewUrl: string }>>([])
const submitting = ref(false)
const uploading = ref(false)

function resolveCover(id: string | null) { return resolveApiAssetUrl(id) }

onLoad((q) => { orderId.value = q?.id || ''; load() })

async function load() {
  await modules.ensureReady()
  if (!moduleEnabled.value) return
  if (!auth.accessToken || !orderId.value) return
  try {
    const data = await getSecondHandOrder(auth.accessToken, orderId.value)
    if (data.status !== 'completed') {
      uni.showToast({ title: '订单完成后才能评价', icon: 'none' })
      setTimeout(() => uni.navigateBack(), 600)
      return
    }
    if (data.has_rated) {
      uni.showToast({ title: '已评价过该订单', icon: 'none' })
      setTimeout(() => uni.navigateBack(), 600)
      return
    }
    order.value = data
  } catch { uni.showToast({ title: '加载失败', icon: 'none' }) }
}

async function upload() {
  if (uploading.value || !auth.accessToken) return
  uploading.value = true
  try { images.value.push(await chooseAndUploadPrivateImage(auth.accessToken, 'second_hand')) }
  catch (e: any) { uni.showToast({ title: e.message || '图片上传失败', icon: 'none' }) }
  finally { uploading.value = false }
}

function preview(idx: number) {
  uni.previewImage({ urls: images.value.map((i) => i.previewUrl), current: idx })
}

async function submit() {
  if (!order.value || !auth.accessToken || submitting.value || score.value === 0) return
  submitting.value = true
  try {
    await rateSecondHandOrder(auth.accessToken, order.value.id, {
      score: score.value,
      content: content.value.trim() || undefined,
      images: images.value.length ? images.value.map((i) => i.fileId) : undefined,
    })
    uni.showToast({ title: '评价成功', icon: 'success' })
    setTimeout(() => uni.navigateBack(), 600)
  } catch (e: any) {
    uni.showToast({ title: e.message || '提交失败', icon: 'none' })
    submitting.value = false
  }
}
</script>

<style scoped>
.page { --green:#0BBF63; --green-deep:#08A152; --green-grad:linear-gradient(135deg,#12D978,#08A152); --green-soft:#E7F9EF; --amber:#FFB020; --t1:#191B1F; --t2:#767C85; --t3:#AEB3BB; --line:#EDEFEF; min-height:100vh; background:#F4F6F4; padding-bottom:180rpx; }
.card { background:#fff; border-radius:24rpx; margin:20rpx 24rpx 0; }
.goods { display:flex; gap:22rpx; align-items:center; padding:24rpx 28rpx; }
.cover { width:108rpx; height:108rpx; border-radius:20rpx; background:#F2F4F3; flex:none; }
.cover.placeholder { display:flex; align-items:center; justify-content:center; color:var(--t3); font-size:40rpx; }
.goods-main { flex:1; min-width:0; }
.goods-title { font-size:26rpx; color:var(--t1); line-height:1.4; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; }
.goods-sub { font-size:22rpx; color:var(--t3); margin-top:8rpx; display:block; }
.form { padding:4rpx 28rpx; }
.frow { display:flex; align-items:center; gap:20rpx; padding:28rpx 0; border-bottom:2rpx solid #F5F6F6; }
.frow:last-child { border-bottom:0; }
.label { font-size:28rpx; font-weight:600; width:128rpx; flex:none; }
.stars { display:flex; gap:12rpx; flex:1; }
.star { font-size:52rpx; color:#D6DAD8; line-height:1; }
.star.on { color:var(--amber); }
.textarea-row { align-items:flex-start; }
.textarea-row textarea { width:100%; min-height:160rpx; font-size:28rpx; line-height:1.6; color:var(--t1); }
.upload-row { align-items:flex-start; }
.upload-row .label { padding-top:24rpx; }
.upload-grid { flex:1; display:grid; grid-template-columns:repeat(4,1fr); gap:16rpx; padding:16rpx 0 24rpx; }
.u-item { position:relative; width:100%; aspect-ratio:1; border-radius:16rpx; overflow:hidden; background:#F2F4F3; }
.u-item image { width:100%; height:100%; }
.u-del { position:absolute; top:4rpx; right:4rpx; width:36rpx; height:36rpx; border-radius:50%; background:rgba(25,27,31,.6); color:#fff; font-size:24rpx; line-height:36rpx; text-align:center; }
.u-item.add { border:3rpx dashed #D7DBD8; background:#F7F9F8; display:flex; flex-direction:column; align-items:center; justify-content:center; gap:8rpx; color:var(--t3); }
.u-plus { font-size:40rpx; }
.u-text { font-size:20rpx; }
.action-bar { position:fixed; left:0; right:0; bottom:0; background:#fff; border-top:2rpx solid var(--line); padding:16rpx 24rpx calc(16rpx + env(safe-area-inset-bottom)); }
.action-bar button { margin:0; }
.action-bar button::after { border:0; }
.ab-main.full { width:100%; height:84rpx; line-height:84rpx; border-radius:42rpx; background:var(--green-grad); color:#fff; font-size:30rpx; font-weight:700; box-shadow:0 12rpx 28rpx rgba(11,191,99,.3); }
.ab-main[disabled] { opacity:.6; }
</style>
