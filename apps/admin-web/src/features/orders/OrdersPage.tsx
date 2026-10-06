import { Button, Descriptions, Drawer, Form, Input, Modal, Select, Table, Timeline, message } from 'antd'
import { useCallback, useEffect, useState } from 'react'
import {
  ApiError,
  createManagedFulfillment,
  getManagedOrder,
  listManagedOrders,
  type ManagedOrder,
} from '../../api/admin'
import { Links, PageHead, Pill, UCell, type PillTone } from '../../components/xq'
import { businessLabel, fulfillmentTypeLabels, orderStatusLabels } from '../../constants/businessLabels'

const formatMoney = (cents = 0) => `¥${(cents / 100).toFixed(2)}`
const formatDate = (value: string | null | undefined) =>
  value ? new Date(value).toLocaleString('zh-CN', { hour12: false }) : '—'

const statusTone: Record<string, PillTone> = {
  pending_payment: 'orange',
  pending_fulfillment: 'blue',
  partially_fulfilled: 'blue',
  shipped: 'blue',
  delivered: 'blue',
  fulfilled: 'blue',
  completed: 'green',
  cancelled: 'gray',
  closed: 'gray',
  refund_processing: 'orange',
  refunded: 'gray',
  partially_refunded: 'gray',
}

const fulfillable = (order: ManagedOrder) =>
  ['pending_fulfillment', 'partially_fulfilled'].includes(order.status) &&
  order.items.some((item) => item.remaining_fulfillment_quantity > 0)

interface ShipForm {
  method: 'logistics' | 'no_logistics'
  carrier_code?: string
  tracking_no?: string
  no_logistics_reason?: string
}

function orderAddress(order: ManagedOrder) {
  const address = order.address_snapshot
  if (!address) return '无需收货地址'
  const name = String(address.recipient_name ?? address.name ?? '')
  const phone = String(address.phone_masked ?? address.phone ?? '')
  const detail = [address.province, address.city, address.district, address.detail].filter(Boolean).join(' ')
  return [name, phone, detail].filter(Boolean).join(' · ') || '地址快照已保存'
}

export function OrdersPage() {
  const [items, setItems] = useState<ManagedOrder[]>([])
  const [total, setTotal] = useState(0)
  const [page, setPage] = useState(1)
  const [loading, setLoading] = useState(false)
  const [submitting, setSubmitting] = useState(false)
  const [status, setStatus] = useState('')
  const [keywordInput, setKeywordInput] = useState('')
  const [keyword, setKeyword] = useState('')
  const [detail, setDetail] = useState<ManagedOrder | null>(null)
  const [detailLoading, setDetailLoading] = useState(false)
  const [shipTarget, setShipTarget] = useState<ManagedOrder | null>(null)
  const [shipForm] = Form.useForm<ShipForm>()
  const shipMethod = Form.useWatch('method', shipForm)
  const [messageApi, contextHolder] = message.useMessage()

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const result = await listManagedOrders({ keyword, status, page, page_size: 10 })
      setItems(result.items)
      setTotal(result.pagination?.total ?? result.items.length)
    } catch (cause) {
      messageApi.error(cause instanceof ApiError ? cause.message : '订单列表加载失败。')
    } finally {
      setLoading(false)
    }
  }, [keyword, status, page, messageApi])

  useEffect(() => { void load() }, [load])

  const openDetail = async (order: ManagedOrder) => {
    setDetail(order)
    setDetailLoading(true)
    try {
      setDetail(await getManagedOrder(order.id))
    } catch (cause) {
      messageApi.error(cause instanceof ApiError ? cause.message : '订单详情加载失败。')
    } finally {
      setDetailLoading(false)
    }
  }

  const ship = async (values: ShipForm) => {
    if (!shipTarget) return
    const shipItems = shipTarget.items
      .filter((item) => item.remaining_fulfillment_quantity > 0)
      .map((item) => ({ order_item_id: item.id, quantity: item.remaining_fulfillment_quantity }))
    if (!shipItems.length) return messageApi.warning('该订单没有可发货的商品。')
    setSubmitting(true)
    try {
      await createManagedFulfillment(shipTarget.id, {
        method: values.method,
        carrier_code: values.carrier_code,
        tracking_no: values.tracking_no,
        no_logistics_reason: values.no_logistics_reason,
        items: shipItems,
      })
      messageApi.success('发货成功。')
      setShipTarget(null)
      shipForm.resetFields()
      await load()
    } catch (cause) {
      messageApi.error(cause instanceof ApiError ? cause.message : '发货失败。')
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <div className="xq-page">
      {contextHolder}
      <PageHead title="订单管理" />

      <div className="xq-card xq-filter" style={{ marginBottom: 16 }}>
        <Select
          value={status || 'all'}
          style={{ minWidth: 130 }}
          onChange={(value) => { setStatus(value === 'all' ? '' : value); setPage(1) }}
          options={[
            { value: 'all', label: '全部状态' },
            ...Object.entries(orderStatusLabels).map(([value, label]) => ({ value, label })),
          ]}
        />
        <Input
          placeholder="订单号/用户"
          style={{ maxWidth: 220 }}
          value={keywordInput}
          onChange={(event) => setKeywordInput(event.target.value)}
          onPressEnter={() => { setKeyword(keywordInput.trim()); setPage(1) }}
          allowClear
        />
        <Button type="primary" onClick={() => { setKeyword(keywordInput.trim()); setPage(1) }}>查询</Button>
        <Button onClick={() => { setStatus(''); setKeywordInput(''); setKeyword(''); setPage(1) }}>重置</Button>
      </div>

      <div className="xq-card xq-table">
        <Table
          rowKey="id"
          size="middle"
          loading={loading}
          dataSource={items}
          pagination={{
            current: page,
            pageSize: 10,
            total,
            showTotal: (value) => `共 ${value} 条`,
            onChange: (next) => setPage(next),
          }}
          columns={[
            { title: '订单号', dataIndex: 'order_no', render: (value: string) => <span style={{ fontWeight: 600 }}>{value}</span> },
            {
              title: '买家',
              render: (_: unknown, order: ManagedOrder) => (
                <UCell name={order.user.nickname || '用户'} sub={`ID:${order.user.id}`} />
              ),
            },
            {
              title: '商品',
              render: (_: unknown, order: ManagedOrder) => {
                const first = order.items[0]
                if (!first) return '—'
                const extra = order.items.length - 1
                return `${first.product_name} x${first.quantity}${extra > 0 ? ` 等${order.items.length}件` : ''}`
              },
            },
            {
              title: '金额',
              render: (_: unknown, order: ManagedOrder) => (
                <span>
                  {formatMoney(order.payable_cash_amount)}
                  {order.payable_points > 0 && <span style={{ color: '#6b7280', fontSize: 12 }}> + {order.payable_points} 积分</span>}
                </span>
              ),
            },
            {
              title: '状态',
              render: (_: unknown, order: ManagedOrder) => (
                <Pill tone={statusTone[order.status] ?? 'gray'}>{businessLabel(orderStatusLabels, order.status)}</Pill>
              ),
            },
            { title: '下单时间', dataIndex: 'created_at', render: (value: string) => formatDate(value) },
            {
              title: '操作',
              render: (_: unknown, order: ManagedOrder) => (
                <Links items={[
                  { label: '详情', onClick: () => void openDetail(order) },
                  fulfillable(order) && { label: '发货', onClick: () => { shipForm.resetFields(); shipForm.setFieldsValue({ method: order.fulfillment_type === 'no_logistics' ? 'no_logistics' : 'logistics' }); setShipTarget(order) } },
                ]} />
              ),
            },
          ]}
        />
      </div>

      <Drawer title={detail ? `订单详情 · ${detail.order_no}` : '订单详情'} width={560} open={detail !== null} onClose={() => setDetail(null)} loading={detailLoading}>
        {detail && (
          <>
            <Descriptions
              column={1}
              size="small"
              bordered
              items={[
                { key: 'status', label: '订单状态', children: <Pill tone={statusTone[detail.status] ?? 'gray'}>{businessLabel(orderStatusLabels, detail.status)}</Pill> },
                { key: 'buyer', label: '买家', children: `${detail.user.nickname}（ID:${detail.user.id}）` },
                { key: 'fulfillment', label: '履约方式', children: businessLabel(fulfillmentTypeLabels, detail.fulfillment_type) },
                { key: 'amount', label: '应付金额', children: `${formatMoney(detail.payable_cash_amount)}${detail.payable_points > 0 ? ` + ${detail.payable_points} 积分` : ''}` },
                { key: 'address', label: '收货信息', children: orderAddress(detail) },
                { key: 'created', label: '下单时间', children: formatDate(detail.created_at) },
                { key: 'paid', label: '支付时间', children: formatDate(detail.paid_at) },
              ]}
            />
            <p className="xq-card-title" style={{ marginTop: 20 }}>商品清单</p>
            <Table
              rowKey="id"
              size="small"
              dataSource={detail.items}
              pagination={false}
              columns={[
                { title: '商品', dataIndex: 'product_name' },
                { title: '规格', dataIndex: 'sku_name' },
                { title: '数量', dataIndex: 'quantity', width: 70 },
                { title: '单价', render: (_: unknown, item) => item.unit_cash_amount ? formatMoney(item.unit_cash_amount) : `${item.unit_points} 积分`, width: 100 },
              ]}
            />
            {detail.status_changes && detail.status_changes.length > 0 && (
              <>
                <p className="xq-card-title" style={{ marginTop: 20 }}>订单时间线</p>
                <Timeline
                  items={detail.status_changes.map((change) => ({
                    children: (
                      <>
                        <b>{businessLabel(orderStatusLabels, change.to_status)}</b>
                        <br />
                        <span style={{ color: '#6b7280', fontSize: 12 }}>{change.reason} · {formatDate(change.created_at)}</span>
                      </>
                    ),
                  }))}
                />
              </>
            )}
          </>
        )}
      </Drawer>

      <Modal
        title={shipTarget ? `订单发货 · ${shipTarget.order_no}` : '订单发货'}
        open={shipTarget !== null}
        onCancel={() => setShipTarget(null)}
        onOk={() => shipForm.submit()}
        okText="确认发货"
        cancelText="取消"
        confirmLoading={submitting}
        destroyOnHidden
      >
        <Form form={shipForm} layout="vertical" onFinish={ship} initialValues={{ method: 'logistics' }}>
          <Form.Item name="method" label="履约方式" rules={[{ required: true }]}>
            <Select options={[{ value: 'logistics', label: '物流配送' }, { value: 'no_logistics', label: '无需物流' }]} />
          </Form.Item>
          {shipMethod === 'logistics' && (
            <>
              <Form.Item name="carrier_code" label="承运商编码" rules={[{ required: true, message: '请填写承运商编码' }]}>
                <Input placeholder="例如 SF / ZTO / YTO" />
              </Form.Item>
              <Form.Item name="tracking_no" label="物流单号" rules={[{ required: true, message: '请填写物流单号' }]}>
                <Input placeholder="请输入物流单号" />
              </Form.Item>
            </>
          )}
          {shipMethod === 'no_logistics' && (
            <Form.Item name="no_logistics_reason" label="无需物流原因" rules={[{ required: true, min: 4, message: '请填写无需物流原因' }]}>
              <Input.TextArea rows={3} placeholder="例如 线下交付、到店自取" />
            </Form.Item>
          )}
        </Form>
      </Modal>
    </div>
  )
}
