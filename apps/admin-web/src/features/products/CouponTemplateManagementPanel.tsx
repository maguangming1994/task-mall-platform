import { EditOutlined, PauseCircleOutlined, PlayCircleOutlined, PlusOutlined } from '@ant-design/icons'
import { Alert, Button, Card, Form, Input, InputNumber, Modal, Space, Table, Tag, Typography, message } from 'antd'
import { useCallback, useEffect, useState } from 'react'
import {
  ApiError,
  changeCouponTemplateStatus,
  createCouponTemplate,
  createCouponTemplateVersion,
  listCouponTemplates,
  type ManagedCouponTemplate,
} from '../../api/admin'

interface CouponTemplateForm {
  name: string
  cash_discount_cents: number
  points_discount: number
  minimum_cash_cents: number
  minimum_points: number
  valid_days: number
  total_quantity: number
  reason?: string
}

interface StatusForm { reason: string }

const emptyTemplate: CouponTemplateForm = {
  name: '', cash_discount_cents: 0, points_discount: 0, minimum_cash_cents: 0,
  minimum_points: 0, valid_days: 30, total_quantity: 100,
}

export function CouponTemplateManagementPanel() {
  const [items, setItems] = useState<ManagedCouponTemplate[]>([])
  const [loading, setLoading] = useState(false)
  const [submitting, setSubmitting] = useState(false)
  const [editorOpen, setEditorOpen] = useState(false)
  const [source, setSource] = useState<ManagedCouponTemplate | null>(null)
  const [statusTarget, setStatusTarget] = useState<ManagedCouponTemplate | null>(null)
  const [statusAction, setStatusAction] = useState<'enable' | 'disable'>('enable')
  const [form] = Form.useForm<CouponTemplateForm>()
  const [statusForm] = Form.useForm<StatusForm>()
  const [messageApi, contextHolder] = message.useMessage()

  const load = useCallback(async () => {
    setLoading(true)
    try { setItems((await listCouponTemplates()).items) }
    catch (cause) { messageApi.error(cause instanceof ApiError ? cause.message : '优惠券模板加载失败。') }
    finally { setLoading(false) }
  }, [messageApi])

  useEffect(() => { void load() }, [load])

  const openEditor = (template?: ManagedCouponTemplate) => {
    setSource(template ?? null)
    form.setFieldsValue(template ? {
      name: template.name,
      cash_discount_cents: template.cash_discount_cents,
      points_discount: template.points_discount,
      minimum_cash_cents: template.minimum_cash_cents,
      minimum_points: template.minimum_points,
      valid_days: template.valid_days,
      total_quantity: template.total_quantity,
      reason: '',
    } : emptyTemplate)
    setEditorOpen(true)
  }

  const save = async (values: CouponTemplateForm) => {
    setSubmitting(true)
    try {
      const definition = {
        name: values.name,
        cash_discount_cents: values.cash_discount_cents,
        points_discount: values.points_discount,
        minimum_cash_cents: values.minimum_cash_cents,
        minimum_points: values.minimum_points,
        valid_days: values.valid_days,
        total_quantity: values.total_quantity,
      }
      if (source) await createCouponTemplateVersion(source.id, { ...definition, reason: values.reason || '基于现有模板创建新的优惠券版本。' })
      else await createCouponTemplate(definition)
      setEditorOpen(false); setSource(null); form.resetFields(); await load()
      messageApi.success(source ? '已创建独立的新模板版本，原模板与已绑定任务未被改动。' : '优惠券模板已创建。')
    } catch (cause) { messageApi.error(cause instanceof ApiError ? cause.message : '优惠券模板保存失败。') }
    finally { setSubmitting(false) }
  }

  const openStatus = (template: ManagedCouponTemplate, action: 'enable' | 'disable') => {
    setStatusTarget(template); setStatusAction(action); statusForm.setFieldsValue({ reason: '' })
  }

  const saveStatus = async ({ reason }: StatusForm) => {
    if (!statusTarget) return
    setSubmitting(true)
    try {
      await changeCouponTemplateStatus(statusTarget.id, statusAction, statusTarget.version, reason)
      setStatusTarget(null); statusForm.resetFields(); await load()
      messageApi.success(statusAction === 'enable' ? '模板已启用。' : '模板已停用。')
    } catch (cause) { messageApi.error(cause instanceof ApiError ? cause.message : '模板状态更新失败。') }
    finally { setSubmitting(false) }
  }

  return <>
    {contextHolder}
    <Card bordered={false} style={{ marginTop: 16 }} title="优惠券模板" extra={<Button type="primary" icon={<PlusOutlined />} onClick={() => openEditor()}>新建优惠券</Button>}>
      <Alert showIcon type="info" message="模板作为任务奖励与商城抵扣的共同来源。编辑会创建一个新的独立模板版本，不会改变已发券快照、已发布任务或既有预算池。" style={{ marginBottom: 16 }} />
      <Table rowKey="id" loading={loading} dataSource={items} pagination={false} scroll={{ x: 980 }} columns={[
        { title: '模板', width: 190, render: (_: unknown, item: ManagedCouponTemplate) => <Space direction="vertical" size={0}><strong>{item.name}</strong><Typography.Text type="secondary">数据版本 {item.version}</Typography.Text></Space> },
        { title: '抵扣', width: 180, render: (_: unknown, item: ManagedCouponTemplate) => <Space direction="vertical" size={0}><span>{item.cash_discount_cents ? `现金 ¥${(item.cash_discount_cents / 100).toFixed(2)}` : '无现金抵扣'}</span><span>{item.points_discount ? `积分 ${item.points_discount}` : '无积分抵扣'}</span></Space> },
        { title: '使用门槛', width: 190, render: (_: unknown, item: ManagedCouponTemplate) => <Space direction="vertical" size={0}><span>现金满 ¥{(item.minimum_cash_cents / 100).toFixed(2)}</span><span>积分满 {item.minimum_points}</span></Space> },
        { title: '有效期', dataIndex: 'valid_days', render: (value: number) => `领取后 ${value} 天` },
        { title: '发行进度', render: (_: unknown, item: ManagedCouponTemplate) => `${item.issued_quantity} / ${item.total_quantity}（剩 ${item.remaining_quantity}）` },
        { title: '状态', dataIndex: 'status', render: (value: string) => <Tag color={value === 'active' ? 'success' : 'default'}>{value === 'active' ? '已启用' : '已停用'}</Tag> },
        { title: '操作', width: 210, fixed: 'right', render: (_: unknown, item: ManagedCouponTemplate) => <Space wrap><Button icon={<EditOutlined />} onClick={() => openEditor(item)}>编辑为新版本</Button>{item.status === 'active' ? <Button danger icon={<PauseCircleOutlined />} onClick={() => openStatus(item, 'disable')}>停用</Button> : <Button icon={<PlayCircleOutlined />} onClick={() => openStatus(item, 'enable')}>启用</Button>}</Space> },
      ]} />
    </Card>

    <Modal title={source ? `基于“${source.name}”创建新版本` : '新建优惠券模板'} open={editorOpen} onCancel={() => { setEditorOpen(false); setSource(null) }} onOk={() => form.submit()} confirmLoading={submitting} okText={source ? '创建新版本' : '创建模板'} cancelText="取消" destroyOnHidden>
      <Form form={form} layout="vertical" onFinish={save} initialValues={emptyTemplate}>
        {source && <Alert showIcon type="warning" message="新版本默认停用，确认配置与预算后再启用；原模板不会被覆盖。" style={{ marginBottom: 16 }} />}
        <Form.Item name="name" label="模板名称" rules={[{ required: true }]}><Input maxLength={120} /></Form.Item>
        <Space align="start" wrap><Form.Item name="cash_discount_cents" label="现金抵扣（分）" rules={[{ required: true }]}><InputNumber min={0} /></Form.Item><Form.Item name="points_discount" label="积分抵扣" rules={[{ required: true }]}><InputNumber min={0} /></Form.Item></Space>
        <Space align="start" wrap><Form.Item name="minimum_cash_cents" label="现金门槛（分）" rules={[{ required: true }]}><InputNumber min={0} /></Form.Item><Form.Item name="minimum_points" label="积分门槛" rules={[{ required: true }]}><InputNumber min={0} /></Form.Item></Space>
        <Space align="start" wrap><Form.Item name="valid_days" label="领取后有效天数" rules={[{ required: true }]}><InputNumber min={1} max={3650} /></Form.Item><Form.Item name="total_quantity" label="发行总量" rules={[{ required: true }]}><InputNumber min={1} max={100000000} /></Form.Item></Space>
        {source && <Form.Item name="reason" label="新版本原因" rules={[{ required: true }]}><Input.TextArea rows={3} maxLength={500} /></Form.Item>}
      </Form>
    </Modal>

    <Modal title={statusAction === 'enable' ? '启用优惠券模板' : '停用优惠券模板'} open={statusTarget !== null} onCancel={() => setStatusTarget(null)} onOk={() => statusForm.submit()} confirmLoading={submitting} okButtonProps={{ danger: statusAction === 'disable' }} okText={statusAction === 'enable' ? '确认启用' : '确认停用'} cancelText="取消" destroyOnHidden>
      <Form form={statusForm} layout="vertical" onFinish={saveStatus}>
        {statusAction === 'disable' && <Alert showIcon type="warning" message="若仍有已发布任务锁定该模板预算，系统会拒绝停用；已经发到用户账户的优惠券不受影响。" style={{ marginBottom: 16 }} />}
        <Form.Item name="reason" label="操作原因" rules={[{ required: true }]}><Input.TextArea rows={3} maxLength={500} /></Form.Item>
      </Form>
    </Modal>
  </>
}
