<template>
  <view class="page">
    <view v-if="campus" class="campus-bar"><text>当前校区</text><text class="campus-name">{{ campus.campus_name }}</text></view>
    <view class="section">
      <text class="section-title">基础信息</text>
      <view class="field"><text class="label">商品名称</text><input v-model="form.name" class="input" maxlength="160" placeholder="例如：校园文创帆布袋" /></view>
      <view class="field"><text class="label">商品卖点</text><input v-model="form.subtitle" class="input" maxlength="255" placeholder="一句话介绍" /></view>
      <view class="field"><text class="label">商品分类</text>
        <picker :range="categoryNames" :value="categoryIndex" @change="onCategoryChange">
          <view class="picker">{{ categoryIndex >= 0 ? categoryNames[categoryIndex] : '未分类' }}</view>
        </picker>
      </view>
      <view class="field column">
        <text class="label">商品详情</text>
        <textarea v-model="form.description" class="textarea" maxlength="20000" placeholder="介绍商品材质、规格与使用说明" />
      </view>
    </view>

    <view class="section">
      <text class="section-title">商品图片</text>
      <view class="images">
        <view v-for="(image, index) in gallery" :key="image + index" class="image-item">
          <image :src="image" class="image" mode="aspectFill" />
          <text class="remove" @tap="removeImage(index)">×</text>
        </view>
        <view v-if="gallery.length < 9" class="image-add" @tap="chooseImages">＋</view>
      </view>
      <text class="hint">第一张作为商品封面，最多 9 张。</text>
      <view class="field"><text class="label">或粘贴图片链接</text><input v-model="imageUrlInput" class="input" placeholder="https://..." @confirm="appendImageUrl" /></view>
      <button class="mini" @tap="appendImageUrl">添加链接图片</button>
    </view>

    <view class="section">
      <view class="section-head">
        <text class="section-title">规格与价格</text>
        <text class="add-sku" @tap="addSku">＋ 添加规格</text>
      </view>
      <view v-for="(sku, index) in form.skus" :key="index" class="sku">
        <view class="sku-head">
          <text class="sku-index">规格 {{ index + 1 }}</text>
          <text v-if="form.skus.length > 1" class="sku-remove" @tap="removeSku(index)">删除</text>
        </view>
        <view class="field"><text class="label">规格名称</text><input v-model="sku.name" class="input" maxlength="160" placeholder="例如：标准款" /></view>
        <view class="field"><text class="label">规格描述</text><input v-model="sku.specText" class="input" maxlength="200" placeholder="例如：颜色:米白,尺码:M" /></view>
        <view class="field"><text class="label">价格（元）</text><input v-model="sku.priceYuan" class="input" type="digit" placeholder="0.00" /></view>
        <view class="field"><text class="label">库存</text><input v-model="sku.stock" class="input" type="number" placeholder="0" /></view>
        <view class="field"><text class="label">交付方式</text>
          <picker :range="fulfillmentNames" :value="sku.fulfillmentIndex" @change="onFulfillmentChange(sku, $event)">
            <view class="picker">{{ fulfillmentNames[sku.fulfillmentIndex] }}</view>
          </picker>
        </view>
        <view class="field"><text class="label">单笔限购</text><input v-model="sku.limit" class="input" type="number" placeholder="不限制可留空" /></view>
      </view>
    </view>

    <view class="actions">
      <button class="btn" :loading="saving" :disabled="saving" @tap="save('draft')">保存</button>
      <button class="btn primary" :loading="saving" :disabled="saving" @tap="save('on_sale')">保存并上架</button>
    </view>
  </view>
</template>

<script setup lang="ts">
import { onLoad, onShow } from '@dcloudio/uni-app'
import { computed, reactive, ref } from 'vue'
import {
  createManagerProduct,
  getManagerProduct,
  listStoreCategories,
  updateManagerProduct,
  uploadStoreImage,
  type StoreCampus,
  type StoreProductDetail,
  type StoreProductInput,
} from '@/api/stores'
import { getSelectedStoreCampus, requireStoreSession } from '@/utils/storeSession'

interface SkuForm {
  id?: string
  name: string
  specText: string
  priceYuan: string
  stock: string
  limit: string
  fulfillmentIndex: number
}

const session = requireStoreSession()
const campus = ref<StoreCampus | null>(session ? getSelectedStoreCampus(session.storeId) : null)
const productId = ref('')
const version = ref(0)
const saving = ref(false)
const gallery = ref<string[]>([])
const imageUrlInput = ref('')
const categories = ref<Array<{ id: string; name: string }>>([])
const categoryId = ref<string | null>(null)
const form = reactive({
  name: '',
  subtitle: '',
  description: '',
  status: 'draft' as 'draft' | 'on_sale' | 'off_shelf',
  skus: [] as SkuForm[],
})
const fulfillmentNames = ['快递配送', '到店自提/无需物流']
const categoryNames = computed(() => ['未分类', ...categories.value.map((category) => category.name)])
const categoryIndex = computed(() => {
  if (!categoryId.value) return 0
  const index = categories.value.findIndex((category) => category.id === categoryId.value)
  return index < 0 ? 0 : index + 1
})

const toast = (title: string) => uni.showToast({ title, icon: 'none' })

const emptySku = (): SkuForm => ({ name: '默认规格', specText: '', priceYuan: '', stock: '0', limit: '', fulfillmentIndex: 0 })
const addSku = () => form.skus.push(emptySku())
const removeSku = (index: number) => form.skus.splice(index, 1)

const onCategoryChange = (event: any) => {
  const index = Number(event.detail.value)
  categoryId.value = index === 0 ? null : categories.value[index - 1]?.id ?? null
}

const onFulfillmentChange = (sku: SkuForm, event: any) => {
  sku.fulfillmentIndex = Number(event.detail.value)
}

const removeImage = (index: number) => gallery.value.splice(index, 1)
const appendImageUrl = () => {
  const url = imageUrlInput.value.trim()
  if (!/^https?:\/\//i.test(url)) return toast('请填写 http/https 图片链接')
  if (gallery.value.includes(url)) return
  gallery.value.push(url)
  imageUrlInput.value = ''
}

const chooseImages = () => {
  if (!session) return
  uni.chooseImage({
    count: Math.max(1, 9 - gallery.value.length),
    sizeType: ['compressed'],
    success: async (result) => {
      uni.showLoading({ title: '上传中' })
      try {
        for (const path of result.tempFilePaths) {
          const uploaded = await uploadStoreImage(session.token, path)
          gallery.value.push(uploaded.url)
        }
      } catch (error) {
        toast(error instanceof Error ? error.message : '上传失败')
      } finally {
        uni.hideLoading()
      }
    },
  })
}

const specsToText = (specs: Record<string, string>) => Object.entries(specs || {}).map(([key, value]) => `${key}:${value}`).join(',')
const textToSpecs = (text: string) => {
  const specs: Record<string, string> = {}
  text.split(/[,，]/).map((part) => part.trim()).filter(Boolean).forEach((part) => {
    const [key, ...rest] = part.split(/[:：]/)
    if (key?.trim() && rest.join(':').trim()) specs[key.trim()] = rest.join(':').trim()
  })
  return specs
}

const fill = (product: StoreProductDetail) => {
  productId.value = product.id
  version.value = product.version
  form.name = product.name
  form.subtitle = product.subtitle || ''
  form.description = product.description || ''
  form.status = product.status
  categoryId.value = product.category?.id ?? null
  gallery.value = [product.cover_url, ...(product.gallery_images || [])].filter((url, index, list) => Boolean(url) && list.indexOf(url) === index) as string[]
  form.skus = product.skus.map((sku) => ({
    id: sku.id,
    name: sku.name,
    specText: specsToText(sku.specs),
    priceYuan: (sku.cash_amount / 100).toFixed(2),
    stock: String(sku.on_hand_quantity),
    limit: sku.limit_per_order ? String(sku.limit_per_order) : '',
    fulfillmentIndex: sku.fulfillment_type === 'no_logistics' ? 1 : 0,
  }))
  if (form.skus.length === 0) form.skus.push(emptySku())
}

const save = async (status: 'draft' | 'on_sale') => {
  if (!session) return
  if (form.name.trim().length < 2) return toast('请填写商品名称')
  const skus: StoreProductInput['skus'] = []
  for (const [index, sku] of form.skus.entries()) {
    if (!sku.name.trim()) return toast(`请填写规格 ${index + 1} 的名称`)
    const price = Math.round(Number(sku.priceYuan) * 100)
    if (!Number.isFinite(price) || price < 1) return toast(`规格 ${index + 1} 的价格需大于 0`)
    const stock = Number(sku.stock)
    if (!Number.isInteger(stock) || stock < 0) return toast(`规格 ${index + 1} 的库存无效`)
    skus.push({
      id: sku.id,
      name: sku.name.trim(),
      specs: textToSpecs(sku.specText),
      fulfillment_type: sku.fulfillmentIndex === 1 ? 'no_logistics' : 'logistics',
      cash_amount: price,
      stock_quantity: stock,
      limit_per_order: sku.limit ? Number(sku.limit) : null,
      status: 'active',
    })
  }
  const payload: StoreProductInput = {
    name: form.name.trim(),
    subtitle: form.subtitle.trim() || null,
    description: form.description.trim() || null,
    cover_url: gallery.value[0] || null,
    gallery_images: gallery.value,
    category_id: categoryId.value,
    status,
    skus,
  }
  saving.value = true
  try {
    if (productId.value) {
      await updateManagerProduct(session.token, productId.value, { ...payload, version: version.value }, campus.value?.campus_id)
    } else {
      await createManagerProduct(session.token, payload, campus.value?.campus_id)
    }
    toast(status === 'on_sale' ? '商品已上架' : '已保存')
    setTimeout(() => uni.navigateBack(), 600)
  } catch (error) {
    toast(error instanceof Error ? error.message : '保存失败')
  } finally {
    saving.value = false
  }
}

onLoad(async (query) => {
  if (!session) return
  try {
    categories.value = (await listStoreCategories(session.token)).items
    if (query?.id) fill(await getManagerProduct(session.token, String(query.id), campus.value?.campus_id))
    else form.skus.push(emptySku())
  } catch (error) {
    toast(error instanceof Error ? error.message : '加载失败')
  }
})

onShow(() => {
  if (session) campus.value = getSelectedStoreCampus(session.storeId)
})
</script>

<style scoped>
.page { min-height: 100vh; background: #f5f6f8; padding: 24rpx 24rpx 160rpx; box-sizing: border-box; }
.campus-bar { display: flex; gap: 12rpx; margin-bottom: 16rpx; padding: 0 4rpx; font-size: 22rpx; color: #6b7280; }
.campus-name { color: #2f6bff; font-weight: 600; }
.section { background: #fff; border-radius: 20rpx; padding: 8rpx 24rpx 24rpx; margin-bottom: 20rpx; }
.section-title { display: block; font-size: 28rpx; font-weight: 700; color: #111827; padding: 22rpx 0 8rpx; }
.section-head { display: flex; justify-content: space-between; align-items: center; }
.add-sku { font-size: 24rpx; color: #2f6bff; }
.field { display: flex; align-items: center; padding: 20rpx 0; border-bottom: 1rpx solid #f3f4f6; }
.field.column { display: block; }
.field:last-child { border-bottom: none; }
.label { width: 190rpx; font-size: 24rpx; color: #6b7280; flex-shrink: 0; }
.input, .textarea, .picker { flex: 1; font-size: 27rpx; color: #111827; }
.textarea { width: 100%; height: 160rpx; margin-top: 12rpx; line-height: 1.6; }
.images { display: flex; flex-wrap: wrap; gap: 16rpx; padding-top: 20rpx; }
.image-item { position: relative; width: 150rpx; height: 150rpx; }
.image { width: 150rpx; height: 150rpx; border-radius: 16rpx; background: #f3f4f6; }
.remove { position: absolute; top: -10rpx; right: -10rpx; width: 40rpx; height: 40rpx; border-radius: 50%; background: rgba(17, 24, 39, 0.7); color: #fff; font-size: 28rpx; text-align: center; line-height: 40rpx; }
.image-add { width: 150rpx; height: 150rpx; border-radius: 16rpx; background: #f3f4f6; color: #9ca3af; font-size: 44rpx; display: flex; align-items: center; justify-content: center; }
.hint { display: block; font-size: 20rpx; color: #9ca3af; padding: 12rpx 0; }
.mini { margin: 8rpx 0 0; font-size: 22rpx; background: #eef2ff; color: #2f6bff; border-radius: 999rpx; }
.sku { border-top: 1rpx dashed #e5e7eb; margin-top: 16rpx; padding-top: 8rpx; }
.sku-head { display: flex; justify-content: space-between; padding: 16rpx 0 4rpx; }
.sku-index { font-size: 24rpx; color: #374151; font-weight: 600; }
.sku-remove { font-size: 22rpx; color: #dc2626; }
.actions { position: fixed; left: 0; right: 0; bottom: 0; display: flex; gap: 16rpx; padding: 20rpx 24rpx 40rpx; background: #fff; box-shadow: 0 -4rpx 20rpx rgba(17, 24, 39, 0.06); }
.btn { flex: 1; margin: 0; border-radius: 999rpx; font-size: 28rpx; background: #f3f4f6; color: #374151; }
.btn.primary { background: #2f6bff; color: #fff; }
</style>
