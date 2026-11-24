import { DeleteOutlined, EditOutlined, PlusOutlined, ReloadOutlined, SendOutlined } from '@ant-design/icons'
import { Alert, Button, Checkbox, Drawer, Form, Input, InputNumber, Modal, Popconfirm, Select, Space, Switch, Table, Tag, Tooltip, Typography, message } from 'antd'
import type { ColumnsType } from 'antd/es/table'
import { useCallback, useEffect, useState } from 'react'
import {
  createXpRobotPostBot,
  createXpRobotPostTemplate,
  deleteXpRobotPostBot,
  deleteXpRobotPostTemplate,
  listXpRobotForwardFilterOptions,
  listXpRobotPostBots,
  listXpRobotPostTemplates,
  publishXpRobotPostBotNow,
  updateXpRobotPostBot,
  updateXpRobotPostBotStatus,
  updateXpRobotPostTemplate,
  type XpRobotForumCategory,
  type XpRobotPostBot,
  type XpRobotPostBotInput,
  type XpRobotPostTemplate,
  type XpRobotSchoolOption,
} from '../../api/admin'
import { formatRobotTime } from './robotUtils'
import { useRobotRecentVerification } from './useRobotRecentVerification'

interface Props {
  canManage: boolean
  requiresMfa: boolean
}

const SOURCE_LABELS: Record<string, string> = {
  pool: '人工帖子池',
  history: '复用历史帖子',
  ai: 'AI 生成',
}

const WEEKDAYS = [
  { label: '周一', value: 1 }, { label: '周二', value: 2 }, { label: '周三', value: 3 }, { label: '周四', value: 4 },
  { label: '周五', value: 5 }, { label: '周六', value: 6 }, { label: '周日', value: 7 },
]

const TIME_RULE = { pattern: /^([01]\d|2[0-3]):[0-5]\d$/, message: '请使用 HH:mm 格式，例如 09:00。' }

export function PostBotPanel({ canManage, requiresMfa }: Props) {
  const [messageApi, messageContext] = message.useMessage()
  const { runWrite, verificationContext } = useRobotRecentVerification(requiresMfa)
  const [form] = Form.useForm<XpRobotPostBotInput>()
  const [templateForm] = Form.useForm<{ title: string; content: string; enabled: boolean; sort: number }>()
  const [items, setItems] = useState<XpRobotPostBot[]>([])
  const [schools, setSchools] = useState<XpRobotSchoolOption[]>([])
  const [categories, setCategories] = useState<XpRobotForumCategory[]>([])
  const [loading, setLoading] = useState(false)
  const [editorOpen, setEditorOpen] = useState(false)
  const [editing, setEditing] = useState<XpRobotPostBot | null>(null)
  const [saving, setSaving] = useState(false)
  const [busyId, setBusyId] = useState<string | null>(null)
  const [publishingId, setPublishingId] = useState<string | null>(null)
  const [templatesFor, setTemplatesFor] = useState<XpRobotPostBot | null>(null)
  const [templates, setTemplates] = useState<XpRobotPostTemplate[]>([])
  const [templatesLoading, setTemplatesLoading] = useState(false)
  const [templateOpen, setTemplateOpen] = useState(false)
  const [templateEditing, setTemplateEditing] = useState<XpRobotPostTemplate | null>(null)
  const [templateSaving, setTemplateSaving] = useState(false)

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const [result, options] = await Promise.all([listXpRobotPostBots(), listXpRobotForwardFilterOptions()])
      setItems(result.items)
      setSchools(options.schools)
    } catch (error) {
      messageApi.error(error instanceof Error ? error.message : '发帖机器人加载失败。')
    } finally {
      setLoading(false)
    }
  }, [messageApi])

  useEffect(() => { void load() }, [load])

  const loadCategories = async (campusId?: string) => {
    if (!campusId) {
      setCategories([])
      return
    }
    try {
      const options = await listXpRobotForwardFilterOptions(campusId)
      setCategories(options.categories)
    } catch {
      setCategories([])
    }
  }

  const openEditor = (record: XpRobotPostBot | null) => {
    setEditing(record)
    setEditorOpen(true)
    form.resetFields()
    if (record) {
      form.setFieldsValue({
        name: record.name, enabled: record.enabled, campus_id: record.campus_id ?? undefined,
        category_code: record.category_code, content_source: record.content_source,
        posts_per_day: record.posts_per_day, posts_per_run: record.posts_per_run,
        active_weekdays: record.active_weekdays,
        active_start: record.active_start, active_end: record.active_end,
        min_gap_minutes: record.min_gap_minutes, history_min_likes: record.history_min_likes,
        ai_prompt: record.ai_prompt ?? undefined,
      })
      void loadCategories(record.campus_id ?? undefined)
    } else {
      form.setFieldsValue({
        enabled: true, content_source: 'pool', posts_per_day: 3, posts_per_run: 1, active_weekdays: [1, 2, 3, 4, 5, 6, 7],
        active_start: '09:00', active_end: '22:00', min_gap_minutes: 60, history_min_likes: 0,
      } as XpRobotPostBotInput)
    }
  }

  const save = async () => {
    const values = await form.validateFields()
    setSaving(true)
    try {
      const payload = { ...values, ai_prompt: values.ai_prompt?.trim() || null }
      const saved = editing
        ? await runWrite(() => updateXpRobotPostBot(editing.id, payload), '保存失败，请稍后重试。')
        : await runWrite(() => createXpRobotPostBot(payload), '创建失败，请稍后重试。')
      if (!saved) return
      setEditorOpen(false)
      messageApi.success(editing ? '发帖机器人已更新。' : '发帖机器人已创建。')
      void load()
    } finally {
      setSaving(false)
    }
  }

  const toggle = async (record: XpRobotPostBot, enabled: boolean) => {
    setBusyId(record.id)
    try {
      const updated = await runWrite(() => updateXpRobotPostBotStatus(record.id, enabled), '状态调整失败。')
      if (!updated) return
      setItems((current) => current.map((item) => item.id === updated.id ? updated : item))
      messageApi.success(enabled ? '已启用发帖机器人。' : '已停用发帖机器人。')
    } finally {
      setBusyId(null)
    }
  }

  const remove = async (record: XpRobotPostBot) => {
    setBusyId(record.id)
    try {
      const result = await runWrite(() => deleteXpRobotPostBot(record.id), '删除失败。')
      if (!result) return
      setItems((current) => current.filter((item) => item.id !== record.id))
      messageApi.success('发帖机器人已删除。')
    } finally {
      setBusyId(null)
    }
  }

  const publishNow = async (record: XpRobotPostBot) => {
    setPublishingId(record.id)
    try {
      const result = await runWrite(() => publishXpRobotPostBotNow(record.id), '立即发帖失败。')
      if (!result) return
      messageApi.success(`已发布帖子：${result.title}`)
      void load()
    } finally {
      setPublishingId(null)
    }
  }

  const openTemplates = async (record: XpRobotPostBot) => {
    setTemplatesFor(record)
    setTemplatesLoading(true)
    try {
      const result = await listXpRobotPostTemplates(record.id)
      setTemplates(result.items)
    } catch (error) {
      messageApi.error(error instanceof Error ? error.message : '帖子池加载失败。')
    } finally {
      setTemplatesLoading(false)
    }
  }

  const openTemplateEditor = (record: XpRobotPostTemplate | null) => {
    setTemplateEditing(record)
    setTemplateOpen(true)
    templateForm.resetFields()
    if (record) {
      templateForm.setFieldsValue({ title: record.title, content: record.content, enabled: record.enabled, sort: record.sort })
    } else {
      templateForm.setFieldsValue({ enabled: true, sort: 0 } as { title: string; content: string; enabled: boolean; sort: number })
    }
  }

  const saveTemplate = async () => {
    if (!templatesFor) return
    const values = await templateForm.validateFields()
    setTemplateSaving(true)
    try {
      const saved = templateEditing
        ? await runWrite(() => updateXpRobotPostTemplate(templatesFor.id, templateEditing.id, values), '保存失败。')
        : await runWrite(() => createXpRobotPostTemplate(templatesFor.id, values), '创建失败。')
      if (!saved) return
      setTemplateOpen(false)
      messageApi.success(templateEditing ? '帖子模板已更新。' : '帖子模板已添加。')
      await openTemplates(templatesFor)
      void load()
    } finally {
      setTemplateSaving(false)
    }
  }

  const removeTemplate = async (record: XpRobotPostTemplate) => {
    if (!templatesFor) return
    const result = await runWrite(() => deleteXpRobotPostTemplate(templatesFor.id, record.id), '删除失败。')
    if (!result) return
    setTemplates((current) => current.filter((item) => item.id !== record.id))
    messageApi.success('帖子模板已删除。')
    void load()
  }

  const columns: ColumnsType<XpRobotPostBot> = [
    {
      title: '机器人', width: 200, fixed: 'left',
      render: (_, record) => <div>
        <Typography.Text strong>{record.name}</Typography.Text>
        <Typography.Text type="secondary" style={{ display: 'block', marginTop: 4, fontSize: 12 }}>
          {record.campus_name || '未绑定学校'} · {record.category_name}
        </Typography.Text>
      </div>,
    },
    {
      title: '状态', width: 90,
      render: (_, record) => <Switch
        size="small" checked={record.enabled} disabled={!canManage || busyId === record.id} loading={busyId === record.id}
        checkedChildren="启用" unCheckedChildren="停用" onChange={(enabled) => void toggle(record, enabled)}
      />,
    },
    {
      title: '内容来源', width: 130,
      render: (_, record) => <Space direction="vertical" size={2}>
        <Tag>{SOURCE_LABELS[record.content_source] ?? record.content_source}</Tag>
        {record.content_source === 'pool'
          ? <Typography.Text type="secondary" style={{ fontSize: 12 }}>可用模板 {record.templates_available}/{record.templates_total}</Typography.Text>
          : null}
      </Space>,
    },
    {
      title: '发布节奏', width: 170,
      render: (_, record) => <div>
        <Typography.Text>今日 {record.posted_today}/{record.posts_per_day} 条</Typography.Text>
        <Typography.Text type="secondary" style={{ display: 'block', marginTop: 4, fontSize: 12 }}>
          {record.active_start}–{record.active_end} · 间隔≥{record.min_gap_minutes} 分钟 · 每轮 {record.posts_per_run} 条
        </Typography.Text>
      </div>,
    },
    {
      title: '最近发布', width: 170,
      render: (_, record) => <div>
        {formatRobotTime(record.last_posted_at)}
        {record.last_error
          ? <Tooltip title={record.last_error}><Typography.Text type="danger" style={{ display: 'block', marginTop: 4, fontSize: 12 }}>最近一次失败</Typography.Text></Tooltip>
          : null}
      </div>,
    },
    {
      title: '操作', width: 300, fixed: 'right',
      render: (_, record) => <Space size={0} wrap>
        <Button type="link" size="small" icon={<EditOutlined />} disabled={!canManage} onClick={() => openEditor(record)}>编辑</Button>
        <Button type="link" size="small" disabled={!canManage || record.content_source !== 'pool'} onClick={() => void openTemplates(record)}>帖子池</Button>
        <Button type="link" size="small" icon={<SendOutlined />} loading={publishingId === record.id} disabled={!canManage} onClick={() => void publishNow(record)}>立即发一条</Button>
        <Popconfirm title="删除该发帖机器人？" description="已发布的帖子不会删除。" disabled={!canManage} onConfirm={() => void remove(record)}>
          <Button type="link" size="small" danger icon={<DeleteOutlined />} disabled={!canManage} loading={busyId === record.id}>删除</Button>
        </Popconfirm>
      </Space>,
    },
  ]

  const templateColumns: ColumnsType<XpRobotPostTemplate> = [
    { title: '标题', width: 180, render: (_, record) => <Typography.Text strong ellipsis={{ tooltip: record.title }}>{record.title}</Typography.Text> },
    { title: '正文', render: (_, record) => <Typography.Text ellipsis={{ tooltip: record.content }} style={{ maxWidth: 360 }}>{record.content}</Typography.Text> },
    { title: '状态', width: 100, render: (_, record) => <Tag color={record.enabled ? 'green' : 'default'}>{record.enabled ? '启用' : '停用'}</Tag> },
    { title: '已使用', width: 170, render: (_, record) => record.used_at ? formatRobotTime(record.used_at) : '未使用' },
    {
      title: '操作', width: 120,
      render: (_, record) => <Space size={0}>
        <Button type="link" size="small" disabled={!canManage} onClick={() => openTemplateEditor(record)}>编辑</Button>
        <Popconfirm title="删除该模板？" onConfirm={() => void removeTemplate(record)}>
          <Button type="link" size="small" danger disabled={!canManage}>删除</Button>
        </Popconfirm>
      </Space>,
    },
  ]

  return (
    <>
      {messageContext}
      {verificationContext}
      <Alert
        type="info" showIcon style={{ marginBottom: 16 }}
        message="发帖机器人按设定频率与时段自动在指定学校论坛发布帖子，用于补充平台活跃度。"
        description="内容来源支持人工帖子池、复用本校高赞历史帖子、AI 生成（需配置 ROBOT_AI_* 环境变量）。发布账号为系统虚拟用户，不影响真实学生。"
      />
      <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: 12 }}>
        <Space>
          <Button icon={<ReloadOutlined />} onClick={() => void load()} loading={loading}>刷新</Button>
          <Typography.Text type="secondary">共 {items.length} 个发帖机器人</Typography.Text>
        </Space>
        <Button type="primary" icon={<PlusOutlined />} disabled={!canManage} onClick={() => openEditor(null)}>新建发帖机器人</Button>
      </div>
      <Table rowKey="id" size="small" loading={loading} columns={columns} dataSource={items} scroll={{ x: 1200 }} pagination={false} />

      <Modal
        title={editing ? '编辑发帖机器人' : '新建发帖机器人'} open={editorOpen} width={640}
        confirmLoading={saving} onOk={() => void save()} onCancel={() => setEditorOpen(false)} okText="保存" destroyOnHidden
      >
        <Form form={form} layout="vertical" requiredMark={false}>
          <Form.Item label="名称" name="name" rules={[{ required: true, message: '请输入名称。' }]}>
            <Input maxLength={100} placeholder="例如：校园活跃小助手" />
          </Form.Item>
          <Space size={12} style={{ width: '100%' }} align="start">
            <Form.Item label="学校" name="campus_id" rules={[{ required: true, message: '请选择学校。' }]} style={{ width: 280 }}>
              <Select
                showSearch optionFilterProp="label" placeholder="选择学校"
                options={schools.map((school) => ({ label: school.name, value: school.id }))}
                onChange={(value: string) => { form.setFieldValue('category_code', undefined); void loadCategories(value) }}
              />
            </Form.Item>
            <Form.Item label="分类" name="category_code" rules={[{ required: true, message: '请选择分类。' }]} style={{ width: 200 }}>
              <Select placeholder="先选择学校" disabled={!categories.length}
                options={categories.map((category) => ({ label: category.name, value: category.code }))} />
            </Form.Item>
            <Form.Item label="启用" name="enabled" valuePropName="checked">
              <Switch checkedChildren="启用" unCheckedChildren="停用" />
            </Form.Item>
          </Space>
          <Form.Item label="内容来源" name="content_source" rules={[{ required: true }]}>
            <Select options={[
              { label: '人工帖子池（推荐，可预览审核）', value: 'pool' },
              { label: '复用本校高赞历史帖子', value: 'history' },
              { label: 'AI 生成（需配置模型服务）', value: 'ai' },
            ]} />
          </Form.Item>
          <Form.Item noStyle shouldUpdate={(prev, next) => prev.content_source !== next.content_source}>
            {({ getFieldValue }) => {
              const source = getFieldValue('content_source')
              if (source === 'history') {
                return <Form.Item label="历史帖子最低点赞数" name="history_min_likes">
                  <InputNumber min={0} max={100000} style={{ width: 200 }} />
                </Form.Item>
              }
              if (source === 'ai') {
                return <Form.Item label="生成要求（可选）" name="ai_prompt">
                  <Input.TextArea maxLength={1000} rows={3} showCount placeholder="例如：围绕食堂、图书馆、选课等校园话题，语气自然，避免广告。" />
                </Form.Item>
              }
              return null
            }}
          </Form.Item>
          <Space size={12} align="start" wrap>
            <Form.Item label="每日最多" name="posts_per_day" rules={[{ required: true }]}>
              <InputNumber min={1} max={10000} style={{ width: 120 }} />
            </Form.Item>
            <Form.Item label="每轮发布（条）" name="posts_per_run" rules={[{ required: true }]} tooltip="调度每分钟执行一次，压测转发链路时可设为 3。">
              <InputNumber min={1} max={20} style={{ width: 130 }} />
            </Form.Item>
            <Form.Item label="最短间隔（分钟）" name="min_gap_minutes" rules={[{ required: true }]}>
              <InputNumber min={1} max={1440} style={{ width: 150 }} />
            </Form.Item>
            <Form.Item label="活跃时段" required style={{ marginBottom: 0 }}>
              <Space.Compact>
                <Form.Item name="active_start" noStyle rules={[{ required: true, ...TIME_RULE }]}>
                  <Input placeholder="09:00" style={{ width: 90 }} />
                </Form.Item>
                <Input style={{ width: 32, pointerEvents: 'none', textAlign: 'center' }} value="—" readOnly />
                <Form.Item name="active_end" noStyle rules={[{ required: true, ...TIME_RULE }]}>
                  <Input placeholder="22:00" style={{ width: 90 }} />
                </Form.Item>
              </Space.Compact>
            </Form.Item>
          </Space>
          <Form.Item label="活跃星期" name="active_weekdays" rules={[{ required: true, message: '请至少选择一天。' }]}>
            <Checkbox.Group options={WEEKDAYS} />
          </Form.Item>
        </Form>
      </Modal>

      <Drawer
        title={templatesFor ? `帖子池 · ${templatesFor.name}` : '帖子池'} width={720} open={Boolean(templatesFor)}
        onClose={() => setTemplatesFor(null)} destroyOnHidden
        extra={<Button type="primary" size="small" icon={<PlusOutlined />} disabled={!canManage} onClick={() => openTemplateEditor(null)}>添加模板</Button>}
      >
        <Typography.Paragraph type="secondary" style={{ fontSize: 12 }}>
          支持变量 {'${campus}'}（学校名）、{'${date}'}（当天日期）、{'${weekday}'}（星期）、{'${category}'}（分类名）。模板用完后会从头循环。
        </Typography.Paragraph>
        <Table rowKey="id" size="small" loading={templatesLoading} columns={templateColumns} dataSource={templates} pagination={false} />
      </Drawer>

      <Modal
        title={templateEditing ? '编辑帖子模板' : '添加帖子模板'} open={templateOpen} width={620}
        confirmLoading={templateSaving} onOk={() => void saveTemplate()} onCancel={() => setTemplateOpen(false)} okText="保存" destroyOnHidden
      >
        <Form form={templateForm} layout="vertical" requiredMark={false}>
          <Form.Item label="标题" name="title" rules={[{ required: true, min: 5, max: 100, message: '标题需 5–100 字。' }]}>
            <Input maxLength={100} />
          </Form.Item>
          <Form.Item label="正文" name="content" rules={[{ required: true, min: 10, max: 5000, message: '正文需 10–5000 字。' }]}>
            <Input.TextArea rows={6} maxLength={5000} showCount />
          </Form.Item>
          <Space size={12}>
            <Form.Item label="启用" name="enabled" valuePropName="checked"><Switch /></Form.Item>
            <Form.Item label="排序" name="sort"><InputNumber min={0} max={9999} style={{ width: 120 }} /></Form.Item>
          </Space>
        </Form>
      </Modal>
    </>
  )
}
