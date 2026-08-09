import { Button, Card, Space, Switch, Table, Tabs, Tag, Typography, message } from 'antd'
import { useCallback, useEffect, useState } from 'react'
import { listManagedAfterSaleReasons, listManagedExchanges, listManagedRefunds, receiveManagedReturn, reviewManagedExchange, reviewManagedRefund, updateManagedAfterSaleReason, type ManagedAfterSaleReason, type ManagedExchange, type ManagedRefund } from '../../api/admin'

const statusColor: Record<string, string> = { submitted: 'gold', awaiting_return: 'blue', return_in_transit: 'cyan', processing: 'purple', succeeded: 'green', rejected: 'red', closed: 'default' }
const statusNames: Record<string, string> = {
  submitted: '待审核', awaiting_return: '待用户退货', return_in_transit: '退货运输中', processing: '处理中',
  succeeded: '售后已完成', rejected: '已拒绝', closed: '已关闭', approved: '已通过', expired: '已超时关闭',
}
const responsibilityNames: Record<string, string> = {
  buyer: '买家责任', seller: '卖家责任', carrier: '物流方责任', platform: '平台责任',
  pending: '待审核判定', negotiated: '客服协商确定', none: '无责任方',
}
const shippingPayerNames: Record<string, string> = {
  buyer: '买家承担', seller: '卖家承担', carrier: '物流方承担', platform: '平台承担',
  pending: '待审核判定', negotiated: '客服协商确定', none: '无需承担',
}
const returnMethodNames: Record<string, string> = {
  logistics: '物流退回', no_logistics: '无需物流', pickup: '上门取件',
}

export function AfterSaleManagementPage() {
  const [refunds, setRefunds] = useState<ManagedRefund[]>([])
  const [exchanges, setExchanges] = useState<ManagedExchange[]>([])
  const [reasons, setReasons] = useState<ManagedAfterSaleReason[]>([])
  const [busy, setBusy] = useState('')
  const [messageApi, contextHolder] = message.useMessage()
  const load = useCallback(async () => { try { const [r, e, c] = await Promise.all([listManagedRefunds(), listManagedExchanges(), listManagedAfterSaleReasons()]); setRefunds(r.items); setExchanges(e.items); setReasons(c.items) } catch (error) { messageApi.error(error instanceof Error ? error.message : '售后队列加载失败') } }, [messageApi])
  useEffect(() => { void load() }, [load])
  const action = async (id: string, run: () => Promise<unknown>) => { setBusy(id); try { await run(); messageApi.success('处理成功'); await load() } catch (error) { messageApi.error(error instanceof Error ? error.message : '处理失败') } finally { setBusy('') } }
  return <>
    {contextHolder}
    <div className="page-heading"><div><Typography.Text className="eyebrow">AFTER SALES</Typography.Text><Typography.Title level={2}>订单与售后</Typography.Title><Typography.Paragraph type="secondary">退款、退货、同 SKU 换货、责任归属和运费补偿统一处理。</Typography.Paragraph></div><Tag color="blue">第 24 批</Tag></div>
    <Card bordered={false}>
      <Tabs items={[
        { key: 'refunds', label: `退款 / 退货 (${refunds.length})`, children: <Table rowKey="id" dataSource={refunds} pagination={false} columns={[
          { title: '退款单', dataIndex: 'refund_no' }, { title: '原因', dataIndex: 'reason_name' }, { title: '责任归属', dataIndex: 'responsibility_party', render: (value: string) => responsibilityNames[value] ?? value }, { title: '退款', render: (_, r) => `¥${(r.refund_cash_amount / 100).toFixed(2)} + ${r.refund_points}积分` }, { title: '状态', render: (_, r) => <Tag color={statusColor[r.status]}>{statusNames[r.status] ?? r.status}</Tag> },
          { title: '操作', render: (_, r) => <Space>{r.status === 'submitted' && <><Button size="small" type="primary" loading={busy === r.id} onClick={() => void action(r.id, () => reviewManagedRefund(r.id, 'approve', '客服核验通过。'))}>通过</Button><Button size="small" danger onClick={() => void action(r.id, () => reviewManagedRefund(r.id, 'reject', '不符合售后条件。'))}>拒绝</Button></>}{r.status === 'return_in_transit' && <Button size="small" type="primary" onClick={() => void action(r.id, () => receiveManagedReturn(r.id, '仓库验收通过。'))}>确认收货退款</Button>}</Space> },
        ]} /> },
        { key: 'exchanges', label: `换货 (${exchanges.length})`, children: <Table rowKey="id" dataSource={exchanges} pagination={false} columns={[
          { title: '换货单', dataIndex: 'exchange_no' }, { title: '原因', dataIndex: 'reason_name' }, { title: '数量', dataIndex: 'quantity' }, { title: '寄回方式', dataIndex: 'return_method', render: (value: string) => returnMethodNames[value] ?? value }, { title: '状态', render: (_, r) => <Tag color={statusColor[r.status]}>{statusNames[r.status] ?? r.status}</Tag> }, { title: '操作', render: (_, r) => r.status === 'submitted' ? <Space><Button size="small" type="primary" onClick={() => void action(r.id, () => reviewManagedExchange(r.id, 'approve', '同意同 SKU 换货。'))}>通过</Button><Button size="small" danger onClick={() => void action(r.id, () => reviewManagedExchange(r.id, 'reject', '不符合换货条件。'))}>拒绝</Button></Space> : null },
        ]} /> },
        { key: 'reasons', label: `原因配置 (${reasons.length})`, children: <Table rowKey="id" dataSource={reasons} pagination={false} columns={[
          { title: '规则标识', dataIndex: 'code', render: (value: string) => <Typography.Text code>{value}</Typography.Text> }, { title: '用户看到的原因', dataIndex: 'name' }, { title: '默认责任归属', dataIndex: 'default_responsibility', render: (value: string) => responsibilityNames[value] ?? value }, { title: '退货运费承担方', dataIndex: 'return_shipping_payer', render: (value: string) => shippingPayerNames[value] ?? value }, { title: '凭证要求', render: (_, r) => r.evidence_required ? '必须提交' : '可选提交' }, { title: '是否启用', render: (_, r) => <Switch checked={r.enabled} checkedChildren="启用" unCheckedChildren="停用" onChange={(enabled) => void action(r.id, () => updateManagedAfterSaleReason({ ...r, enabled }))} /> },
        ]} /> },
      ]} />
    </Card>
  </>
}
