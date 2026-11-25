import { Button, Descriptions, Form, Input, Modal, Select, Table, message } from 'antd'
import { useCallback, useEffect, useMemo, useState } from 'react'
import {
  ApiError,
  forwardManagedTask,
  listManagedTasks,
  transitionManagedTask,
  type ManagedTask,
} from '../../api/admin'
import { Links, PageHead, Pill, StatCard, UCell, type PillTone } from '../../components/xq'

const formatDate = (value: string | null | undefined) =>
  value ? new Date(value).toLocaleString('zh-CN', { hour12: false }) : '—'

const statusMeta: Record<string, { label: string; tone: PillTone }> = {
  published: { label: '进行中', tone: 'green' },
  scheduled: { label: '待上线', tone: 'blue' },
  pending_approval: { label: '待审核', tone: 'orange' },
  paused: { label: '已暂停', tone: 'orange' },
  draft: { label: '草稿', tone: 'gray' },
  closed: { label: '已结束', tone: 'gray' },
  expired: { label: '已过期', tone: 'gray' },
  archived: { label: '已归档', tone: 'gray' },
}

const taskTypeLabels: Record<string, string> = {
  one_time: '每人一次',
  daily: '每日成长',
  weekly: '每周成长',
  monthly: '每月成长',
  milestone: '里程碑',
  repeatable: '可重复',
}

function pointsOf(task: ManagedTask) {
  const reward = task.rewards.find((item) => item.type === 'points')
  return reward?.points ?? '—'
}

export function TaskListPage() {
  const [items, setItems] = useState<ManagedTask[]>([])
  const [loading, setLoading] = useState(false)
  const [submitting, setSubmitting] = useState(false)
  const [status, setStatus] = useState('')
  const [category, setCategory] = useState('')
  const [campus, setCampus] = useState('all')
  const [keywordInput, setKeywordInput] = useState('')
  const [keyword, setKeyword] = useState('')
  const [detail, setDetail] = useState<ManagedTask | null>(null)
  const [offShelfTarget, setOffShelfTarget] = useState<ManagedTask | null>(null)
  const [forwardingId, setForwardingId] = useState<string | null>(null)
  const [offShelfForm] = Form.useForm<{ reason: string }>()
  const [messageApi, contextHolder] = message.useMessage()

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const result = await listManagedTasks()
      setItems(result.items)
    } catch (cause) {
      messageApi.error(cause instanceof ApiError ? cause.message : '任务列表加载失败。')
    } finally {
      setLoading(false)
    }
  }, [messageApi])

  useEffect(() => { void load() }, [load])

  const categories = useMemo(
    () => Array.from(new Set(items.map((item) => item.category).filter(Boolean))),
    [items],
  )

  const filtered = useMemo(() => items.filter((item) => {
    if (status && item.status !== status) return false
    if (category && item.category !== category) return false
    if (keyword && !item.title.toLowerCase().includes(keyword.toLowerCase())) return false
    return true
  }), [items, status, category, campus, keyword])

  const stats = useMemo(() => ({
    running: items.filter((item) => item.status === 'published').length,
    scheduled: items.filter((item) => item.status === 'scheduled' || item.status === 'pending_approval').length,
    paused: items.filter((item) => item.status === 'paused').length,
    offShelf: items.filter((item) => ['closed', 'expired', 'archived'].includes(item.status)).length,
  }), [items])

  const offShelf = async (values: { reason: string }) => {
    if (!offShelfTarget) return
    setSubmitting(true)
    try {
      await transitionManagedTask(offShelfTarget.id, 'close', values.reason, 'continue')
      messageApi.success('任务已下架，已领取用户可继续履约。')
      setOffShelfTarget(null)
      await load()
    } catch (cause) {
      messageApi.error(cause instanceof ApiError ? cause.message : '任务下架失败。')
    } finally {
      setSubmitting(false)
    }
  }

  const resume = (task: ManagedTask) => Modal.confirm({
    title: `重新上架“${task.title}”`,
    content: '任务将恢复发布并继续接受领取。',
    okText: '确认上架',
    cancelText: '取消',
    onOk: async () => {
      try {
        await transitionManagedTask(task.id, 'resume', '后台运营重新上架任务。')
        messageApi.success('任务已重新上架。')
        await load()
      } catch (cause) {
        messageApi.error(cause instanceof ApiError ? cause.message : '上架失败。')
      }
    },
  })

  const forwardTask = (task: ManagedTask) => {
    if (forwardingId) return
    Modal.confirm({
      title: '转发任务到群',
      content: `将按当前启用中的「任务」转发配置把「${task.title}」发送到目标群，可能产生重复消息。确认继续？`,
      okText: '确认转发',
      cancelText: '取消',
      onOk: async () => {
        setForwardingId(task.id)
        try {
          const result = await forwardManagedTask(task.id)
          const failed = result.groups.filter((group) => group.status !== 'success')
          if (failed.length) {
            messageApi.warning(`转发完成：成功 ${result.groups.length - failed.length} 个群，失败 ${failed.length} 个群。`)
          } else {
            messageApi.success(`已转发到 ${result.groups.length} 个群。`)
          }
        } catch (cause) {
          messageApi.error(cause instanceof ApiError ? cause.message : '转发失败，请稍后重试。')
        } finally {
          setForwardingId(null)
        }
      },
    })
  }

  return (
    <div className="xq-page">
      {contextHolder}
      <PageHead title="任务管理" extra={<>
        <Button style={{ marginRight: 10 }} onClick={() => messageApi.info('导出能力待接入。')}>导出</Button>
        <Button type="primary" onClick={() => messageApi.info('推荐位配置能力待接入。')}>+ 推荐位配置</Button>
      </>} />

      <div className="xq-stats">
        <StatCard title="进行中任务" value={stats.running} delta="已发布任务" />
        <StatCard title="待上线 / 待审核" value={stats.scheduled} delta="排期与复核中" />
        <StatCard title="已暂停" value={stats.paused} valueColor="#d97706" delta="暂停领取中" />
        <StatCard title="已下架" value={stats.offShelf} valueColor="#dc2626" delta="结束 / 过期 / 归档" />
      </div>

      <div className="xq-card xq-filter" style={{ marginBottom: 16 }}>
        <Select
          value={status || 'all'}
          style={{ minWidth: 130 }}
          onChange={(value) => setStatus(value === 'all' ? '' : value)}
          options={[
            { value: 'all', label: '全部状态' },
            ...Object.entries(statusMeta).map(([value, meta]) => ({ value, label: meta.label })),
          ]}
        />
        <Select
          value={category || 'all'}
          style={{ minWidth: 130 }}
          onChange={(value) => setCategory(value === 'all' ? '' : value)}
          options={[
            { value: 'all', label: '全部类型' },
            ...categories.map((item) => ({ value: item, label: item })),
          ]}
        />
        <Select
          value={campus}
          style={{ minWidth: 130 }}
          onChange={setCampus}
          options={[{ value: 'all', label: '全部校区' }]}
        />
        <Input
          placeholder="任务标题/发布人"
          style={{ maxWidth: 220 }}
          value={keywordInput}
          onChange={(event) => setKeywordInput(event.target.value)}
          onPressEnter={() => setKeyword(keywordInput.trim())}
          allowClear
        />
        <Button type="primary" onClick={() => setKeyword(keywordInput.trim())}>查询</Button>
        <Button onClick={() => { setStatus(''); setCategory(''); setCampus('all'); setKeywordInput(''); setKeyword('') }}>重置</Button>
      </div>

      <div className="xq-card xq-table">
        <Table
          rowKey="id"
          size="middle"
          loading={loading}
          dataSource={filtered}
          pagination={{ pageSize: 10, showTotal: (total) => `共 ${total} 条` }}
          columns={[
            { title: '任务标题', dataIndex: 'title', render: (value: string) => <span style={{ fontWeight: 600 }}>{value}</span> },
            { title: '发布人', render: () => <UCell name="平台运营" sub="官方发布" /> },
            { title: '类型', dataIndex: 'task_type', render: (value: string, item: ManagedTask) => taskTypeLabels[value] ?? item.category ?? value ?? '—' },
            { title: '积分', render: (_: unknown, item: ManagedTask) => pointsOf(item) },
            { title: '领取名额', dataIndex: 'reward_quota_total' },
            {
              title: '状态',
              render: (_: unknown, item: ManagedTask) => {
                const meta = statusMeta[item.status] ?? { label: item.status, tone: 'gray' as PillTone }
                return <Pill tone={meta.tone}>{meta.label}</Pill>
              },
            },
            { title: '推荐位', render: () => <span style={{ color: '#9ca3af' }}>无</span> },
            {
              title: '操作',
              render: (_: unknown, item: ManagedTask) => (
                <Links items={[
                  { label: '详情', onClick: () => setDetail(item) },
                  ['published', 'scheduled'].includes(item.status) && { label: '下架', danger: true, onClick: () => { offShelfForm.resetFields(); setOffShelfTarget(item) } },
                  item.status === 'paused' && { label: '上架', onClick: () => resume(item) },
                  item.status === 'published' && { label: '转发', onClick: () => forwardTask(item) },
                  item.status === 'published' && { label: '催办', onClick: () => messageApi.info('催办能力待接入。') },
                  item.status === 'published' && { label: '介入', onClick: () => messageApi.info('纠纷介入能力待接入。') },
                ]} />
              ),
            },
          ]}
        />
      </div>

      <Modal title="任务详情" width={640} open={detail !== null} footer={null} onCancel={() => setDetail(null)}>
        {detail && (
          <Descriptions column={2} size="small" bordered items={[
            { key: 'title', label: '任务标题', children: detail.title, span: 2 },
            { key: 'category', label: '分类', children: detail.category || '—' },
            { key: 'type', label: '参与频率', children: taskTypeLabels[detail.task_type] ?? detail.task_type },
            { key: 'points', label: '积分', children: pointsOf(detail) },
            { key: 'quota', label: '奖励名额', children: detail.reward_quota_total },
            { key: 'status', label: '状态', children: statusMeta[detail.status]?.label ?? detail.status },
            { key: 'version', label: '版本', children: `v${detail.task_version.version_no}` },
            { key: 'claim', label: '领取时间', children: `${formatDate(detail.claim_starts_at)} ~ ${formatDate(detail.claim_ends_at)}`, span: 2 },
            { key: 'desc', label: '任务说明', children: <span style={{ whiteSpace: 'pre-wrap' }}>{detail.description?.replace(/<[^>]+>/g, '') || '—'}</span>, span: 2 },
          ]} />
        )}
      </Modal>

      <Modal
        title={offShelfTarget ? `下架任务“${offShelfTarget.title}”` : '下架任务'}
        open={offShelfTarget !== null}
        onCancel={() => setOffShelfTarget(null)}
        onOk={() => offShelfForm.submit()}
        okText="确认下架"
        cancelText="取消"
        confirmLoading={submitting}
        destroyOnHidden
      >
        <Form form={offShelfForm} layout="vertical" onFinish={offShelf}>
          <Form.Item name="reason" label="下架原因" rules={[{ required: true, min: 2, message: '请填写下架原因' }]}>
            <Input.TextArea rows={3} maxLength={500} placeholder="下架后停止新领取，已领取用户继续按原截止时间履约" />
          </Form.Item>
        </Form>
      </Modal>
    </div>
  )
}
