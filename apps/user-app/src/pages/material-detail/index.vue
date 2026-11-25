<template>
  <view class="page">
    <ModulePlaceholder v-if="modules.resolved && !moduleEnabled" code="module_material" />
    <view v-else-if="!modules.resolved" class="loading-wrap">
      <view class="tm-skeleton skel-card" />
    </view>
    <template v-else>
      <view class="head">
        <view class="head-back" @click="goBack">‹</view>
        <text class="head-title">资料详情</text>
      </view>

      <view v-if="material" class="content">
        <view class="info-card">
          <view class="info-top">
            <view :class="['file-badge', `f-${material.file_type}`]">{{ fileBadge(material.file_type) }}</view>
            <view class="info-main">
              <text class="info-title">{{ material.title }}</text>
              <view class="info-tags">
                <text class="tag">{{ material.netdisk_label }}</text>
                <text v-if="material.category" class="tag">{{ material.category.name }}</text>
                <text class="tag muted">{{ material.uploader_name }}</text>
              </view>
            </view>
          </view>
          <text v-if="material.description" class="info-desc">{{ material.description }}</text>
        </view>

        <!-- 已获取 -->
        <view v-if="link" class="link-card">
          <view class="link-head">
            <view class="link-ok"><text>✓</text></view>
            <text class="link-title">已获取网盘地址</text>
            <text class="link-copy-all" @click="copyAll">复制全部</text>
          </view>
          <view class="link-row">
            <text class="link-label">网盘链接</text>
            <text class="link-value">{{ link }}</text>
            <text class="copy-btn" @click="copyLink">复制链接</text>
          </view>
          <view v-if="extractCode" class="link-row">
            <text class="link-label">提取码</text>
            <text class="link-code">{{ extractCode }}</text>
            <text class="copy-btn" @click="copyCode">复制</text>
          </view>
          <view class="quota-note">今日剩余获取次数：{{ quotaText }}</view>
        </view>

        <!-- 未获取：额度可用 -->
        <view v-else-if="canFetch" class="action-card">
          <view class="action-head">
            <text class="action-title">获取资料</text>
            <text class="action-quota">剩余 {{ remaining }} 次</text>
          </view>
          <text class="action-note">获取后展示网盘链接与提取码，同一资料重复获取不再消耗次数。</text>
          <button class="action-btn" :disabled="fetching" @click="doFetch()">{{ fetching ? '获取中…' : '获取网盘地址' }}</button>
        </view>

        <!-- 未获取：额度已用完 -->
        <view v-else class="action-card exhausted">
          <view class="exhausted-btn">今日额度已用完</view>
          <view class="reward-card">
            <text class="reward-title">✦ 分享投喂资料，审核通过立赠 {{ bonus }} 次长期额度</text>
            <view v-if="postEnabled || requestEnabled" class="reward-actions">
              <text v-if="postEnabled" class="reward-chip" @click="openPost">去投喂资料</text>
              <text v-if="requestEnabled" class="reward-chip" @click="openRequest">提交求资料需求</text>
            </view>
          </view>
        </view>

        <view class="foot-links">
          <text class="foot-link" @click="openReport">侵权举报</text>
          <text class="foot-dot">·</text>
          <text class="foot-link" @click="openLegal">用户协议</text>
        </view>
      </view>

      <view v-else class="empty">
        <text class="empty-title">资料不存在或已下架</text>
      </view>
    </template>
  </view>
</template>

<script setup lang="ts">
import { onLoad } from '@dcloudio/uni-app'
import { computed, ref } from 'vue'
import {
  fetchMaterial,
  getMaterial,
  getMaterialMeta,
  reportMaterial,
  type MaterialItem,
  type MaterialMeta,
} from '@/api/material'
import ModulePlaceholder from '@/components/ModulePlaceholder.vue'
import { DEFAULT_WECHAT_SHARE_IMAGE, enableWechatShare } from '@/platform/wechatJssdk'
import { useAuthStore } from '@/stores/auth'
import { useModulesStore } from '@/stores/modules'

const auth = useAuthStore()
const modules = useModulesStore()
// 新增模块采用 fail-close：服务端未明确开启时不展示入口与内容
const moduleEnabled = computed(() => modules.isLaunched('module_material'))
const postEnabled = computed(() => modules.isLaunched('module_material_post'))
const requestEnabled = computed(() => modules.isLaunched('module_material_request'))

const materialId = ref('')
const material = ref<MaterialItem | null>(null)
const meta = ref<MaterialMeta | null>(null)
const link = ref('')
const extractCode = ref('')
const fetching = ref(false)

const remaining = computed(() => meta.value?.quota?.total_remaining ?? 0)
const canFetch = computed(() => {
  if (!auth.isAuthenticated) return true
  return remaining.value > 0 || Boolean(material.value?.already_fetched)
})
const quotaText = computed(() => {
  if (!auth.isAuthenticated) return '登录后查看'
  const q = meta.value?.quota
  return q ? `${q.total_remaining}` : '—'
})
const bonus = computed(() => meta.value?.bonus_per_approved ?? 10)

function fileBadge(type: string) {
  const map: Record<string, string> = { pdf: 'PDF', word: 'W', excel: 'X', ppt: 'P', video: '▶', archive: 'Z' }
  return map[type] ?? '文'
}

async function load() {
  try {
    material.value = await getMaterial(auth.accessToken || undefined, materialId.value)
    meta.value = await getMaterialMeta(auth.accessToken || undefined)
    void enableWechatShare({
      title: material.value.title || '校园资料共享',
      desc: material.value.description || '校园资料互助，学习资料免费获取。',
      link: `/pages/material-detail/index?id=${encodeURIComponent(material.value.id)}`,
      imageUrl: DEFAULT_WECHAT_SHARE_IMAGE,
    })
    if (material.value.is_owner && material.value.share_url) {
      link.value = material.value.share_url
      extractCode.value = material.value.extract_code ?? ''
    } else if (auth.isAuthenticated && material.value.already_fetched) {
      // 已获取过：免费再次展示地址
      await doFetch(true)
    }
  } catch {
    uni.showToast({ title: '资料加载失败', icon: 'none' })
  }
}

async function doFetch(silent = false) {
  if (!auth.isAuthenticated) {
    uni.navigateTo({ url: '/pages/login/index' })
    return
  }
  fetching.value = true
  try {
    const result = await fetchMaterial(auth.accessToken || '', materialId.value)
    link.value = result.share_url
    extractCode.value = result.extract_code || ''
    meta.value = { ...(meta.value as MaterialMeta), quota: result.quota }
    if (!silent) uni.showToast({ title: result.already_owned ? '已为你展示网盘地址' : '获取成功', icon: 'none' })
  } catch (error) {
    const err = error as { code?: string }
    if (err.code === 'MATERIAL_QUOTA_EXHAUSTED') {
      await loadMeta()
      uni.showToast({ title: '今日额度已用完', icon: 'none' })
    } else {
      uni.showToast({ title: '获取失败，请稍后重试', icon: 'none' })
    }
  } finally {
    fetching.value = false
  }
}

async function loadMeta() {
  try {
    meta.value = await getMaterialMeta(auth.accessToken || undefined)
  } catch {
    /* ignore */
  }
}

function copyText(value: string, tip = '已复制') {
  if (!value) return
  uni.setClipboardData({ data: value, success: () => uni.showToast({ title: tip, icon: 'none' }) })
}

function copyLink() {
  copyText(link.value, '链接已复制')
}

function copyCode() {
  copyText(extractCode.value, '提取码已复制')
}

function copyAll() {
  const code = extractCode.value ? `，提取码：${extractCode.value}` : ''
  copyText(`${link.value}${code}`, '已复制全部信息')
}

function openReport() {
  if (!auth.isAuthenticated) {
    uni.navigateTo({ url: '/pages/login/index' })
    return
  }
  const reasons = ['侵犯版权', '内容失效/无法打开', '包含违规内容', '其他问题']
  uni.showActionSheet({
    itemList: reasons,
    success: async ({ tapIndex }) => {
      try {
        await reportMaterial(auth.accessToken || '', materialId.value, { reason: reasons[tapIndex] })
        uni.showToast({ title: '举报已提交', icon: 'none' })
      } catch {
        uni.showToast({ title: '提交失败，请稍后重试', icon: 'none' })
      }
    },
  })
}

function openLegal() {
  uni.navigateTo({ url: '/pages/privacy/index' })
}

function openPost() {
  uni.navigateTo({ url: '/pages/material-post/index' })
}

function openRequest() {
  uni.navigateTo({ url: '/pages/material-request/index' })
}

function goBack() {
  const pages = getCurrentPages()
  if (pages.length > 1) uni.navigateBack()
  else uni.reLaunch({ url: '/pages/material/index' })
}

onLoad(async (query) => {
  materialId.value = String((query as Record<string, string>)?.id || '')
  await modules.ensureReady()
  if (!moduleEnabled.value || !materialId.value) return
  await load()
})
</script>

<style lang="scss" scoped>
.page { min-height: 100vh; background: #F4F6F4; padding-bottom: 60rpx; }
.head { display: flex; align-items: center; gap: 16rpx; padding: calc(var(--status-bar-height) + 16rpx) 24rpx 12rpx; }
.head-back { width: 60rpx; height: 60rpx; border-radius: 30rpx; background: #fff; font-size: 40rpx; line-height: 54rpx; text-align: center; }
.head-title { font-size: 34rpx; font-weight: 800; color: #191B1F; }
.content { padding: 8rpx 24rpx; }

.info-card { background: #fff; border-radius: 28rpx; padding: 26rpx; }
.info-top { display: flex; gap: 18rpx; }
.file-badge { flex: none; width: 92rpx; height: 92rpx; border-radius: 20rpx; display: flex; align-items: center; justify-content: center; color: #fff; font-size: 28rpx; font-weight: 800; background: #4F7DF3; }
.file-badge.f-pdf { background: #F04438; }
.file-badge.f-word { background: #2B6CE6; }
.file-badge.f-excel { background: #1E9E5A; }
.file-badge.f-ppt { background: #E06A1B; }
.file-badge.f-video { background: #7A5AF8; }
.file-badge.f-archive { background: #6B7280; }
.info-main { flex: 1; min-width: 0; }
.info-title { font-size: 32rpx; font-weight: 800; color: #191B1F; line-height: 1.4; }
.info-tags { display: flex; flex-wrap: wrap; gap: 10rpx; margin-top: 14rpx; }
.tag { padding: 6rpx 14rpx; border-radius: 10rpx; background: #E7F9EF; color: #08A152; font-size: 20rpx; }
.tag.muted { background: #F2F4F3; color: #6B7280; }
.info-desc { display: block; margin-top: 20rpx; font-size: 24rpx; color: #6B7280; line-height: 1.65; }

.link-card { margin-top: 20rpx; padding: 24rpx; border-radius: 28rpx; background: #F0FBF5; border: 1rpx solid #CBEFD9; }
.link-head { display: flex; align-items: center; gap: 12rpx; }
.link-ok { width: 40rpx; height: 40rpx; border-radius: 20rpx; background: #0BBF63; color: #fff; font-size: 22rpx; display: flex; align-items: center; justify-content: center; }
.link-title { font-size: 27rpx; font-weight: 700; color: #0A6B42; }
.link-copy-all { margin-left: auto; font-size: 22rpx; color: #6B7280; }
.link-row { margin-top: 16rpx; padding: 14rpx 16rpx; border-radius: 16rpx; background: #fff; display: flex; align-items: center; gap: 12rpx; }
.link-label { font-size: 20rpx; color: #AEB3BB; flex: none; }
.link-value { flex: 1; min-width: 0; font-size: 22rpx; color: #191B1F; word-break: break-all; }
.link-code { flex: 1; font-size: 28rpx; font-weight: 800; color: #0A6B42; letter-spacing: 2rpx; }
.copy-btn { flex: none; padding: 8rpx 18rpx; border-radius: 12rpx; background: #E7F9EF; color: #08A152; font-size: 21rpx; font-weight: 700; }
.quota-note { margin-top: 14rpx; font-size: 20rpx; color: #6B7280; }

.action-card { margin-top: 20rpx; padding: 26rpx; border-radius: 28rpx; background: #fff; }
.action-head { display: flex; align-items: baseline; }
.action-title { font-size: 28rpx; font-weight: 800; color: #191B1F; }
.action-quota { margin-left: auto; font-size: 22rpx; color: #08A152; }
.action-note { display: block; margin-top: 12rpx; font-size: 22rpx; color: #AEB3BB; line-height: 1.6; }
.action-btn { margin-top: 22rpx; height: 88rpx; line-height: 88rpx; border-radius: 44rpx; background: linear-gradient(135deg, #12D978, #08A152); color: #fff; font-size: 28rpx; font-weight: 700; }
.action-btn::after { border: 0; }
.action-btn[disabled] { opacity: .6; }
.exhausted-btn { height: 88rpx; line-height: 88rpx; border-radius: 44rpx; background: #BFE9D2; color: #fff; text-align: center; font-size: 28rpx; font-weight: 700; }
.reward-card { margin-top: 20rpx; padding: 22rpx; border-radius: 20rpx; background: #FFF9E8; }
.reward-title { font-size: 23rpx; color: #B7791F; line-height: 1.6; }
.reward-actions { display: flex; gap: 16rpx; margin-top: 16rpx; }
.reward-chip { padding: 10rpx 22rpx; border-radius: 999rpx; background: #fff; border: 1rpx solid #F3D9A4; color: #B7791F; font-size: 22rpx; }

.foot-links { margin-top: 40rpx; display: flex; align-items: center; justify-content: center; gap: 16rpx; }
.foot-link { font-size: 22rpx; color: #AEB3BB; }
.foot-dot { font-size: 20rpx; color: #D1D5DB; }
.skel-card { height: 360rpx; border-radius: 28rpx; margin: 24rpx; }
.empty { padding: 160rpx 0; text-align: center; }
.empty-title { font-size: 26rpx; color: #AEB3BB; }
</style>
