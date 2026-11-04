<template>
  <view class="page">
    <view class="head">
      <view class="back" @click="goBack"><text class="back-ic">‹</text></view>
      <text class="head-title">银行卡管理</text>
      <view class="back" />
    </view>

    <view v-if="loading" class="card">
      <view class="skel-card tm-skeleton" />
      <view class="skel-card tm-skeleton" />
    </view>

    <template v-else>
      <view v-if="cards.length" class="card">
        <view v-for="c in cards" :key="c.id" class="bank-card" :class="{ default: c.is_default }">
          <view class="bank-card-bg" />
          <view class="bank-row">
            <view class="bank-logo"><text>{{ c.bank_name.slice(0, 1) }}</text></view>
            <view class="bank-main">
              <text class="bank-name">{{ c.bank_name }}</text>
              <text class="bank-type">储蓄卡</text>
            </view>
            <text v-if="c.is_default" class="bank-default">默认</text>
          </view>
          <text class="bank-num">{{ c.card_masked }}</text>
          <view class="bank-foot">
            <text class="bank-holder">{{ c.cardholder_name }}</text>
            <view class="bank-ops">
              <text v-if="!c.is_default" class="bank-op" @click="setDefault(c.id)">设为默认</text>
              <text class="bank-op danger" @click="removeCard(c.id)">解绑</text>
            </view>
          </view>
        </view>
      </view>

      <view v-else class="empty">
        <view class="empty-ic">💳</view>
        <text class="empty-title">暂无绑定银行卡</text>
        <text class="empty-copy">绑定银行卡用于后续资金服务</text>
      </view>

      <view class="add-btn-wrap">
        <button class="add-btn" @click="goAdd">+ 添加银行卡</button>
      </view>
    </template>
  </view>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { onShow } from '@dcloudio/uni-app'
import { useAuthStore } from '@/stores/auth'
import { listBankCards, setDefaultBankCard, deleteBankCard, type BankCard } from '@/api/bank-cards'

const auth = useAuthStore()
const cards = ref<BankCard[]>([])
const loading = ref(false)

onShow(() => { load() })

async function load() {
  if (!auth.accessToken) { uni.navigateTo({ url: '/pages/login/index' }); return }
  loading.value = true
  try {
    const res = await listBankCards(auth.accessToken)
    cards.value = res.items
  } catch {
    uni.showToast({ title: '加载失败', icon: 'none' })
  } finally {
    loading.value = false
  }
}

async function setDefault(id: string) {
  try {
    await setDefaultBankCard(auth.accessToken, id)
    uni.showToast({ title: '已设为默认', icon: 'success' })
    load()
  } catch {
    uni.showToast({ title: '操作失败', icon: 'none' })
  }
}

function removeCard(id: string) {
  uni.showModal({
    title: '解绑银行卡',
    content: '解绑后该卡将不可用于提现，确定解绑吗？',
    success: async (r) => {
      if (!r.confirm) return
      try {
        await deleteBankCard(auth.accessToken, id)
        uni.showToast({ title: '已解绑', icon: 'success' })
        load()
      } catch {
        uni.showToast({ title: '操作失败', icon: 'none' })
      }
    },
  })
}

function goAdd() { uni.navigateTo({ url: '/pages/bank-card-add/index' }) }
function goBack() { uni.navigateBack({ delta: 1 }) }
</script>

<style scoped>
.page{min-height:100vh;background:#F4F6F4;padding-bottom:40rpx}
.head{display:flex;align-items:center;justify-content:space-between;padding:20rpx 24rpx;background:#fff}
.back{width:56rpx;height:56rpx;display:flex;align-items:center;justify-content:center}
.back-ic{font-size:48rpx;color:#191B1F;line-height:1}
.head-title{font-size:32rpx;font-weight:700;color:#191B1F}
.card{margin:20rpx 24rpx 0}
.bank-card{position:relative;border-radius:24rpx;padding:32rpx;color:#fff;margin-bottom:24rpx;overflow:hidden;background:linear-gradient(135deg,#12D978,#08A152)}
.bank-card.default{background:linear-gradient(135deg,#12D978,#08A152)}
.bank-card:not(.default){background:linear-gradient(135deg,#FF9A3D,#F2620A)}
.bank-card-bg{position:absolute;width:280rpx;height:280rpx;border-radius:50%;background:rgba(255,255,255,.08);top:-120rpx;right:-60rpx}
.bank-row{display:flex;align-items:center;gap:16rpx;position:relative}
.bank-logo{width:64rpx;height:64rpx;border-radius:16rpx;background:rgba(255,255,255,.2);display:flex;align-items:center;justify-content:center;font-size:28rpx;font-weight:800}
.bank-main{flex:1}
.bank-name{font-size:28rpx;font-weight:700;display:block}
.bank-type{font-size:20rpx;opacity:.8;margin-top:4rpx;display:block}
.bank-default{font-size:20rpx;background:rgba(255,255,255,.25);border-radius:8rpx;padding:4rpx 12rpx}
.bank-num{font-size:36rpx;font-weight:700;letter-spacing:4rpx;margin-top:32rpx;display:block;position:relative}
.bank-foot{display:flex;justify-content:space-between;align-items:center;margin-top:24rpx;position:relative}
.bank-holder{font-size:22rpx;opacity:.85}
.bank-ops{display:flex;gap:20rpx}
.bank-op{font-size:22rpx;opacity:.9}
.bank-op.danger{opacity:.75}
.empty{display:flex;flex-direction:column;align-items:center;padding:120rpx 48rpx;gap:16rpx}
.empty-ic{font-size:80rpx}
.empty-title{font-size:30rpx;font-weight:700;color:#191B1F}
.empty-copy{font-size:24rpx;color:#AEB3BB}
.add-btn-wrap{padding:40rpx 24rpx}
.add-btn{width:100%;height:88rpx;line-height:88rpx;border-radius:44rpx;background:linear-gradient(135deg,#12D978,#08A152);color:#fff;font-size:30rpx;font-weight:700;border:0}
.skel-card{height:220rpx;border-radius:24rpx;margin-bottom:24rpx}
.tm-skeleton{background:linear-gradient(90deg,#F0F2F0 25%,#E8EBE8 50%,#F0F2F0 75%);background-size:400% 100%;animation:sk 1.2s ease infinite}
@keyframes sk{0%{background-position:100% 0}100%{background-position:-100% 0}}
</style>
