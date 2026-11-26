<template>
  <view class="page">
    <view v-if="loading" class="empty">加载中…</view>
    <template v-else>
      <view v-if="runner" class="status-card" :class="runner.status">
        <text class="status-title">{{ statusTitle }}</text>
        <text class="status-desc">{{ statusDesc }}</text>
      </view>

      <view v-if="!runner || runner.status === 'rejected'" class="section">
        <view class="field">
          <text class="field-label">真实姓名</text>
          <input v-model="realName" class="field-input" placeholder="与校园卡一致" maxlength="50" />
        </view>
        <view class="field">
          <text class="field-label">手机号</text>
          <input v-model="phone" class="field-input" type="number" placeholder="11 位手机号" maxlength="11" />
        </view>
      </view>

      <view class="tip">审核通过后即可在「跑腿大厅」抢单；完成代取后酬劳进入结算批次，打款后进入钱包余额。</view>

      <button
        v-if="!runner || runner.status === 'rejected'"
        class="submit"
        :loading="submitting"
        :disabled="submitting"
        @tap="submit"
      >提交申请</button>
    </template>
  </view>
</template>

<script setup lang="ts">
import { onShow } from '@dcloudio/uni-app'
import { computed, ref } from 'vue'
import { applyExpressPickupRunner, getExpressPickupRunnerProfile, type ExpressPickupRunnerProfile } from '@/api/express-pickup'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const runner = ref<ExpressPickupRunnerProfile | null>(null)
const loading = ref(false)
const submitting = ref(false)
const realName = ref('')
const phone = ref('')

const statusTitle = computed(() => {
  switch (runner.value?.status) {
    case 'active': return '已通过跑腿认证'
    case 'pending': return '申请审核中'
    case 'rejected': return '申请未通过'
    case 'suspended': return '跑腿资格已挂起'
    default: return ''
  }
})

const statusDesc = computed(() => {
  switch (runner.value?.status) {
    case 'active': return '可前往跑腿大厅接单。'
    case 'pending': return '管理员审核通过后会通知你。'
    case 'rejected': return runner.value.reject_reason || '可修改资料后重新提交。'
    case 'suspended': return runner.value.reject_reason || '请联系管理员恢复资格。'
    default: return ''
  }
})

const toast = (title: string) => uni.showToast({ title, icon: 'none' })

const load = async () => {
  if (!auth.accessToken) {
    uni.navigateTo({ url: '/pages/login/index' })
    return
  }
  loading.value = true
  try {
    const result = await getExpressPickupRunnerProfile(auth.accessToken)
    runner.value = result.runner
    if (result.runner) {
      realName.value = result.runner.real_name
      phone.value = result.runner.phone
    }
  } catch (error) {
    toast(error instanceof Error ? error.message : '加载失败')
  } finally {
    loading.value = false
  }
}

const submit = async () => {
  if (!auth.accessToken) return
  if (!realName.value.trim()) return toast('请填写真实姓名')
  if (!/^1[3-9]\d{9}$/.test(phone.value)) return toast('请填写正确的手机号')
  submitting.value = true
  try {
    runner.value = await applyExpressPickupRunner(auth.accessToken, { real_name: realName.value.trim(), phone: phone.value })
    toast('申请已提交，请等待审核')
  } catch (error) {
    toast(error instanceof Error ? error.message : '提交失败')
  } finally {
    submitting.value = false
  }
}

onShow(() => { void load() })
</script>

<style scoped>
.page { min-height: 100vh; background: #f5f6f8; padding: 24rpx 24rpx 80rpx; box-sizing: border-box; }
.empty { padding: 160rpx 0; text-align: center; color: #9ca3af; font-size: 26rpx; }
.status-card { border-radius: 20rpx; padding: 32rpx; background: #eef2ff; margin-bottom: 16rpx; }
.status-card.active { background: #ecfdf5; }
.status-card.rejected { background: #fef2f2; }
.status-title { font-size: 30rpx; font-weight: 700; color: #111827; display: block; }
.status-desc { font-size: 24rpx; color: #6b7280; margin-top: 8rpx; display: block; line-height: 1.6; }
.section { background: #fff; border-radius: 20rpx; padding: 8rpx 24rpx; }
.field { display: flex; align-items: center; padding: 24rpx 0; border-bottom: 1rpx solid #f3f4f6; }
.field:last-child { border-bottom: none; }
.field-label { width: 180rpx; font-size: 26rpx; color: #374151; }
.field-input { flex: 1; font-size: 26rpx; color: #111827; }
.tip { font-size: 22rpx; color: #9ca3af; line-height: 1.6; padding: 24rpx 8rpx; }
.submit { background: #2f6bff; color: #fff; border-radius: 999rpx; font-size: 30rpx; }
</style>
