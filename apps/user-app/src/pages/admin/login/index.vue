<template>
  <view class="page">
    <view class="hero">
      <view class="hero-badge">审</view>
      <text class="hero-title">审核工作台</text>
      <text class="hero-sub">校园任务商城 · 管理员专用通道</text>
    </view>

    <view class="card">
      <view class="field">
        <text class="label">管理员账号</text>
        <input
          v-model="account"
          class="input"
          type="text"
          maxlength="64"
          placeholder="请输入管理员账号"
          placeholder-style="color:#AEB3BB"
          :disabled="admin.submitting"
        />
      </view>
      <view class="field">
        <text class="label">登录密码</text>
        <input
          v-model="password"
          class="input"
          :password="!showPassword"
          maxlength="128"
          placeholder="请输入登录密码"
          placeholder-style="color:#AEB3BB"
          :disabled="admin.submitting"
        />
        <text class="eye" @click="showPassword = !showPassword">{{ showPassword ? '隐藏' : '显示' }}</text>
      </view>

      <button class="submit" :disabled="admin.submitting || !canSubmit" :loading="admin.submitting" @click="submit">
        {{ admin.submitting ? '登录中…' : '登 录' }}
      </button>

      <text class="hint">仅限已授权的审核管理员使用，操作将记录审计日志。</text>
    </view>
  </view>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue'
import { ApiError } from '@/api/http'
import { useAdminStore } from '@/stores/admin'

const admin = useAdminStore()
const account = ref('')
const password = ref('')
const showPassword = ref(false)

const canSubmit = computed(() => account.value.trim().length >= 3 && password.value.length >= 6)

async function submit() {
  if (!canSubmit.value || admin.submitting) return
  try {
    const profile = await admin.login(account.value, password.value)
    if (profile.requires_mfa) {
      uni.showToast({ title: '该账号需要二次验证，请在后台登录。', icon: 'none', duration: 2500 })
      admin.clear()
      return
    }
    if (!admin.hasAnyReviewAccess) {
      // 无审核权限的账号不保留会话，避免后续请求携带无效管理员令牌
      admin.clear()
      uni.showToast({ title: '当前角色没有审核权限，请联系超管分配。', icon: 'none', duration: 2500 })
      return
    }
    uni.showToast({ title: '登录成功', icon: 'success' })
    uni.reLaunch({ url: '/pages/admin/review/index' })
  } catch (error) {
    const message = error instanceof ApiError ? error.message : '登录失败，请检查网络。'
    uni.showToast({ title: message || '登录失败', icon: 'none' })
  }
}
</script>

<style scoped>
.page{min-height:100vh;padding:calc(var(--status-bar-height) + 48rpx) 40rpx 48rpx;box-sizing:border-box;background:linear-gradient(180deg,#EAFBF2 0%,#F4F6F4 360rpx)}
.hero{display:flex;align-items:center;flex-direction:column;padding:32rpx 0 48rpx;text-align:center}
.hero-badge{display:flex;align-items:center;justify-content:center;width:112rpx;height:112rpx;border-radius:32rpx;background:linear-gradient(135deg,#12D978,#08A152);color:#fff;font-size:48rpx;font-weight:800;box-shadow:0 16rpx 40rpx rgba(8,161,82,.28)}
.hero-title{display:block;margin-top:28rpx;color:#191B1F;font-size:44rpx;font-weight:800}
.hero-sub{display:block;margin-top:14rpx;color:#767C85;font-size:24rpx}
.card{padding:48rpx 36rpx 40rpx;border-radius:32rpx;background:#fff;box-shadow:0 2rpx 4rpx rgba(23,26,23,.03),0 12rpx 40rpx rgba(23,26,23,.05)}
.field{position:relative;margin-bottom:36rpx}
.label{display:block;margin-bottom:16rpx;color:#191B1F;font-size:26rpx;font-weight:600}
.input{width:100%;height:92rpx;padding:0 28rpx;box-sizing:border-box;border:2rpx solid #E7EAE8;border-radius:20rpx;background:#F7F9F8;color:#191B1F;font-size:28rpx}
.input:focus{border-color:#0BBF63;background:#fff}
.eye{position:absolute;right:24rpx;bottom:24rpx;color:#0BBF63;font-size:22rpx}
.submit{height:92rpx;margin-top:16rpx;border-radius:46rpx;background:linear-gradient(135deg,#12D978,#08A152);color:#fff;font-size:30rpx;font-weight:800;line-height:92rpx;box-shadow:0 12rpx 28rpx rgba(8,161,82,.25)}
.submit::after{border:0}
.submit[disabled]{opacity:.55;color:#fff}
.hint{display:block;margin-top:28rpx;color:#AEB3BB;font-size:22rpx;text-align:center;line-height:1.6}
</style>
