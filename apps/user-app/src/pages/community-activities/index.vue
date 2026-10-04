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
      <text class="nav-title">校园活动</text>
      <text v-if="modules.isEnabled('module_community_post')" class="nav-action" @click="createActivity">发布</text>
    </view>

    <view class="act-banner">
      <text class="banner-title">校园活动季 · 参与赢积分</text>
      <text class="banner-copy">报名校园活动，做任务赚积分，好物好礼送到宿舍</text>
    </view>

    <scroll-view class="cat-chips" scroll-x :show-scrollbar="false"><view class="chips-inner"><text :class="['chip', { on: mode === 'all' }]" @click="selectMode('all')">发现活动</text><text :class="['chip', { on: mode === 'created' }]" @click="selectMode('created')">我发起的</text><text :class="['chip', { on: mode === 'joined' }]" @click="selectMode('joined')">我的参与</text></view></scroll-view>
    <scroll-view class="cat-chips chips-sub" scroll-x :show-scrollbar="false"><view class="chips-inner"><text :class="['chip', { on: !activeCategory }]" @click="selectCategory('')">全部分类</text><text v-for="item in categories" :key="item.code" :class="['chip', { on: activeCategory === item.code }]" @click="selectCategory(item.code)">{{ item.label }}</text></view></scroll-view>

    <view v-if="loading" class="card">
      <view v-for="i in 3" :key="i" class="act-card">
        <view class="cover tm-skeleton" />
        <view class="skel tm-skeleton" /><view class="skel short tm-skeleton" />
      </view>
    </view>
    <view v-else-if="error" class="state-card">
      <view class="state-icon">!</view>
      <text class="state-title">活动加载失败</text>
      <text class="state-copy">{{ error }}</text>
      <button class="state-btn" @click="load">重新加载</button>
    </view>
    <view v-else-if="activities.length" class="card">
      <view v-for="(activity,index) in activities" :key="activity.id" class="act-card" @click="openDetail(activity.id)">
        <image v-if="firstImage(activity)" class="cover" :src="firstImage(activity)" mode="aspectFill" />
        <view v-else class="cover placeholder" :style="{ background: accentColor(activity.category_code,index) }"><text>{{ activity.category_label.slice(0,1) }}</text></view>
        <text class="act-title">{{ activity.title }}</text>
        <view class="act-meta"><text class="meta-ic">◷</text><text class="meta-text">{{ formatStart(activity.starts_at) }} · {{ activity.location || '校内地点待定' }}</text></view>
        <view class="act-foot">
          <text class="tag tag-green">{{ activity.category_label }}</text>
          <text :class="['tag', `tag-${statusTone(activity.status)}`]">{{ statusText(activity.status) }}</text>
          <text class="signup">已报名 {{ activity.participant_count }}/{{ activity.participant_limit }} 人</text>
        </view>
      </view>
    </view>
    <view v-else class="empty">
      <text class="empty-title">暂时还没有这样的活动</text>
      <text class="empty-sub">发起第一个活动，邀请同学一起参与校园活动</text>
      <button v-if="modules.isEnabled('module_community_post')" class="empty-btn" @click="createActivity">发起活动</button>
    </view>

    <view class="safe-space" />
    </template>
    <AppTabBar current-target="community" @compose="createActivity" />
  </view>
</template>

<script setup lang="ts">
import { formatDateTime as formatStart } from '@/utils/dateTime'
import { computed, ref } from 'vue'
import { onLoad, onPullDownRefresh, onShow } from '@dcloudio/uni-app'
import { communityActivityImageUrl, listCommunityActivities, listCommunityActivityCategories, listMyCommunityActivities, type CommunityActivity, type CommunityActivityCategory } from '@/api/community'
import AppTabBar from '@/components/AppTabBar.vue'
import { useAuthStore } from '@/stores/auth'
import { navigateToLogin } from '@/utils/authRedirect'
import ModulePlaceholder from '@/components/ModulePlaceholder.vue'
import { useModulesStore } from '@/stores/modules'

const modules = useModulesStore()
const moduleEnabled = computed(() => modules.isEnabled('module_community'))

type Mode = 'all' | 'created' | 'joined'
const fallbackCategories: CommunityActivityCategory[] = [
  { code:'study',label:'自习' },{ code:'movie',label:'电影' },{ code:'dining',label:'聚餐' },{ code:'carpool',label:'拼车' },
  { code:'group_buy',label:'拼单' },{ code:'gaming',label:'游戏' },{ code:'sports',label:'运动' },{ code:'travel',label:'旅行' },{ code:'other',label:'其他' },
]
const auth = useAuthStore()
const categories = ref<CommunityActivityCategory[]>(fallbackCategories)
const activities = ref<CommunityActivity[]>([])
const activeCategory = ref('')
const mode = ref<Mode>('all')
const loading = ref(false)
const error = ref('')
const modeTitle = computed(() => ({ all:'最新组团',created:'我发起的组团',joined:'我参与的组团' })[mode.value])

onLoad((options?:Record<string,string|undefined>) => { activeCategory.value = options?.category || '' })
onShow(async () => { await modules.ensureReady(); if (!moduleEnabled.value) return; await Promise.all([loadCategories(),load()]) })
onPullDownRefresh(async () => { try { await modules.ensureReady(); if (!moduleEnabled.value) return; await load() } finally { uni.stopPullDownRefresh() } })

async function loadCategories(){ try { const result=await listCommunityActivityCategories(); categories.value=result.items.length?result.items:fallbackCategories } catch { categories.value=fallbackCategories } }
async function load(){ loading.value=true; error.value=''; try { if(mode.value==='all'){ const result=await listCommunityActivities({category:activeCategory.value||undefined,page_size:30},auth.accessToken||undefined); activities.value=result.items } else { if(!ensureAuth()) return; const result=await listMyCommunityActivities(auth.accessToken,mode.value); activities.value=activeCategory.value?result.items.filter(item=>item.category_code===activeCategory.value):result.items } } catch(cause){ error.value=message(cause) } finally { loading.value=false } }
async function selectCategory(value:string){ activeCategory.value=value; await load() }
async function selectMode(value:Mode){ if(value!=='all'&&!ensureAuth())return; mode.value=value; await load() }
function ensureAuth(){ if(auth.isAuthenticated)return true; uni.showModal({title:'登录后查看组团',content:'登录后可以发起、报名和管理校园组团。',confirmText:'去登录',success:result=>{if(result.confirm)navigateToLogin()}}); return false }
function createActivity(){ if(!ensureAuth())return; uni.navigateTo({url:'/pages/community-activity-compose/index'}) }
function openDetail(id:string){ uni.navigateTo({url:`/pages/community-activity-detail/index?id=${encodeURIComponent(id)}`}) }
function goBack(){ const pages=getCurrentPages(); pages.length>1?uni.navigateBack():uni.reLaunch({url:'/pages/community/index'}) }
function categoryStyle(item:CommunityActivityCategory,index:number){ return {'--category-color':item.color||['#39c8ff','#ff6f9b','#ff9847','#ba8355','#ff5e71','#a35cff','#62d84d','#16cca2','#717985'][index%9]} }
function accentColor(code:string,index:number){ const item=categories.value.find(entry=>entry.code===code); return item?.color||['#39c8ff','#ff6f9b','#ff9847','#ba8355','#ff5e71','#a35cff','#62d84d','#16cca2','#717985'][Math.max(0,categories.value.findIndex(entry=>entry.code===code))%9]||['#39c8ff','#ff6f9b','#ff9847'][index%3] }
function firstImage(activity:CommunityActivity){ const image=activity.images?.[0]; return image?communityActivityImageUrl(image):'' }
function avatarText(value:string){ return (value||'同').slice(0,1) }
function statusText(value:string){ return ({pending_review:'审核中',approved:'报名中',closed:'已关闭',rejected:'未通过'} as Record<string,string>)[value]||'处理中' }
function statusTone(value:string){ return ({approved:'open',pending_review:'pending',rejected:'danger',closed:'muted'} as Record<string,string>)[value]||'pending' }
function message(value:unknown){ return value instanceof Error?value.message:'请检查网络后重试' }
</script>

<style scoped>
.page{--ink:#111827;min-height:100vh;padding:calc(22rpx + env(safe-area-inset-top)) 24rpx 0;box-sizing:border-box;background:linear-gradient(180deg,#fff0a9 0,#fff8dc 370rpx,#f8f8fa 800rpx);color:var(--ink)}.topbar,.topbar>view,.category-row,.mode-grid,.list-head,.activity-card,.activity-head,.meta,.activity-foot,.creator,.capacity{display:flex;align-items:center}.topbar{justify-content:space-between;gap:16rpx}.topbar>view:nth-child(2){min-width:0;flex:1}.back{display:flex!important;align-items:center;justify-content:center;width:62rpx;height:62rpx;border:3rpx solid var(--ink);border-radius:19rpx;background:#fff;font-size:26px;font-weight:900;line-height:1;box-shadow:0 5rpx 0 var(--ink)}.title,.subtitle,.hero-tag,.hero-title,.hero-copy,.list-head text,.activity-title,.activity-details,.state-title,.state-copy{display:block}.title{font-size:17px;font-weight:950}.subtitle{margin-top:2rpx;color:#6d7481;font-size:12px}.my-link{flex:none;padding:11rpx 15rpx;border:2rpx solid var(--ink);border-radius:999rpx;background:#fff;font-size:12px;font-weight:900}.hero{position:relative;display:flex;justify-content:space-between;min-height:218rpx;margin-top:25rpx;padding:28rpx;overflow:hidden;border:3rpx solid var(--ink);border-radius:28rpx;background:linear-gradient(135deg,#ffe132,#ff9255);box-shadow:0 10rpx 0 var(--ink);box-sizing:border-box}.hero>view:first-child{position:relative;z-index:2;max-width:500rpx}.hero-tag{font-size:12px;font-weight:950;letter-spacing:2rpx}.hero-title{margin-top:12rpx;font-size:22px;font-weight:950}.hero-copy{margin-top:10rpx;font-size:13px;font-weight:650;line-height:1.5}.hero-art{position:absolute;right:25rpx;top:30rpx;width:130rpx;height:130rpx;transform:rotate(9deg)}.hero-art text{position:relative;z-index:2;display:flex;align-items:center;justify-content:center;width:94rpx;height:94rpx;border:3rpx solid var(--ink);border-radius:30rpx;background:#fff;color:#ff5d70;font-size:24px;font-weight:950;box-shadow:0 8rpx 0 var(--ink)}.hero-art view{position:absolute;right:0;bottom:0;width:74rpx;height:74rpx;border:3rpx solid var(--ink);border-radius:50%;background:#52d7ff}.category-scroll{margin:31rpx -24rpx 0;padding:0 24rpx;box-sizing:border-box;white-space:nowrap}.category-row{gap:13rpx;padding:8rpx 28rpx 9rpx 0}.category{display:flex;align-items:center;gap:9rpx;padding:14rpx 20rpx;border:2rpx solid var(--ink);border-radius:14rpx;background:#fff;box-shadow:0 5rpx 0 rgba(17,24,39,.16);font-size:13px;font-weight:900}.category i{display:block;width:8rpx;height:34rpx;border-radius:999rpx;background:var(--category-color,#111827)}.category.active{background:var(--ink);color:#fff;box-shadow:0 5rpx 0 #ff5c70}.mode-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:8rpx;margin-top:19rpx;padding:7rpx;border:2rpx solid var(--ink);border-radius:18rpx;background:#fff}.mode-grid view{padding:14rpx 6rpx;text-align:center;border-radius:12rpx;font-size:13px;font-weight:850}.mode-grid view.active{background:#111827;color:#fff}.list-head{justify-content:space-between;margin:28rpx 4rpx 6rpx}.list-head>view text:first-child{font-size:17px;font-weight:950}.list-head>view text:last-child{margin-top:4rpx;color:#767e8c;font-size:12px}.list-head button{width:auto;height:58rpx;margin:0;padding:0 17rpx;border:2rpx solid var(--ink);border-radius:999rpx;background:#26df9e;font-size:12px;font-weight:950;line-height:54rpx;box-shadow:0 4rpx 0 var(--ink)}.list-head button::after,.state-card button::after,.floating-create::after{border:0}.activity-card{position:relative;align-items:stretch;overflow:hidden;margin-top:16rpx;border:3rpx solid var(--ink);border-radius:23rpx;background:#fff;box-shadow:0 8rpx 0 rgba(17,24,39,.12)}.activity-accent{position:absolute;left:0;top:0;bottom:0;width:10rpx}.activity-image{width:180rpx;min-height:235rpx;flex:none;border-right:3rpx solid var(--ink)}.activity-image.fallback{display:flex;align-items:center;justify-content:center}.activity-image.fallback text{display:flex;align-items:center;justify-content:center;width:88rpx;height:88rpx;border:3rpx solid var(--ink);border-radius:28rpx;background:rgba(255,255,255,.74);font-size:22px;font-weight:950}.activity-main{min-width:0;flex:1;padding:19rpx}.activity-head{justify-content:space-between;gap:10rpx}.category-tag,.status{padding:5rpx 10rpx;border-radius:999rpx;font-size:10px;font-weight:900}.category-tag{background:#edf0f3}.status-open{background:#cff8e7;color:#087a53}.status-full{background:#ffe1a7;color:#8d5800}.status-pending{background:#dcedff;color:#175b9c}.status-danger{background:#ffe0e5;color:#b7293d}.status-muted{background:#eceef1;color:#6b7380}.activity-title{overflow:hidden;margin-top:11rpx;font-size:15px;font-weight:950;text-overflow:ellipsis;white-space:nowrap}.activity-details{display:-webkit-box;overflow:hidden;margin-top:6rpx;color:#5d6675;font-size:12px;line-height:1.45;-webkit-box-orient:vertical;-webkit-line-clamp:2}.meta{gap:13rpx;margin-top:11rpx;color:#777f8c;font-size:10px}.meta text{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.activity-foot{justify-content:space-between;margin-top:15rpx;padding-top:12rpx;border-top:1rpx dashed #d7dce2}.creator{gap:7rpx;min-width:0}.creator view{display:flex;align-items:center;justify-content:center;width:34rpx;height:34rpx;border:2rpx solid var(--ink);border-radius:50%;background:#ffcc73;font-size:10px;font-weight:900}.creator text{overflow:hidden;max-width:100rpx;font-size:10px;text-overflow:ellipsis;white-space:nowrap}.capacity{font-size:10px;font-weight:750}.capacity strong{color:#f44358;font-size:14px}.capacity text{margin-left:5rpx;font-size:14px}.state-card{margin-top:22rpx;padding:60rpx 30rpx;text-align:center;border:3rpx solid var(--ink);border-radius:26rpx;background:#fff;box-shadow:0 9rpx 0 rgba(17,24,39,.12)}.state-icon{display:flex;align-items:center;justify-content:center;width:92rpx;height:92rpx;margin:auto;border:3rpx solid var(--ink);border-radius:29rpx;background:#ffdf35;box-shadow:0 7rpx 0 var(--ink);font-size:22px;font-weight:950}.state-title{margin-top:24rpx;font-size:17px;font-weight:950}.state-copy{margin-top:8rpx;color:#707987;font-size:13px;line-height:1.55}.state-card button{height:64rpx;margin-top:21rpx;border:2rpx solid var(--ink);border-radius:999rpx;background:#22dd9c;font-size:13px;font-weight:950;line-height:60rpx}.state-card.error .state-icon{background:#ff7787}.loading-list .activity-card{height:235rpx}.safe-space{height:160rpx}.floating-create{position:fixed;z-index:20;right:24rpx;bottom:calc(24rpx + env(safe-area-inset-bottom));height:78rpx;padding:0 27rpx;border:3rpx solid var(--ink);border-radius:999rpx;background:#ff5d70;color:var(--ink);font-size:14px;font-weight:950;line-height:72rpx;box-shadow:0 8rpx 0 var(--ink)}.floating-create text{font-size:17px}@media screen and (min-width:768px){.page{width:750rpx;margin:auto}.floating-create{right:calc(50% - 351rpx)}}
.page{background:radial-gradient(circle at 92% 0,rgba(55,205,212,.18),transparent 28%),linear-gradient(180deg,#eefaff 0,#f7fbfd 44%,#f4f8fa 100%)!important}.back,.my-link{border:1rpx solid #cfe1e9!important;background:#fff!important;box-shadow:none!important;color:#1475c5}.hero{border:1rpx solid #d7e8ef!important;background:linear-gradient(135deg,#1268c9,#19abc4)!important;box-shadow:0 16rpx 34rpx rgba(29,104,145,.16)!important;color:#fff}.hero-art text{border:0;background:rgba(255,255,255,.18);box-shadow:none;color:#fff}.hero-art view{border:0;background:rgba(255,243,200,.72)}.category{border:1rpx solid #d7e6ed!important;background:#fff!important;box-shadow:none!important}.category.active{border-color:#1778ce!important;background:#1778ce!important;box-shadow:0 7rpx 16rpx rgba(23,120,206,.16)!important}.mode-grid{border:1rpx solid #dce9ef!important;background:#fff!important}.mode-grid view.active{background:#e7f8fb;color:#14758a}.list-head button,.floating-create{border:0!important;box-shadow:0 8rpx 18rpx rgba(8,169,103,.16)!important;background:linear-gradient(135deg,#08a967,#0abca1)!important;color:#fff!important}.list-head button[disabled],.floating-create[disabled]{opacity:.48;box-shadow:none!important}.activity-card,.state-card{border:1rpx solid #dce9ef!important;box-shadow:0 12rpx 30rpx rgba(34,78,103,.075)!important;background:rgba(255,255,255,.97)!important}.activity-image{border-right:1rpx solid #dce9ef!important}.activity-image.fallback{color:#fff}.activity-image.fallback text{border:0;background:rgba(255,255,255,.75);box-shadow:none;color:#1475c5}.creator view{border:0;background:#e6f7fb;color:#1475c5}.state-icon{border:0;background:#e8f7fb;color:#1477c7;box-shadow:none}.state-card button{border:1rpx solid #b9dbe5!important;background:#fff!important;color:#1475c5!important;box-shadow:none!important}.loading-list .activity-card{background:linear-gradient(100deg,#e6f1f5 30%,#f8fcfd 45%,#e6f1f5 60%)!important;background-size:220% 100%;animation:community-activity-shimmer 1.2s ease-in-out infinite}@keyframes community-activity-shimmer{0%{background-position:100% 0}100%{background-position:-100% 0}}
.floating-create{bottom:calc(154rpx + env(safe-area-inset-bottom))!important}
@media screen and (min-width:768px){.floating-create{bottom:calc(154rpx + env(safe-area-inset-bottom))!important}}
/* ===== 新视觉（设计屏 42 act-list） ===== */
.page{--green:#0BBF63;--green-deep:#08A152;--green-soft:#E7F9EF;--t1:#191B1F;--t2:#767C85;--t3:#AEB3BB;--line:#EDEFEF;--price:#FF2442;background:#F4F6F4!important;padding:0 0 30rpx!important;color:var(--t1)}
.subnav{display:flex;align-items:center;padding:calc(var(--status-bar-height,20px) + 8rpx) 20rpx 8rpx;background:#F4F6F4}
.subnav .back{width:64rpx;height:64rpx;display:flex;align-items:center;justify-content:center;font-size:44rpx;color:var(--t1);line-height:1}
.nav-title{flex:1;text-align:center;font-size:32rpx;font-weight:800;margin-right:64rpx}
.nav-action{position:absolute;right:28rpx;font-size:26rpx;color:var(--green-deep);font-weight:700}
.act-banner{position:relative;margin:12rpx 24rpx 0;border-radius:24rpx;padding:40rpx 32rpx;background:linear-gradient(135deg,#12D978,#08A152);color:#fff;overflow:hidden}
.act-banner::before{content:'';position:absolute;width:300rpx;height:300rpx;border-radius:50%;background:rgba(255,255,255,.10);top:-120rpx;right:-80rpx}
.banner-title{position:relative;display:block;font-size:36rpx;font-weight:800}
.banner-copy{position:relative;display:block;margin-top:12rpx;font-size:23rpx;opacity:.92;line-height:1.6}
.cat-chips{white-space:nowrap;margin-top:16rpx}
.chips-sub{margin-top:0}
.chips-inner{display:inline-flex;gap:16rpx;padding:8rpx 24rpx}
.chip{flex:none;height:60rpx;line-height:60rpx;padding:0 28rpx;border-radius:30rpx;background:#fff;border:2rpx solid var(--line);font-size:25rpx;color:var(--t2)}
.chip.on{background:linear-gradient(135deg,#12D978,#08A152);color:#fff;font-weight:700;border-color:transparent;box-shadow:0 6rpx 14rpx rgba(11,191,99,.22)}
.card{background:#fff;border-radius:24rpx;margin:16rpx 24rpx 0;overflow:hidden}
.act-card{padding:24rpx}
.act-card+.act-card{border-top:2rpx solid #F5F6F6}
.act-card .cover{width:100%;height:260rpx;border-radius:18rpx;background:#F2F4F3;display:block}
.act-card .cover.placeholder{display:flex;align-items:center;justify-content:center;color:#fff;font-size:48rpx;font-weight:800;opacity:.85}
.act-title{display:block;margin-top:20rpx;font-size:28rpx;font-weight:700;color:var(--t1);line-height:1.45}
.act-meta{display:flex;align-items:center;gap:10rpx;margin-top:12rpx}
.meta-ic{font-size:24rpx;color:var(--green)}
.meta-text{font-size:22rpx;color:var(--t3);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.act-foot{display:flex;align-items:center;gap:12rpx;margin-top:16rpx}
.act-foot .tag{font-size:20rpx;padding:4rpx 14rpx;border-radius:8rpx;background:#F2F4F3;color:var(--t2)}
.act-foot .tag-green{background:var(--green-soft);color:var(--green-deep)}
.act-foot .tag-open{background:var(--green-soft);color:var(--green-deep)}
.act-foot .tag-pending{background:#EAF2FE;color:#2F7DE1}
.act-foot .tag-danger{background:#FDECEC;color:#D4380D}
.act-foot .tag-muted{background:#F2F4F3;color:var(--t3)}
.act-foot .tag-full{background:#FFF3E5;color:#D97706}
.signup{margin-left:auto;font-size:22rpx;color:var(--t3)}
.act-card .skel{height:28rpx;border-radius:8rpx;margin:20rpx 0 14rpx}
.act-card .skel.short{width:55%}
.state-card{margin:24rpx;background:#fff;border-radius:24rpx;padding:70rpx 48rpx;display:flex;flex-direction:column;align-items:center}
.state-icon{width:88rpx;height:88rpx;border-radius:26rpx;background:#FDECEC!important;color:#D4380D!important;display:flex;align-items:center;justify-content:center;font-size:40rpx;font-weight:800;border:0;box-shadow:none}
.state-title{font-size:30rpx;font-weight:700;color:var(--t1);margin-top:24rpx}
.state-copy{font-size:24rpx;color:var(--t3);margin-top:12rpx;text-align:center}
.state-btn{margin-top:32rpx;height:72rpx;line-height:72rpx;padding:0 48rpx;border-radius:36rpx;background:#fff!important;color:var(--green-deep)!important;font-size:26rpx;border:2rpx solid var(--green-soft)!important;box-shadow:none}
.state-btn::after{border:0}
.empty{display:flex;flex-direction:column;align-items:center;padding:120rpx 48rpx}
.empty-title{font-size:30rpx;font-weight:700;color:var(--t1)}
.empty-sub{font-size:24rpx;color:var(--t3);margin-top:14rpx;text-align:center}
.empty-btn{margin-top:36rpx;height:76rpx;line-height:76rpx;padding:0 56rpx;border-radius:38rpx;background:linear-gradient(135deg,#12D978,#08A152);color:#fff;font-size:28rpx;font-weight:700}
.empty-btn::after{border:0}
.safe-space{height:150rpx}
</style>
