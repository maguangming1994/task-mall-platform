import {
  DeleteOutlined,
  EditOutlined,
  EyeOutlined,
  PlusOutlined,
  PrinterOutlined,
  ReloadOutlined,
} from '@ant-design/icons'
import {
  Alert,
  Button,
  DatePicker,
  Descriptions,
  Drawer,
  Form,
  Input,
  Modal,
  Popconfirm,
  Select,
  Space,
  Table,
  Tabs,
  Tag,
  Typography,
  message,
} from 'antd'
import type { ColumnsType } from 'antd/es/table'
import type { TextAreaRef } from 'antd/es/input/TextArea'
import type { MessageInstance } from 'antd/es/message/interface'
import { useCallback, useEffect, useRef, useState } from 'react'
import {
  ApiError,
  cancelExpressPickupOrder,
  confirmExpressPickupSettlement,
  createCampusPrinter,
  createExpressPickupSettlement,
  deleteCampusPrinter,
  EXPRESS_PICKUP_STATUS_OPTIONS,
  getExpressPickupOrder,
  listCampusPrinters,
  listExpressPickupOrders,
  listExpressPickupRunners,
  listExpressPickupSettlements,
  payExpressPickupSettlement,
  previewCampusPrinter,
  reprintExpressPickupOrder,
  reviewExpressPickupRunner,
  testCampusPrinter,
  updateCampusPrinter,
  type CampusPrinter,
  type CampusPrinterInput,
  type CampusPrinterList,
  type ExpressPickupOrderAdmin,
  type ExpressPickupRunnerAdmin,
  type ExpressPickupSettlementAdmin,
} from '../../api/admin'
import { PageHead, Pill } from '../../components/xq'
import { useAdminAuth } from '../auth/AdminAuthContext'

const money = (cents: number) => `¥${(cents / 100).toFixed(2)}`
const timeText = (value: string | null) => (value ? new Date(value).toLocaleString('zh-CN', { hour12: false }) : '—')
const statusMeta = (status: string) => EXPRESS_PICKUP_STATUS_OPTIONS.find((item) => item.value === status) ?? { label: status, color: 'default' }

function StatusTag({ status }: { status: string }) {
  const meta = statusMeta(status)
  return <Tag color={meta.color}>{meta.label}</Tag>
}

function failText(error: unknown, fallback: string): string {
  return error instanceof ApiError ? error.message : fallback
}

export function ExpressPickupPage() {
  const { admin } = useAdminAuth()
  const [messageApi, contextHolder] = message.useMessage()
  const canManage = admin?.permissions.includes('express_pickup.manage') ?? false
  const canSettle = admin?.permissions.includes('express_pickup.settle') ?? false
  const canPrinter = admin?.permissions.includes('express_pickup.printer_manage') ?? false

  return (
    <div>
      {contextHolder}
      <PageHead
        title="快递代取"
        extra={<Pill tone="blue">跑腿接单 · 结算 · 校区小票机</Pill>}
      />
      <Tabs
        defaultActiveKey="orders"
        items={[
          { key: 'orders', label: '代取订单', children: <OrdersTab messageApi={messageApi} canManage={canManage} /> },
          { key: 'runners', label: '跑腿员', children: <RunnersTab messageApi={messageApi} canManage={canManage} /> },
          { key: 'settlements', label: '跑腿结算', children: <SettlementsTab messageApi={messageApi} canSettle={canSettle} /> },
          { key: 'printers', label: '小票机', children: <PrintersTab messageApi={messageApi} canPrinter={canPrinter} /> },
        ]}
      />
    </div>
  )
}

function OrdersTab({ messageApi, canManage }: { messageApi: MessageInstance; canManage: boolean }) {
  const [items, setItems] = useState<ExpressPickupOrderAdmin[]>([])
  const [status, setStatus] = useState<string | undefined>(undefined)
  const [keyword, setKeyword] = useState('')
  const [loading, setLoading] = useState(false)
  const [detail, setDetail] = useState<ExpressPickupOrderAdmin | null>(null)
  const [detailLoading, setDetailLoading] = useState(false)
  const [cancelTarget, setCancelTarget] = useState<ExpressPickupOrderAdmin | null>(null)
  const [cancelReason, setCancelReason] = useState('')
  const [busyId, setBusyId] = useState<string | null>(null)

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const result = await listExpressPickupOrders({ status, keyword: keyword.trim(), page_size: 50 })
      setItems(result.items)
    } catch (error) {
      messageApi.error(failText(error, '代取订单加载失败。'))
    } finally {
      setLoading(false)
    }
  }, [keyword, messageApi, status])

  useEffect(() => { void load() }, [load])

  const openDetail = async (record: ExpressPickupOrderAdmin) => {
    setDetailLoading(true)
    setDetail(record)
    try {
      setDetail(await getExpressPickupOrder(record.id))
    } catch (error) {
      messageApi.error(failText(error, '订单详情加载失败。'))
    } finally {
      setDetailLoading(false)
    }
  }

  const reprint = async (record: ExpressPickupOrderAdmin) => {
    setBusyId(record.id)
    try {
      await reprintExpressPickupOrder(record.id)
      messageApi.success('小票已发送至打印机。')
      void load()
    } catch (error) {
      messageApi.error(failText(error, '小票补打失败。'))
    } finally {
      setBusyId(null)
    }
  }

  const submitCancel = async () => {
    if (!cancelTarget) return
    if (!cancelReason.trim()) {
      messageApi.warning('请填写取消原因。')
      return
    }
    setBusyId(cancelTarget.id)
    try {
      await cancelExpressPickupOrder(cancelTarget.id, cancelReason.trim())
      messageApi.success('代取单已取消。')
      setCancelTarget(null)
      setCancelReason('')
      void load()
    } catch (error) {
      messageApi.error(failText(error, '取消失败。'))
    } finally {
      setBusyId(null)
    }
  }

  const columns: ColumnsType<ExpressPickupOrderAdmin> = [
    {
      title: '订单号 / 下单时间', width: 220,
      render: (_, record) => <div>
        <Typography.Text strong copyable>{record.order_no}</Typography.Text>
        <Typography.Text type="secondary" style={{ display: 'block', fontSize: 12, marginTop: 4 }}>{timeText(record.created_at)}</Typography.Text>
      </div>,
    },
    { title: '状态', width: 90, render: (_, record) => <StatusTag status={record.status} /> },
    {
      title: '取件 → 送达', width: 240,
      render: (_, record) => <div>
        <Typography.Text>{record.pickup_site}（{record.pickup_code}）</Typography.Text>
        <Typography.Text type="secondary" style={{ display: 'block', fontSize: 12, marginTop: 4 }}>{record.deliver_building} {record.deliver_address}</Typography.Text>
      </div>,
    },
    {
      title: '联系人', width: 150,
      render: (_, record) => <div>
        <Typography.Text>{record.contact_name}</Typography.Text>
        <Typography.Text type="secondary" style={{ display: 'block', fontSize: 12, marginTop: 4 }}>{record.contact_phone}</Typography.Text>
      </div>,
    },
    { title: '跑腿费', width: 100, render: (_, record) => money(record.total_cents) },
    { title: '跑腿', width: 120, render: (_, record) => record.runner_name ?? '—' },
    {
      title: '小票', width: 100,
      render: (_, record) => record.print_status === 'printed'
        ? <Tag color="success">已打印</Tag>
        : record.print_status === 'failed' ? <Tag color="error">打印失败</Tag> : <Tag>未打印</Tag>,
    },
    {
      title: '操作', width: 210, fixed: 'right',
      render: (_, record) => <Space size={0} wrap>
        <Button type="link" size="small" icon={<EyeOutlined />} onClick={() => void openDetail(record)}>详情</Button>
        <Button type="link" size="small" icon={<PrinterOutlined />} disabled={!canManage || busyId === record.id} loading={busyId === record.id} onClick={() => void reprint(record)}>补打</Button>
        <Button type="link" size="small" danger disabled={!canManage || ['completed', 'cancelled'].includes(record.status)} onClick={() => { setCancelTarget(record); setCancelReason('') }}>取消</Button>
      </Space>,
    },
  ]

  return (
    <>
      <Space wrap style={{ marginBottom: 12 }}>
        <Select
          allowClear
          placeholder="全部状态"
          style={{ width: 140 }}
          value={status}
          onChange={setStatus}
          options={EXPRESS_PICKUP_STATUS_OPTIONS.map((item) => ({ value: item.value, label: item.label }))}
        />
        <Input.Search placeholder="订单号 / 联系人 / 手机号" allowClear style={{ width: 240 }} value={keyword} onChange={(event) => setKeyword(event.target.value)} onSearch={() => void load()} />
        <Button icon={<ReloadOutlined />} onClick={() => void load()}>刷新</Button>
      </Space>
      <Table rowKey="id" size="small" loading={loading} columns={columns} dataSource={items} scroll={{ x: 1240 }} pagination={{ pageSize: 20, showSizeChanger: false }} />

      <Drawer open={detail !== null} width={560} title={detail?.order_no ?? '订单详情'} onClose={() => setDetail(null)} loading={detailLoading}>
        {detail ? <Space direction="vertical" size={16} style={{ width: '100%' }}>
          <Descriptions column={1} size="small" bordered items={[
            { key: 'status', label: '状态', children: <StatusTag status={detail.status} /> },
            { key: 'pickup', label: '取件', children: `${detail.pickup_site} / ${detail.pickup_code}` },
            { key: 'package', label: '包裹', children: `${detail.package_info || '—'} × ${detail.package_count}` },
            { key: 'deliver', label: '送达', children: `${detail.deliver_building} ${detail.deliver_address}` },
            { key: 'contact', label: '联系人', children: `${detail.contact_name} ${detail.contact_phone}` },
            { key: 'reward', label: '跑腿费', children: money(detail.total_cents) },
            { key: 'expected', label: '期望送达', children: timeText(detail.expected_at) },
            { key: 'remark', label: '备注', children: detail.remark || '—' },
            { key: 'runner', label: '跑腿', children: detail.runner_name || '—' },
            { key: 'paid', label: '支付时间', children: timeText(detail.paid_at) },
            { key: 'completed', label: '完成时间', children: timeText(detail.completed_at) },
          ]} />
          <div>
            <Typography.Text strong>流转记录</Typography.Text>
            <Space direction="vertical" size={4} style={{ display: 'flex', marginTop: 8 }}>
              {(detail.events ?? []).map((event, index) => <Typography.Text key={index} type="secondary" style={{ fontSize: 12 }}>
                {timeText(event.created_at)} · {event.from_status ?? '—'} → {event.to_status}（{event.event}）{event.reason ? ` · ${event.reason}` : ''}
              </Typography.Text>)}
              {(detail.events ?? []).length === 0 ? <Typography.Text type="secondary">暂无记录</Typography.Text> : null}
            </Space>
          </div>
          <div>
            <Typography.Text strong>打印任务</Typography.Text>
            <Space direction="vertical" size={4} style={{ display: 'flex', marginTop: 8 }}>
              {(detail.print_jobs ?? []).map((job) => <Typography.Text key={job.id} type="secondary" style={{ fontSize: 12 }}>
                {timeText(job.printed_at)} · {job.status}{job.error_message ? ` · ${job.error_message}` : ''}
              </Typography.Text>)}
              {(detail.print_jobs ?? []).length === 0 ? <Typography.Text type="secondary">暂无打印任务</Typography.Text> : null}
            </Space>
          </div>
        </Space> : null}
      </Drawer>

      <Modal open={cancelTarget !== null} title={`取消订单 ${cancelTarget?.order_no ?? ''}`} okText="确认取消" okButtonProps={{ danger: true, loading: busyId === cancelTarget?.id }} onOk={() => void submitCancel()} onCancel={() => setCancelTarget(null)}>
        <Alert type="warning" showIcon message="已支付的订单取消后会自动退回用户钱包余额。" style={{ marginBottom: 12 }} />
        <Input.TextArea rows={3} maxLength={200} placeholder="请填写取消原因（必填）" value={cancelReason} onChange={(event) => setCancelReason(event.target.value)} />
      </Modal>
    </>
  )
}

function RunnersTab({ messageApi, canManage }: { messageApi: MessageInstance; canManage: boolean }) {
  const [items, setItems] = useState<ExpressPickupRunnerAdmin[]>([])
  const [status, setStatus] = useState<string | undefined>('pending')
  const [loading, setLoading] = useState(false)
  const [reviewTarget, setReviewTarget] = useState<ExpressPickupRunnerAdmin | null>(null)
  const [reviewStatus, setReviewStatus] = useState<'active' | 'rejected' | 'suspended'>('active')
  const [reviewReason, setReviewReason] = useState('')
  const [busy, setBusy] = useState(false)

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const result = await listExpressPickupRunners({ status, page_size: 50 })
      setItems(result.items)
    } catch (error) {
      messageApi.error(failText(error, '跑腿员列表加载失败。'))
    } finally {
      setLoading(false)
    }
  }, [messageApi, status])

  useEffect(() => { void load() }, [load])

  const submitReview = async () => {
    if (!reviewTarget) return
    if (reviewStatus !== 'active' && !reviewReason.trim()) {
      messageApi.warning('请填写原因。')
      return
    }
    setBusy(true)
    try {
      await reviewExpressPickupRunner(reviewTarget.id, {
        status: reviewStatus,
        ...(reviewStatus === 'active' ? {} : { reason: reviewReason.trim() }),
      })
      messageApi.success('跑腿资格状态已更新。')
      setReviewTarget(null)
      setReviewReason('')
      void load()
    } catch (error) {
      messageApi.error(failText(error, '审核失败。'))
    } finally {
      setBusy(false)
    }
  }

  const columns: ColumnsType<ExpressPickupRunnerAdmin> = [
    { title: '申请人', width: 160, render: (_, record) => record.user?.display_name ?? record.real_name },
    { title: '真实姓名', width: 120, render: (_, record) => record.real_name },
    { title: '手机号', width: 140, render: (_, record) => record.phone },
    { title: '状态', width: 100, render: (_, record) => <Tag color={record.status === 'active' ? 'success' : record.status === 'pending' ? 'processing' : 'default'}>{record.status === 'active' ? '已通过' : record.status === 'pending' ? '待审核' : record.status === 'rejected' ? '已驳回' : '已挂起'}</Tag> },
    { title: '申请时间', width: 180, render: (_, record) => timeText(record.applied_at) },
    { title: '说明', width: 200, render: (_, record) => record.reject_reason || '—' },
    {
      title: '操作', width: 220, fixed: 'right',
      render: (_, record) => <Space size={0} wrap>
        <Button type="link" size="small" disabled={!canManage} onClick={() => { setReviewTarget(record); setReviewStatus('active'); setReviewReason('') }}>通过</Button>
        <Button type="link" size="small" danger disabled={!canManage} onClick={() => { setReviewTarget(record); setReviewStatus('rejected'); setReviewReason('') }}>驳回</Button>
        <Button type="link" size="small" disabled={!canManage} onClick={() => { setReviewTarget(record); setReviewStatus('suspended'); setReviewReason('') }}>挂起</Button>
      </Space>,
    },
  ]

  return (
    <>
      <Space wrap style={{ marginBottom: 12 }}>
        <Select
          allowClear
          placeholder="全部状态"
          style={{ width: 140 }}
          value={status}
          onChange={setStatus}
          options={[
            { value: 'pending', label: '待审核' },
            { value: 'active', label: '已通过' },
            { value: 'rejected', label: '已驳回' },
            { value: 'suspended', label: '已挂起' },
          ]}
        />
        <Button icon={<ReloadOutlined />} onClick={() => void load()}>刷新</Button>
      </Space>
      <Table rowKey="id" size="small" loading={loading} columns={columns} dataSource={items} scroll={{ x: 1120 }} pagination={{ pageSize: 20, showSizeChanger: false }} />

      <Modal open={reviewTarget !== null} title={`处理跑腿申请 ${reviewTarget?.real_name ?? ''}`} okText="提交" confirmLoading={busy} onOk={() => void submitReview()} onCancel={() => setReviewTarget(null)}>
        <Space direction="vertical" size={12} style={{ width: '100%' }}>
          <Select
            style={{ width: '100%' }}
            value={reviewStatus}
            onChange={setReviewStatus}
            options={[
              { value: 'active', label: '通过并启用' },
              { value: 'rejected', label: '驳回' },
              { value: 'suspended', label: '挂起' },
            ]}
          />
          {reviewStatus !== 'active' ? <Input.TextArea rows={3} maxLength={200} placeholder="请填写原因（必填）" value={reviewReason} onChange={(event) => setReviewReason(event.target.value)} /> : null}
        </Space>
      </Modal>
    </>
  )
}

function SettlementsTab({ messageApi, canSettle }: { messageApi: MessageInstance; canSettle: boolean }) {
  const [items, setItems] = useState<ExpressPickupSettlementAdmin[]>([])
  const [loading, setLoading] = useState(false)
  const [rangeStart, setRangeStart] = useState<string | null>(null)
  const [rangeEnd, setRangeEnd] = useState<string | null>(null)
  const [rangeKey, setRangeKey] = useState(0)
  const [creating, setCreating] = useState(false)
  const [busyId, setBusyId] = useState<string | null>(null)

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const result = await listExpressPickupSettlements()
      setItems(result.items)
    } catch (error) {
      messageApi.error(failText(error, '结算批次加载失败。'))
    } finally {
      setLoading(false)
    }
  }, [messageApi])

  useEffect(() => { void load() }, [load])

  const create = async () => {
    if (!rangeStart || !rangeEnd) {
      messageApi.warning('请选择结算周期。')
      return
    }
    setCreating(true)
    try {
      await createExpressPickupSettlement({ period_start: rangeStart, period_end: rangeEnd })
      messageApi.success('结算批次已生成。')
      setRangeStart(null)
      setRangeEnd(null)
      setRangeKey((current) => current + 1)
      void load()
    } catch (error) {
      messageApi.error(failText(error, '生成结算批次失败。'))
    } finally {
      setCreating(false)
    }
  }

  const act = async (record: ExpressPickupSettlementAdmin, action: 'confirm' | 'pay') => {
    setBusyId(record.id)
    try {
      if (action === 'confirm') await confirmExpressPickupSettlement(record.id)
      else await payExpressPickupSettlement(record.id)
      messageApi.success(action === 'confirm' ? '批次已确认。' : '已打款到跑腿钱包。')
      void load()
    } catch (error) {
      messageApi.error(failText(error, action === 'confirm' ? '确认失败。' : '打款失败。'))
    } finally {
      setBusyId(null)
    }
  }

  const columns: ColumnsType<ExpressPickupSettlementAdmin> = [
    { title: '批次号', width: 220, render: (_, record) => <Typography.Text strong copyable>{record.batch_no}</Typography.Text> },
    { title: '周期', width: 200, render: (_, record) => `${record.period_start} ~ ${record.period_end}` },
    { title: '状态', width: 90, render: (_, record) => <Tag color={record.status === 'paid' ? 'success' : record.status === 'pending' ? 'processing' : 'default'}>{record.status === 'paid' ? '已打款' : record.status === 'pending' ? '已确认' : '草稿'}</Tag> },
    { title: '跑腿人数', width: 100, render: (_, record) => record.runner_count },
    { title: '订单数', width: 90, render: (_, record) => record.order_count },
    { title: '金额', width: 110, render: (_, record) => money(record.total_cents) },
    { title: '创建时间', width: 180, render: (_, record) => timeText(record.created_at) },
    {
      title: '操作', width: 180, fixed: 'right',
      render: (_, record) => <Space size={0}>
        <Button type="link" size="small" disabled={!canSettle || record.status !== 'draft' || busyId === record.id} onClick={() => void act(record, 'confirm')}>确认</Button>
        <Popconfirm title={`确认打款 ${money(record.total_cents)} 到跑腿钱包？`} onConfirm={() => void act(record, 'pay')} disabled={!canSettle || record.status !== 'pending'}>
          <Button type="link" size="small" disabled={!canSettle || record.status !== 'pending' || busyId === record.id} loading={busyId === record.id}>打款</Button>
        </Popconfirm>
      </Space>,
    },
  ]

  return (
    <>
      <Space wrap style={{ marginBottom: 12 }}>
        <DatePicker.RangePicker
          key={rangeKey}
          onChange={(_, dateStrings) => {
            setRangeStart(dateStrings[0] || null)
            setRangeEnd(dateStrings[1] || null)
          }}
        />
        <Button type="primary" icon={<PlusOutlined />} loading={creating} disabled={!canSettle} onClick={() => void create()}>生成结算批次</Button>
        <Button icon={<ReloadOutlined />} onClick={() => void load()}>刷新</Button>
      </Space>
      <Alert type="info" showIcon message="生成批次会把所选周期内已完成且未结算的代取单归集；确认后打款会直接进入跑腿钱包，沿用现有提现审核链路。" style={{ marginBottom: 12 }} />
      <Table
        rowKey="id"
        size="small"
        loading={loading}
        columns={columns}
        dataSource={items}
        scroll={{ x: 1120 }}
        pagination={{ pageSize: 20, showSizeChanger: false }}
        expandable={{
          expandedRowRender: (record) => <Space direction="vertical" size={4}>
            {record.items.map((item) => <Typography.Text key={item.id} type="secondary" style={{ fontSize: 12 }}>
              跑腿用户 #{item.runner_user_id} · {item.order_count} 单 · {money(item.amount_cents)} · {item.status === 'paid' ? '已打款' : '待打款'}
            </Typography.Text>)}
          </Space>,
        }}
      />
    </>
  )
}

interface PrinterFormValues {
  name: string
  vendor: CampusPrinter['vendor']
  sn: string
  username?: string
  secret?: string
  paper_width: number
  status: CampusPrinter['status']
}

function PrintersTab({ messageApi, canPrinter }: { messageApi: MessageInstance; canPrinter: boolean }) {
  const [data, setData] = useState<CampusPrinterList | null>(null)
  const [loading, setLoading] = useState(false)
  const [editing, setEditing] = useState<CampusPrinter | null>(null)
  const [formOpen, setFormOpen] = useState(false)
  const [saving, setSaving] = useState(false)
  const [template, setTemplate] = useState('')
  const [preview, setPreview] = useState('')
  const [previewing, setPreviewing] = useState(false)
  const [busyId, setBusyId] = useState<string | null>(null)
  const [form] = Form.useForm<PrinterFormValues>()
  const textAreaRef = useRef<TextAreaRef>(null)

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const result = await listCampusPrinters()
      setData(result)
    } catch (error) {
      messageApi.error(failText(error, '小票机列表加载失败。'))
    } finally {
      setLoading(false)
    }
  }, [messageApi])

  useEffect(() => { void load() }, [load])

  const openCreate = () => {
    setEditing(null)
    form.resetFields()
    form.setFieldsValue({ name: '', vendor: 'feie', sn: '', username: '', secret: '', paper_width: 58, status: 'enabled' })
    setTemplate(data?.default_template ?? '')
    setPreview('')
    setFormOpen(true)
  }

  const openEdit = (printer: CampusPrinter) => {
    setEditing(printer)
    form.setFieldsValue({
      name: printer.name,
      vendor: printer.vendor,
      sn: printer.sn,
      username: printer.username ?? '',
      secret: '',
      paper_width: printer.paper_width,
      status: printer.status,
    })
    setTemplate(printer.template ?? data?.default_template ?? '')
    setPreview('')
    setFormOpen(true)
  }

  const insertVariable = (key: string) => {
    const element = textAreaRef.current?.resizableTextArea?.textArea
    const start = element?.selectionStart ?? template.length
    const end = element?.selectionEnd ?? start
    const next = template.slice(0, start) + key + template.slice(end)
    setTemplate(next)
    window.setTimeout(() => {
      if (element) {
        element.focus()
        element.setSelectionRange(start + key.length, start + key.length)
      }
    }, 0)
  }

  const runPreview = async () => {
    setPreviewing(true)
    try {
      const values = form.getFieldsValue()
      const result = await previewCampusPrinter({ template, paper_width: values.paper_width ?? 58 })
      setPreview(result.content)
    } catch (error) {
      messageApi.error(failText(error, '预览生成失败。'))
    } finally {
      setPreviewing(false)
    }
  }

  const save = async () => {
    const values = await form.validateFields()
    const payload: CampusPrinterInput = {
      name: values.name.trim(),
      vendor: values.vendor,
      sn: values.sn.trim(),
      username: values.username?.trim() || null,
      secret: values.secret?.trim() || null,
      paper_width: values.paper_width,
      status: values.status,
      template,
    }
    setSaving(true)
    try {
      if (editing) await updateCampusPrinter(editing.id, payload)
      else await createCampusPrinter(payload)
      messageApi.success(editing ? '小票机已保存。' : '小票机已创建。')
      setFormOpen(false)
      void load()
    } catch (error) {
      messageApi.error(failText(error, '保存失败。'))
    } finally {
      setSaving(false)
    }
  }

  const test = async (printer: CampusPrinter) => {
    setBusyId(printer.id)
    try {
      const result = await testCampusPrinter(printer.id)
      if (result.ok) messageApi.success(result.message || '连接成功。')
      else messageApi.warning(result.message || '连接失败。')
      void load()
    } catch (error) {
      messageApi.error(failText(error, '测试连接失败。'))
    } finally {
      setBusyId(null)
    }
  }

  const remove = async (printer: CampusPrinter) => {
    setBusyId(printer.id)
    try {
      await deleteCampusPrinter(printer.id)
      messageApi.success('小票机已删除。')
      void load()
    } catch (error) {
      messageApi.error(failText(error, '删除失败。'))
    } finally {
      setBusyId(null)
    }
  }

  const columns: ColumnsType<CampusPrinter> = [
    { title: '名称', width: 160, render: (_, record) => <Typography.Text strong>{record.name}</Typography.Text> },
    { title: '厂商', width: 110, render: (_, record) => <Tag color={record.vendor === 'feie' ? 'blue' : 'default'}>{record.vendor_label}{record.vendor !== 'feie' ? '（待接入）' : ''}</Tag> },
    { title: '设备 SN', width: 180, render: (_, record) => record.sn },
    { title: '纸宽', width: 90, render: (_, record) => `${record.paper_width}mm` },
    { title: '密钥', width: 90, render: (_, record) => record.secret_configured ? <Tag color="success">已配置</Tag> : <Tag color="warning">未配置</Tag> },
    { title: '状态', width: 90, render: (_, record) => record.status === 'enabled' ? <Tag color="success">启用</Tag> : <Tag>停用</Tag> },
    { title: '最近测试', width: 220, render: (_, record) => record.last_test_at ? `${timeText(record.last_test_at)} · ${record.last_test_message ?? ''}` : '—' },
    {
      title: '操作', width: 230, fixed: 'right',
      render: (_, record) => <Space size={0} wrap>
        <Button type="link" size="small" icon={<PrinterOutlined />} disabled={!canPrinter || busyId === record.id} loading={busyId === record.id} onClick={() => void test(record)}>测试</Button>
        <Button type="link" size="small" icon={<EditOutlined />} disabled={!canPrinter} onClick={() => openEdit(record)}>编辑</Button>
        <Popconfirm title="删除后该校区将无法自动打印小票，确认删除？" onConfirm={() => void remove(record)} disabled={!canPrinter}>
          <Button type="link" size="small" danger icon={<DeleteOutlined />} disabled={!canPrinter}>删除</Button>
        </Popconfirm>
      </Space>,
    },
  ]

  return (
    <>
      <Space wrap style={{ marginBottom: 12 }}>
        <Button type="primary" icon={<PlusOutlined />} disabled={!canPrinter} onClick={openCreate}>新增小票机</Button>
        <Button icon={<ReloadOutlined />} onClick={() => void load()}>刷新</Button>
      </Space>
      <Alert type="info" showIcon style={{ marginBottom: 12 }} message="每家校区可配置多台小票机，支付成功时使用该校区最新启用的一台自动打印。飞鹅已接入；大趋、商鹏需提供接口文档后补齐驱动。" />
      <Table rowKey="id" size="small" loading={loading} columns={columns} dataSource={data?.items ?? []} scroll={{ x: 1180 }} pagination={{ pageSize: 20, showSizeChanger: false }} />

      <Modal
        open={formOpen}
        width={880}
        title={editing ? `编辑小票机 ${editing.name}` : '新增小票机'}
        okText="保存"
        confirmLoading={saving}
        onOk={() => void save()}
        onCancel={() => setFormOpen(false)}
      >
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 320px', gap: 16 }}>
          <div>
            <Form form={form} layout="vertical" requiredMark={false}>
              <Form.Item name="name" label="名称" rules={[{ required: true, message: '请填写名称' }]}>
                <Input maxLength={60} placeholder="如：菜鸟驿站东门打印机" />
              </Form.Item>
              <Space.Compact style={{ width: '100%' }}>
                <Form.Item name="vendor" label="厂商" rules={[{ required: true }]} style={{ flex: 1 }}>
                  <Select options={(data?.vendors ?? []).map((vendor) => ({ value: vendor.code, label: `${vendor.label}${vendor.ready ? '' : '（待接入）'}` }))} />
                </Form.Item>
                <Form.Item name="paper_width" label="纸宽" style={{ width: 120, marginLeft: 8 }}>
                  <Select options={[{ value: 58, label: '58mm' }, { value: 80, label: '80mm' }]} />
                </Form.Item>
              </Space.Compact>
              <Form.Item name="sn" label="设备编号 SN" rules={[{ required: true, message: '请填写设备 SN' }]}>
                <Input maxLength={64} />
              </Form.Item>
              <Form.Item name="username" label="账号（飞鹅 user）">
                <Input maxLength={64} placeholder="飞鹅开放平台账号" />
              </Form.Item>
              <Form.Item name="secret" label={editing?.secret_configured ? '接口密钥（留空则不修改）' : '接口密钥（飞鹅 ukey）'}>
                <Input.Password maxLength={200} placeholder={editing?.secret_configured ? '已配置，留空保持不变' : '请输入接口密钥'} />
              </Form.Item>
              <Form.Item name="status" label="状态">
                <Select options={[{ value: 'enabled', label: '启用' }, { value: 'disabled', label: '停用' }]} />
              </Form.Item>
            </Form>
            <Typography.Text strong>小票模板</Typography.Text>
            <div style={{ margin: '6px 0' }}>
              <Space size={[4, 4]} wrap>
                {(data?.variables ?? []).map((variable) => (
                  <Button key={variable.key} size="small" onClick={() => insertVariable(variable.key)}>{variable.label}</Button>
                ))}
              </Space>
            </div>
            <Input.TextArea
              ref={textAreaRef}
              rows={12}
              value={template}
              onChange={(event) => setTemplate(event.target.value)}
              style={{ fontFamily: 'monospace', fontSize: 12 }}
            />
          </div>
          <div>
            <Space style={{ marginBottom: 8 }}>
              <Button size="small" loading={previewing} onClick={() => void runPreview()}>生成预览</Button>
            </Space>
            <div style={{ background: '#fafafa', border: '1px dashed #d9d9d9', borderRadius: 6, padding: 12, minHeight: 320, maxHeight: 460, overflow: 'auto' }}>
              <pre style={{ margin: 0, fontFamily: 'monospace', fontSize: 12, whiteSpace: 'pre-wrap' }}>{preview || '点击「生成预览」查看小票效果'}</pre>
            </div>
          </div>
        </div>
      </Modal>
    </>
  )
}
