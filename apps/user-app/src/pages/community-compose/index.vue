<template>
  <view class="page">
    <ModulePlaceholder v-if="modules.resolved && !moduleEnabled" code="module_community_post" />
    <view v-else-if="!modules.resolved" class="mp-skeleton">
      <view class="tm-skeleton" style="height: 200rpx; border-radius: 24rpx; margin: 24rpx 28rpx;" />
      <view class="tm-skeleton" style="height: 320rpx; border-radius: 24rpx; margin: 20rpx 28rpx;" />
    </view>
    <template v-else>
    <view class="subnav">
      <button class="nav-back" aria-label="返回" @click="goBack">‹</button>
      <text class="nav-title">{{ editingId ? '编辑动态' : '发布动态' }}</text>
      <button class="nav-draft" :disabled="submitting || uploading" @click="saveDraft">存草稿</button>
    </view>

    <view v-if="loading" class="state-card"><view class="state-spinner" /><text class="state-copy">正在读取帖子内容…</text></view>
    <view v-else-if="loadError" class="state-card error-card">
      <text class="state-title">帖子读取失败</text>
      <text class="state-copy">{{ loadError }}</text>
      <button class="btn-main" @click="loadEditing">重新加载</button>
    </view>
    <template v-else>
      <view v-if="reviewNote" class="review-alert"><text>上次审核未通过</text><text>{{ reviewNote }}</text></view>

      <view class="card editor-card">
        <view class="field-head"><text>帖子标题</text><text class="field-count">{{ unicodeLength(title) }}/{{ publishingRules.post.title.max_length }}</text></view>
        <input v-model="title" class="title-input" :maxlength="publishingRules.post.title.max_length" placeholder="用一句话说明你想分享的内容" />
        <view class="divider" />
        <view class="field-head"><text>内容分类</text><text class="field-count">帮助大家快速找到内容</text></view>
        <view class="chip-pick">
          <text v-for="item in categories" :key="item.code" :class="{ on: categoryCode === item.code }" @click="categoryCode = item.code">{{ item.label }}</text>
        </view>
        <view class="divider" />
        <view class="field-head"><text>正文内容</text><text class="field-count">{{ unicodeLength(content) }}/{{ publishingRules.post.content.max_length }}</text></view>
        <textarea
          v-model="content"
          class="content-input"
          :maxlength="publishingRules.post.content.max_length"
          :cursor="contentCursor"
          placeholder="分享校园新鲜事，让更多同学看到…"
          @input="trackContentCursor"
          @blur="trackContentCursor"
        />
        <view class="editor-tools">
          <button :class="['emoji-toggle', { active: emojiVisible }]" aria-label="选择表情" @click="toggleEmojiPicker">☺</button>
          <text>在正文中加入表情，让表达更自然</text>
        </view>
        <EmojiPicker v-if="emojiVisible" @select="insertContentEmoji" />
      </view>

      <view class="card media-card">
        <view class="media-head"><view><text class="media-title">图片</text><text class="media-sub">最多 9 张，上传后需通过安全扫描</text></view><text class="field-count">{{ images.length }}/9</text></view>
        <view class="upload-grid">
          <view v-for="image in images" :key="image.fileId" class="upload-item">
            <image v-if="image.previewUrl" :src="image.previewUrl" mode="aspectFill" />
            <view v-else class="upload-placeholder"><text>已保存</text><small>发布后可预览</small></view>
            <text class="upload-remove" @click="removeImage(image.fileId)">×</text>
          </view>
          <view v-if="images.length < 9" class="upload-add" @click="addImage"><text class="upload-plus">＋</text><small>{{ uploading ? '上传中…' : '添加图片' }}</small></view>
        </view>
        <view v-if="uploadError" class="inline-error"><text>{{ uploadError }}</text><text @click="addImage">重试上传</text></view>
      </view>

      <view class="form-card">
        <view class="frow">
          <view class="frow-ic green">#</view>
          <text class="frow-val topic">#{{ selectedCategoryLabel }}</text>
          <text class="spacer" />
        </view>
        <view class="frow" @click="locationEditing = true">
          <view class="frow-ic orange">位</view>
          <text :class="['frow-val', { dim: !locationName }]">{{ locationName || '添加位置（选填）' }}</text>
          <text class="spacer" />
          <text v-if="locationName" class="frow-clear" @click.stop="locationName = ''">×</text>
          <text v-else class="frow-arrow">›</text>
        </view>
        <view v-if="locationEditing" class="loc-edit">
          <input v-model="locationName" focus :maxlength="80" placeholder="如：图书馆三楼 / 8 号宿舍楼" @blur="locationEditing = false" @confirm="locationEditing = false" />
        </view>
        <view class="frow">
          <view class="frow-ic blue">见</view>
          <text class="frow-val dim">所有人可见</text>
          <text class="spacer" />
        </view>
      </view>

      <view class="reward-card">
        <view class="reward-ic">奖</view>
        <text class="reward-copy">带话题发布并通过审核后，可获得 <text class="reward-strong">{{ rewardText }}</text> 创作奖励</text>
      </view>

      <view class="guidance">
        <text class="guidance-title">发布前检查</text>
        <view><text class="check">✓</text><text>不公开手机号、地址、证件和订单隐私</text></view>
        <view><text class="check">✓</text><text>不发布广告、刷量或无法验证的收益承诺</text></view>
        <view><text class="check">✓</text><text>保持真实、友善，并尊重他人版权</text></view>
      </view>

      <view v-if="previewing" class="card preview-card">
        <view class="preview-head"><text>发布预览</text><text class="preview-edit" @click="previewing = false">继续编辑</text></view>
        <text class="preview-category"># {{ selectedCategoryLabel }}</text>
        <text class="preview-title">{{ title || '未填写标题' }}</text>
        <text class="preview-content">{{ content || '未填写正文' }}</text>
        <view v-if="images.length" class="preview-images"><image v-for="image in images.filter(item => item.previewUrl)" :key="image.fileId" :src="image.previewUrl" mode="aspectFill" /></view>
      </view>

      <view v-if="submitError" class="submit-error"><text>提交没有成功</text><text>{{ submitError }}</text><text>内容和已上传图片均已保留，可以直接重试。</text></view>

      <view class="action-bar">
        <button class="ab-ghost" :disabled="submitting || uploading" @click="saveDraft">存草稿</button>
        <button class="ab-ghost" :disabled="submitting || uploading" @click="preview">预览</button>
        <button class="ab-main" :disabled="submitting || uploading" :loading="submitting" @click="submit">{{ submitLabel }}</button>
      </view>
    </template>
    </template>
  </view>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue'
import { onLoad } from '@dcloudio/uni-app'
import EmojiPicker from '@/components/EmojiPicker.vue'
import {
  communityAttachmentUrl,
  createCommunityRequestKey,
  createCommunityPost,
  DEFAULT_COMMUNITY_PUBLISHING_RULES,
  DEFAULT_POST_REWARD_POINTS,
  listCommunityCategories,
  listMyCommunityPosts,
  updateCommunityPost,
  type CommunityCategory,
  type CommunityPublishingRules,
} from '@/api/community'
import { chooseAndUploadPrivateImage } from '@/api/upload'
import { useAuthStore } from '@/stores/auth'
import { redirectToLogin } from '@/utils/authRedirect'
import { insertTextAtCursor, unicodeLength } from '@/utils/textInput'
import { primeCommunitySubscription, requestCommunitySubscriptionAndWait } from '@/utils/wechatSubscription'
import ModulePlaceholder from '@/components/ModulePlaceholder.vue'
import { useModulesStore } from '@/stores/modules'

const modules = useModulesStore()
const moduleEnabled = computed(() => modules.isEnabled('module_community_post'))

interface EditableImage { fileId: string; previewUrl: string }
interface SavedDraft { title?: string; content?: string; categoryCode?: CommunityCategory['code']; locationName?: string; images?: EditableImage[]; requestKey?: string }
interface TextareaEvent { detail?: { cursor?: number } }

const fallbackCategories: CommunityCategory[] = [
  { code: 'experience', label: '经验分享' }, { code: 'guide', label: '实用攻略' },
  { code: 'question', label: '互助问答' }, { code: 'showcase', label: '成果展示' },
]
const auth = useAuthStore()
const editingId = ref('')
const title = ref('')
const content = ref('')
const contentCursor = ref(0)
const emojiVisible = ref(false)
const categoryCode = ref<CommunityCategory['code']>('experience')
const locationName = ref('')
const locationEditing = ref(false)
const categories = ref<CommunityCategory[]>(fallbackCategories)
const publishingRules = ref<CommunityPublishingRules>(DEFAULT_COMMUNITY_PUBLISHING_RULES)
const postRewardPoints = ref(DEFAULT_POST_REWARD_POINTS)
const rewardText = computed(() => (postRewardPoints.value > 0 ? `+${postRewardPoints.value} 积分` : '平台奖励'))
const images = ref<EditableImage[]>([])
const reviewNote = ref('')
const previewing = ref(false)
const submitting = ref(false)
const uploading = ref(false)
const loading = ref(false)
const loadError = ref('')
const uploadError = ref('')
const submitError = ref('')
const submitRequestKey = ref(createCommunityRequestKey('post'))
const selectedCategoryLabel = computed(() => categories.value.find(item => item.code === categoryCode.value)?.label || '经验分享')
const submitLabel = computed(() => editingId.value ? (reviewNote.value ? '修改并重新提交' : '提交新版本') : '发布')

onLoad(async (options?: Record<string, string | undefined>) => {
  await modules.ensureReady()
  if (!moduleEnabled.value) return
  if (!auth.isAuthenticated) { redirectToLogin(); return }
  if (auth.accessToken) void primeCommunitySubscription(auth.accessToken)
  editingId.value = options?.postId || ''
  const requestedCategory = options?.category
  try {
    const result = await listCommunityCategories()
    categories.value = result.items
    publishingRules.value = result.publishing_rules || DEFAULT_COMMUNITY_PUBLISHING_RULES
    postRewardPoints.value = result.post_reward_points ?? DEFAULT_POST_REWARD_POINTS
  } catch {
    categories.value = fallbackCategories
    publishingRules.value = DEFAULT_COMMUNITY_PUBLISHING_RULES
  }
  if (editingId.value) await loadEditing()
  else restoreDraft()
  if (requestedCategory && categories.value.some(item => item.code === requestedCategory)) categoryCode.value = requestedCategory as CommunityCategory['code']
})

async function loadEditing() {
  if (!auth.accessToken || !editingId.value) return
  loading.value = true; loadError.value = ''
  try {
    const result = await listMyCommunityPosts(auth.accessToken, 1, 50)
    const post = result.items.find(item => item.id === editingId.value)
    if (!post) throw new Error('没有找到该帖子，可能已被删除。')
    if (!post.can_edit) throw new Error('当前帖子状态不可编辑。')
    const version = post.latest_version
    title.value = version?.title || post.title
    content.value = version?.content || post.content
    contentCursor.value = content.value.length
    categoryCode.value = version?.category_code || post.category_code
    locationName.value = post.location_name || ''
    images.value = (version?.attachments || post.attachments).filter(item => item.type === 'image').map(item => ({ fileId: item.file_id, previewUrl: communityAttachmentUrl(item) }))
    reviewNote.value = version?.review_status === 'rejected' ? version.review_note || '内容暂未通过审核，请修改后重新提交。' : ''
    restoreDraft()
  } catch (error) { loadError.value = errorMessage(error) }
  finally { loading.value = false }
}

function draftKey() { return `tm-community-compose-draft:${editingId.value || 'new'}` }
function restoreDraft() {
  const draft = uni.getStorageSync(draftKey()) as SavedDraft | null
  if (!draft || typeof draft !== 'object') return
  title.value = draft.title ?? title.value
  content.value = draft.content ?? content.value
  contentCursor.value = content.value.length
  categoryCode.value = draft.categoryCode ?? categoryCode.value
  locationName.value = draft.locationName ?? locationName.value
  images.value = Array.isArray(draft.images) ? draft.images : images.value
  submitRequestKey.value = draft.requestKey || submitRequestKey.value
}
function validate() {
  const titleLength = unicodeLength(title.value.trim())
  const contentLength = unicodeLength(content.value.trim())
  const titleRule = publishingRules.value.post.title
  const contentRule = publishingRules.value.post.content
  if (titleLength < titleRule.min_length) return `标题至少需要 ${titleRule.min_length} 个字符`
  if (titleLength > titleRule.max_length) return `标题最多输入 ${titleRule.max_length} 个字符`
  if (contentLength < contentRule.min_length) return `正文至少需要 ${contentRule.min_length} 个字符`
  if (contentLength > contentRule.max_length) return `正文最多输入 ${contentRule.max_length} 个字符`
  return ''
}
function persistDraft() { uni.setStorageSync(draftKey(), { title: title.value, content: content.value, categoryCode: categoryCode.value, locationName: locationName.value, images: images.value, requestKey: submitRequestKey.value, savedAt: Date.now() }) }
function saveDraft() { persistDraft(); uni.showToast({ title: '草稿已保存在本机', icon: 'success' }) }
function preview() { const message = validate(); if (message) return uni.showToast({ title: message, icon: 'none' }); previewing.value = true }
async function addImage() {
  if (!auth.accessToken || uploading.value || images.value.length >= 9) return
  uploading.value = true; uploadError.value = ''
  try { const uploaded = await chooseAndUploadPrivateImage(auth.accessToken, 'community'); images.value.push({ fileId: uploaded.fileId, previewUrl: uploaded.previewUrl }) }
  catch (error) { uploadError.value = errorMessage(error) }
  finally { uploading.value = false }
}
function removeImage(fileId: string) { images.value = images.value.filter(item => item.fileId !== fileId) }
function trackContentCursor(event: Event) {
  const nextCursor = Number((event as unknown as TextareaEvent).detail?.cursor)
  if (Number.isFinite(nextCursor) && nextCursor >= 0) contentCursor.value = nextCursor
}
function toggleEmojiPicker() {
  emojiVisible.value = !emojiVisible.value
  if (emojiVisible.value) uni.hideKeyboard()
}
function insertContentEmoji(emoji: string) {
  const maxLength = publishingRules.value.post.content.max_length
  const result = insertTextAtCursor(content.value, emoji, contentCursor.value, maxLength)
  if (!result.changed) return uni.showToast({ title: `正文最多输入 ${maxLength} 个字符`, icon: 'none' })
  content.value = result.value
  contentCursor.value = result.cursor
}
async function submit() {
  if (submitting.value) return
  const message = validate()
  if (message) return uni.showToast({ title: message, icon: 'none' })
  if (!auth.isAuthenticated) return redirectToLogin()
  submitting.value = true; submitError.value = ''
  const payload = { title: title.value.trim(), content: content.value.trim(), category_code: categoryCode.value, location_name: locationName.value.trim() || undefined, image_file_ids: images.value.map(item => item.fileId) }
  try {
    // 发布成功场景会立即消费订阅授权，等用户完成授权后再提交，避免授权晚到导致推送被跳过。
    await requestCommunitySubscriptionAndWait(auth.accessToken)
    const post = editingId.value ? await updateCommunityPost(auth.accessToken, editingId.value, payload) : await createCommunityPost(auth.accessToken, payload, submitRequestKey.value)
    uni.removeStorageSync(draftKey())
    uni.showToast({ title: post.status === 'published' ? '发布成功' : '内容已提交审核', icon: 'success' })
    setTimeout(() => uni.navigateBack(), 600)
  } catch (error) { submitError.value = errorMessage(error); persistDraft() }
  finally { submitting.value = false }
}
function goBack() { uni.navigateBack() }
function categoryTip(code: CommunityCategory['code']) { return ({ experience: '经历与复盘', guide: '方法与步骤', question: '问题与求助', showcase: '作品与成果' } as Record<CommunityCategory['code'], string>)[code] }
function errorMessage(error: unknown) { return error instanceof Error ? error.message : '操作失败，请稍后重试。' }
</script>

<style scoped>
.page{min-height:100vh;padding-bottom:180rpx;background:#F4F6F4;color:#191B1F}
.subnav{display:flex;align-items:center;height:88rpx;padding:0 16rpx;background:#fff}
.nav-back{display:flex;align-items:center;justify-content:center;width:68rpx;height:68rpx;margin:0;padding:0;color:#191B1F;font-size:40rpx;line-height:60rpx}
.nav-back::after{border:0}
/* #ifdef MP-WEIXIN */
/* 小程序原生导航栏已有返回键，页内返回键保留占位但不再显示，避免出现两个返回按钮 */
.nav-back{visibility:hidden}
/* #endif */
.nav-title{flex:1;font-size:32rpx;font-weight:700;text-align:center}
.nav-draft{display:flex;align-items:center;justify-content:center;height:68rpx;margin:0;padding:0 10rpx;color:#767C85;font-size:26rpx}
.nav-draft::after{border:0}
.nav-draft[disabled]{opacity:.5}
.card{margin:20rpx 24rpx 0;padding:28rpx;border-radius:32rpx;background:#fff}
.field-head{display:flex;align-items:center;justify-content:space-between;font-size:28rpx;font-weight:700}
.field-count{color:#AEB3BB;font-size:22rpx;font-weight:400}
.title-input{height:88rpx;color:#191B1F;font-size:32rpx;font-weight:700}
.divider{height:1rpx;margin:16rpx 0;background:#F5F6F6}
.chip-pick{display:flex;flex-wrap:wrap;gap:14rpx;margin-top:18rpx}
.chip-pick text{padding:14rpx 26rpx;border-radius:14rpx;background:#F2F4F3;color:#767C85;font-size:26rpx}
.chip-pick text.on{background:#E7F9EF;color:#08A152;font-weight:700;box-shadow:inset 0 0 0 2rpx #0BBF63}
.content-input{width:100%;height:320rpx;margin-top:14rpx;color:#191B1F;font-size:28rpx;line-height:1.7}
.editor-tools{display:flex;align-items:center;gap:14rpx;margin-top:14rpx}
.editor-tools>text{color:#AEB3BB;font-size:22rpx}
.emoji-toggle{display:flex;align-items:center;justify-content:center;width:64rpx;height:58rpx;flex:none;margin:0;padding:0;border:1rpx solid #EDEFEF;border-radius:16rpx;background:#F7F9F8;color:#08A152;font-size:30rpx;line-height:56rpx}
.emoji-toggle::after{border:0}
.emoji-toggle.active{border-color:#0BBF63;background:#E7F9EF}
.media-head{display:flex;align-items:center;justify-content:space-between}
.media-title,.media-sub{display:block}
.media-title{font-size:28rpx;font-weight:700}
.media-sub{margin-top:6rpx;color:#AEB3BB;font-size:22rpx}
.upload-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14rpx;margin-top:20rpx}
.upload-item,.upload-add{position:relative;height:200rpx;overflow:hidden;border-radius:20rpx}
.upload-item{background:#F7F9F8}
.upload-item image{width:100%;height:100%}
.upload-placeholder,.upload-add{display:flex;flex-direction:column;align-items:center;justify-content:center}
.upload-placeholder{width:100%;height:100%;color:#767C85}
.upload-placeholder text{font-size:24rpx;font-weight:700}
.upload-placeholder small{margin-top:6rpx;font-size:20rpx}
.upload-remove{position:absolute;top:10rpx;right:10rpx;display:flex;align-items:center;justify-content:center;width:40rpx;height:40rpx;border-radius:50%;background:rgba(23,26,23,.6);color:#fff;font-size:26rpx}
.upload-add{border:2rpx dashed #D7DBD8;background:#F7F9F8;color:#AEB3BB}
.upload-plus{font-size:40rpx}
.upload-add small{margin-top:6rpx;font-size:20rpx}
.inline-error{display:flex;justify-content:space-between;gap:16rpx;margin-top:16rpx;color:#FF3B30;font-size:24rpx}
.inline-error text:last-child{flex:none;color:#08A152;font-weight:700}
.form-card{margin:20rpx 24rpx 0;padding:4rpx 28rpx;border-radius:32rpx;background:#fff}
.frow{display:flex;align-items:center;gap:20rpx;min-height:104rpx;padding:24rpx 0;border-bottom:1rpx solid #F5F6F6}
.frow:last-child{border-bottom:0}
.frow-ic{display:flex;align-items:center;justify-content:center;width:56rpx;height:56rpx;flex:none;border-radius:16rpx;font-size:26rpx;font-weight:800}
.frow-ic.green{background:#E7F9EF;color:#08A152}
.frow-ic.orange{background:#FFF2E5;color:#F2620A}
.frow-ic.blue{background:#EAF2FF;color:#3B82F6}
.frow-val{font-size:28rpx;color:#191B1F}
.frow-val.topic{color:#08A152;font-weight:700}
.frow-val.dim{color:#AEB3BB}
.spacer{flex:1}
.frow-arrow{color:#AEB3BB;font-size:30rpx}
.frow-clear{display:flex;align-items:center;justify-content:center;width:44rpx;height:44rpx;border-radius:50%;background:#F2F4F3;color:#767C85;font-size:26rpx}
.loc-edit{padding:0 0 20rpx;border-bottom:1rpx solid #F5F6F6}
.loc-edit input{height:72rpx;padding:0 24rpx;border-radius:999rpx;background:#F4F8F5;font-size:26rpx}
.reward-card{display:flex;align-items:center;gap:20rpx;margin:20rpx 24rpx 0;padding:24rpx 28rpx;border-radius:32rpx;background:#fff}
.reward-ic{display:flex;align-items:center;justify-content:center;width:68rpx;height:68rpx;flex:none;border-radius:20rpx;background:#FFF2E5;color:#F2620A;font-size:28rpx;font-weight:800}
.reward-copy{min-width:0;flex:1;color:#767C85;font-size:24rpx;line-height:1.5}
.reward-strong{color:#F2620A;font-weight:800}
.guidance{margin:20rpx 24rpx 0;padding:26rpx 28rpx;border-radius:32rpx;background:#fff}
.guidance-title{display:block;margin-bottom:14rpx;font-size:28rpx;font-weight:700}
.guidance>view{display:flex;align-items:flex-start;gap:12rpx;margin-top:10rpx;color:#767C85;font-size:24rpx;line-height:1.55}
.check{color:#0BBF63;font-weight:900}
.preview-head{display:flex;align-items:center;justify-content:space-between;font-size:28rpx;font-weight:700}
.preview-edit{color:#08A152;font-size:24rpx}
.preview-category{display:block;margin-top:18rpx;color:#08A152;font-size:24rpx;font-weight:700}
.preview-title{display:block;margin-top:10rpx;font-size:32rpx;font-weight:800}
.preview-content{display:block;margin-top:12rpx;color:#3A3D43;font-size:26rpx;line-height:1.75;white-space:pre-wrap}
.preview-images{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10rpx;margin-top:18rpx}
.preview-images image{width:100%;height:160rpx;border-radius:16rpx}
.review-alert,.submit-error{margin:20rpx 24rpx 0;padding:24rpx 28rpx;border-radius:24rpx;background:#FFEEEA;color:#FF3B30;font-size:24rpx;line-height:1.55}
.review-alert text,.submit-error text{display:block}
.review-alert text:first-child,.submit-error text:first-child{font-weight:800}
.submit-error text:last-child{margin-top:6rpx;color:#767C85}
.state-card{margin:20rpx 24rpx 0;padding:90rpx 40rpx;border-radius:32rpx;background:#fff;text-align:center}
.state-spinner{width:56rpx;height:56rpx;margin:0 auto;border:6rpx solid #E7F9EF;border-top-color:#0BBF63;border-radius:50%;animation:cc-spin .8s linear infinite}
.state-title{display:block;font-size:30rpx;font-weight:700}
.state-copy{display:block;margin-top:14rpx;color:#767C85;font-size:24rpx;line-height:1.6}
.error-card .state-title{color:#FF3B30}
.btn-main{height:80rpx;margin:32rpx auto 0;padding:0 48rpx;border-radius:999rpx;background:linear-gradient(135deg,#12D978,#08A152);color:#fff;font-size:28rpx;font-weight:700;line-height:80rpx}
.btn-main::after{border:0}
.action-bar{position:fixed;z-index:20;right:0;bottom:0;left:0;display:flex;gap:16rpx;padding:16rpx 24rpx calc(16rpx + env(safe-area-inset-bottom));border-top:1rpx solid #EDEFEF;background:#fff}
.action-bar button{height:84rpx;margin:0;border-radius:999rpx;font-size:28rpx;font-weight:700;line-height:84rpx}
.action-bar button::after{border:0}
.action-bar button[disabled]{opacity:.5}
.ab-ghost{flex:.7;border:1rpx solid #EDEFEF;background:#F2F4F3;color:#767C85}
.ab-main{flex:1.4;background:linear-gradient(135deg,#12D978,#08A152);color:#fff;box-shadow:0 12rpx 28rpx rgba(11,191,99,.3)}
@keyframes cc-spin{to{transform:rotate(360deg)}}
</style>
