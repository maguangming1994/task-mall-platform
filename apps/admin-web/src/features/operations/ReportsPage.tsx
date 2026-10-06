import { Button, Input, Modal, Select, Table, message } from 'antd'
import { useCallback, useEffect, useMemo, useState } from 'react'
import {
  ApiError,
  listCommunityAppeals,
  listCommunityReports,
  reviewCommunityAppeal,
  type ManagedContentAppeal,
  type ManagedContentReport,
} from '../../api/admin'
import { Links, PageHead, Pill, StatCard, type PillTone } from '../../components/xq'

const reasonLabels: Record<string, string> = {
  spam: '垃圾广告',
  sexual: '色情低俗',
  fraud: '诈骗信息',
  abuse: '人身攻击',
  infringement: '侵权内容',
  illegal: '违法违规',
  content_not_compliant: '内容违规',
  other: '其他',
}
const reasonLabel = (code: string) => reasonLabels[code] ?? code

type Row =
  | { kind: 'report'; key: string; report: ManagedContentReport }
  | { kind: 'appeal'; key: string; appeal: ManagedContentAppeal }

function statusOf(row: Row): { key: string; label: string; tone: PillTone } {
  if (row.kind === 'report') {
    return row.report.status === 'submitted'
      ? { key: 'pending', label: '待处理', tone: 'orange' }
      : { key: 'resolved', label: '已处理', tone: 'green' }
  }
  if (row.appeal.status === 'submitted') return { key: 'appealing', label: '申诉中', tone: 'blue' }
  return row.appeal.status === 'approved'
    ? { key: 'appeal_done', label: '申诉成立', tone: 'green' }
    : { key: 'appeal_done', label: '申诉驳回', tone: 'gray' }
}

const timeOf = (row: Row) => (row.kind === 'report' ? row.report.created_at : row.appeal.created_at)
const isPending = (row: Row) => {
  const key = statusOf(row).key
  return key === 'pending' || key === 'appealing'
}
const fmtTime = (value: string) =>
  new Date(value).toLocaleString('zh-CN', { year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hour12: false }).replace(/\//g, '-')

export function ReportsPage() {
  const [reports, setReports] = useState<ManagedContentReport[]>([])
  const [appeals, setAppeals] = useState<ManagedContentAppeal[]>([])
  const [loading, setLoading] = useState(true)
  const [status, setStatus] = useState('')
  const [reason, setReason] = useState('')
  const [kw, setKw] = useState('')
  const [applied, setApplied] = useState({ status: '', reason: '', kw: '' })
  const [detail, setDetail] = useState<Row | null>(null)
  const [reviewing, setReviewing] = useState<ManagedContentAppeal | null>(null)
  const [submitting, setSubmitting] = useState(false)
  const [messageApi, contextHolder] = message.useMessage()

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const [reportResult, appealResult] = await Promise.all([listCommunityReports(), listCommunityAppeals()])
      setReports(reportResult.items)
      setAppeals(appealResult.items)
    } catch (error) {
      messageApi.error(error instanceof ApiError ? error.message : '举报数据加载失败。')
    } finally {
      setLoading(false)
    }
  }, [messageApi])

  useEffect(() => { void load() }, [load])

  const rows = useMemo<Row[]>(() => {
    const all: Row[] = [
      ...reports.map((report) => ({ kind: 'report' as const, key: `r-${report.id}`, report })),
      ...appeals.map((appeal) => ({ kind: 'appeal' as const, key: `a-${appeal.id}`, appeal })),
    ]
    return all
      .filter((row) => (applied.status ? statusOf(row).key === applied.status : true))
      .filter((row) => (applied.reason ? row.kind === 'report' && row.report.reason_code === applied.reason : true))
      .filter((row) => {
        if (!applied.kw) return true
        const text = row.kind === 'report' ? `${row.report.description ?? ''}${row.report.content_id}` : `${row.appeal.reason}${row.appeal.action_id}`
        return text.includes(applied.kw)
      })
      .sort((a, b) => {
        const pa = isPending(a) ? 0 : 1
        const pb = isPending(b) ? 0 : 1
        if (pa !== pb) return pa - pb
        return timeOf(b).localeCompare(timeOf(a))
      })
  }, [reports, appeals, applied])

  const pendingCount = reports.filter((item) => item.status === 'submitted').length
  const todayKey = new Date().toDateString()
  const resolvedTotal = reports.filter((item) => item.status !== 'submitted').length
  const resolvedToday = reports.filter((item) => item.status !== 'submitted' && new Date(item.created_at).toDateString() === todayKey).length
  const appealPending = appeals.filter((item) => item.status === 'submitted').length

  const reviewAppeal = async (decision: 'approve' | 'reject') => {
    if (!reviewing) return
    setSubmitting(true)
    try {
      await reviewCommunityAppeal(reviewing.id, decision)
      messageApi.success(decision === 'approve' ? '已撤销原处置，内容与限制已恢复。' : '已维持原处置。')
      setReviewing(null)
      await load()
    } catch (error) {
      messageApi.error(error instanceof ApiError ? error.message : '复核提交失败。')
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <div className="xq-page">
      {contextHolder}
      <PageHead title="举报处理" />

      <div className="xq-stats">
        <StatCard title="待处理举报" value={pendingCount} valueColor={pendingCount > 0 ? '#f59e0b' : undefined} delta={pendingCount > 0 ? '需尽快核实处置' : '暂无待处理举报'} />
        <StatCard title="今日已处理" value={resolvedToday} delta={`累计已处理 ${resolvedTotal} 件`} />
        <StatCard title="被处罚用户" value="—" delta="处分统计接口未开放" />
        <StatCard title="申诉待处理" value={appealPending} valueColor={appealPending > 0 ? '#dc2626' : undefined} delta={appealPending > 0 ? '需复核' : '暂无待复核申诉'} />
      </div>

      <div className="xq-card xq-filter" style={{ marginBottom: 16 }}>
        <Select
          allowClear
          placeholder="全部状态"
          value={status || undefined}
          onChange={(value) => { const next = value ?? ''; setStatus(next); setApplied((prev) => ({ ...prev, status: next })) }}
          options={[
            { value: 'pending', label: '待处理' },
            { value: 'resolved', label: '已处理' },
            { value: 'appealing', label: '申诉中' },
            { value: 'appeal_done', label: '申诉已复核' },
          ]}
        />
        <Select allowClear placeholder="举报类型" value={reason || undefined} onChange={(value) => { const next = value ?? ''; setReason(next); setApplied((prev) => ({ ...prev, reason: next })) }} options={Object.entries(reasonLabels).map(([value, label]) => ({ value, label }))} />
        <Input allowClear placeholder="被举报内容/用户" value={kw} onChange={(event) => setKw(event.target.value)} onPressEnter={() => setApplied({ status, reason, kw: kw.trim() })} />
        <Button type="primary" onClick={() => setApplied({ status, reason, kw: kw.trim() })}>查询</Button>
      </div>

      <div className="xq-card xq-table">
        <Table<Row>
          rowKey="key"
          size="middle"
          loading={loading}
          dataSource={rows}
          pagination={{ pageSize: 10, showTotal: (total) => `共 ${total} 条` }}
          columns={[
            {
              title: '被举报内容',
              render: (_: unknown, row: Row) =>
                row.kind === 'report' ? (
                  <span>
                    {row.report.description || reasonLabel(row.report.reason_code)}
                    <span style={{ color: 'var(--xq-muted)', fontSize: 12 }}>（{row.report.content_type === 'post' ? '帖子' : '评论'}）</span>
                  </span>
                ) : (
                  <span>
                    申诉：{row.appeal.reason}
                    <span style={{ color: 'var(--xq-muted)', fontSize: 12 }}>（处置单 {row.appeal.action_id.slice(0, 8)}…）</span>
                  </span>
                ),
            },
            { title: '被举报人', width: 120, render: () => '—' },
            { title: '举报人', width: 110, render: () => '—' },
            { title: '类型', width: 110, render: (_: unknown, row: Row) => (row.kind === 'report' ? reasonLabel(row.report.reason_code) : '用户申诉') },
            { title: '状态', width: 100, render: (_: unknown, row: Row) => { const s = statusOf(row); return <Pill tone={s.tone}>{s.label}</Pill> } },
            { title: '举报时间', width: 150, render: (_: unknown, row: Row) => fmtTime(timeOf(row)) },
            {
              title: '操作',
              width: 120,
              render: (_: unknown, row: Row) => (
                <Links
                  items={[
                    { label: '查看', onClick: () => setDetail(row) },
                    row.kind === 'appeal' && row.appeal.status === 'submitted' && { label: '复核', onClick: () => setReviewing(row.appeal) },
                  ]}
                />
              ),
            },
          ]}
        />
      </div>

      <Modal title={detail?.kind === 'appeal' ? '申诉详情' : '举报详情'} open={Boolean(detail)} onCancel={() => setDetail(null)} footer={<Button type="primary" onClick={() => setDetail(null)}>关闭</Button>}>
        {detail && (
          <div style={{ fontSize: 13, lineHeight: 2.1 }}>
            {detail.kind === 'report' ? (
              <>
                <div><b>内容类型：</b>{detail.report.content_type === 'post' ? '帖子' : '评论'}</div>
                <div><b>内容 ID：</b>{detail.report.content_id}</div>
                <div><b>举报类型：</b>{reasonLabel(detail.report.reason_code)}</div>
                <div><b>举报描述：</b>{detail.report.description || '未填写'}</div>
                <div><b>状态：</b>{detail.report.status === 'submitted' ? '待处理' : '已处理'}</div>
                <div><b>举报时间：</b>{fmtTime(detail.report.created_at)}</div>
              </>
            ) : (
              <>
                <div><b>处置单 ID：</b>{detail.appeal.action_id}</div>
                <div><b>申诉理由：</b>{detail.appeal.reason}</div>
                <div><b>状态：</b>{statusOf(detail).label}</div>
                {detail.appeal.review_note && <div><b>复核备注：</b>{detail.appeal.review_note}</div>}
                <div><b>申诉时间：</b>{fmtTime(detail.appeal.created_at)}</div>
              </>
            )}
          </div>
        )}
      </Modal>

      <Modal title="申诉复核" open={Boolean(reviewing)} onCancel={() => setReviewing(null)} footer={null} destroyOnHidden>
        {reviewing && (
          <div style={{ fontSize: 13, lineHeight: 2.1 }}>
            <div><b>处置单 ID：</b>{reviewing.action_id}</div>
            <div><b>申诉理由：</b>{reviewing.reason}</div>
            <div style={{ marginTop: 18, display: 'flex', gap: 12, justifyContent: 'flex-end' }}>
              <Button loading={submitting} onClick={() => void reviewAppeal('approve')}>撤销原处置</Button>
              <Button type="primary" danger loading={submitting} onClick={() => void reviewAppeal('reject')}>维持原处置</Button>
            </div>
          </div>
        )}
      </Modal>
    </div>
  )
}
