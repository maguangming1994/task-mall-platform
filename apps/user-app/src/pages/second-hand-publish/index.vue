<template>
  <view class="page">
    <view class="page-title">发布闲置</view>

    <view class="form-card">
      <view class="frow block">
        <text class="frow-label">宝贝照片 <text class="label-hint">（第一张为封面，最多 9 张）</text></text>
        <view class="upload-grid">
          <view v-for="(img, idx) in images" :key="idx" class="img-item">
            <image class="img" :src="img.url" mode="aspectFill" />
            <view class="img-del" @click="removeImage(idx)">×</view>
          </view>
          <view v-if="images.length < 9" class="upload-add" @click="addImage">
            <text class="plus">＋</text>
            <text class="add-text">添加照片 {{ images.length }}/9</text>
          </view>
        </view>
      </view>
      <view class="frow block">
        <text class="frow-label">宝贝标题</text>
        <input v-model="form.title" class="frow-input" placeholder="品牌型号 + 成色 + 亮点，如：iPad Air 5 95新" maxlength="80" />
        <text class="char-count">{{ form.title.length }}/80</text>
      </view>
      <view class="frow block">
        <textarea v-model="form.description" class="frow-textarea" placeholder="购买渠道、使用时长、成色细节、配件清单、转手原因…" maxlength="2000" />
      </view>
    </view>

    <view class="form-card">
      <view class="frow">
        <text class="frow-label">分类</text>
        <picker :range="categoryNames" @change="onCategoryChange">
          <view class="frow-value" :class="{ placeholder: !categoryNames[form.category_index] }">
            {{ categoryNames[form.category_index] || '请选择分类' }}<text class="arrow">›</text>
          </view>
        </picker>
      </view>
      <view class="frow block">
        <text class="frow-label">成色</text>
        <view class="chip-pick">
          <text v-for="(label, key) in conditions" :key="key" :class="['chip', { on: form.condition === key }]" @click="setCondition(key)">{{ label }}</text>
        </view>
      </view>
    </view>

    <view class="form-card">
      <view class="frow">
        <text class="frow-label">价格</text>
        <text class="price-symbol">¥</text>
        <input v-model="form.price" class="frow-input price-input" type="digit" placeholder="0.00" />
      </view>
      <view class="frow block">
        <text class="frow-label">交易方式</text>
        <view class="chip-pick">
          <text :class="['chip', { on: form.trade_type === 'meetup' }]" @click="form.trade_type = 'meetup'">校内面交</text>
          <text :class="['chip', { on: form.trade_type === 'mail' }]" @click="form.trade_type = 'mail'">邮寄</text>
          <text :class="['chip', { on: form.trade_type === 'both' }]" @click="form.trade_type = 'both'">均可</text>
        </view>
      </view>
      <view class="frow">
        <text class="frow-label">交易地点</text>
        <input v-model="form.location" class="frow-input" placeholder="如：3号宿舍楼下" maxlength="120" />
      </view>
    </view>

    <view class="publish-tip">发布后需平台审核，通过后上架闲置市场；请使用平台私信沟通，平台托管支付，确认收货后款项结算给卖家。</view>

    <view class="action-bar">
      <button class="ab-main" :loading="submitting" :disabled="!canSubmit" @click="submit">确认发布</button>
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
.page { min-height: 100vh; background: #F4F6F4; padding-bottom: 180rpx; }
.page-title { font-size: 40rpx; font-weight: 800; color: #191B1F; padding: 24rpx 24rpx 16rpx; }

.form-card { background: #fff; border-radius: 32rpx; margin: 20rpx 24rpx 0; padding: 4rpx 28rpx; }
.frow { display: flex; align-items: center; gap: 20rpx; padding: 26rpx 0; border-bottom: 1rpx solid #F5F6F6; min-height: 52rpx; }
.frow:last-child { border-bottom: 0; }
.frow.block { display: block; }
.frow-label { font-size: 28rpx; font-weight: 600; color: #191B1F; flex: none; }
.frow.block .frow-label { display: block; margin-bottom: 16rpx; }
.label-hint { font-weight: 400; color: #AEB3BB; font-size: 22rpx; }
.frow-input { flex: 1; font-size: 28rpx; color: #191B1F; }
.frow.block .frow-input { width: 100%; }
.frow-textarea { width: 100%; min-height: 160rpx; font-size: 28rpx; line-height: 1.6; color: #191B1F; }
.frow-value { flex: 1; font-size: 26rpx; color: #767C85; display: flex; align-items: center; justify-content: space-between; }
.frow-value.placeholder { color: #AEB3BB; }
.arrow { color: #AEB3BB; font-size: 28rpx; }
.char-count { display: block; text-align: right; font-size: 20rpx; color: #AEB3BB; margin-top: 8rpx; }

/* 上传 */
.upload-grid { display: flex; flex-wrap: wrap; gap: 16rpx; }
.img-item { width: 200rpx; height: 200rpx; position: relative; border-radius: 20rpx; overflow: hidden; }
.img { width: 100%; height: 100%; }
.img-del { position: absolute; top: 0; right: 0; width: 40rpx; height: 40rpx; background: rgba(0, 0, 0, .5); color: #fff; border-radius: 0 0 0 20rpx; display: flex; align-items: center; justify-content: center; font-size: 28rpx; }
.upload-add { width: 200rpx; height: 200rpx; border: 3rpx dashed #D7DBD8; background: #F7F9F8; border-radius: 20rpx; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 8rpx; }
.plus { font-size: 52rpx; color: #AEB3BB; line-height: 1; }
.add-text { font-size: 20rpx; color: #AEB3BB; }

/* chips */
.chip-pick { display: flex; flex-wrap: wrap; gap: 16rpx; }
.chip { height: 60rpx; line-height: 60rpx; padding: 0 26rpx; border-radius: 30rpx; border: 1rpx solid #EDEFEF; font-size: 24rpx; color: #767C85; background: #fff; }
.chip.on { border-color: #0BBF63; color: #08A152; background: #E7F9EF; font-weight: 600; }

/* 价格 */
.price-symbol { font-size: 32rpx; font-weight: 800; color: #FF2442; }
.price-input { font-size: 32rpx; font-weight: 800; color: #191B1F; }

.publish-tip { margin: 20rpx 28rpx 0; font-size: 22rpx; color: #AEB3BB; line-height: 1.6; }

/* 底部操作栏 */
.action-bar { position: fixed; bottom: 0; left: 0; right: 0; padding: 20rpx 24rpx calc(20rpx + env(safe-area-inset-bottom)); background: #fff; border-top: 1rpx solid #EDEFEF; }
.ab-main { height: 84rpx; line-height: 84rpx; border-radius: 42rpx; background: linear-gradient(135deg, #12D978, #08A152); color: #fff; font-size: 30rpx; font-weight: 700; box-shadow: 0 12rpx 28rpx rgba(11, 191, 99, .3); }
.ab-main::after { border: 0; }
.ab-main[disabled] { background: #F2F4F3; color: #AEB3BB; box-shadow: none; }
</style>
