import { Button, Form, Input, Modal, Select, Table, message } from 'antd'
import { useCallback, useEffect, useMemo, useState } from 'react'
import {
  ApiError,
  changeManagedTaskTemplateStatus,
  createManagedTaskTemplate,
  listManagedTaskTemplates,
  type ManagedTaskTemplate,
} from '../../api/admin'
import { Links, PageHead, Pill } from '../../components/xq'

interface CreateForm {
  code: string
  name: string
  description?: string
}

function templatePoints(template: ManagedTaskTemplate) {
  const rewards = (template.content?.rewards ?? []) as Array<{ type?: string; points?: number }>
  const reward = rewards.find((item) => item.type === 'points')
  return reward?.points ?? '—'
}

export function TaskTemplatesPage() {
  const [items, setItems] = useState<ManagedTaskTemplate[]>([])
  const [loading, setLoading] = useState(false)
  const [submitting, setSubmitting] = useState(false)
  const [category, setCategory] = useState('')
  const [status, setStatus] = useState('')
  const [keywordInput, setKeywordInput] = useState('')
  const [keyword, setKeyword] = useState('')
  const [createOpen, setCreateOpen] = useState(false)
  const [form] = Form.useForm<CreateForm>()
  const [messageApi, contextHolder] = message.useMessage()

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const result = await listManagedTaskTemplates()
      setItems(result.items)
    } catch (cause) {
      messageApi.error(cause instanceof ApiError ? cause.message : '任务模板加载失败。')
    } finally {
      setLoading(false)
    }
  }, [messageApi])

  useEffect(() => { void load() }, [load])

  const categories = useMemo(
    () => Array.from(new Set(items.map((item) => item.category).filter((value): value is string => Boolean(value)))),
    [items],
  )

  const filtered = useMemo(() => items.filter((item) => {
    if (category && item.category !== category) return false
    if (status && item.status !== status) return false
    if (keyword && !item.name.toLowerCase().includes(keyword.toLowerCase())) return false
    return true
  }), [items, category, status, keyword])

  const toggleStatus = (template: ManagedTaskTemplate) => {
    const enable = template.status !== 'active'
    Modal.confirm({
      title: enable ? `启用模板“${template.name}”` : `停用模板“${template.name}”`,
      content: enable ? '启用后用户可继续基于该模板发布任务。' : '停用后该模板不再出现在发布入口，历史任务不受影响。',
      okText: enable ? '确认启用' : '确认停用',
      cancelText: '取消',
      onOk: async () => {
        try {
          await changeManagedTaskTemplateStatus(template.id, {
            action: enable ? 'enable' : 'disable',
            version: template.version,
            reason: enable ? '后台运营重新启用模板。' : '后台运营停用模板。',
          })
          messageApi.success(enable ? '模板已启用。' : '模板已停用。')
          await load()
        } catch (cause) {
          messageApi.error(cause instanceof ApiError ? cause.message : '模板状态变更失败。')
        }
      },
    })
  }

  const create = async (values: CreateForm) => {
    setSubmitting(true)
    try {
      await createManagedTaskTemplate({ code: values.code, name: values.name, description: values.description || null })
      messageApi.success('模板已创建。')
      setCreateOpen(false)
      form.resetFields()
      await load()
    } catch (cause) {
      messageApi.error(cause instanceof ApiError ? cause.message : '模板创建失败。')
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <div className="xq-page">
      {contextHolder}
      <PageHead title="任务模板中心" extra={<Button type="primary" onClick={() => setCreateOpen(true)}>+ 新增模板</Button>} />

      <div className="xq-card xq-filter" style={{ marginBottom: 16 }}>
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
          value={status || 'all'}
          style={{ minWidth: 130 }}
          onChange={(value) => setStatus(value === 'all' ? '' : value)}
          options={[
            { value: 'all', label: '全部状态' },
            { value: 'active', label: '启用中' },
            { value: 'inactive', label: '已停用' },
          ]}
        />
        <Input
          placeholder="模板名称"
          style={{ maxWidth: 220 }}
          value={keywordInput}
          onChange={(event) => setKeywordInput(event.target.value)}
          onPressEnter={() => setKeyword(keywordInput.trim())}
          allowClear
        />
        <Button type="primary" onClick={() => setKeyword(keywordInput.trim())}>查询</Button>
        <Button onClick={() => { setCategory(''); setStatus(''); setKeywordInput(''); setKeyword('') }}>重置</Button>
      </div>

      <div className="xq-card xq-table">
        <Table
          rowKey="id"
          size="middle"
          loading={loading}
          dataSource={filtered}
          pagination={{ pageSize: 10, showTotal: (total) => `共 ${total} 条` }}
          columns={[
            {
              title: '模板名称',
              render: (_: unknown, item: ManagedTaskTemplate) => (
                <span>
                  <span style={{ fontWeight: 600, display: 'block' }}>{item.name}</span>
                  <span style={{ color: '#6b7280', fontSize: 11 }}>{item.description || '—'}</span>
                </span>
              ),
            },
            { title: '类型', dataIndex: 'category', render: (value: string | null) => value || '—' },
            { title: '默认积分', render: (_: unknown, item: ManagedTaskTemplate) => templatePoints(item) },
            { title: '积分区间', render: () => '—' },
            { title: '使用次数', render: () => '—' },
            {
              title: '状态',
              render: (_: unknown, item: ManagedTaskTemplate) => (
                item.status === 'active'
                  ? <Pill tone="green">{item.status_label || '启用中'}</Pill>
                  : <Pill tone="gray">{item.status_label || '已停用'}</Pill>
              ),
            },
            {
              title: '操作',
              render: (_: unknown, item: ManagedTaskTemplate) => (
                <Links items={[
                  { label: '编辑', onClick: () => messageApi.info('模板内容编辑能力待接入。') },
                  item.status === 'active'
                    ? { label: '停用', onClick: () => toggleStatus(item) }
                    : { label: '启用', onClick: () => toggleStatus(item) },
                ]} />
              ),
            },
          ]}
        />
      </div>

      <Modal
        title="新增任务模板"
        open={createOpen}
        onCancel={() => setCreateOpen(false)}
        onOk={() => form.submit()}
        okText="创建模板"
        cancelText="取消"
        confirmLoading={submitting}
        destroyOnHidden
      >
        <Form form={form} layout="vertical" onFinish={create}>
          <Form.Item name="code" label="模板编码" rules={[{ required: true, message: '请输入模板编码' }]}>
            <Input maxLength={64} placeholder="例如 errand-express" />
          </Form.Item>
          <Form.Item name="name" label="模板名称" rules={[{ required: true, message: '请输入模板名称' }]}>
            <Input maxLength={120} placeholder="例如 代取快递" />
          </Form.Item>
          <Form.Item name="description" label="模板说明">
            <Input.TextArea rows={3} maxLength={500} placeholder="模板包含的字段与使用说明" />
          </Form.Item>
        </Form>
      </Modal>
    </div>
  )
}
