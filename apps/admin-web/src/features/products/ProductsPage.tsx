import { Button, Form, Input, InputNumber, Modal, Select, Table, message } from 'antd'
import { useCallback, useEffect, useMemo, useState } from 'react'
import {
  ApiError,
  adjustInventory,
  changeManagedProductStatus,
  createManagedProduct,
  listInventories,
  listManagedProducts,
  updateManagedProduct,
  type ManagedInventory,
  type ManagedProduct,
} from '../../api/admin'
import { Links, PCell, PageHead, Pill, type PillTone } from '../../components/xq'

const formatMoney = (cents: number) => `¥${(cents / 100).toFixed(2)}`
const LOW_STOCK = 10

function priceOf(product: ManagedProduct) {
  const cash: number[] = []
  const points: number[] = []
  product.skus.forEach((sku) => sku.purchase_options.forEach((option) => {
    if (!option.enabled) return
    if (option.cash_amount > 0) cash.push(option.cash_amount)
    if (option.points > 0) points.push(option.points)
  }))
  if (cash.length) {
    const min = Math.min(...cash)
    const max = Math.max(...cash)
    return min === max ? formatMoney(min) : `${formatMoney(min)} 起`
  }
  if (points.length) return `${Math.min(...points)} 积分`
  return '—'
}

const stockOf = (product: ManagedProduct) => product.skus.reduce((sum, sku) => sum + sku.stock, 0)

function statusOf(product: ManagedProduct): { label: string; tone: PillTone } {
  if (product.status === 'on_sale') {
    return stockOf(product) <= LOW_STOCK
      ? { label: '库存预警', tone: 'orange' }
      : { label: '上架中', tone: 'green' }
  }
  if (product.status === 'off_shelf') return { label: '已下架', tone: 'gray' }
  return { label: '待上线', tone: 'blue' }
}

interface RestockForm {
  quantity: number
  reason: string
}

export function ProductsPage() {
  const [items, setItems] = useState<ManagedProduct[]>([])
  const [inventories, setInventories] = useState<ManagedInventory[]>([])
  const [loading, setLoading] = useState(false)
  const [submitting, setSubmitting] = useState(false)
  const [status, setStatus] = useState('')
  const [keywordInput, setKeywordInput] = useState('')
  const [keyword, setKeyword] = useState('')
  const [editor, setEditor] = useState<{ product: ManagedProduct | null } | null>(null)
  const [restockTarget, setRestockTarget] = useState<ManagedProduct | null>(null)
  const [editorForm] = Form.useForm<{ name: string }>()
  const [restockForm] = Form.useForm<RestockForm>()
  const [messageApi, contextHolder] = message.useMessage()

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const [productResult, inventoryResult] = await Promise.all([listManagedProducts(), listInventories()])
      setItems(productResult.items)
      setInventories(inventoryResult.items)
    } catch (cause) {
      messageApi.error(cause instanceof ApiError ? cause.message : '商品列表加载失败。')
    } finally {
      setLoading(false)
    }
  }, [messageApi])

  useEffect(() => { void load() }, [load])

  const filtered = useMemo(() => items.filter((item) => {
    if (status && item.status !== status) return false
    if (keyword) {
      const lower = keyword.toLowerCase()
      const hitName = item.name.toLowerCase().includes(lower)
      const hitSku = item.skus.some((sku) => sku.sku_code.toLowerCase().includes(lower))
      if (!hitName && !hitSku) return false
    }
    return true
  }), [items, status, keyword])

  const transition = (product: ManagedProduct, action: 'publish' | 'off_shelf') => Modal.confirm({
    title: action === 'publish' ? `上架“${product.name}”` : `下架“${product.name}”`,
    content: action === 'publish' ? '上架前会再次校验每个 SKU 至少有一种有效购买方式。' : '下架只阻止新下单，不影响历史订单与售后。',
    okText: action === 'publish' ? '确认上架' : '确认下架',
    cancelText: '取消',
    onOk: async () => {
      try {
        await changeManagedProductStatus(product.id, action, action === 'publish' ? '后台完成商品资料与价格复核。' : '后台运营下架商品。')
        messageApi.success(action === 'publish' ? '商品已上架。' : '商品已下架。')
        await load()
      } catch (cause) {
        messageApi.error(cause instanceof ApiError ? cause.message : '商品状态变更失败。')
      }
    },
  })

  const saveEditor = async (values: { name: string }) => {
    if (!editor) return
    setSubmitting(true)
    try {
      if (editor.product) {
        await updateManagedProduct(editor.product.id, { name: values.name })
        messageApi.success('商品已更新。')
      } else {
        await createManagedProduct({ name: values.name, description: '' })
        messageApi.success('商品草稿已创建，请完善价格与库存后上架。')
      }
      setEditor(null)
      editorForm.resetFields()
      await load()
    } catch (cause) {
      messageApi.error(cause instanceof ApiError ? cause.message : '商品保存失败。')
    } finally {
      setSubmitting(false)
    }
  }

  const restock = async (values: RestockForm) => {
    if (!restockTarget) return
    const sku = [...restockTarget.skus].sort((a, b) => a.stock - b.stock)[0]
    if (!sku) return messageApi.warning('该商品还没有 SKU，无法补货。')
    const inventory = inventories.find((item) => item.sku_id === sku.id)
    if (!inventory) return messageApi.warning('未找到该 SKU 的库存记录。')
    setSubmitting(true)
    try {
      await adjustInventory(sku.id, { action: 'increase', quantity: values.quantity, version: inventory.version, reason: values.reason })
      messageApi.success(`已为 SKU ${sku.sku_code} 补货 ${values.quantity} 件。`)
      setRestockTarget(null)
      restockForm.resetFields()
      await load()
    } catch (cause) {
      messageApi.error(cause instanceof ApiError ? cause.message : '补货失败。')
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <div className="xq-page">
      {contextHolder}
      <PageHead title="商品管理" extra={<Button type="primary" onClick={() => { editorForm.resetFields(); setEditor({ product: null }) }}>+ 新增商品</Button>} />

      <div className="xq-card xq-filter" style={{ marginBottom: 16 }}>
        <Select value="all" style={{ minWidth: 130 }} options={[{ value: 'all', label: '全部分类' }]} />
        <Select
          value={status || 'all'}
          style={{ minWidth: 130 }}
          onChange={(value) => setStatus(value === 'all' ? '' : value)}
          options={[
            { value: 'all', label: '全部状态' },
            { value: 'on_sale', label: '上架中' },
            { value: 'off_shelf', label: '已下架' },
            { value: 'draft', label: '待上线' },
          ]}
        />
        <Input
          placeholder="商品名称/SKU"
          style={{ maxWidth: 220 }}
          value={keywordInput}
          onChange={(event) => setKeywordInput(event.target.value)}
          onPressEnter={() => setKeyword(keywordInput.trim())}
          allowClear
        />
        <Button type="primary" onClick={() => setKeyword(keywordInput.trim())}>查询</Button>
        <Button onClick={() => { setStatus(''); setKeywordInput(''); setKeyword('') }}>重置</Button>
      </div>

      <div className="xq-card xq-table">
        <Table
          rowKey="id"
          size="middle"
          loading={loading}
          dataSource={filtered}
          pagination={{ pageSize: 10, showTotal: (total) => `共 ${total} 条` }}
          columns={[
            {
              title: '商品信息',
              render: (_: unknown, item: ManagedProduct) => (
                <PCell name={item.name} sub={`SKU: ${item.skus[0]?.sku_code ?? '—'}`} img={item.gallery_images[0]?.url ?? null} />
              ),
            },
            { title: '分类', render: (_: unknown, item: ManagedProduct) => item.category?.name ?? '—' },
            { title: '售价', render: (_: unknown, item: ManagedProduct) => priceOf(item) },
            { title: '库存', render: (_: unknown, item: ManagedProduct) => {
              const stock = stockOf(item)
              return <span style={stock <= LOW_STOCK ? { color: '#dc2626', fontWeight: 700 } : undefined}>{stock}</span>
            } },
            { title: '状态', render: (_: unknown, item: ManagedProduct) => {
              const meta = statusOf(item)
              return <Pill tone={meta.tone}>{meta.label}</Pill>
            } },
            {
              title: '操作',
              render: (_: unknown, item: ManagedProduct) => (
                <Links items={[
                  { label: '编辑', onClick: () => { editorForm.setFieldsValue({ name: item.name }); setEditor({ product: item }) } },
                  item.status === 'on_sale'
                    ? { label: '下架', onClick: () => transition(item, 'off_shelf') }
                    : { label: '上架', onClick: () => transition(item, 'publish') },
                  item.status === 'on_sale' && stockOf(item) <= LOW_STOCK && { label: '补货', onClick: () => { restockForm.resetFields(); setRestockTarget(item) } },
                ]} />
              ),
            },
          ]}
        />
      </div>

      <Modal
        title={editor?.product ? '编辑商品' : '新增商品'}
        open={editor !== null}
        onCancel={() => setEditor(null)}
        onOk={() => editorForm.submit()}
        okText="保存"
        cancelText="取消"
        confirmLoading={submitting}
        destroyOnHidden
      >
        <Form form={editorForm} layout="vertical" onFinish={saveEditor}>
          <Form.Item name="name" label="商品名称" rules={[{ required: true, message: '请输入商品名称' }]}>
            <Input maxLength={120} placeholder="例如 校圈定制帆布包" />
          </Form.Item>
        </Form>
      </Modal>

      <Modal
        title={restockTarget ? `补货 · ${restockTarget.name}` : '补货'}
        open={restockTarget !== null}
        onCancel={() => setRestockTarget(null)}
        onOk={() => restockForm.submit()}
        okText="确认补货"
        cancelText="取消"
        confirmLoading={submitting}
        destroyOnHidden
      >
        <Form form={restockForm} layout="vertical" onFinish={restock}>
          <Form.Item name="quantity" label="补货数量" rules={[{ required: true, message: '请输入补货数量' }]}>
            <InputNumber min={1} precision={0} style={{ width: '100%' }} placeholder="入库数量将写入库存流水" />
          </Form.Item>
          <Form.Item name="reason" label="补货原因" rules={[{ required: true, min: 2, message: '请填写补货原因' }]}>
            <Input.TextArea rows={3} maxLength={500} placeholder="例如 采购到货入库" />
          </Form.Item>
        </Form>
      </Modal>
    </div>
  )
}
