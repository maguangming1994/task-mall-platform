<template>
  <view class="page">
    <view class="card">
      <text class="title">更换手机号</text>
      <text class="tip">为保障账号安全，需要先验证当前手机号，再验证新手机号。换绑后旧手机号将不能再登录本账号。</text>

      <view class="section">
        <text class="sec-title">当前手机号</text>
        <view class="field"><text class="prefix">+86</text><input v-model.trim="currentPhone" class="input" type="number" maxlength="20" placeholder="请输入当前手机号" /></view>
        <view class="field code-field">
          <input v-model.trim="currentCode" class="input" type="number" maxlength="10" placeholder="当前手机号验证码" />
          <button class="code-btn" :disabled="currentCountdown > 0 || sendingCurrent" @click="sendCode('current')">
            {{ currentCountdown > 0 ? `${currentCountdown}s` : sendingCurrent ? '发送中' : '获取验证码' }}
          </button>
        </view>
      </view>

      <view class="section">
        <text class="sec-title">新手机号</text>
        <view class="field"><text class="prefix">+86</text><input v-model.trim="newPhone" class="input" type="number" maxlength="20" placeholder="请输入新手机号" /></view>
        <view class="field code-field">
          <input v-model.trim="newCode" class="input" type="number" maxlength="10" placeholder="新手机号验证码" />
          <button class="code-btn" :disabled="newCountdown > 0 || sendingNew" @click="sendCode('new')">
            {{ newCountdown > 0 ? `${newCountdown}s` : sendingNew ? '发送中' : '获取验证码' }}
          </button>
        </view>
      </view>

      <view v-if="formError" class="form-error">{{ formError }}</view>
      <button class="submit" :loading="auth.submitting" :disabled="auth.submitting" @click="submit">确认更换</button>
    </view>
  </view>
</template>

<script setup lang="ts">
import { onUnmounted, ref } from 'vue'
import { ApiError } from '@/api/http'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const currentPhone = ref('')
const currentCode = ref('')
const newPhone = ref('')
const newCode = ref('')
const currentCountdown = ref(0)
const newCountdown = ref(0)
const sendingCurrent = ref(false)
const sendingNew = ref(false)
const formError = ref('')
let currentTimer: ReturnType<typeof setInterval> | null = null
let newTimer: ReturnType<typeof setInterval> | null = null

function startCountdown(kind: 'current' | 'new') {
  const counter = kind === 'current' ? currentCountdown : newCountdown
  counter.value = 60
  const timer = setInterval(() => {
    counter.value -= 1
    if (counter.value <= 0) clearInterval(timer)
  }, 1000)
  if (kind === 'current') currentTimer = timer
  else newTimer = timer
}

async function sendCode(kind: 'current' | 'new') {
  const phone = kind === 'current' ? currentPhone.value : newPhone.value
  if (!/^\+?[0-9]{6,20}$/.test(phone)) {
    formError.value = '请输入正确的手机号。'
    return
  }
  formError.value = ''
  if (kind === 'current') sendingCurrent.value = true
  else sendingNew.value = true
  try {
    await auth.requestPhoneCode(phone, kind === 'current' ? 'change_phone_old' : 'change_phone_new')
    startCountdown(kind)
    uni.showToast({ title: '验证码已发送', icon: 'none' })
  } catch (error) {
    formError.value = error instanceof ApiError ? error.message : '验证码发送失败，请稍后重试。'
  } finally {
    if (kind === 'current') sendingCurrent.value = false
    else sendingNew.value = false
  }
}

async function submit() {
  formError.value = ''
  if (!currentPhone.value || !currentCode.value) {
    formError.value = '请完成当前手机号验证。'
    return
  }
  if (!newPhone.value || !newCode.value) {
    formError.value = '请完成新手机号验证。'
    return
  }
  try {
    const result = await auth.changePhone(currentPhone.value, currentCode.value, newPhone.value, newCode.value)
    uni.showToast({ title: result.accountResolution?.message || '手机号已更换', icon: 'none' })
    setTimeout(() => uni.navigateBack(), 800)
  } catch (error) {
    formError.value = error instanceof ApiError ? error.message : '换绑失败，请稍后重试。'
  }
}

onUnmounted(() => {
  if (currentTimer) clearInterval(currentTimer)
  if (newTimer) clearInterval(newTimer)
})
</script>

<style scoped>
.page { min-height: 100vh; padding: 28rpx; background: #F5F6F8; box-sizing: border-box; }
.card { padding: 32rpx; border-radius: 28rpx; background: #fff; }
.title { display: block; font-size: 36rpx; font-weight: 700; color: #191B1F; }
.tip { display: block; margin-top: 12rpx; font-size: 24rpx; line-height: 1.7; color: #7A7F87; }
.section { margin-top: 36rpx; }
.sec-title { display: block; font-size: 28rpx; font-weight: 600; color: #3A3D43; margin-bottom: 16rpx; }
.field { display: flex; align-items: center; height: 88rpx; padding: 0 24rpx; margin-bottom: 18rpx; border-radius: 18rpx; background: #F5F6F8; }
.prefix { font-size: 28rpx; color: #7A7F87; margin-right: 12rpx; }
.input { flex: 1; font-size: 28rpx; color: #191B1F; }
.code-field { padding-right: 10rpx; }
.code-btn { flex-shrink: 0; padding: 0 20rpx; height: 64rpx; line-height: 64rpx; font-size: 24rpx; color: #0BBF63; background: #E7F9EF; border-radius: 14rpx; }
.form-error { margin-top: 20rpx; font-size: 26rpx; color: #D4380D; }
.submit { margin-top: 40rpx; height: 92rpx; line-height: 92rpx; border-radius: 46rpx; font-size: 30rpx; color: #fff; background: #0BBF63; }
.submit[disabled] { opacity: .6; }
</style>
