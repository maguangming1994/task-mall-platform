import {
  Alert,
  Button,
  Descriptions,
  Divider,
  Drawer,
  Empty,
  Form,
  Input,
  InputNumber,
  Modal,
  Select,
  Space,
  Table,
  Tag,
  Timeline,
  Typography,
  message,
} from 'antd'
import { useCallback, useEffect, useMemo, useState } from 'react'
import {
  ApiError,
  createManagedFulfillment,
  getManagedOrder,
  listManagedOrders,
  type ManagedOrder,
  type ManagedOrderItem,
} from '../../api/admin'
import { BusinessStatusTag } from '../../components/BusinessStatusTag'
import {
  businessLabel,
  fulfillmentStatusLabels,
  fulfillmentTypeLabels,
  orderStatusLabels,
  paymentMethodLabels,
  purchaseModeLabels,
} from '../../constants/businessLabels'
import { useAdminAuth } from '../auth/AdminAuthContext'

const formatMoney = (cents = 0) => `¥${(cents / 100).toFixed(2)}`
const formatDate = (value: string | null | undefined) => value ? new Date(value).toLocaleString('zh-CN', { hour12: false }) : '—'

function orderAddress(order: ManagedOrder) {
  const address = order.address_snapshot
  if (!address) return '无需收货地址'
  const name = String(address.recipient_name ?? address.name ?? '')
  const phone = String(address.phone_masked ?? address.phone ?? '')
  const detail = [address.province, address.city, address.district, address.detail].filter(Boolean).join(' ')
  return [name, phone, detail].filter(Boolean).join(' · ') || '地址快照已保存'
}

interface FulfillmentForm {
  method: 'logistics' | 'no_logistics' | 'virtual_delivery'
  carrier_code?: string
  carrier_name?: string
  tracking_no?: string
  no_logistics_reason?: string
  virtual_delivery_content?: string
}

export function OrderManagementPanel() {
  const { admin } = useAdminAuth()
  const canFulfill = admin?.permissions.includes('fulfillment.manage') ?? false
  const [items, setItems] = useState<ManagedOrder[]>([])
  const [loading, setLoading] = useState(true)
  const [keyword, setKeyword] = useState('')
  const [status, setStatus] = useState('')
  const [fulfillmentType, setFulfillmentType] = useState('')
  const [page, setPage] = useState(1)
  const [total, setTotal] = useState(0)
  const [selected, setSelected] = useState<ManagedOrder | null>(null)
  const [detailLoading, setDetailLoading] = useState(false)
  const [fulfilling, setFulfilling] = useState<ManagedOrder | null>(null)
  const [quantities, setQuantities] = useState<Record<string, number>>({})
  const [submitting, setSubmitting] = useState(false)
  const [form] = Form.useForm<FulfillmentForm>()
  const [messageApi, contextHolder] = message.useMessage()

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const result = await listManagedOrders({ keyword, status, fulfillment_type: fulfillmentType, page, page_size: 20 })
      setItems(result.items)
      setTotal(result.pagination.total)
    } catch (error) {
      messageApi.error(error instanceof ApiError ? error.message : '订单列表加载失败。')
    } finally {
      setLoading(false)
    }
  }, [fulfillmentType, keyword, messageApi, page, status])

  useEffect(() => { void load() }, [load])

  const openDetail = useCallback(async (order: ManagedOrder) => {
    setSelected(order)
    setDetailLoading(true)
    try { setSelected(await getManagedOrder(order.id)) }
    catch (error) { messageApi.error(error instanceof ApiError ? error.message : '订单详情加载失败。') }
    finally { setDetailLoading(false) }
  }, [messageApi])

  const openFulfillment = useCallback((order: ManagedOrder) => {
    const available = Object.fromEntries(order.items.map((item) => [item.id, item.remaining_fulfillment_quantity]))
    setQuantities(available)
    setFulfilling(order)
    form.setFieldsValue({
      method: order.fulfillment_type as FulfillmentForm['method'],
      carrier_code: '', carrier_name: '', tracking_no: '', no_logistics_reason: '', virtual_delivery_content: '',
    })
  }, [form])

  const submitFulfillment = async () => {
    if (!fulfilling) return
    try {
      const values = await form.validateFields()
      const fulfillmentItems = fulfilling.items
        .map((item) => ({ order_item_id: item.id, quantity: quantities[item.id] ?? 0 }))
        .filter((item) => item.quantity > 0)
      if (!fulfillmentItems.length) {
        messageApi.warning('请至少为一个商品填写本次发货数量。')
        return
      }
      setSubmitting(true)
      await createManagedFulfillment(fulfilling.id, {
        method: values.method,
        carrier_code: values.method === 'logistics' ? values.carrier_code : undefined,
        carrier_name: values.method === 'logistics' ? values.carrier_name : undefined,
        tracking_no: values.method === 'logistics' ? values.tracking_no : undefined,
        no_logistics_reason: values.method === 'no_logistics' ? values.no_logistics_reason : undefined,
        virtual_delivery: values.method === 'virtual_delivery' ? { content: values.virtual_delivery_content } : undefined,
        items: fulfillmentItems,
      })
      messageApi.success('履约记录已创建，订单状态已同步更新。')
      setFulfilling(null)
      form.resetFields()
      await load()
    } catch (error) {
      if (error instanceof ApiError) messageApi.error(error.message)
    } finally { setSubmitting(false) }
  }

  const columns = useMemo(() => [
    { title: '订单', width: 190, render: (_: unknown, order: ManagedOrder) => <Space direction="vertical" size={2}><Typography.Text strong>{order.order_no}</Typography.Text><Typography.Text type="secondary">{formatDate(order.created_at)}</Typography.Text></Space> },
    { title: '用户', width: 140, render: (_: unknown, order: ManagedOrder) => <Space direction="vertical" size={2}><span>{order.user.nickname || '未设置昵称'}</span><Typography.Text type="secondary">{order.user.phone_masked ?? order.user.id}</Typography.Text></Space> },
    { title: '商品', render: (_: unknown, order: ManagedOrder) => <Space direction="vertical" size={2}>{order.items.slice(0, 2).map((item) => <Typography.Text key={item.id}>{item.product_name} · {item.sku_name} × {item.quantity}</Typography.Text>)}{order.items.length > 2 && <Typography.Text type="secondary">另有 {order.items.length - 2} 项</Typography.Text>}</Space> },
    { title: '支付', width: 150, render: (_: unknown, order: ManagedOrder) => <Space direction="vertical" size={2}><span>{businessLabel(paymentMethodLabels, order.cash_payment_method)}</span><Typography.Text className="order-money">{formatMoney(order.payable_cash_amount)}{order.payable_points > 0 ? ` + ${order.payable_points} 积分` : ''}</Typography.Text></Space> },
    { title: '履约', width: 120, render: (_: unknown, order: ManagedOrder) => <Space direction="vertical" size={4}><Tag>{businessLabel(fulfillmentTypeLabels, order.fulfillment_type)}</Tag>{order.fulfill_by && <Typography.Text type="secondary">截止 {formatDate(order.fulfill_by)}</Typography.Text>}</Space> },
    { title: '状态', width: 110, render: (_: unknown, order: ManagedOrder) => <BusinessStatusTag status={order.status} label={businessLabel(orderStatusLabels, order.status)} /> },
    { title: '操作', fixed: 'right' as const, width: 150, render: (_: unknown, order: ManagedOrder) => <Space><Button size="small" onClick={() => void openDetail(order)}>详情</Button>{canFulfill && order.items.some((item) => item.remaining_fulfillment_quantity > 0) && ['pending_fulfillment', 'partially_fulfilled'].includes(order.status) && <Button size="small" type="primary" onClick={() => openFulfillment(order)}>发货</Button>}</Space> },
  ], [canFulfill, openDetail, openFulfillment])

  const fulfillmentMethod = Form.useWatch('method', form)

  return <>
    {contextHolder}
    <div className="order-filter-bar">
      <Input.Search allowClear placeholder="订单号、用户昵称或手机号" onSearch={(value) => { setKeyword(value.trim()); setPage(1) }} style={{ width: 280 }} />
      <Select allowClear placeholder="订单状态" value={status || undefined} onChange={(value) => { setStatus(value ?? ''); setPage(1) }} style={{ width: 150 }} options={Object.entries(orderStatusLabels).map(([value, label]) => ({ value, label }))} />
      <Select allowClear placeholder="履约方式" value={fulfillmentType || undefined} onChange={(value) => { setFulfillmentType(value ?? ''); setPage(1) }} style={{ width: 150 }} options={Object.entries(fulfillmentTypeLabels).map(([value, label]) => ({ value, label }))} />
      <Button onClick={() => void load()}>刷新</Button>
    </div>
    <Alert showIcon type="info" message="运费始终以现金结算；物流、无需物流和虚拟交付都在此留下不可删除的履约记录。" style={{ marginBottom: 16 }} />
    <Table rowKey="id" loading={loading} dataSource={items} columns={columns} scroll={{ x: 1180 }} pagination={{ current: page, pageSize: 20, total, showSizeChanger: false, showTotal: (value) => `共 ${value} 单`, onChange: setPage }} />

    <Drawer title={selected ? `订单详情 · ${selected.order_no}` : '订单详情'} width={720} open={Boolean(selected)} onClose={() => setSelected(null)} loading={detailLoading} destroyOnHidden>
      {selected && <Space direction="vertical" size={18} style={{ width: '100%' }}>
        <Descriptions column={2} bordered size="small" items={[
          { key: 'status', label: '订单状态', children: <BusinessStatusTag status={selected.status} label={businessLabel(orderStatusLabels, selected.status)} /> },
          { key: 'fulfillment', label: '履约方式', children: businessLabel(fulfillmentTypeLabels, selected.fulfillment_type) },
          { key: 'payment', label: '现金支付', children: businessLabel(paymentMethodLabels, selected.cash_payment_method) },
          { key: 'amount', label: '订单应付', children: `${formatMoney(selected.payable_cash_amount)}${selected.payable_points ? ` + ${selected.payable_points} 积分` : ''}` },
          { key: 'paid', label: '实付拆分', span: 2, children: `余额 ${formatMoney(selected.paid_wallet_amount)} · 微信 ${formatMoney(selected.paid_external_amount)} · 积分 ${selected.paid_points}` },
          { key: 'refund', label: '累计退款', span: 2, children: `余额 ${formatMoney(selected.refunded_wallet_amount)} · 微信 ${formatMoney(selected.refunded_external_amount)} · 积分 ${selected.refunded_points}` },
          { key: 'shipping', label: '原运费', children: formatMoney(selected.shipping_amount) },
          { key: 'user', label: '下单用户', children: `${selected.user.nickname} · ${selected.user.phone_masked ?? selected.user.id}` },
          { key: 'address', label: '收货信息', span: 2, children: orderAddress(selected) },
        ]} />
        <div><Typography.Title level={5}>商品与履约数量</Typography.Title><Table<ManagedOrderItem> size="small" rowKey="id" pagination={false} dataSource={selected.items} columns={[
          { title: '商品', render: (_, item) => <><strong>{item.product_name}</strong><br /><Typography.Text type="secondary">{item.sku_name}</Typography.Text></> },
          { title: '购买方式', render: (_, item) => businessLabel(purchaseModeLabels, item.purchase_mode) },
          { title: '购买', dataIndex: 'quantity' },
          { title: '已发', dataIndex: 'fulfilled_quantity' },
          { title: '可发', dataIndex: 'remaining_fulfillment_quantity' },
          { title: '已退', dataIndex: 'refunded_quantity' },
          { title: '退款占用', dataIndex: 'pending_refund_quantity' },
          { title: '换货占用', dataIndex: 'active_exchange_quantity' },
          { title: '剩余可售后', dataIndex: 'remaining_after_sale_quantity' },
        ]} /></div>
        <div><Typography.Title level={5}>履约记录</Typography.Title>{selected.fulfillments.length ? <Space direction="vertical" style={{ width: '100%' }}>{selected.fulfillments.map((record) => <Alert key={record.id} type="success" showIcon message={`${businessLabel(fulfillmentTypeLabels, record.method)} · ${businessLabel(fulfillmentStatusLabels, record.status)}`} description={[record.carrier_name, record.tracking_no, record.no_logistics_reason, formatDate(record.fulfilled_at)].filter(Boolean).join(' · ')} />)}</Space> : <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="暂未创建履约记录" />}</div>
        {selected.status_changes && selected.status_changes.length > 0 && <div><Typography.Title level={5}>订单时间线</Typography.Title><Timeline items={selected.status_changes.map((change) => ({ children: <><strong>{businessLabel(orderStatusLabels, change.to_status)}</strong><br /><Typography.Text type="secondary">{change.reason} · {formatDate(change.created_at)}</Typography.Text></> }))} /></div>}
      </Space>}
    </Drawer>

    <Modal title={fulfilling ? `创建履约 · ${fulfilling.order_no}` : '创建履约'} width={680} open={Boolean(fulfilling)} onCancel={() => setFulfilling(null)} onOk={() => void submitFulfillment()} okText="确认并更新订单" confirmLoading={submitting} destroyOnHidden>
      {fulfilling && <>
        <Alert showIcon type="warning" message="履约记录提交后不可删除；部分发货时请准确填写本次数量。" style={{ marginBottom: 16 }} />
        <Form form={form} layout="vertical">
          <Form.Item name="method" label="履约方式" rules={[{ required: true }]}><Select options={Object.entries(fulfillmentTypeLabels).map(([value, label]) => ({ value, label }))} /></Form.Item>
          {fulfillmentMethod === 'logistics' && <Space align="start" style={{ display: 'flex' }}><Form.Item name="carrier_name" label="物流公司" rules={[{ required: true }]} style={{ flex: 1 }}><Input placeholder="例如：顺丰速运" /></Form.Item><Form.Item name="carrier_code" label="物流编码" style={{ flex: 1 }}><Input placeholder="例如：SF" /></Form.Item><Form.Item name="tracking_no" label="物流单号" rules={[{ required: true }]} style={{ flex: 1 }}><Input /></Form.Item></Space>}
          {fulfillmentMethod === 'no_logistics' && <Form.Item name="no_logistics_reason" label="无需物流原因" rules={[{ required: true, min: 4 }]}><Input.TextArea rows={3} placeholder="例如：线下交付、到店自取或服务已现场完成" /></Form.Item>}
          {fulfillmentMethod === 'virtual_delivery' && <Form.Item name="virtual_delivery_content" label="虚拟交付内容" rules={[{ required: true, min: 2 }]}><Input.TextArea rows={4} placeholder="填写卡密、下载方式、课程权限或交付说明。请勿写入管理端密钥。" /></Form.Item>}
        </Form>
        <Divider>本次履约数量</Divider>
        <Space direction="vertical" style={{ width: '100%' }}>{fulfilling.items.filter((item) => item.remaining_fulfillment_quantity > 0).map((item) => <div className="fulfillment-item" key={item.id}><div><strong>{item.product_name}</strong><Typography.Text type="secondary">{item.sku_name} · 剩余可发 {item.remaining_fulfillment_quantity}</Typography.Text></div><InputNumber min={0} max={item.remaining_fulfillment_quantity} value={quantities[item.id] ?? 0} onChange={(value) => setQuantities((current) => ({ ...current, [item.id]: Number(value ?? 0) }))} /></div>)}</Space>
      </>}
    </Modal>
  </>
}
