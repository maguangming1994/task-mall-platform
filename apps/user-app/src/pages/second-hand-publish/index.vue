<template>
  <view class="page">
    <view class="form">
      <view class="field">
        <text class="label">图片</text>
        <view class="images">
          <view v-for="(img, idx) in images" :key="idx" class="img-item">
            <image class="img" :src="img.url" mode="aspectFill" />
            <view class="img-del" @click="removeImage(idx)">×</view>
          </view>
          <view v-if="images.length < 9" class="img-add" @click="addImage">
            <text class="plus">+</text>
          </view>
        </view>
      </view>

      <view class="field">
        <text class="label">标题</text>
        <input v-model="form.title" class="input" placeholder="一句话描述你的闲置" maxlength="80" />
      </view>

      <view class="field">
        <text class="label">描述</text>
        <textarea v-model="form.description" class="textarea" placeholder="成色、使用情况、转手原因等" maxlength="2000" />
      </view>

      <view class="field">
        <text class="label">价格 (元)</text>
        <input v-model="form.price" class="input" type="digit" placeholder="0.00" />
      </view>

      <view class="field">
        <text class="label">成色</text>
        <view class="chips">
          <view v-for="(label, key) in conditions" :key="key" :class="['chip', { active: form.condition === key }]" @click="setCondition(key)">{{ label }}</view>
        </view>
      </view>

      <view class="field">
        <text class="label">分类</text>
        <picker :range="categoryNames" @change="onCategoryChange">
          <view class="picker-val">{{ categoryNames[form.category_index] || '请选择分类' }}</view>
        </picker>
      </view>

      <view class="field">
        <text class="label">交易方式</text>
        <view class="chips">
          <view :class="['chip', { active: form.trade_type === 'meetup' }]" @click="form.trade_type = 'meetup'">当面交易</view>
          <view :class="['chip', { active: form.trade_type === 'mail' }]" @click="form.trade_type = 'mail'">邮寄</view>
          <view :class="['chip', { active: form.trade_type === 'both' }]" @click="form.trade_type = 'both'">均可</view>
        </view>
      </view>

      <view class="field">
        <text class="label">位置</text>
        <input v-model="form.location" class="input" placeholder="如：3号宿舍楼下" maxlength="120" />
      </view>
    </view>

    <view class="submit-bar">
      <button class="submit" :loading="submitting" :disabled="!canSubmit" @click="submit">发布</button>
    </view>
  </view>
</template>

<script setup lang="ts">
import { onLoad } from '@dcloudio/uni-app'
import { computed, reactive, ref } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { chooseAndUploadPrivateImage } from '@/api/upload'
import { createSecondHandItem, listSecondHandCategories, type SecondHandCategory, type SecondHandCondition } from '@/api/second-hand'

const auth = useAuthStore()
const submitting = ref(false)
const images = ref<{ fileId: string; url: string }[]>([])
const categories = ref<SecondHandCategory[]>([])
const conditions: Record<string, string> = { new: '全新', like_new: '几乎全新', very_good: '轻微使用', good: '正常使用', fair: '明显使用' }

const form = reactive<{
  title: string
  description: string
  price: string
  condition: SecondHandCondition
  category_id: string
  category_index: number
  trade_type: 'meetup' | 'mail' | 'both'
  location: string
}>({
  title: '',
  description: '',
  price: '',
  condition: 'like_new',
  category_id: '',
  category_index: 0,
  trade_type: 'meetup',
  location: '',
})

const categoryNames = computed(() => categories.value.map(c => c.name))
const canSubmit = computed(() => form.title.trim().length >= 2 && Number(form.price) >= 0)

onLoad(() => { loadCategories() })

async function loadCategories() {
  if (!auth.accessToken) return
  try {
    const res = await listSecondHandCategories(auth.accessToken)
    categories.value = res.items
  } catch { /* ignore */ }
}

function onCategoryChange(e: any) {
  form.category_index = Number(e.detail.value)
  form.category_id = categories.value[form.category_index]?.id || ''
}

async function addImage() {
  if (!auth.accessToken) return
  try {
    uni.showLoading({ title: '上传中' })
    const res = await chooseAndUploadPrivateImage(auth.accessToken, 'second_hand')
    images.value.push({ fileId: res.fileId, url: res.previewUrl })
  } catch (e: any) {
    uni.showToast({ title: e.message || '上传失败', icon: 'none' })
  } finally {
    uni.hideLoading()
  }
}

function removeImage(idx: number) {
  images.value.splice(idx, 1)
}

function setCondition(key: string) {
  form.condition = key as SecondHandCondition
}

async function submit() {
  if (!auth.accessToken || !canSubmit.value) return
  submitting.value = true
  try {
    const cover = images.value[0]?.fileId || ''
    await createSecondHandItem(auth.accessToken, {
      title: form.title,
      description: form.description || undefined,
      price: Number(form.price),
      condition: form.condition,
      category_id: form.category_id || undefined,
      cover: cover || undefined,
      images: images.value.map(i => i.fileId),
      trade_type: form.trade_type,
      location: form.location || undefined,
    }, `sh-pub-${Date.now()}`)
    uni.showToast({ title: '发布成功', icon: 'success' })
    setTimeout(() => uni.navigateBack(), 800)
  } catch (e: any) {
    uni.showToast({ title: e.message || '发布失败', icon: 'none' })
  } finally {
    submitting.value = false
  }
}
</script>

<style lang="scss" scoped>
.page { min-height: 100vh; background: #f6f6f8; padding-bottom: 160rpx; }
.form { padding: 24rpx 28rpx; }
.field { background: #fff; border-radius: 16rpx; padding: 24rpx 28rpx; margin-bottom: 20rpx; }
.label { font-size: 28rpx; color: #1a1a1b; font-weight: 600; display: block; margin-bottom: 16rpx; }
.input { font-size: 30rpx; padding: 16rpx 0; border-bottom: 1rpx solid #f0f0f0; }
.textarea { width: 100%; min-height: 160rpx; font-size: 30rpx; padding: 16rpx 0; }
.images { display: flex; flex-wrap: wrap; gap: 16rpx; }
.img-item { width: 200rpx; height: 200rpx; position: relative; border-radius: 12rpx; overflow: hidden; }
.img { width: 100%; height: 100%; }
.img-del { position: absolute; top: 0; right: 0; width: 40rpx; height: 40rpx; background: rgba(0,0,0,0.5); color: #fff; border-radius: 0 0 0 12rpx; display: flex; align-items: center; justify-content: center; font-size: 28rpx; }
.img-add { width: 200rpx; height: 200rpx; border: 2rpx dashed #ccc; border-radius: 12rpx; display: flex; align-items: center; justify-content: center; }
.plus { font-size: 60rpx; color: #ccc; }
.chips { display: flex; flex-wrap: wrap; gap: 16rpx; }
.chip { padding: 12rpx 28rpx; background: #f5f5f7; border-radius: 28rpx; font-size: 26rpx; color: #6e6e73; }
.chip.active { background: #07c160; color: #fff; }
.picker-val { font-size: 30rpx; padding: 16rpx 0; color: #1a1a1b; }
.submit-bar { position: fixed; bottom: 0; left: 0; right: 0; padding: 20rpx 28rpx; background: #fff; border-top: 1rpx solid #ececee; }
.submit { background: #07c160; color: #fff; border-radius: 40rpx; height: 88rpx; line-height: 88rpx; font-size: 32rpx; }
.submit[disabled] { background: #a0a0a5; }
</style>
