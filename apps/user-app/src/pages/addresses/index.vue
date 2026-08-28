<template>
  <view class="page tm-page-shell">
    <view class="section-head"><view><text class="title">收货地址与隐私</text><text class="sub">管理配送地址，同时查看个人信息使用与账号权益</text></view><button class="add-link" @click="startCreate">＋ 新增</button></view>
    <view class="privacy-banner" @click="openPrivacy"><text class="privacy-icon">盾</text><text>收货信息仅用于商城履约，我们将严格保护你的隐私安全</text><text class="privacy-arrow">›</text></view>

    <view v-if="loading && !addresses.length" class="state-card loading-state" role="status" aria-live="polite"><text class="state-title">正在加载收货地址…</text><text class="state-copy">地址信息仅用于商城履约。</text></view>
    <view v-else-if="loadError" class="state-card error-state" role="alert"><text class="state-title">地址加载失败</text><text class="state-copy">{{ loadError }}</text><button class="retry-button" @click="load">重新加载</button></view>

    <view v-for="item in addresses" :key="item.id" class="address-card tm-panel">
      <view class="address-top"><text>{{ item.recipient_name }} · {{ maskPhone(item.phone) }}</text><text v-if="item.is_default" class="default-tag">默认地址</text></view>
      <text class="address-detail">{{ item.province }}{{ item.city }}{{ item.district }}{{ item.detail }}</text>
      <view class="card-actions"><button v-if="!item.is_default" @click="setDefault(item)">设为默认</button><button @click="edit(item)">编辑</button><button class="danger" @click="remove(item.id)">删除</button></view>
    </view>
    <view v-if="!addresses.length && !loading && !loadError" class="empty tm-panel"><text>还没有收货地址</text><text>物流商品下单前需要先添加地址</text><button class="empty-action" @click="startCreate">新增收货地址</button></view>

    <view v-if="formVisible" class="form-card tm-panel">
      <view class="form-head"><text>{{ editingId ? '编辑收货地址' : '新增收货地址' }}</text><text @click="closeForm">关闭</text></view>
      <view class="two-column"><input v-model="form.recipient_name" class="input" maxlength="80" placeholder="收货人姓名" /><input v-model="form.phone" class="input" type="number" maxlength="20" placeholder="联系电话" /></view>
      <picker mode="region" :value="region" @change="selectRegion"><view class="input region">{{ regionText }}</view></picker>
      <input v-model="form.detail" class="input" maxlength="255" placeholder="详细地址，如街道、门牌号" />
      <input v-model="form.postal_code" class="input" maxlength="20" placeholder="邮政编码（选填）" />
      <label class="default-check"><checkbox value="default" :checked="form.is_default" color="#0b8f89" @change="onDefaultChange" />设为默认收货地址</label>
      <view v-if="formError" class="form-error" role="alert"><text>{{ formError }}</text><button @click="formError = ''">知道了</button></view><button class="tm-primary-button save" :loading="saving" @click="save">保存地址</button>
    </view>

    <view class="privacy-card tm-panel">
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
.section-head,.address-top,.card-actions,.form-head,.default-check{display:flex;align-items:center}.section-head{justify-content:space-between;margin:8rpx 2rpx 24rpx}.title,.sub,.address-detail,.empty text{display:block}.title{font-size:22px;font-weight:950}.sub{margin-top:8rpx;color:var(--tm-ink-500);font-size:14px}.add-link{margin:0;padding:0 22rpx;height:58rpx;border:1rpx solid var(--tm-brand-600);border-radius:999rpx;background:#fff;color:var(--tm-brand-700);font-size:14px;line-height:56rpx}.address-card{margin-top:16rpx;padding:26rpx}.address-top{justify-content:space-between;font-size:15px;font-weight:900}.default-tag{padding:5rpx 10rpx;border-radius:8rpx;background:var(--tm-brand-100);color:var(--tm-brand-700);font-size:12px}.address-detail{margin-top:12rpx;color:var(--tm-ink-700);font-size:14px;line-height:1.6}.card-actions{justify-content:flex-end;gap:8rpx;margin-top:18rpx;padding-top:16rpx;border-top:1rpx solid var(--tm-line)}.card-actions button{margin:0;padding:0 18rpx;height:50rpx;border:0;background:transparent;color:var(--tm-brand-700);font-size:13px;line-height:50rpx}.card-actions .danger{color:var(--tm-danger)}.empty{padding:70rpx 30rpx;text-align:center}.empty text:first-child{font-size:17px;font-weight:900}.empty text:last-child{margin-top:8rpx;color:var(--tm-ink-500);font-size:14px}.form-card{margin-top:24rpx;padding:26rpx}.form-head{justify-content:space-between;font-size:17px;font-weight:900}.form-head text:last-child{color:var(--tm-ink-500);font-size:13px;font-weight:500}.two-column{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12rpx}.input{width:100%;height:76rpx;margin-top:16rpx;padding:0 18rpx;border:1rpx solid var(--tm-line);border-radius:14rpx;background:#fafcfc;box-sizing:border-box;font-size:14px}.region{display:flex;align-items:center;color:var(--tm-ink-500)}.default-check{gap:8rpx;margin-top:20rpx;color:var(--tm-ink-700);font-size:14px}.save{margin-top:22rpx}.privacy-note{margin-top:22rpx;padding:18rpx;border-radius:14rpx;background:var(--tm-reward-100);color:#8b561b;font-size:13px}
/* V09 campus cards: 细边框、浅蓝底和柔和阴影。 */
.page{background:linear-gradient(180deg,#edfafa 0,var(--tm-canvas) 430rpx)}
.section-head{padding:28rpx 24rpx;border:1rpx solid #cfe8e7;border-radius:28rpx;background:linear-gradient(135deg,#e1f8f5,#eaf5ff);box-shadow:0 12rpx 30rpx rgba(34,107,113,.08)}
.sub{color:#5f747c;font-weight:550}.add-link{border:1rpx solid #99d5ce;background:#fff;box-shadow:0 7rpx 16rpx rgba(24,125,111,.10);color:#177c75}
.address-card,.empty,.form-card{border:1rpx solid #d6e6e8;box-shadow:0 12rpx 26rpx rgba(25,72,89,.07)}
.address-card:nth-of-type(even){background:#f7fcff}.default-tag{border:0;background:#e5f7ee;color:#197657}
.card-actions button{border:1rpx solid #d1e2e4;border-radius:12rpx;background:#fff;color:#28736e}
.empty{background:#fff}.form-card{background:#f6fcfc}
.input{border:1rpx solid #cfe0e2;background:#fff}.save{border:0;box-shadow:0 10rpx 20rpx rgba(24,125,111,.18)}
.privacy-note{border:1rpx solid #d6e9e7;background:#eff9f7;color:#4d7177}
.state-card{margin-top:18rpx;padding:38rpx 28rpx;text-align:center;border:1rpx solid #d6e6e8;border-radius:24rpx;background:#fff;box-shadow:0 10rpx 24rpx rgba(25,72,89,.06)}
.state-title,.state-copy{display:block}.state-title{color:#214d58;font-size:15px;font-weight:850}.state-copy{margin-top:8rpx;color:#72858c;font-size:13px;line-height:1.5}.retry-button,.empty-action{height:64rpx;margin:20rpx auto 0;padding:0 24rpx;border:0;border-radius:16rpx;background:#2a9e98;color:#fff;font-size:13px;line-height:64rpx}.retry-button::after,.empty-action::after{border:0}.empty-action{display:block;background:#e6f8f3;color:#197b72}.form-error{display:flex;align-items:center;justify-content:space-between;gap:14rpx;margin-top:20rpx;padding:14rpx 16rpx;border:1rpx solid #f1c9c2;border-radius:15rpx;background:#fff5f2;color:#b42318;font-size:13px}.form-error text{min-width:0;flex:1;line-height:1.4}.form-error button{flex:none;margin:0;padding:0 12rpx;height:46rpx;border:1rpx solid #dc8f84;border-radius:10rpx;background:#fff;color:#a33d32;font-size:13px;line-height:44rpx}.form-error button::after{border:0}
.privacy-banner{display:flex;align-items:center;gap:14rpx;margin:0 2rpx 20rpx;padding:20rpx 22rpx;border:1rpx solid #d2e8fa;border-radius:20rpx;background:linear-gradient(135deg,#f0f8ff,#edfaff);color:#365675;font-size:13px;line-height:1.45;box-shadow:0 8rpx 20rpx rgba(44,116,164,.05)}.privacy-banner>text:nth-child(2){flex:1}.privacy-icon,.row-icon,.support-icon{display:flex;align-items:center;justify-content:center;flex:none;font-weight:900}.privacy-icon{width:48rpx;height:48rpx;border-radius:15rpx;background:#dceeff;color:#2d8de0;font-size:14px}.privacy-arrow{color:#6a85a0;font-size:22px;line-height:1}
.privacy-card{margin-top:24rpx;padding:26rpx 24rpx;border:1rpx solid #d6e6e8;box-shadow:0 12rpx 26rpx rgba(25,72,89,.07);background:#fff}.privacy-card-head{display:flex;align-items:baseline;justify-content:space-between;padding-bottom:12rpx;border-bottom:1rpx solid #e5eef0}.privacy-card-head>text:first-child{font-size:17px;font-weight:900;color:#173b56}.privacy-card-head>text:last-child{font-size:13px;color:#72858c}.privacy-row{display:flex;align-items:center;gap:14rpx;padding:18rpx 0;border-bottom:1rpx solid #edf2f3}.privacy-row:last-child{border-bottom:0}.privacy-row>view{min-width:0;flex:1}.privacy-row>view>text,.privacy-row small{display:block}.privacy-row>view>text{font-size:14px;font-weight:800;color:#193b57}.privacy-row small{margin-top:5rpx;color:#72858c;font-size:13px}.row-icon{width:48rpx;height:48rpx;border-radius:15rpx;font-size:13px}.row-icon.blue{background:#e4f2ff;color:#2d8de0}.row-icon.green{background:#e6f8f0;color:#16a571}.row-icon.yellow{background:#fff4d7;color:#c88b16}
.support-banner{display:flex;align-items:center;gap:12rpx;margin-top:20rpx;padding:20rpx 22rpx;border:1rpx solid #d2e8fa;border-radius:20rpx;background:#eff8ff;color:#4c6d89;font-size:13px;line-height:1.4}.support-banner>text:nth-child(2){min-width:0;flex:1}.support-icon{width:38rpx;height:38rpx;border:2rpx solid #4b9be4;border-radius:50%;color:#368bd6;font-size:14px}.support-link{color:#287ed0;white-space:nowrap;font-size:13px}
</style>
