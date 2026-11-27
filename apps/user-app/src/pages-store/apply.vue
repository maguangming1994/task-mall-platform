<template>
  <view class="page">
    <view class="intro">
      <text class="intro-title">开一家校园门店</text>
      <text class="intro-copy">提交门店资料后由平台审核，通过后可在手机端上架商品、管理库存与查看结算。</text>
    </view>

    <view class="form">
      <view class="field">
        <text class="label">门店名称</text>
        <input v-model="form.name" class="input" placeholder="例如：青禾文创店" maxlength="80" />
      </view>
      <view class="field">
        <text class="label">联系电话</text>
        <input v-model="form.contact_phone" class="input" type="number" placeholder="11 位手机号" maxlength="11" />
      </view>
      <view class="field">
        <text class="label">门店简介</text>
        <textarea v-model="form.description" class="textarea" placeholder="介绍你的门店与主营商品" maxlength="500" />
      </view>
      <view class="field">
        <text class="label">门店地址</text>
        <input v-model="form.address" class="input" placeholder="例如：东区生活广场 1 号" maxlength="255" />
      </view>
      <view class="field">
        <text class="label">营业时间</text>
        <input v-model="form.business_hours" class="input" placeholder="例如：09:00-21:00" maxlength="60" />
      </view>
    </view>

    <text class="tip">审核通过后可在「门店设置」中上传门店 Logo；平台统一收款，按结算周期打款。</text>
    <button class="submit" :loading="submitting" :disabled="submitting" @tap="submit">{{ editingStoreId ? '保存并重新提交审核' : '提交开店申请' }}</button>
  </view>
</template>

<script setup lang="ts">
import { onLoad } from '@dcloudio/uni-app'
import { reactive, ref } from 'vue'
import { applyStore, listMyStores, resubmitStore, updateMyStore } from '@/api/stores'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const submitting = ref(false)
const editingStoreId = ref('')
const form = reactive({ name: '', contact_phone: '', description: '', address: '', business_hours: '' })

const toast = (title: string) => uni.showToast({ title, icon: 'none' })

const submit = async () => {
  if (!auth.accessToken) {
    uni.navigateTo({ url: '/pages/login/index' })
    return
  }
  if (form.name.trim().length < 2) return toast('请填写门店名称')
  if (!/^1[3-9]\d{9}$/.test(form.contact_phone)) return toast('请填写正确的手机号')
  submitting.value = true
  try {
    const payload = {
      name: form.name.trim(),
      contact_phone: form.contact_phone,
      description: form.description.trim() || undefined,
      address: form.address.trim() || undefined,
      business_hours: form.business_hours.trim() || undefined,
    }
    if (editingStoreId.value) {
      await updateMyStore(auth.accessToken, editingStoreId.value, payload)
      await resubmitStore(auth.accessToken, editingStoreId.value)
      toast('已重新提交，等待平台审核')
    } else {
      await applyStore(auth.accessToken, payload)
      toast('申请已提交，等待平台审核')
    }
    setTimeout(() => uni.navigateBack(), 800)
  } catch (error) {
    toast(error instanceof Error ? error.message : '提交失败')
  } finally {
    submitting.value = false
  }
}

onLoad(async (query) => {
  const storeId = String(query?.id || '')
  if (!storeId || !auth.accessToken) return
  try {
    const stores = await listMyStores(auth.accessToken)
    const store = stores.items.find((item) => item.id === storeId)
    if (!store) return
    editingStoreId.value = storeId
    form.name = store.name
    form.contact_phone = store.contact_phone
    form.description = store.description || ''
    form.address = store.address || ''
    form.business_hours = store.business_hours || ''
  } catch (error) {
    toast(error instanceof Error ? error.message : '加载门店资料失败')
  }
})
</script>

<style scoped>
.page { min-height: 100vh; background: #f5f6f8; padding: 24rpx 24rpx 80rpx; box-sizing: border-box; }
.intro { padding: 16rpx 8rpx 24rpx; }
.intro-title { display: block; font-size: 34rpx; font-weight: 700; color: #111827; }
.intro-copy { display: block; margin-top: 10rpx; font-size: 24rpx; color: #6b7280; line-height: 1.7; }
.form { background: #fff; border-radius: 20rpx; padding: 8rpx 24rpx; }
.field { padding: 24rpx 0; border-bottom: 1rpx solid #f3f4f6; }
.field:last-child { border-bottom: none; }
.label { display: block; font-size: 24rpx; color: #6b7280; margin-bottom: 12rpx; }
.input, .textarea { width: 100%; font-size: 28rpx; color: #111827; }
.textarea { height: 140rpx; line-height: 1.6; }
.tip { display: block; padding: 24rpx 8rpx; font-size: 22rpx; color: #9ca3af; line-height: 1.6; }
.submit { background: #2f6bff; color: #fff; border-radius: 999rpx; font-size: 30rpx; }
</style>
