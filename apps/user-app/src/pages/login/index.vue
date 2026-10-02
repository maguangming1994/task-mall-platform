<template>
  <view class="page" :style="topSafeAreaStyle">
    <view class="login-deco" />
    <view class="login-deco d2" />
    <view class="login-back" role="button" aria-label="返回上一页" @click="goBack">‹</view>

    <view class="login-top">
      <image v-if="brand.logo_url" class="login-logo login-logo-img" :src="brand.logo_url" mode="aspectFit" />
      <view v-else class="login-logo">{{ brand.logo_text }}</view>
      <text class="login-name">{{ brand.name }}</text>
      <text class="login-slogan">{{ needsPhoneBinding ? '微信身份已验证，绑定手机号后继续使用' : '校园任务互助 · 积分换好物' }}</text>
    </view>

    <view class="login-card">
      <template v-if="wechatMethod && !needsPhoneBinding">
        <button class="btn-wx" :loading="wechatSubmitting" :disabled="wechatSubmitting" @click="wechatLogin">
          <text class="btn-wx-icon">微</text>
          {{ wechatMethod === 'wechat_mini' ? '微信一键登录' : '微信授权登录' }}
        </button>
        <view class="divider"><view /><text>或使用手机号登录</text><view /></view>
      </template>

      <view v-if="needsPhoneBinding" class="verified-tip">
        <text class="verified-badge">✓</text>
        <view class="verified-main"><text class="verified-title">微信身份验证成功</text><text class="verified-copy">绑定手机号后即可继续使用任务、资产与商城服务</text></view>
      </view>

      <!-- #ifdef MP-WEIXIN -->
      <template v-if="needsPhoneBinding && wechatPhoneAvailable">
        <button
          v-if="agreed"
          class="btn-wx"
          open-type="getPhoneNumber"
          :loading="wechatPhoneSubmitting"
          :disabled="wechatPhoneSubmitting"
          @getphonenumber="authorizeWechatPhone"
        >
          微信授权手机号
        </button>
        <button v-else class="btn-wx" @click="ensureAgreed">微信授权手机号</button>
        <view class="divider"><view /><text>或使用短信验证码</text><view /></view>
      </template>
      <view v-else-if="needsPhoneBinding && capabilities" class="wechat-phone-unavailable">
        微信手机号授权暂时不可用，请使用短信验证码完成绑定。
      </view>
      <!-- #endif -->

      <view class="field-group">
        <view class="input-shell"><text class="input-prefix">+86</text><input v-model.trim="phone" class="input" type="number" maxlength="20" aria-label="手机号" placeholder="请输入常用手机号" /></view>
      </view>

      <view class="field-group">
        <view class="input-shell code-shell">
          <input v-model.trim="code" class="input" type="number" maxlength="10" aria-label="短信验证码" placeholder="请输入短信验证码" />
          <button class="code-button" :disabled="countdown > 0 || requestingCode" @click="requestCode">
            {{ countdown > 0 ? `${countdown}s 后重发` : requestingCode ? '发送中' : '获取验证码' }}
          </button>
        </view>
      </view>

      <view v-if="isDevelopment" class="dev-tip"><text class="dev-mark">DEV</text><text>本地调试验证码 246810</text></view>

      <view v-if="formError" class="form-error" role="alert" aria-live="assertive">{{ formError }}</view>

      <button :class="['btn-phone-submit', { primary: !wechatMethod || needsPhoneBinding }]" :loading="auth.submitting" :disabled="auth.submitting" @click="phoneAction">
        {{ needsPhoneBinding ? '完成绑定并进入首页' : '手机号登录' }}
      </button>

      <view
        class="login-agree"
        role="checkbox"
        :aria-checked="agreed"
        aria-label="同意用户协议和隐私政策"
        @click="toggleAgreement"
      >
        <view :class="['agree-dot', { checked: agreed }]">{{ agreed ? '✓' : '' }}</view>
        <view class="agree-copy">
          <text>登录即代表同意 </text>
          <text class="agree-link" @click.stop="openLegalDocument('user_agreement')">《用户服务协议》</text>
          <text> 和 </text>
          <text class="agree-link" @click.stop="openLegalDocument('privacy_policy')">《隐私政策》</text>
          <text>，未注册手机号将自动创建账号</text>
        </view>
      </view>
    </view>
  </view>
</template>

<script setup lang="ts">
import { onLoad } from '@dcloudio/uni-app'
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { ApiError } from '@/api/http'
import { loadHomeContent, type HomeBrand } from '@/api/home'
import { loadPendingLegalDocuments } from '@/api/legal'
import { requestWechatLoginCode } from '@/platform/auth'
import { loadClientCapabilities, type ClientCapabilities } from '@/platform/capabilities'
import { useAuthStore } from '@/stores/auth'
import { appendReturnUrl, clearReturnUrl, resolveReturnUrl } from '@/utils/authRedirect'
import { createTopSafeAreaStyle } from '@/utils/safeArea'

const topSafeAreaStyle = createTopSafeAreaStyle()
const auth = useAuthStore()
const phone = ref('')
const code = ref('')
const countdown = ref(0)
const requestingCode = ref(false)
const wechatSubmitting = ref(false)
const wechatPhoneSubmitting = ref(false)
const agreed = ref(false)
const formError = ref('')
const capabilities = ref<ClientCapabilities | null>(null)
const brand = ref<HomeBrand>({ logo_url: '', logo_text: 'TM', name: '任务商城', caption: 'TASK · REWARD · LIFE' })
const needsPhoneBinding = ref(Boolean(auth.user && !auth.user.phone_bound))
const returnUrl = ref<string | null>(null)
const isDevelopment = import.meta.env.MODE === 'development'
const wechatMethod = computed(() => capabilities.value?.login_methods.find(method => method !== 'phone'))
const wechatPhoneAvailable = computed(() => capabilities.value?.login_methods.includes('wechat_mini') === true)
let timer: ReturnType<typeof setInterval> | undefined

function showError(error: unknown) {
  const message = error instanceof ApiError ? error.message : error instanceof Error ? error.message : '操作失败，请稍后重试。'
  formError.value = message
  uni.showToast({ title: message, icon: 'none', duration: 3000 })
}

async function requestCode() {
  formError.value = ''
  if (!/^\+?[0-9]{6,20}$/.test(phone.value)) {
    formError.value = '请输入正确的手机号'
    uni.showToast({ title: formError.value, icon: 'none' })
    return
  }
  requestingCode.value = true
  try {
    await auth.requestPhoneCode(phone.value, needsPhoneBinding.value ? 'bind_phone' : 'login')
    uni.showToast({ title: '验证码已发送，请注意查收', icon: 'success' })
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
  formError.value = ''
  wechatSubmitting.value = true
  try {
    let credential = await requestWechatLoginCode(capabilities.value?.wechat_official_account_app_id)
    if (!credential) return
    try {
      const user = await auth.loginWithWechat(credential.channel, credential.code)
      await routeAfterAuthentication(!user.phone_bound)
    } catch (firstError) {
      // code 过期时自动重试一次
      if (firstError instanceof ApiError && firstError.code === 'WECHAT_AUTH_FAILED') {
        credential = await requestWechatLoginCode(capabilities.value?.wechat_official_account_app_id)
        if (!credential) return
        const user = await auth.loginWithWechat(credential.channel, credential.code)
        await routeAfterAuthentication(!user.phone_bound)
        return
      }
      throw firstError
    }
  } catch (error) {
    showError(error)
  } finally {
    wechatSubmitting.value = false
  }
}

async function phoneAction() {
  if (!ensureAgreed()) return
  formError.value = ''
  if (!phone.value || !code.value) {
    formError.value = '请填写手机号和验证码'
    uni.showToast({ title: formError.value, icon: 'none' })
    return
  }
  try {
    if (needsPhoneBinding.value) {
      const result = await auth.bindPhone(phone.value, code.value)
      await routeAfterAuthentication(false, result.accountResolution?.message)
    } else {
      await auth.loginWithPhone(phone.value, code.value)
      await routeAfterAuthentication(false)
    }
  } catch (error) {
    showError(error)
  }
}

interface WechatPhoneEvent {
  detail?: {
    code?: string
    errMsg?: string
  }
}

function isSessionError(error: unknown) {
  return error instanceof ApiError && (
    error.statusCode === 401
    || ['AUTH_REQUIRED', 'AUTH_SESSION_REVOKED'].includes(error.code)
  )
}

async function bindWechatPhoneWithSessionRecovery(phoneCode: string) {
  const restoreSessionAndBind = async () => {
    const credential = await requestWechatLoginCode(capabilities.value?.wechat_official_account_app_id)
    if (!credential || credential.channel !== 'mini') {
      throw new Error('微信登录状态已更新，请重新点击授权手机号。')
    }

    const user = await auth.loginWithWechat('mini', credential.code)
    needsPhoneBinding.value = !user.phone_bound
    return user.phone_bound
      ? { user, accountResolution: null }
      : auth.bindWechatPhone(phoneCode)
  }

  if (!auth.accessToken) return restoreSessionAndBind()

  try {
    return await auth.bindWechatPhone(phoneCode)
  } catch (error) {
    if (!isSessionError(error)) throw error
    return restoreSessionAndBind()
  }
}

async function authorizeWechatPhone(event: WechatPhoneEvent) {
  if (!ensureAgreed()) return
  formError.value = ''
  const phoneCode = event.detail?.code
  if (!phoneCode) {
    const denied = event.detail?.errMsg?.includes('deny') || event.detail?.errMsg?.includes('cancel')
    formError.value = denied ? '你已取消手机号授权，可改用短信验证码绑定。' : '未获取到微信手机号授权凭证，请重试或改用短信验证码。'
    uni.showToast({ title: formError.value, icon: 'none', duration: 3000 })
    return
  }

  wechatPhoneSubmitting.value = true
  try {
    const result = await bindWechatPhoneWithSessionRecovery(phoneCode)
    await routeAfterAuthentication(false, result.accountResolution?.message)
  } catch (error) {
    if (error instanceof ApiError && ['WECHAT_PHONE_PROVIDER_NOT_CONFIGURED', 'WECHAT_PROVIDER_UNAVAILABLE'].includes(error.code)) {
      const refreshed = await refreshCapabilities()
      if (refreshed?.login_methods.includes('wechat_mini')) {
        try {
          const result = await bindWechatPhoneWithSessionRecovery(phoneCode)
          await routeAfterAuthentication(false, result.accountResolution?.message)
          return
        } catch (retryError) {
          if (!(retryError instanceof ApiError) || !['WECHAT_PHONE_PROVIDER_NOT_CONFIGURED', 'WECHAT_PROVIDER_UNAVAILABLE'].includes(retryError.code)) {
            showError(retryError)
            return
          }
        }
      }
      showError(new Error('微信手机号授权暂时不可用，请使用短信验证码完成绑定，或稍后重试。'))
      return
    }
    showError(error)
  } finally {
    wechatPhoneSubmitting.value = false
  }
}

async function refreshCapabilities() {
  try {
    capabilities.value = await loadClientCapabilities()
    return capabilities.value
  } catch {
    return null
  }
}

async function routeAfterAuthentication(bindPhone: boolean, successMessage?: string) {
  const pending = await loadPendingLegalDocuments(auth.accessToken, 'account')
  if (pending.length > 0) {
    const consentUrl = `/pages/legal-consent/index?next=${bindPhone ? 'bind-phone' : 'home'}`
    uni.navigateTo({ url: appendReturnUrl(consentUrl, returnUrl.value) })
    return
  }
  if (bindPhone) {
    needsPhoneBinding.value = true
    uni.showToast({ title: '请继续绑定手机号', icon: 'none' })
    return
  }
  finishLogin(successMessage)
}

function finishLogin(successMessage?: string) {
  const target = returnUrl.value || resolveReturnUrl()
  clearReturnUrl()
  uni.showToast({
    title: successMessage || '登录成功',
    icon: successMessage ? 'none' : 'success',
    duration: successMessage ? 2600 : 1500,
  })
  setTimeout(() => uni.reLaunch({ url: target || '/pages/index/index' }), successMessage ? 900 : 350)
}

function ensureAgreed() {
  if (agreed.value) return true
  formError.value = '请先阅读并勾选用户协议和隐私政策'
  uni.showToast({ title: formError.value, icon: 'none' })
  return false
}

function toggleAgreement() {
  formError.value = ''
  agreed.value = !agreed.value
}

function goBack() {
  const pages = getCurrentPages()
  if (pages.length > 1) {
    uni.navigateBack()
    return
  }
  uni.reLaunch({ url: '/pages/index/index' })
}

function openLegalDocument(documentType: 'user_agreement' | 'privacy_policy') {
  const legalUrl = `/pages/legal-consent/index?mode=read&type=${documentType}`
  uni.navigateTo({ url: appendReturnUrl(legalUrl, returnUrl.value) })
}

onLoad((options) => {
  returnUrl.value = resolveReturnUrl(options?.returnUrl)
})

onMounted(async () => {
  const [capabilityResult, homeResult] = await Promise.allSettled([loadClientCapabilities(), loadHomeContent()])
  capabilities.value = capabilityResult.status === 'fulfilled' ? capabilityResult.value : null
  if (homeResult.status === 'fulfilled') brand.value = homeResult.value.brand
})

onUnmounted(() => {
  if (timer) clearInterval(timer)
})
</script>

<style scoped>
/* v13 登录页：绿渐变背景 + 白色 logo 块 + 微信一键登录 + 协议勾选 */
.page{position:relative;min-height:100vh;overflow:hidden;box-sizing:border-box;background:linear-gradient(135deg,#12D978,#08A152);display:flex;flex-direction:column}
.page::before{content:'';position:absolute;top:-240rpx;right:-180rpx;width:600rpx;height:600rpx;border-radius:50%;background:rgba(255,255,255,.10);pointer-events:none}
.page::after{content:'';position:absolute;bottom:420rpx;left:-160rpx;width:400rpx;height:400rpx;border-radius:50%;background:rgba(255,255,255,.08);pointer-events:none}
.login-deco{position:absolute;top:140rpx;left:80rpx;width:108rpx;height:108rpx;border-radius:36rpx;background:rgba(255,255,255,.14);pointer-events:none}
.login-deco.d2{top:240rpx;left:auto;right:108rpx;width:76rpx;height:76rpx;border-radius:24rpx;background:rgba(255,255,255,.10)}
.login-back{position:relative;z-index:3;display:flex;width:72rpx;height:72rpx;align-items:center;justify-content:center;margin:8rpx 0 0 24rpx;color:#fff;font-size:48rpx;font-weight:300;line-height:1}
.login-top{position:relative;z-index:2;flex:1;display:flex;flex-direction:column;align-items:center;padding-top:110rpx}
.login-logo{display:flex;width:168rpx;height:168rpx;align-items:center;justify-content:center;border-radius:48rpx;background:#fff;box-shadow:0 28rpx 60rpx rgba(0,0,0,.15);color:#08A152;font-size:80rpx;font-weight:900}
.login-logo-img{padding:24rpx;box-sizing:border-box}
.login-name{margin-top:36rpx;color:#fff;font-size:56rpx;font-weight:800;letter-spacing:8rpx}
.login-slogan{margin-top:16rpx;color:rgba(255,255,255,.88);font-size:26rpx;letter-spacing:2rpx}
.login-card{position:relative;z-index:2;margin:64rpx 40rpx calc(48rpx + env(safe-area-inset-bottom));padding:52rpx 40rpx 44rpx;border-radius:44rpx;background:#fff;box-shadow:0 -16rpx 80rpx rgba(0,0,0,.12)}
.btn-wx{display:flex;width:100%;height:96rpx;align-items:center;justify-content:center;gap:18rpx;margin:0;border:0;border-radius:48rpx;background:linear-gradient(135deg,#12D978,#08A152);box-shadow:0 16rpx 36rpx rgba(11,191,99,.35);color:#fff;font-size:32rpx;font-weight:700;line-height:96rpx}
.btn-wx-icon{display:flex;width:44rpx;height:44rpx;align-items:center;justify-content:center;border-radius:50%;background:rgba(255,255,255,.22);font-size:24rpx;font-weight:700}
.divider{display:flex;align-items:center;gap:24rpx;margin:36rpx 0 28rpx;color:#AEB3BB;font-size:24rpx}
.divider view{flex:1;height:2rpx;background:#EDEFEF}
.field-group+.field-group{margin-top:20rpx}
.input-shell{display:flex;height:96rpx;align-items:center;overflow:hidden;padding:0 16rpx;border:2rpx solid #EDEFEF;border-radius:24rpx;background:#F7F9F8;box-sizing:border-box;transition:border-color .18s ease,box-shadow .18s ease}
.input-shell:focus-within{border-color:#0BBF63;box-shadow:0 0 0 6rpx rgba(11,191,99,.10)}
.input-prefix{display:flex;height:56rpx;align-items:center;justify-content:center;flex:none;padding:0 20rpx;border-radius:16rpx;background:#E7F9EF;color:#08A152;font-size:26rpx;font-weight:700}
.input{min-width:0;height:92rpx;flex:1;padding:0 20rpx;color:#191B1F;font-size:28rpx;box-sizing:border-box}
.input-shell .input,.input-shell .input:focus{border:0!important;border-color:transparent!important;border-radius:0!important;background:transparent!important;box-shadow:none!important;outline:none}
.code-shell{padding-right:10rpx}
.code-button{width:auto;min-width:180rpx;height:68rpx;flex:none;margin:0;padding:0 24rpx;border:0;border-radius:18rpx;background:#E7F9EF;color:#08A152;font-size:24rpx;font-weight:700;line-height:68rpx;white-space:nowrap}
.code-button[disabled]{background:#F2F4F3;color:#AEB3BB}
.code-button::after,.btn-wx::after,.btn-phone-submit::after{border:0}
.dev-tip{display:flex;align-items:center;gap:16rpx;margin-top:24rpx;padding:16rpx 20rpx;border-radius:18rpx;background:#FFF8E1;color:#8a6416;font-size:24rpx}
.dev-mark{padding:4rpx 10rpx;border-radius:8rpx;background:#FFB020;color:#fff;font-size:20rpx;font-weight:900}
.form-error{margin-top:24rpx;padding:18rpx 22rpx;border:2rpx solid #FFD7D2;border-radius:18rpx;background:#FFF5F3;color:#FF3B30;font-size:24rpx;line-height:1.5}
.btn-phone-submit{display:flex;width:100%;height:96rpx;align-items:center;justify-content:center;margin:32rpx 0 0;border:3rpx solid #0BBF63;border-radius:48rpx;background:#fff;color:#08A152;font-size:30rpx;font-weight:700;line-height:96rpx}
.btn-phone-submit.primary{border:0;background:linear-gradient(135deg,#12D978,#08A152);box-shadow:0 16rpx 36rpx rgba(11,191,99,.3);color:#fff}
.btn-phone-submit[disabled],.btn-wx[disabled]{box-shadow:none;opacity:.5}
.login-agree{display:flex;align-items:flex-start;gap:12rpx;margin-top:32rpx;color:#AEB3BB;font-size:22rpx;line-height:1.5}
.agree-dot{display:flex;width:28rpx;height:28rpx;align-items:center;justify-content:center;flex:none;margin-top:2rpx;border:2rpx solid #D7DBD8;border-radius:50%;background:#fff;color:transparent;font-size:18rpx}
.agree-dot.checked{border-color:#0BBF63;background:#0BBF63;color:#fff}
.agree-copy{min-width:0;flex:1}
.agree-link{color:#08A152;font-weight:600}
.verified-tip{display:flex;align-items:center;gap:20rpx;margin-bottom:28rpx;padding:24rpx;border-radius:24rpx;background:#E7F9EF}
.verified-badge{display:flex;width:56rpx;height:56rpx;align-items:center;justify-content:center;flex:none;border-radius:50%;background:linear-gradient(135deg,#12D978,#08A152);color:#fff;font-size:30rpx;font-weight:700}
.verified-title,.verified-copy{display:block}
.verified-title{color:#08A152;font-size:28rpx;font-weight:700}
.verified-copy{margin-top:6rpx;color:#5a8a72;font-size:22rpx}
.wechat-phone-unavailable{margin-bottom:24rpx;padding:20rpx 24rpx;border-radius:20rpx;background:#FFF2E5;color:#F2620A;font-size:24rpx;line-height:1.5}
@media screen and (min-width:768px){.page{width:750rpx;margin:0 auto}}
</style>
