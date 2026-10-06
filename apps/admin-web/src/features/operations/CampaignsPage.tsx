import { useCallback, useEffect, useMemo, useState } from 'react'
import { Button, DatePicker, Form, Input, Modal, Select, Table, message } from 'antd'
import type { ColumnsType } from 'antd/es/table'
import {
  cancelNotificationCampaign,
  createNotificationCampaign,
  listNotificationCampaigns,
  listNotificationTemplates,
  type ManagedNotificationCampaign,
  type ManagedNotificationTemplate,
} from '../../api/admin'
import { Links, PageHead, Pill, type PillTone } from '../../components/xq'

const fmtTime = (v?: string | null) => (v ? new Date(v).toLocaleString('zh-CN', { hour12: false }) : '—')
const fmtDate = (v?: string | null) => (v ? new Date(v).toLocaleDateString('zh-CN') : '—')

const channelLabels: Record<string, string> = {
  in_app: '站内消息',
  wechat: '微信消息',
  wechat_mini: '小程序订阅消息',
  sms: '短信',
}
const channelName = (v: string) => channelLabels[v] ?? v

const campaignPills: Record<string, { tone: PillTone; label: string }> = {
  draft: { tone: 'gray', label: '草稿' },
  ready: { tone: 'blue', label: '待上线' },
  scheduled: { tone: 'blue', label: '待上线' },
  sending: { tone: 'green', label: '进行中' },
  completed: { tone: 'gray', label: '已结束' },
  cancelled: { tone: 'red', label: '已取消' },
  failed: { tone: 'red', label: '发送失败' },
}
const campaignPill = (s: string) => campaignPills[s] ?? { tone: 'gray' as PillTone, label: s }

interface CreateFormValue { name: string; title: string; body: string; channels: string[]; scheduledAt?: string }

export function CampaignsPage() {
  const [messageApi, contextHolder] = message.useMessage()
  const [tab, setTab] = useState<'slots' | 'templates'>('slots')
  const [campaigns, setCampaigns] = useState<ManagedNotificationCampaign[]>([])
  const [templates, setTemplates] = useState<ManagedNotificationTemplate[]>([])
  const [channel, setChannel] = useState('')
  const [status, setStatus] = useState('')
  const [applied, setApplied] = useState({ channel: '', status: '' })
  const [loading, setLoading] = useState(false)
  const [createOpen, setCreateOpen] = useState(false)
  const [editTarget, setEditTarget] = useState<ManagedNotificationCampaign | null>(null)
  const [submitting, setSubmitting] = useState(false)
  const [form] = Form.useForm<CreateFormValue>()

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const [c, t] = await Promise.all([listNotificationCampaigns(), listNotificationTemplates()])
      setCampaigns(c.items)
      setTemplates(t.items)
    } catch {
      messageApi.error('活动运营数据加载失败，请稍后重试。')
    } finally {
      setLoading(false)
    }
  }, [messageApi])

  useEffect(() => { void load() }, [load])

  const rows = useMemo(() => campaigns.filter((c) => {
    if (applied.channel && !c.channels.includes(applied.channel)) return false
    if (applied.status && c.status !== applied.status) return false
    return true
  }), [campaigns, applied])

  const offline = (c: ManagedNotificationCampaign) => {
    Modal.confirm({
      title: `下线活动「${c.name}」？`,
      content: '下线后该活动将立即停止投放。',
      okText: '确认下线',
      cancelText: '取消',
      onOk: async () => {
        try {
          await cancelNotificationCampaign(c.id, '运营下线')
          messageApi.success('活动已下线。')
          await load()
        } catch {
          messageApi.error('下线失败，请稍后重试。')
        }
      },
    })
  }

  const submitCreate = async (values: CreateFormValue) => {
    setSubmitting(true)
    try {
      await createNotificationCampaign({
        name: values.name.trim(),
        title: values.title.trim(),
        body: values.body.trim(),
        channels: values.channels,
        scheduled_at: values.scheduledAt ? new Date(values.scheduledAt).toISOString() : undefined,
      })
      messageApi.success('活动位已创建。')
      setCreateOpen(false)
      form.resetFields()
      await load()
    } catch {
      messageApi.error('创建失败，请检查参数后重试。')
    } finally {
      setSubmitting(false)
    }
  }

  const campaignColumns: ColumnsType<ManagedNotificationCampaign> = [
    {
      title: '活动名称',
      render: (_, r) => (
        <span>
          <span style={{ fontWeight: 600 }}>{r.name}</span>
          <span style={{ display: 'block', color: '#9ca3af', fontSize: 11 }}>创建：{fmtDate(r.created_at)}</span>
        </span>
      ),
    },
    { title: '位置', render: (_, r) => r.channels.map(channelName).join('、') || '—' },
    { title: '跳转', render: (_, r) => <span style={{ color: '#6b7280' }}>{r.title || '—'}</span> },
    { title: '有效期', render: (_, r) => `${fmtDate(r.scheduled_at)} ~ ${r.completed_at ? fmtDate(r.completed_at) : '长期'}` },
    { title: '状态', width: 100, render: (_, r) => { const p = campaignPill(r.status); return <Pill tone={p.tone}>{p.label}</Pill> } },
    {
      title: '操作',
      render: (_, r) => (
        <Links items={[
          { label: '编辑', onClick: () => setEditTarget(r) },
          ['draft', 'ready', 'scheduled'].includes(r.status) && { label: '下线', danger: true, onClick: () => offline(r) },
        ]} />
      ),
    },
  ]

  const templateColumns: ColumnsType<ManagedNotificationTemplate> = [
    { title: '场景编码', dataIndex: 'scene_code' },
    { title: '渠道', render: (_, r) => channelName(r.channel) },
    {
      title: '状态',
      width: 100,
      render: (_, r) => (r.status === 'published' ? <Pill tone="green">已发布</Pill> : <Pill tone="gray">草稿</Pill>),
    },
    { title: '当前版本', render: (_, r) => `v${r.current_version_no}` },
    { title: '主题', render: (_, r) => r.current_version?.subject ?? '—', ellipsis: true },
    { title: '更新时间', render: (_, r) => fmtTime(r.updated_at) },
  ]

  return (
    <div className="xq-page">
      {contextHolder}
      <PageHead title="活动运营" extra={<Button type="primary" onClick={() => setCreateOpen(true)}>+ 新增活动位</Button>} />

      <div className="xq-tabs">
        <div className={tab === 'slots' ? 'it on' : 'it'} onClick={() => setTab('slots')}>活动位管理</div>
        <div className={tab === 'templates' ? 'it on' : 'it'} onClick={() => setTab('templates')}>通知模板</div>
      </div>

      {tab === 'slots' && (
        <div className="xq-card xq-filter" style={{ marginBottom: 16 }}>
          <Select
            value={channel || undefined}
            onChange={(v) => setChannel(v ?? '')}
            allowClear
            placeholder="全部位置"
            style={{ minWidth: 120 }}
            options={Object.entries(channelLabels).map(([value, label]) => ({ value, label }))}
          />
          <Select
            value={status || undefined}
            onChange={(v) => setStatus(v ?? '')}
            allowClear
            placeholder="全部状态"
            style={{ minWidth: 120 }}
            options={[
              { value: 'scheduled', label: '待上线' },
              { value: 'sending', label: '进行中' },
              { value: 'completed', label: '已结束' },
              { value: 'cancelled', label: '已取消' },
            ]}
          />
          <Button type="primary" onClick={() => setApplied({ channel, status })}>查询</Button>
          <Button onClick={() => { setChannel(''); setStatus(''); setApplied({ channel: '', status: '' }) }}>重置</Button>
        </div>
      )}

      <div className="xq-card xq-table">
        {tab === 'slots' ? (
          <Table rowKey="id" size="middle" loading={loading} columns={campaignColumns} dataSource={rows} pagination={{ pageSize: 10, showTotal: (t) => `共 ${t} 条` }} />
        ) : (
          <Table rowKey="id" size="middle" loading={loading} columns={templateColumns} dataSource={templates} pagination={{ pageSize: 10, showTotal: (t) => `共 ${t} 条` }} />
        )}
      </div>

      <Modal title="新增活动位" open={createOpen} onCancel={() => setCreateOpen(false)} onOk={() => form.submit()} confirmLoading={submitting} okText="创建" cancelText="取消">
        <Form form={form} layout="vertical" onFinish={submitCreate}>
          <Form.Item name="name" label="活动名称" rules={[{ required: true, message: '请输入活动名称' }]}>
            <Input placeholder="例如：国庆校园任务周" maxLength={30} />
          </Form.Item>
          <Form.Item name="title" label="跳转/标题" rules={[{ required: true, message: '请输入跳转标题' }]}>
            <Input placeholder="例如：/pages/tasks 或 通知标题" maxLength={60} />
          </Form.Item>
          <Form.Item name="body" label="活动内容" rules={[{ required: true, message: '请输入活动内容' }]}>
            <Input.TextArea rows={3} placeholder="投放的文案内容" />
          </Form.Item>
          <Form.Item name="channels" label="投放位置" rules={[{ required: true, message: '请选择投放位置' }]}>
            <Select mode="multiple" options={Object.entries(channelLabels).map(([value, label]) => ({ value, label }))} placeholder="可多选" />
          </Form.Item>
          <Form.Item name="scheduledAt" label="上线时间">
            <DatePicker showTime style={{ width: '100%' }} onChange={(_, s) => form.setFieldValue('scheduledAt', typeof s === 'string' ? s : '')} />
          </Form.Item>
        </Form>
      </Modal>

      <Modal title="编辑活动位" open={!!editTarget} footer={<Button onClick={() => setEditTarget(null)}>关闭</Button>} onCancel={() => setEditTarget(null)} width={520}>
        {editTarget && (
          <>
            <p><b>活动名称：</b>{editTarget.name}</p>
            <p><b>投放位置：</b>{editTarget.channels.map(channelName).join('、') || '—'}</p>
            <p><b>跳转/标题：</b>{editTarget.title}</p>
            <p><b>活动内容：</b>{editTarget.body}</p>
            <p><b>触达进度：</b>已发 {editTarget.sent_count.toLocaleString()} / 目标 {editTarget.recipient_count.toLocaleString()}</p>
            <p style={{ color: '#6b7280', fontSize: 12 }}>活动位内容创建后不可直接修改，如需调整请下线后重新创建。</p>
          </>
        )}
      </Modal>
    </div>
  )
}
