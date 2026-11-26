<template>
  <view class="page">
    <view class="section">
      <view class="field">
        <text class="field-label">取件点</text>
        <input v-model="form.pickup_site" class="field-input" placeholder="如：菜鸟驿站（东门）" maxlength="100" />
      </view>
      <view class="field">
        <text class="field-label">取件码</text>
        <input v-model="form.pickup_code" class="field-input" placeholder="如：8-2-3021" maxlength="64" />
      </view>
      <view class="field">
        <text class="field-label">包裹描述</text>
        <input v-model="form.package_info" class="field-input" placeholder="如：纸箱小件、易碎" maxlength="200" />
      </view>
      <view class="field">
        <text class="field-label">包裹件数</text>
        <input v-model="packageCountText" class="field-input" type="number" placeholder="1" />
      </view>
    </view>

    <view class="section">
      <view class="field">
        <text class="field-label">送达楼栋</text>
        <input v-model="form.deliver_building" class="field-input" placeholder="如：学生公寓 3 号楼" maxlength="100" />
      </view>
      <view class="field">
        <text class="field-label">详细地址</text>
        <input v-model="form.deliver_address" class="field-input" placeholder="如：3-412 门口" maxlength="200" />
      </view>
      <view class="field">
        <text class="field-label">联系人</text>
        <input v-model="form.contact_name" class="field-input" placeholder="收件人姓名" maxlength="50" />
      </view>
      <view class="field">
        <text class="field-label">联系电话</text>
        <input v-model="form.contact_phone" class="field-input" type="number" placeholder="11 位手机号" maxlength="11" />
      </view>
    </view>

    <view class="section">
      <view class="field">
        <text class="field-label">期望送达</text>
        <view class="field-input field-pickers">
          <picker mode="date" :value="expectedDate" @change="onExpectedDate">
            <text :class="expectedDate ? 'picker-value' : 'picker-placeholder'">{{ expectedDate || '选择日期' }}</text>
          </picker>
          <picker mode="time" :value="expectedTime" @change="onExpectedTime">
            <text :class="expectedTime ? 'picker-value' : 'picker-placeholder'">{{ expectedTime || '选择时间' }}</text>
          </picker>
        </view>
      </view>
      <view class="field">
        <text class="field-label">跑腿费（元）</text>
        <input v-model="rewardText" class="field-input" type="digit" placeholder="1 ~ 100" />
      </view>
      <view class="field">
        <text class="field-label">备注</text>
        <input v-model="form.remark" class="field-input" placeholder="选填，如：轻拿轻放" maxlength="300" />
      </view>
    </view>

    <view class="tip">提交后需完成支付，跑腿才会看到并接单；支付成功会自动打印取件小票。</view>
    <button class="submit" :loading="submitting" :disabled="submitting" @tap="submit">提交并去支付</button>
  </view>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { createExpressPickupOrder, type CreateExpressPickupInput } from '@/api/express-pickup'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const submitting = ref(false)
const packageCountText = ref('1')
const rewardText = ref('5')
const form = ref<CreateExpressPickupInput>({
  pickup_site: '',
  pickup_code: '',
  package_info: '',
  package_count: 1,
  deliver_building: '',
  deliver_address: '',
  contact_name: '',
  contact_phone: '',
  reward_cents: 500,
  expected_at: '',
  remark: '',
})

const toast = (title: string) => uni.showToast({ title, icon: 'none' })

const expectedDate = ref('')
const expectedTime = ref('')

const syncExpectedAt = () => {
  form.value.expected_at = expectedDate.value && expectedTime.value
    ? `${expectedDate.value} ${expectedTime.value}:00`
    : null
}

const onExpectedDate = (event: { detail: { value: string } }) => {
  expectedDate.value = event.detail.value
  if (!expectedTime.value) expectedTime.value = '18:00'
  syncExpectedAt()
}

const onExpectedTime = (event: { detail: { value: string } }) => {
  expectedTime.value = event.detail.value
  syncExpectedAt()
}

const submit = async () => {
  if (!auth.accessToken) {
    uni.navigateTo({ url: '/pages/login/index' })
    return
  }
  const data = form.value
  if (!data.pickup_site.trim() || !data.pickup_code.trim()) return toast('请填写取件点与取件码')
  if (!data.deliver_building.trim() || !data.deliver_address.trim()) return toast('请填写送达地址')
  if (!data.contact_name.trim()) return toast('请填写联系人')
  if (!/^1[3-9]\d{9}$/.test(data.contact_phone)) return toast('请填写正确的手机号')
  const reward = Math.round(Number(rewardText.value) * 100)
  if (!Number.isFinite(reward) || reward < 100 || reward > 10000) return toast('跑腿费需在 1 ~ 100 元之间')

  submitting.value = true
  try {
    const order = await createExpressPickupOrder(auth.accessToken, {
      ...data,
      package_count: Math.max(1, Math.min(20, Number(packageCountText.value) || 1)),
      reward_cents: reward,
      expected_at: data.expected_at || null,
      remark: data.remark?.trim() || undefined,
    })
    uni.redirectTo({ url: `/pages-express/detail?id=${order.id}` })
  } catch (error) {
    toast(error instanceof Error ? error.message : '提交失败')
  } finally {
    submitting.value = false
  }
}
</script>

<style scoped>
.page { min-height: 100vh; background: #f5f6f8; padding: 24rpx 24rpx 80rpx; box-sizing: border-box; }
.section { background: #fff; border-radius: 20rpx; padding: 8rpx 24rpx; margin-bottom: 16rpx; }
.field { display: flex; align-items: center; padding: 24rpx 0; border-bottom: 1rpx solid #f3f4f6; }
.field:last-child { border-bottom: none; }
.field-label { width: 180rpx; font-size: 26rpx; color: #374151; }
.field-input { flex: 1; font-size: 26rpx; color: #111827; }
.field-pickers { display: flex; gap: 24rpx; }
.picker-value { font-size: 26rpx; color: #111827; }
.picker-placeholder { font-size: 26rpx; color: #9ca3af; }
.tip { font-size: 22rpx; color: #9ca3af; line-height: 1.6; padding: 8rpx 8rpx 24rpx; }
.submit { background: #2f6bff; color: #fff; border-radius: 999rpx; font-size: 30rpx; }
</style>
