<template>
  <view class="page">
    <view class="section">
      <text class="section-title">门店资料</text>
      <view class="field"><text class="label">门店名称</text><input v-model="form.name" class="input" :disabled="!canManageIdentity" maxlength="80" /></view>
      <view class="field"><text class="label">联系电话</text><input v-model="form.contact_phone" class="input" :disabled="!canManageIdentity" maxlength="11" /></view>
      <view class="field"><text class="label">门店简介</text><textarea v-model="form.description" class="textarea" maxlength="500" /></view>
      <view class="field"><text class="label">门店地址</text><input v-model="form.address" class="input" maxlength="255" /></view>
      <view class="field"><text class="label">营业时间</text><input v-model="form.business_hours" class="input" maxlength="60" /></view>
      <view class="field"><text class="label">门店 Logo</text><input v-model="form.logo_url" class="input" placeholder="https://..." /></view>
      <button class="upload" @tap="chooseLogo">上传 Logo</button>
    </view>

    <view class="section">
      <text class="section-title">履约与结算</text>
      <view class="field"><text class="label">门店运费（元）</text><input v-model="shippingFeeYuan" class="input" type="digit" placeholder="0.00" /></view>
      <view class="field"><text class="label">包邮门槛（元）</text><input v-model="freeShippingYuan" class="input" type="digit" placeholder="不设置则留空" /></view>
      <text class="hint">平台统一收款，按门店商品实付金额扣除平台抽成后结算。</text>
    </view>

    <button class="save" :loading="saving" :disabled="saving" @tap="save">保存门店设置</button>

    <view class="section members">
      <view class="section-head">
        <text class="section-title">门店成员</text>
        <text v-if="isOwner" class="add" @tap="memberModal = true">＋ 添加成员</text>
      </view>
      <view v-for="member in members" :key="member.id" class="member">
        <view class="member-main">
          <text class="member-name">{{ member.nickname || '未设置昵称' }}<text class="member-role">{{ member.role_label }}</text></text>
          <text class="member-phone">{{ member.phone_masked || '' }} · {{ member.status === 'active' ? '使用中' : '已移除' }}</text>
        </view>
        <text v-if="isOwner && member.role !== 'owner' && member.status === 'active'" class="remove" @tap="remove(member)">移除</text>
      </view>
      <text v-if="members.length === 0" class="hint">暂无成员。</text>
    </view>

    <view v-if="memberModal" class="mask" @tap="memberModal = false">
      <view class="sheet" @tap.stop>
        <text class="sheet-title">添加门店成员</text>
        <input v-model="memberPhone" class="sheet-input" type="number" maxlength="11" placeholder="成员注册手机号" />
        <view class="roles">
          <view class="role" :class="{ active: memberRole === 'staff' }" @tap="memberRole = 'staff'">店员</view>
          <view class="role" :class="{ active: memberRole === 'manager' }" @tap="memberRole = 'manager'">店长</view>
        </view>
        <button class="sheet-submit" :loading="adding" :disabled="adding" @tap="addMember">确认添加</button>
      </view>
    </view>
  </view>
</template>

<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import {
  addStoreMember,
  getStoreManagerProfile,
  listStoreMembers,
  removeStoreMember,
  updateManagerStore,
  uploadStoreImage,
  type StoreMemberView,
} from '@/api/stores'
import { requireStoreSession } from '@/utils/storeSession'

const session = requireStoreSession()
const canManageIdentity = ref(false)
const form = reactive({ name: '', contact_phone: '', description: '', address: '', business_hours: '', logo_url: '' })
const shippingFeeYuan = ref('')
const freeShippingYuan = ref('')
const members = ref<StoreMemberView[]>([])
const saving = ref(false)
const memberModal = ref(false)
const memberPhone = ref('')
const memberRole = ref<'staff' | 'manager'>('staff')
const adding = ref(false)
const isOwner = computed(() => members.value.some((member) => member.role === 'owner' && member.status === 'active'))

const toast = (title: string) => uni.showToast({ title, icon: 'none' })

const load = async () => {
  if (!session) return
  try {
    const [profile, memberList] = await Promise.all([getStoreManagerProfile(session.token), listStoreMembers(session.token)])
    canManageIdentity.value = profile.member.role === 'owner'
    form.name = profile.store.name
    form.contact_phone = profile.store.contact_phone
    form.description = profile.store.description || ''
    form.address = profile.store.address || ''
    form.business_hours = profile.store.business_hours || ''
    form.logo_url = profile.store.logo_url || ''
    shippingFeeYuan.value = (profile.store.shipping_fee_amount / 100).toFixed(2)
    freeShippingYuan.value = profile.store.free_shipping_threshold === null ? '' : (profile.store.free_shipping_threshold / 100).toFixed(2)
    members.value = memberList.items
  } catch (error) {
    toast(error instanceof Error ? error.message : '加载失败')
  }
}

const chooseLogo = () => {
  if (!session) return
  uni.chooseImage({
    count: 1,
    sizeType: ['compressed'],
    success: async (result) => {
      uni.showLoading({ title: '上传中' })
      try {
        const uploaded = await uploadStoreImage(session.token, result.tempFilePaths[0])
        form.logo_url = uploaded.url
      } catch (error) {
        toast(error instanceof Error ? error.message : '上传失败')
      } finally {
        uni.hideLoading()
      }
    },
  })
}

const save = async () => {
  if (!session) return
  const payload: Record<string, unknown> = {
    description: form.description.trim() || null,
    address: form.address.trim() || null,
    business_hours: form.business_hours.trim() || null,
    logo_url: form.logo_url.trim() || null,
    shipping_fee_amount: Math.round(Number(shippingFeeYuan.value || 0) * 100),
    free_shipping_threshold: freeShippingYuan.value.trim() === '' ? null : Math.round(Number(freeShippingYuan.value) * 100),
  }
  if (canManageIdentity.value) {
    payload.name = form.name.trim()
    payload.contact_phone = form.contact_phone.trim()
  }
  saving.value = true
  try {
    await updateManagerStore(session.token, payload)
    toast('已保存')
    await load()
  } catch (error) {
    toast(error instanceof Error ? error.message : '保存失败')
  } finally {
    saving.value = false
  }
}

const addMember = async () => {
  if (!session) return
  if (!/^1[3-9]\d{9}$/.test(memberPhone.value)) return toast('请输入正确的手机号')
  adding.value = true
  try {
    await addStoreMember(session.token, { phone: memberPhone.value, role: memberRole.value })
    toast('成员已添加')
    memberModal.value = false
    memberPhone.value = ''
    await load()
  } catch (error) {
    toast(error instanceof Error ? error.message : '添加失败')
  } finally {
    adding.value = false
  }
}

const remove = (member: StoreMemberView) => {
  if (!session) return
  uni.showModal({
    title: '移除成员',
    content: `确认移除 ${member.nickname || '该成员'} 的门店权限？`,
    success: async (result) => {
      if (!result.confirm) return
      try {
        await removeStoreMember(session.token, member.id)
        toast('已移除')
        await load()
      } catch (error) {
        toast(error instanceof Error ? error.message : '移除失败')
      }
    },
  })
}

onMounted(() => { void load() })
</script>

<style scoped>
.page { min-height: 100vh; background: #f5f6f8; padding: 24rpx 24rpx 80rpx; box-sizing: border-box; }
.section { background: #fff; border-radius: 20rpx; padding: 8rpx 24rpx 24rpx; margin-bottom: 20rpx; }
.section-title { display: block; font-size: 28rpx; font-weight: 700; color: #111827; padding: 22rpx 0 8rpx; }
.section-head { display: flex; justify-content: space-between; align-items: center; }
.add { font-size: 24rpx; color: #2f6bff; }
.field { display: flex; align-items: center; padding: 20rpx 0; border-bottom: 1rpx solid #f3f4f6; }
.field:last-child { border-bottom: none; }
.label { width: 210rpx; font-size: 24rpx; color: #6b7280; flex-shrink: 0; }
.input, .textarea { flex: 1; font-size: 27rpx; color: #111827; }
.textarea { height: 120rpx; padding-top: 12rpx; }
.upload { margin: 8rpx 0 0; font-size: 22rpx; background: #eef2ff; color: #2f6bff; border-radius: 999rpx; }
.hint { display: block; padding: 16rpx 0 0; font-size: 20rpx; color: #9ca3af; line-height: 1.6; }
.save { background: #2f6bff; color: #fff; border-radius: 999rpx; font-size: 28rpx; margin-bottom: 20rpx; }
.members { padding-bottom: 12rpx; }
.member { display: flex; align-items: center; justify-content: space-between; padding: 18rpx 0; border-top: 1rpx solid #f3f4f6; }
.member-main { flex: 1; min-width: 0; }
.member-name { font-size: 26rpx; color: #111827; }
.member-role { margin-left: 10rpx; font-size: 18rpx; color: #2f6bff; background: #eef2ff; padding: 2rpx 10rpx; border-radius: 999rpx; }
.member-phone { display: block; margin-top: 4rpx; font-size: 20rpx; color: #9ca3af; }
.remove { font-size: 22rpx; color: #dc2626; }
.mask { position: fixed; inset: 0; background: rgba(17, 24, 39, 0.45); display: flex; align-items: flex-end; z-index: 20; }
.sheet { width: 100%; background: #fff; border-radius: 28rpx 28rpx 0 0; padding: 32rpx 32rpx 56rpx; box-sizing: border-box; }
.sheet-title { display: block; font-size: 28rpx; font-weight: 700; color: #111827; }
.sheet-input { margin-top: 20rpx; background: #f3f4f6; border-radius: 16rpx; padding: 20rpx 24rpx; font-size: 26rpx; }
.roles { display: flex; gap: 16rpx; margin-top: 20rpx; }
.role { flex: 1; text-align: center; padding: 16rpx 0; border-radius: 16rpx; background: #f3f4f6; color: #6b7280; font-size: 24rpx; }
.role.active { background: #eef2ff; color: #2f6bff; font-weight: 600; }
.sheet-submit { margin-top: 28rpx; background: #2f6bff; color: #fff; border-radius: 999rpx; font-size: 28rpx; }
</style>
