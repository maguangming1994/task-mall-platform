import { CopyOutlined, DeleteOutlined, EditOutlined, PlusOutlined, StopOutlined, UploadOutlined } from '@ant-design/icons'
import { Alert, Button, Card, Checkbox, Divider, Form, Input, InputNumber, Modal, Select, Space, Table, Tag, Typography, message } from 'antd'
import { useCallback, useEffect, useState } from 'react'
import { ApiError, adjustInventory, changeManagedProductStatus, createManagedProduct, listInventories, listManagedProducts, listShippingTemplates, updateManagedProduct, type ManagedInventory, type ManagedProduct, type ManagedProductSku, type ManagedShippingTemplate } from '../../api/admin'
import { RichTextEditor } from '../../components/RichTextEditor'
import { MediaGalleryInput, type GalleryImageValue } from '../../components/MediaGalleryInput'
import { CouponTemplateManagementPanel } from './CouponTemplateManagementPanel'
import { ProductCategoryManagementPanel } from './ProductCategoryManagementPanel'
import { ShippingTemplateManagementPanel } from './ShippingTemplateManagementPanel'
import { listProductCategories, type ManagedProductCategory } from '../../api/product-categories'

interface ProductSkuForm {
  sku_code: string; name: string; specs: Array<{ name: string; value: string }>;
  fulfillment_type: ManagedProductSku['fulfillment_type']; shipping_template_id?: string; weight_grams?: number;
  modes: Array<'cash' | 'points' | 'cash_points'>; cash_price: number; points_price: number; mixed_cash_price: number; mixed_points_price: number
}
interface ProductForm {
  category_id: string; name: string; subtitle?: string; description: string;
  gallery_images: GalleryImageValue[];
  skus: ProductSkuForm[]
}
type CategorizedProduct = ManagedProduct & { category: { id: string; name: string } | null }
interface InventoryForm { action: 'increase' | 'decrease' | 'set'; quantity: number; reason: string }

const modeName = { cash: '现金', points: '积分', cash_points: '现金 + 积分' }
const productStatusMeta: Record<string, { label: string; color: string }> = {
  draft: { label: '草稿', color: 'default' },
  on_sale: { label: '销售中', color: 'success' },
  off_shelf: { label: '已下架', color: 'warning' },
  sold_out: { label: '已售罄', color: 'error' },
  deleted: { label: '已删除', color: 'default' },
}
const emptyProductForm: ProductForm = {
  category_id: '', name: '', subtitle: '', description: '', gallery_images: [],
  skus: [{ sku_code: '', name: '', specs: [{ name: '', value: '' }], fulfillment_type: 'logistics', modes: ['cash', 'points', 'cash_points'], cash_price: 1990, points_price: 2600, mixed_cash_price: 990, mixed_points_price: 1200 }],
}

function productToForm(product: CategorizedProduct, copy = false): ProductForm {
  const copySuffix = Date.now().toString().slice(-6)
  return {
    category_id: product.category?.id ?? '',
    name: copy ? `${product.name}（副本）` : product.name,
    subtitle: product.subtitle ?? '',
    description: product.description,
    gallery_images: product.gallery_images.map((image) => ({ asset_id: image.asset_id, url: image.url, alt: image.alt ?? '', sort_order: image.sort_order })),
    skus: product.skus.filter((sku) => sku.status !== 'inactive').map((sku) => {
      const options = Object.fromEntries(sku.purchase_options.map((option) => [option.purchase_mode, option]))
      return {
        sku_code: copy ? `${sku.sku_code.slice(0, 65)}-COPY-${copySuffix}` : sku.sku_code,
        name: sku.name,
        specs: Object.entries(sku.specs).map(([name, value]) => ({ name, value })),
        fulfillment_type: sku.fulfillment_type,
        shipping_template_id: sku.shipping_template_id ?? undefined,
        modes: sku.purchase_options.filter((option) => option.enabled).map((option) => option.purchase_mode),
        cash_price: options.cash?.cash_amount ?? 1,
        points_price: options.points?.points ?? 1,
        mixed_cash_price: options.cash_points?.cash_amount ?? 1,
        mixed_points_price: options.cash_points?.points ?? 1,
      }
    }),
  }
}

export function ProductManagementPage() {
  const [products, setProducts] = useState<CategorizedProduct[]>([])
  const [categories, setCategories] = useState<ManagedProductCategory[]>([])
  const [templates, setTemplates] = useState<ManagedShippingTemplate[]>([])
  const [inventories, setInventories] = useState<ManagedInventory[]>([])
  const [loading, setLoading] = useState(false)
  const [open, setOpen] = useState(false)
  const [editingProduct, setEditingProduct] = useState<CategorizedProduct | null>(null)
  const [copyingProduct, setCopyingProduct] = useState(false)
  const [inventoryItem, setInventoryItem] = useState<ManagedInventory | null>(null)
  const [submitting, setSubmitting] = useState(false)
  const [form] = Form.useForm<ProductForm>()
  const [inventoryForm] = Form.useForm<InventoryForm>()
  const [messageApi, contextHolder] = message.useMessage()
  const load = useCallback(async () => { setLoading(true); try { const [productResult, templateResult, inventoryResult, categoryResult] = await Promise.all([listManagedProducts(), listShippingTemplates(), listInventories(), listProductCategories()]); setProducts(productResult.items as CategorizedProduct[]); setTemplates(templateResult.items); setInventories(inventoryResult.items); setCategories(categoryResult.items) } catch (error) { messageApi.error(error instanceof ApiError ? error.message : '商城数据加载失败。') } finally { setLoading(false) } }, [messageApi])
  useEffect(() => { void load() }, [load])

  const openProductEditor = (product?: CategorizedProduct, copy = false) => {
    setEditingProduct(product && !copy ? product : null)
    setCopyingProduct(copy)
    form.resetFields()
    form.setFieldsValue(product ? productToForm(product, copy) : emptyProductForm)
    setOpen(true)
  }

  const saveProduct = async (values: ProductForm) => {
    setSubmitting(true)
    try {
      const payload = {
        category_id: values.category_id,
        name: values.name,
        subtitle: values.subtitle,
        description: values.description,
        gallery_images: values.gallery_images.map((image, index) => ({ ...image, sort_order: index })),
        skus: values.skus.map((sku, index) => ({
          sku_code: sku.sku_code,
          name: sku.name,
          specs: Object.fromEntries(sku.specs.filter((spec) => spec.name.trim() && spec.value.trim()).map((spec) => [spec.name.trim(), spec.value.trim()])),
          fulfillment_type: sku.fulfillment_type,
          shipping_template_id: sku.fulfillment_type === 'logistics' ? sku.shipping_template_id : null,
          weight_grams: sku.weight_grams,
          sort_order: index,
          purchase_options: sku.modes.map((mode) => ({
            purchase_mode: mode,
            cash_amount: mode === 'cash' ? sku.cash_price : mode === 'cash_points' ? sku.mixed_cash_price : 0,
            points: mode === 'points' ? sku.points_price : mode === 'cash_points' ? sku.mixed_points_price : 0,
            enabled: true,
          })),
        })),
      }
      if (editingProduct) await updateManagedProduct(editingProduct.id, { ...payload, version: editingProduct.version })
      else await createManagedProduct(payload)
      setOpen(false); setEditingProduct(null); setCopyingProduct(false); form.resetFields(); await load(); messageApi.success(editingProduct ? '商品资料已更新并生成新版本。' : copyingProduct ? '商品已复制为新草稿。' : '商品草稿已创建。')
    } catch (error) { messageApi.error(error instanceof ApiError ? error.message : editingProduct ? '商品编辑失败。' : '商品创建失败。') } finally { setSubmitting(false) }
  }

  const submitInventory = async (values: InventoryForm) => { if (!inventoryItem) return; setSubmitting(true); try { await adjustInventory(inventoryItem.sku_id, { ...values, version: inventoryItem.version }); setInventoryItem(null); inventoryForm.resetFields(); await load(); messageApi.success('库存已调整并写入不可变流水。') } catch (error) { messageApi.error(error instanceof ApiError ? error.message : '库存调整失败。') } finally { setSubmitting(false) } }

  const transition = (product: ManagedProduct, action: 'publish' | 'off_shelf') => Modal.confirm({
    title: action === 'publish' ? `上架“${product.name}”` : `下架“${product.name}”`,
    content: action === 'publish' ? '上架前会再次校验每个 SKU 至少有一种有效购买方式。' : '下架只阻止新下单，不影响历史订单价格快照和售后。',
    onOk: async () => { await changeManagedProductStatus(product.id, action, action === 'publish' ? '后台完成商品资料与价格复核。' : '后台运营下架商品。'); await load(); messageApi.success(action === 'publish' ? '商品已上架。' : '商品已下架。') },
  })

  return <>
    {contextHolder}
    <div className="page-heading"><div><Typography.Text className="eyebrow">校园商城商品管理</Typography.Text><Typography.Title level={2}>商品、库存与运费</Typography.Title><Typography.Paragraph type="secondary">三个首发端共享价格；购物车不占库存，所有库存调整只追加流水。</Typography.Paragraph></div><Button type="primary" icon={<PlusOutlined />} onClick={() => openProductEditor()}>新建商品</Button></div>
    <Alert showIcon type="info" message="同一 SKU 可选择一种、两种或全部三种购买方式；运费不在商品价中抵扣，后续始终以现金结算。" />
    <ProductCategoryManagementPanel categories={categories} loading={loading} onChanged={load} />
    <ShippingTemplateManagementPanel templates={templates} loading={loading} onChanged={load} />
    <CouponTemplateManagementPanel />
    <Card bordered={false} style={{ marginTop: 16 }}>
      <Table rowKey="id" loading={loading} dataSource={products} pagination={false} columns={[
        { title: '商品', render: (_: unknown, item: CategorizedProduct) => <Space direction="vertical" size={0}><strong>{item.name}</strong><Typography.Text type="secondary">{item.category?.name ?? '未分类'} · 定义 v{item.definition_version}</Typography.Text></Space> },
        { title: 'SKU / 购买方式', render: (_: unknown, item: CategorizedProduct) => <Space direction="vertical">{item.skus.map((sku) => <span key={sku.id}>{sku.name} · {sku.purchase_options.filter((option) => option.enabled).map((option) => <Tag key={option.id}>{modeName[option.purchase_mode]}</Tag>)}</span>)}</Space> },
        { title: '状态', dataIndex: 'status', render: (status: string) => { const meta = productStatusMeta[status] ?? { label: '未识别状态', color: 'default' }; return <Tag color={meta.color}>{meta.label}</Tag> } },
        { title: '操作', width: 300, render: (_: unknown, item: CategorizedProduct) => <Space wrap><Button icon={<EditOutlined />} onClick={() => openProductEditor(item)}>编辑</Button><Button icon={<CopyOutlined />} onClick={() => openProductEditor(item, true)}>复制</Button>{item.status === 'on_sale' ? <Button danger icon={<StopOutlined />} onClick={() => transition(item, 'off_shelf')}>下架</Button> : <Button icon={<UploadOutlined />} onClick={() => transition(item, 'publish')}>上架</Button>}</Space> },
      ]} />
    </Card>
    <Card bordered={false} style={{ marginTop: 16 }} title="库存与低库存预警">
      <Table rowKey="sku_id" loading={loading} dataSource={inventories} pagination={false} columns={[
        { title: '商品 / SKU', render: (_: unknown, item: ManagedInventory) => <Space direction="vertical" size={0}><strong>{item.product_name}</strong><Typography.Text type="secondary">{item.sku_code}</Typography.Text></Space> },
        { title: '在库', dataIndex: 'on_hand_quantity' }, { title: '预占', dataIndex: 'reserved_quantity' }, { title: '可售', dataIndex: 'available_quantity' },
        { title: '预警', render: (_: unknown, item: ManagedInventory) => <Tag color={item.low_stock ? 'error' : 'success'}>{item.low_stock ? `低于 ${item.low_stock_threshold}` : '正常'}</Tag> },
        { title: '操作', render: (_: unknown, item: ManagedInventory) => <Button onClick={() => { setInventoryItem(item); inventoryForm.setFieldsValue({ action: 'increase', quantity: 1, reason: '' }) }}>调整库存</Button> },
      ]} />
    </Card>
    <Modal title={editingProduct ? `编辑商品：${editingProduct.name}` : copyingProduct ? '复制商品为新草稿' : '新建商品草稿'} open={open} onCancel={() => { setOpen(false); setEditingProduct(null); setCopyingProduct(false) }} onOk={() => form.submit()} confirmLoading={submitting} width={1000} styles={{ body: { maxHeight: '72vh', overflowY: 'auto', paddingRight: 8 } }} okText={editingProduct ? '保存并生成新版本' : '保存商品草稿'} cancelText="取消">
      <Form form={form} layout="vertical" onFinish={saveProduct} initialValues={emptyProductForm}>
        {editingProduct?.status === 'on_sale' && <Alert showIcon type="warning" message="当前商品正在销售。保存后生成新的商品定义版本，只影响后续新订单；历史订单继续使用下单时的价格和商品快照。" style={{ marginBottom: 16 }} />}
        <Typography.Title level={5}>一、商品基础信息</Typography.Title>
        <Space align="start" style={{ display: 'flex' }}><Form.Item name="category_id" label="商品分类" rules={[{ required: true, message: '请选择商品分类' }]} style={{ width: 220 }}><Select placeholder="请选择分类" options={categories.filter(item => item.status === 'active' || item.id === editingProduct?.category?.id).map(item => ({ value: item.id, label: item.status === 'active' ? item.name : `${item.name}（已停用）` }))} /></Form.Item><Form.Item name="name" label="商品名称" rules={[{ required: true }]} style={{ flex: 1 }}><Input /></Form.Item><Form.Item name="subtitle" label="副标题" style={{ flex: 1 }}><Input /></Form.Item></Space>

        <Form.Item name="gallery_images" label="商品主图与轮播图" required extra="支持本地上传、HTTPS 链接转存和直接外链；长期使用的图片建议转存到当前存储。" rules={[{ validator: async (_, images) => { if (!images?.length) throw new Error('至少添加一张商品图片') } }]}><MediaGalleryInput /></Form.Item>

        <Form.Item name="description" label="商品详情" rules={[{ required: true, message: '请输入商品详情' }]}><RichTextEditor placeholder="请输入商品介绍、规格说明和售后提示…" /></Form.Item>
        <Divider />
        <Typography.Title level={5}>二、销售规格与 SKU</Typography.Title>
        <Alert showIcon type="info" message="颜色、尺寸、容量等规格按 SKU 分别填写；每个 SKU 可以独立配置交付方式和三种价格。" style={{ marginBottom: 16 }} />
        <Form.List name="skus" rules={[{ validator: async (_, skus) => { if (!skus?.length) throw new Error('至少添加一个 SKU') } }]}>
          {(skuFields, { add: addSku, remove: removeSku }, { errors }) => <Space direction="vertical" size={16} style={{ width: '100%' }}>
            {skuFields.map((skuField, skuIndex) => <Card key={skuField.key} size="small" title={`SKU ${skuIndex + 1}`} extra={skuFields.length > 1 && <Button danger type="text" icon={<DeleteOutlined />} onClick={() => removeSku(skuField.name)}>删除 SKU</Button>}>
              <Space align="start" style={{ display: 'flex' }}>
                <Form.Item name={[skuField.name, 'sku_code']} label="SKU 编码" rules={[{ required: true }]} style={{ flex: 1 }}><Input placeholder="如 CUP-WHITE-450" /></Form.Item>
                <Form.Item name={[skuField.name, 'name']} label="SKU 名称" rules={[{ required: true }]} style={{ flex: 1 }}><Input placeholder="如 白色 450ml" /></Form.Item>
                <Form.Item name={[skuField.name, 'fulfillment_type']} label="交付类型" rules={[{ required: true }]} style={{ width: 150 }}><Select options={[{ value: 'logistics', label: '物流配送' }, { value: 'no_logistics', label: '无需物流' }, { value: 'virtual_delivery', label: '虚拟交付' }]} /></Form.Item>
              </Space>

              <Form.Item label="规格属性" extra="例如：颜色=白色、容量=450ml。">
                <Form.List name={[skuField.name, 'specs']}>
                  {(specFields, { add: addSpec, remove: removeSpec }) => <Space direction="vertical" style={{ width: '100%' }}>
                    {specFields.map((specField) => <Space key={specField.key} align="start"><Form.Item name={[specField.name, 'name']} rules={[{ required: true, message: '填写规格名' }]}><Input placeholder="规格名，如颜色" /></Form.Item><Form.Item name={[specField.name, 'value']} rules={[{ required: true, message: '填写规格值' }]}><Input placeholder="规格值，如白色" /></Form.Item><Button danger type="text" icon={<DeleteOutlined />} onClick={() => removeSpec(specField.name)} /></Space>)}
                    <Button type="dashed" size="small" icon={<PlusOutlined />} onClick={() => addSpec({ name: '', value: '' })}>添加规格属性</Button>
                  </Space>}
                </Form.List>
              </Form.Item>

              <Space align="start" wrap>
                <Form.Item noStyle shouldUpdate>{({ getFieldValue }) => getFieldValue(['skus', skuField.name, 'fulfillment_type']) === 'logistics' ? <Form.Item name={[skuField.name, 'shipping_template_id']} label="运费模板" rules={[{ required: true, message: '请选择运费模板' }]}><Select style={{ width: 220 }} options={templates.filter((item) => item.status === 'active').map((item) => ({ value: item.id, label: item.name }))} /></Form.Item> : null}</Form.Item>
                <Form.Item name={[skuField.name, 'weight_grams']} label="计费重量（克）"><InputNumber min={1} /></Form.Item>
              </Space>
              <Form.Item name={[skuField.name, 'modes']} label="开放购买方式" rules={[{ required: true, type: 'array', min: 1 }]}><Checkbox.Group options={[{ value: 'cash', label: '现金' }, { value: 'points', label: '积分' }, { value: 'cash_points', label: '现金 + 积分' }]} /></Form.Item>
              <Space align="start" wrap><Form.Item name={[skuField.name, 'cash_price']} label="纯现金（分）" rules={[{ required: true }]}><InputNumber min={1} /></Form.Item><Form.Item name={[skuField.name, 'points_price']} label="纯积分" rules={[{ required: true }]}><InputNumber min={1} /></Form.Item><Form.Item name={[skuField.name, 'mixed_cash_price']} label="混合现金（分）" rules={[{ required: true }]}><InputNumber min={1} /></Form.Item><Form.Item name={[skuField.name, 'mixed_points_price']} label="混合积分" rules={[{ required: true }]}><InputNumber min={1} /></Form.Item></Space>
            </Card>)}
            <Form.ErrorList errors={errors} />
            <Button type="dashed" icon={<PlusOutlined />} onClick={() => addSku({ sku_code: '', name: '', specs: [{ name: '', value: '' }], fulfillment_type: 'logistics', modes: ['cash'], cash_price: 1990, points_price: 2600, mixed_cash_price: 990, mixed_points_price: 1200 })}>添加 SKU</Button>
          </Space>}
        </Form.List>
      </Form>
    </Modal>
    <Modal title="库存调整" open={!!inventoryItem} onCancel={() => setInventoryItem(null)} onOk={() => inventoryForm.submit()} confirmLoading={submitting}><Form form={inventoryForm} layout="vertical" onFinish={submitInventory}><Form.Item name="action" label="调整方式" rules={[{ required: true }]}><Select options={[{ value: 'increase', label: '入库增加' }, { value: 'decrease', label: '盘点减少' }, { value: 'set', label: '设置在库总数' }]} /></Form.Item><Form.Item name="quantity" label="数量" rules={[{ required: true }]}><InputNumber min={0} /></Form.Item><Form.Item name="reason" label="调整原因" rules={[{ required: true }]}><Input.TextArea /></Form.Item><Alert type="warning" showIcon message="减少或设置库存不能侵占已预占数量，提交后会生成不可删除流水。" /></Form></Modal>
  </>
}
