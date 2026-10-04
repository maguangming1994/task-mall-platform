<template>
  <view class="page">
    <view class="subnav">
      <view class="back" @click="goBack"><text>‹</text></view>
      <text class="nav-title">确认购买</text>
      <text class="nav-side" />
    </view>

    <view v-if="loading" class="card loading-card">
      <view class="skel-img tm-skeleton" />
      <view class="skel-main">
        <view class="skel-line tm-skeleton" />
        <view class="skel-line short tm-skeleton" />
        <view class="skel-line price tm-skeleton" />
      </view>
    </view>

    <template v-else-if="item">
      <!-- 商品 + 卖家 -->
      <view class="card item-card">
        <view class="goods">
          <image v-if="item.cover" class="cover" :src="resolveApiAssetUrl(item.cover)" mode="aspectFill" />
          <view v-else class="cover placeholder"><text>{{ item.title.slice(0, 1) }}</text></view>
          <view class="goods-main">
            <text class="title">{{ item.title }}</text>
            <view class="tags">
              <text class="tag tag-green">{{ item.condition_label }}</text>
              <text class="tag">{{ tradeLabel }}</text>
            </view>
            <view class="price-row">
              <text class="price">¥{{ item.price }}</text>
              <text class="qty">×1</text>
            </view>
          </view>
        </view>
        <view class="seller-row" @click="goSeller">
          <image v-if="item.seller?.avatar" class="avatar" :src="resolveApiAssetUrl(item.seller.avatar)" mode="aspectFill" />
          <view v-else class="avatar placeholder"><text>{{ item.seller?.nickname?.slice(0, 1) || '卖' }}</text></view>
          <text class="seller-name">卖家 <text class="seller-b">{{ item.seller?.nickname || '校园用户' }}</text></text>
          <text class="chat" @click.stop="goChat">聊一聊</text>
        </view>
      </view>

      <!-- 交易方式 -->
      <view class="card form-card">
        <view class="frow">
          <text class="label">交易方式</text>
          <view class="chips">
            <text
              v-for="opt in tradeOptions"
              :key="opt.value"
              class="chip"
              :class="{ on: tradeType === opt.value }"
              @click="selectTrade(opt.value)"
            >{{ opt.label }}</text>
          </view>
        </view>

        <view v-if="tradeType === 'meetup'" class="frow location-row">
          <text class="label">面交地点</text>
          <text class="value">{{ item.location || '与卖家协商校内面交地点' }}</text>
        </view>
        <view v-else class="frow location-row" @click="chooseAddress">
          <text class="label">收货地址</text>
          <text v-if="selectedAddress" class="value">{{ selectedAddress.recipient_name }} {{ selectedAddress.phone }} {{ selectedAddress.detail }}</text>
          <text v-else class="value placeholder">选择收货地址</text>
          <text class="arrow">›</text>
        </view>

        <view class="frow">
          <text class="label">联系电话</text>
          <text class="value placeholder">默认使用平台虚拟号保护隐私</text>
        </view>
      </view>

      <!-- 支付与留言 -->
      <view class="card form-card">
        <view class="frow">
          <text class="label">支付方式</text>
          <text class="spacer" />
          <text class="value wallet">钱包余额 {{ wallet ? `¥${(wallet.available_amount / 100).toFixed(2)}` : '读取中' }}</text>
          <view class="radio-on" />
        </view>
        <view class="frow">
          <text class="label">买家留言</text>
          <input v-model="message" class="value input" maxlength="20" placeholder="选填，留言给卖家（20 字以内）" placeholder-class="ph" />
        </view>
      </view>

      <!-- 托管提示 -->
      <view class="escrow">
        <view class="escrow-icon"><text>🛡</text></view>
        <text class="escrow-copy">支付后款项由平台托管，确认收货后结算给卖家；取消订单将全额退款。</text>
      </view>

      <!-- 底部操作栏 -->
      <view class="action-bar">
        <view class="total-box">
          <text class="total-label">合计</text>
          <text class="total-price">¥{{ item.price }}</text>
        </view>
        <button class="pay-btn" :loading="submitting" :disabled="submitting || !canSubmit" @click="submit">确认支付</button>
      </view>
    </template>

    <view v-else class="error-state">
      <text class="error-title">商品信息不可用</text>
      <text class="error-sub">{{ loadError || '商品可能已被售出或下架' }}</text>
      <button class="back-btn" @click="goBack">返回</button>
    </view>
  </view>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue'
import { onLoad } from '@dcloudio/uni-app'
import { getSecondHandItem, createSecondHandOrder, type SecondHandItem, type SecondHandTradeType } from '@/api/second-hand'
import { ensureChatConversation } from '@/api/chat'
import { getWalletAccount, type WalletAccount } from '@/api/assets'
import { listAddresses, type UserAddress } from '@/api/account'
import { resolveApiAssetUrl } from '@/api/http'
import { useAuthStore } from '@/stores/auth'
import { navigateToLogin } from '@/utils/authRedirect'

type TradeChoice = 'meetup' | 'mail'
const auth = useAuthStore()
const itemId = ref('')
const item = ref<SecondHandItem | null>(null)
const wallet = ref<WalletAccount | null>(null)
const addresses = ref<UserAddress[]>([])
const selectedAddressId = ref<string | null>(null)
const tradeType = ref<TradeChoice>('meetup')
const message = ref('')
const loading = ref(true)
const loadError = ref('')
const submitting = ref(false)

const selectedAddress = computed(() => addresses.value.find((a) => a.id === selectedAddressId.value) || addresses.value.find((a) => a.is_default) || null)
const tradeOptions = computed<Array<{ value: TradeChoice; label: string }>>(() => {
  const t = item.value?.trade_type ?? 'meetup'
  if (t === 'meetup') return [{ value: 'meetup', label: '校内面交' }]
  if (t === 'mail') return [{ value: 'mail', label: '快递发货' }]
  return [
    { value: 'meetup', label: '校内面交' },
    { value: 'mail', label: '快递发货' },
  ]
})
const tradeLabel = computed(() => (tradeType.value === 'meetup' ? '校内面交' : '快递包邮'))
const canSubmit = computed(() => {
  if (!item.value || submitting.value) return false
  if (tradeType.value === 'mail' && !selectedAddress.value) return false
  return true
})

onLoad((query) => {
  if (!auth.isAuthenticated) return navigateToLogin()
  itemId.value = typeof query?.id === 'string' ? query.id : ''
  load()
})

async function load() {
  if (!auth.accessToken || !itemId.value) return
  loading.value = true
  loadError.value = ''
  try {
    const [detail, account, addressList] = await Promise.all([
      getSecondHandItem(auth.accessToken, itemId.value),
      getWalletAccount(auth.accessToken).catch(() => null),
      listAddresses(auth.accessToken).catch(() => [] as UserAddress[]),
    ])
    item.value = detail
    wallet.value = account
    addresses.value = addressList
    const allowed = tradeOptions.value.map((o) => o.value)
    const preferred: TradeChoice = detail.trade_type === 'mail' ? 'mail' : 'meetup'
    tradeType.value = allowed.includes(preferred) ? preferred : allowed[0]
  } catch (error) {
    loadError.value = error instanceof Error ? error.message : '加载失败'
  } finally {
    loading.value = false
  }
}

function selectTrade(value: TradeChoice) {
  tradeType.value = value
}

function chooseAddress() {
  if (!addresses.value.length) {
    uni.showModal({
      title: '暂无收货地址',
      content: '先去添加一个收货地址？',
      confirmText: '去添加',
      success: (r) => { if (r.confirm) uni.navigateTo({ url: '/pages/addresses/index' }) },
    })
    return
  }
  uni.showActionSheet({
    itemList: addresses.value.map((a) => `${a.recipient_name} ${a.phone} ${a.detail}`),
    success: (r) => {
      const picked = addresses.value[r.tapIndex]
      if (picked) selectedAddressId.value = picked.id
    },
  })
}

async function submit() {
  if (!canSubmit.value || !item.value || !auth.accessToken) return
  submitting.value = true
  try {
    const order = await createSecondHandOrder(
      auth.accessToken,
      {
        item_id: item.value.id,
        trade_type: tradeType.value,
        ...(message.value.trim() ? { buyer_message: message.value.trim() } : {}),
        ...(tradeType.value === 'mail' && selectedAddress.value
          ? {
              address: {
                recipient_name: selectedAddress.value.recipient_name,
                phone: selectedAddress.value.phone,
                province: selectedAddress.value.province,
                city: selectedAddress.value.city,
                district: selectedAddress.value.district,
                detail: selectedAddress.value.detail,
              },
            }
          : {}),
      },
      `sh-buy-${Date.now()}`,
    )
    uni.redirectTo({ url: `/pages/second-hand-order-detail/index?id=${encodeURIComponent(order.id)}` })
  } catch (error) {
    uni.showToast({ title: error instanceof Error ? error.message : '下单失败', icon: 'none' })
    submitting.value = false
  }
}

async function goChat() {
  if (!item.value?.seller?.id) return
  try {
    const conv = await ensureChatConversation(auth.accessToken!, { user_id: item.value.seller.id, item_id: item.value.id })
    uni.navigateTo({ url: `/pages/chat-detail/index?id=${conv.id}` })
  } catch {
    uni.showToast({ title: '发起聊天失败', icon: 'none' })
  }
}

function goSeller() {
  if (!item.value?.seller?.id) return
  uni.navigateTo({ url: `/pages/sh-user/index?id=${item.value.seller.id}` })
}

function goBack() {
  uni.navigateBack({ fail: () => uni.redirectTo({ url: '/pages/second-hand/index' }) })
}
</script>

<style scoped>
.page { min-height: 100vh; background: #F4F6F4; padding-bottom: calc(180rpx + env(safe-area-inset-bottom)); }

.subnav { display: flex; align-items: center; height: 88rpx; padding: 0 24rpx; padding-top: var(--status-bar-height, 0); background: #0BBF63; }
.back { width: 64rpx; height: 64rpx; display: flex; align-items: center; justify-content: center; }
.back text { font-size: 52rpx; line-height: 1; color: #fff; margin-top: -6rpx; }
.nav-title { flex: 1; text-align: center; font-size: 32rpx; font-weight: 800; color: #fff; }
.nav-side { width: 64rpx; }

.card { margin: 20rpx 24rpx 0; background: #fff; border-radius: 24rpx; box-shadow: 0 4rpx 16rpx rgba(25, 27, 31, 0.05); }
.item-card { padding: 28rpx; }
.goods { display: flex; gap: 22rpx; }
.cover { width: 152rpx; height: 152rpx; border-radius: 20rpx; flex: none; background: #EEF1EF; }
.cover.placeholder { display: flex; align-items: center; justify-content: center; }
.cover.placeholder text { font-size: 44rpx; color: #08A152; font-weight: 800; }
.goods-main { flex: 1; min-width: 0; display: flex; flex-direction: column; }
.title { font-size: 26rpx; font-weight: 700; color: #191B1F; line-height: 1.45; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
.tags { display: flex; gap: 10rpx; margin-top: 12rpx; }
.tag { padding: 4rpx 14rpx; border-radius: 8rpx; background: #F2F4F3; color: #767C85; font-size: 20rpx; }
.tag-green { background: #E7F9EF; color: #08A152; }
.price-row { display: flex; align-items: center; margin-top: auto; }
.price { font-size: 34rpx; color: #FF2442; font-weight: 800; }
.qty { margin-left: auto; font-size: 24rpx; color: #767C85; }

.seller-row { display: flex; align-items: center; gap: 14rpx; margin-top: 24rpx; padding-top: 24rpx; border-top: 2rpx solid #F5F6F6; }
.avatar { width: 52rpx; height: 52rpx; border-radius: 50%; background: #E7F9EF; }
.avatar.placeholder { display: flex; align-items: center; justify-content: center; }
.avatar.placeholder text { font-size: 24rpx; color: #08A152; }
.seller-name { font-size: 24rpx; color: #767C85; }
.seller-b { color: #191B1F; font-weight: 700; }
.chat { margin-left: auto; font-size: 24rpx; color: #08A152; font-weight: 700; }
.chat::before { content: '💬 '; }

.form-card { padding: 8rpx 28rpx; }
.frow { display: flex; align-items: center; min-height: 96rpx; padding: 20rpx 0; border-bottom: 2rpx solid #F5F6F6; }
.frow:last-child { border-bottom: 0; }
.label { font-size: 27rpx; color: #191B1F; font-weight: 600; flex: none; }
.spacer { flex: 1; }
.value { font-size: 25rpx; color: #191B1F; text-align: right; }
.value.placeholder, .ph { color: #AEB3BB; }
.value.wallet { color: #08A152; font-weight: 700; }
.input { flex: 1; margin-left: 24rpx; font-size: 25rpx; }
.arrow { margin-left: 10rpx; color: #AEB3BB; font-size: 32rpx; }
.location-row .value { flex: 1; margin-left: 24rpx; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

.chips { display: flex; gap: 14rpx; margin-left: 24rpx; flex: 1; justify-content: flex-end; flex-wrap: wrap; }
.chip { padding: 12rpx 26rpx; border-radius: 30rpx; background: #F2F4F3; color: #767C85; font-size: 24rpx; }
.chip.on { background: #E7F9EF; color: #08A152; font-weight: 700; box-shadow: inset 0 0 0 2rpx #0BBF63; }
.radio-on { width: 36rpx; height: 36rpx; margin-left: 14rpx; border-radius: 50%; background: #0BBF63; position: relative; flex: none; }
.radio-on::after { content: ''; position: absolute; left: 12rpx; top: 8rpx; width: 8rpx; height: 14rpx; border: solid #fff; border-width: 0 3rpx 3rpx 0; transform: rotate(45deg); }

.escrow { display: flex; align-items: center; gap: 16rpx; margin: 20rpx 24rpx 0; padding: 22rpx 26rpx; background: #FFF8F2; border-radius: 20rpx; }
.escrow-icon { width: 56rpx; height: 56rpx; border-radius: 16rpx; background: #FFEEDD; display: flex; align-items: center; justify-content: center; flex: none; }
.escrow-icon text { font-size: 28rpx; }
.escrow-copy { font-size: 22rpx; color: #767C85; line-height: 1.6; }

.action-bar { position: fixed; left: 0; right: 0; bottom: 0; display: flex; align-items: center; gap: 20rpx; padding: 16rpx 24rpx calc(16rpx + env(safe-area-inset-bottom)); background: #fff; box-shadow: 0 -4rpx 20rpx rgba(25, 27, 31, 0.06); }
.total-box { flex: 1; display: flex; align-items: baseline; gap: 10rpx; }
.total-label { font-size: 24rpx; color: #767C85; }
.total-price { font-size: 42rpx; font-weight: 800; color: #FF2442; }
.pay-btn { flex: none; width: 280rpx; height: 84rpx; line-height: 84rpx; border-radius: 42rpx; background: linear-gradient(135deg, #12D978, #08A152); color: #fff; font-size: 30rpx; font-weight: 700; box-shadow: 0 10rpx 22rpx rgba(11, 191, 99, 0.28); }
.pay-btn::after { border: 0; }
.pay-btn[disabled] { opacity: 0.55; }

.loading-card { padding: 28rpx; display: flex; gap: 22rpx; }
.skel-img { width: 152rpx; height: 152rpx; border-radius: 20rpx; flex: none; }
.skel-main { flex: 1; }
.skel-line { height: 28rpx; border-radius: 8rpx; margin-bottom: 18rpx; }
.skel-line.short { width: 60%; }
.skel-line.price { width: 40%; margin-top: auto; }

.error-state { padding: 180rpx 48rpx; text-align: center; display: flex; flex-direction: column; align-items: center; }
.error-title { font-size: 32rpx; font-weight: 800; color: #191B1F; }
.error-sub { margin-top: 14rpx; font-size: 25rpx; color: #AEB3BB; }
.back-btn { margin-top: 44rpx; width: 300rpx; height: 80rpx; line-height: 80rpx; border-radius: 40rpx; background: #F2F4F3; color: #191B1F; font-size: 28rpx; }
.back-btn::after { border: 0; }
</style>
