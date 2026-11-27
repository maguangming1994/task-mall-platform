import {
  DeleteOutlined,
  PlusOutlined,
  TeamOutlined,
} from '@ant-design/icons'
import {
  Alert,
  Button,
  Card,
  Form,
  Input,
  Modal,
  Popconfirm,
  Select,
  Space,
  Table,
  Tabs,
  Tag,
  Typography,
  message,
} from 'antd'
import { useEffect, useState } from 'react'
import {
  ApiError,
  listCampusAgentOptions,
  type CampusAgentOption,
} from '../../api/admin'
import {
  createCampusAgentBinding,
  deleteCampusAgentBinding,
  listCampusAgentBindings,
  listCampusAgentPosts,
  toggleCampusAgentPost,
  type CampusAgentBindingItem,
  type CampusAgentPostItem,
} from '../../api/campusAgent'

const { Title, Text } = Typography

function BindingsPanel() {
  const [loading, setLoading] = useState(false)
  const [items, setItems] = useState<CampusAgentBindingItem[]>([])
  const [total, setTotal] = useState(0)
  const [page, setPage] = useState(1)
  const [pageSize, setPageSize] = useState(20)
  const [modalOpen, setModalOpen] = useState(false)
  const [submitting, setSubmitting] = useState(false)
  const [agentOptions, setAgentOptions] = useState<CampusAgentOption[]>([])
  const [form] = Form.useForm()

  async function load() {
    setLoading(true)
    try {
      const res = await listCampusAgentBindings(page, pageSize)
      setItems(res.items)
      setTotal(res.pagination.total)
    } catch (e) {
      message.error(e instanceof ApiError ? e.message : '加载失败')
    } finally {
      setLoading(false)
    }
  }

  async function loadAgents() {
    try {
      const res = await listCampusAgentOptions()
      setAgentOptions(res.items || [])
    } catch {
      // 下拉加载失败不阻塞页面
    }
  }

  useEffect(() => { load() }, [page, pageSize])
  useEffect(() => { if (modalOpen) loadAgents() }, [modalOpen])

  async function handleSubmit() {
    try {
      const values = await form.validateFields()
      setSubmitting(true)
      await createCampusAgentBinding({
        openid: values.openid.trim(),
        admin_user_id: Number(values.admin_user_id),
        nickname_snapshot: values.nickname_snapshot || undefined,
      })
      message.success('绑定成功')
      setModalOpen(false)
      form.resetFields()
      load()
    } catch (e) {
      if (e instanceof ApiError) message.error(e.message)
    } finally {
      setSubmitting(false)
    }
  }

  async function handleDelete(record: CampusAgentBindingItem) {
    try {
      await deleteCampusAgentBinding(record.public_id)
      message.success('已解绑')
      load()
    } catch (e) {
      message.error(e instanceof ApiError ? e.message : '操作失败')
    }
  }

  return (
    <Card>
      <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: 16 }}>
        <Title level={5} style={{ margin: 0 }}>微信 openid 绑定</Title>
        <Button type="primary" icon={<PlusOutlined />} onClick={() => setModalOpen(true)}>
          新增绑定
        </Button>
      </div>
      <Table
        rowKey="public_id"
        loading={loading}
        dataSource={items}
        size="small"
        pagination={{
          current: page,
          pageSize,
          total,
          onChange: (p, ps) => { setPage(p); setPageSize(ps) },
        }}
        columns={[
          { title: '代理', dataIndex: 'admin_name', render: (v: string | null, r: CampusAgentBindingItem) => (
            <div><Text strong>{v || `#${r.admin_user_id}`}</Text><br /><Text type="secondary" style={{ fontSize: 12 }}>{r.admin_email}</Text></div>
          )},
          { title: '昵称快照', dataIndex: 'nickname_snapshot', render: (v: string | null) => v || '—' },
          { title: '绑定时间', dataIndex: 'bound_at', render: (v: string | null) => v ? new Date(v).toLocaleString('zh-CN') : '—' },
          { title: '最近使用', dataIndex: 'last_used_at', render: (v: string | null) => v ? new Date(v).toLocaleString('zh-CN') : '—' },
          { title: '操作', key: 'actions', width: 100, render: (_: unknown, r: CampusAgentBindingItem) => (
            <Popconfirm title="确认解绑？解绑后该代理将无法访问交流板" onConfirm={() => handleDelete(r)}>
              <Button size="small" danger icon={<DeleteOutlined />}>解绑</Button>
            </Popconfirm>
          )},
        ]}
      />
      <Modal
        title="新增 openid 绑定"
        open={modalOpen}
        onCancel={() => setModalOpen(false)}
        onOk={handleSubmit}
        okText="确认绑定"
        confirmLoading={submitting}
        destroyOnHidden
      >
        <Alert
          type="info"
          showIcon
          style={{ marginBottom: 16 }}
          message="openid 可从小程序登录日志或 auth_identities 表获取。"
        />
        <Form form={form} layout="vertical">
          <Form.Item label="代理" name="admin_user_id" rules={[{ required: true, message: '请选择代理' }]}>
            <Select
              placeholder="选择校区代理"
              options={agentOptions.map(a => ({ value: a.id, label: `${a.display_name}（${a.account}）` }))}
              showSearch
              optionFilterProp="label"
            />
          </Form.Item>
          <Form.Item label="微信 openid" name="openid" rules={[{ required: true, message: '请输入 openid' }]}>
            <Input placeholder="oXXXXXXXXXXXXXXXXXXXX" />
          </Form.Item>
          <Form.Item label="昵称快照（可选）" name="nickname_snapshot">
            <Input placeholder="如：张三" />
          </Form.Item>
        </Form>
      </Modal>
    </Card>
  )
}

function PostsPanel() {
  const [loading, setLoading] = useState(false)
  const [items, setItems] = useState<CampusAgentPostItem[]>([])
  const [total, setTotal] = useState(0)
  const [page, setPage] = useState(1)
  const [pageSize, setPageSize] = useState(20)

  async function load() {
    setLoading(true)
    try {
      const res = await listCampusAgentPosts(page, pageSize)
      setItems(res.items)
      setTotal(res.pagination.total)
    } catch (e) {
      message.error(e instanceof ApiError ? e.message : '加载失败')
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => { load() }, [page, pageSize])

  async function handleToggle(record: CampusAgentPostItem) {
    try {
      const next = record.status === 'published' ? 'hidden' : 'published'
      await toggleCampusAgentPost(record.public_id, next)
      message.success(next === 'hidden' ? '已隐藏' : '已恢复')
      load()
    } catch (e) {
      message.error(e instanceof ApiError ? e.message : '操作失败')
    }
  }

  return (
    <Card>
      <Title level={5} style={{ marginBottom: 16 }}>代理帖子管理</Title>
      <Table
        rowKey="public_id"
        loading={loading}
        dataSource={items}
        size="small"
        pagination={{
          current: page,
          pageSize,
          total,
          onChange: (p, ps) => { setPage(p); setPageSize(ps) },
        }}
        columns={[
          { title: '标题', dataIndex: 'title', width: 240, render: (v: string, r: CampusAgentPostItem) => (
            <div><Text strong>{v}</Text><br /><Text type="secondary" style={{ fontSize: 12 }}>{r.author_name} · {r.campus_name || '未知校区'}</Text></div>
          )},
          { title: '分类', dataIndex: 'category_code', width: 90, render: (v: string) => {
            const map: Record<string, { label: string; color: string }> = { notice: { label: '公告', color: 'blue' }, experience: { label: '经验', color: 'green' }, question: { label: '求助', color: 'orange' } }
            const cfg = map[v] || { label: v, color: 'default' }
            return <Tag color={cfg.color}>{cfg.label}</Tag>
          }},
          { title: '状态', dataIndex: 'status', width: 90, render: (v: string) => (
            <Tag color={v === 'published' ? 'green' : 'red'}>{v === 'published' ? '已发布' : '已隐藏'}</Tag>
          )},
          { title: '阅读', dataIndex: 'view_count', width: 70 },
          { title: '发布时间', dataIndex: 'published_at', width: 160, render: (v: string | null) => v ? new Date(v).toLocaleString('zh-CN') : '—' },
          { title: '操作', key: 'actions', width: 100, render: (_: unknown, r: CampusAgentPostItem) => (
            <Button size="small" danger={r.status === 'published'} onClick={() => handleToggle(r)}>
              {r.status === 'published' ? '隐藏' : '恢复'}
            </Button>
          )},
        ]}
      />
    </Card>
  )
}

export function CampusAgentBindingsPage() {
  return (
    <div style={{ padding: 4 }}>
      <Space direction="vertical" size={16} style={{ width: '100%' }}>
        <div>
          <Title level={4}>校区代理绑定管理</Title>
          <Text type="secondary">管理小程序 openid 与后台校区代理账号的绑定关系，以及代理交流板的帖子内容。</Text>
        </div>
        <Tabs
          defaultActiveKey="bindings"
          items={[
            { key: 'bindings', label: '绑定管理', icon: <TeamOutlined />, children: <BindingsPanel /> },
            { key: 'posts', label: '帖子管理', children: <PostsPanel /> },
          ]}
        />
      </Space>
    </div>
  )
}
