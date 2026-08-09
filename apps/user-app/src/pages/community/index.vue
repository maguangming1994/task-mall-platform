<template>
  <view class="page">
    <view class="heading"><text class="eyebrow">GROW TOGETHER</text><text class="title">成长社区</text><text class="sub">分享真实经验，在友善交流中一起进步</text></view>
    <button v-if="auth.isAuthenticated" class="primary" @click="compose">发布帖子</button>
    <view v-for="post in posts" :key="post.id" class="card">
      <view class="row"><view class="author-row"><view class="author-mark">{{ post.author.nickname.slice(0, 1) }}</view><text class="author">{{ post.author.nickname }}</text></view><text class="meta">{{ post.comment_count }} 条评论</text></view>
      <text class="post-title">{{ post.title }}</text><text class="content">{{ post.content }}</text>
      <view class="actions"><button size="mini" @click="toggleComments(post)">{{ opened === post.id ? '收起评论' : '查看评论' }}</button><button v-if="auth.isAuthenticated" size="mini" @click="comment(post)">发表评论</button><button v-if="auth.isAuthenticated && auth.user?.id !== post.author.id" size="mini" @click="report('post', post.id)">举报</button></view>
      <view v-if="opened === post.id" class="comments">
        <view v-for="item in comments" :key="item.id" class="comment"><text class="author">{{ item.author.nickname }}</text><text>{{ item.content }}</text><button v-if="auth.isAuthenticated && !item.parent_comment_id" size="mini" @click="comment(post, item)">回复</button><button v-if="auth.isAuthenticated && auth.user?.id !== item.author.id" size="mini" @click="report('comment', item.id)">举报</button></view>
        <text v-if="!comments.length" class="meta">暂无评论</text>
      </view>
    </view>
    <view v-if="actions.length" class="heading"><text class="title small">我的内容处置</text></view>
    <view v-for="action in actions" :key="action.id" class="card"><view class="row"><text>{{ action.action_type }} · {{ action.reason_code }}</text><text class="meta">{{ action.status }}</text></view><text v-if="action.appeal" class="meta">申诉：{{ action.appeal.status }} {{ action.appeal.review_note || '' }}</text><button v-else-if="action.status === 'effective'" size="mini" class="appeal" @click="appeal(action.id)">发起申诉</button></view>
    <text v-if="!posts.length" class="empty">暂无已发布帖子</text>
    <AppTabBar current-target="community" />
  </view>
</template>

<script setup lang="ts">
import { onPullDownRefresh, onShow } from '@dcloudio/uni-app'
import { ref } from 'vue'
import AppTabBar from '@/components/AppTabBar.vue'
import { appealModerationAction, createCommunityComment, createCommunityPost, listCommunityComments, listCommunityPosts, listMyModerationActions, reportCommunityContent, type CommunityComment, type CommunityPost, type ModerationAction } from '@/api/community'
import { useAuthStore } from '@/stores/auth'
const auth = useAuthStore(); const posts = ref<CommunityPost[]>([]); const comments = ref<CommunityComment[]>([]); const actions = ref<ModerationAction[]>([]); const opened = ref('')
onShow(load)
onPullDownRefresh(async () => { try { await load() } finally { uni.stopPullDownRefresh() } })
async function load() { try { posts.value = (await listCommunityPosts()).items; actions.value = auth.accessToken ? (await listMyModerationActions(auth.accessToken)).items : [] } catch (error) { toast(error) } }
async function compose() { if (!auth.accessToken) return; const title = await input('帖子标题（5～60字）'); if (!title) return; const content = await input('帖子正文（20～5000字）'); if (!content) return; try { const post = await createCommunityPost(auth.accessToken, title, content); uni.showToast({ title: post.status === 'published' ? '发布成功' : '已提交审核', icon: 'success' }); await load() } catch (error) { toast(error) } }
async function toggleComments(post: CommunityPost) { if (opened.value === post.id) { opened.value = ''; comments.value = []; return } opened.value = post.id; try { comments.value = (await listCommunityComments(post.id)).items } catch (error) { toast(error) } }
async function comment(post: CommunityPost, parent?: CommunityComment) { if (!auth.accessToken) return; const content = await input(parent ? `回复 ${parent.author.nickname}` : '发表评论'); if (!content) return; try { const result = await createCommunityComment(auth.accessToken, post.id, content, parent?.id); uni.showToast({ title: result.status === 'published' ? '评论成功' : '评论待审核', icon: 'success' }); await toggleComments(post); await toggleComments(post); await load() } catch (error) { toast(error) } }
async function report(type: 'post' | 'comment', id: string) { if (!auth.accessToken) return; const description = await input('请补充举报说明'); if (!description) return; try { await reportCommunityContent(auth.accessToken, type, id, 'other', description); uni.showToast({ title: '举报已提交', icon: 'success' }) } catch (error) { toast(error) } }
async function appeal(id: string) { if (!auth.accessToken) return; const reason = await input('请填写申诉理由'); if (!reason) return; try { await appealModerationAction(auth.accessToken, id, reason); uni.showToast({ title: '申诉已提交', icon: 'success' }); await load() } catch (error) { toast(error) } }
function input(title: string): Promise<string | null> { return new Promise((resolve) => uni.showModal({ title, editable: true, success: (result) => resolve(result.confirm ? result.content || null : null) })) }
function toast(error: unknown) { uni.showToast({ title: error instanceof Error ? error.message : '操作失败', icon: 'none' }) }
</script>

<style scoped>
.page{min-height:100vh;padding:32rpx;background:#f5f7fb;box-sizing:border-box}.heading{padding:18rpx 8rpx}.title,.sub,.post-title,.content,.empty{display:block}.title{font-size:42rpx;font-weight:750}.title.small{font-size:34rpx}.sub,.meta{margin-top:8rpx;color:#667085;font-size:24rpx}.primary{background:#3157d5;color:#fff}.card{margin-top:20rpx;padding:28rpx;background:#fff;border-radius:22rpx}.row,.actions{display:flex;justify-content:space-between;gap:16rpx}.author{font-weight:650}.post-title{margin-top:20rpx;font-size:32rpx;font-weight:750}.content{margin-top:14rpx;line-height:1.7;color:#344054}.actions{justify-content:flex-start;margin-top:22rpx;flex-wrap:wrap}.actions button,.comment button{margin:0}.comments{margin-top:20rpx;padding-top:16rpx;border-top:1rpx solid #eef0f3}.comment{display:flex;gap:14rpx;align-items:flex-start;padding:14rpx 0;flex-wrap:wrap}.appeal{margin:18rpx 0 0}.empty{padding:120rpx;text-align:center;color:#98a2b3}
.page{padding:32rpx 28rpx 90rpx;background:var(--tm-canvas)}.heading{position:relative;overflow:hidden;padding:34rpx 32rpx;border-radius:30rpx;background:linear-gradient(135deg,#0f7258,#18a476);box-shadow:0 18rpx 42rpx rgba(21,150,106,.16);color:#fff}.heading::after{content:'';position:absolute;right:-70rpx;top:-85rpx;width:230rpx;height:230rpx;border:38rpx solid rgba(255,255,255,.09);border-radius:50%}.eyebrow{position:relative;display:block;color:#c9f0e3;font-size:19rpx;font-weight:750;letter-spacing:2rpx}.heading .title{position:relative;margin-top:10rpx;font-size:40rpx;font-weight:850}.heading .sub{position:relative;color:rgba(255,255,255,.77);font-size:23rpx}.primary{height:78rpx;margin-top:20rpx;border-radius:20rpx;background:var(--tm-brand-600);font-size:25rpx;font-weight:750;line-height:78rpx}.card{padding:27rpx;border:1rpx solid var(--tm-line);border-radius:27rpx;box-shadow:0 10rpx 26rpx rgba(35,49,80,.04)}.row,.author-row{display:flex;align-items:center}.author-row{gap:12rpx}.author-mark{display:flex;align-items:center;justify-content:center;width:54rpx;height:54rpx;border-radius:18rpx;background:#e3f6ef;color:#158763;font-size:22rpx;font-weight:850}.author{font-weight:750}.post-title{font-size:30rpx;font-weight:800}.content{color:var(--tm-ink-700);font-size:25rpx}.actions button,.comment button{height:54rpx;padding:0 18rpx;border-radius:14rpx;background:#f3f6fa;color:var(--tm-ink-700);font-size:21rpx;line-height:54rpx}.comments{border-color:var(--tm-line)}
@media screen and (min-width:768px){.page{width:750rpx;margin:0 auto}}
</style>
