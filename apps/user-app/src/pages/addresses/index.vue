<template>
  <view class="page tm-page-shell">
    <view class="section-head">
      <view><text class="title">收货地址</text><text class="sub">管理配送地址，物流商品下单前需要先添加地址</text></view>
      <button class="add-link" @click="startCreate">＋ 新增</button>
    </view>

    <view v-if="loading && !addresses.length" class="state-card loading-state" role="status" aria-live="polite"><text class="state-title">正在加载收货地址…</text><text class="state-copy">地址信息仅用于商城履约。</text></view>
    <view v-else-if="loadError" class="state-card error-state" role="alert"><text class="state-title">地址加载失败</text><text class="state-copy">{{ loadError }}</text><button class="retry-button" @click="load">重新加载</button></view>

    <view v-for="item in addresses" :key="item.id" class="address-card">
      <view class="address-top">
        <text>{{ item.recipient_name }}</text>
        <text class="address-phone">{{ maskPhone(item.phone) }}</text>
        <text v-if="item.is_default" class="default-tag">默认</text>
      </view>
      <text class="address-detail">{{ item.province }}{{ item.city }}{{ item.district }}{{ item.detail }}</text>
      <view class="card-actions">
        <button v-if="!item.is_default" @click="setDefault(item)">设为默认</button>
        <button @click="edit(item)">编辑</button>
        <button class="danger" @click="remove(item.id)">删除</button>
      </view>
    </view>

    <view v-if="!addresses.length && !loading && !loadError" class="empty">
      <text>还没有收货地址</text>
      <text>物流商品下单前需要先添加地址</text>
      <button class="empty-action" @click="startCreate">新增收货地址</button>
    </view>

    <view v-if="formVisible" class="form-card">
      <view class="form-head"><text>{{ editingId ? '编辑收货地址' : '新增收货地址' }}</text><text class="close" @click="closeForm">关闭</text></view>
      <view class="two-column"><input v-model="form.recipient_name" class="input" maxlength="80" placeholder="收货人姓名" /><input v-model="form.phone" class="input" type="number" maxlength="20" placeholder="联系电话" /></view>
      <picker mode="region" :value="region" @change="selectRegion"><view class="input region">{{ regionText }}</view></picker>
      <input v-model="form.detail" class="input" maxlength="255" placeholder="详细地址，如街道、门牌号" />
      <input v-model="form.postal_code" class="input" maxlength="20" placeholder="邮政编码（选填）" />
      <label class="default-check"><checkbox value="default" :checked="form.is_default" color="#0BBF63" @change="onDefaultChange" />设为默认收货地址</label>
      <view v-if="formError" class="form-error" role="alert"><text>{{ formError }}</text><button @click="formError = ''">知道了</button></view>
      <button class="save" :loading="saving" @click="save">保存地址</button>
    </view>

    <view class="privacy-card">
      <view class="privacy-card-head"><text>隐私与数据管理</text><text>保护账户信息</text></view>
      <view class="privacy-row" @click="openPrivacy"><text class="row-icon blue">盾</text><view><text>数据使用说明</text><small>了解平台如何使用你的个人信息</small></view><text class="privacy-arrow">›</text></view>
      <view class="privacy-row" @click="openPrivacy"><text class="row-icon green">锁</text><view><text>隐私权限设置</text><small>管理授权与可见范围</small></view><text class="privacy-arrow">›</text></view>
      <view class="privacy-row" @click="openPrivacy"><text class="row-icon yellow">导</text><view><text>个人信息导出</text><small>申请导出账户及相关数据</small></view><text class="privacy-arrow">›</text></view>
      <view class="privacy-row" @click="openPrivacy"><text class="row-icon blue">注</text><view><text>注销账户</text><small>永久删除账户及个人数据</small></view><text class="privacy-arrow">›</text></view>
    </view>
    <view class="support-banner" @click="openSupport"><text class="support-icon">i</text><text>你的信任对我们很重要，如有疑问可联系平台客服</text><text class="support-link">联系客服 ›</text></view>
    <view class="privacy-note">地址信息仅用于商城履约，并受隐私政策保护。</view>
    <AppTabBar current-target="account" />
  </view>
</template>

<script setup lang="ts">
import { onShow } from '@dcloudio/uni-app'
import { computed, reactive, ref } from 'vue'
import { createAddress, deleteAddress, listAddresses, updateAddress, type AddressInput, type UserAddress } from '@/api/account'
import AppTabBar from '@/components/AppTabBar.vue'
import { useAuthStore } from '@/stores/auth'
import { navigateToLogin } from '@/utils/authRedirect'

const auth = useAuthStore()
const addresses = ref<UserAddress[]>([])
const loading = ref(false)
const loadError = ref('')
const saving = ref(false)
const formError = ref('')
const formVisible = ref(false)
const editingId = ref('')
const region = ref<string[]>([])
const form = reactive<AddressInput>({ recipient_name: '', phone: '', province: '', city: '', district: '', detail: '', postal_code: '', is_default: false })
const regionText = computed(() => region.value.length === 3 ? region.value.join(' / ') : '选择省 / 市 / 区')

onShow(load)
async function load() {
  if (!auth.isAuthenticated) return navigateToLogin()
  loading.value = true
  loadError.value = ''
  try { addresses.value = await listAddresses(auth.accessToken) }
  catch (error) { loadError.value = error instanceof Error ? error.message : '请检查网络后重试'; toast(error, '地址加载失败') }
  finally { loading.value = false }
}
function startCreate() { reset(); formError.value = ''; formVisible.value = true }
function edit(item: UserAddress) { editingId.value = item.id; Object.assign(form, item); region.value = [item.province, item.city, item.district]; formVisible.value = true; uni.pageScrollTo({ scrollTop: 99999, duration: 240 }) }
function closeForm() { formVisible.value = false; formError.value = ''; reset() }
function reset() { editingId.value = ''; region.value = []; Object.assign(form, { recipient_name: '', phone: '', province: '', city: '', district: '', detail: '', postal_code: '', is_default: false }) }
function selectRegion(event: { detail: { value: string[] } }) { region.value = event.detail.value; [form.province, form.city, form.district] = event.detail.value }
function onDefaultChange(event: { detail: { value: string[] } }) { form.is_default = event.detail.value.includes('default') }
async function save() {
  if (!form.recipient_name || !form.phone || !form.province || !form.city || !form.district || !form.detail) return uni.showToast({ title: '请填写完整收货信息', icon: 'none' })
  saving.value = true
  formError.value = ''
  try {
    if (editingId.value) await updateAddress(auth.accessToken, editingId.value, { ...form })
    else await createAddress(auth.accessToken, { ...form })
    closeForm(); await load(); uni.showToast({ title: '地址已保存', icon: 'success' })
  } catch (error) { formError.value = error instanceof Error ? error.message : '地址保存失败，请稍后重试'; toast(error, '保存失败') }
  finally { saving.value = false }
}
async function setDefault(item: UserAddress) { try { await updateAddress(auth.accessToken, item.id, { is_default: true }); await load() } catch (error) { toast(error, '设置失败') } }
async function remove(id: string) {
  const result = await uni.showModal({ title: '删除地址', content: '删除后无法恢复，确认继续？' })
  if (!result.confirm) return
  try { await deleteAddress(auth.accessToken, id); await load() } catch (error) { toast(error, '删除失败') }
}
function maskPhone(value: string) { return value ? value.replace(/(\d{3})\d{4}(\d{4})/, '$1****$2') : '未填写电话' }
function openPrivacy() { uni.navigateTo({ url: '/pages/privacy/index' }) }
function openSupport() { uni.navigateTo({ url: '/pages/support/index' }) }
function toast(error: unknown, fallback: string) { uni.showToast({ title: error instanceof Error ? error.message : fallback, icon: 'none' }) }
</script>

<style scoped>
.page{min-height:100vh;padding:24rpx 28rpx 120rpx;background:#F4F6F4;color:#191B1F}
/* 页头 */
.section-head,.address-top,.card-actions,.form-head,.default-check{display:flex;align-items:center}
.section-head{justify-content:space-between;margin:8rpx 2rpx 24rpx}
.title,.sub,.address-detail,.empty text{display:block}
.title{font-size:36rpx;font-weight:800;color:#191B1F}
.sub{margin-top:8rpx;color:#767C85;font-size:24rpx}
.add-link{margin:0;padding:0 28rpx;height:60rpx;border:2rpx solid #0BBF63;border-radius:30rpx;background:#fff;color:#08A152;font-size:24rpx;font-weight:700;line-height:56rpx}
.add-link::after{border:0}
/* 地址卡片 */
.address-card{margin-top:20rpx;padding:28rpx;border-radius:32rpx;background:#fff;box-shadow:0 2rpx 4rpx rgba(23,26,23,.03),0 12rpx 40rpx rgba(23,26,23,.05)}
.address-top{gap:16rpx;font-size:30rpx;font-weight:700;color:#191B1F}
.address-phone{font-size:26rpx;font-weight:400;color:#767C85}
.default-tag{margin-left:auto;padding:4rpx 12rpx;border-radius:10rpx;background:#E7F9EF;color:#08A152;font-size:20rpx;font-weight:500}
.address-detail{margin-top:16rpx;color:#767C85;font-size:26rpx;line-height:1.5}
.card-actions{justify-content:flex-end;gap:32rpx;margin-top:22rpx;padding-top:22rpx;border-top:1rpx solid #F5F6F6}
.card-actions button{margin:0;padding:0;border:0;background:transparent;color:#AEB3BB;font-size:24rpx;line-height:1.4}
.card-actions button::after{border:0}
.card-actions .danger{color:#767C85}
/* 空态 */
.empty{margin-top:20rpx;padding:80rpx 40rpx;text-align:center;border-radius:32rpx;background:#fff;box-shadow:0 2rpx 4rpx rgba(23,26,23,.03),0 12rpx 40rpx rgba(23,26,23,.05)}
.empty text:first-child{font-size:30rpx;font-weight:700;color:#191B1F}
.empty text:nth-child(2){margin-top:12rpx;color:#767C85;font-size:24rpx}
.empty-action{display:block;height:80rpx;margin:28rpx auto 0;padding:0 40rpx;border:0;border-radius:40rpx;background:linear-gradient(135deg,#12D978,#08A152);color:#fff;font-size:28rpx;font-weight:700;line-height:80rpx;box-shadow:0 12rpx 28rpx rgba(11,191,99,.3)}
.empty-action::after{border:0}
/* 表单卡片 */
.form-card{margin-top:20rpx;padding:28rpx;border-radius:32rpx;background:#fff;box-shadow:0 2rpx 4rpx rgba(23,26,23,.03),0 12rpx 40rpx rgba(23,26,23,.05)}
.form-head{justify-content:space-between;font-size:30rpx;font-weight:700;color:#191B1F}
.form-head .close{color:#767C85;font-size:24rpx;font-weight:400}
.two-column{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16rpx}
.input{width:100%;height:80rpx;margin-top:18rpx;padding:0 24rpx;border:2rpx solid #EDEFEF;border-radius:24rpx;background:#F7F9F8;box-sizing:border-box;font-size:26rpx;color:#191B1F}
.region{display:flex;align-items:center;color:#AEB3BB}
.default-check{gap:10rpx;margin-top:22rpx;color:#767C85;font-size:26rpx}
.save{width:100%;height:88rpx;margin-top:24rpx;border:0;border-radius:44rpx;background:linear-gradient(135deg,#12D978,#08A152);color:#fff;font-size:30rpx;font-weight:700;line-height:88rpx;box-shadow:0 12rpx 28rpx rgba(11,191,99,.3)}
.save::after{border:0}
/* 隐私卡片 */
.privacy-card{margin-top:20rpx;padding:28rpx;border-radius:32rpx;background:#fff;box-shadow:0 2rpx 4rpx rgba(23,26,23,.03),0 12rpx 40rpx rgba(23,26,23,.05)}
.privacy-card-head{display:flex;align-items:baseline;justify-content:space-between;padding-bottom:16rpx;border-bottom:1rpx solid #F5F6F6}
.privacy-card-head>text:first-child{font-size:30rpx;font-weight:700;color:#191B1F}
.privacy-card-head>text:last-child{font-size:24rpx;color:#AEB3BB}
.privacy-row{display:flex;align-items:center;gap:20rpx;padding:22rpx 0;border-bottom:1rpx solid #F5F6F6}
.privacy-row:last-child{border-bottom:0}
.privacy-row>view{min-width:0;flex:1}
.privacy-row>view>text,.privacy-row small{display:block}
.privacy-row>view>text{font-size:28rpx;font-weight:700;color:#191B1F}
.privacy-row small{margin-top:6rpx;color:#AEB3BB;font-size:24rpx}
.privacy-arrow{color:#AEB3BB;font-size:32rpx;line-height:1}
.row-icon{display:flex;align-items:center;justify-content:center;width:64rpx;height:64rpx;flex:none;border-radius:20rpx;font-size:24rpx;font-weight:900}
.row-icon.blue{background:#EAF2FF;color:#3B82F6}
.row-icon.green{background:#E7F9EF;color:#08A152}
.row-icon.yellow{background:#FFF2E5;color:#F2620A}
/* 支持横幅 */
.support-banner{display:flex;align-items:center;gap:16rpx;margin-top:20rpx;padding:24rpx;border-radius:24rpx;background:#E7F9EF;color:#08A152;font-size:24rpx;line-height:1.4}
.support-banner>text:nth-child(2){min-width:0;flex:1}
.support-icon{display:flex;align-items:center;justify-content:center;width:44rpx;height:44rpx;flex:none;border:3rpx solid #0BBF63;border-radius:50%;color:#08A152;font-size:24rpx;font-weight:900}
.support-link{color:#08A152;font-weight:700;white-space:nowrap;font-size:24rpx}
/* 隐私注释 */
.privacy-note{margin-top:20rpx;padding:20rpx;border-radius:24rpx;background:#FFF2E5;color:#F2620A;font-size:24rpx}
/* 状态卡 */
.state-card{margin-top:20rpx;padding:40rpx;text-align:center;border-radius:32rpx;background:#fff;box-shadow:0 2rpx 4rpx rgba(23,26,23,.03),0 12rpx 40rpx rgba(23,26,23,.05)}
.state-title,.state-copy{display:block}
.state-title{color:#191B1F;font-size:30rpx;font-weight:700}
.state-copy{margin-top:10rpx;color:#767C85;font-size:24rpx;line-height:1.5}
.retry-button{height:64rpx;margin:24rpx auto 0;padding:0 32rpx;border:2rpx solid #0BBF63;border-radius:32rpx;background:#fff;color:#08A152;font-size:24rpx;font-weight:700;line-height:60rpx}
.retry-button::after{border:0}
.error-state{color:#FF2442}
.error-state .state-title{color:#FF2442}
/* 表单错误 */
.form-error{display:flex;align-items:center;justify-content:space-between;gap:16rpx;margin-top:20rpx;padding:20rpx;border-radius:24rpx;background:#FFEEEA;color:#FF2442;font-size:24rpx}
.form-error text{min-width:0;flex:1;line-height:1.4}
.form-error button{flex:none;margin:0;padding:0 20rpx;height:52rpx;border:2rpx solid #FF2442;border-radius:16rpx;background:#fff;color:#FF2442;font-size:22rpx;line-height:48rpx}
.form-error button::after{border:0}
</style>
