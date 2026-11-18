<template>
  <view class="page referrals-page">
    <ModulePlaceholder v-if="modules.resolved && !moduleEnabled" code="module_referrals" />
    <view v-else-if="!modules.resolved" class="mp-skeleton">
      <view class="tm-skeleton" style="height: 200rpx; border-radius: 24rpx; margin: 24rpx 28rpx;" />
      <view class="tm-skeleton" style="height: 320rpx; border-radius: 24rpx; margin: 20rpx 28rpx;" />
    </view>
    <template v-else>

    <view class="ref-hero">
      <view class="hero-col"><text class="hero-num">{{ dashboard ? dashboard.summary.invited_count : 0 }}</text><text class="hero-label">累计邀请（人）</text></view>
      <view class="hero-col"><text class="hero-num">{{ dashboard ? dashboard.summary.reward_points_total : 0 }}</text><text class="hero-label">获得积分</text></view>
    </view>

    <view class="invite-panel"><view class="invite-code"><text class="code-label">我的邀请码</text><view class="code-line"><text class="code">{{ displayReferralCode }}</text><button class="copy-icon" :disabled="!referralCode" aria-label="复制邀请码" @click="copyCode">复制</button></view></view><view class="reward-badge"><text>{{ rewardPoints }}</text><small>积分 / 人</small></view><view class="invite-actions"><button class="poster-button" :disabled="!referralCode" @click="openPoster">生成邀请海报</button><button class="link-button" :disabled="!referralCode" @click="copyLink">复制链接</button></view></view>

    <view v-if="loading && !dashboard" class="state-card" role="status" aria-live="polite"><view class="spinner" /><text>正在同步邀请规则与好友进度…</text></view>
    <view v-else-if="loadError && !dashboard" class="state-card error-state" role="alert"><text class="state-icon">!</text><text>邀请数据暂时不可用</text><small>{{ loadError }}</small><button @click="load">重试</button></view>

    <view v-if="dashboard" class="section-card rules-card"><view class="section-head"><view class="section-heading"><text class="section-icon">♔</text><view><text class="section-title">如何获得奖励</text><text class="section-subtitle">好友完成全部条件后，你将获得对应奖励</text></view></view></view><view class="rule-flow"><view class="rule-step"><view class="rule-icon invite">♙+</view><text>好友通过你的<br />邀请加入</text></view><text class="flow-arrow">→</text><view class="rule-step"><view class="rule-icon done">✓</view><text>好友完成<br />全部条件</text></view><text class="flow-arrow">→</text><view class="rule-step"><view class="rule-icon reward">＋</view><text>你获得<br />奖励积分</text></view></view><view class="rule-note">ⓘ　仅在好友全部条件完成后，奖励才会发放。</view></view>

    <view class="section-card bind-card"><view class="section-heading"><text class="section-icon bind-icon">♙+</text><view><text class="section-kicker">绑定邀请</text><text class="section-title">我收到了好友邀请</text></view><button class="where-button" @click="showInviteHelp">邀请码在哪里？</button></view><text v-if="pendingCode" class="pending-tip">已从邀请链接识别邀请码 {{ pendingCode }}，确认无误后绑定。</text><view class="bind-row"><input v-model.trim="bindCode" class="bind-input" maxlength="32" placeholder="输入好友邀请码" /><button class="bind-button" :loading="binding" :disabled="binding" @click="bind">确认绑定</button></view><text class="bind-help">推荐关系仅可绑定一次，请核对邀请人后再提交。</text></view>

    <view v-if="dashboard" class="section-card progress-card"><view class="section-head"><view class="section-heading"><text class="section-icon progress-icon">▥</text><view><text class="section-kicker">邀请进度</text><text class="section-title">我的好友</text></view></view><button class="history-button" @click="showInviteHistory">邀请记录　›</button></view><view class="progress-summary"><view><text class="progress-icon-large">♙♙</text><strong>{{ dashboard.summary.invited_count }}</strong><small>已邀请（人）</small><em>通过你的邀请链接<br />或邀请码加入</em></view><view><text class="progress-icon-large done-color">✓</text><strong>{{ dashboard.summary.rewarded_count }}</strong><small>完成条件（人）</small><em>好友完成全部条件，<br />奖励将自动到账</em></view><view><text class="progress-icon-large reward-color">★</text><strong>{{ dashboard.summary.reward_points_total }}</strong><small>获得积分（分）</small><em>每成功邀请 1 人，<br />获得对应奖励</em></view></view><view v-if="items.length" class="friends-list"><view v-for="item in items" :key="item.id" class="friend-row"><view class="friend-avatar">{{ item.referred_user.nickname.slice(0, 1) }}</view><view class="friend-main"><text>{{ item.referred_user.nickname }}</text><small>绑定于 {{ formatDate(item.bound_at) }}</small></view><text class="status-pill">{{ statusText(item.status) }}</text></view></view></view>

    <view class="compliance-card"><text class="compliance-icon">♢</text><view><text class="compliance-title">合规邀请 · 安心分享</text><text class="compliance-copy">禁止使用不正当手段（如刷单、批量注册、虚假信息等）获取奖励。平台将对违规行为进行处理，必要时追究法律责任。详情见 <text class="link">《用户协议》</text> 和 <text class="link">《隐私政策》</text>。</text></view></view>

    <!-- #ifdef MP-WEIXIN -->
    <button class="wechat-share" :disabled="!referralCode" open-type="share">微信分享</button>
    <!-- #endif -->
    </template>
    <AppTabBar current-target="account" />
  </view>
</template>

<script setup lang="ts">
import { formatDate } from '@/utils/dateTime'
import { onLoad, onShareAppMessage, onShow } from '@dcloudio/uni-app'
import { enableWechatShare, toWechatShareLink } from '@/platform/wechatJssdk'
import { computed, ref } from 'vue'
import { bindReferral, getReferrals, type ReferralDashboard } from '@/api/referrals'
import AppTabBar from '@/components/AppTabBar.vue'
import { useAuthStore } from '@/stores/auth'
import { navigateToLogin } from '@/utils/authRedirect'
import ModulePlaceholder from '@/components/ModulePlaceholder.vue'
import { useModulesStore } from '@/stores/modules'

const modules = useModulesStore()
const moduleEnabled = computed(() => modules.isEnabled('module_referrals'))

const pendingStorageKey = 'task_mall_pending_referral_code'
const auth = useAuthStore()
const dashboard = ref<ReferralDashboard | null>(null)
const bindCode = ref('')
const pendingCode = ref('')
const loading = ref(false)
const loadError = ref('')
const binding = ref(false)
const referralCode = computed(() => dashboard.value?.referral_code || '')
const rewardPoints = computed(() => dashboard.value?.rules.reward.points ?? 0)
const items = computed(() => dashboard.value?.items || [])
const displayReferralCode = computed(() => referralCode.value ? `******${referralCode.value.slice(-2)}` : '加载中')
const sharePath = computed(() => dashboard.value?.share.path || '/pages/referrals/index')

onLoad((options) => {
  const code = typeof options?.referral_code === 'string' ? options.referral_code.trim().toUpperCase() : ''
  if (code) { pendingCode.value = code; bindCode.value = code; uni.setStorageSync(pendingStorageKey, code) }
})

onShow(async () => {
  await modules.ensureReady()
  if (!moduleEnabled.value) return
  const stored = String(uni.getStorageSync(pendingStorageKey) || '').trim().toUpperCase()
  if (stored && !bindCode.value) { pendingCode.value = stored; bindCode.value = stored }
  if (!auth.isAuthenticated) { navigateToLogin(); return }
  void load()
})

onShareAppMessage(() => ({ title: shareTitle(), path: sharePath.value }))

async function load() {
  if (!auth.accessToken || loading.value) return
  loading.value = true
  loadError.value = ''
  try {
    dashboard.value = await getReferrals(auth.accessToken)
    // 公众号内：邀请链接走 JSSDK 分享卡片；其他端自动跳过。
    void enableWechatShare({ title: shareTitle(), link: toWechatShareLink(sharePath.value) })
  }
  catch (error) { loadError.value = errorMessage(error, '请检查网络后重试'); uni.showToast({ title: loadError.value, icon: 'none' }) }
  finally { loading.value = false }
}

async function bind() {
  const code = bindCode.value.trim().toUpperCase()
  if (!auth.accessToken || !code) return uni.showToast({ title: '请输入好友邀请码', icon: 'none' })
  binding.value = true
  try { await bindReferral(auth.accessToken, code); bindCode.value = ''; pendingCode.value = ''; uni.removeStorageSync(pendingStorageKey); uni.showToast({ title: '邀请关系绑定成功', icon: 'success' }); await load() }
  catch (error) { uni.showToast({ title: errorMessage(error, '绑定失败，请稍后重试'), icon: 'none', duration: 3000 }) }
  finally { binding.value = false }
}

function copyCode() { if (referralCode.value) uni.setClipboardData({ data: referralCode.value, success: () => uni.showToast({ title: '邀请码已复制', icon: 'success' }) }) }
function copyLink() { if (referralCode.value) uni.setClipboardData({ data: inviteLink(), success: () => uni.showToast({ title: '邀请链接已复制', icon: 'success' }) }) }
function inviteLink() { let link = sharePath.value; const publicBase = String(import.meta.env.VITE_H5_PUBLIC_URL || '').trim().replace(/\/$/, ''); if (publicBase) link = `${publicBase}/#${sharePath.value}`; /* #ifdef H5 */ if (!publicBase) link = `${window.location.origin}/#${sharePath.value}`; /* #endif */ return link }
function openPoster() { if (!referralCode.value) return uni.showToast({ title: '邀请码加载完成后再生成海报', icon: 'none' }); uni.navigateTo({ url: '/pages/referral-poster/index' }) }
function showInviteHelp() { uni.showModal({ title: '邀请码在哪里？', content: '请向邀请你的好友索取邀请码，确认无误后再绑定。', showCancel: false }) }
function showInviteHistory() { if (!items.value.length) uni.showToast({ title: '暂时还没有邀请记录', icon: 'none' }); else uni.showToast({ title: `共 ${items.value.length} 条邀请记录`, icon: 'none' }) }
function shareTitle() { return `邀请你加入任务商城，完成条件可得 ${rewardPoints.value} 积分奖励` }
function statusText(value: string) { return ({ tracking: '进行中', rewarded: '奖励已到账', qualified_cap_reached: '已达标·本月名额已满', revoked: '已失效' } as Record<string, string>)[value] || '进度跟踪中' }
function errorMessage(error: unknown, fallback: string) { return error instanceof Error ? error.message : fallback }
</script>

<style scoped>
.page{min-height:100vh;box-sizing:border-box;padding:0 28rpx 12rpx;background:linear-gradient(180deg,#f5fbff 0,#f7fbfd 900rpx,#f5f9fc 100%);color:#10213a}.page-header{display:flex;height:96rpx;align-items:center;justify-content:space-between;margin:0 -10rpx}.page-title{font-size:21px;font-weight:900}.header-space{width:70rpx}.back-button{width:70rpx;height:70rpx;margin:0;padding:0;border:0;background:transparent;color:#10213a;font-size:35px;line-height:58rpx}.recommend-hero{position:relative;height:282rpx;margin:0 -28rpx;overflow:hidden;background:#eaf8ff}.recommend-hero::after{content:'';position:absolute;inset:auto 0 0;height:70rpx;background:linear-gradient(180deg,transparent,#f5fbff)}.hero-copy{position:relative;z-index:1;width:69%;padding:41rpx 50rpx}.hero-kicker{display:block;color:#246ee0;font-size:13px;font-weight:900;letter-spacing:2.5rpx}.hero-title{display:block;margin-top:16rpx;color:#123a6a;font-size:24px;font-weight:900;line-height:1.25}.hero-subtitle{display:block;margin-top:12rpx;color:#5b7498;font-size:14px;line-height:1.6}.invite-panel{position:relative;z-index:2;margin-top:-3rpx;padding:29rpx 28rpx 27rpx;border:1rpx solid #d9e9f3;border-radius:28rpx;background:#fff;box-shadow:0 12rpx 30rpx rgba(31,56,92,.07)}.invite-code{display:flex;align-items:center;justify-content:space-between}.code-label{display:block;color:#647894;font-size:13px}.code-line{display:flex;align-items:center;gap:12rpx;margin-top:6rpx}.code{color:#10213a;font-size:22px;font-weight:900;letter-spacing:1rpx}.copy-icon{width:48rpx;height:48rpx;margin:0;padding:0;border:0;background:transparent;color:#2475df;font-size:19px;line-height:48rpx}.reward-badge{position:absolute;top:27rpx;right:27rpx;display:flex;width:119rpx;height:93rpx;align-items:center;justify-content:center;flex-direction:column;border:2rpx solid #10213a;border-radius:20rpx;background:#ffe76f}.reward-badge text{font-size:22px;font-weight:900}.reward-badge small{margin-top:2rpx;font-size:12px}.invite-actions{display:grid;grid-template-columns:1.6fr 1fr;gap:14rpx;margin-top:27rpx}.invite-actions button{height:74rpx;margin:0;border-radius:18rpx;font-size:14px;font-weight:850;line-height:72rpx}.poster-button{border:0;background:linear-gradient(135deg,#2477ec,#1b66de);box-shadow:0 10rpx 20rpx rgba(36,119,236,.18);color:#fff}.link-button{border:1rpx solid #2678ec;background:#fff;color:#2475dc}.invite-actions button[disabled]{opacity:.48;box-shadow:none}.state-card{display:flex;min-height:140rpx;align-items:center;justify-content:center;flex-direction:column;gap:15rpx;margin-top:20rpx;border:1rpx solid #dce9ef;border-radius:24rpx;background:#fff;color:#748099;font-size:13px}.spinner{width:40rpx;height:40rpx;border:6rpx solid #d5e7f3;border-top-color:#2475da;border-radius:50%;animation:spin .8s linear infinite}.error-state{color:#b42318;background:#fff6f5}.state-icon{display:flex;align-items:center;justify-content:center;width:48rpx;height:48rpx;border-radius:16rpx;background:#fee7e5;font-weight:900}.state-card small{font-size:12px}.state-card button{height:54rpx;margin:0;padding:0 22rpx;border:1rpx solid currentColor;border-radius:999rpx;background:#fff;color:inherit;font-size:13px;line-height:52rpx}.section-card{margin-top:20rpx;padding:27rpx;border:1rpx solid #dce9f1;border-radius:27rpx;background:rgba(255,255,255,.98);box-shadow:0 10rpx 27rpx rgba(31,56,92,.055)}.section-head{display:flex;align-items:center;justify-content:space-between;gap:12rpx}.section-heading{display:flex;min-width:0;align-items:center;gap:14rpx}.section-icon{display:flex;align-items:center;justify-content:center;width:48rpx;height:48rpx;flex:none;color:#2479e7;font-size:19px}.section-title{display:block;color:#10213a;font-size:17px;font-weight:900}.section-subtitle{display:block;margin-top:6rpx;color:#71819a;font-size:13px;line-height:1.45}.section-kicker{display:block;color:#2478e6;font-size:13px;font-weight:850;letter-spacing:2rpx}.section-kicker+.section-title{margin-top:4rpx}.rules-card{padding-bottom:21rpx}.rule-flow{display:flex;align-items:flex-start;justify-content:space-between;margin-top:26rpx}.rule-step{display:flex;min-width:0;flex:1;align-items:center;flex-direction:column;text-align:center}.rule-icon{display:flex;align-items:center;justify-content:center;width:65rpx;height:65rpx;border-radius:50%;font-size:19px}.rule-icon.invite{background:#e8f2ff;color:#267bf0}.rule-icon.done{background:#e2f8ef;color:#0ca66b}.rule-icon.reward{background:#fff1d8;color:#ffab00}.rule-step>text:last-child{display:block;margin-top:10rpx;color:#172d4c;font-size:13px;line-height:1.45}.flow-arrow{padding-top:23rpx;color:#a0afc3;font-size:21px}.rule-note{margin-top:21rpx;padding:17rpx 19rpx;border-radius:15rpx;background:#f3f7fc;color:#6e7e98;font-size:13px;line-height:1.45}.bind-card{padding-bottom:24rpx}.bind-icon{font-size:17px}.where-button{height:52rpx;margin:0;padding:0 17rpx;border:1rpx solid #f2ad12;border-radius:999rpx;background:#fff;color:#df9700;font-size:13px;line-height:50rpx}.pending-tip{display:block;margin-top:16rpx;padding:13rpx 16rpx;border-radius:13rpx;background:#fff8e5;color:#9b6500;font-size:13px;line-height:1.45}.bind-row{display:flex;gap:12rpx;margin-top:19rpx}.bind-input{min-width:0;flex:1;height:76rpx;padding:0 19rpx;box-sizing:border-box;border:1rpx solid #ceddeb;border-radius:17rpx;background:#fcfdff;color:#10213a;font-size:14px}.bind-button{width:175rpx;height:76rpx;margin:0;border:0;border-radius:17rpx;background:linear-gradient(135deg,#2477ed,#1c68de);color:#fff;font-size:14px;line-height:76rpx}.bind-help{display:block;margin-top:11rpx;color:#74819a;font-size:13px}.progress-card{padding-bottom:25rpx}.history-button{height:52rpx;margin:0;padding:0;border:0;background:transparent;color:#0da767;font-size:13px;line-height:52rpx}.progress-summary{display:grid;grid-template-columns:repeat(3,1fr);margin-top:23rpx;padding:22rpx 0;border:1rpx dashed #cddbeb;border-radius:17rpx;background:#fff}.progress-summary>view{position:relative;display:flex;align-items:center;flex-direction:column;padding:0 9rpx;border-right:1rpx solid #dbe5ef;text-align:center}.progress-summary>view:last-child{border-right:0}.progress-summary strong,.progress-summary small,.progress-summary em{display:block}.progress-icon-large{height:42rpx;color:#2679ea;font-size:21px;font-style:normal;line-height:42rpx}.done-color{color:#0ca66b}.reward-color{color:#ffab00}.progress-summary strong{margin-top:4rpx;font-size:19px}.progress-summary small{margin-top:4rpx;color:#233d60;font-size:13px}.progress-summary em{margin-top:8rpx;color:#71819a;font-size:12px;font-style:normal;line-height:1.45}.friends-list{margin-top:18rpx}.friend-row{display:flex;align-items:center;gap:12rpx;padding:15rpx 0;border-top:1rpx solid #e5edf4}.friend-avatar{display:flex;width:48rpx;height:48rpx;align-items:center;justify-content:center;border-radius:16rpx;background:#e8f3ff;color:#2478e8;font-weight:900}.friend-main{min-width:0;flex:1}.friend-main text,.friend-main small{display:block}.friend-main text{font-size:13px;font-weight:750}.friend-main small{margin-top:4rpx;color:#75849a;font-size:12px}.status-pill{padding:6rpx 10rpx;border-radius:999rpx;background:#e5f8ee;color:#09865c;font-size:12px}.compliance-card{display:flex;gap:14rpx;margin-top:20rpx;padding:23rpx 27rpx;border:1rpx solid #dbe8f0;border-radius:26rpx;background:#f6fbff}.compliance-icon{display:flex;width:43rpx;height:43rpx;flex:none;align-items:center;justify-content:center;color:#2478e8;font-size:19px}.compliance-title,.compliance-copy{display:block}.compliance-title{font-size:15px;font-weight:850}.compliance-copy{margin-top:7rpx;color:#70819a;font-size:13px;line-height:1.55}.link{color:#0ca66b}.wechat-share{height:0;margin:0;overflow:hidden;opacity:0}@keyframes spin{to{transform:rotate(360deg)}}@media screen and (max-width:360px){.page{padding-left:20rpx;padding-right:20rpx}.recommend-hero{margin-left:-20rpx;margin-right:-20rpx}.hero-copy{padding-left:31rpx;padding-right:31rpx}.invite-panel{padding-left:22rpx;padding-right:22rpx}.code{font-size:21px}.reward-badge{right:20rpx}.bind-button{width:160rpx}.progress-summary em{font-size:10px}}
.invite-code{display:block}
.code-line{max-width:calc(100% - 145rpx)}
.code{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.recommend-hero-art{position:absolute;right:0;bottom:0;width:310rpx;height:310rpx}
/* ===== 新视觉（设计屏 48 referrals） ===== */
.referrals-page{--green:#0BBF63;--green-deep:#08A152;--green-soft:#E7F9EF;--t1:#191B1F;--t2:#767C85;--t3:#AEB3BB;--line:#EDEFEF;background:#F4F6F4!important;padding:0 0 150rpx;color:var(--t1)}
.ref-hero{position:relative;display:flex;margin:20rpx 24rpx 0;border-radius:24rpx;padding:36rpx 24rpx;background:linear-gradient(135deg,#12D978,#08A152);color:#fff;overflow:hidden}
.ref-hero::before{content:'';position:absolute;width:300rpx;height:300rpx;border-radius:50%;background:rgba(255,255,255,.10);top:-120rpx;right:-80rpx}
.hero-col{flex:1;position:relative}
.hero-col+.hero-col{border-left:2rpx solid rgba(255,255,255,.28)}
.hero-num{display:block;text-align:center;font-size:50rpx;font-weight:800;line-height:1.2}
.hero-label{display:block;text-align:center;margin-top:8rpx;font-size:22rpx;opacity:.9}
.invite-panel{margin:20rpx 24rpx 0;border-radius:24rpx;border:0;box-shadow:none;background:#fff;padding:28rpx}
.invite-code{display:block}
.code-label{color:var(--t2);font-size:24rpx}
.code-line{display:flex;align-items:center;gap:16rpx;margin-top:10rpx;max-width:calc(100% - 150rpx)}
.code{color:var(--t1);font-size:38rpx;font-weight:800;letter-spacing:2rpx}
.copy-icon{width:auto;height:52rpx;line-height:52rpx;padding:0 22rpx;margin:0;border:2rpx solid var(--green-soft);border-radius:26rpx;background:#fff;color:var(--green-deep);font-size:22rpx;font-weight:600}
.copy-icon::after{border:0}
.reward-badge{top:28rpx;right:28rpx;width:124rpx;height:96rpx;border:0;border-radius:20rpx;background:var(--green-soft)}
.reward-badge text{font-size:34rpx;font-weight:800;color:var(--green-deep)}
.reward-badge small{font-size:20rpx;color:var(--green-deep)}
.invite-actions{margin-top:28rpx;gap:16rpx}
.invite-actions button{height:80rpx;border-radius:40rpx;font-size:27rpx;font-weight:700;line-height:80rpx}
.poster-button{background:linear-gradient(135deg,#12D978,#08A152);box-shadow:0 8rpx 18rpx rgba(11,191,99,.22);color:#fff}
.poster-button::after,.link-button::after{border:0}
.link-button{border:2rpx solid var(--green-soft);background:#fff;color:var(--green-deep)}
.section-card{margin:20rpx 24rpx 0;border:0;border-radius:24rpx;background:#fff;box-shadow:none;padding:28rpx}
.section-icon{color:var(--green-deep)}
.section-title{color:var(--t1)}
.section-subtitle,.section-kicker{color:var(--t2)}
.rules-card{padding-bottom:24rpx}
.rule-icon.invite,.rule-icon.done,.rule-icon.reward{background:var(--green-soft);color:var(--green-deep)}
.flow-arrow{color:var(--t3)}
.rule-step>text:last-child{color:var(--t2)}
.rule-note{border-radius:14rpx;background:var(--green-soft);color:var(--green-deep)}
.bind-input{border-color:var(--line);background:#F7F9F8;color:var(--t1)}
.bind-button{background:linear-gradient(135deg,#12D978,#08A152);border-radius:17rpx}
.bind-button::after{border:0}
.where-button{border-color:var(--green-soft);color:var(--green-deep)}
.pending-tip{background:var(--green-soft);color:var(--green-deep)}
.bind-help{color:var(--t3)}
.progress-summary{border-color:var(--line);background:#FAFBFA;border-radius:16rpx}
.progress-summary>view{border-color:#EEF0EF}
.progress-icon-large{color:var(--green-deep)}
.progress-icon-large.done-color{color:var(--green-deep)}
.progress-icon-large.reward-color{color:#FF9A3D}
.progress-summary small{color:var(--t2)}
.progress-summary em{color:var(--t3)}
.history-button{color:var(--green-deep)}
.friend-avatar{background:var(--green-soft);color:var(--green-deep);border-radius:50%}
.friend-main text{color:var(--t1)}
.friend-main small{color:var(--t3)}
.status-pill{background:var(--green-soft);color:var(--green-deep)}
.compliance-card{border:0;border-radius:24rpx;background:var(--green-soft)}
.compliance-icon{color:var(--green-deep)}
.compliance-title{color:var(--green-deep)}
.compliance-copy{color:var(--green-deep);opacity:.85}
.link{color:var(--green-deep);text-decoration:underline}
.state-card{border:0;border-radius:24rpx;color:var(--t2)}
.spinner{border-color:var(--green-soft);border-top-color:var(--green)}
.state-icon{background:#FDECEC;color:#D4380D}
</style>
