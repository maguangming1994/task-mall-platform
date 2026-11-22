<template>
  <view class="page">
    <view class="card">
      <text class="title">验证管理员身份</text>
      <text class="sub">
        该操作需要重新验证{{ requiresMfa ? '密码与动态码' : '密码' }}，验证后 15 分钟内有效。
      </text>

      <view class="field">
        <text class="label">登录密码</text>
        <input
          v-model="password"
          class="input"
          :password="true"
          maxlength="128"
          placeholder="请输入当前登录密码"
          placeholder-style="color:#AEB3BB"
          :disabled="submitting"
        />
      </view>

      <view v-if="requiresMfa" class="field">
        <text class="label">动态验证码</text>
        <input
          v-model="code"
          class="input"
          type="number"
          maxlength="20"
          placeholder="6 位动态码或恢复码"
          placeholder-style="color:#AEB3BB"
          :disabled="submitting"
        />
      </view>

      <button class="submit" :disabled="submitting || !canSubmit" :loading="submitting" @click="submit">
        {{ submitting ? '验证中…' : '确认验证' }}
      </button>

      <text class="hint">验证仅对当前会话生效，不会影响其他设备上的登录。</text>
    </view>
  </view>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue'
import { ApiError } from '@/api/http'
import { verifyRecentAdmin } from '@/api/admin/auth'
import { useAdminStore } from '@/stores/admin'

const admin = useAdminStore()
const password = ref('')
const code = ref('')
const submitting = ref(false)

const requiresMfa = computed(() => Boolean(admin.profile?.requires_mfa))
const canSubmit = computed(() => password.value.length >= 6 && (!requiresMfa.value || code.value.trim().length >= 6))

async function submit() {
  if (!canSubmit.value || submitting.value) return
  submitting.value = true
  try {
    await verifyRecentAdmin(password.value, requiresMfa.value ? code.value.trim() : undefined)
    uni.showToast({ title: '验证成功', icon: 'success' })
    setTimeout(() => uni.navigateBack(), 600)
  } catch (error) {
    const message = error instanceof ApiError ? error.message : '验证失败，请稍后重试。'
    uni.showToast({ title: message, icon: 'none' })
  } finally {
    submitting.value = false
  }
}
</script>

<style scoped>
.page{min-height:100vh;padding:calc(var(--status-bar-height) + 40rpx) 32rpx 48rpx;box-sizing:border-box;background:#F4F6F4}
.card{padding:44rpx 36rpx 36rpx;border-radius:32rpx;background:#fff;box-shadow:0 2rpx 4rpx rgba(23,26,23,.03),0 12rpx 40rpx rgba(23,26,23,.05)}
.title{display:block;color:#191B1F;font-size:38rpx;font-weight:800}
.sub{display:block;margin-top:16rpx;margin-bottom:36rpx;color:#767C85;font-size:24rpx;line-height:1.7}
.field{margin-bottom:32rpx}
.label{display:block;margin-bottom:14rpx;color:#191B1F;font-size:26rpx;font-weight:600}
.input{width:100%;height:92rpx;padding:0 28rpx;box-sizing:border-box;border:2rpx solid #E7EAE8;border-radius:20rpx;background:#F7F9F8;color:#191B1F;font-size:28rpx}
.submit{height:92rpx;margin-top:8rpx;border-radius:46rpx;background:linear-gradient(135deg,#12D978,#08A152);color:#fff;font-size:30rpx;font-weight:800;line-height:92rpx}
.submit::after{border:0}
.submit[disabled]{opacity:.55;color:#fff}
.hint{display:block;margin-top:28rpx;color:#AEB3BB;font-size:22rpx;text-align:center;line-height:1.6}
</style>
