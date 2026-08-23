import { Alert, Button, Card, Form, Input, InputNumber, Modal, Select, Space, Switch, Table, Tabs, Tag, Typography, message } from 'antd'
import { useCallback, useEffect, useState } from 'react'
import {
  downloadReturnShippingProof,
  fulfillManagedExchange,
  listManagedAfterSaleReasons,
  listManagedExchanges,
  listManagedRefunds,
  listReturnShippingCompensations,
  receiveManagedExchangeReturn,
  receiveManagedReturn,
  reviewManagedExchange,
  reviewManagedRefund,
  reviewReturnShippingCompensation,
  updateManagedAfterSaleReason,
  type ManagedAfterSaleReason,
  type ManagedExchange,
  type ManagedRefund,
  type ReturnShippingCompensation,
} from '../../api/admin'
import { BusinessStatusTag } from '../../components/BusinessStatusTag'
import { afterSaleStatusLabels, businessLabel, responsibilityLabels, shippingPayerLabels } from '../../constants/businessLabels'
import { useAdminAuth } from '../auth/AdminAuthContext'
import { OrderManagementPanel } from '../orders/OrderManagementPanel'

const returnMethodNames: Record<string, string> = { logistics: '物流寄回', no_logistics: '无需物流', pickup: '上门取件' }
const responsibilityOptions = [
  { label: '买家责任', value: 'buyer' }, { label: '卖家责任', value: 'seller' },
  { label: '物流方责任', value: 'carrier' }, { label: '协商确定', value: 'negotiated' },
]
const payerOptions = [
  { label: '买家承担', value: 'buyer' }, { label: '卖家承担', value: 'seller' },
  { label: '物流方承担', value: 'carrier' }, { label: '协商确定', value: 'negotiated' },
]
const returnMethodOptions = [{ label: '物流寄回', value: 'logistics' }, { label: '无需物流', value: 'no_logistics' }]
const compensationStatusLabels: Record<string, string> = { submitted: '待审核', paid: '已发放至余额', rejected: '已拒绝' }

type DialogState =
  | { kind: 'refund-review'; row: ManagedRefund; decision: 'approve' | 'reject' }
  | { kind: 'refund-receive'; row: ManagedRefund }
  | { kind: 'exchange-review'; row: ManagedExchange; decision: 'approve' | 'reject' }
  | { kind: 'exchange-receive'; row: ManagedExchange }
  | { kind: 'exchange-fulfill'; row: ManagedExchange }
  | { kind: 'compensation-review'; row: ReturnShippingCompensation; decision: 'approve' | 'reject' }

interface DialogValues {
  responsibility_party?: string
  return_shipping_payer?: string
  replacement_shipping_payer?: string
  return_method?: string
  fulfillment_type?: 'logistics' | 'no_logistics'
  carrier_code?: string
  tracking_no?: string
  no_logistics_note?: string
  approved_amount_yuan?: number
  note?: string
}

export function AfterSaleManagementPage() {
  const { admin } = useAdminAuth()
  const canReview = admin?.permissions.includes('after_sale.review') ?? false
  const canFulfill = admin?.permissions.includes('fulfillment.manage') ?? false
  const [refunds, setRefunds] = useState<ManagedRefund[]>([])
  const [exchanges, setExchanges] = useState<ManagedExchange[]>([])
  const [compensations, setCompensations] = useState<ReturnShippingCompensation[]>([])
  const [reasons, setReasons] = useState<ManagedAfterSaleReason[]>([])
  const [busy, setBusy] = useState('')
  const [dialog, setDialog] = useState<DialogState | null>(null)
  const [form] = Form.useForm<DialogValues>()
  const fulfillmentType = Form.useWatch('fulfillment_type', form)
  const [messageApi, contextHolder] = message.useMessage()

  const load = useCallback(async () => {
    try {
      const [refundResult, exchangeResult, reasonResult, compensationResult] = await Promise.all([
        canReview ? listManagedRefunds() : Promise.resolve({ items: [] }),
        canReview || canFulfill ? listManagedExchanges() : Promise.resolve({ items: [] }),
        canReview ? listManagedAfterSaleReasons() : Promise.resolve({ items: [] }),
        canReview ? listReturnShippingCompensations() : Promise.resolve({ items: [] }),
      ])
      setRefunds(refundResult.items); setExchanges(exchangeResult.items); setReasons(reasonResult.items); setCompensations(compensationResult.items)
    } catch (error) { messageApi.error(error instanceof Error ? error.message : '售后队列加载失败') }
  }, [canFulfill, canReview, messageApi])
  useEffect(() => { void load() }, [load])

  const action = async (id: string, run: () => Promise<unknown>): Promise<boolean> => {
    setBusy(id)
    try { await run(); messageApi.success('处理成功'); await load(); return true }
    catch (error) { messageApi.error(error instanceof Error ? error.message : '处理失败'); return false }
    finally { setBusy('') }
  }

  const openDialog = (next: DialogState, values: DialogValues) => {
    form.resetFields()
    form.setFieldsValue(values)
    setDialog(next)
  }

  const closeDialog = () => { if (!busy) { setDialog(null); form.resetFields() } }

  const submitDialog = async () => {
    if (!dialog) return
    const values = await form.validateFields()
    let succeeded = false
    if (dialog.kind === 'refund-review') {
      succeeded = await action(dialog.row.id, () => reviewManagedRefund(dialog.row.id, dialog.decision === 'approve' ? {
        decision: 'approve', responsibility_party: values.responsibility_party, return_shipping_payer: values.return_shipping_payer,
        return_method: values.return_method, note: values.note!,
      } : { decision: 'reject', note: values.note! }))
    } else if (dialog.kind === 'refund-receive') {
      succeeded = await action(dialog.row.id, () => receiveManagedReturn(dialog.row.id, values.note!))
    } else if (dialog.kind === 'exchange-review') {
      succeeded = await action(dialog.row.id, () => reviewManagedExchange(dialog.row.id, dialog.decision === 'approve' ? {
        decision: 'approve', responsibility_party: values.responsibility_party, return_shipping_payer: values.return_shipping_payer,
        replacement_shipping_payer: values.replacement_shipping_payer, return_method: values.return_method, reason: values.note!,
      } : { decision: 'reject', reason: values.note! }))
    } else if (dialog.kind === 'exchange-receive') {
      succeeded = await action(dialog.row.id, () => receiveManagedExchangeReturn(dialog.row.id, values.note!))
    } else if (dialog.kind === 'exchange-fulfill') {
      const fulfillment = values.fulfillment_type === 'no_logistics'
        ? { fulfillment_type: 'no_logistics' as const, no_logistics_note: values.no_logistics_note }
        : { fulfillment_type: 'logistics' as const, carrier_code: values.carrier_code, tracking_no: values.tracking_no }
      succeeded = await action(dialog.row.id, () => fulfillManagedExchange(dialog.row.id, fulfillment))
    } else {
      succeeded = await action(dialog.row.id, () => reviewReturnShippingCompensation(dialog.row.id, dialog.decision === 'approve' ? {
        decision: 'approve', approved_amount: Math.round((values.approved_amount_yuan ?? 0) * 100), note: values.note!,
      } : { decision: 'reject', note: values.note! }))
    }
    if (succeeded) closeDialog()
  }

  const previewProof = async (row: ReturnShippingCompensation) => {
    try {
      const blob = await downloadReturnShippingProof(row.id)
      const url = URL.createObjectURL(blob)
      window.open(url, '_blank', 'noopener,noreferrer')
      window.setTimeout(() => URL.revokeObjectURL(url), 60_000)
    } catch (error) { messageApi.error(error instanceof Error ? error.message : '付款凭证预览失败') }
  }

  const dialogTitle = dialog?.kind === 'refund-review' ? (dialog.decision === 'approve' ? '退款 / 退货个案裁决' : '拒绝退款 / 退货申请')
    : dialog?.kind === 'refund-receive' ? '确认收到退货并退款'
      : dialog?.kind === 'exchange-review' ? (dialog.decision === 'approve' ? '换货个案裁决' : '拒绝换货申请')
        : dialog?.kind === 'exchange-receive' ? '确认收到换货寄回商品'
          : dialog?.kind === 'exchange-fulfill' ? '执行替换商品履约'
            : dialog?.kind === 'compensation-review' ? (dialog.decision === 'approve' ? '审核并发放运费补偿' : '拒绝运费补偿') : ''
  const dialogSubject = dialog && 'row' in dialog
    ? ('refund_no' in dialog.row ? dialog.row.refund_no : 'exchange_no' in dialog.row ? dialog.row.exchange_no : `${dialog.row.carrier_code} / ${dialog.row.tracking_no}`)
    : ''
  const approvingReview = (dialog?.kind === 'refund-review' || dialog?.kind === 'exchange-review') && dialog.decision === 'approve'

  return <>
    {contextHolder}
    <div className="page-heading"><div><Typography.Text className="eyebrow">校园商城售后</Typography.Text><Typography.Title level={2}>订单与售后</Typography.Title><Typography.Paragraph type="secondary">退款、退货、同 SKU 换货、责任裁决、替换履约与运费补偿统一处理。</Typography.Paragraph></div><Tag color="blue">售后闭环</Tag></div>
    <Card bordered={false} className="orders-after-sale-card">
      <Tabs items={[
        { key: 'orders', label: '订单与履约', children: <OrderManagementPanel /> },
        ...(canReview ? [{ key: 'refunds', label: `退款 / 退货 (${refunds.length})`, children: <Table rowKey="id" dataSource={refunds} pagination={{ pageSize: 10 }} scroll={{ x: 1160 }} columns={[
          { title: '退款单', dataIndex: 'refund_no' }, { title: '原因', dataIndex: 'reason_name' },
          { title: '个案裁决', render: (_, row) => <Space direction="vertical" size={0}><span>{businessLabel(responsibilityLabels, row.responsibility_party)}</span><Typography.Text type="secondary">运费：{businessLabel(shippingPayerLabels, row.return_shipping_payer)}</Typography.Text></Space> },
          { title: '退款', render: (_, row) => `¥${(row.refund_cash_amount / 100).toFixed(2)} + ${row.refund_points} 积分` },
          { title: '退货物流', render: (_, row) => row.return_shipment?.tracking_no ? <Space direction="vertical" size={0}><span>{row.return_shipment.carrier_code} / {row.return_shipment.tracking_no}</span><Typography.Text type="secondary">实付运费 ¥{((row.return_shipment.paid_shipping_amount ?? 0) / 100).toFixed(2)}</Typography.Text></Space> : '—' },
          { title: '状态', render: (_, row) => <BusinessStatusTag status={row.status} label={businessLabel(afterSaleStatusLabels, row.status)} /> },
          { title: '操作', render: (_, row) => <Space wrap>{row.status === 'submitted' && <><Button size="small" type="primary" onClick={() => openDialog({ kind: 'refund-review', row, decision: 'approve' }, { responsibility_party: row.responsibility_party === 'pending' ? 'seller' : row.responsibility_party, return_shipping_payer: row.return_shipping_payer === 'pending' ? 'seller' : row.return_shipping_payer, return_method: row.return_method || 'logistics', note: '客服核验通过。' })}>裁决通过</Button><Button size="small" danger onClick={() => openDialog({ kind: 'refund-review', row, decision: 'reject' }, { note: '不符合售后条件。' })}>拒绝</Button></>}{row.status === 'return_in_transit' && <Button size="small" type="primary" onClick={() => openDialog({ kind: 'refund-receive', row }, { note: '仓库验收通过。' })}>确认收货并退款</Button>}</Space> },
        ]} /> }] : []),
        ...((canReview || canFulfill) ? [{ key: 'exchanges', label: `换货 (${exchanges.length})`, children: <Table rowKey="id" dataSource={exchanges} pagination={{ pageSize: 10 }} scroll={{ x: 1250 }} columns={[
          { title: '换货单', dataIndex: 'exchange_no' }, { title: '原因', dataIndex: 'reason_name' }, { title: '数量', dataIndex: 'quantity' },
          { title: '寄回方式', dataIndex: 'return_method', render: value => returnMethodNames[value] ?? '未确定' },
          { title: '责任 / 运费', render: (_, row) => <Space direction="vertical" size={0}><span>{businessLabel(responsibilityLabels, row.responsibility_party)}</span><Typography.Text type="secondary">寄回：{businessLabel(shippingPayerLabels, row.return_shipping_payer)} · 补发：{businessLabel(shippingPayerLabels, row.replacement_shipping_payer)}</Typography.Text></Space> },
          { title: '物流进度', render: (_, row) => row.shipments?.length ? <Space direction="vertical" size={0}>{row.shipments.map((item, index) => <Typography.Text key={item.id ?? index} type="secondary">{item.direction === 'return' ? '寄回' : '补发'}：{item.method === 'logistics' ? `${item.carrier_code || '承运商'} / ${item.tracking_no || '待填'}` : item.no_logistics_note || '无需物流'}</Typography.Text>)}</Space> : '—' },
          { title: '状态', render: (_, row) => <BusinessStatusTag status={row.status} label={businessLabel(afterSaleStatusLabels, row.status)} /> },
          { title: '操作', render: (_, row) => <Space wrap>{canReview && row.status === 'submitted' && <><Button size="small" type="primary" onClick={() => openDialog({ kind: 'exchange-review', row, decision: 'approve' }, { responsibility_party: row.responsibility_party === 'pending' ? 'seller' : row.responsibility_party, return_shipping_payer: row.return_shipping_payer === 'pending' ? 'seller' : row.return_shipping_payer, replacement_shipping_payer: row.replacement_shipping_payer === 'pending' ? 'seller' : row.replacement_shipping_payer, return_method: row.return_method || 'logistics', note: '同意同 SKU 换货。' })}>个案裁决</Button><Button size="small" danger onClick={() => openDialog({ kind: 'exchange-review', row, decision: 'reject' }, { note: '不符合换货条件。' })}>拒绝</Button></>}{canFulfill && row.status === 'return_in_transit' && <Button size="small" onClick={() => openDialog({ kind: 'exchange-receive', row }, { note: '仓库已验收寄回商品。' })}>确认收到退货</Button>}{canFulfill && row.status === 'replacement_pending' && <Button size="small" type="primary" onClick={() => openDialog({ kind: 'exchange-fulfill', row }, { fulfillment_type: 'logistics', carrier_code: 'SF' })}>执行替换履约</Button>}</Space> },
        ]} /> }] : []),
        ...(canReview ? [{ key: 'compensations', label: `运费补偿 (${compensations.filter(item => item.status === 'submitted').length})`, children: <Table rowKey="id" dataSource={compensations} pagination={{ pageSize: 10 }} scroll={{ x: 920 }} columns={[
          { title: '来源', dataIndex: 'source_type', render: value => value === 'refund_return' ? '退货退款' : '换货寄回' },
          { title: '物流', render: (_, row) => `${row.carrier_code} / ${row.tracking_no}` },
          { title: '申请金额', render: (_, row) => `¥${(row.requested_amount / 100).toFixed(2)}` },
          { title: '状态', render: (_, row) => <BusinessStatusTag status={row.status} label={businessLabel(compensationStatusLabels, row.status)} /> },
          { title: '操作', render: (_, row) => <Space wrap><Button size="small" onClick={() => void previewProof(row)}>预览付款凭证</Button>{row.status === 'submitted' && <><Button size="small" type="primary" onClick={() => openDialog({ kind: 'compensation-review', row, decision: 'approve' }, { approved_amount_yuan: row.requested_amount / 100, note: '物流单号与付款凭证核验通过。' })}>审核并发放余额</Button><Button size="small" danger onClick={() => openDialog({ kind: 'compensation-review', row, decision: 'reject' }, { note: '凭证未通过核验。' })}>拒绝</Button></>}</Space> },
        ]} /> },
        { key: 'reasons', label: `原因配置 (${reasons.length})`, children: <Table rowKey="id" dataSource={reasons} pagination={false} columns={[
          { title: '规则标识', dataIndex: 'code', render: value => <Typography.Text code>{value}</Typography.Text> }, { title: '用户文案', dataIndex: 'name' },
          { title: '默认责任方', dataIndex: 'default_responsibility', render: value => businessLabel(responsibilityLabels, value) }, { title: '默认运费承担方', dataIndex: 'return_shipping_payer', render: value => businessLabel(shippingPayerLabels, value) },
          { title: '凭证', render: (_, row) => row.evidence_required ? '必填' : '选填' }, { title: '启用', render: (_, row) => <Switch checked={row.enabled} checkedChildren="启用" unCheckedChildren="停用" onChange={enabled => void action(row.id, () => updateManagedAfterSaleReason({ ...row, enabled }))} /> },
        ]} /> },] : []),
      ]} />
    </Card>

    <Modal open={!!dialog} forceRender title={dialogTitle} okText={dialog?.kind === 'compensation-review' && dialog.decision === 'approve' ? '确认发放至余额' : '确认提交'} cancelText="取消" confirmLoading={!!busy} onCancel={closeDialog} onOk={() => void submitDialog()} width={580}>
      {dialog && <>
        <Alert type="info" showIcon message={`当前处理：${dialogSubject}`} description="提交后将记录本次处理结果；涉及资产发放的操作具备幂等保护。" style={{ marginBottom: 20 }} />
        <Form form={form} layout="vertical" preserve={false}>
          {approvingReview && <>
            <Form.Item name="responsibility_party" label="责任方" rules={[{ required: true, message: '请选择责任方' }]}><Select options={responsibilityOptions} /></Form.Item>
            <Form.Item name="return_shipping_payer" label="寄回运费承担方" rules={[{ required: true, message: '请选择寄回运费承担方' }]}><Select options={payerOptions} /></Form.Item>
            {dialog.kind === 'exchange-review' && <Form.Item name="replacement_shipping_payer" label="替换商品发货运费承担方" rules={[{ required: true, message: '请选择补发运费承担方' }]}><Select options={payerOptions} /></Form.Item>}
            <Form.Item name="return_method" label="退货 / 寄回方式" rules={[{ required: true, message: '请选择寄回方式' }]}><Select options={returnMethodOptions} /></Form.Item>
          </>}
          {dialog.kind === 'exchange-fulfill' && <>
            <Form.Item name="fulfillment_type" label="替换商品交付方式" rules={[{ required: true, message: '请选择交付方式' }]}><Select options={returnMethodOptions.map(item => ({ ...item, label: item.value === 'logistics' ? '物流配送' : '无需物流交付' }))} /></Form.Item>
            {fulfillmentType === 'logistics' ? <>
              <Form.Item name="carrier_code" label="承运商编码" rules={[{ required: true, message: '请输入承运商编码' }]}><Input placeholder="例如：SF" maxLength={64} /></Form.Item>
              <Form.Item name="tracking_no" label="物流单号" rules={[{ required: true, message: '请输入物流单号' }]}><Input placeholder="请输入可查询的补发物流单号" maxLength={100} /></Form.Item>
            </> : <Form.Item name="no_logistics_note" label="无需物流交付说明" rules={[{ required: true, message: '请输入交付说明' }]}><Input.TextArea rows={3} maxLength={500} showCount placeholder="例如：替换权益已发放至用户账户" /></Form.Item>}
          </>}
          {dialog.kind === 'compensation-review' && dialog.decision === 'approve' && <Form.Item name="approved_amount_yuan" label="批准金额（元）" rules={[{ required: true, message: '请输入批准金额' }]}><InputNumber min={0.01} max={dialog.row.requested_amount / 100} precision={2} step={0.01} style={{ width: '100%' }} addonBefore="¥" /></Form.Item>}
          {dialog.kind !== 'exchange-fulfill' && <Form.Item name="note" label={dialog.kind.endsWith('receive') ? '验收说明' : dialog.kind === 'compensation-review' ? '审核说明' : '处理说明'} rules={[{ required: true, whitespace: true, message: '请输入处理说明' }]}><Input.TextArea rows={4} maxLength={500} showCount placeholder="请输入本次判断依据与处理说明" /></Form.Item>}
        </Form>
      </>}
    </Modal>
  </>
}
