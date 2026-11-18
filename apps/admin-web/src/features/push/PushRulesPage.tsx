import { useCallback, useEffect, useState } from 'react'
import { Alert, Button, Form, Input, InputNumber, Modal, Select, Table, message } from 'antd'
import type { ColumnsType } from 'antd/es/table'
import { listPushLevels, savePushLevel, updatePushLevel, type PushLevelItem } from '../../api/admin'
import { Links, PageHead, Pill } from '../../components/xq'

interface LevelFormValue {
  code: string
  name: string
  coefficient: number
  team_bonus_rate: number
  min_monthly_orders: number
  sort_order: number
  status: 'active' | 'inactive'
}

export function PushRulesPage() {
  const [messageApi, contextHolder] = message.useMessage()
  const [items, setItems] = useState<PushLevelItem[]>([])
  const [loading, setLoading] = useState(false)
  const [open, setOpen] = useState(false)
  const [editing, setEditing] = useState<PushLevelItem | null>(null)
  const [form] = Form.useForm<LevelFormValue>()

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const result = await listPushLevels()
      setItems(result.items)
    } catch {
      messageApi.error('佣金规则加载失败，请稍后重试。')
    } finally {
      setLoading(false)
    }
  }, [messageApi])

  useEffect(() => { void load() }, [load])

  const openCreate = () => {
    setEditing(null)
    form.setFieldsValue({ code: '', name: '', coefficient: 1, team_bonus_rate: 0.05, min_monthly_orders: 0, sort_order: items.length, status: 'active' })
    setOpen(true)
  }

  const openEdit = (level: PushLevelItem) => {
    setEditing(level)
    form.setFieldsValue({
      code: level.code,
      name: level.name,
      coefficient: level.coefficient,
      team_bonus_rate: level.team_bonus_rate,
      min_monthly_orders: level.min_monthly_orders,
      sort_order: level.sort_order,
      status: level.status,
    })
    setOpen(true)
  }

  const submit = async () => {
    const values = await form.validateFields()
    try {
      if (editing) {
        await updatePushLevel(editing.code, values)
      } else {
        await savePushLevel(values)
      }
      messageApi.success('等级规则已保存。')
      setOpen(false)
      await load()
    } catch {
      messageApi.error('保存失败，请检查系数与加成比例。')
    }
  }

  const columns: ColumnsType<PushLevelItem> = [
    { title: '等级', width: 90, render: (_, row) => <b>{row.code}</b> },
    { title: '名称', render: (_, row) => row.name },
    { title: '佣金系数', width: 110, render: (_, row) => `×${row.coefficient.toFixed(3)}` },
    { title: '团队加成', width: 120, render: (_, row) => `${(row.team_bonus_rate * 100).toFixed(1)}%（平台承担）` },
    { title: '月单量门槛', width: 110, render: (_, row) => row.min_monthly_orders },
    {
      title: '状态',
      width: 90,
      render: (_, row) => <Pill tone={row.status === 'active' ? 'green' : 'gray'}>{row.status === 'active' ? '启用' : '停用'}</Pill>,
    },
    {
      title: '操作',
      width: 90,
      render: (_, row) => <Links items={[{ label: '编辑', onClick: () => openEdit(row) }]} />,
    },
  ]

  return (
    <div className="xq-page">
      {contextHolder}
      <PageHead
        title="地推佣金规则"
        extra={<Button type="primary" onClick={openCreate}>新增等级</Button>}
      />
      <Alert
        type="info"
        showIcon
        style={{ marginBottom: 16 }}
        message="复用现有结算口径"
        description="地推佣金与任务积分分账，提现仍走「提现审核」；本页只维护等级系数与团队加成，不新建钱包账户。团队加成由平台承担，不摊到成员单价。"
      />
      <div className="xq-card xq-table">
        <Table<PushLevelItem>
          rowKey="code"
          loading={loading}
          columns={columns}
          dataSource={items}
          pagination={false}
        />
      </div>
      <Modal
        title={editing ? `编辑等级 ${editing.code}` : '新增等级'}
        open={open}
        onOk={() => void submit()}
        onCancel={() => setOpen(false)}
        destroyOnClose
      >
        <Form form={form} layout="vertical">
          <Form.Item name="code" label="等级编码" rules={[{ required: true, message: '请输入等级编码' }]}>
            <Input maxLength={16} disabled={editing !== null} placeholder="如 A4" />
          </Form.Item>
          <Form.Item name="name" label="等级名称" rules={[{ required: true, message: '请输入等级名称' }]}>
            <Input maxLength={48} />
          </Form.Item>
          <Form.Item name="coefficient" label="佣金系数" rules={[{ required: true }]} extra="最终佣金 = 任务单价 × 系数">
            <InputNumber min={0.1} max={5} step={0.01} style={{ width: '100%' }} />
          </Form.Item>
          <Form.Item name="team_bonus_rate" label="团队加成比例" rules={[{ required: true }]} extra="0.05 表示 5%，由平台承担">
            <InputNumber min={0} max={1} step={0.01} style={{ width: '100%' }} />
          </Form.Item>
          <Form.Item name="min_monthly_orders" label="月单量门槛" rules={[{ required: true }]}>
            <InputNumber min={0} style={{ width: '100%' }} />
          </Form.Item>
          <Form.Item name="sort_order" label="排序" rules={[{ required: true }]}>
            <InputNumber min={0} style={{ width: '100%' }} />
          </Form.Item>
          <Form.Item name="status" label="状态" rules={[{ required: true }]}>
            <Select options={[{ value: 'active', label: '启用' }, { value: 'inactive', label: '停用' }]} />
          </Form.Item>
        </Form>
      </Modal>
    </div>
  )
}
