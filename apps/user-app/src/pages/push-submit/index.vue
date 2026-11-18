<template>
  <view class="push-page">
    <view v-if="loading" class="tip">加载中…</view>
    <view v-else-if="!position" class="error-card">
      <text class="err-title">推广位不存在或已失效</text>
      <text class="err-desc">请返回「我的推广位」重新选择进行中的推广位再交单。</text>
      <button class="ghost-btn" @tap="back">返回推广位</button>
    </view>

    <view v-else class="wrap">
      <view class="card">
        <view class="card-head">
          <text class="card-title">{{ position.task?.title || '推广任务' }}</text>
          <text class="money">¥{{ position.task?.reward_amount || '0.00' }}</text>
        </view>
        <text class="muted">推广关键词：{{ position.keyword || '未填写' }}</text>
        <text class="muted">领取时间：{{ formatTime(position.claimed_at) }}</text>
      </view>

      <view class="card">
        <text class="card-title">上传交单凭证</text>
        <text class="muted">至少 1 张，最多 3 张；需能体现推广完成情况。</text>
        <view class="upload-grid">
          <view v-for="(image, index) in images" :key="image.fileId" class="upload-item">
            <image :src="image.preview" mode="aspectFill" class="preview" />
            <text class="remove" @tap="removeImage(index)">×</text>
          </view>
          <view v-if="images.length < 3" class="upload-add" @tap="pickImage">
            <text class="plus">＋</text>
            <text class="add-text">上传凭证</text>
          </view>
        </view>
      </view>

      <view class="card">
        <text class="card-title">补充信息</text>
        <textarea v-model="remark" class="textarea" placeholder="补充推广情况，如推广人数、地点等（选填）" maxlength="500" />
        <input v-model="contactName" class="input" placeholder="联系人姓名（选填）" maxlength="60" />
        <input v-model="contactPhone" class="input" placeholder="联系电话（选填）" maxlength="30" type="number" />
      </view>

      <view class="bottom-bar">
        <button class="primary-btn" :disabled="submitting" @tap="submit">{{ submitting ? '提交中…' : '提交审核' }}</button>
      </view>
    </view>
  </view>
</template>

<script setup lang="ts">
import { onLoad } from '@dcloudio/uni-app'
import { ref } from 'vue'
import { listPushPositions, submitPushPosition, type PushPosition } from '@/api/push'
import { chooseAndUploadPrivateImage } from '@/api/upload'
import { useAuthStore } from '@/stores/auth'
import { formatDateTime } from '@/utils/dateTime'

const auth = useAuthStore()
const positionId = ref('')
const position = ref<PushPosition | null>(null)
const images = ref<Array<{ fileId: string; preview: string }>>([])
const remark = ref('')
const contactName = ref('')
const contactPhone = ref('')
const loading = ref(false)
const submitting = ref(false)

async function load() {
  if (!auth.accessToken) {
    uni.navigateTo({ url: '/pages/login/index' })
    return
  }
  loading.value = true
  try {
    const result = await listPushPositions(auth.accessToken)
    position.value = result.items.find((item) => item.id === positionId.value) || null
  } catch (error) {
    uni.showToast({ title: error instanceof Error ? error.message : '推广位加载失败', icon: 'none' })
  } finally {
    loading.value = false
  }
}

async function pickImage() {
  if (!auth.accessToken) return
  try {
    const result = await chooseAndUploadPrivateImage(auth.accessToken, 'task_submission')
    images.value.push({ fileId: result.fileId, preview: result.previewUrl })
  } catch (error) {
    uni.showToast({ title: error instanceof Error ? error.message : '上传失败', icon: 'none' })
  }
}

function removeImage(index: number) { images.value.splice(index, 1) }

async function submit() {
  if (!auth.accessToken || !position.value) return
  if (!images.value.length) {
    uni.showToast({ title: '请至少上传 1 张凭证', icon: 'none' })
    return
  }
  submitting.value = true
  try {
    await submitPushPosition(auth.accessToken, position.value.id, {
      images: images.value.map((item) => item.fileId),
      remark: remark.value || undefined,
      contact_name: contactName.value || undefined,
      contact_phone: contactPhone.value || undefined,
    })
    uni.showToast({ title: '已提交，等待审核', icon: 'success' })
    setTimeout(() => { uni.navigateBack() }, 600)
  } catch (error) {
    uni.showToast({ title: error instanceof Error ? error.message : '提交失败', icon: 'none' })
  } finally {
    submitting.value = false
  }
}

function back() { uni.navigateBack() }
function formatTime(value: string | null) { return formatDateTime(value) }

onLoad((options) => {
  positionId.value = String(options?.id || '')
  void load()
})
</script>

<style scoped>
.push-page { min-height: 100vh; background: #f4f6f4; padding: 12px 12px 90px; box-sizing: border-box; }
.wrap { display: flex; flex-direction: column; gap: 12px; }
.tip { text-align: center; color: #aeb3bb; font-size: 12px; padding: 40px 0; }
.card { background: #fff; border-radius: 16px; padding: 14px; }
.card-head { display: flex; align-items: center; justify-content: space-between; }
.card-title { font-size: 15px; font-weight: 700; display: block; margin-bottom: 6px; }
.money { font-size: 15px; font-weight: 800; color: #ff3b30; }
.muted { display: block; color: #767c85; font-size: 12px; margin-top: 4px; }
.upload-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; margin-top: 10px; }
.upload-item { position: relative; aspect-ratio: 1; border-radius: 10px; overflow: hidden; }
.preview { width: 100%; height: 100%; }
.remove { position: absolute; top: 4px; right: 6px; width: 18px; height: 18px; line-height: 16px; text-align: center; border-radius: 50%; background: rgba(0, 0, 0, .55); color: #fff; font-size: 14px; }
.upload-add { aspect-ratio: 1; border: 1.5px dashed #d7dbd8; border-radius: 10px; background: #f7f9f8; display: flex; flex-direction: column; align-items: center; justify-content: center; color: #aeb3bb; }
.plus { font-size: 26px; }
.add-text { font-size: 10px; }
.textarea { width: 100%; min-height: 76px; border: 1px solid #edefef; border-radius: 10px; padding: 10px; font-size: 13px; box-sizing: border-box; margin-top: 6px; }
.input { width: 100%; height: 40px; border: 1px solid #edefef; border-radius: 10px; padding: 0 12px; font-size: 13px; box-sizing: border-box; margin-top: 8px; }
.bottom-bar { position: fixed; left: 0; right: 0; bottom: 0; background: #fff; padding: 10px 14px calc(10px + env(safe-area-inset-bottom)); border-top: 1px solid #edefef; }
.primary-btn { width: 100%; height: 44px; line-height: 44px; border-radius: 22px; background: linear-gradient(135deg, #12d978, #08a152); color: #fff; font-size: 15px; font-weight: 700; }
.primary-btn[disabled] { background: #c9cfcb; }
.error-card { background: #fff5f5; border: 1px solid #ffd7d2; border-radius: 16px; padding: 18px; margin-top: 20px; display: flex; flex-direction: column; gap: 8px; }
.err-title { color: #b91c1c; font-size: 15px; font-weight: 700; }
.err-desc { color: #b91c1c; font-size: 12px; line-height: 1.6; }
.ghost-btn { margin-top: 8px; height: 38px; line-height: 38px; border-radius: 19px; background: #fff; color: #b91c1c; border: 1px solid #ffd7d2; font-size: 13px; }
</style>
