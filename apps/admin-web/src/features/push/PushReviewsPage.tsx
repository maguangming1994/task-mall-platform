import { useCallback, useEffect, useState } from 'react'
import { Button, Input, Modal, Select, Table, message } from 'antd'
import type { ColumnsType } from 'antd/es/table'
import {
  listPushApplications,
  listPushSubmissions,
  reviewPushApplication,
  reviewPushSubmission,
  type PushApplicationItem,
  type PushApplicationList,
  type PushSubmissionItem,
  type PushSubmissionList,
} from '../../api/admin'
import { Links, PageHead, Pill, StatCard, type PillTone } from '../../components/xq'

const reviewStatus: Record<string, { tone: PillTone; label: string }> = {
  pending: { tone: 'orange', label: '待审核' },
  approved: { tone: 'green', label: '已通过' },
  rejected: { tone: 'red', label: '已驳回' },
}
const statusPill = (value: string) => reviewStatus[value] ?? { tone: 'gray' as PillTone, label: value }
const fmtTime = (value: string | null) => (value ? new Date(value).toLocaleString('zh-CN', { hour12: false }) : '—')

type ReviewKind = 'application' | 'submission'
interface ReviewTarget { kind: ReviewKind; id: string; title: string; decision: 'approve' | 'reject' }

export function PushReviewsPage() {
  const [messageApi, contextHolder] = message.useMessage()
  const [tab, setTab] = useState<ReviewKind>('application')
  const [status, setStatus] = useState('pending')
  const [applications, setApplications] = useState<PushApplicationList | null>(null)
  const [submissions, setSubmissions] = useState<PushSubmissionList | null>(null)
  const [loading, setLoading] = useState(false)
  const [target, setTarget] = useState<ReviewTarget | null>(null)
  const [note, setNote] = useState('')
  const [submitting, setSubmitting] = useState(false)

  const load = useCallback(async () => {
    setLoading(true)
    try {
      if (tab === 'application') {
        setApplications(await listPushApplications({ status, page_size: 50 }))
      } else {
        setSubmissions(await listPushSubmissions({ status, page_size: 50 }))
      }
    } catch {
      messageApi.error('审核数据加载失败，请稍后重试。')
    } finally {
      setLoading(false)
    }
  }, [tab, status, messageApi])

  useEffect(() => { void load() }, [load])

  const openReview = (item: PushApplicationItem | PushSubmissionItem, kind: ReviewKind, decision: 'approve' | 'reject') => {
    setTarget({ kind, id: item.id, title: kind === 'application' ? '入团申请' : '交单记录', decision })
    setNote('')
  }

  const submitReview = async () => {
    if (!target) return
    if (target.decision === 'reject' && !note.trim()) {
      messageApi.warning('驳回时必须填写原因。')
      return
    }
    setSubmitting(true)
    try {
      if (target.kind === 'application') {
        await reviewPushApplication(target.id, { decision: target.decision, note: note.trim() || undefined })
      } else {
        await reviewPushSubmission(target.id, { decision: target.decision, reason: note.trim() || undefined })
      }
      messageApi.success(target.decision === 'approve' ? '已通过审核。' : '已驳回。')
      setTarget(null)
      await load()
    } catch {
      messageApi.error('审核失败，请稍后重试。')
    } finally {
      setSubmitting(false)
    }
  }

  const applicationColumns: ColumnsType<PushApplicationItem> = [
    {
      title: '申请人',
      render: (_, row) => (
        <span>
          <span style={{ fontWeight: 600 }}>{row.user?.nickname || '—'}</span>
          <span style={{ display: 'block', color: '#9ca3af', fontSize: 11 }}>{row.type === 'leader' ? '申请成为团长' : `申请加入 ${row.team?.name || '团队'}`}</span>
        </span>
      ),
    },
    { title: '申请说明', render: (_, row) => row.message || '—' },
    { title: '提交时间', width: 170, render: (_, row) => fmtTime(row.created_at) },
    { title: '状态', width: 90, render: (_, row) => <Pill tone={statusPill(row.status).tone}>{statusPill(row.status).label}</Pill> },
    {
      title: '操作',
      width: 140,
      render: (_, row) => row.status === 'pending' ? (
        <Links items={[
          { label: '通过', onClick: () => openReview(row, 'application', 'approve') },
          { label: '驳回', danger: true, onClick: () => openReview(row, 'application', 'reject') },
        ]} />
      ) : <span style={{ color: '#9ca3af' }}>{row.review_note || '—'}</span>,
    },
  ]

  const submissionColumns: ColumnsType<PushSubmissionItem> = [
    {
      title: '交单',
      render: (_, row) => (
        <span>
          <span style={{ fontWeight: 600 }}>{row.task?.title || '—'}</span>
          <span style={{ display: 'block', color: '#9ca3af', fontSize: 11 }}>{row.user?.nickname || '—'} · {row.level_code} · ¥{row.commission}</span>
        </span>
      ),
    },
    {
      title: '凭证',
      width: 100,
      render: (_, row) => row.images.length
        ? <span>已上传 {row.images.length} 张</span>
        : <span style={{ color: '#9ca3af' }}>无</span>,
    },
    { title: '备注', render: (_, row) => row.remark || '—' },
    { title: '提交时间', width: 170, render: (_, row) => fmtTime(row.created_at) },
    { title: '状态', width: 90, render: (_, row) => <Pill tone={statusPill(row.status).tone}>{statusPill(row.status).label}</Pill> },
    {
      title: '操作',
      width: 140,
      render: (_, row) => row.status === 'pending' ? (
        <Links items={[
          { label: '通过', onClick: () => openReview(row, 'submission', 'approve') },
          { label: '驳回', danger: true, onClick: () => openReview(row, 'submission', 'reject') },
        ]} />
      ) : <span style={{ color: '#9ca3af' }}>{row.reject_reason || '—'}</span>,
    },
  ]

  return (
    <div className="xq-page">
      {contextHolder}
      <PageHead title="地推申请与交单审核" />
      <div className="xq-stats">
        <StatCard title="待审入团申请" value={applications?.summary.pending ?? '—'} valueColor="#d97706" />
        <StatCard title="待审团长申请" value={applications?.summary.leader_pending ?? '—'} valueColor="#d97706" />
        <StatCard title="待审交单" value={submissions?.summary.pending ?? '—'} valueColor="#d97706" />
        <StatCard title="已通过交单" value={submissions?.summary.approved ?? '—'} />
      </div>
      <div className="xq-tabs">
        <div className={tab === 'application' ? 'it on' : 'it'} onClick={() => { setTab('application'); setStatus('pending') }}>入团与团长申请</div>
        <div className={tab === 'submission' ? 'it on' : 'it'} onClick={() => { setTab('submission'); setStatus('pending') }}>交单审核</div>
      </div>
      <div className="xq-card xq-filter" style={{ marginBottom: 16 }}>
        <Select
          style={{ width: 140 }}
          value={status}
          onChange={setStatus}
          options={Object.entries(reviewStatus).map(([value, item]) => ({ value, label: item.label }))}
        />
        <Button onClick={() => setStatus('pending')}>只看待审</Button>
      </div>
      <div className="xq-card xq-table">
        {tab === 'application' ? (
          <Table<PushApplicationItem>
            rowKey="id"
            loading={loading}
            columns={applicationColumns}
            dataSource={applications?.items ?? []}
            pagination={{ pageSize: 20, showSizeChanger: false }}
          />
        ) : (
          <Table<PushSubmissionItem>
            rowKey="id"
            loading={loading}
            columns={submissionColumns}
            dataSource={submissions?.items ?? []}
            pagination={{ pageSize: 20, showSizeChanger: false }}
          />
        )}
      </div>
      <Modal
        title={`${target?.decision === 'approve' ? '通过' : '驳回'}${target?.title || ''}`}
        open={target !== null}
        confirmLoading={submitting}
        onOk={() => void submitReview()}
        onCancel={() => setTarget(null)}
        okText={target?.decision === 'approve' ? '确认通过' : '确认驳回'}
        okButtonProps={{ danger: target?.decision === 'reject' }}
      >
        <Input.TextArea
          rows={3}
          maxLength={300}
          value={note}
          onChange={(event) => setNote(event.target.value)}
          placeholder={target?.decision === 'reject' ? '请填写驳回原因（必填）' : '审核备注（选填）'}
        />
      </Modal>
    </div>
  )
}
