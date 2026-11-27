<template>
  <view class="page">
    <view class="field">
      <text class="label">分类</text>
      <picker :value="catIndex" :range="cats" @change="onCatChange">
        <view class="picker">{{ cats[catIndex] }}</view>
      </picker>
    </view>
    <view class="field">
      <text class="label">标题</text>
      <input v-model="title" placeholder="不超过 140 字" maxlength="140" />
    </view>
    <view class="field">
      <text class="label">内容</text>
      <textarea v-model="content" placeholder="分享你的经验或提出问题，所有校区代理都能看到" maxlength="5000" />
    </view>
    <button :loading="submitting" :disabled="!canSubmit" @tap="submit">发布</button>
    <view v-if="error" class="err">{{ error }}</view>
  </view>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue'
import { createCampusAgentPost } from '@/api/campusAgent'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()

const CATS = [
  { code: 'notice', label: '公告' },
  { code: 'experience', label: '经验分享' },
  { code: 'question', label: '求助' },
]

const cats = CATS.map(c => c.label)
const catIndex = ref(0)
const title = ref('')
const content = ref('')
const submitting = ref(false)
const error = ref('')

const canSubmit = computed(() => title.value.trim() && content.value.trim())

function onCatChange(e: { detail: { value: string | number } }) {
  catIndex.value = Number(e.detail.value)
}

async function submit() {
  if (!canSubmit.value || submitting.value) return
  if (!auth.accessToken) {
    error.value = '请先登录'
    return
  }
  submitting.value = true
  error.value = ''
  try {
    await createCampusAgentPost(auth.accessToken, {
      title: title.value.trim(),
      content: content.value.trim(),
      category_code: CATS[catIndex.value].code,
    })
    uni.showToast({ title: '已发布', icon: 'success' })
    setTimeout(() => uni.navigateBack(), 600)
  } catch (e) {
    error.value = e instanceof Error ? e.message : '发布失败'
  } finally {
    submitting.value = false
  }
}
</script>

<style scoped>
.page { padding: 24rpx; }
.field { background: #fff; border-radius: 16rpx; padding: 20rpx 24rpx; margin-bottom: 16rpx; }
.label { display: block; font-size: 24rpx; color: #8a92a6; margin-bottom: 8rpx; }
.picker { font-size: 30rpx; color: #1a1e28; }
input, textarea { width: 100%; font-size: 30rpx; color: #1a1e28; }
textarea { min-height: 240rpx; }
.err { color: #c0392b; font-size: 26rpx; margin-top: 16rpx; }
</style>
