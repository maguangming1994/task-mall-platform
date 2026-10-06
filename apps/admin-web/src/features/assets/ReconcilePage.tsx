import { useCallback, useEffect, useMemo, useState } from 'react'
import { Button, Descriptions, Form, Input, Modal, Select, Table, message } from 'antd'
import type { ColumnsType } from 'antd/es/table'
import {
  importReconciliationRun,
  listReconciliationRuns,
  resolveReconciliationEntry,
  type ReconciliationEntry,
  type ReconciliationRun,
} from '../../api/admin'
import { reconciliationResolutionLabels, reconciliationResultLabels, reconciliationStatusLabels } from '../../constants/businessLabels'
import { Links, PageHead, Pill, StatCard, type PillTone } from '../../components/xq'

const yuan = (cents?: number | null) => (cents === null || cents === undefined ? '—' : `¥${(cents / 100).toFixed(2)}`)
const fmtTime = (v?: string | null) => (v ? new Date(v).toLocaleString('zh-CN', { hour12: false }) : '—')

const resultPills: Record<string, PillTone> = { amount_mismatch: 'orange', state_mismatch: 'orange', missing_local: 'red', missing_provider: 'red', matched: 'green' }
const resolutionPill = (s: string): { tone: PillTone; label: string } =>
  s === 'open' ? { tone: 'red', label: '待处理' } : s === 'not_required' ? { tone: 'gray', label: '无需处置' } : { tone: 'green', label: reconciliationResolutionLabels[s] ?? '已处理' }

type DiffRow = ReconciliationEntry & { run_date: string }
type ResolveAction = 'confirmed_channel_truth' | 'confirmed_local_truth' | 'ignored_with_reason'

interface ImportFormValue { businessDate: string; rows: string }

export function ReconcilePage() {
  const [messageApi, contextHolder] = message.useMessage()
  const [tab, setTab] = useState<'diff' | 'runs'>('diff')
  const [runs, setRuns] = useState<ReconciliationRun[]>([])
  const [loading, setLoading] = useState(false)
  const [detail, setDetail] = useState<DiffRow | null>(null)
  const [resolveTarget, setResolveTarget] = useState<DiffRow | null>(null)
  const [importOpen, setImportOpen] = useState(false)
  const [submitting, setSubmitting] = useState(false)
  const [resolveForm] = Form.useForm<{ action: ResolveAction; reason: string }>()
  const [importForm] = Form.useForm<ImportFormValue>()

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const result = await listReconciliationRuns()
      setRuns(result.items)
    } catch {
      messageApi.error('对账数据加载失败，请稍后重试。')
    } finally {
      setLoading(false)
    }
  }, [messageApi])

  useEffect(() => { void load() }, [load])

  const latest = useMemo(() => [...runs].sort((a, b) => b.business_date.localeCompare(a.business_date))[0], [runs])
  const diffs = useMemo<DiffRow[]>(() => runs.flatMap((r) => (r.entries ?? []).filter((e) => e.result !== 'matched').map((e) => ({ ...e, run_date: r.business_date }))), [runs])
  const matchRate = latest && latest.matched_count + latest.difference_count > 0
    ? `${((latest.matched_count / (latest.matched_count + latest.difference_count)) * 100).toFixed(1)}%`
    : '—'

  const exportCsv = () => {
    if (!diffs.length) { messageApi.info('当前没有可导出的差异条目。'); return }
    const header = '差异单号,关联支付单号,平台金额,渠道金额,差异类型,处置状态,业务日期\n'
    const body = diffs.map((e) => [e.id, e.payment_no, yuan(e.local_amount), yuan(e.provider_amount), reconciliationResultLabels[e.result] ?? e.result, reconciliationResolutionLabels[e.resolution_status] ?? e.resolution_status, e.run_date].join(',')).join('\n')
    const blob = new Blob([`﻿${header}${body}`], { type: 'text/csv;charset=utf-8' })
    const a = document.createElement('a')
    a.href = URL.createObjectURL(blob)
    a.download = `对账差异单_${new Date().toISOString().slice(0, 10)}.csv`
    a.click()
    URL.revokeObjectURL(a.href)
  }

  const submitResolve = async (values: { action: ResolveAction; reason: string }) => {
    if (!resolveTarget) return
    setSubmitting(true)
    try {
      await resolveReconciliationEntry(resolveTarget.id, values.action, values.reason.trim())
      messageApi.success('差异条目已处置。')
      setResolveTarget(null)
      await load()
    } catch {
      messageApi.error('处置失败，请稍后重试。')
    } finally {
      setSubmitting(false)
    }
  }

  const submitImport = async (values: ImportFormValue) => {
    let rows: Array<{ payment_no: string; provider_trade_no?: string; cash_amount: number; state: string }>
    try {
      const parsed: unknown = JSON.parse(values.rows)
      if (!Array.isArray(parsed) || !parsed.length) throw new Error('empty')
      rows = parsed as typeof rows
    } catch {
      messageApi.error('渠道流水 JSON 格式不正确，请检查后重试。')
      return
    }
    setSubmitting(true)
    try {
      await importReconciliationRun(values.businessDate, rows)
      messageApi.success('对账批次已发起。')
      setImportOpen(false)
      importForm.resetFields()
      await load()
    } catch {
      messageApi.error('发起对账失败，请检查参数后重试。')
    } finally {
      setSubmitting(false)
    }
  }

  const diffColumns: ColumnsType<DiffRow> = [
    { title: '差异单号', dataIndex: 'id' },
    { title: '关联订单', dataIndex: 'payment_no' },
    { title: '平台金额', render: (_, r) => yuan(r.local_amount) },
    { title: '渠道金额', render: (_, r) => yuan(r.provider_amount) },
    {
      title: '差异类型',
      render: (_, r) => <Pill tone={resultPills[r.result] ?? 'gray'}>{reconciliationResultLabels[r.result] ?? r.result}</Pill>,
    },
    { title: '业务日期', dataIndex: 'run_date' },
    { title: '状态', render: (_, r) => { const p = resolutionPill(r.resolution_status); return <Pill tone={p.tone}>{p.label}</Pill> } },
    {
      title: '操作',
      render: (_, r) => (
        <Links items={[
          { label: '详情', onClick: () => setDetail(r) },
          r.resolution_status === 'open' && { label: '处置', onClick: () => { resolveForm.resetFields(); resolveForm.setFieldsValue({ action: 'confirmed_local_truth' }); setResolveTarget(r) } },
        ]} />
      ),
    },
  ]

  const runColumns: ColumnsType<ReconciliationRun> = [
    { title: '业务日期', dataIndex: 'business_date' },
    { title: '对账渠道', dataIndex: 'provider' },
    {
      title: '状态',
      render: (_, r) => (
        <Pill tone={r.status === 'matched' ? 'green' : r.status === 'differences_found' ? 'orange' : 'blue'}>
          {reconciliationStatusLabels[r.status] ?? r.status}
        </Pill>
      ),
    },
    { title: '平台流水', render: (_, r) => `${yuan(r.local_amount)}（${r.local_count} 笔）` },
    { title: '渠道流水', render: (_, r) => `${yuan(r.provider_amount)}（${r.provider_count} 笔）` },
    { title: '一致笔数', dataIndex: 'matched_count' },
    { title: '差异笔数', render: (_, r) => <span style={{ color: r.difference_count > 0 ? '#dc2626' : undefined, fontWeight: 600 }}>{r.difference_count}</span> },
    { title: '完成时间', render: (_, r) => fmtTime(r.completed_at) },
  ]

  return (
    <div className="xq-page">
      {contextHolder}
      <PageHead
        title="支付对账"
        extra={(
          <span style={{ display: 'inline-flex', gap: 10 }}>
            <Button onClick={exportCsv}>导出对账单</Button>
            <Button type="primary" onClick={() => setImportOpen(true)}>发起对账</Button>
          </span>
        )}
      />

      <div className="xq-stats">
        <StatCard title="平台支付流水" value={yuan(latest?.local_amount)} delta={latest ? `共 ${latest.local_count} 笔` : '暂无对账批次'} />
        <StatCard title="渠道侧流水" value={yuan(latest?.provider_amount)} delta={latest ? `共 ${latest.provider_count} 笔` : '暂无对账批次'} />
        <StatCard title="账务差异" value={latest?.difference_count ?? 0} valueColor="#dc2626" delta={<span style={{ color: '#dc2626' }}>需人工处理</span>} />
        <StatCard title="对账一致率" value={matchRate} delta={`累计批次 ${runs.length} 个`} />
      </div>

      <div className="xq-tabs">
        <div className={tab === 'diff' ? 'it on' : 'it'} onClick={() => setTab('diff')}>差异处理</div>
        <div className={tab === 'runs' ? 'it on' : 'it'} onClick={() => setTab('runs')}>对账批次</div>
      </div>

      <div className="xq-card xq-table">
        {tab === 'diff' ? (
          <Table rowKey="id" size="middle" loading={loading} columns={diffColumns} dataSource={diffs} pagination={{ pageSize: 10, showTotal: (t) => `共 ${t} 条` }} />
        ) : (
          <Table rowKey="id" size="middle" loading={loading} columns={runColumns} dataSource={runs} pagination={{ pageSize: 10, showTotal: (t) => `共 ${t} 条` }} />
        )}
      </div>

      <Modal title="差异详情" open={!!detail} footer={null} onCancel={() => setDetail(null)} width={520}>
        {detail && (
          <Descriptions
            column={1}
            size="small"
            bordered
            items={[
              { key: 'id', label: '差异单号', children: detail.id },
              { key: 'payment', label: '平台支付单号', children: detail.payment_no },
              { key: 'trade', label: '渠道交易号', children: detail.provider_trade_no ?? '—' },
              { key: 'local', label: '平台金额 / 状态', children: `${yuan(detail.local_amount)} / ${detail.local_state ?? '—'}` },
              { key: 'provider', label: '渠道金额 / 状态', children: `${yuan(detail.provider_amount)} / ${detail.provider_state ?? '—'}` },
              { key: 'result', label: '差异类型', children: reconciliationResultLabels[detail.result] ?? detail.result },
              { key: 'resolution', label: '处置状态', children: reconciliationResolutionLabels[detail.resolution_status] ?? detail.resolution_status },
            ]}
          />
        )}
      </Modal>

      <Modal
        title="处置账务差异"
        open={!!resolveTarget}
        onCancel={() => setResolveTarget(null)}
        onOk={() => resolveForm.submit()}
        confirmLoading={submitting}
        okText="提交处置"
        cancelText="取消"
      >
        <p style={{ color: '#6b7280', fontSize: 13 }}>
          差异单号：{resolveTarget?.id}（平台 {yuan(resolveTarget?.local_amount)} / 渠道 {yuan(resolveTarget?.provider_amount)}）
        </p>
        <Form form={resolveForm} layout="vertical" onFinish={submitResolve} initialValues={{ action: 'confirmed_local_truth' }}>
          <Form.Item name="action" label="处置方式" rules={[{ required: true }]}>
            <Select
              options={[
                { value: 'confirmed_local_truth', label: '补单（以平台记录为准）' },
                { value: 'confirmed_channel_truth', label: '退款/冲正（以渠道记录为准）' },
                { value: 'ignored_with_reason', label: '挂账（说明原因后忽略）' },
              ]}
            />
          </Form.Item>
          <Form.Item name="reason" label="处置原因" rules={[{ required: true, message: '请填写处置原因' }]}>
            <Input.TextArea rows={3} placeholder="将写入对账留痕" />
          </Form.Item>
        </Form>
      </Modal>

      <Modal title="发起对账" open={importOpen} onCancel={() => setImportOpen(false)} onOk={() => importForm.submit()} confirmLoading={submitting} okText="发起" cancelText="取消">
        <Form form={importForm} layout="vertical" onFinish={submitImport}>
          <Form.Item name="businessDate" label="业务日期" rules={[{ required: true, message: '请选择业务日期' }]}>
            <Input type="date" />
          </Form.Item>
          <Form.Item
            name="rows"
            label="渠道流水（JSON 数组）"
            rules={[{ required: true, message: '请粘贴渠道流水 JSON' }]}
            extra='格式：[{"payment_no":"OR20261001001","cash_amount":5900,"state":"succeeded"}]，金额为分'
          >
            <Input.TextArea rows={5} placeholder='[{"payment_no":"OR20261001001","cash_amount":5900,"state":"succeeded"}]' />
          </Form.Item>
        </Form>
      </Modal>
    </div>
  )
}
