import { useCallback, useEffect, useState } from 'react'
import { Button, Checkbox, Form, Input, Modal, Select, Table, Tabs, Typography, message } from 'antd'
import { useDebouncedValue } from '../../hooks/useDebouncedValue'
import type { ColumnsType } from 'antd/es/table'
import {
  handleMaterialReport,
  listMaterialReports,
  listMaterialRequests,
  updateMaterialRequest,
  type ManagedMaterialReport,
  type ManagedMaterialRequest,
} from '../../api/material'
import { Links, PageHead, Pill, type PillTone } from '../../components/xq'

const fmtTime = (v?: string | null) => (v ? new Date(v).toLocaleString('zh-CN', { hour12: false }) : '—')

const requestStatus: Record<string, { tone: PillTone; label: string }> = {
  pending: { tone: 'orange', label: '待处理' },
  processing: { tone: 'blue', label: '处理中' },
  done: { tone: 'green', label: '已找到' },
  closed: { tone: 'gray', label: '已关闭' },
}

export function MaterialRequestPage() {
  const [messageApi, contextHolder] = message.useMessage()
  const [tab, setTab] = useState('requests')

  return (
    <div className="xq-page">
      {contextHolder}
      <PageHead title="求资料与举报" />
      <Tabs
        activeKey={tab}
        onChange={setTab}
        items={[
          { key: 'requests', label: '求资料需求', children: <RequestTable messageApi={messageApi} /> },
          { key: 'reports', label: '侵权举报', children: <ReportTable messageApi={messageApi} /> },
        ]}
      />
    </div>
  )
}

type MessageApi = ReturnType<typeof message.useMessage>[0]

function RequestTable({ messageApi }: { messageApi: MessageApi }) {
  const [status, setStatus] = useState('pending')
  const [keyword, setKeyword] = useState('')
  const [debouncedKeyword, flushKeyword] = useDebouncedValue(keyword, 400)
  const [items, setItems] = useState<ManagedMaterialRequest[]>([])
  const [total, setTotal] = useState(0)
  const [page, setPage] = useState(1)
  const [loading, setLoading] = useState(false)
  const [target, setTarget] = useState<ManagedMaterialRequest | null>(null)
  const [submitting, setSubmitting] = useState(false)
  const [form] = Form.useForm<{ status: ManagedMaterialRequest['status']; admin_note?: string }>()

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const res = await listMaterialRequests({ status, keyword: debouncedKeyword.trim() || undefined, page, page_size: 10 })
      setItems(res.items)
      setTotal(res.pagination.total)
    } catch {
      messageApi.error('需求加载失败，请稍后重试。')
    } finally {
      setLoading(false)
    }
  }, [status, debouncedKeyword, page, messageApi])

  useEffect(() => { void load() }, [load])

  const openHandle = (row: ManagedMaterialRequest) => {
    setTarget(row)
    form.setFieldsValue({ status: row.status, admin_note: row.admin_note ?? '' })
  }

  const submit = async (values: { status: ManagedMaterialRequest['status']; admin_note?: string }) => {
    if (!target) return
    setSubmitting(true)
    try {
      await updateMaterialRequest(target.id, values)
      messageApi.success('需求状态已更新。')
      setTarget(null)
      await load()
    } catch {
      messageApi.error('操作失败，请稍后重试。')
    } finally {
      setSubmitting(false)
    }
  }

  const columns: ColumnsType<ManagedMaterialRequest> = [
    {
      title: '需求内容',
      render: (_, r) => (
        <div>
          <div style={{ fontWeight: 600 }}>{r.title}</div>
          <div style={{ color: '#8c8c8c', fontSize: 12, marginTop: 2 }}>{r.requirement}</div>
        </div>
      ),
    },
    { title: '提交人', width: 140, render: (_, r) => r.user?.nickname ?? '—' },
    {
      title: '状态',
      width: 100,
      render: (_, r) => {
        const p = requestStatus[r.status] ?? { tone: 'gray' as PillTone, label: r.status }
        return <Pill tone={p.tone}>{p.label}</Pill>
      },
    },
    { title: '提交时间', width: 170, render: (_, r) => fmtTime(r.created_at) },
    { title: '最近处理', width: 170, render: (_, r) => fmtTime(r.handled_at) },
    { title: '操作', width: 100, render: (_, r) => <Links items={[{ label: '处理', onClick: () => openHandle(r) }]} /> },
  ]

  return (
    <>
      <div className="xq-card xq-filter" style={{ marginBottom: 16 }}>
        <Select
          value={status}
          onChange={(v) => { setStatus(v); setPage(1) }}
          style={{ minWidth: 130 }}
          options={[
            { value: 'pending', label: '待处理' },
            { value: 'processing', label: '处理中' },
            { value: 'done', label: '已找到' },
            { value: 'closed', label: '已关闭' },
          ]}
        />
        <Input.Search
          placeholder="需求标题 / 内容"
          value={keyword}
          onChange={(e) => setKeyword(e.target.value)}
          onSearch={() => { flushKeyword(); setPage(1) }}
          allowClear
          style={{ maxWidth: 240 }}
        />
      </div>
      <div className="xq-card xq-table">
        <Table
          rowKey="id"
          size="middle"
          loading={loading}
          columns={columns}
          dataSource={items}
          pagination={{ current: page, pageSize: 10, total, onChange: setPage, showTotal: (t) => `共 ${t} 条` }}
        />
      </div>

      <Modal
        title="处理求资料需求"
        open={!!target}
        onCancel={() => setTarget(null)}
        onOk={() => form.submit()}
        confirmLoading={submitting}
        okText="保存"
        cancelText="取消"
      >
        {target && (
          <div style={{ marginBottom: 16, padding: 12, background: '#fafafa', borderRadius: 8 }}>
            <div style={{ fontWeight: 600 }}>{target.title}</div>
            <div style={{ color: '#6b7280', fontSize: 13, marginTop: 6, lineHeight: 1.6 }}>{target.requirement}</div>
          </div>
        )}
        <Form form={form} layout="vertical" onFinish={submit}>
          <Form.Item name="status" label="处理状态" rules={[{ required: true }]}>
            <Select
              options={[
                { value: 'pending', label: '待处理' },
                { value: 'processing', label: '处理中' },
                { value: 'done', label: '已找到' },
                { value: 'closed', label: '已关闭' },
              ]}
            />
          </Form.Item>
          <Form.Item name="admin_note" label="回复用户（可选）">
            <Input.TextArea rows={3} maxLength={1000} placeholder="例如：已将资料投喂到资料库，可按关键词搜索。" />
          </Form.Item>
        </Form>
      </Modal>
    </>
  )
}

const decisionLabel: Record<string, string> = {
  upheld: '举报成立',
  dismissed: '不成立',
  other: '其他处理',
}

const materialStatusPill: Record<string, { tone: PillTone; label: string }> = {
  pending: { tone: 'orange', label: '待审核' },
  approved: { tone: 'green', label: '已上架' },
  rejected: { tone: 'red', label: '已驳回' },
  offline: { tone: 'gray', label: '已下架' },
}

function ReportTable({ messageApi }: { messageApi: MessageApi }) {
  const [status, setStatus] = useState('pending')
  const [items, setItems] = useState<ManagedMaterialReport[]>([])
  const [total, setTotal] = useState(0)
  const [page, setPage] = useState(1)
  const [loading, setLoading] = useState(false)
  const [target, setTarget] = useState<ManagedMaterialReport | null>(null)
  const [submitting, setSubmitting] = useState(false)
  const [form] = Form.useForm<{
    decision: 'upheld' | 'dismissed' | 'other'
    handling_note?: string
    takedown?: boolean
  }>()
  const decision = Form.useWatch('decision', form)

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const res = await listMaterialReports({ status, page, page_size: 10 })
      setItems(res.items)
      setTotal(res.pagination.total)
    } catch {
      messageApi.error('举报加载失败，请稍后重试。')
    } finally {
      setLoading(false)
    }
  }, [status, page, messageApi])

  useEffect(() => { void load() }, [load])

  const openHandle = (row: ManagedMaterialReport) => {
    setTarget(row)
    form.resetFields()
    form.setFieldsValue({ decision: 'upheld', handling_note: '', takedown: true })
  }

  const submit = async (values: { decision: 'upheld' | 'dismissed' | 'other'; handling_note?: string; takedown?: boolean }) => {
    if (!target) return
    setSubmitting(true)
    try {
      const res = await handleMaterialReport(target.id, values)
      if (res.idempotent) messageApi.info('该举报已处理过，未重复执行。')
      else messageApi.success('举报处理完成。')
      setTarget(null)
      await load()
    } catch {
      messageApi.error('操作失败，请稍后重试。')
    } finally {
      setSubmitting(false)
    }
  }

  const columns: ColumnsType<ManagedMaterialReport> = [
    {
      title: '涉及资料',
      render: (_, r) => (
        <div>
          <div style={{ fontWeight: 600 }}>{r.material?.title ?? '资料已删除'}</div>
          {r.material && (
            <div style={{ color: '#8c8c8c', fontSize: 12, marginTop: 2 }}>
              {r.material.netdisk_label} · 上传人 {r.material.uploader_name} · 被获取 {r.material.fetch_count} 次
            </div>
          )}
        </div>
      ),
    },
    {
      title: '资料状态',
      width: 100,
      render: (_, r) => {
        if (!r.material) return '—'
        const p = materialStatusPill[r.material.status] ?? { tone: 'gray' as PillTone, label: r.material.status }
        return <Pill tone={p.tone}>{p.label}</Pill>
      },
    },
    { title: '举报人', width: 120, render: (_, r) => r.reporter?.nickname ?? '—' },
    { title: '举报原因', dataIndex: 'reason', width: 140 },
    {
      title: '处理结论',
      width: 110,
      render: (_, r) => (r.decision ? <span>{decisionLabel[r.decision] ?? r.decision}</span> : '—'),
    },
    {
      title: '状态',
      width: 90,
      render: (_, r) => <Pill tone={r.status === 'pending' ? 'orange' : 'green'}>{r.status === 'pending' ? '待处理' : '已处理'}</Pill>,
    },
    { title: '举报时间', width: 160, render: (_, r) => fmtTime(r.created_at) },
    {
      title: '操作',
      width: 110,
      render: (_, r) => <Links items={[{ label: r.status === 'pending' ? '处理' : '查看', onClick: () => openHandle(r) }]} />,
    },
  ]

  return (
    <>
      <div className="xq-card xq-filter" style={{ marginBottom: 16 }}>
        <Select
          value={status}
          onChange={(v) => { setStatus(v); setPage(1) }}
          style={{ minWidth: 130 }}
          options={[
            { value: 'pending', label: '待处理' },
            { value: 'handled', label: '已处理' },
          ]}
        />
        <Button onClick={() => void load()}>刷新</Button>
      </div>
      <div className="xq-card xq-table">
        <Table
          rowKey="id"
          size="middle"
          loading={loading}
          columns={columns}
          dataSource={items}
          pagination={{ current: page, pageSize: 10, total, onChange: setPage, showTotal: (t) => `共 ${t} 条` }}
        />
      </div>

      <Modal
        title="处理侵权举报"
        open={!!target}
        onCancel={() => setTarget(null)}
        onOk={() => form.submit()}
        confirmLoading={submitting}
        okText="提交处理"
        cancelText="取消"
        width={620}
      >
        {target && (
          <div style={{ marginBottom: 16, padding: 12, background: '#fafafa', borderRadius: 8 }}>
            <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
              <span style={{ fontWeight: 600 }}>{target.material?.title ?? '资料已删除'}</span>
              {target.material && <Pill tone={materialStatusPill[target.material.status]?.tone ?? 'gray'}>{materialStatusPill[target.material.status]?.label ?? target.material.status}</Pill>}
            </div>
            {target.material && (
              <div style={{ color: '#6b7280', fontSize: 13, marginTop: 6, lineHeight: 1.8 }}>
                <div>网盘：{target.material.netdisk_label} · 上传人：{target.material.uploader_name} · 被获取 {target.material.fetch_count} 次</div>
                <Typography.Text copyable style={{ fontSize: 12 }}>{target.material.share_url}</Typography.Text>
                {target.material.extract_code && <span style={{ marginLeft: 12 }}>提取码：{target.material.extract_code}</span>}
              </div>
            )}
            <div style={{ marginTop: 8, color: '#1f2937', fontSize: 13 }}>
              举报原因：<b>{target.reason}</b>{target.detail ? ` · ${target.detail}` : ''}
            </div>
            {target.status === 'handled' && (
              <div style={{ marginTop: 8, color: '#6b7280', fontSize: 12 }}>
                已于 {fmtTime(target.handled_at)} 处理，结论：{decisionLabel[target.decision ?? ''] ?? target.decision ?? '—'}{target.handling_note ? `；备注：${target.handling_note}` : ''}
              </div>
            )}
          </div>
        )}
        <Form
          form={form}
          layout="vertical"
          onFinish={submit}
          onValuesChange={(changed) => {
            if (changed.decision === 'upheld') form.setFieldValue('takedown', true)
          }}
        >
          <Form.Item name="decision" label="处理结论" rules={[{ required: true, message: '请选择处理结论' }]}>
            <Select
              options={[
                { value: 'upheld', label: '举报成立（下架资料）' },
                { value: 'dismissed', label: '不成立' },
                { value: 'other', label: '其他处理' },
              ]}
            />
          </Form.Item>
          <Form.Item name="takedown" valuePropName="checked">
            <Checkbox disabled={decision === 'upheld'}>联动下架该资料（选择“举报成立”时自动下架）</Checkbox>
          </Form.Item>
          <Form.Item name="handling_note" label="处理备注（可选，将作为下架原因 / 通知依据）">
            <Input.TextArea rows={3} maxLength={500} placeholder="例如：经核实存在侵权内容，已予下架。" />
          </Form.Item>
        </Form>
      </Modal>
    </>
  )
}
