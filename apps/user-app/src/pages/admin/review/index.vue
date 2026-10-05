<template>
  <view class="page">
    <view class="head" :style="{ paddingTop: `calc(var(--status-bar-height) + 24rpx)` }">
      <view class="head-top">
        <text class="head-title">审核工作台</text>
        <text class="logout" @click="logout">退出</text>
      </view>
      <view class="head-user">
        <view class="avatar">{{ admin.displayName.slice(0, 1) }}</view>
        <view class="head-user-main">
          <text class="head-name">{{ admin.displayName }}</text>
          <text class="head-roles">{{ admin.roleNames.length ? admin.roleNames.join(' · ') : '管理员' }}</text>
        </view>
      </view>
    </view>

    <view class="body">
      <view v-if="loading" class="tip">待办加载中…</view>
      <view v-else-if="!admin.hasAnyReviewAccess" class="tip card-box">
        <text class="tip-main">当前角色暂无审核权限</text>
        <text class="tip-sub">请联系超级管理员在后台「权限与审计」中分配审核角色。</text>
      </view>
      <template v-else>
        <view v-if="admin.canReviewCommunity" class="work-card" @click="openSecondHand">
          <view class="wc-icon tone-orange">闲</view>
          <view class="wc-main">
            <text class="wc-title">闲置商品审核</text>
            <text class="wc-sub">审核同学们发布的二手商品</text>
          </view>
          <view class="wc-badge" :class="{ zero: !summary.second_hand_pending }">{{ summary.second_hand_pending ?? 0 }}</view>
        </view>

        <view v-if="admin.canReviewCommunity" class="work-card" @click="openCommunity">
          <view class="wc-icon tone-green">社</view>
          <view class="wc-main">
            <text class="wc-title">社区内容审核</text>
            <text class="wc-sub">帖子 · 评论 · 举报处置</text>
          </view>
          <view class="wc-badge" :class="{ zero: !(totalCommunity) }">{{ totalCommunity }}</view>
        </view>

        <view v-if="admin.canReviewTask" class="work-card" @click="openTasks">
          <view class="wc-icon tone-blue">任</view>
          <view class="wc-main">
            <text class="wc-title">任务提交审核</text>
            <text class="wc-sub">领取审核 · 逐步核验 · 通过/驳回</text>
          </view>
          <view class="wc-badge" :class="{ zero: !summary.task_submissions_pending }">{{ summary.task_submissions_pending ?? 0 }}</view>
        </view>

        <view class="legend card-box">
          <text class="legend-title">待办口径</text>
          <text v-if="admin.canReviewCommunity" class="legend-row">· 闲置/社区：待审核商品、待审帖子评论与待处理举报</text>
          <text v-if="admin.canReviewTask" class="legend-row">· 任务：已提交待领取与审核中的提交单</text>
          <text class="legend-tip">所有审核操作都会记录审计日志，请谨慎操作。</text>
        </view>
      </template>
    </view>
  </view>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue'
import { onShow } from '@dcloudio/uni-app'
import { ApiError } from '@/api/http'
import { fetchReviewSummary, type ReviewSummary } from '@/api/admin/review'
import { useAdminStore } from '@/stores/admin'

const admin = useAdminStore()
const loading = ref(true)
const summary = ref<ReviewSummary>({ permissions: { community: false, task: false } })

const totalCommunity = computed(() =>
  (summary.value.community_posts_pending ?? 0)
  + (summary.value.community_comments_pending ?? 0)
  + (summary.value.community_reports_pending ?? 0))

onShow(() => {
  if (!admin.isLoggedIn) {
    uni.redirectTo({ url: '/pages/admin/login/index' })
    return
  }
  void load()
})

async function load() {
  loading.value = true
  try {
    // 刷新档案，确保权限变更（后台调整角色后）及时生效
    try {
      await admin.fetchMe()
    } catch {
      // 令牌刷新失败时 http 层已清理会话
      uni.redirectTo({ url: '/pages/admin/login/index' })
      return
    }
    if (admin.hasAnyReviewAccess) {
      summary.value = await fetchReviewSummary()
    }
  } catch (error) {
    if (error instanceof ApiError && (error.code === 'ADMIN_SESSION_EXPIRED' || error.statusCode === 401)) {
      admin.clear()
      uni.redirectTo({ url: '/pages/admin/login/index' })
      return
    }
    uni.showToast({ title: error instanceof Error ? error.message : '待办加载失败', icon: 'none' })
  } finally {
    loading.value = false
  }
}

function openSecondHand() { uni.navigateTo({ url: '/pages/admin/second-hand/index' }) }
function openCommunity() { uni.navigateTo({ url: '/pages/admin/community/index' }) }
function openTasks() { uni.navigateTo({ url: '/pages/admin/tasks/index' }) }

async function logout() {
  const result = await uni.showModal({ title: '退出登录', content: '确认退出审核工作台？' })
  if (!result.confirm) return
  await admin.logout()
  uni.reLaunch({ url: '/pages/account/index' })
}
</script>

<style scoped>
.page{min-height:100vh;background:#F4F6F4}
.head{padding:0 36rpx 120rpx;background:linear-gradient(135deg,#12D978,#08A152)}
.head-top{display:flex;align-items:center;justify-content:space-between}
.head-title{color:#fff;font-size:34rpx;font-weight:800}
.logout{padding:8rpx 24rpx;border-radius:24rpx;background:rgba(255,255,255,.18);color:#fff;font-size:22rpx}
.head-user{display:flex;align-items:center;gap:22rpx;margin-top:32rpx}
.avatar{display:flex;align-items:center;justify-content:center;width:92rpx;height:92rpx;border-radius:50%;background:rgba(255,255,255,.25);color:#fff;font-size:38rpx;font-weight:800}
.head-user-main{display:flex;flex-direction:column}
.head-name{color:#fff;font-size:34rpx;font-weight:800}
.head-roles{margin-top:8rpx;color:rgba(255,255,255,.82);font-size:22rpx}
.body{margin-top:-72rpx;padding:0 24rpx 48rpx}
.work-card{display:flex;align-items:center;margin-bottom:20rpx;padding:32rpx 28rpx;border-radius:28rpx;background:#fff;box-shadow:0 2rpx 4rpx rgba(23,26,23,.03),0 12rpx 40rpx rgba(23,26,23,.05)}
.wc-icon{display:flex;align-items:center;justify-content:center;width:88rpx;height:88rpx;flex:none;border-radius:24rpx;font-size:36rpx;font-weight:800}
.wc-main{flex:1;margin-left:24rpx;min-width:0}
.wc-title{display:block;color:#191B1F;font-size:30rpx;font-weight:700}
.wc-sub{display:block;margin-top:10rpx;color:#767C85;font-size:22rpx}
.wc-badge{display:flex;align-items:center;justify-content:center;min-width:56rpx;height:56rpx;padding:0 16rpx;border-radius:28rpx;background:#FF3B30;color:#fff;font-size:28rpx;font-weight:800}
.wc-badge.zero{background:#DDE3DE;color:#8A918A}
.tone-orange{background:#FFF2E5;color:#F2620A}
.tone-green{background:#E7F9EF;color:#08A152}
.tone-blue{background:#EAF2FF;color:#3B82F6}
.card-box{border-radius:28rpx;background:#fff;box-shadow:0 2rpx 4rpx rgba(23,26,23,.03),0 12rpx 40rpx rgba(23,26,23,.05)}
.tip{padding:40rpx 32rpx;color:#767C85;font-size:26rpx}
.tip-main{display:block;color:#191B1F;font-size:30rpx;font-weight:700;text-align:center}
.tip-sub{display:block;margin-top:16rpx;color:#767C85;font-size:24rpx;line-height:1.6;text-align:center}
.legend{margin-top:12rpx;padding:28rpx 30rpx}
.legend-title{display:block;color:#191B1F;font-size:26rpx;font-weight:700;margin-bottom:14rpx}
.legend-row{display:block;color:#767C85;font-size:22rpx;line-height:1.8}
.legend-tip{display:block;margin-top:14rpx;color:#AEB3BB;font-size:20rpx;line-height:1.6}
</style>
