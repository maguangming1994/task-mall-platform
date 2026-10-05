<template>
  <view class="page">
    <view class="tabs">
      <view :class="['tab', tab === 'posts' && 'active']" @click="switchTab('posts')">待审帖子</view>
      <view :class="['tab', tab === 'comments' && 'active']" @click="switchTab('comments')">待审评论</view>
      <view :class="['tab', tab === 'reports' && 'active']" @click="switchTab('reports')">待处理举报</view>
    </view>

    <!-- 帖子 -->
    <view v-if="tab === 'posts'">
      <view v-if="posts.length === 0 && !loading" class="empty"><text class="empty-mark">✓</text><text class="empty-text">暂无待审帖子</text><text class="empty-btn" @click="reload">刷新</text></view>
      <view v-for="post in posts" :key="post.id" class="card">
        <view class="card-head">
          <text class="author">{{ post.author?.nickname || '社区用户' }}</text>
          <text v-if="post.author?.verified" class="verified">已实名</text>
          <text class="time">{{ formatRelativeTime(post.created_at) }}</text>
        </view>
        <text class="post-title">{{ post.title }}</text>
        <text class="post-content">{{ post.content }}</text>
        <text class="post-meta">{{ post.category_label }} · 评论 {{ post.comment_count ?? 0 }} · 点赞 {{ post.like_count ?? 0 }}</text>
        <view class="actions">
          <button class="act reject" :disabled="submitting" @click="rejectPost(post)">驳回</button>
          <button class="act approve" :disabled="submitting" @click="approvePost(post)">通过</button>
        </view>
      </view>
    </view>

    <!-- 评论 -->
    <view v-if="tab === 'comments'">
      <view v-if="comments.length === 0 && !loading" class="empty"><text class="empty-mark">✓</text><text class="empty-text">暂无待审评论</text><text class="empty-btn" @click="reload">刷新</text></view>
      <view v-for="comment in comments" :key="comment.id" class="card">
        <view class="card-head">
          <text class="author">{{ comment.author?.nickname || '社区用户' }}</text>
          <text class="time">{{ formatRelativeTime(comment.created_at) }}</text>
        </view>
        <text class="post-content">{{ comment.content }}</text>
        <view class="actions">
          <button class="act reject" :disabled="submitting" @click="rejectComment(comment)">驳回</button>
          <button class="act approve" :disabled="submitting" @click="approveComment(comment)">通过</button>
        </view>
      </view>
    </view>

    <!-- 举报 -->
    <view v-if="tab === 'reports'">
      <view v-if="reports.length === 0 && !loading" class="empty"><text class="empty-mark">✓</text><text class="empty-text">暂无待处理举报</text><text class="empty-btn" @click="reload">刷新</text></view>
      <view v-for="report in reports" :key="report.id" class="card">
        <view class="card-head">
          <text :class="['report-type', report.content_type === 'post' ? 'type-post' : 'type-comment']">
            {{ report.content_type === 'post' ? '帖子' : '评论' }}举报
          </text>
          <text class="time">{{ formatRelativeTime(report.created_at) }}</text>
        </view>
        <text class="report-reason">举报类型：{{ reportReasonLabel(report.reason_code) }}</text>
        <text v-if="report.description" class="post-content">{{ report.description }}</text>
        <text class="post-meta">被举报内容 ID：{{ report.content_id }}</text>
        <view class="actions">
          <button class="act warn" :disabled="submitting" @click="handleReport(report, 'warning')">警告作者</button>
          <button class="act reject" :disabled="submitting" @click="handleReport(report, 'hide')">隐藏内容</button>
          <button class="act danger" :disabled="submitting" @click="handleReport(report, 'delete')">删除内容</button>
        </view>
      </view>
    </view>

    <text v-if="loading" class="loading-tip">加载中…</text>
  </view>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { onShow } from '@dcloudio/uni-app'
import { ApiError } from '@/api/http'
import {
  fetchCommunityPosts,
  fetchCommunityComments,
  fetchCommunityReports,
  reviewCommunityPost,
  reviewCommunityComment,
  moderateCommunityContent,
  type CommunityPostItem,
  type CommunityCommentItem,
  type CommunityReportItem,
} from '@/api/admin/review'
import { useAdminStore } from '@/stores/admin'
import { COMMUNITY_REJECT_REASONS, chooseRejectReason } from '../use-reject'
import { formatRelativeTime } from '../format'

const admin = useAdminStore()
const tab = ref<'posts' | 'comments' | 'reports'>('posts')
const loading = ref(false)
// 提交中的操作锁，非空期间禁用全部审核/处置按钮，防止移动端连点造成重复提交
const submitting = ref(false)
const posts = ref<CommunityPostItem[]>([])
const comments = ref<CommunityCommentItem[]>([])
const reports = ref<CommunityReportItem[]>([])

// 预设文案 → 后端 reason_code
const reasonCodeMap: Record<string, string> = {
  包含违规违法内容: 'illegal',
  广告营销引流: 'spam',
  抄袭搬运他人内容: 'infringement',
  辱骂引战不友善: 'abuse',
}

const reportReasonLabels: Record<string, string> = {
  spam: '垃圾广告',
  sexual: '色情低俗',
  fraud: '欺诈骗局',
  abuse: '辱骂攻击',
  infringement: '侵权抄袭',
  illegal: '违法违规',
  other: '其他',
}

onShow(() => {
  if (!admin.isLoggedIn) {
    uni.redirectTo({ url: '/pages/admin/login/index' })
    return
  }
  void reload()
})

function reportReasonLabel(code: string) {
  return reportReasonLabels[code] || code
}

async function switchTab(next: typeof tab.value) {
  if (tab.value === next) return
  tab.value = next
  await reload()
}

async function reload() {
  loading.value = true
  try {
    if (tab.value === 'posts') posts.value = (await fetchCommunityPosts()).items
    if (tab.value === 'comments') comments.value = (await fetchCommunityComments()).items
    if (tab.value === 'reports') reports.value = (await fetchCommunityReports()).items
  } catch (error) {
    handleAuthError(error)
  } finally {
    loading.value = false
  }
}

async function approvePost(post: CommunityPostItem) {
  if (submitting.value) return
  const confirmed = await uni.showModal({
    title: '确认通过该帖子？',
    content: '通过后帖子将公开展示，并发放社区奖励。',
    confirmText: '确认通过',
    confirmColor: '#08A152',
  }).catch(() => null)
  if (!confirmed?.confirm) return
  submitting.value = true
  try {
    await reviewCommunityPost(post.id, 'approve', '内容合规，审核通过。')
    uni.showToast({ title: '已通过', icon: 'success' })
    posts.value = posts.value.filter(item => item.id !== post.id)
  } catch (error) {
    handleReviewError(error)
  } finally {
    submitting.value = false
  }
}

async function rejectPost(post: CommunityPostItem) {
  if (submitting.value) return
  const picked = await chooseRejectReason(COMMUNITY_REJECT_REASONS)
  if (!picked) return
  submitting.value = true
  try {
    await reviewCommunityPost(post.id, 'reject', picked, reasonCodeMap[picked] || 'other')
    uni.showToast({ title: '已驳回', icon: 'success' })
    posts.value = posts.value.filter(item => item.id !== post.id)
  } catch (error) {
    handleReviewError(error)
  } finally {
    submitting.value = false
  }
}

async function approveComment(comment: CommunityCommentItem) {
  if (submitting.value) return
  const confirmed = await uni.showModal({
    title: '确认通过该评论？',
    content: '通过后评论将公开展示。',
    confirmText: '确认通过',
    confirmColor: '#08A152',
  }).catch(() => null)
  if (!confirmed?.confirm) return
  submitting.value = true
  try {
    await reviewCommunityComment(comment.id, 'approve', '评论合规，审核通过。')
    uni.showToast({ title: '已通过', icon: 'success' })
    comments.value = comments.value.filter(item => item.id !== comment.id)
  } catch (error) {
    handleReviewError(error)
  } finally {
    submitting.value = false
  }
}

async function rejectComment(comment: CommunityCommentItem) {
  if (submitting.value) return
  const picked = await chooseRejectReason(COMMUNITY_REJECT_REASONS)
  if (!picked) return
  submitting.value = true
  try {
    await reviewCommunityComment(comment.id, 'reject', picked, reasonCodeMap[picked] || 'other')
    uni.showToast({ title: '已驳回', icon: 'success' })
    comments.value = comments.value.filter(item => item.id !== comment.id)
  } catch (error) {
    handleReviewError(error)
  } finally {
    submitting.value = false
  }
}

async function handleReport(report: CommunityReportItem, decision: 'warning' | 'hide' | 'delete') {
  if (submitting.value) return
  const label = decision === 'warning' ? '警告作者' : decision === 'hide' ? '隐藏内容' : '删除内容'
  const modal = await uni.showModal({
    title: `${label}确认`,
    editable: true,
    placeholderText: '请填写处置说明（必填）',
    content: `经核查举报（${reportReasonLabel(report.reason_code)}），予以${label}。`,
    confirmText: '确认处置',
    confirmColor: decision === 'delete' ? '#E54D42' : '#F2620A',
  }).catch(() => null)
  if (!modal?.confirm) return
  const note = String(modal.content || '').trim()
  if (note.length < 2) {
    uni.showToast({ title: '请填写处置说明', icon: 'none' })
    return
  }
  submitting.value = true
  try {
    await moderateCommunityContent(report.content_type, report.content_id, decision, report.reason_code, note.slice(0, 500))
    uni.showToast({ title: '处置完成', icon: 'success' })
    // 处置后刷新举报列表（后端可能已联动关闭举报）
    reports.value = reports.value.filter(item => item.id !== report.id)
  } catch (error) {
    handleReviewError(error)
  } finally {
    submitting.value = false
  }
}

function handleAuthError(error: unknown) {
  if (error instanceof ApiError && (error.code === 'ADMIN_SESSION_EXPIRED' || error.statusCode === 401)) {
    admin.clear()
    uni.redirectTo({ url: '/pages/admin/login/index' })
    return
  }
  uni.showToast({ title: error instanceof Error ? error.message : '加载失败', icon: 'none' })
}

function handleReviewError(error: unknown) {
  if (error instanceof ApiError && error.code === 'REVIEW_ALREADY_FINISHED') {
    uni.showToast({ title: '该内容已被审核，列表已刷新', icon: 'none' })
    void reload()
    return
  }
  handleAuthError(error)
}
</script>

<style scoped>
.page{min-height:100vh;padding:0 24rpx 48rpx;background:#F4F6F4}
.tabs{display:flex;padding:16rpx 0 8rpx;position:sticky;top:0;z-index:5;background:#F4F6F4}
.tab{flex:1;text-align:center;padding:20rpx 0;margin:0 8rpx;border-radius:20rpx;color:#767C85;font-size:26rpx;font-weight:600}
.tab.active{background:#fff;color:#08A152;box-shadow:0 2rpx 8rpx rgba(8,161,82,.12)}
.card{margin-top:16rpx;padding:28rpx;border-radius:24rpx;background:#fff;box-shadow:0 2rpx 4rpx rgba(23,26,23,.03),0 8rpx 28rpx rgba(23,26,23,.04)}
.card-head{display:flex;align-items:center;gap:14rpx}
.author{color:#191B1F;font-size:26rpx;font-weight:700}
.verified{padding:2rpx 12rpx;border-radius:14rpx;background:#E7F9EF;color:#08A152;font-size:18rpx}
.time{margin-left:auto;color:#AEB3BB;font-size:20rpx}
.post-title{display:block;margin-top:16rpx;color:#191B1F;font-size:30rpx;font-weight:700;line-height:1.5}
.post-content{display:block;margin-top:12rpx;color:#50565C;font-size:26rpx;line-height:1.7}
.post-meta{display:block;margin-top:14rpx;color:#AEB3BB;font-size:20rpx}
.report-type{padding:4rpx 16rpx;border-radius:16rpx;font-size:22rpx;font-weight:700}
.type-post{background:#EAF2FF;color:#3B82F6}
.type-comment{background:#F1EAFF;color:#8B5CF6}
.report-reason{display:block;margin-top:16rpx;color:#191B1F;font-size:26rpx;font-weight:600}
.actions{display:flex;gap:16rpx;margin-top:22rpx}
.act{flex:1;height:76rpx;margin:0;border-radius:38rpx;font-size:26rpx;font-weight:700;line-height:76rpx}
.act::after{border:0}
.act.approve{background:linear-gradient(135deg,#12D978,#08A152);color:#fff}
.act.reject{background:#FFEAEA;color:#D14343}
.act.warn{background:#FFF6DE;color:#B8860B}
.act.danger{background:#FFEAEA;color:#E54D42}
.empty{display:flex;align-items:center;flex-direction:column;padding:140rpx 48rpx}
.empty-mark{display:flex;align-items:center;justify-content:center;width:112rpx;height:112rpx;border-radius:50%;background:#E7F9EF;color:#08A152;font-size:52rpx}
.empty-text{margin-top:28rpx;color:#767C85;font-size:26rpx}
.empty-btn{margin-top:36rpx;padding:16rpx 56rpx;border-radius:36rpx;background:#08A152;color:#fff;font-size:26rpx}
.loading-tip{display:block;padding:28rpx;text-align:center;color:#AEB3BB;font-size:22rpx}
</style>
