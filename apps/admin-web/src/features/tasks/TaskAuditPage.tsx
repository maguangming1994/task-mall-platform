import { Button, Form, Input, Modal, Select, Table, message } from 'antd'
import { useCallback, useEffect, useMemo, useState } from 'react'
import {
  ApiError,
  listTaskSubmissions,
  reviewTaskSubmission,
  startTaskSubmissionReview,
  type ManagedTaskSubmission,
} from '../../api/admin'
import { Links, PageHead, Pill, UCell, type PillTone } from '../../components/xq'

const formatDate = (value: string | null | undefined) =>
  value ? new Date(value).toLocaleString('zh-CN', { hour12: false }) : '—'

const statusMeta: Record<string, { label: string; tone: PillTone }> = {
  submitted: { label: '待审核', tone: 'orange' },
  reviewing: { label: '审核中', tone: 'blue' },
  approved: { label: '已通过', tone: 'green' },
  rejected: { label: '已驳回', tone: 'red' },
  withdrawn: { label: '已撤回', tone: 'gray' },
}

const queueStatuses = ['submitted', 'reviewing', 'approved', 'rejected']

export function TaskAuditPage() {
  const [items, setItems] = useState<ManagedTaskSubmission[]>([])
  const [loading, setLoading] = useState(false)
  const [submitting, setSubmitting] = useState(false)
  const [status, setStatus] = useState('')
  const [taskType, setTaskType] = useState('')
  const [keywordInput, setKeywordInput] = useState('')
  const [keyword, setKeyword] = useState('')
  const [detail, setDetail] = useState<ManagedTaskSubmission | null>(null)
  const [rejectTarget, setRejectTarget] = useState<ManagedTaskSubmission | null>(null)
  const [rejectForm] = Form.useForm<{ note: string }>()
  const [messageApi, contextHolder] = message.useMessage()

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const results = await Promise.all(queueStatuses.map((s) => listTaskSubmissions(s)))
      const merged = results.flatMap((r) => r.items)
      merged.sort((a, b) => (b.submitted_at || '').localeCompare(a.submitted_at || ''))
      setItems(merged)
    } catch (cause) {
      messageApi.error(cause instanceof ApiError ? cause.message : '任务审核列表加载失败。')
    } finally {
      setLoading(false)
    }
  }, [messageApi])

  useEffect(() => { void load() }, [load])

  const filtered = useMemo(() => items.filter((item) => {
    if (status && item.status !== status) return false
    if (keyword && !item.task_title?.toLowerCase().includes(keyword.toLowerCase())) return false
    return true
  }), [items, status, taskType, keyword])

  const pendingItems = useMemo(
    () => filtered.filter((item) => item.status === 'submitted' || item.status === 'reviewing'),
    [filtered],
  )

  const review = async (item: ManagedTaskSubmission, decision: 'approve' | 'reject', note: string) => {
    setSubmitting(true)
    try {
      const target = item.status === 'submitted' ? await startTaskSubmissionReview(item.id) : item
      const scope = target.revision_step_ids?.length ? target.revision_step_ids : target.task_steps.map((step) => step.id)
      const stepResults = scope.map((stepId) => ({ step_id: stepId, decision, note: note || null }))
      await reviewTaskSubmission(target.id, decision, null, note, stepResults)
      messageApi.success(decision === 'approve' ? '已通过该任务提交。' : '已驳回该任务提交。')
      await load()
      return true
    } catch (cause) {
      messageApi.error(cause instanceof ApiError ? cause.message : '审核操作失败。')
      return false
    } finally {
      setSubmitting(false)
    }
  }

  const batchApprove = () => {
    if (!pendingItems.length) return messageApi.info('当前筛选下没有待审核的任务。')
    Modal.confirm({
      title: `批量通过 ${pendingItems.length} 条待审核任务`,
      content: '将逐条提交“通过”结论，全部步骤按通过处理，操作会写入审计日志。',
      okText: '确认批量通过',
      cancelText: '取消',
      onOk: async () => {
        setSubmitting(true)
        let succeeded = 0
        for (const item of pendingItems) {
          try {
            const target = item.status === 'submitted' ? await startTaskSubmissionReview(item.id) : item
            const scope = target.revision_step_ids?.length ? target.revision_step_ids : target.task_steps.map((step) => step.id)
            await reviewTaskSubmission(target.id, 'approve', null, '后台批量通过。', scope.map((stepId) => ({ step_id: stepId, decision: 'approve' as const })))
            succeeded += 1
          } catch { /* 单条失败不阻断后续 */ }
        }
        setSubmitting(false)
        messageApi.success(`批量通过完成：成功 ${succeeded} 条，失败 ${pendingItems.length - succeeded} 条。`)
        await load()
      },
    })
  }

  return (
    <div className="xq-page">
      {contextHolder}
      <PageHead title="任务审核" extra={<Button type="primary" loading={submitting} onClick={batchApprove}>+ 批量通过</Button>} />

      <div className="xq-card xq-filter" style={{ marginBottom: 16 }}>
        <Select
          value={status || 'all'}
          style={{ minWidth: 130 }}
          onChange={(value) => setStatus(value === 'all' ? '' : value)}
          options={[
            { value: 'all', label: '全部状态' },
            { value: 'submitted', label: '待审核' },
            { value: 'reviewing', label: '审核中' },
            { value: 'approved', label: '已通过' },
            { value: 'rejected', label: '已驳回' },
          ]}
        />
        <Select
          value={taskType || 'all'}
          style={{ minWidth: 130 }}
          onChange={(value) => setTaskType(value === 'all' ? '' : value)}
          options={[
            { value: 'all', label: '全部类型' },
            { value: 'errand', label: '跑腿' },
            { value: 'qa', label: '问答' },
            { value: 'agent', label: '代办' },
            { value: 'skill', label: '技能' },
          ]}
        />
        <Input
          placeholder="关键词搜索"
          style={{ maxWidth: 220 }}
          value={keywordInput}
          onChange={(event) => setKeywordInput(event.target.value)}
          onPressEnter={() => setKeyword(keywordInput.trim())}
          allowClear
        />
        <Button type="primary" onClick={() => setKeyword(keywordInput.trim())}>查询</Button>
        <Button onClick={() => { setStatus(''); setTaskType(''); setKeywordInput(''); setKeyword('') }}>重置</Button>
      </div>

      <div className="xq-card xq-table">
        <Table
          rowKey="id"
          size="middle"
          loading={loading}
          dataSource={filtered}
          pagination={{ pageSize: 10, showTotal: (total) => `共 ${total} 条` }}
          columns={[
            { title: '任务标题', dataIndex: 'task_title', render: (value: string) => <span style={{ fontWeight: 600 }}>{value || '—'}</span> },
            {
              title: '发布人',
              render: (_: unknown, item: ManagedTaskSubmission) => (
                <UCell name={`用户 ${item.user_id.slice(-4) || '—'}`} sub={`ID:${item.user_id}`} />
              ),
            },
            { title: '类型', render: () => '—' },
            { title: '积分', render: () => '—' },
            {
              title: '状态',
              render: (_: unknown, item: ManagedTaskSubmission) => {
                const meta = statusMeta[item.status] ?? { label: item.status, tone: 'gray' as PillTone }
                return <Pill tone={meta.tone}>{meta.label}</Pill>
              },
            },
            { title: '提交时间', dataIndex: 'submitted_at', render: (value: string) => formatDate(value) },
            {
              title: '操作',
              render: (_: unknown, item: ManagedTaskSubmission) => {
                const pending = item.status === 'submitted' || item.status === 'reviewing'
                return (
                  <Links items={[
                    { label: '查看', onClick: () => setDetail(item) },
                    pending && { label: '通过', onClick: () => { void review(item, 'approve', '后台审核通过。') } },
                    pending && { label: '驳回', danger: true, onClick: () => { rejectForm.resetFields(); setRejectTarget(item) } },
                  ]} />
                )
              },
            },
          ]}
        />
      </div>

      <Modal title="任务提交详情" open={detail !== null} footer={null} onCancel={() => setDetail(null)}>
        {detail && (
          <div style={{ lineHeight: 2 }}>
            <p><b>任务：</b>{detail.task_title}</p>
            <p><b>提交用户：</b>{detail.user_id}（第 {detail.submission_no} 次提交）</p>
            <p><b>状态：</b>{statusMeta[detail.status]?.label ?? detail.status} · 提交于 {formatDate(detail.submitted_at)}</p>
            <p><b>文字说明：</b>{detail.content?.text || '无'}</p>
            <p><b>附件：</b>{detail.file_ids.length} 个</p>
            <p style={{ marginBottom: 0 }}><b>任务步骤：</b>{detail.task_steps.map((step) => step.title).join('、') || '—'}</p>
          </div>
        )}
      </Modal>

      <Modal
        title="驳回任务提交"
        open={rejectTarget !== null}
        onCancel={() => setRejectTarget(null)}
        onOk={() => rejectForm.submit()}
        okText="确认驳回"
        cancelText="取消"
        confirmLoading={submitting}
        destroyOnHidden
      >
        <Form
          form={rejectForm}
          layout="vertical"
          onFinish={async (values) => {
            if (!rejectTarget) return
            const ok = await review(rejectTarget, 'reject', values.note)
            if (ok) setRejectTarget(null)
          }}
        >
          <Form.Item name="note" label="驳回原因" rules={[{ required: true, min: 2, message: '请填写驳回原因' }]}>
            <Input.TextArea rows={3} maxLength={500} placeholder="说明驳回原因，用户补交时会看到该说明" />
          </Form.Item>
        </Form>
      </Modal>
    </div>
  )
}
