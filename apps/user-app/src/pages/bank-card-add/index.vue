<template>
  <view class="page">
    <view class="head">
      <view class="back" @click="goBack"><text class="back-ic">‹</text></view>
      <text class="head-title">添加银行卡</text>
      <view class="back" />
    </view>

    <view class="notice">
      <text class="notice-ic">🛡</text>
      <text class="notice-text">请绑定本人储蓄卡，信息需与实名认证保持一致</text>
    </view>

    <view class="form-card">
      <view class="frow">
        <text class="flabel">持卡人</text>
        <input v-model="form.cardholder_name" class="finput" placeholder="请输入本人姓名" maxlength="20">
      </view>
      <view class="frow">
        <text class="flabel">卡号</text>
        <input v-model="form.card_number" class="finput" type="number" placeholder="请输入储蓄卡卡号" maxlength="19">
      </view>
      <view class="frow" @click="bankPickerVisible = true">
        <text class="flabel">银行</text>
        <text class="finput" :class="{ placeholder: !form.bank_name }">{{ form.bank_name || '请选择开户银行' }}</text>
        <text class="farrow">›</text>
      </view>
      <view class="frow">
        <text class="flabel">手机号</text>
        <input v-model="form.phone" class="finput" type="number" placeholder="银行预留手机号" maxlength="11">
      </view>
      <view class="frow">
        <text class="flabel">验证码</text>
        <input v-model="smsCode" class="finput" type="number" placeholder="6 位验证码" maxlength="6">
        <text class="code-btn" :class="{ disabled: codeCountdown > 0 }" @click="sendCode">{{ codeCountdown > 0 ? `${codeCountdown}s 后重发` : '获取验证码' }}</text>
      </view>
    </view>

    <view class="agree-card" @click="agreed = !agreed">
      <view class="agree-check" :class="{ on: agreed }"><text v-if="agreed" class="agree-tick">✓</text></view>
      <text class="agree-text">我已阅读并同意<text class="link">《支付服务协议》</text>和<text class="link">《银行卡绑定须知》</text></text>
    </view>

    <view class="action-bar">
      <button class="ab-main" :disabled="!canSubmit || submitting" :loading="submitting" @click="submit">同意协议并绑卡</button>
    </view>

    <view v-if="bankPickerVisible" class="picker-mask" @click="bankPickerVisible = false">
      <view class="picker-panel" @click.stop>
        <view class="picker-head">
          <text class="picker-title">选择开户银行</text>
          <text class="picker-close" @click="bankPickerVisible = false">✕</text>
        </view>
        <scroll-view scroll-y class="picker-list">
          <view v-for="b in banks" :key="b" class="picker-item" :class="{ on: form.bank_name === b }" @click="pickBank(b)">{{ b }}</view>
        </scroll-view>
      </view>
    </view>
  </view>
</template>

<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { createBankCard } from '@/api/bank-cards'

const auth = useAuthStore()
const form = reactive({ cardholder_name: '', card_number: '', bank_name: '', phone: '' })
const smsCode = ref('')
const agreed = ref(false)
const submitting = ref(false)
const bankPickerVisible = ref(false)
const codeCountdown = ref(0)

const banks = [
  '中国银行', '工商银行', '农业银行', '建设银行', '交通银行',
  '招商银行', '邮储银行', '浦发银行', '民生银行', '中信银行',
  '光大银行', '华夏银行', '广发银行', '平安银行', '兴业银行',
]

const canSubmit = computed(() =>
  agreed.value
  && form.cardholder_name.trim().length > 0
  && /^\d{12,19}$/.test(form.card_number)
  && form.bank_name.length > 0
  && /^1\d{10}$/.test(form.phone),
)

function pickBank(b: string) {
  form.bank_name = b
  bankPickerVisible.value = false
}

function sendCode() {
  if (codeCountdown.value > 0) return
  if (!/^1\d{10}$/.test(form.phone)) {
    uni.showToast({ title: '请先填写手机号', icon: 'none' })
    return
  }
  uni.showToast({ title: '验证码已发送', icon: 'none' })
  codeCountdown.value = 60
  const timer = setInterval(() => {
    codeCountdown.value -= 1
    if (codeCountdown.value <= 0) clearInterval(timer)
  }, 1000)
}

async function submit() {
  if (!canSubmit.value || submitting.value) return
  if (!auth.accessToken) { uni.navigateTo({ url: '/pages/login/index' }); return }
  submitting.value = true
  try {
    await createBankCard(auth.accessToken, {
      cardholder_name: form.cardholder_name.trim(),
      bank_name: form.bank_name,
      card_number: form.card_number,
      phone: form.phone,
    })
    uni.showToast({ title: '绑卡成功', icon: 'success' })
    setTimeout(() => uni.navigateBack({ delta: 1 }), 600)
  } catch (e: any) {
    uni.showToast({ title: e?.message || '绑卡失败', icon: 'none' })
  } finally {
    submitting.value = false
  }
}

function goBack() { uni.navigateBack({ delta: 1 }) }
</script>

<style scoped>
.page{min-height:100vh;background:#F4F6F4;padding-bottom:180rpx}
.head{display:flex;align-items:center;justify-content:space-between;padding:20rpx 24rpx;background:#fff}
.back{width:56rpx;height:56rpx;display:flex;align-items:center;justify-content:center}
.back-ic{font-size:48rpx;color:#191B1F;line-height:1}
.head-title{font-size:32rpx;font-weight:700;color:#191B1F}
.notice{margin:20rpx 24rpx 0;padding:20rpx 24rpx;border-radius:20rpx;background:#E7F9EF;display:flex;align-items:center;gap:14rpx}
.notice-ic{font-size:28rpx;color:#08A152}
.notice-text{font-size:23rpx;color:#08A152;line-height:1.5}
.form-card{margin:20rpx 24rpx 0;background:#fff;border-radius:24rpx;padding:0 28rpx}
.frow{display:flex;align-items:center;gap:20rpx;padding:30rpx 0;border-bottom:2rpx solid #F0F2F0}
.frow:last-child{border-bottom:0}
.flabel{width:120rpx;font-size:28rpx;color:#191B1F;font-weight:600;flex:none}
.finput{flex:1;font-size:28rpx;color:#191B1F}
.finput.placeholder{color:#AEB3BB}
.farrow{font-size:36rpx;color:#C9CEC9;line-height:1}
.code-btn{flex:none;font-size:24rpx;color:#08A152;font-weight:600;padding:10rpx 20rpx;border-radius:28rpx;background:#E7F9EF}
.code-btn.disabled{color:#AEB3BB;background:#F0F2F0}
.agree-card{margin:20rpx 24rpx 0;padding:20rpx 24rpx;background:#fff;border-radius:20rpx;display:flex;gap:14rpx;align-items:flex-start}
.agree-check{width:30rpx;height:30rpx;border-radius:50%;border:2rpx solid #C9CEC9;flex:none;margin-top:2rpx;display:flex;align-items:center;justify-content:center}
.agree-check.on{background:#0BBF63;border-color:#0BBF63}
.agree-tick{font-size:20rpx;color:#fff;line-height:1}
.agree-text{font-size:22rpx;color:#AEB3BB;line-height:1.6}
.link{color:#08A152}
.action-bar{position:fixed;left:0;right:0;bottom:0;padding:20rpx 24rpx calc(20rpx + env(safe-area-inset-bottom));background:#fff;box-shadow:0 -4rpx 20rpx rgba(23,26,23,.05)}
.ab-main{width:100%;height:88rpx;line-height:88rpx;border-radius:44rpx;background:linear-gradient(135deg,#12D978,#08A152);color:#fff;font-size:30rpx;font-weight:700;border:0}
.ab-main[disabled]{opacity:.5}
.picker-mask{position:fixed;inset:0;background:rgba(0,0,0,.45);display:flex;align-items:flex-end;z-index:99}
.picker-panel{width:100%;background:#fff;border-radius:32rpx 32rpx 0 0;padding-bottom:env(safe-area-inset-bottom)}
.picker-head{display:flex;align-items:center;justify-content:space-between;padding:28rpx 32rpx;border-bottom:2rpx solid #F0F2F0}
.picker-title{font-size:30rpx;font-weight:700;color:#191B1F}
.picker-close{font-size:32rpx;color:#AEB3BB}
.picker-list{max-height:60vh}
.picker-item{padding:28rpx 32rpx;font-size:28rpx;color:#191B1F;border-bottom:2rpx solid #F7F8F7}
.picker-item.on{color:#08A152;font-weight:700}
</style>
