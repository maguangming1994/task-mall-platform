import { useCallback, useEffect, useMemo, useState } from 'react'
import { Button, Descriptions, Form, Input, InputNumber, Modal, Select, Table, message } from 'antd'
import type { ColumnsType } from 'antd/es/table'
import {
  changeCouponTemplateStatus,
  createCouponTemplate,
  listCouponTemplates,
  type ManagedCouponTemplate,
} from '../../api/admin'
import { Links, PageHead, Pill, StatCard } from '../../components/xq'

const yuan = (cents?: number | null) => `¥${((cents ?? 0) / 100).toFixed(0)}`
const fmtTime = (v?: string | null) => (v ? new Date(v).toLocaleString('zh-CN', { hour12: false }) : '—')

interface CreateFormValue { name: string; discount: number; total: number; validDays: number; threshold?: number }

export function CouponsPage() {
  const [messageApi, contextHolder] = message.useMessage()
  const [items, setItems] = useState<ManagedCouponTemplate[]>([])
  const [status, setStatus] = useState('')
  const [name, setName] = useState('')
  const [applied, setApplied] = useState({ status: '', name: '' })
  const [loading, setLoading] = useState(false)
  const [detail, setDetail] = useState<ManagedCouponTemplate | null>(null)
  const [createOpen, setCreateOpen] = useState(false)
  const [submitting, setSubmitting] = useState(false)
  const [form] = Form.useForm<CreateFormValue>()

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const result = await listCouponTemplates()
      setItems(result.items)
    } catch {
      messageApi.error('优惠券数据加载失败，请稍后重试。')
    } finally {
      setLoading(false)
    }
  }, [messageApi])

  useEffect(() => { void load() }, [load])

  const stats = useMemo(() => ({
    issued: items.reduce((s, i) => s + i.issued_quantity, 0),
    remaining: items.reduce((s, i) => s + i.remaining_quantity, 0),
    active: items.filter((i) => i.status === 'active').length,
    total: items.length,
  }), [items])

  const rows = useMemo(() => items.filter((i) => {
    if (applied.status && i.status !== applied.status) return false
    if (applied.name.trim() && !i.name.includes(applied.name.trim())) return false
    return true
  }), [items, applied])

  const toggleStatus = (item: ManagedCouponTemplate) => {
    const disabling = item.status === 'active'
    Modal.confirm({
      title: disabling ? '停止发放该优惠券？' : '重新启用该优惠券？',
      content: `模板「${item.name}」${disabling ? '停止发放后用户将无法继续领取。' : '启用后将恢复发放。'}`,
      okText: '确认',
      cancelText: '取消',
      onOk: async () => {
        try {
          await changeCouponTemplateStatus(item.id, disabling ? 'disable' : 'enable', item.version, disabling ? '运营停止发放' : '运营恢复发放')
          messageApi.success(disabling ? '已停止发放。' : '已重新启用。')
          await load()
        } catch {
          messageApi.error('状态变更失败，请稍后重试。')
        }
      },
    })
  }

  const submitCreate = async (values: CreateFormValue) => {
    setSubmitting(true)
    try {
      await createCouponTemplate({
        name: values.name.trim(),
        cash_discount_cents: Math.round(values.discount * 100),
        points_discount: 0,
        minimum_cash_cents: Math.round((values.threshold ?? 0) * 100),
        minimum_points: 0,
        valid_days: values.validDays,
        total_quantity: values.total,
      })
      messageApi.success('优惠券模板已创建。')
      setCreateOpen(false)
      form.resetFields()
      await load()
    } catch {
      messageApi.error('创建失败，请检查参数后重试。')
    } finally {
      setSubmitting(false)
    }
  }

  const columns: ColumnsType<ManagedCouponTemplate> = [
    {
      title: '优惠券名称',
      render: (_, r) => (
        <span>
          <span style={{ fontWeight: 600 }}>{r.name}</span>
          <span style={{ display: 'block', color: '#9ca3af', fontSize: 11 }}>{r.minimum_cash_cents > 0 ? `满 ${yuan(r.minimum_cash_cents)} 可用` : '无门槛'}</span>
        </span>
      ),
    },
    { title: '类型', render: (_, r) => (r.minimum_cash_cents > 0 ? '满减券' : '立减券') },
    { title: '面额', render: (_, r) => yuan(r.cash_discount_cents) },
    { title: '发放/剩余', render: (_, r) => `${r.issued_quantity.toLocaleString()} / ${r.remaining_quantity.toLocaleString()}` },
    { title: '有效期', render: (_, r) => `领取后 ${r.valid_days} 天` },
    {
      title: '状态',
      render: (_, r) => (r.status === 'active' ? <Pill tone="green">进行中</Pill> : <Pill tone="gray">已结束</Pill>),
    },
    {
      title: '操作',
      render: (_, r) => (
        <Links items={[
          { label: '详情', onClick: () => setDetail(r) },
          r.status === 'active'
            ? { label: '停止发放', danger: true, onClick: () => toggleStatus(r) }
            : { label: '重新启用', onClick: () => toggleStatus(r) },
        ]} />
      ),
    },
  ]

  return (
    <div className="xq-page">
      {contextHolder}
      <PageHead title="优惠券管理" extra={<Button type="primary" onClick={() => setCreateOpen(true)}>+ 创建优惠券</Button>} />

      <div className="xq-stats">
        <StatCard title="发放总量" value={stats.issued.toLocaleString()} delta="全部模板累计发放" />
        <StatCard title="剩余库存" value={stats.remaining.toLocaleString()} delta="可继续发放数量" />
        <StatCard title="进行中活动" value={stats.active} delta={<span style={{ color: '#16a34a' }}>发放中的模板</span>} />
        <StatCard title="模板总数" value={stats.total} delta="含已停用模板" />
      </div>

      <div className="xq-card xq-filter" style={{ marginBottom: 16 }}>
        <Select
          value={status || undefined}
          onChange={(v) => setStatus(v ?? '')}
          allowClear
          placeholder="全部状态"
          style={{ minWidth: 120 }}
          options={[{ value: 'active', label: '进行中' }, { value: 'inactive', label: '已结束' }]}
        />
        <Input placeholder="优惠券名称" value={name} onChange={(e) => setName(e.target.value)} onPressEnter={() => setApplied({ status, name })} style={{ maxWidth: 220 }} />
        <Button type="primary" onClick={() => setApplied({ status, name })}>查询</Button>
        <Button onClick={() => { setStatus(''); setName(''); setApplied({ status: '', name: '' }) }}>重置</Button>
      </div>

      <div className="xq-card xq-table">
        <Table rowKey="id" size="middle" loading={loading} columns={columns} dataSource={rows} pagination={{ pageSize: 10, showTotal: (t) => `共 ${t} 条` }} />
      </div>

      <Modal title="优惠券详情" open={!!detail} footer={null} onCancel={() => setDetail(null)} width={520}>
        {detail && (
          <Descriptions
            column={1}
            size="small"
            bordered
            items={[
              { key: 'name', label: '名称', children: detail.name },
              { key: 'discount', label: '面额', children: yuan(detail.cash_discount_cents) },
              { key: 'min', label: '使用门槛', children: detail.minimum_cash_cents > 0 ? `满 ${yuan(detail.minimum_cash_cents)} 可用` : '无门槛' },
              { key: 'valid', label: '有效期', children: `领取后 ${detail.valid_days} 天` },
              { key: 'qty', label: '库存', children: `总量 ${detail.total_quantity.toLocaleString()} / 已发 ${detail.issued_quantity.toLocaleString()} / 剩余 ${detail.remaining_quantity.toLocaleString()}` },
              { key: 'status', label: '状态', children: detail.status === 'active' ? '进行中' : '已结束' },
              { key: 'created', label: '创建时间', children: fmtTime(detail.created_at) },
            ]}
          />
        )}
      </Modal>

      <Modal title="创建优惠券" open={createOpen} onCancel={() => setCreateOpen(false)} onOk={() => form.submit()} confirmLoading={submitting} okText="创建" cancelText="取消">
        <Form form={form} layout="vertical" onFinish={submitCreate}>
          <Form.Item name="name" label="优惠券名称" rules={[{ required: true, message: '请输入名称' }]}>
            <Input placeholder="例如：国庆满 50 减 10" maxLength={30} />
          </Form.Item>
          <Form.Item name="discount" label="面额（元）" rules={[{ required: true, message: '请输入面额' }]}>
            <InputNumber min={0.01} precision={2} style={{ width: '100%' }} placeholder="例如 10" />
          </Form.Item>
          <Form.Item name="threshold" label="使用门槛（元，0 为无门槛）">
            <InputNumber min={0} precision={2} style={{ width: '100%' }} placeholder="例如 50" />
          </Form.Item>
          <Form.Item name="total" label="发放库存" rules={[{ required: true, message: '请输入库存' }]}>
            <InputNumber min={1} precision={0} style={{ width: '100%' }} placeholder="例如 2000" />
          </Form.Item>
          <Form.Item name="validDays" label="有效天数（领取后）" rules={[{ required: true, message: '请输入有效天数' }]}>
            <InputNumber min={1} precision={0} style={{ width: '100%' }} placeholder="例如 7" />
          </Form.Item>
        </Form>
      </Modal>
    </div>
  )
}
