<template>
  <view class="page">
    <view v-if="item" class="content">
      <swiper v-if="images.length" class="swiper" :indicator-dots="images.length > 1" circular indicator-color="rgba(255,255,255,.5)" indicator-active-color="#fff">
        <swiper-item v-for="(img, idx) in images" :key="idx" @click="previewImage(idx)">
          <image class="swiper-img" :src="img" mode="aspectFill" />
        </swiper-item>
      </swiper>
      <view v-else class="swiper no-image"><text>暂无图片</text></view>

      <view class="panel">
        <view class="title-row">
          <text class="price">¥{{ item.price }}</text>
          <text :class="['audit-tag', `tag-${item.audit_status}`]">{{ auditLabel }}</text>
        </view>
        <text class="title">{{ item.title }}</text>
        <view class="info-row">
          <text v-if="item.category" class="info-chip">{{ item.category.name }}</text>
          <text class="info-chip">{{ item.condition_label }}</text>
          <text v-if="item.location" class="info-chip">📍 {{ item.location }}</text>
        </view>
        <text v-if="item.description" class="desc">{{ item.description }}</text>
      </view>

      <view class="panel">
        <text class="panel-title">卖家信息</text>
        <view v-if="item.seller" class="seller-grid">
          <view class="seller-cell"><b>{{ item.seller.nickname || '同学' }}</b><span>昵称</span></view>
          <view class="seller-cell">
            <b :class="{ verified: item.seller.identity_verified }">{{ item.seller.identity_verified ? '已实名' : '未实名' }}</b><span>实名状态</span>
          </view>
          <view class="seller-cell"><b>{{ item.seller.rating_average ?? '—' }}</b><span>平均信用（{{ item.seller.rating_count }}条）</span></view>
          <view class="seller-cell"><b>{{ item.seller.on_sale_count }}</b><span>在售</span></view>
          <view class="seller-cell"><b>{{ item.seller.sold_count }}</b><span>已成交</span></view>
          <view class="seller-cell"><b>{{ formatRelativeTime(item.submitted_at) }}</b><span>提交时间</span></view>
        </view>
      </view>

      <view v-if="item.audit_status !== 'pending'" class="panel result-panel">
        <text class="panel-title">审核结果</text>
        <text class="result-line">审核人：{{ item.reviewed_by_name || '—' }}</text>
        <text class="result-line">审核时间：{{ formatDateTime(item.reviewed_at) || '—' }}</text>
        <text v-if="item.audit_status === 'rejected'" class="result-reason">驳回原因：{{ item.rejected_reason || '—' }}</text>
      </view>
    </view>

    <view v-else class="loading-box">{{ loadError ? '加载失败，请返回重试' : '加载中…' }}</view>

    <view v-if="item" class="footer" :class="{ disabled: item.audit_status !== 'pending' }">
      <button v-if="item.audit_status === 'pending'" class="btn reject" :disabled="submitting" @click="onReject">驳回</button>
      <button v-if="item.audit_status === 'pending'" class="btn approve" :disabled="submitting" @click="onApprove">通过</button>
      <text v-else class="finished-tip">该商品已完成审核（{{ auditLabel }}），不能重复操作</text>
    </view>
  </view>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue'
import { onLoad } from '@dcloudio/uni-app'
import { ApiError, resolveApiAssetUrl } from '@/api/http'
import {
  fetchSecondHandItem,
  reviewSecondHandItem,
  type SecondHandReviewItem,
} from '@/api/admin/review'
import { useAdminStore } from '@/stores/admin'
import { SECOND_HAND_REJECT_REASONS, chooseRejectReason } from '../use-reject'
import { formatDateTime, formatRelativeTime } from '../format'

const admin = useAdminStore()
const item = ref<SecondHandReviewItem | null>(null)
const submitting = ref(false)
const loadError = ref(false)

const images = computed(() => (item.value?.images || []).map(src => resolveApiAssetUrl(src)).filter(Boolean) as string[])
const auditLabel = computed(() => {
  const status = item.value?.audit_status
  return status === 'approved' ? '已通过' : status === 'rejected' ? '已拒绝' : '待审核'
})

onLoad((query) => {
  const id = String(query?.id || '')
  if (!id || !admin.isLoggedIn) {
    uni.redirectTo({ url: '/pages/admin/login/index' })
    return
  }
  void load(id)
})

async function load(id: string) {
  try {
    item.value = await fetchSecondHandItem(id)
  } catch (error) {
    loadError.value = true
    if (error instanceof ApiError && (error.code === 'ADMIN_SESSION_EXPIRED' || error.statusCode === 401)) {
      admin.clear()
      uni.redirectTo({ url: '/pages/admin/login/index' })
      return
    }
    uni.showToast({ title: error instanceof Error ? error.message : '加载失败', icon: 'none' })
  }
}

function previewImage(index: number) {
  if (!images.value.length) return
  uni.previewImage({ urls: images.value, current: images.value[index] })
}

async function onApprove() {
  if (!item.value || submitting.value) return
  const confirmed = await uni.showModal({
    title: '确认通过审核？',
    content: `通过后「${item.value.title}」将公开展示。`,
    confirmText: '确认通过',
    confirmColor: '#08A152',
  }).catch(() => null)
  if (!confirmed?.confirm) return
  await submit('approve')
}

async function onReject() {
  if (!item.value || submitting.value) return
  const reason = await chooseRejectReason(SECOND_HAND_REJECT_REASONS)
  if (!reason) return
  await submit('reject', reason)
}

async function submit(decision: 'approve' | 'reject', reason?: string) {
  if (!item.value) return
  submitting.value = true
  try {
    item.value = await reviewSecondHandItem(item.value.id, decision, reason)
    uni.showToast({ title: decision === 'approve' ? '已通过审核' : '已驳回', icon: 'success' })
    setTimeout(() => uni.navigateBack(), 700)
  } catch (error) {
    if (error instanceof ApiError && error.code === 'REVIEW_ALREADY_FINISHED') {
      if (item.value) await load(item.value.id)
    }
    uni.showToast({ title: error instanceof Error ? error.message : '操作失败', icon: 'none' })
  } finally {
    submitting.value = false
  }
}
</script>

<style scoped>
.page{min-height:100vh;padding-bottom:160rpx;background:#F4F6F4}
.swiper{width:100%;height:560rpx;background:#E7EAE7}
.swiper-img{width:100%;height:560rpx}
.no-image{display:flex;align-items:center;justify-content:center;color:#AEB3BB;font-size:26rpx}
.panel{margin:20rpx 24rpx 0;padding:30rpx 28rpx;border-radius:24rpx;background:#fff;box-shadow:0 2rpx 4rpx rgba(23,26,23,.03),0 8rpx 28rpx rgba(23,26,23,.04)}
.title-row{display:flex;align-items:center;justify-content:space-between}
.price{color:#E54D42;font-size:44rpx;font-weight:800}
.audit-tag{padding:6rpx 18rpx;border-radius:20rpx;font-size:22rpx}
.tag-pending{background:#FFF6DE;color:#B8860B}
.tag-approved{background:#E7F9EF;color:#08A152}
.tag-rejected{background:#FFEAEA;color:#D14343}
.title{display:block;margin-top:16rpx;color:#191B1F;font-size:32rpx;font-weight:700;line-height:1.5}
.info-row{display:flex;flex-wrap:wrap;gap:12rpx;margin-top:18rpx}
.info-chip{padding:6rpx 16rpx;border-radius:16rpx;background:#F4F6F4;color:#767C85;font-size:20rpx}
.desc{display:block;margin-top:20rpx;color:#50565C;font-size:26rpx;line-height:1.7;white-space:pre-wrap}
.panel-title{display:block;margin-bottom:20rpx;color:#191B1F;font-size:28rpx;font-weight:700}
.seller-grid{display:flex;flex-wrap:wrap}
.seller-cell{width:33.33%;padding:12rpx 0}
.seller-cell b{display:block;color:#191B1F;font-size:26rpx;font-weight:700}
.seller-cell b.verified{color:#08A152}
.seller-cell span{display:block;margin-top:6rpx;color:#AEB3BB;font-size:20rpx}
.result-panel .result-line{display:block;color:#50565C;font-size:24rpx;line-height:2}
.result-reason{display:block;margin-top:10rpx;color:#D14343;font-size:24rpx;line-height:1.7}
.loading-box{padding:200rpx 48rpx;text-align:center;color:#767C85;font-size:26rpx}
.footer{position:fixed;left:0;right:0;bottom:0;display:flex;gap:20rpx;padding:20rpx 24rpx calc(20rpx + env(safe-area-inset-bottom));background:#fff;box-shadow:0 -4rpx 20rpx rgba(23,26,23,.06)}
.btn{flex:1;height:88rpx;border-radius:44rpx;font-size:30rpx;font-weight:800;line-height:88rpx}
.btn::after{border:0}
.btn.reject{background:#FFEAEA;color:#D14343}
.btn.approve{background:linear-gradient(135deg,#12D978,#08A152);color:#fff}
.btn[disabled]{opacity:.6}
.footer.disabled{justify-content:center}
.finished-tip{color:#AEB3BB;font-size:24rpx;line-height:88rpx}
</style>
