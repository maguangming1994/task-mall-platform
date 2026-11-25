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
        <view v-for="group in groups" :key="group.key" class="group">
          <text class="group-title">{{ group.title }}</text>
          <view
            v-for="item in group.items"
            :key="item.key"
            class="work-card"
            :class="{ soon: item.soon }"
            @click="openItem(item)"
          >
            <view class="wc-icon" :class="item.tone">{{ item.icon }}</view>
            <view class="wc-main">
              <text class="wc-title">{{ item.title }}</text>
              <text class="wc-sub">{{ item.desc }}</text>
            </view>
            <text v-if="item.soon" class="wc-soon">即将开放</text>
            <view v-else-if="item.badge !== undefined" class="wc-badge" :class="{ zero: !item.badge }">{{ item.badge }}</view>
            <text v-else class="wc-arrow">›</text>
          </view>
        </view>

        <view v-if="wechatH5" class="security card-box">
          <view class="security-main">
            <text class="security-title">免密登录</text>
            <text class="security-sub">{{ binding?.bound ? '已绑定，下次可在公众号内直接登录' : '绑定后下次可直接登录' }}</text>
          </view>
          <text class="security-action" @click="toggleBinding">{{ binding?.bound ? '解绑' : '绑定' }}</text>
        </view>

        <view class="legend card-box">
          <text class="legend-title">待办口径</text>
          <text v-if="admin.canReviewCommunity" class="legend-row">· 闲置/社区：待审核商品、待审帖子评论与待处理举报</text>
          <text v-if="admin.canReviewTask" class="legend-row">· 任务：已提交待领取与审核中的提交单</text>
          <text class="legend-tip">标有「即将开放」的入口将在后续批次上线；所有审核操作都会记录审计日志。</text>
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
import {
  bindWechat, fetchWechatBinding, unbindWechat, type AdminWechatBinding,
} from '@/api/admin/wechat'
import {
  isWechatH5, localAdminWechatCode, setWechatBindingFlag, startAdminWechatAuth,
} from '@/platform/admin-wechat'
import { useAdminStore } from '@/stores/admin'

interface WorkItem {
  key: string
  title: string
  desc: string
  icon: string
  tone: string
  badge?: number | null
  soon?: boolean
  onClick?: () => void
}

interface WorkGroup {
  key: string
  title: string
  items: WorkItem[]
}

const admin = useAdminStore()
const loading = ref(true)
const summary = ref<ReviewSummary>({ permissions: { community: false, task: false } })
const wechatH5 = isWechatH5()
const binding = ref<AdminWechatBinding | null>(null)

const totalCommunity = computed(() =>
  (summary.value.community_posts_pending ?? 0)
  + (summary.value.community_comments_pending ?? 0)
  + (summary.value.community_reports_pending ?? 0))

/** 按权限组装工作台分组，未上线入口标注「即将开放」，不生成空分组 */
const groups = computed<WorkGroup[]>(() => {
  const result: WorkGroup[] = []

  const content: WorkItem[] = []
  if (admin.canReviewCommunity) {
    content.push({
      key: 'community', title: '社区内容审核', desc: '帖子 · 评论 · 举报处置',
      icon: '社', tone: 'tone-green', badge: totalCommunity.value, onClick: openCommunity,
    })
    content.push({
      key: 'second-hand', title: '闲置商品审核', desc: '审核同学们发布的二手商品',
      icon: '闲', tone: 'tone-orange', badge: summary.value.second_hand_pending ?? 0, onClick: openSecondHand,
    })
    content.push({ key: 'activities', title: '组团活动审核', desc: '活动审核 · 关闭 · 移出参与人', icon: '团', tone: 'tone-purple', onClick: openActivities })
  }
  if (admin.canManageMaterial) {
    content.push({
      key: 'material', title: '资料共享审核', desc: '资料 · 求资料 · 举报处理',
      icon: '资', tone: 'tone-blue',
      badge: (summary.value.materials_pending ?? 0) + (summary.value.material_reports_pending ?? 0),
      onClick: openMaterials,
    })
  }
  if (content.length) result.push({ key: 'content', title: '内容与社区', items: content })

  const trade: WorkItem[] = []
  if (admin.canReviewAfterSale) {
    trade.push({
      key: 'refunds', title: '售后与退款审核', desc: '退款审核 · 闲置退款仲裁',
      icon: '售', tone: 'tone-orange',
      badge: (summary.value.refunds_pending ?? 0) + (summary.value.second_hand_refunds_pending ?? 0),
      onClick: openRefunds,
    })
  }
  if (trade.length) result.push({ key: 'trade', title: '交易与售后', items: trade })

  const identity: WorkItem[] = []
  if (admin.canReviewIdentity) {
    identity.push({
      key: 'identity', title: '实名认证审核', desc: '证件信息核验 · 通过/驳回',
      icon: '证', tone: 'tone-blue', badge: summary.value.identity_verifications_pending ?? 0, onClick: openIdentity,
    })
  }
  if (admin.canResolveIdentityConflict) {
    identity.push({ key: 'identity-conflicts', title: '身份冲突处理', desc: '重复身份仲裁', icon: '冲', tone: 'tone-purple', onClick: openIdentityConflicts })
  }
  if (identity.length) result.push({ key: 'identity', title: '用户与身份', items: identity })

  const task: WorkItem[] = []
  if (admin.canReviewTask) {
    task.push({
      key: 'task-submissions', title: '任务提交审核', desc: '领取审核 · 逐步核验 · 通过/驳回',
      icon: '任', tone: 'tone-blue', badge: summary.value.task_submissions_pending ?? 0, onClick: openTasks,
    })
    task.push({ key: 'task-cases', title: '申诉与风险处置', desc: '任务申诉 · 风险案件', icon: '诉', tone: 'tone-purple', onClick: openTaskCases })
  }
  if (admin.canReviewPush) {
    task.push({ key: 'push-reviews', title: '地推申请与交单', desc: '入队申请 · 交单审核', icon: '推', tone: 'tone-green', onClick: openPushReviews })
  }
  if (task.length) result.push({ key: 'task', title: '任务与地推', items: task })

  return result
})

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
    await loadBinding()
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

function openItem(item: WorkItem) {
  if (item.soon) {
    uni.showToast({ title: '该入口即将开放，敬请期待', icon: 'none' })
    return
  }
  item.onClick?.()
}

function openSecondHand() { uni.navigateTo({ url: '/pages/admin/second-hand/index' }) }
function openCommunity() { uni.navigateTo({ url: '/pages/admin/community/index' }) }
function openTasks() { uni.navigateTo({ url: '/pages/admin/tasks/index' }) }
function openIdentity() { uni.navigateTo({ url: '/pages/admin/identity/index' }) }
function openMaterials() { uni.navigateTo({ url: '/pages/admin/materials/index' }) }
function openRefunds() { uni.navigateTo({ url: '/pages/admin/refunds/index' }) }
function openActivities() { uni.navigateTo({ url: '/pages/admin/activities/index' }) }
function openTaskCases() { uni.navigateTo({ url: '/pages/admin/task-cases/index' }) }
function openPushReviews() { uni.navigateTo({ url: '/pages/admin/push-reviews/index' }) }
function openIdentityConflicts() { uni.navigateTo({ url: '/pages/admin/identity-conflicts/index' }) }

async function logout() {
  const result = await uni.showModal({ title: '退出登录', content: '确认退出审核工作台？' })
  if (!result.confirm) return
  await admin.logout()
  uni.reLaunch({ url: '/pages/account/index' })
}

async function loadBinding() {
  if (!wechatH5) return
  try {
    binding.value = await fetchWechatBinding()
  } catch {
    // 绑定状态查询失败不影响工作台主流程
  }
}

async function toggleBinding() {
  if (binding.value?.bound) {
    await unbindCurrentWechat()
    return
  }

  const localCode = localAdminWechatCode()
  if (localCode) {
    await submitBinding(localCode)
    return
  }
  startAdminWechatAuth('bind').catch(() => {
    uni.showToast({ title: '发起授权失败，请稍后重试', icon: 'none' })
  })
}

async function submitBinding(code: string) {
  try {
    binding.value = await bindWechat(code)
    setWechatBindingFlag(true)
    uni.showToast({ title: '绑定成功', icon: 'success' })
  } catch (error) {
    handleSecurityError(error, '绑定失败')
  }
}

async function unbindCurrentWechat() {
  const result = await uni.showModal({ title: '解除绑定', content: '解绑后将不再支持免密登录，确认解绑？' })
  if (!result.confirm) return

  try {
    await unbindWechat()
    binding.value = { bound: false, bound_at: null, last_used_at: null, expires_at: null }
    setWechatBindingFlag(false)
    uni.showToast({ title: '已解绑', icon: 'success' })
  } catch (error) {
    handleSecurityError(error, '解绑失败')
  }
}

function handleSecurityError(error: unknown, fallback: string) {
  if (error instanceof ApiError && error.code === 'ADMIN_RECENT_VERIFICATION_REQUIRED') {
    uni.showToast({ title: '请先完成身份验证', icon: 'none' })
    uni.navigateTo({ url: '/pages/admin/verify/index' })
    return
  }
  uni.showToast({ title: error instanceof ApiError ? error.message : fallback, icon: 'none' })
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
.group{margin-bottom:28rpx}
.group-title{display:block;margin:0 8rpx 16rpx;color:#767C85;font-size:24rpx;font-weight:600}
.work-card{display:flex;align-items:center;margin-bottom:20rpx;padding:32rpx 28rpx;border-radius:28rpx;background:#fff;box-shadow:0 2rpx 4rpx rgba(23,26,23,.03),0 12rpx 40rpx rgba(23,26,23,.05)}
.work-card.soon{background:#FAFBFA;box-shadow:none;border:2rpx dashed #E3E7E4}
.wc-icon{display:flex;align-items:center;justify-content:center;width:88rpx;height:88rpx;flex:none;border-radius:24rpx;font-size:36rpx;font-weight:800}
.wc-main{flex:1;margin-left:24rpx;min-width:0}
.wc-title{display:block;color:#191B1F;font-size:30rpx;font-weight:700}
.wc-sub{display:block;margin-top:10rpx;color:#767C85;font-size:22rpx}
.wc-badge{display:flex;align-items:center;justify-content:center;min-width:56rpx;height:56rpx;padding:0 16rpx;border-radius:28rpx;background:#FF3B30;color:#fff;font-size:28rpx;font-weight:800}
.wc-badge.zero{background:#DDE3DE;color:#8A918A}
.wc-soon{padding:8rpx 18rpx;border-radius:999rpx;background:#EEF1EF;color:#8A918A;font-size:20rpx}
.wc-arrow{color:#C6CCC7;font-size:40rpx;line-height:1}
.tone-orange{background:#FFF2E5;color:#F2620A}
.tone-green{background:#E7F9EF;color:#08A152}
.tone-blue{background:#EAF2FF;color:#3B82F6}
.tone-purple{background:#F1ECFF;color:#6D4AC4}
.card-box{border-radius:28rpx;background:#fff;box-shadow:0 2rpx 4rpx rgba(23,26,23,.03),0 12rpx 40rpx rgba(23,26,23,.05)}
.security{display:flex;align-items:center;justify-content:space-between;margin-top:20rpx;padding:28rpx 30rpx}
.security-main{flex:1;min-width:0}
.security-title{display:block;color:#191B1F;font-size:28rpx;font-weight:700}
.security-sub{display:block;margin-top:10rpx;color:#767C85;font-size:22rpx}
.security-action{padding:10rpx 20rpx;border-radius:999rpx;background:#E7F9EF;color:#08A152;font-size:24rpx;font-weight:700}
.tip{padding:40rpx 32rpx;color:#767C85;font-size:26rpx}
.tip-main{display:block;color:#191B1F;font-size:30rpx;font-weight:700;text-align:center}
.tip-sub{display:block;margin-top:16rpx;color:#767C85;font-size:24rpx;line-height:1.6;text-align:center}
.legend{margin-top:12rpx;padding:28rpx 30rpx}
.legend-title{display:block;color:#191B1F;font-size:26rpx;font-weight:700;margin-bottom:14rpx}
.legend-row{display:block;color:#767C85;font-size:22rpx;line-height:1.8}
.legend-tip{display:block;margin-top:14rpx;color:#AEB3BB;font-size:20rpx;line-height:1.6}
</style>
