import { CopyOutlined, DeleteOutlined, EditOutlined, PlusOutlined, ReloadOutlined } from '@ant-design/icons'
import { Alert, Button, Card, Form, Input, Modal, Popconfirm, Select, Space, Switch, Table, Tag, Typography, message } from 'antd'
import { useCallback, useEffect, useState } from 'react'
import {
  ApiError,
  createDecorationScheme,
  deleteDecorationScheme,
  duplicateDecorationScheme,
  getDecorationSchemes,
  setDecorationSchemeChannel,
  type DecorationChannel,
  type DecorationPageKey,
  type DecorationScheme,
  type DecorationSchemeOverview,
} from '../../api/admin'
import { useAdminAuth } from '../auth/AdminAuthContext'
import { useRobotRecentVerification } from '../robot/useRobotRecentVerification'
import { SchemeEditor } from './SchemeEditor'

interface Props {
  onOpenLiveEditor?: () => void
}

function errorMessage(error: unknown, fallback: string): string {
  return error instanceof ApiError ? error.message : fallback
}

function formatTime(value: string): string {
  return value ? value.slice(0, 19).replace('T', ' ') : '—'
}

export function SchemeListPage({ onOpenLiveEditor }: Props) {
  const { admin } = useAdminAuth()
  const [messageApi, contextHolder] = message.useMessage()
  const [overview, setOverview] = useState<DecorationSchemeOverview | null>(null)
  const [loading, setLoading] = useState(true)
  const [busyId, setBusyId] = useState<string | null>(null)
  const [editing, setEditing] = useState<DecorationScheme | null>(null)
  const [createOpen, setCreateOpen] = useState(false)
  const [creating, setCreating] = useState(false)
  const [createForm] = Form.useForm<{ name: string; page_key: DecorationPageKey }>()
  const { runWrite, verificationContext } = useRobotRecentVerification(
    admin?.requires_mfa ?? false,
    '该操作会启用或修改页面方案，请输入当前管理员密码以继续。',
  )

  const load = useCallback(async () => {
    setLoading(true)
    try {
      setOverview(await getDecorationSchemes())
    } catch (error) {
      messageApi.error(errorMessage(error, '页面方案加载失败。'))
    } finally {
      setLoading(false)
    }
  }, [messageApi])

  useEffect(() => { void load() }, [load])

  const pageLabel = (key: DecorationPageKey) => overview?.page_types.find((item) => item.key === key)?.label ?? key

  const toggleChannel = async (scheme: DecorationScheme, channel: DecorationChannel, checked: boolean) => {
    setBusyId(scheme.id)
    const result = await runWrite(() => setDecorationSchemeChannel(scheme.id, channel, checked))
    setBusyId(null)
    if (!result) return
    setOverview(result)
    messageApi.success(checked ? '方案已在该渠道生效。' : '方案已在该渠道停用。')
  }

  const handleCreate = async () => {
    const values = await createForm.validateFields()
    setCreating(true)
    const result = await runWrite(() => createDecorationScheme({ name: values.name.trim(), page_key: values.page_key }))
    setCreating(false)
    if (!result) return
    setOverview(result.overview)
    setCreateOpen(false)
    createForm.resetFields()
    setEditing(result.scheme)
    messageApi.success('页面方案已创建，可继续装修。')
  }

  const handleDuplicate = async (scheme: DecorationScheme) => {
    setBusyId(scheme.id)
    const result = await runWrite(() => duplicateDecorationScheme(scheme.id))
    setBusyId(null)
    if (!result) return
    setOverview(result.overview)
    messageApi.success('页面方案已复制。')
  }

  const handleDelete = async (scheme: DecorationScheme) => {
    setBusyId(scheme.id)
    const result = await runWrite(() => deleteDecorationScheme(scheme.id))
    setBusyId(null)
    if (!result) return
    messageApi.success('页面方案已删除。')
    await load()
  }

  if (editing) {
    return <>
      {contextHolder}
      <SchemeEditor
        scheme={editing}
        pageLabel={pageLabel(editing.page_key)}
        onBack={() => { setEditing(null); void load() }}
      />
    </>
  }

  return <>
    {contextHolder}
    {verificationContext}
    <Alert
      showIcon
      type="info"
      style={{ marginBottom: 12 }}
      message="同一页面可以保存多套方案。打开「公众号」开关后立即替换线上页面；「小程序」开关只记录状态，待小程序端接入后生效。未启用任何方案时保持原有页面装修。"
      action={onOpenLiveEditor ? <Button size="small" onClick={onOpenLiveEditor}>直接编辑线上</Button> : undefined}
    />
    <Card bordered={false}>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: 12, flexWrap: 'wrap', marginBottom: 12 }}>
        <Space>
          <Typography.Text strong>页面方案</Typography.Text>
          <Tag>{overview?.schemes.length ?? 0} 套</Tag>
        </Space>
        <Space>
          <Button icon={<ReloadOutlined />} onClick={() => void load()}>刷新</Button>
          <Button type="primary" icon={<PlusOutlined />} disabled={!overview} onClick={() => {
            createForm.setFieldsValue({ name: '', page_key: 'home' })
            setCreateOpen(true)
          }}>新建页面</Button>
        </Space>
      </div>
      <Table
        rowKey="id"
        size="small"
        loading={loading}
        dataSource={overview?.schemes ?? []}
        pagination={false}
        scroll={{ x: 1100 }}
        columns={[
          {
            title: '页面名称',
            dataIndex: 'name',
            width: 180,
            render: (value: string, record: DecorationScheme) => <Space size={4}>
              <span>{value}</span>
              {record.derived ? <Tag color="default">默认</Tag> : null}
            </Space>,
          },
          { title: '页面类型', dataIndex: 'page_key', width: 110, render: (value: DecorationPageKey) => pageLabel(value) },
          {
            title: '公众号页面状态',
            width: 130,
            render: (_: unknown, record: DecorationScheme) => <Switch
              size="small"
              checked={overview?.pages[record.page_key].h5 === record.id}
              loading={busyId === record.id}
              onChange={(checked) => void toggleChannel(record, 'h5', checked)}
            />,
          },
          {
            title: '小程序页面状态',
            width: 130,
            render: (_: unknown, record: DecorationScheme) => <Switch
              size="small"
              checked={overview?.pages[record.page_key].miniapp === record.id}
              loading={busyId === record.id}
              onChange={(checked) => void toggleChannel(record, 'miniapp', checked)}
            />,
          },
          { title: '所属区域', width: 100, render: () => <Tag>全站</Tag> },
          { title: '新建时间', dataIndex: 'created_at', width: 160, render: (value: string) => formatTime(value) },
          { title: '最后修改时间', dataIndex: 'updated_at', width: 160, render: (value: string) => formatTime(value) },
          {
            title: '操作',
            width: 230,
            fixed: 'right',
            render: (_: unknown, record: DecorationScheme) => <Space size={4} wrap>
              <Button size="small" type="link" icon={<EditOutlined />} onClick={() => setEditing(record)}>装修</Button>
              <Button size="small" type="link" icon={<CopyOutlined />} loading={busyId === record.id} onClick={() => void handleDuplicate(record)}>复制</Button>
              <Popconfirm
                title="删除后不可恢复，确认删除该页面方案？"
                onConfirm={() => void handleDelete(record)}
                disabled={record.derived}
              >
                <Button size="small" type="link" danger icon={<DeleteOutlined />} disabled={record.derived}>删除</Button>
              </Popconfirm>
            </Space>,
          },
        ]}
      />
    </Card>

    <Modal
      title="新建页面方案"
      open={createOpen}
      confirmLoading={creating}
      onOk={() => void handleCreate()}
      onCancel={() => setCreateOpen(false)}
      destroyOnHidden
    >
      <Form form={createForm} layout="vertical" initialValues={{ page_key: 'home' }}>
        <Form.Item name="name" label="页面名称" rules={[{ required: true, message: '请填写页面名称。' }, { max: 40, message: '不能超过 40 个字符。' }]}>
          <Input placeholder="如：国庆活动首页" maxLength={40} />
        </Form.Item>
        <Form.Item name="page_key" label="页面类型" rules={[{ required: true }]}>
          <Select options={(overview?.page_types ?? []).map((item) => ({ value: item.key, label: item.label }))} />
        </Form.Item>
        <Typography.Text type="secondary">创建时会从当前线上配置复制一份内容，保存后可随时切换生效。</Typography.Text>
      </Form>
    </Modal>
  </>
}
