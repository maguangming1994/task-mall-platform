<template>
  <view class="page">
    <view class="intro">
      <text class="eyebrow">SECURE ACCESS</text>
      <text class="title">{{ needsPhoneBinding ? '绑定安全手机号' : '登录任务商城' }}</text>
      <text class="subtitle">
        {{ needsPhoneBinding ? '微信身份已验证。绑定手机号后即可参与任务、管理资产和商城结算。' : '登录后可领取任务、管理资产并进入商城结算。' }}
      </text>
    </view>

    <view class="form-card">
      <template v-if="wechatMethod && !needsPhoneBinding">
        <button class="wechat-button" :loading="wechatSubmitting" :disabled="wechatSubmitting || !agreed" @click="wechatLogin">
          <text class="wechat-icon">微</text>
          {{ wechatMethod === 'wechat_mini' ? '微信小程序快捷登录' : '微信公众号授权登录' }}
        </button>
        <view class="divider"><view /><text>或使用手机号</text><view /></view>
      </template>

      <view v-if="needsPhoneBinding" class="verified-tip">
        <text class="verified-badge">✓</text>
        <view><text class="verified-title">微信身份验证成功</text><text class="verified-copy">手机号只用于账号安全与必要业务联系</text></view>
      </view>

      <text class="label">手机号</text>
      <input v-model.trim="phone" class="input" type="number" maxlength="20" placeholder="请输入手机号" />

      <text class="label code-label">验证码</text>
      <view class="code-row">
        <input v-model.trim="code" class="input code-input" type="number" maxlength="10" placeholder="请输入验证码" />
        <button class="code-button" :disabled="countdown > 0 || requestingCode" @click="requestCode">
          {{ countdown > 0 ? `${countdown}s` : requestingCode ? '发送中' : '获取验证码' }}
        </button>
      </view>

      <view v-if="isDevelopment" class="dev-tip"><text>本地开发验证码：246810</text></view>

      <button class="login-button" :loading="auth.submitting" :disabled="auth.submitting || !agreed" @click="phoneAction">
        {{ needsPhoneBinding ? '完成绑定并继续' : '手机号登录' }}
      </button>
      <view class="agreement-row" @click="agreed = !agreed">
        <view :class="['agreement-check', { checked: agreed }]">{{ agreed ? '✓' : '' }}</view>
        <view class="agreement-copy">
          <text>我已阅读并同意</text>
          <text class="agreement-link" @click.stop="openLegalDocument('user_agreement')">《用户协议》</text>
          <text>和</text>
          <text class="agreement-link" @click.stop="openLegalDocument('privacy_policy')">《隐私政策》</text>
        </view>
      </view>
    </view>
  </view>
</template>

<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { ApiError } from '@/api/http'
import { loadPendingLegalDocuments } from '@/api/legal'
import { requestWechatLoginCode } from '@/platform/auth'
import { loadClientCapabilities, type ClientCapabilities } from '@/platform/capabilities'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const phone = ref('')
const code = ref('')
const countdown = ref(0)
const requestingCode = ref(false)
const wechatSubmitting = ref(false)
const agreed = ref(false)
const capabilities = ref<ClientCapabilities | null>(null)
const needsPhoneBinding = ref(Boolean(auth.user && !auth.user.phone_bound))
const isDevelopment = import.meta.env.DEV
const wechatMethod = computed(() => capabilities.value?.login_methods.find(method => method !== 'phone'))
let timer: ReturnType<typeof setInterval> | undefined

function showError(error: unknown) {
  const message = error instanceof ApiError ? error.message : error instanceof Error ? error.message : '操作失败，请稍后重试。'
  uni.showToast({ title: message, icon: 'none', duration: 3000 })
}

async function requestCode() {
  if (!/^\+?[0-9]{6,20}$/.test(phone.value)) {
    uni.showToast({ title: '请输入正确的手机号', icon: 'none' })
    return
  }

  requestingCode.value = true
  try {
    await auth.requestPhoneCode(phone.value, needsPhoneBinding.value ? 'bind_phone' : 'login')
    uni.showToast({ title: '验证码已发送', icon: 'success' })
    countdown.value = 60
    timer = setInterval(() => {
      countdown.value -= 1
      if (countdown.value <= 0 && timer) clearInterval(timer)
    }, 1000)
  } catch (error) {
    showError(error)
  } finally {
    requestingCode.value = false
  }
}

async function wechatLogin() {
  if (!ensureAgreed()) return
  wechatSubmitting.value = true
  try {
    const credential = await requestWechatLoginCode()
    if (!credential) return
    const user = await auth.loginWithWechat(credential.channel, credential.code)
    await routeAfterAuthentication(!user.phone_bound)
  } catch (error) {
    showError(error)
  } finally {
    wechatSubmitting.value = false
  }
}

async function phoneAction() {
  if (!ensureAgreed()) return
  if (!phone.value || !code.value) {
    uni.showToast({ title: '请填写手机号和验证码', icon: 'none' })
    return
  }

  try {
    if (needsPhoneBinding.value) {
      await auth.bindPhone(phone.value, code.value)
      finishLogin()
    } else {
      await auth.loginWithPhone(phone.value, code.value)
      await routeAfterAuthentication(false)
    }
  } catch (error) {
    showError(error)
  }
}

async function routeAfterAuthentication(bindPhone: boolean) {
  const pending = await loadPendingLegalDocuments(auth.accessToken, 'account')
  if (pending.length > 0) {
    uni.navigateTo({ url: `/pages/legal-consent/index?next=${bindPhone ? 'bind-phone' : 'home'}` })
    return
  }
  if (bindPhone) {
    needsPhoneBinding.value = true
    uni.showToast({ title: '请继续绑定手机号', icon: 'none' })
    return
  }
  finishLogin()
}

function finishLogin() {
  uni.showToast({ title: '登录成功', icon: 'success' })
  setTimeout(() => uni.reLaunch({ url: '/pages/index/index' }), 350)
}

function ensureAgreed() {
  if (agreed.value) return true
  uni.showToast({ title: '请先阅读并勾选用户协议和隐私政策', icon: 'none' })
  return false
}

function openLegalDocument(documentType: 'user_agreement' | 'privacy_policy') {
  uni.navigateTo({ url: `/pages/legal-consent/index?mode=read&type=${documentType}` })
}

onMounted(async () => {
  try {
    capabilities.value = await loadClientCapabilities()
  } catch {
    capabilities.value = null
  }
})

onUnmounted(() => {
  if (timer) clearInterval(timer)
})
</script>

<style scoped>
.page { min-height: 100vh; padding: 56rpx 32rpx; box-sizing: border-box; background: #f5f7fb; }
.intro { padding: 28rpx 16rpx 40rpx; }
.eyebrow, .title, .subtitle, .label, .verified-title, .verified-copy { display: block; }
.eyebrow { color: #2f6bff; font-size: 22rpx; font-weight: 700; letter-spacing: 3rpx; }
.title { margin-top: 20rpx; color: #172033; font-size: 48rpx; font-weight: 750; }
.subtitle { margin-top: 16rpx; color: #667085; font-size: 27rpx; line-height: 1.7; }
.form-card { padding: 38rpx 32rpx; border: 1rpx solid #e7eaf0; border-radius: 28rpx; background: #fff; box-shadow: 0 18rpx 50rpx rgba(15, 23, 42, .06); }
.wechat-button { display: flex; align-items: center; justify-content: center; gap: 18rpx; height: 96rpx; margin: 0; border: 0; border-radius: 20rpx; background: #08b86a; color: #fff; font-size: 29rpx; font-weight: 650; line-height: 96rpx; }
.wechat-icon { display: inline-flex; align-items: center; justify-content: center; width: 42rpx; height: 42rpx; border-radius: 50%; background: rgba(255,255,255,.2); font-size: 23rpx; }
.divider { display: flex; align-items: center; gap: 18rpx; margin: 34rpx 0; color: #98a2b3; font-size: 22rpx; }
.divider view { flex: 1; height: 1rpx; background: #eaecf0; }
.verified-tip { display: flex; align-items: center; gap: 20rpx; margin-bottom: 32rpx; padding: 22rpx; border-radius: 18rpx; background: #ecfdf3; }
.verified-badge { display: flex; align-items: center; justify-content: center; width: 52rpx; height: 52rpx; border-radius: 50%; background: #12b76a; color: #fff; font-size: 28rpx; }
.verified-title { color: #067647; font-size: 25rpx; font-weight: 650; }
.verified-copy { margin-top: 5rpx; color: #4e8c70; font-size: 21rpx; }
.label { margin-bottom: 14rpx; color: #344054; font-size: 26rpx; font-weight: 600; }
.code-label { margin-top: 30rpx; }
.input { height: 92rpx; padding: 0 26rpx; border: 2rpx solid #e4e7ec; border-radius: 18rpx; background: #fbfcfe; color: #101828; font-size: 30rpx; box-sizing: border-box; }
.code-row { display: flex; gap: 16rpx; }
.code-input { flex: 1; min-width: 0; }
.code-button { width: 226rpx; height: 92rpx; margin: 0; border: 0; border-radius: 18rpx; background: #eef4ff; color: #2f6bff; font-size: 25rpx; line-height: 92rpx; }
.code-button::after, .login-button::after, .wechat-button::after { border: 0; }
.dev-tip { margin-top: 20rpx; padding: 18rpx 20rpx; border-radius: 14rpx; background: #fff8e6; color: #9a6700; font-size: 24rpx; }
.login-button { height: 96rpx; margin-top: 38rpx; border: 0; border-radius: 20rpx; background: linear-gradient(135deg, #2f6bff, #5b8cff); color: #fff; font-size: 30rpx; font-weight: 650; line-height: 96rpx; }
.agreement-row { display: flex; align-items: flex-start; justify-content: center; gap: 14rpx; margin-top: 26rpx; color: #667085; font-size: 22rpx; line-height: 1.7; }
.agreement-check { flex: 0 0 auto; display: flex; align-items: center; justify-content: center; width: 30rpx; height: 30rpx; margin-top: 3rpx; border: 2rpx solid #b9c2d0; border-radius: 7rpx; background: #fff; color: #fff; font-size: 22rpx; line-height: 30rpx; }
.agreement-check.checked { border-color: #2f6bff; background: #2f6bff; }
.agreement-copy { flex: 0 1 auto; }
.agreement-link { color: #2f6bff; }
.login-button[disabled], .wechat-button[disabled] { opacity: .55; }
</style>
