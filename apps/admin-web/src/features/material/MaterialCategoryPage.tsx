import { useCallback, useEffect, useState } from 'react'
import { Button, Form, Input, InputNumber, Modal, Table, message } from 'antd'
import type { ColumnsType } from 'antd/es/table'
import {
  createMaterialCategory,
  deleteMaterialCategory,
  listMaterialCategories,
  updateMaterialCategory,
  type MaterialCategoryRow,
} from '../../api/material'
import { Links, PageHead, Pill } from '../../components/xq'

export function MaterialCategoryPage() {
  const [messageApi, contextHolder] = message.useMessage()
  const [items, setItems] = useState<MaterialCategoryRow[]>([])
  const [loading, setLoading] = useState(false)
  const [editing, setEditing] = useState<MaterialCategoryRow | null>(null)
  const [editorOpen, setEditorOpen] = useState(false)
  const [deleteTarget, setDeleteTarget] = useState<MaterialCategoryRow | null>(null)
  const [submitting, setSubmitting] = useState(false)
  const [form] = Form.useForm<{ name: string; sort_order: number }>()

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const res = await listMaterialCategories()
      setItems(res.items)
    } catch {
      messageApi.error('分类加载失败，请稍后重试。')
    } finally {
      setLoading(false)
    }
  }, [messageApi])

  useEffect(() => { void load() }, [load])

  const openCreate = () => {
    setEditing(null)
    form.setFieldsValue({ name: '', sort_order: (items.at(-1)?.sort_order ?? 0) + 10 })
    setEditorOpen(true)
  }

  const openEdit = (row: MaterialCategoryRow) => {
    setEditing(row)
    form.setFieldsValue({ name: row.name, sort_order: row.sort_order })
    setEditorOpen(true)
  }

  const submit = async (values: { name: string; sort_order: number }) => {
    setSubmitting(true)
    try {
      if (editing) {
        await updateMaterialCategory(editing.id, values)
        messageApi.success('分类已更新。')
      } else {
        await createMaterialCategory(values)
        messageApi.success('分类已创建。')
      }
      setEditorOpen(false)
      await load()
    } catch {
      messageApi.error('保存失败，请稍后重试。')
    } finally {
      setSubmitting(false)
    }
  }

  const toggleStatus = async (row: MaterialCategoryRow) => {
    try {
      await updateMaterialCategory(row.id, { status: row.status === 'active' ? 'disabled' : 'active' })
      messageApi.success(row.status === 'active' ? '分类已停用。' : '分类已启用。')
      await load()
    } catch {
      messageApi.error('操作失败，请稍后重试。')
    }
  }

  const remove = async () => {
    if (!deleteTarget) return
    setSubmitting(true)
    try {
      await deleteMaterialCategory(deleteTarget.id)
      messageApi.success('分类已删除。')
      setDeleteTarget(null)
      await load()
    } catch {
      messageApi.error('该分类下已有资料，无法删除，可先停用分类。')
    } finally {
      setSubmitting(false)
    }
  }

  const columns: ColumnsType<MaterialCategoryRow> = [
    { title: '分类名称', dataIndex: 'name' },
    { title: '排序', dataIndex: 'sort_order', width: 100 },
    { title: '已上架资料', dataIndex: 'material_count', width: 120 },
    {
      title: '状态',
      width: 100,
      render: (_, r) => <Pill tone={r.status === 'active' ? 'green' : 'gray'}>{r.status === 'active' ? '启用' : '停用'}</Pill>,
    },
    {
      title: '操作',
      width: 200,
      render: (_, r) => (
        <Links items={[
          { label: '编辑', onClick: () => openEdit(r) },
          { label: r.status === 'active' ? '停用' : '启用', onClick: () => void toggleStatus(r) },
          { label: '删除', danger: true, onClick: () => setDeleteTarget(r) },
        ]} />
      ),
    },
  ]

  return (
    <div className="xq-page">
      {contextHolder}
      <PageHead title="资料分类" extra={<Button type="primary" onClick={openCreate}>新增分类</Button>} />
      <div className="xq-card xq-table">
        <Table rowKey="id" size="middle" loading={loading} columns={columns} dataSource={items} pagination={false} />
      </div>

      <Modal
        title={editing ? '编辑分类' : '新增分类'}
        open={editorOpen}
        onCancel={() => setEditorOpen(false)}
        onOk={() => form.submit()}
        confirmLoading={submitting}
        okText="保存"
        cancelText="取消"
        destroyOnClose
      >
        <Form form={form} layout="vertical" onFinish={submit}>
          <Form.Item name="name" label="分类名称" rules={[{ required: true, message: '请输入分类名称' }]}>
            <Input maxLength={40} placeholder="例如：考研资料" />
          </Form.Item>
          <Form.Item name="sort_order" label="排序（数字越小越靠前）">
            <InputNumber min={0} max={9999} style={{ width: '100%' }} />
          </Form.Item>
        </Form>
      </Modal>

      <Modal
        title="删除分类"
        open={!!deleteTarget}
        onCancel={() => setDeleteTarget(null)}
        onOk={() => void remove()}
        confirmLoading={submitting}
        okText="确认删除"
        okButtonProps={{ danger: true }}
        cancelText="取消"
      >
        确认删除分类「{deleteTarget?.name}」？分类下已有资料时不可删除。
      </Modal>
    </div>
  )
}
