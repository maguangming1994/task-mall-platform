import { DeleteOutlined, EditOutlined, PlusOutlined } from '@ant-design/icons'
import { Button, Card, Form, Input, InputNumber, Modal, Select, Space, Table, Tag, Typography, message } from 'antd'
import { useState } from 'react'
import { ApiError } from '../../api/admin'
import { createProductCategory, deleteProductCategory, updateProductCategory, type ManagedProductCategory, type ProductCategoryInput } from '../../api/product-categories'

interface Props {
  categories: ManagedProductCategory[]
  loading: boolean
  onChanged: () => Promise<void>
}

const initialValues: ProductCategoryInput = { parent_id: null, name: '', slug: '', status: 'active', sort_order: 0 }

export function ProductCategoryManagementPanel({ categories, loading, onChanged }: Props) {
  const [editing, setEditing] = useState<ManagedProductCategory | null>(null)
  const [open, setOpen] = useState(false)
  const [saving, setSaving] = useState(false)
  const [form] = Form.useForm<ProductCategoryInput>()
  const [messageApi, contextHolder] = message.useMessage()

  const showEditor = (category?: ManagedProductCategory) => {
    setEditing(category ?? null)
    form.setFieldsValue(category ? {
      parent_id: category.parent_id,
      name: category.name,
      slug: category.slug,
      status: category.status,
      sort_order: category.sort_order,
    } : initialValues)
    setOpen(true)
  }

  const save = async (values: ProductCategoryInput) => {
    setSaving(true)
    try {
      if (editing) await updateProductCategory(editing.id, values)
      else await createProductCategory(values)
      setOpen(false)
      setEditing(null)
      form.resetFields()
      await onChanged()
      messageApi.success(editing ? '商品分类已更新。' : '商品分类已创建。')
    } catch (error) {
      messageApi.error(error instanceof ApiError ? error.message : '商品分类保存失败。')
    } finally {
      setSaving(false)
    }
  }

  const remove = (category: ManagedProductCategory) => Modal.confirm({
    title: `删除分类“${category.name}”`,
    content: '仅无关联商品、无下级分类的空分类可以删除。此操作不可撤销。',
    okText: '确认删除',
    okButtonProps: { danger: true },
    cancelText: '取消',
    onOk: async () => {
      try {
        await deleteProductCategory(category.id)
        await onChanged()
        messageApi.success('商品分类已删除。')
      } catch (error) {
        messageApi.error(error instanceof ApiError ? error.message : '商品分类删除失败。')
      }
    },
  })

  return <>
    {contextHolder}
    <Card bordered={false} style={{ marginTop: 16 }} title="商品分类" extra={<Button type="primary" icon={<PlusOutlined />} onClick={() => showEditor()}>新建分类</Button>}>
      <Typography.Paragraph type="secondary">商城前台只展示已启用且存在在售商品的分类；排序值越小越靠前。</Typography.Paragraph>
      <Table rowKey="id" loading={loading} dataSource={categories} pagination={false} columns={[
        { title: '分类名称', render: (_: unknown, item: ManagedProductCategory) => <Space direction="vertical" size={0}><strong>{item.name}</strong>{item.parent_name && <Typography.Text type="secondary">上级：{item.parent_name}</Typography.Text>}</Space> },
        { title: '标识', dataIndex: 'slug' },
        { title: '排序', dataIndex: 'sort_order', width: 90 },
        { title: '商品数', dataIndex: 'product_count', width: 90 },
        { title: '状态', dataIndex: 'status', width: 100, render: (status: ManagedProductCategory['status']) => <Tag color={status === 'active' ? 'success' : 'default'}>{status === 'active' ? '已启用' : '已停用'}</Tag> },
        { title: '操作', width: 180, render: (_: unknown, item: ManagedProductCategory) => <Space><Button icon={<EditOutlined />} onClick={() => showEditor(item)}>编辑</Button><Button danger type="text" icon={<DeleteOutlined />} disabled={item.product_count > 0 || item.children_count > 0} onClick={() => remove(item)}>删除</Button></Space> },
      ]} />
    </Card>
    <Modal title={editing ? '编辑商品分类' : '新建商品分类'} open={open} onCancel={() => setOpen(false)} onOk={() => form.submit()} confirmLoading={saving} okText="保存" cancelText="取消">
      <Form form={form} layout="vertical" initialValues={initialValues} onFinish={save}>
        <Form.Item name="name" label="分类名称" rules={[{ required: true, message: '请输入分类名称' }]}><Input maxLength={80} placeholder="例如：生活补给" /></Form.Item>
        <Form.Item name="slug" label="分类标识" extra="仅支持字母、数字、中划线和下划线；用于接口与运营识别。" rules={[{ required: true, message: '请输入分类标识' }, { pattern: /^[A-Za-z0-9_-]+$/, message: '分类标识格式不正确' }]}><Input maxLength={80} placeholder="例如：daily-goods" /></Form.Item>
        <Form.Item name="parent_id" label="上级分类"><Select allowClear placeholder="无上级分类" options={categories.filter(item => item.id !== editing?.id).map(item => ({ value: item.id, label: item.name }))} /></Form.Item>
        <Space align="start">
          <Form.Item name="sort_order" label="排序值" rules={[{ required: true }]}><InputNumber min={0} precision={0} /></Form.Item>
          <Form.Item name="status" label="状态" rules={[{ required: true }]}><Select style={{ width: 150 }} options={[{ value: 'active', label: '启用' }, { value: 'inactive', label: '停用' }]} /></Form.Item>
        </Space>
      </Form>
    </Modal>
  </>
}
