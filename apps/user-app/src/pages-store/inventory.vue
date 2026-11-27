<template>
  <view class="page">
    <view v-if="campus" class="campus-bar"><text>当前校区</text><text class="campus-name">{{ campus.campus_name }}</text></view>
    <view class="toolbar">
      <input v-model="keyword" class="search" placeholder="搜索商品/规格" confirm-type="search" @confirm="reload" />
      <view class="low-toggle" :class="{ active: lowStock }" @tap="toggleLow">仅看预警</view>
    </view>

    <view v-if="loading" class="empty">加载中…</view>
    <view v-else-if="items.length === 0" class="empty">没有匹配的库存记录。</view>
    <view v-else class="list">
      <view v-for="item in items" :key="item.sku_id" class="card">
        <view class="card-top">
          <view class="main">
            <text class="product">{{ item.product_name }}</text>
            <text class="sku">{{ item.sku_name }}<text v-if="specText(item)"> · {{ specText(item) }}</text></text>
          </view>
          <text class="stock" :class="{ low: item.available_quantity <= 5 }">可售 {{ item.available_quantity }}</text>
        </view>
        <view class="numbers">
          <text>在库 {{ item.on_hand_quantity }}</text>
          <text>预占 {{ item.reserved_quantity }}</text>
        </view>
        <button class="adjust" @tap="openAdjust(item)">调整库存</button>
      </view>
    </view>

    <view v-if="adjusting" class="mask" @tap="closeAdjust">
      <view class="sheet" @tap.stop>
        <text class="sheet-title">{{ current?.product_name }} · {{ current?.sku_name }}</text>
        <view class="actions">
          <view v-for="option in actions" :key="option.value" class="action" :class="{ active: action === option.value }" @tap="action = option.value">{{ option.label }}</view>
        </view>
        <input v-model="quantity" class="sheet-input" type="number" placeholder="请输入数量" />
        <input v-model="reason" class="sheet-input" placeholder="调整原因（可选）" maxlength="200" />
        <button class="sheet-submit" :loading="submitting" :disabled="submitting" @tap="submitAdjust">确认调整</button>
      </view>
    </view>
  </view>
</template>

<script setup lang="ts">
import { onShow } from '@dcloudio/uni-app'
import { ref } from 'vue'
import { adjustManagerInventory, listManagerInventory, type StoreCampus, type StoreInventoryItem } from '@/api/stores'
import { getSelectedStoreCampus, requireStoreSession } from '@/utils/storeSession'

const session = requireStoreSession()
const campus = ref<StoreCampus | null>(null)
const items = ref<StoreInventoryItem[]>([])
const loading = ref(false)
const keyword = ref('')
const lowStock = ref(false)
const adjusting = ref(false)
const current = ref<StoreInventoryItem | null>(null)
const action = ref<'increase' | 'decrease' | 'set'>('increase')
const quantity = ref('')
const reason = ref('')
const submitting = ref(false)
const actions = [
  { value: 'increase', label: '补货' },
  { value: 'decrease', label: '出库' },
  { value: 'set', label: '盘点设为' },
] as const

const toast = (title: string) => uni.showToast({ title, icon: 'none' })
const specText = (item: StoreInventoryItem) => Object.entries(item.specs || {}).map(([key, value]) => `${key}:${value}`).join(', ')

const load = async () => {
  if (!session) return
  loading.value = true
  try {
    const result = await listManagerInventory(session.token, { keyword: keyword.value.trim(), low_stock: lowStock.value, campus_id: campus.value?.campus_id, page_size: 100 })
    items.value = result.items
  } catch (error) {
    toast(error instanceof Error ? error.message : '加载失败')
  } finally {
    loading.value = false
  }
}
const reload = () => { void load() }
const toggleLow = () => { lowStock.value = !lowStock.value; void load() }

const openAdjust = (item: StoreInventoryItem) => {
  current.value = item
  action.value = 'increase'
  quantity.value = ''
  reason.value = ''
  adjusting.value = true
}
const closeAdjust = () => { adjusting.value = false }

const submitAdjust = async () => {
  if (!session || !current.value) return
  const value = Number(quantity.value)
  if (!Number.isInteger(value) || value < 0) return toast('请输入正确的数量')
  submitting.value = true
  try {
    await adjustManagerInventory(session.token, current.value.sku_id, {
      action: action.value,
      quantity: value,
      version: current.value.version,
      reason: reason.value.trim() || undefined,
    }, campus.value?.campus_id)
    toast('库存已更新')
    adjusting.value = false
    await load()
  } catch (error) {
    toast(error instanceof Error ? error.message : '调整失败')
  } finally {
    submitting.value = false
  }
}

onShow(() => {
  if (session) campus.value = getSelectedStoreCampus(session.storeId)
  void load()
})
</script>

<style scoped>
.page { min-height: 100vh; background: #f5f6f8; padding: 24rpx 24rpx 80rpx; box-sizing: border-box; }
.campus-bar { display: flex; gap: 12rpx; margin-bottom: 16rpx; padding: 0 4rpx; font-size: 22rpx; color: #6b7280; }
.campus-name { color: #2f6bff; font-weight: 600; }
.toolbar { display: flex; gap: 16rpx; align-items: center; }
.search { flex: 1; background: #fff; border-radius: 999rpx; padding: 18rpx 28rpx; font-size: 26rpx; }
.low-toggle { font-size: 22rpx; color: #6b7280; background: #fff; padding: 14rpx 22rpx; border-radius: 999rpx; }
.low-toggle.active { background: #fff7ed; color: #d97706; }
.empty { padding: 140rpx 0; text-align: center; color: #9ca3af; font-size: 26rpx; }
.list { margin-top: 16rpx; }
.card { background: #fff; border-radius: 20rpx; padding: 22rpx; margin-bottom: 16rpx; }
.card-top { display: flex; justify-content: space-between; gap: 16rpx; }
.main { flex: 1; min-width: 0; }
.product { display: block; font-size: 27rpx; font-weight: 600; color: #111827; }
.sku { display: block; margin-top: 6rpx; font-size: 22rpx; color: #9ca3af; }
.stock { font-size: 24rpx; color: #059669; font-weight: 600; }
.stock.low { color: #d97706; }
.numbers { display: flex; gap: 28rpx; margin-top: 12rpx; font-size: 22rpx; color: #6b7280; }
.adjust { margin: 16rpx 0 0; font-size: 22rpx; height: 56rpx; line-height: 56rpx; border-radius: 999rpx; background: #eef2ff; color: #2f6bff; }
.mask { position: fixed; inset: 0; background: rgba(17, 24, 39, 0.45); display: flex; align-items: flex-end; z-index: 20; }
.sheet { width: 100%; background: #fff; border-radius: 28rpx 28rpx 0 0; padding: 32rpx 32rpx 56rpx; box-sizing: border-box; }
.sheet-title { display: block; font-size: 28rpx; font-weight: 700; color: #111827; }
.actions { display: flex; gap: 16rpx; margin-top: 24rpx; }
.action { flex: 1; text-align: center; padding: 16rpx 0; border-radius: 16rpx; background: #f3f4f6; color: #6b7280; font-size: 24rpx; }
.action.active { background: #eef2ff; color: #2f6bff; font-weight: 600; }
.sheet-input { margin-top: 20rpx; background: #f3f4f6; border-radius: 16rpx; padding: 20rpx 24rpx; font-size: 26rpx; }
.sheet-submit { margin-top: 28rpx; background: #2f6bff; color: #fff; border-radius: 999rpx; font-size: 28rpx; }
</style>
