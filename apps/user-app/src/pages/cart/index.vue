<template>
  <view class="page">
    <view class="heading"><text class="title">购物车</text><text class="sub">购物车不占库存，结算时会重新校验价格、库存、限购和运费</text></view>
    <view v-for="item in cart.items" :key="item.id" class="card" :class="{ invalid: !item.valid }">
      <view class="row"><text class="name">{{ item.product_name }}</text><switch :checked="item.selected" color="#ef6c35" @change="toggle(item, $event)" /></view>
      <text class="meta">{{ item.sku_name }} · {{ modeText(item.purchase_mode) }}</text>
      <text class="price">{{ priceText(item) }}</text>
      <text v-if="!item.valid" class="warning">当前不可结算：{{ reasonText(item.invalid_reason) }}</text>
      <view class="row actions"><view><button class="qty" @click="changeQuantity(item, -1)">−</button><text class="count">{{ item.quantity }}</text><button class="qty" @click="changeQuantity(item, 1)">+</button></view><button class="delete" @click="remove(item)">删除</button></view>
    </view>
    <view v-if="cart.items.length" class="quote-card">
      <picker :range="addressLabels" @change="selectAddress"><view class="address">{{ selectedAddress ? `配送到：${selectedAddress.province}${selectedAddress.city}${selectedAddress.district}` : '选择收货地址试算运费' }}</view></picker>
      <text v-if="quote" class="shipping">预估运费 ¥{{ (quote.shipping_cash_amount / 100).toFixed(2) }}（只能使用现金）</text>
      <picker :range="paymentLabels" :value="paymentIndex" @change="selectPayment"><view class="address payment">现金部分：{{ paymentLabels[paymentIndex] }}</view></picker>
      <button class="checkout" :loading="submitting" :disabled="submitting || !canCheckout" @click="previewOrder">结算预览</button>
    </view>
    <view v-if="preview" class="preview-card">
      <view class="row"><text class="preview-title">将拆为 {{ preview.groups.length }} 个订单</text><text class="expires">5 分钟内有效</text></view>
      <view v-for="group in preview.groups" :key="group.group_key" class="group">
        <text>{{ fulfillmentText(group.fulfillment_type) }}</text><text>{{ amountText(group.payable_cash_amount, group.payable_points) }}</text>
      </view>
      <view class="row total"><text>合计</text><text>{{ amountText(preview.payable_cash_amount, preview.payable_points) }}</text></view>
      <text v-if="preview.shipping_amount" class="shipping">其中现金运费 ¥{{ (preview.shipping_amount / 100).toFixed(2) }}</text>
      <button class="confirm" :loading="submitting" :disabled="submitting" @click="createOrder">确认创建订单</button>
    </view>
    <text v-else class="empty">购物车还是空的</text>
  </view>
</template>

<script setup lang="ts">
import { onShow } from '@dcloudio/uni-app'
import { computed, ref } from 'vue'
import { deleteCartItem, getCart, quoteShipping, updateCartItem, type Cart, type CartItem, type ShippingQuote } from '@/api/cart'
import { listAddresses, type UserAddress } from '@/api/account'
import { createOrders, previewCheckout, type CashPaymentMethod, type CheckoutInput, type CheckoutPreview, type FulfillmentType } from '@/api/orders'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore(); const cart = ref<Cart>({ items: [], summary: { item_count: 0, selected_quantity: 0, all_valid: true } }); const addresses = ref<UserAddress[]>([]); const selectedAddress = ref<UserAddress | null>(null); const quote = ref<ShippingQuote | null>(null)
const preview = ref<CheckoutPreview | null>(null); const previewInput = ref<CheckoutInput | null>(null); const submitting = ref(false); const paymentIndex = ref(0)
const addressLabels = computed(() => addresses.value.map((item) => `${item.recipient_name} · ${item.province}${item.city}${item.district}`))
const paymentLabels = ['微信全额支付', '余额全额支付']
const canCheckout = computed(() => { const selected = cart.value.items.filter((item) => item.selected); return selected.length > 0 && selected.every((item) => item.valid) && (!selected.some((item) => item.fulfillment_type === 'logistics') || !!selectedAddress.value) })
onShow(async () => { if (!auth.accessToken) return uni.navigateTo({ url: '/pages/login/index' }); await load() })
async function load() { if (!auth.accessToken) return; [cart.value, addresses.value] = await Promise.all([getCart(auth.accessToken), listAddresses(auth.accessToken)]); selectedAddress.value = addresses.value.find((item) => item.is_default) || addresses.value[0] || null; if (selectedAddress.value) await loadQuote() }
async function toggle(item: CartItem, event: Event) { if (!auth.accessToken) return; invalidatePreview(); const selected = (event as CustomEvent<{ value: boolean }>).detail.value; cart.value = await updateCartItem(auth.accessToken, item.id, { selected }); await loadQuote() }
async function changeQuantity(item: CartItem, delta: number) { if (!auth.accessToken) return; invalidatePreview(); const quantity = item.quantity + delta; if (quantity < 1) return remove(item); try { cart.value = await updateCartItem(auth.accessToken, item.id, { quantity }); await loadQuote() } catch (error) { uni.showToast({ title: error instanceof Error ? error.message : '数量更新失败', icon: 'none' }) } }
async function remove(item: CartItem) { if (!auth.accessToken) return; invalidatePreview(); await deleteCartItem(auth.accessToken, item.id); await load() }
async function selectAddress(event: { detail: { value: number } }) { invalidatePreview(); selectedAddress.value = addresses.value[Number(event.detail.value)] || null; await loadQuote() }
function selectPayment(event: { detail: { value: number } }) { invalidatePreview(); paymentIndex.value = Number(event.detail.value) }
async function loadQuote() { quote.value = null; if (!auth.accessToken || !selectedAddress.value || !cart.value.items.some((item) => item.selected && item.fulfillment_type === 'logistics')) return; try { quote.value = await quoteShipping(auth.accessToken, selectedAddress.value.id) } catch (error) { uni.showToast({ title: error instanceof Error ? error.message : '运费试算失败', icon: 'none' }) } }
function buildInput(): CheckoutInput {
  const items = cart.value.items.filter((item) => item.selected && item.valid)
  const paymentMethod: CashPaymentMethod = paymentIndex.value === 1 ? 'wallet' : 'wechat'
  const fulfillmentTypes = [...new Set(items.map((item) => item.fulfillment_type as FulfillmentType))]
  return { capability_version: 1, items: items.map((item) => ({ sku_id: item.sku_id, quantity: item.quantity, purchase_mode: item.purchase_mode })), group_options: fulfillmentTypes.map((type) => {
    const group = items.filter((item) => item.fulfillment_type === type)
    const hasCash = group.some((item) => (item.unit_cash_amount || 0) > 0) || type === 'logistics'
    return { fulfillment_type: type, ...(type === 'logistics' && selectedAddress.value ? { address_id: selectedAddress.value.id } : {}), ...(hasCash ? { cash_payment_method: paymentMethod } : {}) }
  }) }
}
async function previewOrder() { if (!auth.accessToken || !canCheckout.value) return; submitting.value = true; try { const input = buildInput(); preview.value = await previewCheckout(auth.accessToken, input); previewInput.value = input } catch (error) { uni.showToast({ title: error instanceof Error ? error.message : '结算预览失败', icon: 'none' }) } finally { submitting.value = false } }
async function createOrder() { if (!auth.accessToken || !preview.value || !previewInput.value) return; submitting.value = true; try { const input = { ...previewInput.value, preview_token: preview.value.preview_token }; await createOrders(auth.accessToken, input, `checkout-${Date.now()}-${Math.random().toString(36).slice(2)}`); uni.showToast({ title: '订单创建成功', icon: 'success' }); setTimeout(() => uni.redirectTo({ url: '/pages/orders/index' }), 500) } catch (error) { invalidatePreview(); uni.showToast({ title: error instanceof Error ? error.message : '创建订单失败，请重新预览', icon: 'none' }) } finally { submitting.value = false } }
function invalidatePreview() { preview.value = null; previewInput.value = null }
function modeText(mode: string) { return ({ cash: '现金', points: '积分', cash_points: '现金 + 积分' } as Record<string, string>)[mode] || mode }
function priceText(item: CartItem) { const cash = item.unit_cash_amount ? `¥${(item.unit_cash_amount / 100).toFixed(2)}` : ''; const points = item.unit_points ? `${item.unit_points} 积分` : ''; return [cash, points].filter(Boolean).join(' + ') }
function reasonText(reason: string | null) { return ({ product_off_shelf: '商品已下架', sku_inactive: '规格已停用', purchase_mode_unavailable: '购买方式已关闭', insufficient_stock: '库存不足' } as Record<string, string>)[reason || ''] || '规则已变化' }
function fulfillmentText(type: string) { return ({ logistics: '物流配送', no_logistics: '无需物流', virtual_delivery: '虚拟交付' } as Record<string, string>)[type] || type }
function amountText(cash: number, points: number) { return [cash ? `¥${(cash / 100).toFixed(2)}` : '', points ? `${points} 积分` : ''].filter(Boolean).join(' + ') || '无需支付' }
</script>

<style scoped>
.page{min-height:100vh;padding:32rpx;background:#f5f7fb;box-sizing:border-box}.heading{padding:18rpx 8rpx}.title,.sub,.meta,.price,.warning,.shipping,.empty{display:block}.title{font-size:42rpx;font-weight:750}.sub{margin-top:10rpx;color:#667085;font-size:24rpx}.card,.quote-card,.preview-card{margin-top:20rpx;padding:28rpx;background:#fff;border-radius:22rpx}.card.invalid{border:2rpx solid #fdb7a0}.row{display:flex;justify-content:space-between;align-items:center}.name{font-size:30rpx;font-weight:700}.meta{margin-top:10rpx;color:#667085;font-size:24rpx}.price{margin-top:14rpx;color:#d94b17;font-weight:700}.warning{margin-top:12rpx;color:#d92d20;font-size:24rpx}.actions{margin-top:20rpx}.qty,.delete{display:inline-block;margin:0;padding:0;border:0;background:#f2f4f7;font-size:26rpx}.qty{width:56rpx;height:56rpx;line-height:56rpx}.count{display:inline-block;width:64rpx;text-align:center}.delete{padding:0 22rpx;height:56rpx;line-height:56rpx;color:#d92d20}.qty::after,.delete::after{border:0}.address{padding:20rpx;border-radius:14rpx;background:#f8fafc}.payment{margin-top:16rpx}.shipping{margin-top:18rpx;color:#d94b17;font-weight:700}.checkout,.confirm{margin-top:20rpx;background:#ef6c35;color:#fff}.preview-title{font-weight:700}.expires{color:#667085;font-size:23rpx}.group{display:flex;justify-content:space-between;margin-top:18rpx;padding:18rpx;background:#f8fafc;border-radius:14rpx}.total{margin-top:22rpx;font-weight:700}.empty{padding:100rpx;text-align:center;color:#98a2b3}
</style>
