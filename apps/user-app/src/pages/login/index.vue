<template>
  <view class="page">
    <view class="ambient ambient-one" />
    <view class="ambient ambient-two" />
    <view class="login-topbar">
      <view class="login-back" role="button" aria-label="返回上一页" @click="goBack">‹</view>
      <text class="login-page-title">登录</text>
      <view class="login-topbar-spacer" aria-hidden="true" />
    </view>
    <view class="page-shell">
      <view class="intro-panel">
        <view class="brand-line">
          <image v-if="brand.logo_url" class="brand-image" :src="brand.logo_url" mode="aspectFit" />
          <view v-else class="brand-symbol">{{ brand.logo_text }}</view>
          <view class="brand-copy"><text class="brand-name">{{ brand.name }}</text><text class="brand-caption">{{ brand.caption }}</text></view>
          <text class="secure-pill">安全登录</text>
        </view>

        <view class="hero-copy">
          <text class="eyebrow">CAMPUS ACCESS</text>
          <text class="title">{{ needsPhoneBinding ? '补充手机号，完成账号保护' : '欢迎回来，校园生活从这里继续' }}</text>
          <text class="subtitle">
            {{ needsPhoneBinding ? '微信身份已验证，绑定手机号后即可继续使用任务、资产与商城服务。' : '一个账号连接任务、校园服务与积分商城。验证手机号即可登录，新用户将自动完成注册。' }}
          </text>
        </view>

        <view class="welcome-visual" aria-hidden="true">
          <view class="visual-orbit orbit-one" />
          <view class="visual-orbit orbit-two" />
          <view class="visual-card visual-card-back"><text>积分</text></view>
          <view class="visual-card visual-card-front"><text>★</text><text class="pass-label">CAMPUS PASS</text></view>
        </view>

        <view class="benefit-row">
          <view><text class="benefit-title">任务</text><text>领取成长奖励</text></view>
          <view><text class="benefit-title">资产</text><text>记录清晰可查</text></view>
          <view><text class="benefit-title">商城</text><text>多种方式兑换</text></view>
        </view>
      </view>

      <view class="form-card">
        <view class="form-heading">
          <view><text class="form-kicker">{{ needsPhoneBinding ? 'PHONE BINDING' : 'ACCOUNT ACCESS' }}</text><text class="form-title">{{ needsPhoneBinding ? '绑定安全手机号' : '手机号登录 / 注册' }}</text></view>
          <text class="form-step">{{ needsPhoneBinding ? '最后一步' : '安全验证' }}</text>
        </view>

        <template v-if="wechatMethod && !needsPhoneBinding">
          <button class="wechat-button" :loading="wechatSubmitting" :disabled="wechatSubmitting" @click="wechatLogin">
            <text class="wechat-icon">微</text>
            {{ wechatMethod === 'wechat_mini' ? '微信小程序快捷登录' : '微信公众号授权登录' }}
          </button>
          <view class="divider"><view /><text>也可以使用手机号</text><view /></view>
        </template>

        <view v-if="needsPhoneBinding" class="verified-tip">
          <text class="verified-badge">✓</text>
          <view><text class="verified-title">微信身份验证成功</text><text class="verified-copy">手机号仅用于账号安全与必要业务联系</text></view>
        </view>

        <!-- #ifdef MP-WEIXIN -->
        <template v-if="needsPhoneBinding && wechatPhoneAvailable">
          <button
            v-if="agreed"
            class="wechat-phone-button"
            open-type="getPhoneNumber"
            :loading="wechatPhoneSubmitting"
            :disabled="wechatPhoneSubmitting"
            @getphonenumber="authorizeWechatPhone"
          >
            微信授权手机号
          </button>
          <button v-else class="wechat-phone-button" @click="ensureAgreed">微信授权手机号</button>
          <view class="divider"><view /><text>或使用短信验证码</text><view /></view>
        </template>
        <view v-else-if="needsPhoneBinding && capabilities" class="wechat-phone-unavailable">
          微信手机号授权暂时不可用，请使用短信验证码完成绑定。
        </view>
        <!-- #endif -->

        <view class="field-group">
          <text class="label">手机号</text>
          <view class="input-shell phone-shell"><text class="input-prefix phone-prefix">+86</text><input v-model.trim="phone" class="input" type="number" maxlength="20" aria-label="手机号" placeholder="请输入常用手机号" /></view>
        </view>

        <view class="field-group">
          <text class="label">短信验证码</text>
          <view class="input-shell code-shell">
            <input v-model.trim="code" class="input code-input" type="number" maxlength="10" aria-label="短信验证码" placeholder="请输入短信验证码" />
            <button class="code-button" :disabled="countdown > 0 || requestingCode" @click="requestCode">
              {{ countdown > 0 ? `${countdown}s 后重发` : requestingCode ? '发送中' : '获取验证码' }}
            </button>
          </view>
        </view>

        <view v-if="isDevelopment" class="dev-tip"><text class="dev-mark">DEV</text><text>本地调试验证码 246810</text></view>

        <view
          class="agreement-row"
          role="checkbox"
          :aria-checked="agreed"
          aria-label="同意用户协议和隐私政策"
          @click="toggleAgreement"
        >
          <view :class="['agreement-check', { checked: agreed }]">{{ agreed ? '✓' : '' }}</view>
          <view class="agreement-copy">
            <text>我已阅读并同意</text>
            <text class="agreement-link" @click.stop="openLegalDocument('user_agreement')">《用户协议》</text>
            <text>与</text>
            <text class="agreement-link" @click.stop="openLegalDocument('privacy_policy')">《隐私政策》</text>
          </view>
        </view>

        <view v-if="formError" class="form-error" role="alert" aria-live="assertive">{{ formError }}</view>

        <button class="login-button" :loading="auth.submitting" :disabled="auth.submitting" @click="phoneAction">
          {{ needsPhoneBinding ? '完成绑定并进入首页' : '验证并登录' }}
        </button>
        <text class="register-note">{{ needsPhoneBinding ? '完成绑定后将自动返回业务页面' : '未注册手机号验证后将自动创建账号，无需另行注册' }}</text>
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
const isDevelopment = import.meta.env.DEV
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
    const credential = await requestWechatLoginCode(capabilities.value?.wechat_official_account_app_id)
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
.login-topbar{position:relative;z-index:2;display:flex;align-items:center;justify-content:space-between;height:84rpx;padding:calc(env(safe-area-inset-top) + 4rpx) 28rpx 0;box-sizing:content-box}
.login-back{display:flex;width:64rpx;height:64rpx;align-items:center;justify-content:center;border-radius:18rpx;color:#10283f;font-size:30px;font-weight:350;line-height:1}
.login-topbar-spacer{width:64rpx;height:64rpx}.login-page-title{color:#0d2740;font-size:22px;font-weight:950}
.page{position:relative;min-height:100vh;overflow:hidden;background:linear-gradient(180deg,#eafaff 0,#f5fbff 42%,#f7f8fc 100%);box-sizing:border-box}.ambient{position:absolute;border-radius:50%;pointer-events:none}.ambient-one{right:-150rpx;top:-130rpx;width:430rpx;height:430rpx;background:rgba(42,202,202,.18)}.ambient-two{left:-220rpx;top:440rpx;width:420rpx;height:420rpx;background:rgba(255,219,77,.18)}.page-shell{position:relative;z-index:1;padding:30rpx 28rpx calc(52rpx + env(safe-area-inset-bottom));box-sizing:border-box}
.intro-panel{position:relative;overflow:hidden;padding:28rpx 28rpx 24rpx;border:1rpx solid rgba(65,163,205,.22);border-radius:32rpx;background:linear-gradient(135deg,rgba(255,255,255,.96),rgba(226,248,252,.94));box-shadow:0 18rpx 44rpx rgba(31,98,137,.11)}.brand-line{display:flex;align-items:center;gap:14rpx}.brand-symbol,.brand-image{width:64rpx;height:64rpx;flex:none;border-radius:19rpx}.brand-symbol{display:flex;align-items:center;justify-content:center;background:linear-gradient(145deg,#1267df,#19c6d1);box-shadow:0 9rpx 22rpx rgba(18,103,223,.20);color:#fff;font-size:14px;font-weight:900}.brand-image{display:block}.brand-copy{min-width:0}.brand-name,.brand-caption{display:block}.brand-name{color:var(--tm-ink-900);font-size:17px;font-weight:900}.brand-caption{margin-top:2rpx;color:#556b91;font-size:10px;font-weight:800;letter-spacing:1.4rpx}.secure-pill{margin-left:auto;padding:9rpx 14rpx;border-radius:999rpx;background:#e5f8ed;color:#14784c;font-size:13px;font-weight:850}
.hero-copy{position:relative;z-index:2;max-width:440rpx;margin-top:62rpx}.eyebrow,.title,.subtitle,.form-kicker,.form-title,.label,.verified-title,.verified-copy,.register-note{display:block}.eyebrow{color:#087f9d;font-size:13px;font-weight:900;letter-spacing:2.4rpx}.title{margin-top:12rpx;color:var(--tm-ink-900);font-size:22px;font-weight:950;line-height:1.26;letter-spacing:-.03em}.subtitle{margin-top:13rpx;color:#5f7080;font-size:14px;font-weight:600;line-height:1.65}.welcome-visual{position:absolute;right:-8rpx;top:105rpx;width:235rpx;height:205rpx}.visual-card{position:absolute;display:flex;align-items:center;justify-content:center;flex-direction:column;width:145rpx;height:102rpx;border:1rpx solid rgba(24,76,105,.12);border-radius:22rpx;box-shadow:0 14rpx 28rpx rgba(20,89,128,.12)}.visual-card-back{right:0;bottom:10rpx;transform:rotate(11deg);background:linear-gradient(145deg,#22c8d1,#56d6c5);color:#fff;font-size:14px;font-weight:850}.visual-card-front{left:5rpx;top:5rpx;transform:rotate(-8deg);background:linear-gradient(145deg,#fff4a4,#ffd94d);color:#ff5561}.visual-card-front>text:first-child{font-size:24px;font-weight:950}.pass-label{margin-top:2rpx;color:#635619;font-size:10px;font-weight:900;letter-spacing:.08em}.visual-orbit{position:absolute;border:1rpx solid rgba(18,103,223,.13);border-radius:50%}.orbit-one{right:8rpx;top:4rpx;width:185rpx;height:185rpx}.orbit-two{right:43rpx;top:38rpx;width:118rpx;height:118rpx}.benefit-row{display:grid;grid-template-columns:repeat(3,1fr);gap:9rpx;margin-top:28rpx}.benefit-row view{padding:14rpx 11rpx;border:1rpx solid rgba(87,151,184,.16);border-radius:17rpx;background:rgba(255,255,255,.76)}.benefit-row text{display:block}.benefit-title{color:var(--tm-ink-900);font-size:13px;font-weight:900}.benefit-row text:last-child{margin-top:4rpx;color:#758594;font-size:10px;white-space:nowrap}
.form-card{margin-top:22rpx;padding:28rpx;border:1rpx solid #dceaf2;border-radius:32rpx;background:rgba(255,255,255,.98);box-shadow:0 18rpx 42rpx rgba(28,79,111,.09)}
.form-heading{display:flex;align-items:flex-start;justify-content:space-between;gap:18rpx;margin-bottom:26rpx}.form-kicker{color:#1178bd;font-size:10px;font-weight:900;letter-spacing:2rpx}.form-title{margin-top:6rpx;color:var(--tm-ink-900);font-size:17px;font-weight:950}.form-step{padding:8rpx 13rpx;border-radius:999rpx;background:#fff4bd;color:#7c5b00;font-size:12px;font-weight:850}
.wechat-button,.wechat-phone-button{display:flex;align-items:center;justify-content:center;gap:13rpx;height:84rpx;margin:0;border:0;border-radius:20rpx;background:#1fbd6b;box-shadow:0 10rpx 22rpx rgba(21,150,84,.18);color:#fff;font-size:15px;font-weight:850;line-height:84rpx}.wechat-icon{display:inline-flex;align-items:center;justify-content:center;width:40rpx;height:40rpx;border-radius:50%;background:rgba(255,255,255,.2);font-size:13px}.divider{display:flex;align-items:center;gap:16rpx;margin:25rpx 0;color:#98a5b2;font-size:13px}.divider view{flex:1;height:1rpx;background:#e6edf1}
.verified-tip{display:flex;align-items:center;gap:16rpx;margin-bottom:24rpx;padding:18rpx;border:1rpx solid #c9ebd8;border-radius:18rpx;background:#effbf4}.verified-badge{display:flex;align-items:center;justify-content:center;width:48rpx;height:48rpx;flex:none;border-radius:50%;background:#1ab66d;color:#fff;font-size:15px}.verified-title{color:#0b7544;font-size:14px;font-weight:850}.verified-copy{margin-top:4rpx;color:#56806b;font-size:12px}
.wechat-phone-unavailable{margin-bottom:22rpx;padding:16rpx 18rpx;border:1rpx solid #f0d7a8;border-radius:16rpx;background:#fff9e8;color:#8a6416;font-size:13px;line-height:1.5}
.field-group+.field-group{margin-top:22rpx}.label{margin-bottom:10rpx;color:#31455a;font-size:14px;font-weight:850}.input-shell{display:flex;height:82rpx;align-items:center;overflow:hidden;padding:0 10rpx;border:1rpx solid #d4e4ec;border-radius:18rpx;background:#f9fcfd;box-sizing:border-box;transition:border-color .18s ease,box-shadow .18s ease}.input-shell:focus-within{border-color:#2096db;box-shadow:0 0 0 5rpx rgba(32,150,219,.10)}.input-prefix{display:flex;height:46rpx;align-items:center;justify-content:center;flex:none;padding:0 15rpx;border-radius:13rpx;background:#e9f5fb;color:#286078;font-size:13px;font-weight:900}.phone-prefix{margin-left:1rpx;letter-spacing:.4rpx}.input{min-width:0;height:80rpx;flex:1;padding:0 16rpx;color:var(--tm-ink-900);font-size:15px;box-sizing:border-box}.code-shell{min-width:0;padding-right:7rpx}.code-input{padding-left:17rpx}.code-button{width:auto;min-width:146rpx;height:58rpx;flex:none;margin:0;padding:0 18rpx;border:0;border-radius:14rpx;background:#dff3ff;color:#0d68b7;font-size:13px;font-weight:900;line-height:58rpx;white-space:nowrap}.code-button[disabled]{background:#edf2f5;color:#98a3ad}
.dev-tip{display:flex;align-items:center;gap:10rpx;margin-top:18rpx;padding:13rpx 15rpx;border-radius:14rpx;background:#fff8df;color:#886514;font-size:13px}.dev-mark{padding:3rpx 7rpx;border-radius:7rpx;background:#ffd84d;color:#534000;font-size:10px;font-weight:950}.agreement-row{display:flex;align-items:flex-start;gap:12rpx;margin-top:24rpx;color:#6c7884;font-size:13px;line-height:1.65}.agreement-check{display:flex;width:30rpx;height:30rpx;align-items:center;justify-content:center;flex:none;margin-top:1rpx;border:1rpx solid #aebdc8;border-radius:8rpx;background:#fff;color:#fff;font-size:13px}.agreement-check.checked{border-color:#158fca;background:linear-gradient(145deg,#1267df,#20c3c8)}.agreement-copy{min-width:0;flex:1}.agreement-link{color:#0c6fc0;font-weight:800}.login-button{height:88rpx;margin-top:22rpx;border:0;border-radius:21rpx;background:linear-gradient(115deg,#1267df,#1ba2de 62%,#20c6c5);box-shadow:0 13rpx 28rpx rgba(17,111,189,.22);color:#fff;font-size:17px;font-weight:900;line-height:88rpx}.login-button[disabled],.wechat-button[disabled],.wechat-phone-button[disabled]{box-shadow:none;opacity:.46}.register-note{margin-top:14rpx;color:#8a96a2;text-align:center;font-size:12px;line-height:1.45}.code-button::after,.login-button::after,.wechat-button::after,.wechat-phone-button::after{border:0}
.form-error{margin-top:16rpx;padding:14rpx 16rpx;border:1rpx solid #f4c4be;border-radius:14rpx;background:#fff5f3;color:#b42318;font-size:13px;line-height:1.5}
.input-shell .input,.input-shell .input:focus{border:0!important;border-color:transparent!important;border-radius:0!important;background:transparent!important;box-shadow:none!important;outline:none}
@media screen and (min-width:768px){.page-shell{width:750rpx;margin:0 auto}.page{background:#f3f8fb}.page-shell{box-shadow:0 0 70rpx rgba(24,42,78,.06)}}
/* V09 登录稿：把登录动作的主色收回校园绿，湖蓝只作为环境色与辅助强调。 */
.page{background:linear-gradient(180deg,#eefbff 0,#f8fcfd 42%,#f7fafc 100%)}
.intro-panel{background-image:linear-gradient(110deg,rgba(255,255,255,.98) 0%,rgba(239,253,251,.93) 48%,rgba(222,247,252,.66) 100%)}
.brand-symbol{background:linear-gradient(145deg,#08a865,#11c796);box-shadow:0 9rpx 22rpx rgba(7,167,111,.2)}
.secure-pill{background:#e6f8ef;color:#07885a}
.eyebrow,.form-kicker{color:#087db6}
.form-step{background:#fff5ce;color:#936b0b}
.code-button{background:#e8faf2;color:#07885a}
.agreement-link{color:#078d5d}
.agreement-check.checked{border-color:#08a86b;background:linear-gradient(145deg,#08a865,#14c78e)}
.login-button,.wechat-button,.wechat-phone-button{background:linear-gradient(110deg,#08a965,#0ac182);box-shadow:0 13rpx 28rpx rgba(7,166,101,.2)}
.login-button[disabled],.wechat-button[disabled],.wechat-phone-button[disabled]{background:#b9e5d4;opacity:.76}
.verified-badge{background:#0bb476}
.verified-tip{border-color:#c2ecd7;background:#effbf5}
.register-note{color:#81909c}
</style>
