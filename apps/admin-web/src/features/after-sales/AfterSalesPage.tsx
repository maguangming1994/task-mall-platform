import { Button, Form, Input, Modal, Select, Table, message } from 'antd'
import { useCallback, useEffect, useMemo, useState } from 'react'
import {
  ApiError,
  listManagedExchanges,
  listManagedRefunds,
  reviewManagedExchange,
  reviewManagedRefund,
  type ManagedExchange,
  type ManagedRefund,
} from '../../api/admin'
import { Links, PageHead, Pill, type PillTone } from '../../components/xq'
import { afterSaleStatusLabels, businessLabel, responsibilityLabels, shippingPayerLabels } from '../../constants/businessLabels'

const formatMoney = (cents = 0) => `¥${(cents / 100).toFixed(2)}`
const formatDate = (value: string | null | undefined) =>
  value ? new Date(value).toLocaleString('zh-CN', { hour12: false }) : '—'

type AfterSaleType = 'refund' | 'exchange'

interface AfterSaleRow {
  key: string
  type: AfterSaleType
  typeLabel: string
  ticketNo: string
  orderNo: string
  userLabel: string
  amount: string
  status: string
  reason: string
  createdAt: string
  refund?: ManagedRefund
  exchange?: ManagedExchange
}

const statusTone = (status: string): PillTone => {
  if (status === 'submitted') return 'orange'
  if (['rejected'].includes(status)) return 'red'
  if (['succeeded', 'completed'].includes(status)) return 'green'
  if (['cancelled', 'closed', 'expired'].includes(status)) return 'gray'
  return 'blue'
}

const refundTypeLabel = (serviceType: string) =>
  serviceType.includes('return') ? '退货退款' : '退款'

interface ReviewForm {
  responsibility_party?: string
  return_shipping_payer?: string
  replacement_shipping_payer?: string
  return_method?: string
  note: string
}

export function AfterSalesPage() {
  const [refunds, setRefunds] = useState<ManagedRefund[]>([])
  const [exchanges, setExchanges] = useState<ManagedExchange[]>([])
  const [loading, setLoading] = useState(false)
  const [submitting, setSubmitting] = useState(false)
  const [typeFilter, setTypeFilter] = useState('')
  const [status, setStatus] = useState('')
  const [keywordInput, setKeywordInput] = useState('')
  const [keyword, setKeyword] = useState('')
  const [detail, setDetail] = useState<AfterSaleRow | null>(null)
  const [review, setReview] = useState<{ row: AfterSaleRow; decision: 'approve' | 'reject' } | null>(null)
  const [form] = Form.useForm<ReviewForm>()
  const [messageApi, contextHolder] = message.useMessage()

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const [refundResult, exchangeResult] = await Promise.all([listManagedRefunds(), listManagedExchanges()])
      setRefunds(refundResult.items)
      setExchanges(exchangeResult.items)
    } catch (cause) {
      messageApi.error(cause instanceof ApiError ? cause.message : '售后列表加载失败。')
    } finally {
      setLoading(false)
    }
  }, [messageApi])

  useEffect(() => { void load() }, [load])

  const rows = useMemo<AfterSaleRow[]>(() => {
    const refundRows: AfterSaleRow[] = refunds.map((item) => ({
      key: `refund-${item.id}`,
      type: 'refund',
      typeLabel: refundTypeLabel(item.service_type),
      ticketNo: item.refund_no,
      orderNo: item.order_no ?? '—',
      userLabel: item.user?.nickname ?? '—',
      amount: `${formatMoney(item.refund_cash_amount)}${item.refund_points > 0 ? ` + ${item.refund_points} 积分` : ''}`,
      status: item.status,
      reason: item.reason_name,
      createdAt: item.created_at,
      refund: item,
    }))
    const exchangeRows: AfterSaleRow[] = exchanges.map((item) => ({
      key: `exchange-${item.id}`,
      type: 'exchange',
      typeLabel: '换货',
      ticketNo: item.exchange_no,
      orderNo: item.order_no ?? '—',
      userLabel: item.user?.nickname ?? '—',
      amount: '—',
      status: item.status,
      reason: item.reason_name,
      createdAt: item.created_at,
      exchange: item,
    }))
    return [...refundRows, ...exchangeRows].sort((a, b) => (b.createdAt || '').localeCompare(a.createdAt || ''))
  }, [refunds, exchanges])

  const filtered = useMemo(() => rows.filter((row) => {
    if (typeFilter && row.type !== typeFilter) return false
    if (status && row.status !== status) return false
    if (keyword && !`${row.ticketNo} ${row.orderNo} ${row.userLabel}`.toLowerCase().includes(keyword.toLowerCase())) return false
    return true
  }), [rows, typeFilter, status, keyword])

  const submitReview = async (values: ReviewForm) => {
    if (!review) return
    setSubmitting(true)
    try {
      if (review.row.type === 'refund' && review.row.refund) {
        await reviewManagedRefund(review.row.refund.id, review.decision === 'approve'
          ? {
              decision: 'approve',
              responsibility_party: values.responsibility_party,
              return_shipping_payer: values.return_shipping_payer,
              return_method: values.return_method,
              note: values.note,
            }
          : { decision: 'reject', note: values.note })
      } else if (review.row.exchange) {
        await reviewManagedExchange(review.row.exchange.id, review.decision === 'approve'
          ? {
              decision: 'approve',
              responsibility_party: values.responsibility_party,
              return_shipping_payer: values.return_shipping_payer,
              replacement_shipping_payer: values.replacement_shipping_payer,
              return_method: values.return_method,
              reason: values.note,
            }
          : { decision: 'reject', reason: values.note })
      }
      messageApi.success(review.decision === 'approve' ? '已同意该售后申请。' : '已拒绝该售后申请。')
      setReview(null)
      await load()
    } catch (cause) {
      messageApi.error(cause instanceof ApiError ? cause.message : '售后审核失败。')
    } finally {
      setSubmitting(false)
    }
  }

  const responsibilityOptions = Object.entries(responsibilityLabels)
    .filter(([value]) => value !== 'pending' && value !== 'none')
    .map(([value, label]) => ({ value, label }))
  const payerOptions = Object.entries(shippingPayerLabels).map(([value, label]) => ({ value, label }))

  return (
    <div className="xq-page">
      {contextHolder}
      <PageHead title="售后处理" />

      <div className="xq-card xq-filter" style={{ marginBottom: 16 }}>
        <Select
          value={typeFilter || 'all'}
          style={{ minWidth: 130 }}
          onChange={(value) => setTypeFilter(value === 'all' ? '' : value)}
          options={[
            { value: 'all', label: '全部类型' },
            { value: 'refund', label: '退款 / 退货' },
            { value: 'exchange', label: '换货' },
          ]}
        />
        <Select
          value={status || 'all'}
          style={{ minWidth: 130 }}
          onChange={(value) => setStatus(value === 'all' ? '' : value)}
          options={[
            { value: 'all', label: '全部状态' },
            { value: 'submitted', label: '待处理' },
            { value: 'awaiting_return', label: '处理中' },
            { value: 'succeeded', label: '已完成' },
            { value: 'rejected', label: '已拒绝' },
            { value: 'closed', label: '已关闭' },
          ]}
        />
        <Input
          placeholder="售后单号/订单号"
          style={{ maxWidth: 220 }}
          value={keywordInput}
          onChange={(event) => setKeywordInput(event.target.value)}
          onPressEnter={() => setKeyword(keywordInput.trim())}
          allowClear
        />
        <Button type="primary" onClick={() => setKeyword(keywordInput.trim())}>查询</Button>
        <Button onClick={() => { setTypeFilter(''); setStatus(''); setKeywordInput(''); setKeyword('') }}>重置</Button>
      </div>

      <div className="xq-card xq-table">
        <Table
          rowKey="key"
          size="middle"
          loading={loading}
          dataSource={filtered}
          pagination={{ pageSize: 10, showTotal: (total) => `共 ${total} 条` }}
          columns={[
            { title: '售后单号', dataIndex: 'ticketNo', render: (value: string) => <span style={{ fontWeight: 600 }}>{value}</span> },
            { title: '订单号', dataIndex: 'orderNo' },
            { title: '用户', dataIndex: 'userLabel' },
            { title: '类型', dataIndex: 'typeLabel' },
            { title: '金额', dataIndex: 'amount' },
            {
              title: '状态',
              render: (_: unknown, row: AfterSaleRow) => (
                <Pill tone={statusTone(row.status)}>{businessLabel(afterSaleStatusLabels, row.status)}</Pill>
              ),
            },
            { title: '申请时间', dataIndex: 'createdAt', render: (value: string) => formatDate(value) },
            {
              title: '操作',
              render: (_: unknown, row: AfterSaleRow) => (
                <Links items={[
                  { label: '查看', onClick: () => setDetail(row) },
                  row.status === 'submitted' && { label: '同意', onClick: () => { form.resetFields(); form.setFieldsValue({ responsibility_party: 'buyer', return_shipping_payer: 'buyer', replacement_shipping_payer: 'seller', return_method: 'logistics' }); setReview({ row, decision: 'approve' }) } },
                  row.status === 'submitted' && { label: '拒绝', danger: true, onClick: () => { form.resetFields(); setReview({ row, decision: 'reject' }) } },
                ]} />
              ),
            },
          ]}
        />
      </div>

      <Modal title="售后详情" open={detail !== null} footer={null} onCancel={() => setDetail(null)}>
        {detail && (
          <div style={{ lineHeight: 2 }}>
            <p><b>售后单号：</b>{detail.ticketNo}</p>
            <p><b>类型：</b>{detail.typeLabel} · <b>申请原因：</b>{detail.reason}</p>
            <p><b>金额：</b>{detail.amount}</p>
            <p><b>状态：</b>{businessLabel(afterSaleStatusLabels, detail.status)} · 申请于 {formatDate(detail.createdAt)}</p>
            {detail.refund && (
              <p style={{ marginBottom: 0 }}>
                <b>责任判定：</b>{businessLabel(responsibilityLabels, detail.refund.responsibility_party)} ·
                <b> 退货运费：</b>{businessLabel(shippingPayerLabels, detail.refund.return_shipping_payer)}
                {detail.refund.review_note ? ` · 审核备注：${detail.refund.review_note}` : ''}
              </p>
            )}
            {detail.exchange && (
              <p style={{ marginBottom: 0 }}>
                <b>换货数量：</b>{detail.exchange.quantity} ·
                <b> 责任判定：</b>{businessLabel(responsibilityLabels, detail.exchange.responsibility_party)}
                {detail.exchange.review_note ? ` · 审核备注：${detail.exchange.review_note}` : ''}
              </p>
            )}
          </div>
        )}
      </Modal>

      <Modal
        title={review ? (review.decision === 'approve' ? `同意售后申请 · ${review.row.ticketNo}` : `拒绝售后申请 · ${review.row.ticketNo}`) : '售后审核'}
        open={review !== null}
        onCancel={() => setReview(null)}
        onOk={() => form.submit()}
        okText={review?.decision === 'approve' ? '确认同意' : '确认拒绝'}
        cancelText="取消"
        confirmLoading={submitting}
        destroyOnHidden
      >
        <Form form={form} layout="vertical" onFinish={submitReview}>
          {review?.decision === 'approve' && (
            <>
              <Form.Item name="responsibility_party" label="责任方" rules={[{ required: true, message: '请选择责任方' }]}>
                <Select options={responsibilityOptions} />
              </Form.Item>
              <Form.Item name="return_shipping_payer" label="退货运费承担方" rules={[{ required: true, message: '请选择运费承担方' }]}>
                <Select options={payerOptions} />
              </Form.Item>
              {review.row.type === 'exchange' && (
                <Form.Item name="replacement_shipping_payer" label="换出运费承担方" rules={[{ required: true, message: '请选择换出运费承担方' }]}>
                  <Select options={payerOptions} />
                </Form.Item>
              )}
              <Form.Item name="return_method" label="退货方式" rules={[{ required: true, message: '请选择退货方式' }]}>
                <Select options={[{ value: 'logistics', label: '物流寄回' }, { value: 'no_logistics', label: '无需物流' }]} />
              </Form.Item>
            </>
          )}
          <Form.Item name="note" label={review?.decision === 'approve' ? '审核备注' : '拒绝原因'} rules={[{ required: true, min: 2, message: '请填写说明' }]}>
            <Input.TextArea rows={3} maxLength={500} />
          </Form.Item>
        </Form>
      </Modal>
    </div>
  )
}
