<template>
  <view class="page">
    <view class="hero">
      <view class="hero-badge">审</view>
      <text class="hero-title">审核工作台</text>
      <text class="hero-sub">校园任务商城 · 管理员专用通道</text>
    </view>

    <view class="card">
      <template v-if="!challenge">
        <template v-if="wechatAvailable">
          <view class="wechat-login" @click="wechatLogin">
            <text class="wechat-icon">微</text>
            <text class="wechat-text">微信一键登录</text>
          </view>
          <view class="divider"><text class="divider-text">或使用账号密码</text></view>
        </template>

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
      </template>

      <template v-else>
        <view class="mfa-tip">账号已开启二次验证，请输入身份验证器中的 6 位动态码，或一次性恢复码。</view>
        <view class="field">
          <text class="label">动态验证码</text>
          <input
            v-model="code"
            class="input"
            type="number"
            maxlength="20"
            placeholder="6 位动态码"
            placeholder-style="color:#AEB3BB"
            :disabled="admin.submitting"
          />
        </view>
        <text class="switch-back" @click="backToPassword">返回账号登录</text>
      </template>

      <button class="submit" :disabled="admin.submitting || !canSubmit" :loading="admin.submitting" @click="submit">
        {{ admin.submitting ? (challenge ? '验证中…' : '登录中…') : (challenge ? '验证并登录' : '登 录') }}
      </button>

      <view v-if="wechatAvailable && !challenge" class="remember-row">
        <text class="remember-label">记住此微信，下次免密登录</text>
        <switch :checked="rememberWechat" color="#08A152" style="transform: scale(.8)" @change="onRememberChange" />
      </view>

      <text class="hint">仅限已授权的审核管理员使用，操作将记录审计日志。</text>
    </view>
  </view>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue'
import { onLoad, onShow } from '@dcloudio/uni-app'
import { ApiError } from '@/api/http'
import type { AdminMfaChallenge, AdminProfile } from '@/api/admin/auth'
import { bindWechat } from '@/api/admin/wechat'
import {
  consumeAdminWechatCallback, hasWechatBindingFlag, isWechatH5,
  localAdminWechatCode, setWechatBindingFlag, startAdminWechatAuth,
} from '@/platform/admin-wechat'
import { useAdminStore } from '@/stores/admin'

const admin = useAdminStore()
const account = ref('')
const password = ref('')
const code = ref('')
const showPassword = ref(false)
const rememberWechat = ref(false)
const challenge = ref<AdminMfaChallenge | null>(null)

const wechatAvailable = computed(() => isWechatH5() || localAdminWechatCode() !== null)

const canSubmit = computed(() => challenge.value
  ? code.value.trim().length >= 6
  : account.value.trim().length >= 3 && password.value.length >= 6)

let callbackHandled = false
let autoAttempted = false

onLoad((query) => {
  const callback = consumeAdminWechatCallback(query as Record<string, string | undefined>)
  if (!callback) return
  callbackHandled = true
  void handleWechatCode(callback.mode, callback.code)
})

onShow(() => {
  // 仅在本机曾绑定过、且当前无会话时尝试静默免密，避免每次打开都跳授权
  if (callbackHandled || autoAttempted) return
  if (!admin.isLoggedIn && hasWechatBindingFlag() && isWechatH5()) {
    autoAttempted = true
    void startAdminWechatAuth('login')
  }
})

async function submit() {
  if (!canSubmit.value || admin.submitting) return
  try {
    if (challenge.value) {
      const result = await admin.verifyMfa(challenge.value, code.value)
      await finish(result.admin, true)
      return
    }
    const result = await admin.login(account.value, password.value)
    if ('mfa_required' in result) {
      challenge.value = result
      code.value = ''
      uni.showToast({ title: '请输入动态验证码', icon: 'none' })
      return
    }
    await finish(result.admin, true)
  } catch (error) {
    // 挑战过期或已消费时回到账号密码步骤，避免用户卡在无效动态码页面
    if (challenge.value && error instanceof ApiError && error.code === 'ADMIN_MFA_CHALLENGE_INVALID') {
      backToPassword()
    }
    toastError(error, '登录失败，请检查网络。')
  }
}

/** allowBind：密码登录成功后可顺带完成微信绑定；微信免密登录本身无需再绑 */
async function finish(profile: AdminProfile, allowBind: boolean) {
  if (!admin.hasAnyReviewAccess) {
    // 无审核权限的账号不保留会话，避免后续请求携带无效管理员令牌
    admin.clear()
    uni.showToast({ title: '当前角色没有审核权限，请联系超管分配。', icon: 'none', duration: 2500 })
    return
  }

  if (allowBind && rememberWechat.value && wechatAvailable.value) {
    const localCode = localAdminWechatCode()
    if (localCode) {
      try {
        await bindWechat(localCode)
        setWechatBindingFlag(true)
      } catch {
        // 绑定失败不影响本次登录
      }
    } else if (await startAdminWechatAuth('bind')) {
      // 授权回调会重新进入本页，由 handleWechatCode 完成绑定后跳转
      return
    }
  }

  uni.showToast({ title: '登录成功', icon: 'success' })
  uni.reLaunch({ url: '/pages/admin/review/index' })
}

async function handleWechatCode(mode: 'login' | 'bind', wechatCode: string) {
  if (mode === 'bind') {
    if (!admin.isLoggedIn) {
      uni.showToast({ title: '登录状态已失效，请重新登录后再绑定', icon: 'none' })
      return
    }
    try {
      await bindWechat(wechatCode)
      setWechatBindingFlag(true)
      uni.showToast({ title: '已记住此微信', icon: 'success' })
      uni.reLaunch({ url: '/pages/admin/review/index' })
    } catch (error) {
      toastError(error, '微信绑定失败')
    }
    return
  }

  try {
    const result = await admin.loginWithWechat(wechatCode)
    setWechatBindingFlag(true)
    if ('mfa_required' in result) {
      challenge.value = result
      code.value = ''
      return
    }
    await finish(result.admin, false)
  } catch (error) {
    if (error instanceof ApiError && error.code === 'WECHAT_BINDING_NOT_FOUND') {
      setWechatBindingFlag(false)
      uni.showToast({ title: '当前微信未绑定管理员，请用账号密码登录', icon: 'none', duration: 2500 })
      return
    }
    toastError(error, '微信登录失败')
  }
}

function wechatLogin() {
  const localCode = localAdminWechatCode()
  if (localCode) {
    void handleWechatCode('login', localCode)
    return
  }
  startAdminWechatAuth('login').catch(() => {
    uni.showToast({ title: '发起微信授权失败，请稍后重试', icon: 'none' })
  })
}

function onRememberChange(event: Event) {
  const detail = (event as unknown as { detail?: { value?: boolean } }).detail
  rememberWechat.value = Boolean(detail?.value)
}

function backToPassword() {
  challenge.value = null
  code.value = ''
}

function toastError(error: unknown, fallback: string) {
  const message = error instanceof ApiError ? error.message : fallback
  uni.showToast({ title: message || fallback, icon: 'none' })
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
.wechat-login{display:flex;align-items:center;justify-content:center;gap:14rpx;height:92rpx;border-radius:46rpx;background:#E7F9EF;color:#087A43;font-size:28rpx;font-weight:700}
.wechat-icon{display:flex;align-items:center;justify-content:center;width:40rpx;height:40rpx;border-radius:50%;background:#08A152;color:#fff;font-size:22rpx}
.wechat-text{font-size:28rpx}
.divider{display:flex;align-items:center;justify-content:center;margin:32rpx 0 28rpx}
.divider-text{color:#AEB3BB;font-size:22rpx}
.mfa-tip{margin-bottom:32rpx;padding:20rpx 24rpx;border-radius:16rpx;background:#E7F9EF;color:#087A43;font-size:24rpx;line-height:1.6}
.switch-back{display:block;margin:-8rpx 0 24rpx;color:#0BBF63;font-size:24rpx;text-align:center}
.submit{height:92rpx;margin-top:16rpx;border-radius:46rpx;background:linear-gradient(135deg,#12D978,#08A152);color:#fff;font-size:30rpx;font-weight:800;line-height:92rpx;box-shadow:0 12rpx 28rpx rgba(8,161,82,.25)}
.submit::after{border:0}
.submit[disabled]{opacity:.55;color:#fff}
.remember-row{display:flex;align-items:center;justify-content:space-between;margin-top:28rpx;padding:0 8rpx}
.remember-label{color:#767C85;font-size:24rpx}
.hint{display:block;margin-top:20rpx;color:#AEB3BB;font-size:22rpx;text-align:center;line-height:1.6}
</style>
