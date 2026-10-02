<template>
  <view class="page tm-page-shell">
    <ModulePlaceholder v-if="modules.resolved && !moduleEnabled" code="module_wallet_recharge" />
    <view v-else-if="!modules.resolved" class="mp-skeleton">
      <view class="tm-skeleton" style="height: 200rpx; border-radius: 24rpx; margin: 24rpx 28rpx;" />
      <view class="tm-skeleton" style="height: 320rpx; border-radius: 24rpx; margin: 20rpx 28rpx;" />
    </view>
    <template v-else>
    <view class="balance-card">
      <view class="balance-ic">¥</view>
      <view class="balance-main">
        <text class="balance-label">当前余额</text>
        <text class="balance-tip">{{ wallet ? '余额无有效期，可用于商城现金部分的全额支付' : '余额信息暂不可用，请重试加载' }}</text>
      </view>
      <text class="balance">{{ wallet ? `¥${money(wallet.available_amount)}` : '—' }}</text>
      <button v-if="returnToCheckout" class="return-checkout" @click="backToCheckout">返回结算</button>
    </view>

    <view v-if="loading && !wallet" class="state-card loading-state" role="status" aria-live="polite"><text class="state-title">正在加载充值信息…</text><text class="state-copy">余额和充值记录马上就会显示。</text></view>
    <view v-if="loadError" class="state-card error-state"><text class="state-title">充值信息加载失败</text><text class="state-copy">{{ loadError }}</text><button class="state-action" @click="load">重新加载</button></view>

    <view v-if="onlineRecharge" class="online-result tm-panel" :class="`result-${onlineRecharge.status}`">
      <view class="result-head"><view><text class="form-title">线上充值结果</text><text class="form-desc">充值单号 {{ onlineRecharge.recharge_no }}</text></view><text class="result-status">{{ statusText(onlineRecharge.status) }}</text></view>
      <text class="result-amount">¥{{ money(onlineRecharge.amount_cents) }}</text>
      <text class="result-copy">{{ onlineResultText }}</text>
      <view class="result-actions"><button v-if="isOnlinePending" class="tm-primary-button" :loading="onlinePaying" @click="continueOnlinePayment">继续支付</button><button class="tm-secondary-button" :loading="onlinePolling" @click="pollOnlineRecharge()">{{ onlinePolling ? '查询中…' : '刷新结果' }}</button><button class="tm-secondary-button" @click="clearOnlineResult">返回充值</button></view>
    </view>

    <view class="method-tabs">
      <view v-for="item in methods" :key="item.code" class="method-tab" :class="{ active: method === item.code }" @click="method = item.code"><text class="method-icon">{{ item.icon }}</text><text>{{ item.label }}</text></view>
    </view>

    <view class="form-card tm-panel">
      <view v-if="actionError" class="action-error" role="alert"><text>{{ actionError }}</text><button aria-label="关闭充值错误提示" @click="actionError = ''">知道了</button></view>
      <template v-if="method === 'card_code'">
        <text class="form-title">卡密兑换</text><text class="form-desc">输入完整卡密，校验成功后余额立即到账；每张卡密只能使用一次。</text>
        <input v-model="cardCode" class="input" maxlength="80" placeholder="请输入充值卡密" />
        <button class="tm-primary-button submit" :loading="submitting" @click="submitCard">立即兑换</button>
      </template>

      <template v-else-if="method === 'online'">
        <text class="form-title">线上支付充值</text><text class="form-desc">选择金额后使用微信支付。支付回调成功才会增加余额，请勿重复付款。</text>
        <view class="frow-label">充值金额</view>
        <view class="chip-pick"><view v-for="item in presets" :key="item" class="chip" :class="{ on: amountYuan === String(item) }" @click="amountYuan = String(item)">¥{{ item }}</view></view>
        <view class="frow-label">自定义</view>
        <input v-model="amountYuan" class="input" type="digit" placeholder="输入金额，最低 1 元，最高 50000 元" />
        <view class="pay-row"><text class="pay-label">支付方式</text><text class="pay-value">微信支付</text><view class="pay-check">✓</view></view>
        <view class="tip-card">充值即时到账；余额可用于校园闲置担保交易，确认收货后结算给卖家；支持随时提现回微信零钱。</view>
        <button class="tm-primary-button submit" :loading="submitting || onlinePaying" :disabled="onlinePaying" @click="submitOnline">{{ onlineRecharge && isOnlinePending ? '继续微信支付' : `确认充值 ¥${amountYuan || '0'}` }}</button>
      </template>

      <template v-else>
        <text class="form-title">线下转账充值</text><text class="form-desc">完成线下转账后提交付款信息与凭证，财务核验通过后到账。</text>
        <input v-model="amountYuan" class="input" type="digit" placeholder="充值金额（元）" />
        <input v-model="payerName" class="input" placeholder="付款人姓名" maxlength="80" />
        <input v-model="offlineReference" class="input" placeholder="转账单号（选填）" maxlength="120" />
        <textarea v-model="note" class="textarea" placeholder="付款时间、收款账户或其他说明（选填）" maxlength="500" />
        <view class="proofs"><view v-for="proof in proofs" :key="proof.fileId" class="proof"><image :src="proof.previewUrl" mode="aspectFill" /><text @click="removeProof(proof.fileId)">删除</text></view><view v-if="proofs.length < 6" class="add-proof" @click="addProof"><text>＋</text><small>上传付款凭证</small></view></view>
        <view class="review-note">至少上传 1 张清晰付款凭证；审核期间不会提前增加余额。</view>
        <button class="tm-primary-button submit" :loading="submitting" @click="submitOffline">提交财务审核</button>
      </template>
    </view>

    <view class="record-card tm-panel">
      <view class="section-head"><text>充值记录</text><text>最近 50 笔</text></view>
      <view v-for="item in recharges" :key="item.id" class="record-row"><view><text class="record-title">{{ methodText(item.method) }} · ¥{{ money(item.amount_cents) }}</text><text class="record-time">{{ formatTime(item.created_at) }} · {{ item.recharge_no }}</text><text v-if="item.review_note" class="review-result">审核说明：{{ item.review_note }}</text></view><text class="status" :class="item.status">{{ statusText(item.status) }}</text></view>
      <text v-if="!loading && !loadError && !recharges.length" class="empty">暂无充值记录</text>
    </view>
    </template>
  </view>
</template>

<script setup lang="ts">
import { formatDateTime as formatTime } from '@/utils/dateTime'
import { onLoad, onShow } from '@dcloudio/uni-app'
import { computed, ref } from 'vue'
import { createRechargePayment, createWalletRecharge, getWalletAccount, getWalletRecharge, listWalletRecharges, type RechargeMethod, type WalletAccount, type WalletRecharge } from '@/api/assets'
import { chooseAndUploadPrivateImage } from '@/api/upload'
import { clientPlatform } from '@/platform/runtime'
import { useAuthStore } from '@/stores/auth'
import { navigateToLogin } from '@/utils/authRedirect'
import ModulePlaceholder from '@/components/ModulePlaceholder.vue'
import { useModulesStore } from '@/stores/modules'

const modules = useModulesStore()
const moduleEnabled = computed(() => modules.isEnabled('module_wallet_recharge'))

const auth = useAuthStore()
const methods = [{ code: 'card_code' as const, label: '卡密兑换', icon: '卡' }, { code: 'online' as const, label: '线上支付', icon: '微' }, { code: 'offline' as const, label: '线下支付', icon: '凭' }]
const method = ref<RechargeMethod>('card_code')
const wallet = ref<WalletAccount | null>(null)
const recharges = ref<WalletRecharge[]>([])
const cardCode = ref('')
const amountYuan = ref('100')
const payerName = ref('')
const offlineReference = ref('')
const note = ref('')
const proofs = ref<Array<{ fileId: string; previewUrl: string }>>([])
const submitting = ref(false)
const presets = [20, 50, 100, 200, 500, 1000]
const loading = ref(false)
const loadError = ref('')
const actionError = ref('')
const onlineRecharge = ref<WalletRecharge | null>(null)
const onlinePaying = ref(false)
const onlinePolling = ref(false)
const onlineIntentKey = ref('')
const onlineIntentAmount = ref(0)
const onlinePaymentKey = ref('')
const onlinePaymentId = ref('')
const returnToCheckout = ref(false)
const checkoutReturnUrl = '/pages/checkout/index'
const onlineIntentStorageKey = 'task_mall_wallet_recharge_intent'
const isOnlinePending = computed(() => onlineRecharge.value?.status === 'pending_payment')
const onlineResultText = computed(() => {
  if (!onlineRecharge.value) return ''
  return ({ pending_payment: '充值单已创建，支付成功后余额才会到账。你可以继续支付或刷新结果。', succeeded: '充值已到账，余额已更新。', expired: '充值单已过期，未增加余额，请重新发起充值。', cancelled: '支付已取消，充值单已关闭，请重新发起充值。', rejected: onlineRecharge.value.review_note || '充值未完成，请重新发起。' } as Record<string, string>)[onlineRecharge.value.status] || '正在确认充值结果，请稍后刷新。'
})

onLoad((query) => { returnToCheckout.value = query?.returnTo === 'checkout' })
onShow(async () => { await modules.ensureReady(); if (!moduleEnabled.value) return; await load(); await resumeOnlineIntent() })
async function load() {
  if (!auth.isAuthenticated) return navigateToLogin()
  loading.value = true
  loadError.value = ''
  try { [wallet.value, recharges.value] = await Promise.all([getWalletAccount(auth.accessToken), listWalletRecharges(auth.accessToken).then((r) => r.items)]) }
  catch (error) { loadError.value = error instanceof Error ? error.message : '请稍后重试。'; toast(error, '充值信息加载失败') }
  finally { loading.value = false }
}
function cents() { const value = Math.round(Number(amountYuan.value) * 100); if (!Number.isFinite(value) || value < 100 || value > 5000000) throw new Error('充值金额需为 1 至 50000 元'); return value }
async function submitCard() { if (!cardCode.value.trim()) return uni.showToast({ title: '请输入充值卡密', icon: 'none' }); await submit(async () => { await createWalletRecharge(auth.accessToken, { method: 'card_code', card_code: cardCode.value.trim() }); cardCode.value = ''; uni.showToast({ title: '兑换成功，余额已到账', icon: 'success' }) }) }
async function submitOnline() {
  if (onlineRecharge.value?.status === 'succeeded' || ['expired', 'rejected'].includes(onlineRecharge.value?.status || '')) clearOnlineResult()
  await submit(async () => {
    const amount = cents()
    ensureOnlineIntent(amount)
    const existing = onlineRecharge.value && onlineRecharge.value.status === 'pending_payment' && onlineRecharge.value.amount_cents === amount ? onlineRecharge.value : null
    const recharge = existing || await createWalletRecharge(auth.accessToken, { method: 'online', amount_cents: amount }, onlineIntentKey.value)
    onlineRecharge.value = recharge
    persistOnlineIntent(recharge.id)
    if (recharge.status !== 'pending_payment') return
    await continueOnlinePayment()
  })
}

async function continueOnlinePayment() {
  if (!auth.accessToken || !onlineRecharge.value || onlineRecharge.value.status !== 'pending_payment' || onlinePaying.value) return
  onlinePaying.value = true
  try {
    if (!onlinePaymentKey.value || onlinePaymentId.value === '') onlinePaymentKey.value = `recharge-pay-${onlineRecharge.value.id}-${Date.now()}-${Math.random().toString(36).slice(2)}`
    const payment = await createRechargePayment(auth.accessToken, onlineRecharge.value.id, clientPlatform === 'h5-wechat' ? 'wechat-h5' : clientPlatform, onlinePaymentKey.value)
    onlinePaymentId.value = payment.id
    persistOnlineIntent(onlineRecharge.value.id, payment.id)
    if (['failed', 'closed', 'cancelled'].includes(payment.status)) {
      onlinePaymentKey.value = ''
      onlinePaymentId.value = ''
      throw new Error('支付单未成功，请点击继续支付重试')
    }
    const payload = payment.client_payload
    if (payment.status === 'succeeded') return await pollOnlineRecharge(1)
    if (!payload) throw new Error('支付参数生成失败')
    if (payload.sandbox) { uni.showToast({ title: '本地模拟支付单已创建，请查询结果', icon: 'none' }); return await pollOnlineRecharge() }
    if (payload.kind === 'mweb') {
      /* #ifdef H5 */
      const returnUrl = new URL(window.location.href)
      returnUrl.searchParams.set('resumeRecharge', onlineRecharge.value.id)
      const separator = payload.mweb_url.includes('?') ? '&' : '?'
      window.location.assign(`${payload.mweb_url}${separator}redirect_url=${encodeURIComponent(returnUrl.toString())}`)
      /* #endif */
      return
    }
    /* #ifdef MP-WEIXIN */
    await uni.requestPayment({ provider: 'wxpay', timeStamp: payload.timeStamp, nonceStr: payload.nonceStr, package: payload.package, signType: 'RSA', paySign: payload.paySign })
    /* #endif */
    uni.showToast({ title: '支付结果确认中', icon: 'none' })
    await pollOnlineRecharge()
  } catch (error) {
    actionError.value = error instanceof Error ? error.message : '支付未完成，可稍后继续支付'
    toast(error, '支付未完成，可稍后继续支付')
    if (onlineRecharge.value) await refreshOnlineRecharge(onlineRecharge.value.id)
  } finally { onlinePaying.value = false }
}

async function pollOnlineRecharge(maxAttempts = 10) {
  if (!auth.accessToken || !onlineRecharge.value || onlinePolling.value) return
  onlinePolling.value = true
  try {
    for (let attempt = 0; attempt < maxAttempts; attempt += 1) {
      const current = await refreshOnlineRecharge(onlineRecharge.value.id)
      if (['succeeded', 'expired', 'cancelled', 'rejected'].includes(current.status)) {
        await load()
        if (current.status === 'succeeded') uni.showToast({ title: '充值已到账', icon: 'success' })
        return current
      }
      if (attempt < maxAttempts - 1) await new Promise((resolve) => setTimeout(resolve, 1500))
    }
    uni.showToast({ title: '结果仍在确认中，请稍后刷新', icon: 'none' })
  } catch (error) { toast(error, '查询充值结果失败，请重试') }
  finally { onlinePolling.value = false }
}

async function refreshOnlineRecharge(id: string) {
  if (!auth.accessToken) throw new Error('登录状态已失效，请重新登录')
  const recharge = await getWalletRecharge(auth.accessToken, id)
  onlineRecharge.value = recharge
  return recharge
}

async function resumeOnlineIntent() {
  const stored = uni.getStorageSync(onlineIntentStorageKey) as { rechargeId?: string; paymentId?: string; key?: string; amount?: number } | null
  if (!stored?.rechargeId || !auth.accessToken) return
  onlineIntentKey.value = stored.key || ''
  onlineIntentAmount.value = stored.amount || 0
  onlinePaymentId.value = stored.paymentId || ''
  try {
    const recharge = await refreshOnlineRecharge(stored.rechargeId)
    if (['succeeded', 'expired', 'cancelled', 'rejected'].includes(recharge.status)) uni.removeStorageSync(onlineIntentStorageKey)
  } catch { /* 页面仍可通过充值记录查看，并由用户点击重试 */ }
}

function ensureOnlineIntent(amount: number) {
  if (!onlineIntentKey.value || onlineIntentAmount.value !== amount) {
    onlineIntentKey.value = `recharge-${Date.now()}-${Math.random().toString(36).slice(2)}`
    onlineIntentAmount.value = amount
    onlinePaymentKey.value = ''
    onlinePaymentId.value = ''
  }
  persistOnlineIntent(onlineRecharge.value?.id)
}
function persistOnlineIntent(rechargeId?: string, paymentId?: string) { uni.setStorageSync(onlineIntentStorageKey, { key: onlineIntentKey.value, amount: onlineIntentAmount.value, rechargeId: rechargeId || onlineRecharge.value?.id, paymentId: paymentId || onlinePaymentId.value }) }
function clearOnlineResult() { onlineRecharge.value = null; onlinePaymentId.value = ''; onlinePaymentKey.value = ''; onlineIntentKey.value = ''; onlineIntentAmount.value = 0; uni.removeStorageSync(onlineIntentStorageKey) }
function backToCheckout() {
  const pages = getCurrentPages()
  if (pages.length > 1) {
    uni.navigateBack({ delta: 1 })
    return
  }
  uni.redirectTo({ url: checkoutReturnUrl })
}
async function addProof() { if (!auth.accessToken) return; try { proofs.value.push(await chooseAndUploadPrivateImage(auth.accessToken, 'offline_recharge')) } catch (error) { toast(error, '凭证上传失败') } }
function removeProof(id: string) { proofs.value = proofs.value.filter((item) => item.fileId !== id) }
async function submitOffline() { if (!payerName.value.trim()) return uni.showToast({ title: '请填写付款人姓名', icon: 'none' }); if (!proofs.value.length) return uni.showToast({ title: '请上传付款凭证', icon: 'none' }); await submit(async () => { await createWalletRecharge(auth.accessToken, { method: 'offline', amount_cents: cents(), payer_name: payerName.value.trim(), offline_reference: offlineReference.value.trim() || undefined, note: note.value.trim() || undefined, offline_proof_file_ids: proofs.value.map((item) => item.fileId) }); payerName.value = ''; offlineReference.value = ''; note.value = ''; proofs.value = []; uni.showToast({ title: '已提交财务审核', icon: 'success' }) }) }
async function submit(action: () => Promise<void>) { if (submitting.value) return; submitting.value = true; actionError.value = ''; try { await action(); await load() } catch (error) { actionError.value = error instanceof Error ? error.message : '充值操作失败，请稍后重试'; toast(error, '充值操作失败') } finally { submitting.value = false } }
function money(value: number) { return (value / 100).toFixed(2) }
function methodText(value: string) { return ({ card_code: '卡密兑换', online: '线上支付', offline: '线下支付' } as Record<string,string>)[value] || '余额充值' }
function statusText(value: string) { return ({ pending_payment: '待支付', pending_review: '待审核', succeeded: '已到账', rejected: '已拒绝', expired: '已过期', cancelled: '已取消' } as Record<string,string>)[value] || '处理中' }
function toast(error: unknown, fallback: string) { uni.showToast({ title: error instanceof Error ? error.message : fallback, icon: 'none' }) }
</script>

<style scoped>
.page{min-height:100vh;padding:24rpx 28rpx 120rpx;background:#F4F6F4;color:#191B1F}
/* 余额卡 */
.balance-card{display:flex;align-items:center;gap:24rpx;margin:0 0 20rpx;padding:28rpx 24rpx;border-radius:32rpx;background:#fff;box-shadow:0 2rpx 4rpx rgba(23,26,23,.03),0 12rpx 40rpx rgba(23,26,23,.05)}
.balance-ic{display:flex;align-items:center;justify-content:center;width:76rpx;height:76rpx;flex:none;border-radius:24rpx;background:#E7F9EF;color:#08A152;font-size:36rpx;font-weight:900}
.balance-main{flex:1;min-width:0}
.balance-label{display:block;font-size:28rpx;font-weight:700;color:#191B1F}
.balance-tip{display:block;margin-top:6rpx;font-size:22rpx;color:#767C85}
.balance{font-size:36rpx;font-weight:800;color:#08A152;flex:none}
.return-checkout{height:56rpx;margin:0;padding:0 28rpx;border:2rpx solid #0BBF63;border-radius:28rpx;background:#fff;color:#08A152;font-size:24rpx;font-weight:700;line-height:52rpx}
.return-checkout::after{border:0}
/* 标签切换 */
.method-tabs{display:grid;grid-template-columns:repeat(3,1fr);gap:12rpx;margin-top:8rpx}
.method-tab{display:flex;align-items:center;justify-content:center;gap:8rpx;padding:24rpx 8rpx;border-radius:24rpx;background:#fff;font-size:28rpx;font-weight:700;box-shadow:0 2rpx 4rpx rgba(23,26,23,.03),0 8rpx 20rpx rgba(23,26,23,.05)}
.method-tab.active{background:#E7F9EF;color:#08A152}
.method-icon{display:flex;align-items:center;justify-content:center;width:40rpx;height:40rpx;border-radius:14rpx;background:#0BBF63;color:#fff;font-size:20rpx;font-weight:700}
.method-tab.active .method-icon{background:#08A152}
/* 表单卡片 */
.form-card,.record-card{margin-top:20rpx;padding:28rpx;border-radius:32rpx;background:#fff;box-shadow:0 2rpx 4rpx rgba(23,26,23,.03),0 12rpx 40rpx rgba(23,26,23,.05)}
.form-title{display:block;font-size:30rpx;font-weight:700;color:#191B1F}
.form-desc{display:block;margin-top:10rpx;color:#767C85;font-size:24rpx;line-height:1.55}
.input,.textarea{box-sizing:border-box;width:100%;margin-top:18rpx;padding:0 24rpx;border:2rpx solid #EDEFEF;border-radius:24rpx;background:#F7F9F8;font-size:26rpx;color:#191B1F}
.input::placeholder{color:#AEB3BB}
.input{height:84rpx}
.textarea{height:160rpx;padding-top:20rpx}
.submit{height:80rpx;margin-top:24rpx;border:0;border-radius:40rpx;background:linear-gradient(135deg,#12D978,#08A152);color:#fff;font-size:28rpx;font-weight:700;line-height:80rpx;box-shadow:0 12rpx 28rpx rgba(11,191,99,.3)}
.submit::after{border:0}
/* chip-pick 金额选项 */
.frow-label{display:block;margin-top:24rpx;font-size:26rpx;font-weight:600;color:#191B1F}
.chip-pick{display:flex;flex-wrap:wrap;gap:16rpx;margin-top:14rpx}
.chip{height:64rpx;padding:0 28rpx;border-radius:32rpx;border:2rpx solid #EDEFEF;background:#F7F9F8;font-size:26rpx;font-weight:600;color:#767C85;line-height:60rpx}
.chip.on{border-color:#0BBF63;color:#08A152;background:#E7F9EF}
/* 支付方式行 */
.pay-row{display:flex;align-items:center;gap:14rpx;margin-top:24rpx;padding:24rpx 0;border-top:1rpx solid #EDEFEF}
.pay-label{font-size:26rpx;font-weight:600;color:#191B1F;flex:1}
.pay-value{font-size:26rpx;font-weight:600;color:#191B1F}
.pay-check{display:flex;align-items:center;justify-content:center;width:36rpx;height:36rpx;border-radius:50%;background:#0BBF63;color:#fff;font-size:20rpx;font-weight:700}
/* 提示卡 */
.tip-card{margin-top:20rpx;padding:24rpx;border-radius:24rpx;background:#FFF2E5;color:#767C85;font-size:22rpx;line-height:1.6}
/* 记录卡片 */
.section-head{display:flex;justify-content:space-between;align-items:center;padding-bottom:18rpx;border-bottom:1rpx solid #EDEFEF}
.section-head text:first-child{font-size:30rpx;font-weight:700}
.section-head text:last-child{color:#AEB3BB;font-size:24rpx}
.record-row{display:flex;justify-content:space-between;align-items:flex-start;gap:16rpx;padding:22rpx 0;border-bottom:1rpx solid #EDEFEF}
.record-row>view{min-width:0;flex:1}
.record-title{display:block;font-size:28rpx;font-weight:600;color:#191B1F}
.record-time,.review-result{display:block;margin-top:8rpx;color:#AEB3BB;font-size:22rpx;line-height:1.4}
.status{padding:8rpx 16rpx;border-radius:999rpx;background:#F7F9F8;font-size:22rpx;color:#767C85;white-space:nowrap}
.status.succeeded{background:#E7F9EF;color:#08A152}
.status.pending_review,.status.pending_payment{background:#FFF2E5;color:#F2620A}
.status.rejected{background:#FFEEEA;color:#FF2442}
.empty{padding:40rpx 0;text-align:center;color:#AEB3BB;font-size:26rpx}
/* 状态卡 */
.state-card{margin-top:20rpx;padding:40rpx;text-align:center;border-radius:32rpx;background:#fff;box-shadow:0 2rpx 4rpx rgba(23,26,23,.03),0 12rpx 40rpx rgba(23,26,23,.05);color:#767C85}
.state-title,.state-copy{display:block}.state-title{font-size:30rpx;font-weight:700;color:#191B1F}.state-copy{margin-top:10rpx;font-size:24rpx;color:#767C85;line-height:1.5}
.state-action{height:64rpx;margin:20rpx auto 0;padding:0 32rpx;border:2rpx solid #FF2442;border-radius:32rpx;background:#fff;color:#FF2442;font-size:24rpx;line-height:60rpx}.state-action::after{border:0}
.error-state{color:#FF2442}
.error-state .state-title{color:#FF2442}
/* 线上充值结果 */
.online-result{margin-top:20rpx;padding:28rpx;border-radius:32rpx;background:#E7F9EF}
.result-head{display:flex;align-items:flex-start;justify-content:space-between;gap:16rpx}
.result-status{padding:8rpx 16rpx;border-radius:999rpx;background:#0BBF63;color:#fff;font-size:24rpx;font-weight:700}
.result-amount{display:block;margin-top:24rpx;color:#08A152;font-size:36rpx;font-weight:800}
.result-copy{display:block;margin-top:10rpx;color:#767C85;font-size:24rpx;line-height:1.5}
.result-actions{display:flex;flex-wrap:wrap;gap:12rpx;margin-top:20rpx}
.result-actions button{flex:1;min-width:200rpx;height:68rpx;margin:0;border-radius:24rpx;font-size:24rpx;line-height:66rpx}
.result-actions button::after{border:0}
.result-pending_payment{background:#FFF2E5}
.result-succeeded{background:#E7F9EF}
.result-expired,.result-rejected{background:#FFEEEA}
/* 附件与备注 */
.proofs{display:flex;flex-wrap:wrap;gap:16rpx;margin-top:20rpx}
.proof,.add-proof{width:152rpx;height:152rpx;border:2rpx dashed #D7DBD8;border-radius:24rpx;overflow:hidden}
.proof{position:relative}.proof image{width:100%;height:100%}
.proof text{position:absolute;right:8rpx;bottom:8rpx;padding:6rpx 12rpx;border-radius:999rpx;background:rgba(0,0,0,.65);color:#fff;font-size:20rpx}
.add-proof{display:flex;align-items:center;justify-content:center;flex-direction:column;background:#F7F9F8}.add-proof text{font-size:32rpx;color:#AEB3BB}.add-proof small{font-size:20rpx;color:#AEB3BB}
.review-note{margin-top:20rpx;padding:20rpx;border-radius:20rpx;background:#FFF2E5;color:#767C85;font-size:22rpx}
/* 错误提示 */
.action-error{display:flex;align-items:center;justify-content:space-between;gap:16rpx;margin-bottom:20rpx;padding:20rpx 24rpx;border-radius:24rpx;background:#FFEEEA;color:#FF2442;font-size:24rpx;line-height:1.45}
.action-error text{min-width:0;flex:1}
.action-error button{flex:none;margin:0;padding:0 20rpx;height:52rpx;border:2rpx solid #FF2442;border-radius:16rpx;background:#fff;color:#FF2442;font-size:22rpx;line-height:48rpx}
.action-error button::after{border:0}
</style>
