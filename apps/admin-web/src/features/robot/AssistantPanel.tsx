import { ApiOutlined, DeleteOutlined, EditOutlined, KeyOutlined, LinkOutlined, PlusOutlined, ReloadOutlined } from '@ant-design/icons'
import { Alert, Button, Form, Input, Modal, Popconfirm, Select, Space, Switch, Table, Tag, Typography, message } from 'antd'
import type { ColumnsType } from 'antd/es/table'
import { useCallback, useEffect, useState } from 'react'
import {
  createXpRobotAssistant,
  deleteXpRobotAssistant,
  listXpRobotAssistants,
  updateXpRobotAssistant,
  updateXpRobotAssistantStatus,
  type XpRobotAssistant,
} from '../../api/admin'
import { AssistantBindingModal } from './AssistantBindingModal'
import { AssistantWebhookModal } from './AssistantWebhookModal'
import { formatRobotTime } from './robotUtils'
import { useRobotRecentVerification } from './useRobotRecentVerification'

interface Props {
  configured: boolean
  canManage: boolean
  requiresMfa: boolean
}

interface FilterValues {
  name?: string
  app_id?: string
  enabled?: boolean
}

interface AssistantFormValues {
  name: string
  remark?: string
  enabled: boolean
}

export function AssistantPanel({ configured, canManage, requiresMfa }: Props) {
  const [filterForm] = Form.useForm<FilterValues>()
  const [assistantForm] = Form.useForm<AssistantFormValues>()
  const [messageApi, messageContext] = message.useMessage()
  const { runWrite, verificationContext } = useRobotRecentVerification(requiresMfa)
  const [items, setItems] = useState<XpRobotAssistant[]>([])
  const [filters, setFilters] = useState<FilterValues>({})
  const [loading, setLoading] = useState(false)
  const [editorOpen, setEditorOpen] = useState(false)
  const [editing, setEditing] = useState<XpRobotAssistant | null>(null)
  const [saving, setSaving] = useState(false)
  const [busyId, setBusyId] = useState<string | null>(null)
  const [credentials, setCredentials] = useState<{ assistant: XpRobotAssistant; secret: string } | null>(null)
  const [bindingAssistant, setBindingAssistant] = useState<XpRobotAssistant | null>(null)
  const [webhookAssistant, setWebhookAssistant] = useState<XpRobotAssistant | null>(null)

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const result = await listXpRobotAssistants(filters)
      setItems(result.items)
    } catch (error) {
      messageApi.error(error instanceof Error ? error.message : '助手配置加载失败。')
    } finally {
      setLoading(false)
    }
  }, [filters, messageApi])

  useEffect(() => { void load() }, [load])

  const replaceItem = (updated: XpRobotAssistant) => {
    setItems((current) => current.map((item) => item.id === updated.id ? updated : item))
    if (bindingAssistant?.id === updated.id) setBindingAssistant(updated)
    if (webhookAssistant?.id === updated.id) setWebhookAssistant(updated)
  }

  const openEditor = (record: XpRobotAssistant | null) => {
    setEditing(record)
    assistantForm.setFieldsValue(record ? { name: record.name, remark: record.remark ?? '', enabled: record.enabled } : { name: '', remark: '', enabled: true })
    setEditorOpen(true)
  }

  const save = async (values: AssistantFormValues) => {
    const name = values.name.trim()
    const remark = values.remark?.trim() || null
    setSaving(true)
    try {
      if (editing) {
        const updated = await runWrite(() => updateXpRobotAssistant(editing.id, { name, remark }), '助手配置保存失败。')
        if (!updated) return
        replaceItem(updated)
        setEditorOpen(false)
        messageApi.success('助手配置已保存。')
      } else {
        const created = await runWrite(() => createXpRobotAssistant({ name, remark, enabled: values.enabled }), '助手创建失败。')
        if (!created) return
        setEditorOpen(false)
        setCredentials({ assistant: created, secret: created.app_secret_once })
        void load()
      }
    } finally {
      setSaving(false)
    }
  }

  const changeStatus = async (record: XpRobotAssistant, enabled: boolean) => {
    setBusyId(record.id)
    try {
      const updated = await runWrite(() => updateXpRobotAssistantStatus(record.id, enabled), '助手状态调整失败。')
      if (!updated) return
      replaceItem(updated)
      messageApi.success(enabled ? '助手已启用。' : '助手已停用。')
    } finally {
      setBusyId(null)
    }
  }

  const remove = async (record: XpRobotAssistant) => {
    setBusyId(record.id)
    try {
      const result = await runWrite(() => deleteXpRobotAssistant(record.id), '助手删除失败。')
      if (!result) return
      setItems((current) => current.filter((item) => item.id !== record.id))
      messageApi.success('助手配置已删除。')
    } finally {
      setBusyId(null)
    }
  }

  const columns: ColumnsType<XpRobotAssistant> = [
    {
      title: '助手名称', width: 200, fixed: 'left',
      render: (_, record) => <div>
        <Typography.Text strong>{record.name}</Typography.Text>
        <Typography.Text type="secondary" ellipsis={{ tooltip: record.remark }} style={{ display: 'block', marginTop: 4, fontSize: 12 }}>{record.remark || '无备注'}</Typography.Text>
      </div>,
    },
    {
      title: '应用 ID', width: 240,
      render: (_, record) => <Typography.Text copyable={{ text: record.app_id }} style={{ fontSize: 12 }}>{record.app_id}</Typography.Text>,
    },
    {
      title: '应用密钥', width: 180,
      render: (_, record) => <div>
        <Typography.Text code>{record.app_secret_masked || '不可用'}</Typography.Text>
        <Typography.Text type="secondary" style={{ display: 'block', marginTop: 4, fontSize: 12 }}>完整密钥不可再次查看</Typography.Text>
      </div>,
    },
    {
      title: '状态', width: 90,
      render: (_, record) => <Switch size="small" checked={record.enabled} loading={busyId === record.id} disabled={!canManage || busyId === record.id} checkedChildren="启用" unCheckedChildren="停用" onChange={(enabled) => void changeStatus(record, enabled)} />,
    },
    {
      title: '机器人绑定', width: 130,
      render: (_, record) => <Button type="link" icon={<LinkOutlined />} onClick={() => setBindingAssistant(record)}>{record.robot_count} 个机器人</Button>,
    },
    { title: '创建时间', width: 170, render: (_, record) => formatRobotTime(record.created_at) },
    { title: '更新时间', width: 170, render: (_, record) => formatRobotTime(record.updated_at) },
    {
      title: '操作', width: 280, fixed: 'right',
      render: (_, record) => <Space size={0} wrap>
        <Button type="link" size="small" icon={<EditOutlined />} disabled={!canManage} onClick={() => openEditor(record)}>编辑</Button>
        <Button type="link" size="small" icon={<LinkOutlined />} onClick={() => setBindingAssistant(record)}>绑定管理</Button>
        <Button type="link" size="small" icon={<ApiOutlined />} onClick={() => setWebhookAssistant(record)}>Webhook</Button>
        <Popconfirm title="删除该助手？" description="将删除本地助手配置与绑定关系，历史审计记录仍保留。" okText="删除" cancelText="取消" okButtonProps={{ danger: true }} onConfirm={() => void remove(record)}>
          <Button type="link" size="small" danger icon={<DeleteOutlined />} disabled={!canManage}>删除</Button>
        </Popconfirm>
      </Space>,
    },
  ]

  return <section className="xq-card xq-table" aria-label="助手配置管理">
    {messageContext}
    {verificationContext}
    <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: 12, flexWrap: 'wrap', marginBottom: 16 }}>
      <div>
        <p className="xq-card-title" style={{ margin: 0 }}>助手配置</p>
        <Typography.Text type="secondary">本地应用凭证、机器人绑定及预留 Webhook 管理。</Typography.Text>
      </div>
      <Space wrap>
        <Button icon={<ReloadOutlined />} loading={loading} onClick={() => void load()}>刷新</Button>
        <Button type="primary" icon={<PlusOutlined />} disabled={!canManage} onClick={() => openEditor(null)}>新增助手</Button>
      </Space>
    </div>
    <Alert showIcon type="info" message="应用密钥由后端加密保存，仅在创建成功时完整展示一次。当前不对接第三方托管平台。" style={{ marginBottom: 16 }} />
    {!canManage ? <Alert showIcon type="warning" message="当前管理员只有查看权限；新增、编辑、启停、绑定和本地自检需要 robot.settings_manage 权限。" style={{ marginBottom: 16 }} /> : null}
    <Form<FilterValues> form={filterForm} layout="inline" style={{ marginBottom: 16, gap: 8 }} onFinish={(values) => setFilters({ name: values.name?.trim() || undefined, app_id: values.app_id?.trim() || undefined, enabled: values.enabled })}>
      <Form.Item name="name" label="助手名称"><Input allowClear placeholder="按名称搜索" style={{ width: 180 }} /></Form.Item>
      <Form.Item name="app_id" label="应用 ID"><Input allowClear placeholder="按应用 ID 搜索" style={{ width: 220 }} /></Form.Item>
      <Form.Item name="enabled" label="状态"><Select allowClear placeholder="全部状态" style={{ width: 120 }} options={[{ value: true, label: '启用' }, { value: false, label: '停用' }]} /></Form.Item>
      <Form.Item><Space><Button type="primary" htmlType="submit">查询</Button><Button onClick={() => { filterForm.resetFields(); setFilters({}) }}>重置</Button></Space></Form.Item>
    </Form>
    <Table<XpRobotAssistant>
      rowKey="id"
      loading={loading}
      columns={columns}
      dataSource={items}
      scroll={{ x: 1470 }}
      pagination={{ pageSize: 10, showTotal: (total) => `共 ${total} 条` }}
      locale={{ emptyText: '暂无助手配置' }}
    />

    <Modal
      title={editing ? `编辑助手：${editing.name}` : '新增助手'}
      open={editorOpen}
      onCancel={() => { if (!saving) setEditorOpen(false) }}
      onOk={() => assistantForm.submit()}
      okText={editing ? '保存配置' : '创建并生成凭证'}
      cancelText="取消"
      confirmLoading={saving}
      maskClosable={!saving}
      width={560}
    >
      {editing ? <Alert showIcon type="info" message="编辑不会更换应用 ID 或应用密钥；启停请在列表中单独操作。" style={{ marginBottom: 16 }} /> : <Alert showIcon type="warning" message="创建后请立即保存完整应用密钥，关闭结果窗口后无法再次查看。" style={{ marginBottom: 16 }} />}
      <Form<AssistantFormValues> form={assistantForm} layout="vertical" initialValues={{ name: '', remark: '', enabled: true }} onFinish={(values) => void save(values)}>
        <Form.Item name="name" label="助手名称" rules={[{ required: true, whitespace: true, max: 191, message: '请输入不超过 191 字的助手名称。' }]}>
          <Input maxLength={191} showCount placeholder="例如：任务内容助手" />
        </Form.Item>
        <Form.Item name="remark" label="备注" rules={[{ max: 2000, message: '备注不能超过 2000 字。' }]}>
          <Input.TextArea rows={4} maxLength={2000} showCount placeholder="说明助手用途和绑定范围" />
        </Form.Item>
        {!editing ? <Form.Item name="enabled" label="启用状态" valuePropName="checked"><Switch checkedChildren="启用" unCheckedChildren="停用" /></Form.Item> : null}
      </Form>
    </Modal>

    <Modal
      title={<Space><KeyOutlined />助手创建成功，请立即保存凭证</Space>}
      open={!!credentials}
      onCancel={() => setCredentials(null)}
      footer={<Button type="primary" onClick={() => setCredentials(null)}>我已妥善保存</Button>}
      width={680}
      maskClosable={false}
    >
      <Alert showIcon type="error" message="完整密钥仅本次显示" description="关闭后列表、编辑和 Webhook 页面均只显示脱敏密钥，平台无法找回完整明文。" style={{ marginBottom: 18 }} />
      {credentials ? <div>
        <Typography.Title level={5}>{credentials.assistant.name}</Typography.Title>
        <Typography.Paragraph>
          <Typography.Text strong>应用 ID</Typography.Text><br />
          <Typography.Text copyable={{ text: credentials.assistant.app_id }} style={{ wordBreak: 'break-all' }}>{credentials.assistant.app_id}</Typography.Text>
        </Typography.Paragraph>
        <Typography.Paragraph>
          <Typography.Text strong>应用密钥（仅本次完整展示）</Typography.Text><br />
          <Typography.Text code copyable={{ text: credentials.secret, tooltips: ['复制完整密钥', '已复制'] }} style={{ display: 'inline-block', marginTop: 8, wordBreak: 'break-all', userSelect: 'all' }}>{credentials.secret}</Typography.Text>
        </Typography.Paragraph>
        <Tag color="success">创建后可继续绑定机器人并执行 Webhook 本地自检</Tag>
      </div> : null}
    </Modal>

    <AssistantBindingModal
      assistant={bindingAssistant}
      configured={configured}
      canManage={canManage}
      requiresMfa={requiresMfa}
      onClose={() => setBindingAssistant(null)}
      onUpdated={replaceItem}
    />
    <AssistantWebhookModal
      assistant={webhookAssistant}
      canManage={canManage}
      requiresMfa={requiresMfa}
      onClose={() => setWebhookAssistant(null)}
    />
  </section>
}
