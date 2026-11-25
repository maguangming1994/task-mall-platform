<template>
  <view class="page">
    <ModulePlaceholder v-if="modules.resolved && !moduleEnabled" code="module_community" />
    <view v-else-if="!modules.resolved" class="mp-skeleton">
      <view class="tm-skeleton" style="height: 200rpx; border-radius: 24rpx; margin: 24rpx 28rpx;" />
      <view class="tm-skeleton" style="height: 320rpx; border-radius: 24rpx; margin: 20rpx 28rpx;" />
    </view>
    <template v-else>
    <view class="subnav">
      <view class="back" @click="goBack"><text>‹</text></view>
      <text class="nav-title">活动详情</text>
      <view class="nav-action" @click="share"><text>↗</text></view>
    </view>
    <view v-if="loading" class="card tm-skeleton loading" />
    <view v-else-if="error" class="state-card"><view class="state-icon">!</view><text class="state-title">详情加载失败</text><text class="state-copy">{{ error }}</text><button class="state-btn" @click="load">重新加载</button></view>
    <template v-else-if="activity">
      <view class="act-hero">
        <image v-if="firstImage" class="hero-img" :src="firstImage" mode="aspectFill" />
        <view v-else class="hero-img fallback"><text>{{ activity.category_label.slice(0,1) }}</text></view>
        <text class="hero-cnt">已报名 {{ activity.participant_count }} 人</text>
        <view class="hero-mask">
          <view class="hero-badges"><text class="tag tag-green">{{ activity.category_label }}</text><text :class="['tag', `tag-${statusTone}`]">{{ statusLabel }}</text></view>
          <text class="hero-title">{{ activity.title }}</text>
        </view>
      </view>

      <view class="card info-card">
        <view class="info-row"><text class="info-ic">◷</text><text class="info-label">活动时间</text><text class="sp" /><text class="v">{{ formatStart(activity.starts_at) }}</text></view>
        <view class="info-row"><text class="info-ic">⌖</text><text class="info-label">集合地点</text><text class="sp" /><text class="v">{{ activity.location || '校内地点待定' }}</text></view>
        <view class="info-row"><text class="info-ic">♙</text><text class="info-label">报名情况</text><text class="sp" /><text class="v">{{ activity.participant_count }} / {{ activity.participant_limit }} 人 · 余 {{ Math.max(0,activity.participant_limit-activity.participant_count) }} 名额</text></view>
      </view>

      <view class="card participants-card"><view class="sec-title"><text class="sec-name">参加的同学</text><text class="more">{{ activity.participant_count }} 人 · {{ activity.creator.nickname }} 发起</text></view><view class="participant-list"><view v-for="person in activity.participant_preview" :key="`${person.role}-${person.id}`" class="participant"><view class="participant-avatar">{{ avatarText(person.nickname) }}</view><text class="participant-name">{{ person.nickname }}</text><text v-if="person.role==='creator'" class="participant-role">发起人</text></view><text v-if="!activity.participant_preview.length" class="participant-empty">还没有同学加入</text></view></view>

      <view class="card details-card"><view class="sec-title"><text class="sec-name">活动详情</text></view><text class="desc">{{ activity.details }}</text><view class="rule-note"><text class="rule-title">安全提醒</text><text class="rule-copy">请选择校园公共区域见面；不要提前向陌生人转账；如活动内容与描述不符，请及时退出并举报。</text></view></view>

      <view v-if="activity.images.length>1" class="card"><view class="sec-title"><text class="sec-name">活动图片</text><text class="more">{{ activity.images.length }} 张</text></view><view class="image-grid"><image v-for="(item,index) in activity.images" :key="item.file_id" :src="communityActivityImageUrl(item)" mode="aspectFill" @click="preview(index)" /></view></view>

      <view v-if="activity.review_note" class="review-note"><text class="review-title">审核说明</text><text class="review-copy">{{ activity.review_note }}</text></view>
      <view class="bottom-space" />
      <view class="action-bar"><button class="ab-ghost" :disabled="submitting" @click="share">分享给同学</button><button v-if="activity.is_creator" class="ab-main" disabled>我发起的</button><button v-else-if="activity.joined_by_me" class="ab-danger" :disabled="submitting" :loading="submitting" @click="leave">退出活动</button><button class="ab-main" v-else :disabled="submitting || !activity.can_join" :loading="submitting" @click="join">{{ joinLabel }}</button></view>
    </template>
    <view v-else class="state-card"><view class="state-icon">活</view><text class="state-title">没有找到这个活动</text><text class="state-copy">活动可能已结束或被下线，请返回活动大厅查看其他活动。</text><button class="state-btn" @click="goBack">返回活动大厅</button></view>
    </template>
  </view>
</template>

<script setup lang="ts">
import { formatDateTime as formatStart } from '@/utils/dateTime'
import { computed, ref } from 'vue'
import { onLoad } from '@dcloudio/uni-app'
import { communityActivityImageUrl, getCommunityActivity, joinCommunityActivity, leaveCommunityActivity, type CommunityActivity } from '@/api/community'
import { useAuthStore } from '@/stores/auth'
import { navigateToLogin } from '@/utils/authRedirect'
import ModulePlaceholder from '@/components/ModulePlaceholder.vue'
import { useModulesStore } from '@/stores/modules'
import { DEFAULT_WECHAT_SHARE_IMAGE, enableWechatShare } from '@/platform/wechatJssdk'

const modules=useModulesStore()
const moduleEnabled=computed(()=>modules.isEnabled('module_community'))

const auth=useAuthStore()
const id=ref('')
const activity=ref<CommunityActivity|null>(null)
const loading=ref(true)
const submitting=ref(false)
const error=ref('')
const firstImage=computed(()=>activity.value?.images?.[0]?communityActivityImageUrl(activity.value.images[0]):'')
const statusLabel=computed(()=>activity.value?.status==='approved'&&activity.value.remaining_slots===0?'已满员':({pending_review:'审核中',approved:'报名中',closed:'已关闭',rejected:'未通过'} as Record<string,string>)[activity.value?.status||'']||'处理中')
const statusTone=computed(()=>({approved:'open',pending_review:'pending',rejected:'danger',closed:'muted'} as Record<string,string>)[activity.value?.status||'']||'pending')
const joinLabel=computed(()=>activity.value?.remaining_slots===0?'已经满员':activity.value?.status!=='approved'?'暂不可报名':'立即报名')

onLoad(async (options?:Record<string,string|undefined>)=>{await modules.ensureReady();if(!moduleEnabled.value){loading.value=false;return}id.value=options?.id||'';void load()})
async function load(){if(!id.value){error.value='缺少组团编号';loading.value=false;return}loading.value=true;error.value='';try{activity.value=await getCommunityActivity(id.value,auth.accessToken||undefined);if(activity.value)void enableWechatShare({title:activity.value.title||'校园组团',desc:activity.value.details||'一起参加校园组团，名额有限。',link:`/pages/community-activity-detail/index?id=${encodeURIComponent(activity.value.id)}`,imageUrl:firstImage.value||DEFAULT_WECHAT_SHARE_IMAGE})}catch(cause){error.value=message(cause)}finally{loading.value=false}}
function ensureAuth(){if(auth.isAuthenticated)return true;uni.showModal({title:'登录后报名',content:'登录后即可参加校园组团并接收活动消息。',confirmText:'去登录',success:result=>{if(result.confirm)navigateToLogin()}});return false}
async function join(){if(!ensureAuth()||!auth.accessToken||!activity.value?.can_join)return;submitting.value=true;try{activity.value=await joinCommunityActivity(auth.accessToken,id.value);uni.showToast({title:'报名成功',icon:'success'})}catch(cause){toast(cause)}finally{submitting.value=false}}
async function leave(){if(!auth.accessToken||!activity.value)return;const result=await uni.showModal({title:'退出这个组团？',content:'退出后名额会释放给其他同学。',confirmText:'确认退出'});if(!result.confirm)return;submitting.value=true;try{activity.value=await leaveCommunityActivity(auth.accessToken,id.value);uni.showToast({title:'已退出组团',icon:'success'})}catch(cause){toast(cause)}finally{submitting.value=false}}
function preview(current:number){if(!activity.value)return;const urls=activity.value.images.map(communityActivityImageUrl).filter(Boolean);if(urls.length)uni.previewImage({urls,current:urls[current]})}
function share(){
  // #ifdef H5
  if (typeof navigator !== 'undefined' && /MicroMessenger/i.test(navigator.userAgent)) {
    uni.showModal({ title: '分享组团', content: '请点击右上角「···」，选择「发送给朋友」或「分享到朋友圈」。', showCancel: false })
    return
  }
  // #endif
  uni.showModal({ title: '分享组团', content: '当前环境暂不支持直接分享卡片，可复制页面链接邀请同学。', showCancel: false })
}
function goBack(){const pages=getCurrentPages();pages.length>1?uni.navigateBack():uni.redirectTo({url:'/pages/community-activities/index'})}
function avatarText(value:string){return(value||'同').slice(0,1)}
function message(value:unknown){return value instanceof Error?value.message:'操作失败，请稍后重试'}
function toast(value:unknown){uni.showToast({title:message(value),icon:'none'})}
</script>

<style scoped>
.page{--ink:#111827;min-height:100vh;padding:calc(22rpx + env(safe-area-inset-top)) 24rpx 0;box-sizing:border-box;background:linear-gradient(180deg,#e8ffd2 0,#f8fff2 420rpx,#f7f8fa 900rpx);color:var(--ink)}.topbar,.badges,.creator,.stats-card,.section-title,.info-row,.footer{display:flex;align-items:center}.topbar{justify-content:space-between}.topbar>text{font-size:17px;font-weight:950}.back,.share{display:flex;align-items:center;justify-content:center;width:60rpx;height:60rpx;border:3rpx solid var(--ink);border-radius:19rpx;background:#fff;box-shadow:0 5rpx 0 var(--ink);font-size:21px;font-weight:950}.detail-card{margin-top:24rpx;padding:23rpx;border:3rpx solid var(--ink);border-radius:25rpx;background:#fff;box-shadow:0 8rpx 0 rgba(17,24,39,.12)}.detail-card.hero{padding:0;overflow:hidden}.cover{width:100%;height:360rpx;border-bottom:3rpx solid var(--ink)}.cover.fallback{display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,#ffe33d,#ff6f88)}.cover.fallback text{display:flex;align-items:center;justify-content:center;width:130rpx;height:130rpx;border:4rpx solid var(--ink);border-radius:42rpx;background:#fff;font-size:30px;font-weight:950;box-shadow:0 10rpx 0 var(--ink)}.hero-content{padding:22rpx}.badges{gap:10rpx}.badges text{padding:5rpx 11rpx;border-radius:999rpx;background:#eceff2;font-size:10px;font-weight:900}.badges .status-open{background:#c9f7e4;color:#087952}.badges .status-full{background:#ffe5ac;color:#8d5900}.badges .status-pending{background:#dceeff;color:#175b9c}.badges .status-danger{background:#ffe0e5;color:#b7293d}.badges .status-muted{background:#e8eaed;color:#67717f}.title{display:block;margin-top:15rpx;font-size:21px;font-weight:950;line-height:1.35}.creator{gap:8rpx;margin-top:18rpx}.creator view{display:flex;align-items:center;justify-content:center;width:44rpx;height:44rpx;border:2rpx solid var(--ink);border-radius:50%;background:#ffcb6d;font-size:12px;font-weight:950}.creator>text:nth-child(2){font-size:13px;font-weight:850}.creator>text:last-child{padding:4rpx 8rpx;border-radius:8rpx;background:#edf0f2;color:#6e7785;font-size:10px}.stats-card{display:grid;grid-template-columns:repeat(3,1fr);padding:0}.stats-card view{padding:22rpx;text-align:center}.stats-card view+view{border-left:2rpx solid var(--ink)}.stats-card strong,.stats-card text{display:block}.stats-card strong{font-size:19px}.stats-card text{margin-top:4rpx;color:#747d8b;font-size:12px}.section-title{justify-content:space-between;padding-bottom:17rpx;border-bottom:2rpx solid var(--ink)}.section-title text:first-child{font-size:15px;font-weight:950}.section-title text:last-child{color:#808895;font-size:10px;font-weight:900;letter-spacing:2rpx}.info-row{gap:15rpx;padding:18rpx 0}.info-row+.info-row{border-top:1rpx dashed #ced4dc}.info-icon{display:flex;align-items:center;justify-content:center;width:58rpx;height:58rpx;flex:none;border:2rpx solid var(--ink);border-radius:18rpx;font-size:13px;font-weight:950}.info-icon.yellow{background:#ffe13c}.info-icon.blue{background:#63d4ff}.info-icon.green{background:#64e7b5}.info-row>view:last-child text{display:block}.info-row>view:last-child text:first-child{font-size:12px;color:#7a8390}.info-row>view:last-child text:last-child{margin-top:4rpx;font-size:13px;font-weight:900}.details{display:block;padding:20rpx 2rpx;color:#414a59;font-size:14px;line-height:1.75;white-space:pre-wrap}.rule-note{padding:17rpx;border:2rpx solid var(--ink);border-radius:16rpx;background:#fff3b2}.rule-note text{display:block}.rule-note text:first-child{font-size:13px;font-weight:950}.rule-note text:last-child{margin-top:6rpx;color:#5c6573;font-size:12px;line-height:1.55}.image-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:9rpx;margin-top:17rpx}.image-grid image{width:100%;height:220rpx;border:2rpx solid var(--ink);border-radius:15rpx}.review-note{margin-top:22rpx;padding:19rpx;border:2rpx solid #e23b51;border-radius:17rpx;background:#fff0f2;color:#b9273b}.review-note text{display:block}.review-note text:first-child{font-size:13px;font-weight:950}.review-note text:last-child{margin-top:6rpx;font-size:12px}.state-card{margin-top:28rpx;padding:60rpx 25rpx;text-align:center;border:3rpx solid var(--ink);border-radius:25rpx;background:#fff}.state-card>view{display:flex;align-items:center;justify-content:center;width:86rpx;height:86rpx;margin:auto;border:3rpx solid var(--ink);border-radius:27rpx;background:#ff7183;box-shadow:0 7rpx 0 var(--ink);font-size:21px;font-weight:950}.state-card>text{display:block}.state-card>text:nth-child(2){margin-top:22rpx;font-size:15px;font-weight:950}.state-card>text:nth-child(3){margin-top:7rpx;color:#747d8a;font-size:13px}.state-card button{height:62rpx;margin-top:19rpx;border:2rpx solid var(--ink);border-radius:999rpx;background:#39dfa1;font-size:13px;font-weight:950;line-height:58rpx}.state-card button::after,.footer button::after{border:0}.loading{height:650rpx}.bottom-space{height:150rpx}.footer{position:fixed;z-index:20;right:0;bottom:0;left:0;gap:12rpx;padding:17rpx 24rpx calc(17rpx + env(safe-area-inset-bottom));border-top:3rpx solid var(--ink);background:#fff}.footer button{height:72rpx;margin:0;border:3rpx solid var(--ink);border-radius:999rpx;font-size:13px;font-weight:950;line-height:66rpx;box-shadow:0 6rpx 0 var(--ink)}.footer .secondary{width:35%;background:#fff}.footer .primary,.footer .danger{flex:1;background:#23dd9c}.footer .danger{background:#ff6d7f}.footer button[disabled]{opacity:.45}@media screen and (min-width:768px){.page{width:750rpx;margin:auto}.footer{left:50%;width:750rpx;box-sizing:border-box;transform:translateX(-50%)}}
.participant-list{display:flex;gap:18rpx;margin-top:20rpx;overflow-x:auto}.participant{flex:none;width:96rpx;text-align:center}.participant>view{display:flex;align-items:center;justify-content:center;width:62rpx;height:62rpx;margin:auto;border-radius:22rpx;background:#e4f5f1;color:#167568;font-size:14px;font-weight:900}.participant>text{display:block;margin-top:7rpx;overflow:hidden;font-size:12px;font-weight:750;text-overflow:ellipsis;white-space:nowrap}.participant>text:last-child:not(:first-child){color:#d46a22;font-size:10px}.participant-empty{color:#747d8a;font-size:13px}
.page{background:radial-gradient(circle at 92% 0,rgba(55,205,212,.18),transparent 28%),linear-gradient(180deg,#eefaff 0,#f7fbfd 44%,#f4f8fa 100%)!important;color:#173b53!important}.topbar .back,.topbar .share{border:1rpx solid #cfe1e9!important;background:#fff!important;box-shadow:none!important;color:#1475c5}.detail-card,.stats-card,.state-card{border:1rpx solid #dce9ef!important;box-shadow:0 12rpx 30rpx rgba(34,78,103,.075)!important;background:rgba(255,255,255,.97)!important}.detail-card.hero{background:#fff!important}.cover{border-bottom:1rpx solid #dce9ef!important}.cover.fallback{background:linear-gradient(145deg,#dff5ff,#e7faef)!important}.cover.fallback text{border:0;background:rgba(255,255,255,.82);box-shadow:none;color:#1475c5}.creator view,.info-icon{border:0!important;background:#e7f8fb!important;color:#1475c5}.stats-card view+view{border-left:1rpx solid #e1edf1!important}.section-title{border-bottom:1rpx solid #e1edf1!important}.rule-note{border:1rpx solid #f0dfb2!important;background:#fff8e8!important}.image-grid image{border:1rpx solid #dce9ef!important}.review-note{border:1rpx solid #f0c8c1!important;background:#fff4f1!important}.state-card>view{border:0!important;background:#e8f7fb!important;box-shadow:none!important;color:#1477c7}.state-card button{border:1rpx solid #b9dbe5!important;background:#fff!important;color:#1475c5!important;box-shadow:none!important}.loading{background:linear-gradient(100deg,#e6f1f5 30%,#f8fcfd 45%,#e6f1f5 60%)!important;background-size:220% 100%;animation:community-activity-detail-shimmer 1.2s ease-in-out infinite}.footer{border-top:1rpx solid #dce9ef!important;background:rgba(255,255,255,.95)!important;box-shadow:0 -10rpx 28rpx rgba(31,76,101,.08)}.footer button{border:1rpx solid #cfe1e9!important;box-shadow:none!important}.footer .primary{border:0!important;background:linear-gradient(135deg,#08a967,#0abca1)!important}.footer .danger{border:0!important;background:#fff0ed!important;color:#b9382d!important}.footer button[disabled]{opacity:.48;box-shadow:none!important}@keyframes community-activity-detail-shimmer{0%{background-position:100% 0}100%{background-position:-100% 0}}
/* ===== 新视觉（设计屏 43 act-detail） ===== */
.page{--green:#0BBF63;--green-deep:#08A152;--green-soft:#E7F9EF;--t1:#191B1F;--t2:#767C85;--t3:#AEB3BB;--line:#EDEFEF;--price:#FF2442;background:#F4F6F4!important;padding:0 0 30rpx;color:var(--t1)}
.subnav{display:flex;align-items:center;padding:calc(var(--status-bar-height,20px) + 8rpx) 20rpx 8rpx;background:#F4F6F4}
.subnav .back{width:64rpx;height:64rpx;display:flex;align-items:center;justify-content:center;font-size:44rpx;color:var(--t1);line-height:1}
.nav-title{flex:1;text-align:center;font-size:32rpx;font-weight:800}
.nav-action{width:64rpx;height:64rpx;display:flex;align-items:center;justify-content:center;font-size:36rpx;color:var(--t2)}
.act-hero{position:relative;height:420rpx;margin:12rpx 24rpx 0;border-radius:24rpx;overflow:hidden;background:#F2F4F3}
.hero-img{width:100%;height:100%;display:block}
.hero-img.fallback{display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,#12D978,#08A152);color:#fff;font-size:56rpx;font-weight:800}
.hero-mask{position:absolute;left:0;right:0;bottom:0;top:0;background:linear-gradient(180deg,rgba(0,0,0,.05) 30%,rgba(0,0,0,.55));padding:24rpx;display:flex;flex-direction:column;justify-content:flex-end}
.hero-cnt{position:absolute;top:24rpx;left:24rpx;z-index:2;height:44rpx;padding:0 18rpx;border-radius:22rpx;background:rgba(0,0,0,.38);color:#fff;font-size:20rpx;display:flex;align-items:center}
.hero-badges{display:flex;gap:12rpx;margin-bottom:14rpx}
.hero-badges .tag{font-size:20rpx;padding:4rpx 14rpx;border-radius:8rpx;background:rgba(255,255,255,.85);color:var(--t2)}
.hero-badges .tag-green{background:var(--green-soft);color:var(--green-deep)}
.hero-badges .tag-open{background:var(--green-soft);color:var(--green-deep)}
.hero-badges .tag-pending{background:#EAF2FE;color:#2F7DE1}
.hero-badges .tag-danger{background:#FDECEC;color:#D4380D}
.hero-badges .tag-muted{background:rgba(255,255,255,.85);color:var(--t3)}
.hero-title{color:#fff;font-size:34rpx;font-weight:800;line-height:1.4}
.card{background:#fff;border-radius:24rpx;margin:20rpx 24rpx 0;overflow:hidden}
.card.loading{margin:20rpx 24rpx 0;height:520rpx;background:linear-gradient(100deg,#F0F2F0 30%,#E8EBE8 45%,#F0F2F0 60%)!important;background-size:220% 100%;animation:community-activity-detail-shimmer 1.2s ease-in-out infinite}
.info-card{padding:2rpx 28rpx}
.info-row{display:flex;align-items:center;gap:14rpx;padding:24rpx 0;border-bottom:2rpx solid #F5F6F6;font-size:26rpx;color:#3A3D43}
.info-row:last-child{border-bottom:0}
.info-ic{color:var(--green);font-size:28rpx;width:36rpx;text-align:center}
.info-label{color:var(--t1)}
.sp{flex:1}
.v{color:var(--t2);font-size:23rpx;text-align:right}
.sec-title{display:flex;align-items:center;justify-content:space-between;padding:26rpx 28rpx 18rpx}
.sec-name{font-size:30rpx;font-weight:700;color:var(--t1);display:flex;align-items:center;gap:14rpx}
.sec-name::before{content:'';width:8rpx;height:28rpx;border-radius:4rpx;background:var(--green)}
.more{font-size:22rpx;color:var(--t3)}
.participant-list{display:flex;gap:28rpx;padding:0 28rpx 28rpx;overflow-x:auto}
.participant{flex:none;width:104rpx;text-align:center}
.participant-avatar{width:80rpx;height:80rpx;margin:0 auto;border-radius:24rpx;background:var(--green-soft);color:var(--green-deep);display:flex;align-items:center;justify-content:center;font-size:30rpx;font-weight:800}
.participant-name{display:block;margin-top:12rpx;font-size:23rpx;color:var(--t1);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.participant-role{font-size:18rpx;color:var(--green-deep)}
.participant-empty{color:var(--t3);font-size:24rpx;padding:0 28rpx 28rpx}
.desc{display:block;padding:0 28rpx 24rpx;font-size:27rpx;line-height:1.75;color:#3A3D43;white-space:pre-wrap}
.rule-note{margin:0 28rpx 28rpx;padding:22rpx;border-radius:16rpx;background:var(--green-soft)}
.rule-title{display:block;font-size:25rpx;font-weight:700;color:var(--green-deep)}
.rule-copy{display:block;margin-top:8rpx;font-size:22rpx;line-height:1.6;color:var(--green-deep);opacity:.85}
.image-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:14rpx;padding:0 28rpx 28rpx}
.image-grid image{width:100%;height:230rpx;border-radius:16rpx}
.review-note{margin:20rpx 24rpx 0;padding:24rpx;border-radius:20rpx;background:#FFF4F1;border:2rpx solid #FADBD6}
.review-title{display:block;font-size:25rpx;font-weight:700;color:#B42318}
.review-copy{display:block;margin-top:8rpx;font-size:22rpx;color:#B42318}
.bottom-space{height:150rpx}
.action-bar{position:fixed;left:0;right:0;bottom:0;z-index:50;display:flex;align-items:center;gap:16rpx;padding:16rpx 24rpx calc(16rpx + env(safe-area-inset-bottom));background:#fff;border-top:2rpx solid var(--line)}
.action-bar button{height:84rpx;border-radius:42rpx;font-size:29rpx;font-weight:700;display:flex;align-items:center;justify-content:center;margin:0}
.action-bar button::after{border:0}
.ab-main{flex:1;background:linear-gradient(135deg,#12D978,#08A152);color:#fff;box-shadow:0 8rpx 18rpx rgba(11,191,99,.25)}
.ab-ghost{width:240rpx;background:#fff;color:var(--t2);border:2rpx solid var(--line)}
.ab-danger{flex:1;background:#FFF0ED;color:#B4382D;border:2rpx solid #FAD2CB}
.action-bar button[disabled]{opacity:.5}
.state-card{margin:24rpx;background:#fff;border-radius:24rpx;padding:90rpx 48rpx;display:flex;flex-direction:column;align-items:center}
.state-card .state-icon{width:96rpx;height:96rpx;border-radius:28rpx;background:var(--green-soft)!important;color:var(--green-deep)!important;display:flex;align-items:center;justify-content:center;font-size:40rpx;font-weight:800;border:0;box-shadow:none}
.state-title{margin-top:28rpx;font-size:30rpx;font-weight:700;color:var(--t1)}
.state-copy{margin-top:12rpx;font-size:24rpx;color:var(--t3);text-align:center;line-height:1.6}
.state-btn{margin-top:32rpx;height:72rpx;line-height:72rpx;padding:0 48rpx;border-radius:36rpx;background:#fff!important;color:var(--green-deep)!important;font-size:26rpx;border:2rpx solid var(--green-soft)!important;box-shadow:none}
.state-btn::after{border:0}
</style>
