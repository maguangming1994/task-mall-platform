import { Button, Form, Input, InputNumber, Modal, Select, Table, message } from 'antd'
import { useCallback, useEffect, useMemo, useState } from 'react'
import {
  createProductCategory,
  listProductCategories,
  updateProductCategory,
  type ManagedProductCategory,
} from '../../api/product-categories'
import { Links, PageHead, Pill } from '../../components/xq'

interface CategoryFormValues {
  name: string
  slug: string
  sort_order: number
  status: 'active' | 'inactive'
}

interface EditorState {
  category: ManagedProductCategory | null
  parent: ManagedProductCategory | null
}

export function CategoriesPage() {
  const [items, setItems] = useState<ManagedProductCategory[]>([])
  const [loading, setLoading] = useState(false)
  const [submitting, setSubmitting] = useState(false)
  const [editor, setEditor] = useState<EditorState | null>(null)
  const [form] = Form.useForm<CategoryFormValues>()
  const [messageApi, contextHolder] = message.useMessage()

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const result = await listProductCategories()
      setItems(result.items)
    } catch (cause) {
      messageApi.error(cause instanceof Error ? cause.message : '商品分类加载失败。')
    } finally {
      setLoading(false)
    }
  }, [messageApi])

  useEffect(() => { void load() }, [load])

  const rows = useMemo(() => {
    const roots = items.filter((item) => !item.parent_id).sort((a, b) => a.sort_order - b.sort_order)
    const childrenOf = (id: string) => items.filter((item) => item.parent_id === id).sort((a, b) => a.sort_order - b.sort_order)
    return roots.flatMap((root) => [root, ...childrenOf(root.id)])
  }, [items])

  const openEditor = (state: EditorState) => {
    form.resetFields()
    if (state.category) {
      form.setFieldsValue({
        name: state.category.name,
        slug: state.category.slug,
        sort_order: state.category.sort_order,
        status: state.category.status,
      })
    } else {
      form.setFieldsValue({ name: '', slug: '', sort_order: items.length + 1, status: 'active' })
    }
    setEditor(state)
  }

  const save = async (values: CategoryFormValues) => {
    if (!editor) return
    setSubmitting(true)
    try {
      if (editor.category) {
        await updateProductCategory(editor.category.id, {
          name: values.name,
          slug: values.slug,
          sort_order: values.sort_order,
          status: values.status,
          parent_id: editor.category.parent_id,
        })
        messageApi.success('分类已更新。')
      } else {
        await createProductCategory({
          name: values.name,
          slug: values.slug,
          sort_order: values.sort_order,
          status: values.status,
          parent_id: editor.parent?.id ?? null,
        })
        messageApi.success('分类已创建。')
      }
      setEditor(null)
      await load()
    } catch (cause) {
      messageApi.error(cause instanceof Error ? cause.message : '分类保存失败。')
    } finally {
      setSubmitting(false)
    }
  }

  const toggleVisibility = async (category: ManagedProductCategory) => {
    const next = category.status === 'active' ? 'inactive' as const : 'active' as const
    try {
      await updateProductCategory(category.id, {
        name: category.name,
        slug: category.slug,
        sort_order: category.sort_order,
        status: next,
        parent_id: category.parent_id,
      })
      messageApi.success(next === 'active' ? '分类已显示。' : '分类已隐藏。')
      await load()
    } catch (cause) {
      messageApi.error(cause instanceof Error ? cause.message : '分类状态变更失败。')
    }
  }

  return (
    <div className="xq-page">
      {contextHolder}
      <PageHead title="商品分类管理" extra={<Button type="primary" onClick={() => openEditor({ category: null, parent: null })}>+ 新增分类</Button>} />

      <div className="xq-card xq-table">
        <Table
          rowKey="id"
          size="middle"
          loading={loading}
          dataSource={rows}
          pagination={{ pageSize: 10, showTotal: (total) => `共 ${total} 条` }}
          columns={[
            { title: '排序', dataIndex: 'sort_order', width: 70 },
            {
              title: '分类名称',
              render: (_: unknown, item: ManagedProductCategory) => (
                <span style={{ paddingLeft: item.parent_id ? 28 : 0, fontWeight: item.parent_id ? 400 : 700 }}>{item.name}</span>
              ),
            },
            {
              title: '层级',
              render: (_: unknown, item: ManagedProductCategory) => (
                <Pill tone="blue">{item.parent_id ? '二级' : '一级'}</Pill>
              ),
            },
            { title: '商品数', dataIndex: 'product_count' },
            {
              title: '状态',
              render: (_: unknown, item: ManagedProductCategory) => (
                item.status === 'active' ? <Pill tone="green">显示中</Pill> : <Pill tone="gray">已隐藏</Pill>
              ),
            },
            {
              title: '操作',
              render: (_: unknown, item: ManagedProductCategory) => (
                <Links items={[
                  { label: '编辑', onClick: () => openEditor({ category: item, parent: null }) },
                  !item.parent_id && { label: '子分类', onClick: () => openEditor({ category: null, parent: item }) },
                  item.status === 'active'
                    ? { label: '隐藏', danger: true, onClick: () => void toggleVisibility(item) }
                    : { label: '显示', onClick: () => void toggleVisibility(item) },
                ]} />
              ),
            },
          ]}
        />
      </div>

      <Modal
        title={editor?.category ? '编辑分类' : editor?.parent ? `新增子分类 · ${editor.parent.name}` : '新增分类'}
        open={editor !== null}
        onCancel={() => setEditor(null)}
        onOk={() => form.submit()}
        okText="保存"
        cancelText="取消"
        confirmLoading={submitting}
        destroyOnHidden
      >
        <Form form={form} layout="vertical" onFinish={save}>
          <Form.Item name="name" label="分类名称" rules={[{ required: true, message: '请输入分类名称' }]}>
            <Input maxLength={60} placeholder="例如 数码电子" />
          </Form.Item>
          <Form.Item name="slug" label="分类标识（slug）" rules={[{ required: true, message: '请输入分类标识' }]}>
            <Input maxLength={60} placeholder="例如 digital" />
          </Form.Item>
          <Form.Item name="sort_order" label="排序" rules={[{ required: true, message: '请输入排序值' }]}>
            <InputNumber min={0} precision={0} style={{ width: '100%' }} />
          </Form.Item>
          <Form.Item name="status" label="状态" rules={[{ required: true }]}>
            <Select options={[{ value: 'active', label: '显示中' }, { value: 'inactive', label: '已隐藏' }]} />
          </Form.Item>
        </Form>
      </Modal>
    </div>
  )
}
