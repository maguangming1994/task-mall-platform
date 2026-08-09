<template>
  <view class="page"><text class="title">我的推荐</text><view class="card"><text class="label">我的推荐码</text><text class="code">{{ referralCode || '加载中' }}</text><text class="hint">新人需同时完成第一次有效任务和第一笔现金支付大于 0 的已完成订单，奖励才到账。</text></view><view class="card"><text class="label">绑定推荐人</text><input v-model="bindCode" class="input" placeholder="输入推荐码" /><button class="primary" @click="bind">确认绑定</button></view><view v-for="item in items" :key="item.id" class="card"><text>{{ item.referred_user.nickname }}</text><text class="hint">首个任务 {{ item.first_task_completed ? '已完成' : '未完成' }} · 首笔现金订单 {{ item.first_cash_order_completed ? '已完成' : '未完成' }}</text><text class="status">{{ item.status }}</text></view></view>
</template>
<script setup lang="ts">
import { onShow } from '@dcloudio/uni-app'
import { ref } from 'vue'
import { bindReferral, getReferrals, type ReferralProgress } from '@/api/referrals'
import { useAuthStore } from '@/stores/auth'
const auth = useAuthStore(); const referralCode = ref(''); const bindCode = ref(''); const items = ref<ReferralProgress[]>([])
onShow(() => { void load() })
async function load() { if (!auth.accessToken) return uni.navigateTo({ url: '/pages/login/index' }); const data = await getReferrals(auth.accessToken); referralCode.value = data.referral_code; items.value = data.items }
async function bind() { if (!auth.accessToken || !bindCode.value.trim()) return; try { await bindReferral(auth.accessToken, bindCode.value.trim()); uni.showToast({ title: '绑定成功', icon: 'success' }) } catch (error) { uni.showToast({ title: error instanceof Error ? error.message : '绑定失败', icon: 'none' }) } }
</script>
<style scoped>.page{min-height:100vh;padding:32rpx;background:#f5f7fb}.title{font-size:44rpx;font-weight:750}.card{margin-top:22rpx;padding:28rpx;border-radius:22rpx;background:#fff}.label,.hint,.status{display:block}.label{font-weight:700}.code{display:block;margin:16rpx 0;color:#3157d5;font-size:40rpx;font-weight:800;letter-spacing:3rpx}.hint{margin-top:12rpx;color:#667085;line-height:1.6}.input{height:80rpx;margin-top:16rpx;padding:0 20rpx;border:1rpx solid #d0d5dd;border-radius:14rpx}.primary{margin-top:16rpx;background:#3157d5;color:#fff}.status{margin-top:12rpx;color:#3157d5}</style>
