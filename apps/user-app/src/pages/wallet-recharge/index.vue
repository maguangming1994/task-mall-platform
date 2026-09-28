<template>
  <view class="page tm-page-shell">
    <view class="balance-card">
      <text class="eyebrow">CAMPUS WALLET</text><text class="balance-label">当前可用余额</text>
      <text class="balance">{{ wallet ? `¥${money(wallet.available_amount)}` : '—' }}</text>
      <text class="balance-tip">{{ wallet ? '余额无有效期，可用于商城现金部分的全额支付' : '余额信息暂不可用，请重试加载' }}</text>
      <button v-if="returnToCheckout" class="tm-secondary-button return-checkout" @click="backToCheckout">返回结算</button>
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
        <view class="amount-grid"><view v-for="item in presets" :key="item" class="amount-option" :class="{ active: amountYuan === String(item) }" @click="amountYuan = String(item)"><strong>¥{{ item }}</strong><text>到账 {{ item }} 元</text></view></view>
        <input v-model="amountYuan" class="input" type="digit" placeholder="或输入其他金额（1-50000 元）" />
        <button class="tm-primary-button submit" :loading="submitting || onlinePaying" :disabled="onlinePaying" @click="submitOnline">{{ onlineRecharge && isOnlinePending ? '继续微信支付' : '微信支付' }}</button>
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
onShow(async () => { await load(); await resumeOnlineIntent() })
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
.return-checkout{margin-top:20rpx;border:2rpx solid #fff;color:#173d39;background:#fff;box-shadow:none}
.page{min-height:100vh;padding:28rpx 26rpx 90rpx;background:linear-gradient(180deg,#dff5f1 0,var(--tm-canvas) 500rpx)}.balance-card{padding:32rpx;border:2rpx solid var(--tm-ink-900);border-radius:28rpx;background:linear-gradient(135deg,#173d39,#1b756b);color:#fff;box-shadow:0 8rpx 0 rgba(20,37,35,.2)}.eyebrow,.balance-label,.balance,.balance-tip,.form-title,.form-desc,.record-title,.record-time,.review-result,.empty{display:block}.eyebrow{font-size:13px;font-weight:850;letter-spacing:3rpx;color:#a9eadf}.balance-label{margin-top:25rpx;font-size:14px}.balance{margin-top:8rpx;font-size:30px;font-weight:950}.balance-tip{margin-top:15rpx;color:#d6efeb;font-size:13px}.method-tabs{display:grid;grid-template-columns:repeat(3,1fr);gap:12rpx;margin-top:22rpx}.method-tab{display:flex;align-items:center;justify-content:center;gap:9rpx;padding:20rpx 8rpx;border:2rpx solid var(--tm-ink-900);border-radius:18rpx;background:#fff;font-size:14px;font-weight:850;box-shadow:0 4rpx 0 rgba(22,22,22,.12)}.method-tab.active{background:var(--tm-campus-yellow)}.method-icon{display:flex;align-items:center;justify-content:center;width:36rpx;height:36rpx;border-radius:11rpx;background:var(--tm-brand-600);color:#fff;font-size:12px}.form-card,.record-card{margin-top:22rpx;padding:26rpx;border:2rpx solid var(--tm-ink-900);box-shadow:0 6rpx 0 rgba(22,22,22,.12)}.form-title{font-size:17px;font-weight:950}.form-desc{margin-top:8rpx;color:var(--tm-ink-500);font-size:13px;line-height:1.55}.input,.textarea{box-sizing:border-box;width:100%;margin-top:18rpx;padding:0 20rpx;border:2rpx solid var(--tm-ink-900);border-radius:16rpx;background:#fff;font-size:14px}.input{height:78rpx}.textarea{height:150rpx;padding-top:18rpx}.submit{margin-top:22rpx;border:2rpx solid var(--tm-ink-900);box-shadow:0 5rpx 0 var(--tm-ink-900)}.amount-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:12rpx;margin-top:20rpx}.amount-option{padding:18rpx 8rpx;border:2rpx solid var(--tm-ink-900);border-radius:16rpx;text-align:center;background:#fff}.amount-option.active{background:var(--tm-campus-lime)}.amount-option strong,.amount-option text{display:block}.amount-option strong{font-size:15px}.amount-option text{margin-top:4rpx;color:var(--tm-ink-500);font-size:12px}.proofs{display:flex;flex-wrap:wrap;gap:12rpx;margin-top:18rpx}.proof,.add-proof{width:142rpx;height:142rpx;border:2rpx dashed var(--tm-ink-900);border-radius:16rpx;overflow:hidden}.proof{position:relative}.proof image{width:100%;height:100%}.proof text{position:absolute;right:5rpx;bottom:5rpx;padding:5rpx 9rpx;border-radius:999rpx;background:rgba(0,0,0,.7);color:#fff;font-size:12px}.add-proof{display:flex;align-items:center;justify-content:center;flex-direction:column;background:#f8fbfb}.add-proof text{font-size:22px}.add-proof small{font-size:12px}.review-note{margin-top:16rpx;padding:16rpx;border-radius:14rpx;background:#fff5d8;color:#815f10;font-size:13px}.section-head{display:flex;justify-content:space-between;align-items:center;padding-bottom:15rpx;border-bottom:1rpx solid var(--tm-line)}.section-head text:first-child{font-size:17px;font-weight:950}.section-head text:last-child{color:var(--tm-ink-500);font-size:13px}.record-row{display:flex;justify-content:space-between;align-items:flex-start;gap:15rpx;padding:21rpx 0;border-bottom:1rpx solid var(--tm-line)}.record-row>view{min-width:0;flex:1}.record-title{font-size:14px;font-weight:850}.record-time,.review-result{margin-top:7rpx;color:var(--tm-ink-500);font-size:12px;line-height:1.4}.status{padding:7rpx 12rpx;border-radius:999rpx;background:#eef1f1;font-size:12px;white-space:nowrap}.status.succeeded{background:#d9f3e7;color:#067647}.status.pending_review,.status.pending_payment{background:#fff0cf;color:#9b6200}.status.rejected{background:#fee4e2;color:#b42318}.empty{padding:32rpx;text-align:center;color:var(--tm-ink-500)}
.state-card{margin-top:20rpx;padding:30rpx;text-align:center;border:2rpx solid #b42318;border-radius:22rpx;background:#fff7f5;color:#b42318}.state-title,.state-copy{display:block}.state-title{font-size:15px;font-weight:850}.state-copy{margin-top:7rpx;font-size:13px;line-height:1.5}.state-action{height:62rpx;margin:18rpx auto 0;padding:0 22rpx;border:2rpx solid #b42318;border-radius:14rpx;background:#fff;color:#b42318;font-size:13px;line-height:58rpx}.state-action::after{border:0}.online-result{margin-top:22rpx;padding:26rpx;border:2rpx solid var(--tm-ink-900);background:var(--tm-campus-cyan-soft)}.result-head{display:flex;align-items:flex-start;justify-content:space-between;gap:15rpx}.result-status{padding:7rpx 12rpx;border-radius:999rpx;background:var(--tm-campus-yellow);font-size:13px;font-weight:850}.result-amount{display:block;margin-top:20rpx;color:var(--tm-reward-600);font-size:24px;font-weight:950}.result-copy{display:block;margin-top:8rpx;color:var(--tm-ink-500);font-size:13px;line-height:1.5}.result-actions{display:flex;flex-wrap:wrap;gap:10rpx;margin-top:18rpx}.result-actions button{flex:1;min-width:180rpx;height:62rpx;margin:0;border-radius:14rpx;font-size:13px;line-height:60rpx}.result-actions button::after{border:0}.result-pending_payment{background:var(--tm-campus-yellow-soft)}.result-succeeded{background:var(--tm-campus-lime-soft)}.result-expired,.result-rejected{background:var(--tm-campus-coral-soft)}
/* V09 campus cards: light borders, rounded surfaces and restrained shadows. */
.balance-card{border:0;box-shadow:0 16rpx 34rpx rgba(22,91,107,.18)}
.method-tab{border:1rpx solid #d6e5e7;box-shadow:0 8rpx 18rpx rgba(24,79,91,.06);font-weight:750}
.method-tab.active{border-color:#65c8bf;background:#e4faf5;color:#135b58}
.form-card,.record-card{border:1rpx solid #d8e7e8;box-shadow:0 12rpx 28rpx rgba(25,72,89,.07)}
.input,.textarea{border:1rpx solid #cfe0e2;background:#fbfefe}
.amount-option{border:1rpx solid #d6e5e7;box-shadow:none}
.amount-option.active{border-color:#8ed7c4;background:#eaf9f0}
.submit{border:0;box-shadow:0 10rpx 20rpx rgba(24,125,111,.18)}
.proof,.add-proof{border:1rpx dashed #b9d5d8}
.online-result{border:1rpx solid #cfe5e6}
.state-card{border:1rpx solid #f1c9c2;box-shadow:0 8rpx 20rpx rgba(151,67,52,.06)}
.action-error{display:flex;align-items:center;justify-content:space-between;gap:14rpx;margin-bottom:18rpx;padding:16rpx 18rpx;border:1rpx solid #f0c6bf;border-radius:16rpx;background:#fff5f2;color:#b42318;font-size:13px;line-height:1.45}
.action-error text{min-width:0;flex:1}
.action-error button{flex:none;margin:0;padding:0 14rpx;height:50rpx;border:1rpx solid #dc8f84;border-radius:12rpx;background:#fff;color:#a33d32;font-size:13px;line-height:48rpx}
.action-error button::after{border:0}
/* V09 普通注册用户：充值页与资产总览保持同一套校园蓝青资产视觉。 */
.page{background:radial-gradient(circle at 92% 8%,rgba(91,210,198,.18),transparent 260rpx),linear-gradient(180deg,#edfaff 0,#f8fcfd 620rpx,#f4f8fa 100%)!important}
.balance-card{background:linear-gradient(135deg,#1268c9 0%,#1598d5 56%,#26c6b9 100%)!important;box-shadow:0 16rpx 34rpx rgba(19,113,159,.18)!important}
.balance-card .eyebrow{color:#e7fbff!important}
.balance-card .balance-label{color:rgba(255,255,255,.92)!important}
.balance-card .balance{color:#fff!important}
.balance-card .balance-tip{color:rgba(255,255,255,.84)!important}
.method-tabs{gap:10rpx}
.method-tab{background:#fff!important;color:#2e5062!important}
.method-tab.active{border-color:#67cdb4!important;background:#e9fbef!important;color:#14735f!important}
.method-icon{background:#e4f7f2!important;color:#16826b!important}
.form-card,.record-card{background:rgba(255,255,255,.98)!important}
.form-title{color:#15324a!important}
.form-desc,.record-time,.review-result,.empty{color:#526b7c!important}
.submit{background:linear-gradient(135deg,#16a6d8,#20bca7)!important;color:#fff!important}
.review-note{background:#fff8df!important;color:#735c19!important}
</style>

<style scoped>
/* V09 mobile recharge surface: preserve the three payment flows while matching the 26 card layout. */
.page{min-height:100vh;padding:24rpx 28rpx 72rpx;background:radial-gradient(circle at 90% 5%,rgba(91,210,198,.18),transparent 250rpx),linear-gradient(180deg,#f0fbff 0,#f7fbfd 640rpx,#f4f8fb 100%)!important}
.balance-card{min-height:276rpx;padding:36rpx 38rpx;border:0;border-radius:31rpx;background:linear-gradient(135deg,#237fd8 0%,#2b9dcc 54%,#28bcb8 100%)!important;box-shadow:0 18rpx 38rpx rgba(35,126,177,.18)!important}
.balance-card .eyebrow{color:rgba(255,255,255,.24)!important;font-size:14px;letter-spacing:3rpx}
.balance-card .balance-label{margin-top:40rpx;color:#fff!important;font-size:15px;font-weight:750}
.balance-card .balance{margin-top:10rpx;color:#fff!important;font-size:34px;font-weight:950}
.balance-card .balance-tip{margin-top:15rpx;color:rgba(255,255,255,.9)!important;font-size:14px}
.method-tabs{gap:12rpx;margin-top:23rpx}
.method-tab{min-height:86rpx;padding:14rpx 7rpx;border:1rpx solid #dfebf3;border-radius:22rpx;background:rgba(255,255,255,.98)!important;box-shadow:0 8rpx 20rpx rgba(31,56,92,.05);color:#10213a!important;font-size:14px;font-weight:800}
.method-tab.active{border:2rpx solid #257be4!important;background:#fff!important;color:#2474cf!important}
.method-icon{width:42rpx;height:42rpx;border-radius:14rpx;background:#edf5ff!important;color:#2678dc!important;font-size:13px}
.form-card,.record-card{margin-top:25rpx;padding:31rpx;border:1rpx solid #dfeaf2; border-radius:27rpx;background:rgba(255,255,255,.98)!important;box-shadow:0 12rpx 30rpx rgba(31,56,92,.055)!important}
.form-title{font-size:19px;font-weight:900;color:#10213a!important}
.form-desc{margin-top:11rpx;color:#71819a!important;font-size:14px;line-height:1.55}
.input,.textarea{border:1rpx solid #d4e1ed;border-radius:17rpx;background:#fbfdff;font-size:14px}
.input{height:84rpx;margin-top:21rpx}
.submit{height:80rpx;margin-top:23rpx;border:0;box-shadow:0 10rpx 20rpx rgba(36,122,218,.16)!important;background:linear-gradient(135deg,#237ce6,#23b9b1)!important;font-size:15px;line-height:80rpx}
.amount-grid{gap:11rpx;margin-top:21rpx}
.amount-option{padding:18rpx 7rpx;border:1rpx solid #dce8f1;border-radius:17rpx;background:#fbfdff}
.amount-option.active{border-color:#80d6bf;background:#ecfaf3}
.record-card{padding-bottom:20rpx}
.section-head{padding-bottom:18rpx;border-bottom:1rpx solid #e7eef4}
.section-head text:first-child{font-size:19px;font-weight:900;color:#10213a}
.section-head text:last-child{color:#71819a;font-size:13px}
.record-row{padding:22rpx 0;border-bottom:1rpx solid #e7eef4}
.record-title{font-size:14px;color:#223650}.record-time,.review-result{color:#7b8ba0;font-size:12px}
.empty{padding:76rpx 0 45rpx;color:#71819a;font-size:14px;font-weight:750}
.empty::before{content:'⌕';display:block;width:93rpx;height:93rpx;margin:0 auto 18rpx;border:8rpx solid #cfdbe8;border-radius:25rpx;color:#cfdbe8;font-size:34px;line-height:82rpx}
.review-note{border-radius:15rpx;background:#f1fbf7;color:#657f81}
.state-card{border:1rpx solid #dce9f1;border-radius:20rpx;background:#fff;color:#71819a}
.action-error{border-color:#f1c7c0;border-radius:16rpx;background:#fff5f2}
@media screen and (max-width:360px){.page{padding-left:20rpx;padding-right:20rpx}.balance-card{padding-left:27rpx;padding-right:27rpx}.balance-card .balance{font-size:30px}.form-card,.record-card{padding-left:23rpx;padding-right:23rpx}}
</style>
