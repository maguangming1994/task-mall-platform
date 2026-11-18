import { Button, Form, Input, InputNumber, Modal, Select, Table, message } from 'antd'
import { useCallback, useEffect, useMemo, useState } from 'react'
import {
  ApiError,
  adjustInventory,
  listInventories,
  type ManagedInventory,
} from '../../api/admin'
import { Links, PCell, PageHead, Pill, StatCard, type PillTone } from '../../components/xq'

type StockState = 'out' | 'low' | 'normal'

const stateMeta: Record<StockState, { label: string; tone: PillTone }> = {
  out: { label: '缺货', tone: 'red' },
  low: { label: '库存预警', tone: 'orange' },
  normal: { label: '正常', tone: 'green' },
}

function stateOf(item: ManagedInventory): StockState {
  if (item.available_quantity <= 0) return 'out'
  if (item.low_stock) return 'low'
  return 'normal'
}

interface AdjustForm {
  quantity: number
  reason: string
}

export function InventoryPage() {
  const [items, setItems] = useState<ManagedInventory[]>([])
  const [loading, setLoading] = useState(false)
  const [submitting, setSubmitting] = useState(false)
  const [status, setStatus] = useState('')
  const [keywordInput, setKeywordInput] = useState('')
  const [keyword, setKeyword] = useState('')
  const [adjustTarget, setAdjustTarget] = useState<ManagedInventory | null>(null)
  const [form] = Form.useForm<AdjustForm>()
  const [messageApi, contextHolder] = message.useMessage()

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const result = await listInventories()
      setItems(result.items)
    } catch (cause) {
      messageApi.error(cause instanceof ApiError ? cause.message : '库存列表加载失败。')
    } finally {
      setLoading(false)
    }
  }, [messageApi])

  useEffect(() => { void load() }, [load])

  const filtered = useMemo(() => items.filter((item) => {
    if (status && stateOf(item) !== status) return false
    if (keyword) {
      const lower = keyword.toLowerCase()
      if (!item.product_name.toLowerCase().includes(lower) && !item.sku_code.toLowerCase().includes(lower)) return false
    }
    return true
  }), [items, status, keyword])

  const stats = useMemo(() => ({
    total: items.length,
    low: items.filter((item) => stateOf(item) === 'low').length,
    out: items.filter((item) => stateOf(item) === 'out').length,
    onSale: items.filter((item) => item.available_quantity > 0).length,
  }), [items])

  const adjust = async (values: AdjustForm) => {
    if (!adjustTarget) return
    setSubmitting(true)
    try {
      await adjustInventory(adjustTarget.sku_id, {
        action: 'increase',
        quantity: values.quantity,
        version: adjustTarget.version,
        reason: values.reason,
      })
      messageApi.success(`已为 SKU ${adjustTarget.sku_code} 补货 ${values.quantity} 件。`)
      setAdjustTarget(null)
      form.resetFields()
      await load()
    } catch (cause) {
      messageApi.error(cause instanceof ApiError ? cause.message : '库存调整失败。')
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <div className="xq-page">
      {contextHolder}
      <PageHead title="库存管理" />

      <div className="xq-stats">
        <StatCard title="SKU 总数" value={stats.total} delta={`在售 ${stats.onSale}`} />
        <StatCard title="库存预警" value={stats.low} valueColor="#d97706" delta="低于预警阈值" />
        <StatCard title="缺货 SKU" value={stats.out} valueColor="#dc2626" delta="已自动下架" />
        <StatCard title="库存总价值" value="—" delta="按成本价估算" />
      </div>

      <div className="xq-card xq-filter" style={{ marginBottom: 16 }}>
        <Select
          value={status || 'all'}
          style={{ minWidth: 130 }}
          onChange={(value) => setStatus(value === 'all' ? '' : value)}
          options={[
            { value: 'all', label: '全部状态' },
            { value: 'normal', label: '正常' },
            { value: 'low', label: '库存预警' },
            { value: 'out', label: '缺货' },
          ]}
        />
        <Select value="all" style={{ minWidth: 130 }} options={[{ value: 'all', label: '全部分类' }]} />
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
          rowKey="sku_id"
          size="middle"
          loading={loading}
          dataSource={filtered}
          pagination={{ pageSize: 10, showTotal: (total) => `共 ${total} 条` }}
          columns={[
            {
              title: '商品 / SKU',
              render: (_: unknown, item: ManagedInventory) => (
                <PCell name={item.product_name} sub={`SKU: ${item.sku_code}`} />
              ),
            },
            { title: '分类', render: () => '—' },
            {
              title: '当前库存',
              render: (_: unknown, item: ManagedInventory) => {
                const state = stateOf(item)
                return (
                  <span style={state !== 'normal' ? { color: state === 'out' ? '#dc2626' : '#d97706', fontWeight: 700 } : undefined}>
                    {item.available_quantity}
                  </span>
                )
              },
            },
            { title: '预警阈值', dataIndex: 'low_stock_threshold' },
            { title: '近7天销量', render: () => '—' },
            {
              title: '状态',
              render: (_: unknown, item: ManagedInventory) => {
                const meta = stateMeta[stateOf(item)]
                return <Pill tone={meta.tone}>{meta.label}</Pill>
              },
            },
            {
              title: '操作',
              render: (_: unknown, item: ManagedInventory) => {
                const state = stateOf(item)
                return (
                  <Links items={[
                    { label: state === 'normal' ? '调整' : '补货', onClick: () => { form.resetFields(); setAdjustTarget(item) } },
                  ]} />
                )
              },
            },
          ]}
        />
      </div>

      <Modal
        title={adjustTarget ? `补货 · ${adjustTarget.product_name}（${adjustTarget.sku_code}）` : '补货'}
        open={adjustTarget !== null}
        onCancel={() => setAdjustTarget(null)}
        onOk={() => form.submit()}
        okText="确认补货"
        cancelText="取消"
        confirmLoading={submitting}
        destroyOnHidden
      >
        <Form form={form} layout="vertical" onFinish={adjust}>
          <Form.Item name="quantity" label="补货数量" rules={[{ required: true, message: '请输入补货数量' }]}>
            <InputNumber min={1} precision={0} style={{ width: '100%' }} placeholder="入库数量将写入不可变库存流水" />
          </Form.Item>
          <Form.Item name="reason" label="补货原因" rules={[{ required: true, min: 2, message: '请填写补货原因' }]}>
            <Input.TextArea rows={3} maxLength={500} placeholder="例如 采购到货入库" />
          </Form.Item>
        </Form>
      </Modal>
    </div>
  )
}
