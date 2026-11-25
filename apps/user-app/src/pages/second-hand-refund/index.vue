<template>
  <view>
    <ModulePlaceholder v-if="modules.resolved && !moduleEnabled" code="module_second_hand" />
    <view v-else-if="!modules.resolved" class="mp-skeleton">
      <view class="tm-skeleton" style="height: 200rpx; border-radius: 24rpx; margin: 24rpx 28rpx;" />
      <view class="tm-skeleton" style="height: 320rpx; border-radius: 24rpx; margin: 20rpx 28rpx;" />
    </view>
    <template v-else>
  <view class="page" v-if="order && loaded">
    <!-- ===== 退款状态视图 ===== -->
    <template v-if="refund">
      <view class="card status" :class="statusTone">
        <view class="status-line">
          <text class="status-title">{{ refundStatusText }}</text>
          <text v-if="refund.status === 'pending' && countdown" class="status-count">剩 {{ countdown }}</text>
        </view>
        <text class="status-desc">{{ refundStatusDesc }}</text>
      </view>

      <!-- 退款进度 -->
      <view class="card">
        <view class="sec-title"><text class="sec-h">退款进度</text><text class="sec-more">订单 {{ order.order_no }}</text></view>
        <view class="tl">
          <view v-for="(row, idx) in timeline" :key="idx" class="tl-row" :class="row.state">
            <view class="tl-dot" />
            <view class="tl-main">
              <text class="tl-title">{{ row.title }}</text>
              <text class="tl-desc">{{ row.desc }}</text>
            </view>
          </view>
        </view>
      </view>

      <!-- 退款信息 -->
      <view class="card">
        <view class="sec-title"><text class="sec-h">退款信息</text></view>
        <view class="kv"><text class="k">退款金额</text><text class="v price">¥{{ refund.amount }}（原路退回）</text></view>
        <view class="kv"><text class="k">退款原因</text><text class="v">{{ refund.reason }}</text></view>
        <view v-if="refund.seller_note" class="kv"><text class="k">卖家说明</text><text class="v">{{ refund.seller_note }}</text></view>
        <view v-if="refund.admin_note" class="kv"><text class="k">平台说明</text><text class="v">{{ refund.admin_note }}</text></view>
        <view v-if="refund.images.length" class="evidence">
          <image v-for="(img, idx) in refund.images" :key="idx" class="ev-img" :src="resolveCover(img)" mode="aspectFill" @click="previewEvidence(idx)" />
        </view>
      </view>

      <view class="action-bar">
        <template v-if="isSeller && refund.status === 'pending'">
          <button class="ab-ghost" :disabled="acting" @click="reject">拒绝</button>
          <button class="ab-main" :disabled="acting" @click="approve">同意退款</button>
        </template>
        <template v-else-if="isBuyer && refund.status === 'pending'">
          <button class="ab-ghost" :disabled="acting" @click="cancel">撤销申请</button>
          <button class="ab-main" @click="contact">联系卖家</button>
        </template>
        <template v-else-if="isBuyer && refund.status === 'seller_rejected'">
          <button class="ab-ghost" @click="contact">联系卖家</button>
          <button class="ab-main" :disabled="acting" @click="escalate">申请平台介入</button>
        </template>
        <template v-else-if="isBuyer && refund.status === 'cancelled'">
          <button class="ab-ghost" @click="contact">联系卖家</button>
          <button class="ab-main" @click="reapply">重新申请</button>
        </template>
        <template v-else-if="isBuyer && ['arbitrating', 'arbitrated_rejected'].includes(refund.status)">
          <button class="ab-ghost" @click="contactSupport">联系客服</button>
          <button class="ab-main" @click="contact">联系卖家</button>
        </template>
        <template v-else>
          <button class="ab-main full" @click="contact">{{ isBuyer ? '联系卖家' : '联系买家' }}</button>
        </template>
      </view>
    </template>

    <!-- ===== 申请退款表单 ===== -->
    <template v-else>
      <view class="card goods">
        <image v-if="order.cover" class="cover" :src="resolveCover(order.cover)" mode="aspectFill" />
        <view v-else class="cover placeholder"><text>{{ order.title.slice(0, 1) }}</text></view>
        <view class="goods-main">
          <text class="goods-title">{{ order.title }}</text>
          <text class="goods-sub">订单 {{ order.order_no }}</text>
        </view>
        <text class="goods-price">¥{{ order.price }}</text>
      </view>

      <view class="card form">
        <view class="kv"><text class="k">退款金额</text><text class="v price">¥{{ order.price }}（原路退回）</text></view>
        <view class="frow column-row">
          <text class="label">退款原因</text>
          <view class="chip-pick">
            <text v-for="r in reasonOptions" :key="r" class="chip" :class="{ on: reason === r }" @click="reason = r">{{ r }}</text>
          </view>
        </view>
        <view class="frow textarea-row">
          <textarea v-model="detail" maxlength="200" placeholder="补充说明（选填）：问题描述、期望处理方式…" auto-height />
        </view>
        <view class="frow upload-row">
          <text class="label">凭证</text>
          <view class="upload-grid">
            <view v-for="(img, idx) in images" :key="img.fileId" class="u-item">
              <image :src="img.previewUrl" mode="aspectFill" @click="previewUpload(idx)" />
              <text class="u-del" @click.stop="images.splice(idx, 1)">×</text>
            </view>
            <view v-if="images.length < 9" class="u-item add" @click="upload">
              <text class="u-plus">＋</text>
              <text class="u-text">上传凭证</text>
            </view>
          </view>
        </view>
      </view>

      <view class="notice">卖家将在 24 小时内处理，超时未处理将自动同意退款；卖家拒绝后可申请平台介入。</view>

      <view class="action-bar">
        <button class="ab-main full" :disabled="submitting || !reason" @click="submit">提交退款申请</button>
      </view>
    </template>
  </view>
    </template>
  </view>
</template>

<script setup lang="ts">
import { onLoad, onUnload } from '@dcloudio/uni-app'
import { computed, ref } from 'vue'
import { useAuthStore } from '@/stores/auth'
import {
  cancelSecondHandRefund, escalateSecondHandRefund, getSecondHandOrder, getSecondHandRefund,
  requestSecondHandRefund, respondSecondHandRefund,
  type SecondHandOrder, type SecondHandRefund,
} from '@/api/second-hand'
import { ensureChatConversation } from '@/api/chat'
import { chooseAndUploadPrivateImage } from '@/api/upload'
import { ApiError, resolveApiAssetUrl } from '@/api/http'
import { formatDateTime } from '@/utils/dateTime'
import ModulePlaceholder from '@/components/ModulePlaceholder.vue'
import { useModulesStore } from '@/stores/modules'

const modules = useModulesStore()
const moduleEnabled = computed(() => modules.isEnabled('module_second_hand'))

const auth = useAuthStore()
const order = ref<SecondHandOrder | null>(null)
const refund = ref<SecondHandRefund | null>(null)
const loaded = ref(false)
const orderId = ref('')
const submitting = ref(false)
const uploading = ref(false)
const acting = ref(false)

const reasonOptions = ['与描述不符', '质量问题', '未收到货', '不想要了', '其他']
const reason = ref('')
const detail = ref('')
const images = ref<Array<{ fileId: string; previewUrl: string }>>([])

const nowTick = ref(Date.now())
let timer: ReturnType<typeof setInterval> | undefined

const isBuyer = computed(() => order.value?.role === 'buyer')
const isSeller = computed(() => order.value?.role === 'seller')

const statusTextMap: Record<string, string> = {
  pending: '退款处理中',
  seller_approved: '退款成功',
  seller_rejected: '卖家拒绝退款',
  arbitrating: '平台仲裁中',
  arbitrated_approved: '平台仲裁通过',
  arbitrated_rejected: '平台仲裁未通过',
  cancelled: '退款申请已撤销',
}

const refundStatusText = computed(() => statusTextMap[refund.value?.status || ''] || refund.value?.status)
const refundStatusDesc = computed(() => {
  const r = refund.value
  if (!r) return ''
  switch (r.status) {
    case 'pending': return '卖家将在 24 小时内处理，超时未处理将自动同意退款；卖家拒绝后可申请平台介入。'
    case 'seller_approved': return `退款 ¥${r.amount} 已原路退回。`
    case 'seller_rejected': return '卖家拒绝了退款申请，可联系卖家协商，或申请平台介入仲裁。'
    case 'arbitrating': return '平台正在核实双方凭证并作出仲裁，请耐心等待。'
    case 'arbitrated_approved': return `平台仲裁通过，退款 ¥${r.amount} 已原路退回。`
    case 'arbitrated_rejected': return '平台仲裁后驳回了退款申请，如有疑问请联系平台客服。'
    case 'cancelled': return '你已主动撤销退款申请，订单继续履约；如仍有问题可重新申请或联系客服。'
    default: return ''
  }
})
const statusTone = computed(() => {
  const s = refund.value?.status
  if (s === 'seller_approved' || s === 'arbitrated_approved') return 'green'
  if (s === 'seller_rejected' || s === 'arbitrated_rejected') return 'red'
  if (s === 'cancelled') return 'gray'
  return 'orange'
})

const countdown = computed(() => {
  const r = refund.value
  if (!r || r.status !== 'pending' || !r.auto_approve_at) return ''
  const diff = new Date(r.auto_approve_at).getTime() - nowTick.value
  if (diff <= 0) return '00:00:00'
  const h = Math.floor(diff / 3600000)
  const m = Math.floor((diff % 3600000) / 60000)
  const s = Math.floor((diff % 60000) / 1000)
  const pad = (n: number) => String(n).padStart(2, '0')
  return `${pad(h)}:${pad(m)}:${pad(s)}`
})

const timeline = computed(() => {
  const r = refund.value
  if (!r) return [] as Array<{ title: string; desc: string; state: string }>
  const rows: Array<{ title: string; desc: string; state: string }> = [
    { title: '提交退款申请', desc: `${formatTime(r.created_at)} · 原因：${r.reason}`, state: 'done' },
  ]
  switch (r.status) {
    case 'pending':
      rows.push({ title: '等待卖家处理', desc: countdown.value ? `剩余 ${countdown.value}，超时自动同意退款` : '超时将自动同意退款', state: 'doing' })
      rows.push({ title: '平台退款', desc: '卖家同意后 1-3 分钟内原路退回', state: 'todo' })
      rows.push({ title: '退款完成', desc: `退款金额 ¥${r.amount}`, state: 'todo' })
      break
    case 'seller_approved':
      rows.push({ title: '卖家已同意', desc: formatTime(r.seller_responded_at), state: 'done' })
      rows.push({ title: '平台退款', desc: '退款已原路退回余额', state: 'done' })
      rows.push({ title: '退款完成', desc: `退款金额 ¥${r.amount}`, state: 'done' })
      break
    case 'seller_rejected':
      rows.push({ title: '卖家拒绝退款', desc: r.seller_note || '卖家拒绝了退款申请', state: 'doing' })
      rows.push({ title: '平台介入', desc: '如无法协商一致，可申请平台介入仲裁', state: 'todo' })
      break
    case 'arbitrating':
      rows.push({ title: '卖家拒绝退款', desc: r.seller_note || '', state: 'done' })
      rows.push({ title: '平台仲裁中', desc: '平台正在核实双方凭证，请耐心等待', state: 'doing' })
      break
    case 'arbitrated_approved':
      rows.push({ title: '平台仲裁通过', desc: r.admin_note || '', state: 'done' })
      rows.push({ title: '平台退款', desc: '退款已原路退回余额', state: 'done' })
      rows.push({ title: '退款完成', desc: `退款金额 ¥${r.amount}`, state: 'done' })
      break
    case 'arbitrated_rejected':
      rows.push({ title: '平台仲裁拒绝', desc: r.admin_note || '平台驳回了退款申请', state: 'done' })
      break
    case 'cancelled':
      rows.push({ title: '退款申请已撤销', desc: '买家主动撤销，订单继续履约；如有需要可重新申请', state: 'cancel' })
      break
  }
  return rows
})

function resolveCover(id: string | null) { return resolveApiAssetUrl(id) }
function formatTime(s: string | null) { return formatDateTime(s, '') }

onLoad((q) => {
  orderId.value = q?.id || ''
  timer = setInterval(() => { nowTick.value = Date.now() }, 1000)
  load()
})
onUnload(() => { if (timer) clearInterval(timer) })

async function load() {
  await modules.ensureReady()
  if (!moduleEnabled.value) return
  if (!auth.accessToken || !orderId.value) return
  try {
    const o = await getSecondHandOrder(auth.accessToken, orderId.value)
    order.value = o
    try {
      refund.value = await getSecondHandRefund(auth.accessToken, orderId.value)
    } catch (e) {
      if (!(e instanceof ApiError && e.statusCode === 404)) throw e
      refund.value = null
    }
    if (!refund.value && (o.role !== 'buyer' || !['paid', 'shipped'].includes(o.status))) {
      uni.showToast({ title: '当前订单不可申请退款', icon: 'none' })
      setTimeout(() => uni.navigateBack(), 600)
    }
  } catch { uni.showToast({ title: '加载失败', icon: 'none' }) }
  finally { loaded.value = true }
}

async function contact() {
  if (!order.value?.counterparty?.id) return
  try {
    const conv = await ensureChatConversation(auth.accessToken, { user_id: order.value.counterparty.id, item_id: order.value.item_id || undefined, order_id: order.value.id })
    uni.navigateTo({ url: `/pages/chat-detail/index?id=${conv.id}` })
  } catch { uni.showToast({ title: '发起聊天失败', icon: 'none' }) }
}

function contactSupport() { uni.navigateTo({ url: '/pages/support/index' }) }

function reapply() {
  refund.value = null
  reason.value = ''
  detail.value = ''
  images.value = []
}

function cancel() {
  if (!refund.value || acting.value) return
  uni.showModal({
    title: '撤销退款申请',
    content: '撤销后本次退款关闭，订单继续履约；之后仍可重新发起申请。确定撤销吗？',
    confirmText: '确定撤销',
    confirmColor: '#d14343',
    success: async (r) => {
      if (!r.confirm || !order.value) return
      acting.value = true
      try {
        await cancelSecondHandRefund(auth.accessToken, order.value.id)
        uni.showToast({ title: '已撤销退款申请', icon: 'success' })
        await load()
      } catch (e: any) { uni.showToast({ title: e.message || '撤销失败', icon: 'none' }) }
      finally { acting.value = false }
    },
  })
}

async function upload() {
  if (uploading.value || !auth.accessToken) return
  uploading.value = true
  try { images.value.push(await chooseAndUploadPrivateImage(auth.accessToken, 'refund_evidence')) }
  catch (e: any) { uni.showToast({ title: e.message || '图片上传失败', icon: 'none' }) }
  finally { uploading.value = false }
}

function previewUpload(idx: number) { uni.previewImage({ urls: images.value.map((i) => i.previewUrl), current: idx }) }
function previewEvidence(idx: number) {
  if (!refund.value) return
  uni.previewImage({ urls: refund.value.images.map((i) => resolveApiAssetUrl(i)), current: idx })
}

async function submit() {
  if (!order.value || !auth.accessToken || submitting.value || !reason.value) return
  submitting.value = true
  try {
    const text = detail.value.trim() ? `${reason.value} · ${detail.value.trim()}` : reason.value
    await requestSecondHandRefund(auth.accessToken, order.value.id, {
      reason: text,
      images: images.value.length ? images.value.map((i) => i.fileId) : undefined,
    })
    uni.showToast({ title: '退款申请已提交', icon: 'success' })
    await load()
  } catch (e: any) {
    uni.showToast({ title: e.message || '提交失败', icon: 'none' })
  } finally { submitting.value = false }
}

function approve() {
  if (!refund.value || acting.value) return
  uni.showModal({
    title: '同意退款',
    content: `确认同意退款 ¥${refund.value.amount}？退款将立即退回买家。`,
    success: async (r) => {
      if (!r.confirm || !order.value) return
      acting.value = true
      try {
        await respondSecondHandRefund(auth.accessToken, order.value.id, { action: 'approve' })
        uni.showToast({ title: '已同意退款', icon: 'success' })
        await load()
      } catch (e: any) { uni.showToast({ title: e.message || '操作失败', icon: 'none' }) }
      finally { acting.value = false }
    },
  })
}

function reject() {
  if (!refund.value || acting.value) return
  uni.showModal({
    title: '拒绝退款',
    editable: true,
    placeholderText: '拒绝原因（选填）',
    success: async (r) => {
      if (!r.confirm || !order.value) return
      acting.value = true
      try {
        await respondSecondHandRefund(auth.accessToken, order.value.id, { action: 'reject', seller_note: r.content?.trim() || undefined })
        uni.showToast({ title: '已拒绝', icon: 'success' })
        await load()
      } catch (e: any) { uni.showToast({ title: e.message || '操作失败', icon: 'none' }) }
      finally { acting.value = false }
    },
  })
}

function escalate() {
  if (!refund.value || acting.value) return
  uni.showModal({
    title: '申请平台介入',
    content: '提交后平台将核实双方凭证进行仲裁，确认申请？',
    success: async (r) => {
      if (!r.confirm || !order.value) return
      acting.value = true
      try {
        await escalateSecondHandRefund(auth.accessToken, order.value.id)
        uni.showToast({ title: '已申请平台介入', icon: 'success' })
        await load()
      } catch (e: any) { uni.showToast({ title: e.message || '操作失败', icon: 'none' }) }
      finally { acting.value = false }
    },
  })
}
</script>

<style scoped>
.page { --green:#0BBF63; --green-deep:#08A152; --green-grad:linear-gradient(135deg,#12D978,#08A152); --green-soft:#E7F9EF; --orange:#FF7A1A; --orange-soft:#FFF2E5; --orange-deep:#F2620A; --t1:#191B1F; --t2:#767C85; --t3:#AEB3BB; --line:#EDEFEF; --price:#FF2442; min-height:100vh; background:#F4F6F4; padding-bottom:180rpx; }
.card { background:#fff; border-radius:24rpx; margin:20rpx 24rpx 0; padding:0 28rpx 24rpx; }
.sec-title { display:flex; align-items:center; justify-content:space-between; padding:28rpx 0 20rpx; }
.sec-h { font-size:30rpx; font-weight:700; display:flex; align-items:center; }
.sec-h::before { content:''; width:8rpx; height:28rpx; border-radius:4rpx; background:var(--green); margin-right:14rpx; }
.sec-more { font-size:24rpx; color:var(--t3); }

/* 状态卡 */
.status { padding:28rpx; }
.status.orange { background:var(--orange-soft); }
.status.green { background:var(--green-soft); }
.status.red { background:#FDECEC; }
.status.gray { background:#F2F4F3; }
.status-line { display:flex; align-items:center; }
.status-title { font-size:30rpx; font-weight:700; }
.status.orange .status-title, .status.orange .status-count { color:var(--orange-deep); }
.status.green .status-title { color:var(--green-deep); }
.status.red .status-title { color:#D4380D; }
.status.gray .status-title { color:var(--t2); }
.status-count { margin-left:auto; font-size:22rpx; }
.status-desc { font-size:23rpx; color:var(--t2); margin-top:12rpx; line-height:1.5; display:block; }

/* 时间线 */
.tl { padding:8rpx 0 4rpx; }
.tl-row { display:flex; gap:22rpx; position:relative; padding-bottom:36rpx; }
.tl-row:last-child { padding-bottom:4rpx; }
.tl-row::before { content:''; position:absolute; left:13rpx; top:34rpx; bottom:0; width:4rpx; background:#ECF0EE; }
.tl-row:last-child::before { display:none; }
.tl-dot { width:28rpx; height:28rpx; border-radius:50%; background:#fff; border:4rpx solid #D6DAD8; flex:none; margin-top:4rpx; position:relative; z-index:1; }
.tl-row.done .tl-dot { border-color:var(--green); background:var(--green); }
.tl-row.doing .tl-dot { border-color:var(--orange); background:#fff; box-shadow:0 0 0 8rpx var(--orange-soft); }
.tl-main { flex:1; min-width:0; }
.tl-title { font-size:27rpx; font-weight:600; color:var(--t1); display:block; }
.tl-row.todo .tl-title { color:var(--t3); }
.tl-row.doing .tl-title { color:var(--orange-deep); }
.tl-row.cancel .tl-dot { border-color:#AEB3BB; background:#AEB3BB; }
.tl-row.cancel .tl-title { color:var(--t2); }
.tl-desc { font-size:22rpx; color:var(--t3); margin-top:6rpx; line-height:1.5; display:block; }

/* 键值与凭证 */
.kv { display:flex; justify-content:space-between; gap:24rpx; padding:22rpx 0; border-bottom:2rpx solid #F5F6F6; font-size:24rpx; }
.kv:last-of-type { border-bottom:0; }
.k { color:var(--t2); flex:none; }
.v { color:var(--t1); text-align:right; flex:1; }
.v.price { color:var(--price); font-weight:700; }
.evidence { display:grid; grid-template-columns:repeat(4,1fr); gap:16rpx; padding-top:20rpx; }
.ev-img { width:100%; aspect-ratio:1; border-radius:16rpx; background:#F2F4F3; }

/* 商品回顾 */
.goods { display:flex; gap:22rpx; align-items:center; padding:24rpx 28rpx; }
.cover { width:108rpx; height:108rpx; border-radius:20rpx; background:#F2F4F3; flex:none; }
.cover.placeholder { display:flex; align-items:center; justify-content:center; color:var(--t3); font-size:40rpx; }
.goods-main { flex:1; min-width:0; }
.goods-title { font-size:26rpx; color:var(--t1); line-height:1.4; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; }
.goods-sub { font-size:22rpx; color:var(--t3); margin-top:8rpx; display:block; }
.goods-price { font-size:30rpx; color:var(--price); font-weight:800; flex:none; }

/* 表单 */
.form { padding-bottom:8rpx; }
.frow { display:flex; align-items:center; gap:20rpx; padding:28rpx 0; border-bottom:2rpx solid #F5F6F6; }
.frow:last-child { border-bottom:0; }
.frow.column-row { flex-direction:column; align-items:flex-start; gap:16rpx; }
.label { font-size:28rpx; font-weight:600; flex:none; }
.chip-pick { display:flex; gap:16rpx; flex-wrap:wrap; }
.chip { height:60rpx; line-height:60rpx; padding:0 26rpx; border-radius:30rpx; border:2rpx solid var(--line); font-size:24rpx; color:var(--t2); background:#fff; }
.chip.on { border-color:var(--green); color:var(--green-deep); background:var(--green-soft); font-weight:600; }
.textarea-row { align-items:flex-start; }
.textarea-row textarea { width:100%; min-height:140rpx; font-size:28rpx; line-height:1.6; color:var(--t1); }
.upload-row { align-items:flex-start; }
.upload-row .label { padding-top:24rpx; }
.upload-grid { flex:1; display:grid; grid-template-columns:repeat(4,1fr); gap:16rpx; padding:16rpx 0 24rpx; }
.u-item { position:relative; width:100%; aspect-ratio:1; border-radius:16rpx; overflow:hidden; background:#F2F4F3; }
.u-item image { width:100%; height:100%; }
.u-del { position:absolute; top:4rpx; right:4rpx; width:36rpx; height:36rpx; border-radius:50%; background:rgba(25,27,31,.6); color:#fff; font-size:24rpx; line-height:36rpx; text-align:center; }
.u-item.add { border:3rpx dashed #D7DBD8; background:#F7F9F8; display:flex; flex-direction:column; align-items:center; justify-content:center; gap:8rpx; color:var(--t3); }
.u-plus { font-size:40rpx; }
.u-text { font-size:20rpx; }
.notice { margin:20rpx 24rpx 0; padding:22rpx 28rpx; border-radius:20rpx; background:var(--green-soft); color:var(--green-deep); font-size:22rpx; line-height:1.55; }

/* 底部操作栏 */
.action-bar { position:fixed; left:0; right:0; bottom:0; background:#fff; border-top:2rpx solid var(--line); display:flex; gap:16rpx; padding:16rpx 24rpx calc(16rpx + env(safe-area-inset-bottom)); }
.action-bar button { margin:0; }
.action-bar button::after { border:0; }
.ab-ghost { flex:1; height:84rpx; line-height:84rpx; border-radius:42rpx; background:#F2F4F3; color:var(--t2); font-size:28rpx; font-weight:700; }
.ab-main { flex:1.6; height:84rpx; line-height:84rpx; border-radius:42rpx; background:var(--green-grad); color:#fff; font-size:30rpx; font-weight:700; box-shadow:0 12rpx 28rpx rgba(11,191,99,.3); }
.ab-main.full { width:100%; flex:none; }
.ab-main[disabled] { opacity:.6; }
</style>
