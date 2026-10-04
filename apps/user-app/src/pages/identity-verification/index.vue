<template>
  <view class="page">
    <view class="status-band">
      <view class="status-mark">证</view>
      <view>
        <text class="eyebrow">IDENTITY VERIFICATION</text>
        <text class="title">实名认证</text>
        <text class="subtitle">由阿里云权威数据源核验姓名与身份证号是否一致</text>
      </view>
    </view>

    <view v-if="loading" class="state-panel">正在读取认证状态...</view>
    <view v-else-if="!state?.enabled" class="state-panel warning">
      <text class="state-title">认证服务尚未开放</text>
      <text>管理员完成阿里云配置并启用后，即可在这里认证。</text>
    </view>
    <view v-else-if="state.status === 'verified'" class="state-panel verified">
      <text class="state-title">实名认证已通过</text>
      <view class="result-row"><text>姓名</text><strong>{{ state.real_name_masked }}</strong></view>
      <view class="result-row"><text>身份证号</text><strong>{{ state.identity_number_masked }}</strong></view>
      <text class="state-note">认证身份不能在此页面直接更换。如需更正，请联系平台客服。</text>
    </view>
    <view v-else class="form-panel">
      <view v-if="state.status === 'pending'" class="notice">资料正在人工审核，请勿重复提交。</view>
      <view v-if="state.status === 'failed'" class="notice danger">上次核验未通过，请检查姓名与身份证号后重试。</view>
      <view v-if="state.status === 'provider_error'" class="notice danger">上次服务调用异常，本次可以重新提交。</view>

      <text class="field-label">真实姓名</text>
      <input v-model="realName" class="input" maxlength="64" placeholder="请输入身份证上的姓名" />
      <text class="field-label">身份证号</text>
      <input v-model="identityNumber" class="input" maxlength="18" placeholder="请输入 18 位二代居民身份证号" />

      <view class="privacy-note">
        <text class="privacy-title">个人信息处理说明</text>
        <text>姓名和身份证号将通过加密连接发送至阿里云，仅用于本次身份二要素核验。平台不会保存原始姓名和完整身份证号，只保存掩码、不可逆摘要和核验结果。</text>
      </view>
      <label class="consent-row">
        <checkbox :checked="consented" color="#0BBF63" @click="consented = !consented" />
        <text>我已阅读并同意上述个人信息处理说明，并授权进行本次实名认证</text>
      </label>
      <button class="submit" :disabled="!canSubmit || state.status === 'pending'" :loading="submitting" @click="submit">验证身份</button>
    </view>
  </view>
</template>

<script setup lang="ts">
import { onShow } from '@dcloudio/uni-app'
import { computed, ref } from 'vue'
import { loadIdentityVerification, submitIdentityVerification, type IdentityVerificationStatus } from '@/api/identity'
import { useAuthStore } from '@/stores/auth'
import { navigateToLogin } from '@/utils/authRedirect'

const auth = useAuthStore()
const state = ref<IdentityVerificationStatus | null>(null)
const loading = ref(true)
const submitting = ref(false)
const realName = ref('')
const identityNumber = ref('')
const consented = ref(false)
const canSubmit = computed(() => realName.value.trim().length >= 2 && /^[0-9]{17}[0-9Xx]$/.test(identityNumber.value.trim()) && consented.value && !submitting.value)

onShow(load)

async function load() {
  if (!auth.isAuthenticated) return navigateToLogin()
  loading.value = true
  try {
    state.value = await loadIdentityVerification(auth.accessToken)
  } catch (error) {
    uni.showToast({ title: error instanceof Error ? error.message : '认证状态加载失败', icon: 'none' })
  } finally { loading.value = false }
}

async function submit() {
  if (!canSubmit.value || !auth.accessToken) return
  submitting.value = true
  try {
    state.value = await submitIdentityVerification(auth.accessToken, realName.value.trim(), identityNumber.value.trim().toUpperCase())
    realName.value = ''; identityNumber.value = ''; consented.value = false
    uni.showToast({ title: state.value.status === 'verified' ? '实名认证已通过' : state.value.status === 'pending' ? '资料已提交' : '核验未通过', icon: state.value.status === 'verified' ? 'success' : 'none' })
    await auth.fetchCurrentUser()
  } catch (error) {
    uni.showToast({ title: error instanceof Error ? error.message : '实名认证失败', icon: 'none' })
  } finally { submitting.value = false }
}
</script>

<style scoped>
.page{min-height:100vh;padding:28rpx;box-sizing:border-box;background:#F4F6F4;color:#191B1F}.status-band{display:flex;align-items:center;gap:22rpx;padding:36rpx 32rpx;border-radius:28rpx;background:linear-gradient(145deg,#12D978,#08A152);box-shadow:0 14rpx 34rpx rgba(11,191,99,.26);color:#fff}.status-mark{display:flex;width:82rpx;height:82rpx;align-items:center;justify-content:center;flex:none;border:2rpx solid rgba(255,255,255,.5);border-radius:20rpx;background:rgba(255,255,255,.17);font-size:21px;font-weight:900}.eyebrow,.title,.subtitle,.field-label,.privacy-title,.state-title,.state-note{display:block}.eyebrow{font-size:12px;font-weight:800}.title{margin-top:6rpx;font-size:22px;font-weight:900}.subtitle{margin-top:6rpx;color:rgba(255,255,255,.85);font-size:13px;line-height:1.5}.form-panel,.state-panel{margin-top:24rpx;padding:28rpx;border:1rpx solid #EDEFEF;border-radius:28rpx;background:#fff;box-shadow:0 12rpx 40rpx rgba(23,26,23,.05)}.state-panel{text-align:center;color:#767C85;font-size:14px;line-height:1.7}.state-panel.warning{background:#FFF2E5;color:#B45309}.state-panel.verified{background:#F0FBF5;color:#08A152}.state-title{margin-bottom:14rpx;color:#191B1F;font-size:17px;font-weight:900}.result-row{display:flex;justify-content:space-between;padding:18rpx 0;border-bottom:1rpx solid #F2F4F3;text-align:left}.result-row strong{color:#191B1F}.state-note{margin-top:18rpx;font-size:13px}.notice{margin-bottom:20rpx;padding:16rpx;border-radius:14rpx;background:#E7F9EF;color:#08A152;font-size:13px;line-height:1.5}.notice.danger{background:#FFEEEA;color:#D0322B}.field-label{margin:22rpx 0 10rpx;color:#191B1F;font-size:14px;font-weight:850}.input{height:88rpx;padding:0 24rpx;border:1rpx solid #EDEFEF;border-radius:16rpx;background:#fff;color:#191B1F;font-size:14px;box-sizing:border-box}.privacy-note{margin-top:26rpx;padding:20rpx;border:1rpx solid #9FD8B6;border-radius:16rpx;background:#F4FBF7;color:#767C85;font-size:13px;line-height:1.7}.privacy-title{margin-bottom:5rpx;color:#191B1F;font-size:14px;font-weight:850}.consent-row{display:flex;align-items:flex-start;gap:8rpx;margin-top:20rpx;color:#767C85;font-size:13px;line-height:1.55}.consent-row checkbox{transform:scale(.82);transform-origin:left top}.submit{height:96rpx;margin-top:24rpx;border:0;border-radius:999rpx;background:linear-gradient(135deg,#12D978,#08A152);color:#fff;font-size:16px;font-weight:850;line-height:96rpx;box-shadow:0 12rpx 28rpx rgba(11,191,99,.22)}.submit[disabled]{background:#DDE3DE;color:#fff;box-shadow:none}.submit::after{border:0}
</style>
