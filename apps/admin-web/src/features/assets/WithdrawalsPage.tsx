import { useCallback, useEffect, useMemo, useState } from 'react'
import { Button, Descriptions, Form, Input, Modal, Select, Table, message } from 'antd'
import type { ColumnsType } from 'antd/es/table'
import {
  listWalletWithdrawals,
  recordManualWithdrawalResult,
  reviewWalletWithdrawal,
  type WalletWithdrawal,
} from '../../api/admin'
import { Links, PageHead, Pill, StatCard, UCell, type PillTone } from '../../components/xq'

const yuan = (cents?: number | null) => `¥${((cents ?? 0) / 100).toFixed(2)}`
const fmtTime = (v?: string | null) => (v ? new Date(v).toLocaleString('zh-CN', { hour12: false }) : '—')
const sameDay = (v: string, d: Date) => new Date(v).toDateString() === d.toDateString()
const sameMonth = (v: string, d: Date) => {
  const t = new Date(v)
  return t.getFullYear() === d.getFullYear() && t.getMonth() === d.getMonth()
}

const statusOptions = [
  { value: 'reviewing', label: '待审核' },
  { value: 'processing', label: '出款中' },
  { value: 'succeeded', label: '已到账' },
  { value: 'failed', label: '出款失败' },
  { value: 'rejected', label: '已拒绝' },
]

const statusPills: Record<string, { tone: PillTone; label: string }> = {
  submitted: { tone: 'orange', label: '待审核' },
  reviewing: { tone: 'orange', label: '待审核' },
  processing: { tone: 'blue', label: '出款中' },
  succeeded: { tone: 'green', label: '已通过' },
  failed: { tone: 'red', label: '出款失败' },
  rejected: { tone: 'red', label: '已驳回' },
}
const pillOf = (s: string) => statusPills[s] ?? { tone: 'gray' as PillTone, label: s }

interface ReviewFormValue { note?: string }
interface ManualFormValue { decision: 'paid' | 'failed'; reference?: string; note?: string }

export function WithdrawalsPage() {
  const [messageApi, contextHolder] = message.useMessage()
  const [status, setStatus] = useState('reviewing')
  const [channel, setChannel] = useState('')
  const [keyword, setKeyword] = useState('')
  const [applied, setApplied] = useState({ channel: '', keyword: '' })
  const [groups, setGroups] = useState<Record<string, WalletWithdrawal[]>>({})
  const [loading, setLoading] = useState(false)
  const [detail, setDetail] = useState<WalletWithdrawal | null>(null)
  const [reviewTarget, setReviewTarget] = useState<{ item: WalletWithdrawal; decision: 'approve' | 'reject' } | null>(null)
  const [manualTarget, setManualTarget] = useState<WalletWithdrawal | null>(null)
  const [submitting, setSubmitting] = useState(false)
  const [reviewForm] = Form.useForm<ReviewFormValue>()
  const [manualForm] = Form.useForm<ManualFormValue>()

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const statuses = statusOptions.map((s) => s.value)
      const results = await Promise.all(statuses.map((s) => listWalletWithdrawals(s)))
      const next: Record<string, WalletWithdrawal[]> = {}
      statuses.forEach((s, i) => { next[s] = results[i].items })
      setGroups(next)
    } catch {
      messageApi.error('提现数据加载失败，请稍后重试。')
    } finally {
      setLoading(false)
    }
  }, [messageApi])

  useEffect(() => { void load() }, [load])

  const now = new Date()
  const stats = useMemo(() => {
    const succeeded = groups.succeeded ?? []
    return {
      todayCount: Object.values(groups).flat().filter((i) => sameDay(i.created_at, now)).length,
      todayApproved: succeeded.filter((i) => sameDay(i.completed_at ?? i.created_at, now)).reduce((s, i) => s + i.requested_amount, 0),
      pending: (groups.reviewing ?? []).length,
      monthTotal: succeeded.filter((i) => sameMonth(i.completed_at ?? i.created_at, now)).reduce((s, i) => s + i.received_amount, 0),
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [groups])

  const rows = useMemo(() => (groups[status] ?? []).filter((i) => {
    if (applied.channel && i.channel !== applied.channel) return false
    if (applied.keyword.trim()) {
      const k = applied.keyword.trim()
      const hay = `${i.withdrawal_no} ${i.id} ${i.bank_card?.cardholder_name ?? ''} ${i.bank_card?.phone_masked ?? ''}`
      if (!hay.includes(k)) return false
    }
    return true
  }), [groups, status, applied])

  const submitReview = async (values: ReviewFormValue) => {
    if (!reviewTarget) return
    setSubmitting(true)
    try {
      await reviewWalletWithdrawal(reviewTarget.item.id, reviewTarget.decision, values.note?.trim() || (reviewTarget.decision === 'approve' ? '审核通过' : '审核驳回'))
      messageApi.success(reviewTarget.decision === 'approve' ? '已通过该提现申请。' : '已驳回该提现申请。')
      setReviewTarget(null)
      await load()
    } catch {
      messageApi.error('审核操作失败，请稍后重试。')
    } finally {
      setSubmitting(false)
    }
  }

  const submitManual = async (values: ManualFormValue) => {
    if (!manualTarget) return
    setSubmitting(true)
    try {
      await recordManualWithdrawalResult(manualTarget.id, values.decision, { reference: values.reference?.trim() || undefined, note: values.note?.trim() || undefined })
      messageApi.success(values.decision === 'paid' ? '已登记为打款成功。' : '已登记为打款失败。')
      setManualTarget(null)
      await load()
    } catch {
      messageApi.error('打款登记失败，请稍后重试。')
    } finally {
      setSubmitting(false)
    }
  }

  const columns: ColumnsType<WalletWithdrawal> = [
    { title: '用户', render: (_, r) => <UCell name={r.bank_card?.cardholder_name ?? '微信用户'} sub={`ID:${r.id}`} /> },
    {
      title: '提现方式',
      render: (_, r) => (
        <span>
          {r.channel_label ?? (r.channel === 'bank_card' ? '银行卡' : '微信零钱')}
          {r.bank_card ? <span style={{ display: 'block', color: '#9ca3af', fontSize: 11 }}>{r.bank_card.bank_name} {r.bank_card.card_masked}</span> : null}
        </span>
      ),
    },
    { title: '金额', render: (_, r) => yuan(r.requested_amount) },
    { title: '手续费', render: (_, r) => yuan(r.fee_amount) },
    { title: '实际到账', render: (_, r) => yuan(r.received_amount) },
    { title: '状态', render: (_, r) => { const p = pillOf(r.status); return <Pill tone={p.tone}>{p.label}</Pill> } },
    { title: '申请时间', render: (_, r) => fmtTime(r.created_at) },
    {
      title: '操作',
      render: (_, r) => (
        <Links items={[
          { label: '查看', onClick: () => setDetail(r) },
          (r.status === 'reviewing' || r.status === 'submitted') && { label: '通过', onClick: () => { reviewForm.resetFields(); setReviewTarget({ item: r, decision: 'approve' }) } },
          (r.status === 'reviewing' || r.status === 'submitted') && { label: '驳回', danger: true, onClick: () => { reviewForm.resetFields(); setReviewTarget({ item: r, decision: 'reject' }) } },
          r.status === 'processing' && r.channel === 'bank_card' && { label: '登记打款', onClick: () => { manualForm.resetFields(); manualForm.setFieldsValue({ decision: 'paid' }); setManualTarget(r) } },
        ]} />
      ),
    },
  ]

  return (
    <div className="xq-page">
      {contextHolder}
      <PageHead title="提现审核" />

      <div className="xq-stats">
        <StatCard title="今日申请笔数" value={stats.todayCount} delta="全部状态合计" />
        <StatCard title="今日通过金额" value={yuan(stats.todayApproved)} delta="按完成时间统计" />
        <StatCard title="待审核笔数" value={stats.pending} valueColor="#d97706" delta={<span style={{ color: '#dc2626' }}>需尽快处理</span>} />
        <StatCard title="本月累计提现" value={yuan(stats.monthTotal)} delta="已成功出款合计" />
      </div>

      <div className="xq-card xq-filter" style={{ marginBottom: 16 }}>
        <Select value={status} onChange={setStatus} style={{ minWidth: 120 }} options={statusOptions} />
        <Select
          value={channel || undefined}
          onChange={(v) => { const next = v ?? ''; setChannel(next); setApplied((prev) => ({ ...prev, channel: next })) }}
          allowClear
          placeholder="全部方式"
          style={{ minWidth: 120 }}
          options={[{ value: 'wechat', label: '微信零钱' }, { value: 'bank_card', label: '银行卡' }]}
        />
        <Input placeholder="用户昵称/手机号" value={keyword} onChange={(e) => setKeyword(e.target.value)} onPressEnter={() => setApplied({ channel, keyword })} style={{ maxWidth: 220 }} />
        <Button type="primary" onClick={() => setApplied({ channel, keyword })}>查询</Button>
        <Button onClick={() => { setChannel(''); setKeyword(''); setApplied({ channel: '', keyword: '' }); setStatus('reviewing') }}>重置</Button>
      </div>

      <div className="xq-card xq-table">
        <Table
          rowKey="id"
          size="middle"
          loading={loading}
          columns={columns}
          dataSource={rows}
          pagination={{ pageSize: 10, showTotal: (t) => `共 ${t} 条` }}
        />
      </div>

      <Modal title="提现详情" open={!!detail} footer={null} onCancel={() => setDetail(null)} width={560}>
        {detail && (
          <Descriptions
            column={1}
            size="small"
            bordered
            items={[
              { key: 'no', label: '提现单号', children: detail.withdrawal_no },
              { key: 'amount', label: '申请金额', children: yuan(detail.requested_amount) },
              { key: 'fee', label: '手续费', children: `${yuan(detail.fee_amount)}（费率 ${(detail.fee_rate * 100).toFixed(2)}%）` },
              { key: 'received', label: '实际到账', children: yuan(detail.received_amount) },
              { key: 'channel', label: '提现方式', children: detail.channel_label ?? detail.channel },
              ...(detail.bank_card ? [
                { key: 'payee', label: '收款人', children: `${detail.bank_card.cardholder_name}（${detail.bank_card.phone_masked}）` },
                { key: 'account', label: '收款账户', children: `${detail.bank_card.bank_name} ${detail.bank_card.card_masked}` },
              ] : []),
              { key: 'status', label: '状态', children: pillOf(detail.status).label },
              { key: 'created', label: '申请时间', children: fmtTime(detail.created_at) },
              { key: 'completed', label: '完成时间', children: fmtTime(detail.completed_at) },
              { key: 'note', label: '审核备注', children: detail.review_note ?? '—' },
              { key: 'ref', label: '渠道参考号', children: detail.channel_reference ?? '—' },
              { key: 'fail', label: '失败原因', children: detail.failure_message ?? '—' },
            ]}
          />
        )}
      </Modal>

      <Modal
        title={reviewTarget?.decision === 'approve' ? '通过提现申请' : '驳回提现申请'}
        open={!!reviewTarget}
        onCancel={() => setReviewTarget(null)}
        onOk={() => reviewForm.submit()}
        confirmLoading={submitting}
        okText="确认"
        cancelText="取消"
      >
        <p style={{ color: '#6b7280', fontSize: 13 }}>
          提现单号：{reviewTarget?.item.withdrawal_no}，到账金额 {yuan(reviewTarget?.item.received_amount)}
        </p>
        <Form form={reviewForm} layout="vertical" onFinish={submitReview}>
          <Form.Item name="note" label="审核备注" rules={reviewTarget?.decision === 'reject' ? [{ required: true, message: '驳回时请填写原因' }] : []}>
            <Input.TextArea rows={3} placeholder={reviewTarget?.decision === 'approve' ? '审核通过（可不填）' : '请填写驳回原因'} />
          </Form.Item>
        </Form>
      </Modal>

      <Modal
        title="银行卡人工打款登记"
        open={!!manualTarget}
        onCancel={() => setManualTarget(null)}
        onOk={() => manualForm.submit()}
        confirmLoading={submitting}
        okText="提交登记"
        cancelText="取消"
      >
        {manualTarget?.bank_card && (
          <p style={{ color: '#6b7280', fontSize: 13 }}>
            收款人：{manualTarget.bank_card.cardholder_name}（{manualTarget.bank_card.phone_masked}）<br />
            收款账户：{manualTarget.bank_card.bank_name} {manualTarget.bank_card.card_masked}，打款金额 {yuan(manualTarget.received_amount)}
          </p>
        )}
        <Form form={manualForm} layout="vertical" onFinish={submitManual} initialValues={{ decision: 'paid' }}>
          <Form.Item name="decision" label="打款结果" rules={[{ required: true }]}>
            <Select options={[{ value: 'paid', label: '打款成功' }, { value: 'failed', label: '打款失败' }]} />
          </Form.Item>
          <Form.Item name="reference" label="银行流水号 / 参考号">
            <Input placeholder="选填" />
          </Form.Item>
          <Form.Item name="note" label="备注">
            <Input.TextArea rows={2} placeholder="选填" />
          </Form.Item>
        </Form>
      </Modal>
    </div>
  )
}
