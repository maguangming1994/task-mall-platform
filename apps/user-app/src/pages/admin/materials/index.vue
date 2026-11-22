<template>
  <view class="page">
    <view class="tabs">
      <text :class="['tab', { on: tab === 'materials' }]" @click="switchTab('materials')">待审资料</text>
      <text :class="['tab', { on: tab === 'reports' }]" @click="switchTab('reports')">资料举报</text>
    </view>

    <view v-if="loading" class="tip">加载中…</view>

    <template v-else-if="tab === 'materials'">
      <view v-if="!materials.length" class="tip">暂无待审核资料</view>
      <view v-else class="list">
        <view v-for="item in materials" :key="item.id" class="card" @click="openMaterial(item)">
          <view class="card-head">
            <text class="title">{{ item.title }}</text>
            <text class="tag">{{ item.category?.name || '未分类' }}</text>
          </view>
          <text class="meta">{{ item.netdisk_label }} · {{ item.file_type }}</text>
          <text class="meta">上传者 {{ item.uploader_name }} · {{ formatDateTime(item.created_at) }}</text>
        </view>
      </view>
    </template>

    <template v-else>
      <view v-if="!reports.length" class="tip">暂无待处理举报</view>
      <view v-else class="list">
        <view v-for="item in reports" :key="item.id" class="card">
          <view class="card-head">
            <text class="title">{{ item.material?.title || '资料已删除' }}</text>
            <text class="tag red">待处理</text>
          </view>
          <text class="meta">举报原因：{{ item.reason }}</text>
          <text v-if="item.detail" class="meta">补充说明：{{ item.detail }}</text>
          <text class="meta">举报人 {{ item.reporter?.nickname || '匿名' }} · {{ formatDateTime(item.created_at) }}</text>
          <button class="handle" @click="openReport(item)">处理举报</button>
        </view>
      </view>
    </template>

    <AdminReviewSheet
      v-model:visible="reportSheetVisible"
      title="处理资料举报"
      :decisions="reportDecisions"
      :submitting="submitting"
      note-placeholder="处理说明（可选）"
      @confirm="submitReport"
    />
  </view>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { onLoad } from '@dcloudio/uni-app'
import { ApiError } from '@/api/http'
import {
  handleMaterialReport, listManagedMaterials, listMaterialReports,
  type AdminMaterial, type AdminMaterialReport,
} from '@/api/admin/material'
import AdminReviewSheet from '@/components/admin/AdminReviewSheet.vue'
import { useAdminGuard } from '@/composables/use-admin-guard'
import { useAdminReviewSelection } from '@/stores/admin-review-selection'
import { formatDateTime } from '@/utils/dateTime'

const guard = useAdminGuard()
const selection = useAdminReviewSelection()

const tab = ref<'materials' | 'reports'>('materials')
const loading = ref(true)
const submitting = ref(false)
const materials = ref<AdminMaterial[]>([])
const reports = ref<AdminMaterialReport[]>([])
const reportSheetVisible = ref(false)
const activeReport = ref<AdminMaterialReport | null>(null)

const reportDecisions = [
  { key: 'upheld', label: '举报成立', tone: 'red' as const },
  { key: 'dismissed', label: '驳回举报', tone: 'green' as const },
]

onLoad(() => {
  if (!guard.requireSession()) return
  void loadMaterials()
})

function switchTab(next: 'materials' | 'reports') {
  if (tab.value === next) return
  tab.value = next
  if (next === 'materials') void loadMaterials()
  else void loadReports()
}

async function loadMaterials() {
  loading.value = true
  try {
    const result = await listManagedMaterials({ status: 'pending', page: 1 })
    materials.value = result.items
  } catch (error) {
    if (guard.handleAuthError(error)) return
    uni.showToast({ title: error instanceof ApiError ? error.message : '加载失败', icon: 'none' })
  } finally {
    loading.value = false
  }
}

async function loadReports() {
  loading.value = true
  try {
    const result = await listMaterialReports({ status: 'pending', page: 1 })
    reports.value = result.items
  } catch (error) {
    if (guard.handleAuthError(error)) return
    uni.showToast({ title: error instanceof ApiError ? error.message : '加载失败', icon: 'none' })
  } finally {
    loading.value = false
  }
}

function openMaterial(item: AdminMaterial) {
  selection.material = item
  uni.navigateTo({ url: '/pages/admin/material-detail/index' })
}

function openReport(item: AdminMaterialReport) {
  activeReport.value = item
  reportSheetVisible.value = true
}

async function submitReport(payload: { decision: string; note: string }) {
  const target = activeReport.value
  if (!target || submitting.value) return
  submitting.value = true
  try {
    await handleMaterialReport(target.id, {
      decision: payload.decision as 'upheld' | 'dismissed',
      handling_note: payload.note || undefined,
      takedown: payload.decision === 'upheld',
    })
    reportSheetVisible.value = false
    reports.value = reports.value.filter((item) => item.id !== target.id)
    uni.showToast({ title: '已处理', icon: 'success' })
  } catch (error) {
    if (guard.handleAuthError(error)) return
    uni.showToast({ title: error instanceof ApiError ? error.message : '处理失败', icon: 'none' })
  } finally {
    submitting.value = false
  }
}
</script>

<style scoped>
.page{min-height:100vh;background:#F4F6F4;padding:20rpx 24rpx 48rpx;box-sizing:border-box}
.tabs{display:flex;gap:16rpx;margin-bottom:20rpx}
.tab{flex:1;padding:20rpx 0;border-radius:18rpx;background:#fff;color:#767C85;font-size:26rpx;text-align:center;border:2rpx solid transparent}
.tab.on{background:#E7F9EF;border-color:#08A152;color:#087A43;font-weight:700}
.tip{padding:120rpx 0;color:#AEB3BB;font-size:26rpx;text-align:center}
.card{margin-bottom:20rpx;padding:28rpx;border-radius:28rpx;background:#fff}
.card-head{display:flex;align-items:center;justify-content:space-between;gap:20rpx;margin-bottom:14rpx}
.title{flex:1;color:#191B1F;font-size:30rpx;font-weight:700;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.tag{flex:none;padding:6rpx 16rpx;border-radius:999rpx;background:#EAF2FF;color:#1D4ED8;font-size:20rpx}
.tag.red{background:#FFECEA;color:#C0261D}
.meta{display:block;margin-top:8rpx;color:#767C85;font-size:24rpx;line-height:1.6}
.handle{height:72rpx;margin-top:20rpx;border-radius:36rpx;background:#E7F9EF;color:#087A43;font-size:26rpx;font-weight:700;line-height:72rpx}
.handle::after{border:0}
</style>
