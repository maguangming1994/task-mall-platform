<template>
  <view class="page">
    <!-- 顶部横幅 -->
    <view class="hero">
      <view class="hero-icon">证</view>
      <view class="hero-main">
        <text class="hero-title">实名认证</text>
        <text class="hero-sub">实名后解锁发布与交易，校园商城购物更放心</text>
      </view>
    </view>

    <view v-if="loading" class="state-panel">正在读取认证状态…</view>

    <!-- 服务未开启兜底 -->
    <view v-else-if="!state?.enabled" class="state-panel warning">
      <text class="state-title">认证服务尚未开放</text>
      <text class="state-copy">管理员完成实名认证服务配置并启用后，即可在这里提交认证。</text>
    </view>

    <!-- 已通过 -->
    <view v-else-if="state.status === 'verified'" class="state-panel verified">
      <text class="verified-mark">✓</text>
      <text class="state-title">实名认证已通过</text>
      <view class="result-row"><text>姓名</text><strong>{{ state.real_name_masked }}</strong></view>
      <view class="result-row"><text>身份证号</text><strong>{{ state.identity_number_masked }}</strong></view>
      <view class="result-row"><text>认证时间</text><strong>{{ formatDate(state.verified_at) }}</strong></view>
      <text class="state-note">认证身份不能自行更换。如需更正，请联系平台客服处理。</text>
    </view>

    <!-- 审核中 -->
    <view v-else-if="state.status === 'pending'" class="state-panel pending-box">
      <text class="pending-spinner">⏳</text>
      <text class="state-title">资料审核中</text>
      <text class="state-copy">你的实名资料已提交，管理员一般会在 1-3 个工作日内完成人工审核，结果会通过站内通知发送，请勿重复提交。</text>
    </view>

    <!-- 表单：未提交 / 未通过 / 服务商异常 -->
    <view v-else class="form-panel">
      <view v-if="state.status === 'failed'" class="notice danger">上次审核未通过，请核对信息后重新提交。</view>
      <view v-else-if="state.status === 'provider_error'" class="notice danger">上次认证服务异常，本次可以重新提交。</view>

      <view class="field">
        <view class="field-head">
          <text class="field-label">真实姓名</text>
          <text class="field-hint">与身份证保持一致</text>
        </view>
        <input v-model="form.realName" class="input" maxlength="64" placeholder="请输入真实姓名" />
      </view>

      <view class="field">
        <view class="field-head">
          <text class="field-label">身份证号</text>
          <text class="field-hint">18 位身份证号码</text>
        </view>
        <input v-model="form.identityNumber" class="input" maxlength="18" placeholder="请输入 18 位二代居民身份证号" />
      </view>

      <view class="field">
        <view class="field-head">
          <text class="field-label">手机号</text>
          <text class="field-hint">默认使用本人绑定手机号</text>
        </view>
        <view class="code-row">
          <input v-model="form.phone" class="input input-flex" type="number" maxlength="11" :placeholder="auth.user?.phone_masked ? `本人手机号 ${auth.user.phone_masked}` : '请输入本人实名手机号'" />
          <button class="code-btn" :disabled="countdown > 0" @click="sendCode">{{ countdown > 0 ? `${countdown}s 后重发` : '获取验证码' }}</button>
        </view>
      </view>

      <view class="field">
        <view class="field-head">
          <text class="field-label">验证码</text>
          <text class="field-hint">6 位短信验证码</text>
        </view>
        <input v-model="form.code" class="input" type="number" maxlength="6" placeholder="请输入收到的 6 位验证码" />
      </view>

      <view class="agreement">
        <view class="checkbox" :class="{ checked: consented }" @click="consented = !consented">{{ consented ? '✓' : '' }}</view>
        <text class="agreement-text" @click="consented = !consented">我已阅读并同意</text>
        <text class="link" @click.stop="openLegal('identity_service')">《实名认证服务协议》</text>
        <text class="agreement-text" @click="consented = !consented">与</text>
        <text class="link" @click.stop="openLegal('privacy_policy')">《隐私政策》</text>
      </view>

      <button class="submit" :disabled="!canSubmit || submitting" @click="submit">提交认证</button>
      <text class="secure-tip">🔒 信息经加密传输，平台仅保存脱敏信息与核验结果</text>
    </view>
  </view>
</template>

<script setup lang="ts">
import { onShow } from '@dcloudio/uni-app'
import { computed, reactive, ref } from 'vue'
import { loadIdentityVerification, submitIdentityVerification, type IdentityVerificationStatus } from '@/api/identity'
import { useAuthStore } from '@/stores/auth'
import { navigateToLogin } from '@/utils/authRedirect'
import { formatDateTime } from '../admin/format'

const auth = useAuthStore()
const state = ref<IdentityVerificationStatus | null>(null)
const loading = ref(true)
const submitting = ref(false)
const countdown = ref(0)
let timer: ReturnType<typeof setInterval> | null = null

const form = reactive({ realName: '', identityNumber: '', phone: '', code: '' })
const consented = ref(false)

const idPattern = /^[0-9]{17}[0-9Xx]$/
const phonePattern = /^1\d{10}$/

const canSubmit = computed(() => {
  const nameOk = form.realName.trim().length >= 2
  const idOk = idPattern.test(form.identityNumber.trim())
  // 手机号留空视为使用账号绑定手机号；填写时必须为 11 位有效号码
  const phoneOk = form.phone.trim() === '' || phonePattern.test(form.phone.trim())
  const codeOk = /^\d{6}$/.test(form.code.trim())
  return nameOk && idOk && phoneOk && codeOk && consented.value && !submitting.value
})

onShow(load)

function formatDate(value?: string | null) {
  return value ? formatDateTime(value) : '—'
}

async function load() {
  if (!auth.isAuthenticated) return navigateToLogin()
  loading.value = true
  try {
    state.value = await loadIdentityVerification(auth.accessToken)
  } catch (error) {
    uni.showToast({ title: error instanceof Error ? error.message : '认证状态加载失败', icon: 'none' })
  } finally {
    loading.value = false
  }
}

/* 验证码为前端演示流程：倒计时 + 本地校验，不额外请求后端 */
function sendCode() {
  if (countdown.value > 0) return
  if (form.phone.trim() && !phonePattern.test(form.phone.trim())) {
    uni.showToast({ title: '请填写正确的手机号', icon: 'none' })
    return
  }
  uni.showToast({ title: '验证码已发送，请注意查收', icon: 'none' })
  countdown.value = 60
  if (timer) clearInterval(timer)
  timer = setInterval(() => {
    countdown.value -= 1
    if (countdown.value <= 0 && timer) {
      clearInterval(timer)
      timer = null
    }
  }, 1000)
}

function openLegal(key: string) {
  uni.navigateTo({ url: `/pages/legal/index?key=${key}` })
}

async function submit() {
  if (!canSubmit.value || !auth.accessToken) return
  submitting.value = true
  try {
    state.value = await submitIdentityVerification(auth.accessToken, form.realName.trim(), form.identityNumber.trim().toUpperCase())
    form.realName = ''; form.identityNumber = ''; form.phone = ''; form.code = ''
    consented.value = false
    const manual = state.value.driver === 'manual'
    uni.showToast({ title: state.value.status === 'verified' ? '实名认证已通过' : '资料已提交，等待审核', icon: manual ? 'none' : 'success' })
    await auth.fetchCurrentUser()
  } catch (error) {
    uni.showToast({ title: error instanceof Error ? error.message : '实名认证失败', icon: 'none' })
  } finally {
    submitting.value = false
  }
}
</script>

<style scoped>
.page { min-height: 100vh; padding: 28rpx 28rpx 80rpx; box-sizing: border-box; background: #F4F6F4; }

.hero { display: flex; align-items: center; gap: 22rpx; padding: 36rpx 32rpx; border-radius: 28rpx; background: linear-gradient(145deg, #12D978, #08A152); box-shadow: 0 14rpx 34rpx rgba(11,191,99,.26); color: #fff; }
.hero-icon { display: flex; align-items: center; justify-content: center; width: 82rpx; height: 82rpx; flex: none; border: 2rpx solid rgba(255,255,255,.5); border-radius: 20rpx; background: rgba(255,255,255,.17); font-size: 38rpx; font-weight: 900; }
.hero-main { display: flex; flex-direction: column; }
.hero-title { font-size: 38rpx; font-weight: 900; }
.hero-sub { margin-top: 8rpx; color: rgba(255,255,255,.88); font-size: 24rpx; line-height: 1.5; }

.form-panel, .state-panel { margin-top: 24rpx; padding: 32rpx 28rpx; border: 1rpx solid #EDEFEF; border-radius: 28rpx; background: #fff; box-shadow: 0 12rpx 40rpx rgba(23,26,23,.05); }
.state-panel { text-align: center; color: #767C85; }
.state-panel.warning { background: #FFF2E5; color: #B45309; }
.state-panel.verified { background: #F0FBF5; }
.state-title { display: block; margin-bottom: 14rpx; color: #191B1F; font-size: 30rpx; font-weight: 900; }
.state-copy { display: block; font-size: 24rpx; line-height: 1.7; }
.verified-mark { display: flex; align-items: center; justify-content: center; width: 100rpx; height: 100rpx; margin: 0 auto 18rpx; border-radius: 50%; background: #08A152; color: #fff; font-size: 52rpx; }
.pending-spinner { font-size: 60rpx; }
.pending-box .state-copy { padding: 0 12rpx; }
.result-row { display: flex; justify-content: space-between; padding: 20rpx 0; border-bottom: 1rpx solid #EAF2EC; text-align: left; font-size: 26rpx; color: #767C85; }
.result-row strong { color: #191B1F; font-weight: 700; }
.state-note { display: block; margin-top: 22rpx; font-size: 22rpx; line-height: 1.6; }

.notice { margin-bottom: 20rpx; padding: 18rpx 20rpx; border-radius: 14rpx; background: #E7F9EF; color: #08A152; font-size: 24rpx; line-height: 1.5; }
.notice.danger { background: #FFEEEA; color: #D0322B; }

.field { margin-bottom: 26rpx; }
.field-head { display: flex; align-items: baseline; justify-content: space-between; margin-bottom: 12rpx; }
.field-label { color: #191B1F; font-size: 26rpx; font-weight: 800; }
.field-hint { color: #AEB3BB; font-size: 20rpx; }
.input { height: 88rpx; padding: 0 24rpx; border: 1rpx solid #EDEFEF; border-radius: 16rpx; background: #FAFBFA; color: #191B1F; font-size: 28rpx; box-sizing: border-box; }
.code-row { display: flex; gap: 16rpx; align-items: center; }
.input-flex { flex: 1; }
.code-btn { flex: none; height: 88rpx; margin: 0; padding: 0 28rpx; border: 0; border-radius: 16rpx; background: #E7F9EF; color: #08A152; font-size: 24rpx; font-weight: 700; line-height: 88rpx; }
.code-btn[disabled] { background: #F0F2F0; color: #AEB3BB; }
.code-btn::after { border: 0; }

.agreement { display: flex; flex-wrap: wrap; align-items: center; margin: 30rpx 0 10rpx; }
.checkbox { display: flex; align-items: center; justify-content: center; width: 34rpx; height: 34rpx; margin-right: 10rpx; border: 2rpx solid #C6CBC6; border-radius: 50%; color: #fff; font-size: 20rpx; }
.checkbox.checked { background: #0BBF63; border-color: #0BBF63; }
.agreement-text { color: #767C85; font-size: 22rpx; line-height: 1.6; }
.link { color: #08A152; font-size: 22rpx; line-height: 1.6; }

.submit { height: 96rpx; margin-top: 24rpx; border: 0; border-radius: 999rpx; background: linear-gradient(135deg, #12D978, #08A152); color: #fff; font-size: 32rpx; font-weight: 850; line-height: 96rpx; box-shadow: 0 12rpx 28rpx rgba(11,191,99,.22); }
.submit[disabled] { background: #DDE3DE; color: #fff; box-shadow: none; }
.submit::after { border: 0; }
.secure-tip { display: block; margin-top: 20rpx; text-align: center; color: #AEB3BB; font-size: 20rpx; }
</style>
