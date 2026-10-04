<template>
  <view class="page">
    <ModulePlaceholder v-if="modules.resolved && !moduleEnabled" code="module_community_post" />
    <view v-else-if="!modules.resolved" class="mp-skeleton">
      <view class="tm-skeleton" style="height: 200rpx; border-radius: 24rpx; margin: 24rpx 28rpx;" />
      <view class="tm-skeleton" style="height: 320rpx; border-radius: 24rpx; margin: 20rpx 28rpx;" />
    </view>
    <template v-else>
    <view class="subnav">
      <view class="back" @click="goBack"><text>‹</text></view>
      <text class="nav-title">发布活动</text>
      <text class="nav-action" @click="showRules">规则</text>
    </view>

    <view class="card cat-card">
      <view class="sec-title"><text class="sec-name">活动主题</text><text class="more">同学可按主题快速筛选</text></view>
      <view class="cat-grid"><text v-for="(item,index) in categories" :key="item.code" :class="['cat',{on:form.category_code===item.code}]" :style="categoryStyle(item,index)" @click="form.category_code=item.code">{{ item.label }}</text></view>
    </view>

    <view class="form-card">
      <view class="frow">
        <text class="flabel">活动名称</text>
        <input v-model="form.title" maxlength="40" placeholder="一句话说清活动，如：秋季篮球 3V3 挑战赛" />
      </view>
      <view class="frow frow-block">
        <text class="flabel block-label">活动封面 <text class="hint">（建议 16:9，选填 1 张）</text></text>
        <view class="upload-wrap">
          <view v-if="image.previewUrl" class="upload-preview"><image :src="image.previewUrl" mode="aspectFill" /><text class="upload-del" @click="image={fileId:'',previewUrl:''}">×</text></view>
          <view v-else class="upload-add" @click="uploadImage"><text class="upload-plus">＋</text><text class="upload-text">{{ uploading ? '上传中…' : '上传封面' }}</text></view>
        </view>
      </view>
      <view class="frow">
        <text class="flabel">活动日期</text>
        <picker mode="date" :value="date" :start="today" @change="date=String($event.detail.value)"><view class="picker-val">{{ date }} <text class="chev">›</text></view></picker>
      </view>
      <view class="frow">
        <text class="flabel">开始时间</text>
        <picker mode="time" :value="time" @change="time=String($event.detail.value)"><view class="picker-val">{{ time }} <text class="chev">›</text></view></picker>
      </view>
      <view class="frow">
        <text class="flabel">活动地点</text>
        <input v-model="form.location" maxlength="80" placeholder="如：滨江校区 · 风雨操场" />
      </view>
      <view class="frow">
        <text class="flabel">人数上限</text>
        <text class="hint">2—100 人</text>
        <view class="stepper"><text class="step-btn" @click="changeLimit(-1)">−</text><input class="step-input" v-model.number="form.participant_limit" type="number" /><text class="step-btn step-plus" @click="changeLimit(1)">＋</text></view>
      </view>
      <view class="frow frow-top">
        <textarea v-model="form.details" maxlength="1000" placeholder="活动详情：流程安排、报名方式、注意事项…" />
      </view>
    </view>

    <view class="agreement" @click="agreed=!agreed"><view :class="{checked:agreed}">{{ agreed?'✓':'' }}</view><text>我已阅读并同意《校园社区规范》，确认活动信息真实，不发布校外引流、危险活动或违规交易。</text></view>
    <view v-if="submitError" class="error-box"><text class="error-title">提交未成功</text><text class="error-copy">{{ submitError }}</text><text class="error-sub">已填写内容会保留，请检查后重试。</text></view>
    <view class="bottom-space" />
    <view class="action-bar"><button class="ab-main" :loading="submitting" :disabled="submitting || uploading || !agreed" @click="submit">{{ submitting ? '提交中…' : agreed ? '提交审核' : '同意规范后提交' }}</button></view>
    </template>
  </view>
</template>

<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import { onLoad } from '@dcloudio/uni-app'
import { createCommunityActivity, listCommunityActivityCategories, type CommunityActivityCategory } from '@/api/community'
import { chooseAndUploadPrivateImage } from '@/api/upload'
import { useAuthStore } from '@/stores/auth'
import { redirectToLogin } from '@/utils/authRedirect'
import ModulePlaceholder from '@/components/ModulePlaceholder.vue'
import { useModulesStore } from '@/stores/modules'

const modules=useModulesStore()
const moduleEnabled=computed(()=>modules.isEnabled('module_community_post'))

const fallbackCategories:CommunityActivityCategory[]=[{code:'study',label:'自习'},{code:'movie',label:'电影'},{code:'dining',label:'聚餐'},{code:'carpool',label:'拼车'},{code:'group_buy',label:'拼单'},{code:'gaming',label:'游戏'},{code:'sports',label:'运动'},{code:'travel',label:'旅行'},{code:'other',label:'其他'}]
const tomorrow=new Date(Date.now()+86400000)
const auth=useAuthStore()
const categories=ref<CommunityActivityCategory[]>(fallbackCategories)
const today=formatDate(new Date())
const date=ref(formatDate(tomorrow))
const time=ref('18:30')
const form=reactive({category_code:'study',title:'',details:'',location:'',participant_limit:4})
const image=ref({fileId:'',previewUrl:''})
const uploading=ref(false)
const submitting=ref(false)
const agreed=ref(false)
const submitError=ref('')

onLoad(async()=>{ await modules.ensureReady(); if(!moduleEnabled.value)return; if(!auth.isAuthenticated){redirectToLogin();return} restoreDraft(); try{const result=await listCommunityActivityCategories();if(result.items.length)categories.value=result.items}catch{} })
function categoryStyle(item:CommunityActivityCategory,index:number){return{'--color':item.color||['#39c8ff','#ff6f9b','#ff9847','#ba8355','#ff5e71','#a35cff','#62d84d','#16cca2','#717985'][index%9]}}
function formatDate(value:Date){return`${value.getFullYear()}-${String(value.getMonth()+1).padStart(2,'0')}-${String(value.getDate()).padStart(2,'0')}`}
function changeLimit(delta:number){form.participant_limit=Math.min(100,Math.max(2,Number(form.participant_limit||2)+delta))}
function goBack(){const pages=getCurrentPages();pages.length>1?uni.navigateBack({delta:1}):uni.redirectTo({url:'/pages/community-activities/index'})}
async function uploadImage(){if(!auth.accessToken||uploading.value)return;uploading.value=true;try{image.value=await chooseAndUploadPrivateImage(auth.accessToken,'community')}catch(error){toast(error)}finally{uploading.value=false}}
function draftKey(){return'tm-community-activity-draft'}
function persist(){uni.setStorageSync(draftKey(),{...form,date:date.value,time:time.value,image:image.value})}
function restoreDraft(){const value=uni.getStorageSync(draftKey()) as Record<string,unknown>|null;if(!value||typeof value!=='object')return;form.category_code=String(value.category_code||form.category_code);form.title=String(value.title||'');form.details=String(value.details||'');form.location=String(value.location||'');form.participant_limit=Number(value.participant_limit||4);date.value=String(value.date||date.value);time.value=String(value.time||time.value);if(value.image&&typeof value.image==='object')image.value=value.image as {fileId:string;previewUrl:string}}
function validate(){if(!form.category_code)return'请选择组团主题';if(form.title.trim().length<4)return'组团标题至少需要 4 个字';if(form.details.trim().length<10)return'活动详情至少需要 10 个字';if(form.location.trim().length<2)return'请填写至少 2 个字的校园公开活动地点';const startsAt=new Date(`${date.value}T${time.value}:00`);if(Number.isNaN(startsAt.getTime())||startsAt.getTime()<=Date.now())return'活动时间必须晚于当前时间';if(form.participant_limit<2||form.participant_limit>100)return'总人数需在 2—100 人之间';if(!agreed.value)return'请先阅读并同意校园社区规范';return''}
async function submit(){const issue=validate();if(issue){uni.showToast({title:issue,icon:'none'});return}if(!auth.accessToken)return;submitting.value=true;submitError.value='';persist();try{await createCommunityActivity(auth.accessToken,{category_code:form.category_code,title:form.title.trim(),details:form.details.trim(),image_file_ids:image.value.fileId?[image.value.fileId]:[],location:form.location.trim(),starts_at:new Date(`${date.value}T${time.value}:00`).toISOString(),participant_limit:Number(form.participant_limit)});uni.removeStorageSync(draftKey());uni.showToast({title:'已提交审核',icon:'success'});setTimeout(()=>uni.redirectTo({url:'/pages/community-activities/index'}),600)}catch(error){submitError.value=message(error);persist()}finally{submitting.value=false}}
function showRules(){uni.showModal({title:'校园组团发布规范',content:'仅允许真实、安全、合法的校园活动；不得发布校外引流、博彩、危险挑战、无资质收费培训或私下交易；联系方式应在报名成功后通过站内方式交换。',showCancel:false})}
function message(value:unknown){return value instanceof Error?value.message:'操作失败，请稍后重试'}
function toast(value:unknown){uni.showToast({title:message(value),icon:'none'})}
</script>

<style scoped>
.page{--ink:#111827;min-height:100vh;padding:calc(22rpx + env(safe-area-inset-top)) 24rpx 0;box-sizing:border-box;background:linear-gradient(180deg,#fff1a9 0,#fffaf0 420rpx,#f8f8fa 900rpx);color:var(--ink)}.topbar,.step-card,.step-card>view:nth-child(2),.form-card label,.photo-row,.form-row,.counter,.agreement{display:flex;align-items:center}.topbar{justify-content:space-between;gap:15rpx}.topbar>view:nth-child(2){min-width:0;flex:1}.back,.help{display:flex;align-items:center;justify-content:center;width:60rpx;height:60rpx;flex:none;border:3rpx solid var(--ink);border-radius:19rpx;background:#fff;box-shadow:0 5rpx 0 var(--ink);font-weight:950}.back{font-size:24px}.help{font-size:15px}.title,.subtitle,.step-card text,.form-card label text,.photo-tip text,.row-title,.row-copy,.error-box text{display:block}.title{font-size:17px;font-weight:950}.subtitle{margin-top:3rpx;color:#6e7684;font-size:12px}.step-card{gap:14rpx;margin-top:30rpx}.step-number{display:flex;align-items:center;justify-content:center;width:54rpx;height:54rpx;border:3rpx solid var(--ink);border-radius:18rpx;background:#ffdc34;box-shadow:0 5rpx 0 var(--ink);font-size:14px;font-weight:950}.step-number.pink{background:#ff7d91}.step-number.blue{background:#59ceff}.step-card>view:nth-child(2){align-items:flex-start;flex-direction:column}.step-card>view:nth-child(2) text:first-child{font-size:15px;font-weight:950}.step-card>view:nth-child(2) text:last-child{margin-top:3rpx;color:#737c8a;font-size:12px}.category-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:11rpx;margin-top:18rpx}.category{display:flex;align-items:center;gap:10rpx;padding:15rpx 13rpx;border:2rpx solid var(--ink);border-radius:14rpx;background:#fff;box-shadow:0 5rpx 0 rgba(17,24,39,.13);font-size:13px;font-weight:900}.category i{display:block;width:8rpx;height:31rpx;border-radius:999rpx;background:var(--color)}.category.active{background:var(--ink);color:#fff;box-shadow:0 5rpx 0 var(--color)}.form-card{margin-top:18rpx;padding:22rpx;border:3rpx solid var(--ink);border-radius:24rpx;background:#fff;box-shadow:0 8rpx 0 rgba(17,24,39,.12)}.form-card label{justify-content:space-between;margin-bottom:9rpx}.form-card label:not(:first-child){margin-top:24rpx}.form-card label text:first-child{font-size:13px;font-weight:950}.form-card label text:last-child{color:#848c98;font-size:12px}.form-card i,.row-title i{color:#f13d52;font-style:normal}.form-card>input{height:72rpx;padding:0 17rpx;border:2rpx solid var(--ink);border-radius:15rpx;background:#fafbfc;font-size:14px;box-sizing:border-box}.form-card>textarea{width:100%;height:260rpx;padding:16rpx;border:2rpx solid var(--ink);border-radius:15rpx;background:#fafbfc;font-size:13px;line-height:1.6;box-sizing:border-box}.photo-row{align-items:flex-start;gap:16rpx;margin-top:22rpx;padding-top:20rpx;border-top:2rpx dashed #d8dde3}.photo,.photo-add{position:relative;display:flex;align-items:center;justify-content:center;width:150rpx;height:150rpx;flex:none;border:2rpx solid var(--ink);border-radius:18rpx;overflow:hidden}.photo image{width:100%;height:100%}.photo>text{position:absolute;right:7rpx;top:7rpx;display:flex;align-items:center;justify-content:center;width:36rpx;height:36rpx;border-radius:50%;background:var(--ink);color:#fff}.photo-add{flex-direction:column;background:#e6fff3;box-shadow:0 5rpx 0 var(--ink)}.photo-add>text{font-size:22px}.photo-add small{margin-top:4rpx;font-size:10px}.photo-tip{padding-top:13rpx}.photo-tip text:first-child{font-size:13px;font-weight:900}.photo-tip text:last-child{margin-top:8rpx;color:#737c8b;font-size:12px;line-height:1.55}.form-card.compact{padding:0 22rpx}.form-row{justify-content:space-between;gap:17rpx;padding:21rpx 0}.form-row+.form-row{border-top:2rpx solid #e1e4e8}.form-row>view:first-child{min-width:0;flex:1}.row-title{font-size:13px;font-weight:950}.row-copy{margin-top:4rpx;color:#7b8390;font-size:10px}.picker-value{padding:12rpx 14rpx;border:2rpx solid var(--ink);border-radius:13rpx;background:#fff4b5;font-size:13px;font-weight:850}.picker-value text{margin-left:8rpx}.form-row>input{width:270rpx;height:62rpx;padding:0 13rpx;text-align:right;border:2rpx solid var(--ink);border-radius:13rpx;font-size:13px;box-sizing:border-box}.counter{border:2rpx solid var(--ink);border-radius:13rpx;overflow:hidden}.counter text{display:flex;align-items:center;justify-content:center;width:58rpx;height:58rpx;background:#e9fff6;font-size:15px;font-weight:950}.counter input{width:76rpx;height:58rpx;text-align:center;border-right:2rpx solid var(--ink);border-left:2rpx solid var(--ink);font-size:14px;font-weight:900}.agreement{align-items:flex-start;gap:12rpx;margin-top:25rpx;padding:18rpx;border:2rpx solid var(--ink);border-radius:18rpx;background:#fff}.agreement>view{display:flex;align-items:center;justify-content:center;width:34rpx;height:34rpx;flex:none;border:2rpx solid var(--ink);border-radius:9rpx;background:#fff;font-size:13px;font-weight:950}.agreement>view.checked{background:#23db9b}.agreement text{color:#5f6877;font-size:12px;line-height:1.55}.error-box{margin-top:18rpx;padding:18rpx;border:2rpx solid #e44154;border-radius:16rpx;background:#fff0f2;color:#b92639}.error-box text:first-child{font-size:13px;font-weight:950}.error-box text:nth-child(2){margin-top:5rpx;font-size:13px}.error-box text:last-child{margin-top:5rpx;color:#737c89;font-size:12px}.bottom-space{height:150rpx}.footer{position:fixed;z-index:20;right:0;bottom:0;left:0;padding:18rpx 24rpx calc(18rpx + env(safe-area-inset-bottom));border-top:3rpx solid var(--ink);background:#fff}.footer button{height:76rpx;border:3rpx solid var(--ink);border-radius:999rpx;background:#ff5e72;color:var(--ink);font-size:14px;font-weight:950;line-height:70rpx;box-shadow:0 7rpx 0 var(--ink)}.footer button::after{border:0}@media screen and (min-width:768px){.page{width:750rpx;margin:auto}.footer{left:50%;width:750rpx;box-sizing:border-box;transform:translateX(-50%)}}
.page{background:radial-gradient(circle at 92% 0,rgba(55,205,212,.18),transparent 28%),linear-gradient(180deg,#eefaff 0,#f7fbfd 44%,#f4f8fa 100%)!important}.back,.help{border:1rpx solid #cfe1e9!important;background:#fff!important;box-shadow:none!important;color:#1475c5}.step-card,.form-card,.agreement{border:1rpx solid #dce9ef!important;box-shadow:0 12rpx 30rpx rgba(34,78,103,.075)!important;background:rgba(255,255,255,.97)!important}.step-number{border:0!important;background:linear-gradient(145deg,#1268c9,#19abc4)!important;box-shadow:0 7rpx 16rpx rgba(18,104,220,.16)!important;color:#fff}.step-number.pink{background:linear-gradient(145deg,#e99c87,#f28b9c)!important}.step-number.blue{background:linear-gradient(145deg,#1599ce,#27c4c5)!important}.category{border:1rpx solid #d7e6ed!important;background:#f7fbfc!important;box-shadow:none!important}.category.active{border-color:#36b4c2!important;background:#e7f8fb!important;color:#146f88!important;box-shadow:none!important}.form-card>input,.form-card>textarea,.form-row>input,.picker-value,.counter{border:1rpx solid #cfe1e9!important;background:#fbfdfe!important}.counter text{background:#e7f8fb!important;color:#1475c5}.photo,.photo-add{border:1rpx solid #cfe1e9!important}.photo-add{border-style:dashed!important;background:#eefafd!important;color:#1475c5!important;box-shadow:none!important}.agreement{background:#f1fbf8!important}.agreement>view{border:1rpx solid #b9dbe5!important}.agreement>view.checked{border-color:#08a967!important;background:#08a967!important;color:#fff}.error-box{border:1rpx solid #f0c8c1!important;background:#fff4f1!important}.footer{border-top:1rpx solid #dce9ef!important;background:rgba(255,255,255,.95)!important;box-shadow:0 -10rpx 28rpx rgba(31,76,101,.08)}.footer button{border:0!important;background:linear-gradient(135deg,#1268c9,#19a7c8)!important;box-shadow:0 8rpx 18rpx rgba(18,104,201,.16)!important;color:#fff!important}.footer button[disabled]{opacity:.5;box-shadow:none!important}
/* ===== 新视觉（设计屏 44 act-compose） ===== */
.page{--green:#0BBF63;--green-deep:#08A152;--green-soft:#E7F9EF;--t1:#191B1F;--t2:#767C85;--t3:#AEB3BB;--line:#EDEFEF;background:#F4F6F4!important;padding:0 0 30rpx;color:var(--t1)}
.subnav{display:flex;align-items:center;padding:calc(var(--status-bar-height,20px) + 8rpx) 20rpx 8rpx;background:#F4F6F4}
.subnav .back{width:64rpx;height:64rpx;display:flex;align-items:center;justify-content:center;font-size:44rpx;color:var(--t1);line-height:1}
.nav-title{flex:1;text-align:center;font-size:32rpx;font-weight:800}
.nav-action{font-size:26rpx;color:var(--t3);font-weight:600;padding:0 8rpx}
.card{background:#fff;border-radius:24rpx;margin:20rpx 24rpx 0;overflow:hidden}
.sec-title{display:flex;align-items:center;justify-content:space-between;padding:26rpx 28rpx 16rpx}
.sec-name{font-size:30rpx;font-weight:700;color:var(--t1);display:flex;align-items:center;gap:14rpx}
.sec-name::before{content:'';width:8rpx;height:28rpx;border-radius:4rpx;background:var(--green)}
.more{font-size:22rpx;color:var(--t3)}
.cat-grid{display:flex;flex-wrap:wrap;gap:16rpx;padding:0 28rpx 28rpx}
.cat{height:58rpx;line-height:58rpx;padding:0 28rpx;border-radius:29rpx;background:#F2F4F3;color:var(--t2);font-size:25rpx}
.cat.on{background:var(--green-soft);color:var(--green-deep);font-weight:700;box-shadow:inset 0 0 0 2rpx rgba(11,191,99,.18)}
.form-card{background:#fff;border-radius:24rpx;margin:20rpx 24rpx 0;padding:2rpx 28rpx}
.frow{display:flex;align-items:center;gap:16rpx;min-height:100rpx;padding:24rpx 0;border-bottom:2rpx solid #F5F6F6}
.frow:last-child{border-bottom:0}
.flabel{font-size:28rpx;font-weight:600;color:var(--t1);width:140rpx;flex:none}
.frow input{flex:1;min-width:0;font-size:27rpx;color:var(--t1)}
.frow .hint{font-size:22rpx;color:var(--t3);flex:none}
.frow-block{display:block;padding-bottom:28rpx}
.block-label{display:block;margin-bottom:12rpx}
.upload-wrap{padding-top:8rpx}
.upload-add{width:220rpx;height:150rpx;border-radius:18rpx;border:3rpx dashed #D7DBD8;background:#F7F9F8;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:6rpx}
.upload-plus{font-size:44rpx;color:var(--t3);line-height:1}
.upload-text{font-size:22rpx;color:var(--t3)}
.upload-preview{position:relative;width:320rpx;height:180rpx;border-radius:18rpx;overflow:hidden}
.upload-preview image{width:100%;height:100%}
.upload-del{position:absolute;right:10rpx;top:10rpx;width:44rpx;height:44rpx;border-radius:50%;background:rgba(0,0,0,.55);color:#fff;text-align:center;line-height:44rpx;font-size:28rpx}
.picker-val{font-size:27rpx;color:var(--t2)}
.chev{color:var(--t3);margin-left:6rpx}
.stepper{display:flex;align-items:center;gap:20rpx;margin-left:auto}
.step-btn{width:56rpx;height:56rpx;border-radius:50%;background:#F2F4F3;color:var(--t2);font-size:34rpx;display:flex;align-items:center;justify-content:center;line-height:1}
.step-plus{background:linear-gradient(135deg,#12D978,#08A152);color:#fff}
.step-input{width:80rpx;height:56rpx;text-align:center;font-size:30rpx;font-weight:700;color:var(--t1)}
.frow-top{display:block;padding:24rpx 0}
.frow-top textarea{width:100%;height:220rpx;font-size:27rpx;line-height:1.6;color:var(--t1)}
.agreement{display:flex;align-items:flex-start;gap:14rpx;margin:20rpx 24rpx 0;padding:24rpx;border-radius:20rpx;background:#fff}
.agreement>view{width:36rpx;height:36rpx;flex:none;border-radius:50%;border:2rpx solid var(--green)!important;background:#fff;display:flex;align-items:center;justify-content:center;color:#fff;font-size:22rpx}
.agreement>view.checked{background:var(--green)!important;border-color:var(--green)!important}
.agreement text{font-size:22rpx;color:var(--t2);line-height:1.6}
.error-box{margin:20rpx 24rpx 0;padding:24rpx;border-radius:20rpx;background:#FFF4F1;border:2rpx solid #FADBD6}
.error-title{display:block;font-size:26rpx;font-weight:700;color:#B42318}
.error-copy{display:block;margin-top:8rpx;font-size:25rpx;color:#B42318}
.error-sub{display:block;margin-top:8rpx;font-size:22rpx;color:var(--t2)}
.bottom-space{height:150rpx}
.action-bar{position:fixed;left:0;right:0;bottom:0;z-index:50;padding:16rpx 24rpx calc(16rpx + env(safe-area-inset-bottom));background:#fff;border-top:2rpx solid var(--line)}
.ab-main{width:100%;height:88rpx;border-radius:44rpx;background:linear-gradient(135deg,#12D978,#08A152);color:#fff;font-size:30rpx;font-weight:700;display:flex;align-items:center;justify-content:center;box-shadow:0 8rpx 18rpx rgba(11,191,99,.25)}
.ab-main::after{border:0}
.ab-main[disabled]{opacity:.55;box-shadow:none}
</style>
