<template>
  <view class="page">
    <view class="heading"><view class="profile-mark">{{ (auth.user?.nickname || '我').slice(0, 1) }}</view><view><text class="title">{{ auth.user?.nickname || '我的账户' }}</text><text class="subtitle">资料、地址与资产都在这里</text></view></view>

    <view class="card">
      <text class="section-title">个人资料</text>
      <input v-model="nickname" class="input" maxlength="80" placeholder="昵称" />
      <view class="button-row">
        <button class="secondary" :loading="uploading" @click="uploadAvatar">{{ avatarFileId ? '重新选择头像' : '上传头像' }}</button>
        <button class="primary" :loading="saving" @click="saveProfile">保存资料</button>
      </view>
      <text class="hint">资料完善条件：昵称、已扫描头像和已验证手机号。奖励将在资产批次处理。</text>
    </view>

    <view class="asset-grid" @click="openAssets">
      <view class="asset"><text class="asset-value">{{ assets?.points.available ?? 0 }}</text><text>可用积分</text></view>
      <view class="asset"><text class="asset-value">¥{{ money(assets?.wallet.available_amount ?? 0) }}</text><text>余额</text></view>
      <view class="asset"><text class="asset-value">{{ assets?.coupons.available ?? 0 }}</text><text>优惠券</text></view>
    </view>

    <view class="card">
      <view class="section-row"><text class="section-title">收货地址</text><text class="hint">最多 20 个</text></view>
      <view v-for="item in addresses" :key="item.id" class="address-item">
        <view class="address-main" @click="edit(item)">
          <text class="address-name">{{ item.recipient_name }} · {{ item.phone }}</text>
          <text class="address-detail">{{ item.province }}{{ item.city }}{{ item.district }}{{ item.detail }}</text>
          <text v-if="item.is_default" class="default-tag">默认</text>
        </view>
        <button class="delete" @click="remove(item.id)">删除</button>
      </view>
      <text v-if="addresses.length === 0" class="empty">尚未添加收货地址</text>
    </view>

    <view class="card">
      <text class="section-title">{{ editingId ? '编辑地址' : '新增地址' }}</text>
      <input v-model="form.recipient_name" class="input" placeholder="收货人" />
      <input v-model="form.phone" class="input" type="number" placeholder="联系电话" />
      <view class="triple"><input v-model="form.province" class="input" placeholder="省" /><input v-model="form.city" class="input" placeholder="市" /><input v-model="form.district" class="input" placeholder="区" /></view>
      <input v-model="form.detail" class="input" placeholder="详细地址" />
      <label class="checkbox"><checkbox :checked="form.is_default" @click="form.is_default = !form.is_default" />设为默认地址</label>
      <view class="button-row">
        <button v-if="editingId" class="secondary" @click="resetForm">取消编辑</button>
        <button class="primary" :loading="savingAddress" @click="saveAddress">保存地址</button>
      </view>
    </view>
    <AppTabBar current-target="account" />
  </view>
</template>

<script setup lang="ts">
import { onShow } from '@dcloudio/uni-app'
import { reactive, ref } from 'vue'
import AppTabBar from '@/components/AppTabBar.vue'
import { createAddress, deleteAddress, listAddresses, loadAssetOverview, updateAddress, updateProfile, type AddressInput, type AssetOverview, type UserAddress } from '@/api/account'
import { chooseAndUploadAvatar } from '@/api/upload'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const nickname = ref(auth.user?.nickname ?? '')
const avatarFileId = ref(auth.user?.avatar_file_id ?? '')
const assets = ref<AssetOverview | null>(null)
const addresses = ref<UserAddress[]>([])
const editingId = ref('')
const saving = ref(false)
const uploading = ref(false)
const savingAddress = ref(false)
const form = reactive<AddressInput>({ recipient_name: '', phone: '', province: '', city: '', district: '', detail: '', postal_code: '', is_default: false })

onShow(load)

async function load() {
  if (!auth.accessToken) return uni.navigateTo({ url: '/pages/login/index' })
  await auth.fetchCurrentUser()
  nickname.value = auth.user?.nickname ?? ''
  avatarFileId.value = auth.user?.avatar_file_id ?? ''
  ;[assets.value, addresses.value] = await Promise.all([loadAssetOverview(auth.accessToken), listAddresses(auth.accessToken)])
}

async function uploadAvatar() {
  uploading.value = true
  try {
    avatarFileId.value = await chooseAndUploadAvatar(auth.accessToken)
    uni.showToast({ title: '头像检查通过', icon: 'success' })
  } catch (error) {
    uni.showToast({ title: error instanceof Error ? error.message : '上传失败', icon: 'none' })
  } finally { uploading.value = false }
}

async function saveProfile() {
  saving.value = true
  try {
    auth.user = await updateProfile(auth.accessToken, { nickname: nickname.value, avatar_file_id: avatarFileId.value || null })
    auth.persist()
    assets.value = await loadAssetOverview(auth.accessToken)
    uni.showToast({ title: '资料已保存', icon: 'success' })
  } catch (error) { uni.showToast({ title: error instanceof Error ? error.message : '保存失败', icon: 'none' }) }
  finally { saving.value = false }
}

function edit(item: UserAddress) {
  editingId.value = item.id
  Object.assign(form, item)
}

function resetForm() {
  editingId.value = ''
  Object.assign(form, { recipient_name: '', phone: '', province: '', city: '', district: '', detail: '', postal_code: '', is_default: false })
}

async function saveAddress() {
  if (!form.recipient_name || !form.phone || !form.province || !form.city || !form.district || !form.detail) return uni.showToast({ title: '请填写完整地址', icon: 'none' })
  savingAddress.value = true
  try {
    if (editingId.value) await updateAddress(auth.accessToken, editingId.value, { ...form })
    else await createAddress(auth.accessToken, { ...form })
    resetForm()
    addresses.value = await listAddresses(auth.accessToken)
    uni.showToast({ title: '地址已保存', icon: 'success' })
  } catch (error) { uni.showToast({ title: error instanceof Error ? error.message : '保存失败', icon: 'none' }) }
  finally { savingAddress.value = false }
}

async function remove(id: string) {
  const confirmed = await uni.showModal({ title: '删除地址', content: '确认删除这个收货地址？' })
  if (!confirmed.confirm) return
  await deleteAddress(auth.accessToken, id)
  addresses.value = await listAddresses(auth.accessToken)
}

function money(cents: number) { return (cents / 100).toFixed(2) }
function openAssets() { uni.navigateTo({ url: '/pages/assets/index' }) }
</script>

<style scoped>
.page{min-height:100vh;padding:36rpx 28rpx 80rpx;box-sizing:border-box;background:#f5f7fb}.heading{margin:12rpx 4rpx 28rpx}.title,.subtitle,.section-title,.hint,.asset-value,.asset text,.address-name,.address-detail,.empty{display:block}.title{font-size:44rpx;font-weight:750}.subtitle{margin-top:8rpx;color:#667085}.card{margin-top:24rpx;padding:28rpx;border-radius:24rpx;background:#fff}.section-title{font-size:30rpx;font-weight:700}.section-row{display:flex;justify-content:space-between;align-items:center}.input{height:76rpx;margin-top:18rpx;padding:0 20rpx;border:1rpx solid #dfe3eb;border-radius:14rpx;box-sizing:border-box;background:#fafbfc}.triple{display:grid;grid-template-columns:repeat(3,1fr);gap:12rpx}.button-row{display:flex;gap:16rpx;margin-top:22rpx}.primary,.secondary{flex:1;height:78rpx;border-radius:16rpx;font-size:26rpx;line-height:78rpx}.primary{background:#3157d5;color:#fff}.secondary{border:1rpx solid #cfd5e2;background:#fff;color:#475467}.primary::after,.secondary::after,.delete::after{border:0}.hint{margin-top:16rpx;color:#98a2b3;font-size:23rpx;line-height:1.5}.asset-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:14rpx;margin-top:24rpx}.asset{padding:24rpx 10rpx;text-align:center;border-radius:20rpx;background:#fff;color:#667085;font-size:22rpx}.asset-value{margin-bottom:8rpx;color:#1d2939;font-size:32rpx;font-weight:750}.address-item{display:flex;align-items:center;gap:12rpx;padding:22rpx 0;border-bottom:1rpx solid #eef0f4}.address-main{flex:1;position:relative}.address-name{font-weight:650}.address-detail{margin-top:8rpx;color:#667085;font-size:24rpx;line-height:1.5}.default-tag{display:inline-block;margin-top:8rpx;padding:4rpx 12rpx;border-radius:10rpx;background:#eef3ff;color:#3157d5;font-size:20rpx}.delete{width:110rpx;background:#fff;color:#d92d20;font-size:23rpx}.empty{padding:34rpx 0;text-align:center;color:#98a2b3}.checkbox{display:flex;align-items:center;margin-top:20rpx;color:#475467;font-size:25rpx}
.page{padding:32rpx 28rpx 90rpx;background:var(--tm-canvas)}.heading{display:flex;align-items:center;gap:20rpx;margin:0;padding:30rpx;border-radius:30rpx;background:linear-gradient(135deg,#173b87,var(--tm-brand-600));box-shadow:0 18rpx 42rpx rgba(37,83,185,.18);color:#fff}.profile-mark{display:flex;align-items:center;justify-content:center;width:92rpx;height:92rpx;flex:none;border:2rpx solid rgba(255,255,255,.25);border-radius:30rpx;background:rgba(255,255,255,.13);font-size:34rpx;font-weight:850}.heading .title{font-size:35rpx;font-weight:850}.heading .subtitle{color:rgba(255,255,255,.72);font-size:23rpx}.card{border:1rpx solid var(--tm-line);border-radius:27rpx;box-shadow:0 10rpx 26rpx rgba(35,49,80,.04)}.section-title{font-size:28rpx;font-weight:800}.input{border-color:var(--tm-line);border-radius:15rpx;background:#fafbfe}.triple{grid-template-columns:repeat(3,minmax(0,1fr));gap:10rpx}.primary,.secondary{border-radius:17rpx;font-weight:750}.primary{background:var(--tm-brand-600)}.secondary{border-color:var(--tm-line);color:var(--tm-ink-700)}.hint{color:var(--tm-ink-500)}.asset-grid{grid-template-columns:repeat(3,minmax(0,1fr));gap:12rpx}.asset{border:1rpx solid var(--tm-line);border-radius:22rpx;color:var(--tm-ink-500)}.asset:first-child{border-color:#ffe1bc;background:var(--tm-reward-100)}.asset:first-child .asset-value{color:var(--tm-reward-600)}.address-item{border-color:var(--tm-line)}.default-tag{background:var(--tm-brand-100);color:var(--tm-brand-700)}.delete{color:var(--tm-danger)}
@media screen and (min-width:768px){.page{width:750rpx;margin:0 auto}}
</style>
