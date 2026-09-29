<template>
  <view class="page">
    <ModulePlaceholder v-if="modules.resolved && !moduleEnabled" code="module_community_post" />
    <view v-else-if="!modules.resolved" class="mp-skeleton">
      <view class="tm-skeleton" style="height: 200rpx; border-radius: 24rpx; margin: 24rpx 28rpx;" />
      <view class="tm-skeleton" style="height: 320rpx; border-radius: 24rpx; margin: 20rpx 28rpx;" />
    </view>
    <template v-else>
    <view class="intro">
      <text class="eyebrow">CAMPUS STORY</text>
      <text class="title">{{ editingId ? '编辑校园动态' : '写下校园新鲜事' }}</text>
      <text class="subtitle">分享见闻、经验、求助或作品。图片会先经过安全检查，审核通过后才会公开展示。</text>
    </view>

    <view v-if="loading" class="state-card"><view class="state-spinner" /><text>正在读取帖子内容…</text></view>
    <view v-else-if="loadError" class="state-card error-card"><text class="state-title">帖子读取失败</text><text class="state-copy">{{ loadError }}</text><button class="retry" @click="loadEditing">重新加载</button></view>
    <template v-else>
      <view v-if="reviewNote" class="review-alert"><text>上次审核未通过</text><text>{{ reviewNote }}</text></view>

      <view class="card editor-card">
        <view class="field-head"><text>内容分类</text><text>帮助大家快速找到内容</text></view>
        <view class="category-grid"><view v-for="item in categories" :key="item.code" :class="['category-option', { active: categoryCode === item.code }]" @click="categoryCode = item.code"><text>{{ item.label }}</text><small>{{ categoryTip(item.code) }}</small></view></view>
        <view class="divider" />
        <view class="field-head"><text>帖子标题</text><text>{{ unicodeLength(title) }}/{{ publishingRules.post.title.max_length }}</text></view>
        <input v-model="title" class="title-input" :maxlength="publishingRules.post.title.max_length" placeholder="用一句话说明你想分享的内容" />
        <view class="divider" />
        <view class="field-head"><text>正文内容</text><text>{{ unicodeLength(content) }}/{{ publishingRules.post.content.max_length }}</text></view>
        <textarea
          v-model="content"
          class="content-input"
          :maxlength="publishingRules.post.content.max_length"
          :cursor="contentCursor"
          placeholder="建议包含：发生了什么、你如何处理、结果怎样，以及给其他人的建议……"
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
        <view class="media-head"><view><text class="section-title">图片</text><text class="section-copy">最多 9 张，上传后需通过安全扫描</text></view><text>{{ images.length }}/9</text></view>
        <view class="image-grid">
          <view v-for="image in images" :key="image.fileId" class="image-item">
            <image v-if="image.previewUrl" :src="image.previewUrl" mode="aspectFill" />
            <view v-else class="image-placeholder"><text>已保存</text><small>发布后可预览</small></view>
            <text class="remove-image" @click="removeImage(image.fileId)">×</text>
          </view>
          <view v-if="images.length < 9" class="image-add" @click="addImage"><text>＋</text><small>{{ uploading ? '上传中…' : '添加图片' }}</small></view>
        </view>
        <view v-if="uploadError" class="inline-error"><text>{{ uploadError }}</text><text @click="addImage">重试上传</text></view>
      </view>

      <view class="guidance">
        <text class="guidance-title">发布前检查</text>
        <view><text class="check">✓</text><text>不公开手机号、地址、证件和订单隐私</text></view>
        <view><text class="check">✓</text><text>不发布广告、刷量或无法验证的收益承诺</text></view>
        <view><text class="check">✓</text><text>保持真实、友善，并尊重他人版权</text></view>
      </view>

      <view v-if="previewing" class="card preview-card">
        <view class="preview-head"><text>发布预览</text><text @click="previewing = false">继续编辑</text></view>
        <text class="preview-category">{{ selectedCategoryLabel }}</text>
        <text class="preview-title">{{ title || '未填写标题' }}</text>
        <text class="preview-content">{{ content || '未填写正文' }}</text>
        <view v-if="images.length" class="preview-images"><image v-for="image in images.filter(item => item.previewUrl)" :key="image.fileId" :src="image.previewUrl" mode="aspectFill" /></view>
      </view>

      <view v-if="submitError" class="submit-error"><text>提交没有成功</text><text>{{ submitError }}</text><text>内容和已上传图片均已保留，可以直接重试。</text></view>

      <view class="footer-actions">
        <button class="secondary" :disabled="submitting || uploading" @click="saveDraft">保存草稿</button>
        <button class="secondary" :disabled="submitting || uploading" @click="preview">预览</button>
        <button class="primary" :disabled="submitting || uploading" :loading="submitting" @click="submit">{{ submitLabel }}</button>
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
import ModulePlaceholder from '@/components/ModulePlaceholder.vue'
import { useModulesStore } from '@/stores/modules'

const modules = useModulesStore()
const moduleEnabled = computed(() => modules.isEnabled('module_community_post'))

interface EditableImage { fileId: string; previewUrl: string }
interface SavedDraft { title?: string; content?: string; categoryCode?: CommunityCategory['code']; images?: EditableImage[]; requestKey?: string }
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
const categories = ref<CommunityCategory[]>(fallbackCategories)
const publishingRules = ref<CommunityPublishingRules>(DEFAULT_COMMUNITY_PUBLISHING_RULES)
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
  editingId.value = options?.postId || ''
  const requestedCategory = options?.category
  try {
    const result = await listCommunityCategories()
    categories.value = result.items
    publishingRules.value = result.publishing_rules || DEFAULT_COMMUNITY_PUBLISHING_RULES
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
function persistDraft() { uni.setStorageSync(draftKey(), { title: title.value, content: content.value, categoryCode: categoryCode.value, images: images.value, requestKey: submitRequestKey.value, savedAt: Date.now() }) }
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
  const payload = { title: title.value.trim(), content: content.value.trim(), category_code: categoryCode.value, image_file_ids: images.value.map(item => item.fileId) }
  try {
    const post = editingId.value ? await updateCommunityPost(auth.accessToken, editingId.value, payload) : await createCommunityPost(auth.accessToken, payload, submitRequestKey.value)
    uni.removeStorageSync(draftKey())
    uni.showToast({ title: post.status === 'published' ? '发布成功' : '内容已提交审核', icon: 'success' })
    setTimeout(() => uni.navigateBack(), 600)
  } catch (error) { submitError.value = errorMessage(error); persistDraft() }
  finally { submitting.value = false }
}
function categoryTip(code: CommunityCategory['code']) { return ({ experience: '经历与复盘', guide: '方法与步骤', question: '问题与求助', showcase: '作品与成果' } as Record<CommunityCategory['code'], string>)[code] }
function errorMessage(error: unknown) { return error instanceof Error ? error.message : '操作失败，请稍后重试。' }
</script>

<style scoped>
.page{min-height:100vh;padding:30rpx 28rpx 170rpx;box-sizing:border-box;background:var(--tm-canvas)}.intro{padding:22rpx 8rpx 10rpx}.eyebrow,.title,.subtitle,.section-title,.section-copy,.preview-category,.preview-title,.preview-content,.guidance-title,.state-title,.state-copy,.review-alert text,.submit-error text{display:block}.eyebrow{color:var(--tm-brand-600);font-size:13px;font-weight:850;letter-spacing:2.5rpx}.title{margin-top:10rpx;color:var(--tm-ink-900);font-size:24px;font-weight:950}.subtitle{margin-top:10rpx;color:var(--tm-ink-500);font-size:14px;line-height:1.65}.card,.state-card{margin-top:22rpx;padding:26rpx;border:1rpx solid var(--tm-line);border-radius:28rpx;background:#fff;box-shadow:var(--tm-shadow-card)}.field-head,.preview-head,.media-head{display:flex;align-items:center;justify-content:space-between;color:var(--tm-ink-500);font-size:13px}.field-head text:first-child,.preview-head text:first-child{color:var(--tm-ink-900);font-size:14px;font-weight:900}.category-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10rpx;margin-top:15rpx}.category-option{padding:17rpx;border:1rpx solid var(--tm-line);border-radius:16rpx;background:#fafcfc}.category-option text,.category-option small{display:block}.category-option text{font-size:14px;font-weight:850}.category-option small{margin-top:4rpx;color:var(--tm-ink-500);font-size:12px}.category-option.active{border-color:var(--tm-brand-600);background:var(--tm-brand-50);color:var(--tm-brand-700)}.divider{height:1rpx;margin:24rpx 0;background:var(--tm-line)}.title-input{height:76rpx;margin-top:8rpx;color:var(--tm-ink-900);font-size:17px;font-weight:800}.content-input{width:100%;height:380rpx;margin-top:15rpx;color:var(--tm-ink-700);font-size:14px;line-height:1.75}.media-card{padding:26rpx}.media-head>text{font-weight:800}.section-title{font-size:15px;font-weight:900}.section-copy{margin-top:4rpx;color:var(--tm-ink-500);font-size:12px}.image-grid,.preview-images{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10rpx;margin-top:18rpx}.image-item,.image-add{position:relative;height:158rpx;overflow:hidden;border-radius:16rpx}.image-item{background:var(--tm-surface-soft)}.image-item image{width:100%;height:100%}.image-placeholder,.image-add{display:flex;align-items:center;justify-content:center;flex-direction:column}.image-placeholder{width:100%;height:100%;color:var(--tm-ink-500)}.image-placeholder text{font-size:13px;font-weight:800}.image-placeholder small{margin-top:4rpx;font-size:10px}.remove-image{position:absolute;right:7rpx;top:7rpx;display:flex;align-items:center;justify-content:center;width:35rpx;height:35rpx;border-radius:50%;background:rgba(7,26,51,.72);color:#fff;font-size:15px}.image-add{border:2rpx dashed #afc9c6;background:var(--tm-brand-50);color:var(--tm-brand-700)}.image-add text{font-size:21px}.image-add small{margin-top:5rpx;font-size:12px}.inline-error{display:flex;justify-content:space-between;gap:15rpx;margin-top:14rpx;color:var(--tm-danger);font-size:13px}.inline-error text:last-child{flex:none;color:var(--tm-brand-700);font-weight:850}.guidance{margin-top:20rpx;padding:24rpx;border:1rpx solid #d6e9e7;border-radius:24rpx;background:#f2f9f8}.guidance-title{margin-bottom:12rpx;font-size:14px;font-weight:900}.guidance>view{display:flex;align-items:flex-start;gap:10rpx;margin-top:8rpx;color:var(--tm-ink-500);font-size:13px;line-height:1.55}.check{color:var(--tm-brand-600);font-weight:900}.preview-head text:last-child{color:var(--tm-brand-600);font-weight:800}.preview-category{margin-top:20rpx;color:var(--tm-reward-600);font-size:13px;font-weight:850}.preview-title{margin-top:8rpx;font-size:19px;font-weight:950}.preview-content{margin-top:13rpx;color:var(--tm-ink-700);font-size:14px;line-height:1.75;white-space:pre-wrap}.preview-images image{width:100%;height:150rpx;border-radius:14rpx}.review-alert,.submit-error{margin-top:20rpx;padding:20rpx;border-radius:17rpx;background:#fff2ef;color:#b42318;font-size:13px;line-height:1.55}.review-alert text:first-child,.submit-error text:first-child{font-weight:900}.submit-error text:last-child{margin-top:5rpx;color:var(--tm-ink-500)}.state-card{padding:55rpx 28rpx;text-align:center;color:var(--tm-ink-500)}.error-card{color:var(--tm-danger)}.state-title{font-size:15px;font-weight:900}.state-copy{margin-top:8rpx;font-size:13px}.retry{height:64rpx;margin-top:20rpx;border-radius:16rpx;background:var(--tm-brand-600);color:#fff;line-height:64rpx}.footer-actions{position:fixed;left:0;right:0;bottom:0;z-index:20;display:grid;grid-template-columns:.75fr .75fr 1.2fr;gap:12rpx;padding:18rpx 28rpx calc(18rpx + env(safe-area-inset-bottom));border-top:1rpx solid var(--tm-line);background:rgba(255,255,255,.97)}.footer-actions button{height:76rpx;margin:0;border-radius:18rpx;font-size:14px;font-weight:850;line-height:76rpx}.footer-actions button::after{border:0}.secondary{border:1rpx solid var(--tm-line);background:#fff;color:var(--tm-ink-700)}.primary{background:var(--tm-brand-600);color:#fff}@media screen and (min-width:768px){.page{width:750rpx;margin:0 auto}.footer-actions{left:50%;width:750rpx;box-sizing:border-box;transform:translateX(-50%)}}
.page{background:linear-gradient(180deg,var(--tm-campus-lime-soft) 0,var(--tm-campus-yellow-soft) 520rpx,var(--tm-canvas) 1040rpx)}
.intro,.card,.state-card,.guidance{border-width:2rpx;box-shadow:0 7rpx 0 rgba(22,22,22,.14)}
.intro{background:linear-gradient(135deg,var(--tm-campus-yellow),var(--tm-campus-coral))}
.category-option{border-width:2rpx}.category-option:nth-child(1){background:var(--tm-campus-cyan-soft)}.category-option:nth-child(2){background:var(--tm-campus-yellow-soft)}.category-option:nth-child(3){background:var(--tm-campus-lime-soft)}.category-option:nth-child(4){background:var(--tm-campus-coral-soft)}
.category-option.active{background:var(--tm-brand-600);color:#fff;box-shadow:0 5rpx 0 var(--tm-ink-900)}
.editor-card{background:#fffef6}.media-card{background:var(--tm-campus-cyan-soft)}
.title-input,.content-input,.image-item,.image-add{border:2rpx solid var(--tm-ink-900);background:#fff}.image-add{background:var(--tm-campus-lime);box-shadow:0 5rpx 0 var(--tm-ink-900)}
.guidance{background:var(--tm-campus-yellow-soft)}.preview-card{background:var(--tm-campus-purple-soft)}
.footer-actions{border-top:2rpx solid var(--tm-ink-900);background:#fffdf5}.footer-actions button{border:2rpx solid var(--tm-ink-900);box-shadow:0 5rpx 0 var(--tm-ink-900)}.primary{background:var(--tm-campus-coral);color:var(--tm-ink-900)}
.page{background:radial-gradient(circle at 92% 0,rgba(55,205,212,.18),transparent 28%),linear-gradient(180deg,#eefaff 0,#f7fbfd 44%,#f4f8fa 100%)!important}.intro{border:1rpx solid #d7e8ef!important;border-radius:28rpx;background:linear-gradient(135deg,#1268c9,#19abc4)!important;box-shadow:0 16rpx 34rpx rgba(29,104,145,.16)!important}.intro .eyebrow,.intro .title{color:#fff!important}.intro .subtitle{color:rgba(255,255,255,.82)!important}.card,.state-card,.guidance{border:1rpx solid #dce9ef!important;box-shadow:0 12rpx 30rpx rgba(34,78,103,.075)!important;background:rgba(255,255,255,.97)!important}.category-option{border:1rpx solid #d7e6ed!important;background:#f7fbfc!important;box-shadow:none!important}.category-option.active{border-color:#36b4c2!important;background:#e7f8fb!important;color:#146f88!important;box-shadow:none!important}.title-input,.content-input{border:1rpx solid #cfe1e9!important;background:#fbfdfe!important}.image-item,.image-add{border:1rpx solid #cfe1e9!important}.image-add{border-style:dashed!important;background:#eefafd!important;color:#1475c5!important;box-shadow:none!important}.guidance{background:linear-gradient(145deg,#effaf7,#f3fbfd)!important}.review-alert,.submit-error{border:1rpx solid #f0c8c1!important;background:#fff4f1!important;color:#b9382d!important}.footer-actions{border-top:1rpx solid #dce9ef!important;background:rgba(255,255,255,.95)!important;box-shadow:0 -10rpx 28rpx rgba(31,76,101,.08)}.footer-actions button{border:1rpx solid #d1e2e9!important;box-shadow:none!important}.footer-actions .primary{border:0!important;background:linear-gradient(135deg,#1268c9,#19a7c8)!important;box-shadow:0 8rpx 18rpx rgba(18,104,201,.16)!important}.footer-actions button[disabled]{opacity:.48;box-shadow:none!important}.retry{border:1rpx solid #b9dbe5!important;background:#fff!important;color:#1475c5!important}.state-spinner{width:42rpx;height:42rpx;margin:0 auto;border:6rpx solid #d3e8ee;border-top-color:#167bca;border-radius:50%;animation:community-compose-spin .8s linear infinite}.state-spinner+text{display:block;margin-top:16rpx}@keyframes community-compose-spin{to{transform:rotate(360deg)}}
</style>
<style scoped>
.editor-tools{display:flex;align-items:center;gap:14rpx;margin-top:13rpx}.editor-tools>text{color:#748a98;font-size:12px}.emoji-toggle{display:flex;align-items:center;justify-content:center;width:64rpx;height:58rpx;flex:none;margin:0;padding:0;border:1rpx solid #c9dfe7!important;border-radius:16rpx;background:#fff!important;color:#147c9b;font-size:23px;line-height:56rpx;box-shadow:none!important}.emoji-toggle.active{border-color:#5bc6c7!important;background:#e8f8f6!important}.emoji-toggle::after{border:0}.editor-card :deep(.emoji-picker){margin:14rpx -26rpx -26rpx}
</style>

<style scoped>
.page{--campus-ink:#111827;background:linear-gradient(180deg,#eaffd2 0,#fff9d8 520rpx,#f7f8fa 1050rpx);color:var(--campus-ink)}
.intro{margin:0 -4rpx;padding:28rpx 24rpx;border:3rpx solid var(--campus-ink);border-radius:26rpx;background:linear-gradient(135deg,#ffe33d,#ff8f58);box-shadow:0 9rpx 0 var(--campus-ink)}
.eyebrow{color:var(--campus-ink)}.title{color:var(--campus-ink);font-weight:950}.subtitle{color:#3f4857;font-weight:650}
.card,.state-card{border:3rpx solid var(--campus-ink);box-shadow:0 8rpx 0 rgba(17,24,39,.13)}
.category-option{border:2rpx solid var(--campus-ink);background:#fff;box-shadow:0 4rpx 0 rgba(17,24,39,.1)}
.category-option:nth-child(1){background:#c9f7e6}.category-option:nth-child(2){background:#fff1a8}.category-option:nth-child(3){background:#bcecff}.category-option:nth-child(4){background:#ffc9d2}
.category-option.active{border-color:var(--campus-ink);background:#111827;color:#fff;box-shadow:0 5rpx 0 #16d99a}.category-option.active small{color:#d8dde5}
.title-input,.content-input{padding:0 15rpx;border:2rpx solid var(--campus-ink);border-radius:15rpx;background:#fafbfc;box-sizing:border-box}.content-input{padding:15rpx}
.image-item,.image-add{border:2rpx solid var(--campus-ink)}.image-add{background:#d7faea;color:var(--campus-ink);box-shadow:0 5rpx 0 var(--campus-ink)}
.guidance{border:3rpx solid var(--campus-ink);background:#fff4ad;box-shadow:0 7rpx 0 rgba(17,24,39,.12)}
.check{color:#08a973}.footer-actions{border-top:3rpx solid var(--campus-ink)}.footer-actions button{border:2rpx solid var(--campus-ink);box-shadow:0 5rpx 0 var(--campus-ink)}.primary{background:#ff5e72;color:var(--campus-ink)}
</style>
<style scoped>
.page{background:radial-gradient(circle at 92% 0,rgba(55,205,212,.18),transparent 28%),linear-gradient(180deg,#eefaff 0,#f7fbfd 44%,#f4f8fa 100%)!important}.intro{border:1rpx solid #d7e8ef!important;border-radius:28rpx;background:linear-gradient(135deg,#1268c9,#19abc4)!important;box-shadow:0 16rpx 34rpx rgba(29,104,145,.16)!important}.intro .eyebrow,.intro .title{color:#fff!important}.intro .subtitle{color:rgba(255,255,255,.82)!important}.card,.state-card,.guidance{border:1rpx solid #dce9ef!important;box-shadow:0 12rpx 30rpx rgba(34,78,103,.075)!important;background:rgba(255,255,255,.97)!important}.category-option{border:1rpx solid #d7e6ed!important;background:#f7fbfc!important;box-shadow:none!important}.category-option.active{border-color:#36b4c2!important;background:#e7f8fb!important;color:#146f88!important;box-shadow:none!important}.title-input,.content-input{border:1rpx solid #cfe1e9!important;background:#fbfdfe!important}.image-item,.image-add{border:1rpx solid #cfe1e9!important}.image-add{border-style:dashed!important;background:#eefafd!important;color:#1475c5!important;box-shadow:none!important}.guidance{background:linear-gradient(145deg,#effaf7,#f3fbfd)!important}.review-alert,.submit-error{border:1rpx solid #f0c8c1!important;background:#fff4f1!important;color:#b9382d!important}.footer-actions{border-top:1rpx solid #dce9ef!important;background:rgba(255,255,255,.95)!important;box-shadow:0 -10rpx 28rpx rgba(31,76,101,.08)}.footer-actions button{border:1rpx solid #d1e2e9!important;box-shadow:none!important}.footer-actions .primary{border:0!important;background:linear-gradient(135deg,#1268c9,#19a7c8)!important;box-shadow:0 8rpx 18rpx rgba(18,104,201,.16)!important}.footer-actions button[disabled]{opacity:.48;box-shadow:none!important}.retry{border:1rpx solid #b9dbe5!important;background:#fff!important;color:#1475c5!important}.state-spinner{width:42rpx;height:42rpx;margin:0 auto;border:6rpx solid #d3e8ee;border-top-color:#167bca;border-radius:50%;animation:community-compose-spin-final .8s linear infinite}.state-spinner+text{display:block;margin-top:16rpx}@keyframes community-compose-spin-final{to{transform:rotate(360deg)}}
</style>
