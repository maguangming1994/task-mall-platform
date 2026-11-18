import { useCallback, useEffect, useState } from 'react'
import { Button, Form, Input, InputNumber, Modal, Select, Table, message } from 'antd'
import type { ColumnsType } from 'antd/es/table'
import {
  createPushTask,
  listPushTasks,
  updatePushTask,
  type PushTaskInput,
  type PushTaskItem,
  type PushTaskList,
} from '../../api/admin'
import { Links, PageHead, Pill, type PillTone } from '../../components/xq'

const taskStatus: Record<string, { tone: PillTone; label: string }> = {
  draft: { tone: 'gray', label: '草稿' },
  published: { tone: 'green', label: '推广中' },
  closed: { tone: 'red', label: '已关闭' },
}
const statusPill = (value: string) => taskStatus[value] ?? { tone: 'gray' as PillTone, label: value }

const fmtDate = (value: string | null) => (value ? new Date(value).toLocaleDateString('zh-CN') : '—')

interface TaskFormValue {
  title: string
  description?: string
  cover_text?: string
  reward_amount: number
  quota: number
  per_user_limit: number
  starts_at?: string
  ends_at?: string
  status: string
}

export function PushTasksPage() {
  const [messageApi, contextHolder] = message.useMessage()
  const [data, setData] = useState<PushTaskList | null>(null)
  const [loading, setLoading] = useState(false)
  const [keyword, setKeyword] = useState('')
  const [status, setStatus] = useState('')
  const [page, setPage] = useState(1)
  const [open, setOpen] = useState(false)
  const [editing, setEditing] = useState<PushTaskItem | null>(null)
  const [form] = Form.useForm<TaskFormValue>()

  const load = useCallback(async () => {
    setLoading(true)
    try {
      setData(await listPushTasks({ keyword, status, page, page_size: 20 }))
    } catch {
      messageApi.error('推广任务加载失败，请稍后重试。')
    } finally {
      setLoading(false)
    }
  }, [keyword, status, page, messageApi])

  useEffect(() => { void load() }, [load])

  const openCreate = () => {
    setEditing(null)
    form.setFieldsValue({ reward_amount: 8, quota: 100, per_user_limit: 1, status: 'draft' })
    setOpen(true)
  }

  const openEdit = (task: PushTaskItem) => {
    setEditing(task)
    form.setFieldsValue({
      title: task.title,
      description: task.description ?? '',
      cover_text: task.cover_text ?? '',
      reward_amount: Number(task.reward_amount),
      quota: task.quota,
      per_user_limit: task.per_user_limit,
      starts_at: task.starts_at ? task.starts_at.slice(0, 10) : undefined,
      ends_at: task.ends_at ? task.ends_at.slice(0, 10) : undefined,
      status: task.status,
    })
    setOpen(true)
  }

  const submit = async () => {
    const values = await form.validateFields()
    const payload: PushTaskInput = { ...values, starts_at: values.starts_at ?? null, ends_at: values.ends_at ?? null }
    try {
      if (editing) {
        await updatePushTask(editing.id, payload)
        messageApi.success('推广任务已更新。')
      } else {
        await createPushTask(payload)
        messageApi.success('推广任务已创建。')
      }
      setOpen(false)
      await load()
    } catch {
      messageApi.error('保存失败，请检查推广单价与时间。')
    }
  }

  const changeStatus = async (task: PushTaskItem, next: 'published' | 'closed') => {
    try {
      await updatePushTask(task.id, { status: next })
      messageApi.success(next === 'published' ? '任务已发布。' : '任务已关闭。')
      await load()
    } catch {
      messageApi.error('操作失败，请稍后重试。')
    }
  }

  const columns: ColumnsType<PushTaskItem> = [
    {
      title: '推广任务',
      render: (_, row) => (
        <span>
          <span style={{ fontWeight: 600 }}>{row.title}</span>
          <span style={{ display: 'block', color: '#9ca3af', fontSize: 11 }}>{row.cover_text || '—'} · 限领 {row.per_user_limit} 次/人</span>
        </span>
      ),
    },
    { title: '推广单价', width: 110, render: (_, row) => `¥${row.reward_amount}` },
    {
      title: '名额',
      width: 120,
      render: (_, row) => `${row.claimed_count}/${row.quota > 0 ? row.quota : '不限'}`,
    },
    { title: '开始', width: 110, render: (_, row) => fmtDate(row.starts_at) },
    { title: '结束', width: 110, render: (_, row) => fmtDate(row.ends_at) },
    { title: '状态', width: 90, render: (_, row) => <Pill tone={statusPill(row.status).tone}>{statusPill(row.status).label}</Pill> },
    {
      title: '操作',
      width: 140,
      render: (_, row) => (
        <Links
          items={[
            { label: '编辑', onClick: () => openEdit(row) },
            row.status === 'published'
              ? { label: '关闭', danger: true, onClick: () => void changeStatus(row, 'closed') }
              : { label: '发布', onClick: () => void changeStatus(row, 'published') },
          ]}
        />
      ),
    },
  ]

  return (
    <div className="xq-page">
      {contextHolder}
      <PageHead
        title="地推推广任务"
        extra={<Button type="primary" onClick={openCreate}>新建推广任务</Button>}
      />
      <div className="xq-card xq-filter" style={{ marginBottom: 16 }}>
        <Input.Search
          allowClear
          placeholder="搜索任务标题"
          style={{ width: 240 }}
          onSearch={(value) => { setKeyword(value); setPage(1) }}
        />
        <Select
          allowClear
          placeholder="全部状态"
          style={{ width: 140 }}
          value={status || undefined}
          onChange={(value) => { setStatus(value ?? ''); setPage(1) }}
          options={Object.entries(taskStatus).map(([value, item]) => ({ value, label: item.label }))}
        />
      </div>
      <div className="xq-card xq-table">
        <Table<PushTaskItem>
          rowKey="id"
          loading={loading}
          columns={columns}
          dataSource={data?.items ?? []}
          pagination={{
            current: page,
            pageSize: 20,
            total: data?.pagination.total ?? 0,
            onChange: setPage,
            showSizeChanger: false,
          }}
        />
      </div>
      <Modal
        title={editing ? '编辑推广任务' : '新建推广任务'}
        open={open}
        onOk={() => void submit()}
        onCancel={() => setOpen(false)}
        destroyOnClose
      >
        <Form form={form} layout="vertical">
          <Form.Item name="title" label="任务标题" rules={[{ required: true, message: '请输入任务标题' }]}>
            <Input maxLength={160} />
          </Form.Item>
          <Form.Item name="description" label="任务说明">
            <Input.TextArea rows={3} maxLength={2000} />
          </Form.Item>
          <Form.Item name="cover_text" label="封面文字" extra="列表卡片使用的短标识，如「极速版」">
            <Input maxLength={24} />
          </Form.Item>
          <Form.Item name="reward_amount" label="推广单价（元）" rules={[{ required: true }]}>
            <InputNumber min={0.01} max={100000} step={0.01} precision={2} style={{ width: '100%' }} />
          </Form.Item>
          <Form.Item name="quota" label="推广位名额" rules={[{ required: true }]} extra="0 表示不限名额">
            <InputNumber min={0} style={{ width: '100%' }} />
          </Form.Item>
          <Form.Item name="per_user_limit" label="每人可领次数" rules={[{ required: true }]}>
            <InputNumber min={1} max={100} style={{ width: '100%' }} />
          </Form.Item>
          <Form.Item name="starts_at" label="开始日期">
            <Input type="date" />
          </Form.Item>
          <Form.Item name="ends_at" label="结束日期">
            <Input type="date" />
          </Form.Item>
          <Form.Item name="status" label="状态" rules={[{ required: true }]}>
            <Select options={Object.entries(taskStatus).map(([value, item]) => ({ value, label: item.label }))} />
          </Form.Item>
        </Form>
      </Modal>
    </div>
  )
}
