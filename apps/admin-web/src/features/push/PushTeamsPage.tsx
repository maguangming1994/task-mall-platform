import { useCallback, useEffect, useState } from 'react'
import { Alert, Button, Form, Input, InputNumber, Modal, Select, Table, message } from 'antd'
import type { ColumnsType } from 'antd/es/table'
import {
  createPushTeam,
  listPushLevels,
  listPushTeams,
  updatePushTeam,
  type PushLevelItem,
  type PushTeamItem,
  type PushTeamList,
} from '../../api/admin'
import { Links, PageHead, Pill, StatCard, type PillTone } from '../../components/xq'

const statusMap: Record<string, { tone: PillTone; label: string }> = {
  recruiting: { tone: 'green', label: '招募中' },
  active: { tone: 'blue', label: '运营中' },
  suspended: { tone: 'orange', label: '已暂停' },
  closed: { tone: 'gray', label: '已关闭' },
}
const statusPill = (value: string) => statusMap[value] ?? { tone: 'gray' as PillTone, label: value }

interface TeamFormValue {
  name: string
  slogan?: string
  leader_user_id?: string
  level_code: string
  status: string
  settlement_cycle: string
  review_hours: number
  member_limit: number
}

export function PushTeamsPage() {
  const [messageApi, contextHolder] = message.useMessage()
  const [data, setData] = useState<PushTeamList | null>(null)
  const [levels, setLevels] = useState<PushLevelItem[]>([])
  const [loading, setLoading] = useState(false)
  const [failed, setFailed] = useState(false)
  const [keyword, setKeyword] = useState('')
  const [status, setStatus] = useState('')
  const [page, setPage] = useState(1)
  const [open, setOpen] = useState(false)
  const [editing, setEditing] = useState<PushTeamItem | null>(null)
  const [form] = Form.useForm<TeamFormValue>()

  const load = useCallback(async () => {
    setLoading(true)
    setFailed(false)
    try {
      const [teams, levelList] = await Promise.all([
        listPushTeams({ keyword, status, page, page_size: 20 }),
        listPushLevels(),
      ])
      setData(teams)
      setLevels(levelList.items)
    } catch {
      setFailed(true)
    } finally {
      setLoading(false)
    }
  }, [keyword, status, page])

  useEffect(() => { void load() }, [load])

  const openCreate = () => {
    setEditing(null)
    form.setFieldsValue({ level_code: 'A1', status: 'recruiting', settlement_cycle: 'monthly', review_hours: 2, member_limit: 200 })
    setOpen(true)
  }

  const openEdit = (team: PushTeamItem) => {
    setEditing(team)
    form.setFieldsValue({
      name: team.name,
      slogan: team.slogan ?? '',
      leader_user_id: team.leader?.id,
      level_code: team.level_code,
      status: team.status,
      settlement_cycle: team.settlement_cycle,
      review_hours: team.review_hours,
      member_limit: team.member_limit,
    })
    setOpen(true)
  }

  const submit = async () => {
    const values = await form.validateFields()
    try {
      if (editing) {
        await updatePushTeam(editing.id, values)
        messageApi.success('团队已更新。')
      } else {
        await createPushTeam(values)
        messageApi.success('团队已创建。')
      }
      setOpen(false)
      await load()
    } catch {
      messageApi.error('保存失败，请检查团长用户 ID 与校区选择。')
    }
  }

  const columns: ColumnsType<PushTeamItem> = [
    {
      title: '团队',
      render: (_, row) => (
        <span>
          <span style={{ fontWeight: 600 }}>{row.name}</span>
          <span style={{ display: 'block', color: '#9ca3af', fontSize: 11 }}>{row.slogan || '—'} · {row.campus?.name || '—'}</span>
        </span>
      ),
    },
    { title: '团长', width: 110, render: (_, row) => row.leader?.nickname || <span style={{ color: '#9ca3af' }}>未指定</span> },
    { title: '等级', width: 90, render: (_, row) => `${row.level_code} · ${row.level_name}` },
    { title: '成员', width: 90, render: (_, row) => `${row.member_count}/${row.member_limit}` },
    { title: '本月单量', width: 100, render: (_, row) => row.month_orders },
    { title: '本月佣金', width: 110, render: (_, row) => `¥${row.month_commission}` },
    { title: '状态', width: 90, render: (_, row) => <Pill tone={statusPill(row.status).tone}>{statusPill(row.status).label}</Pill> },
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
        title="地推团队"
        extra={<Button type="primary" onClick={openCreate}>新建团队</Button>}
      />
      <div className="xq-stats">
        <StatCard title="地推团队总数" value={data?.summary.total ?? '—'} />
        <StatCard title="招募中" value={data?.summary.recruiting ?? '—'} />
        <StatCard title="在册成员" value={data?.summary.members ?? '—'} />
        <StatCard title="本月有效单" value={data?.summary.month_orders ?? '—'} />
      </div>
      {failed && (
        <Alert
          type="error"
          showIcon
          style={{ marginBottom: 12 }}
          message="团队数据加载失败"
          description="地推团队服务暂时不可用，列表展示为最近一次缓存，操作按钮已禁用。请稍后重试。"
          action={<Button size="small" onClick={() => void load()}>重试</Button>}
        />
      )}
      <div className="xq-card xq-filter" style={{ marginBottom: 16 }}>
        <Input.Search
          allowClear
          placeholder="搜索团队名称"
          style={{ width: 240 }}
          onSearch={(value) => { setKeyword(value); setPage(1) }}
        />
        <Select
          allowClear
          placeholder="全部状态"
          style={{ width: 140 }}
          value={status || undefined}
          onChange={(value) => { setStatus(value ?? ''); setPage(1) }}
          options={Object.entries(statusMap).map(([value, item]) => ({ value, label: item.label }))}
        />
      </div>
      <div className="xq-card xq-table">
        <Table<PushTeamItem>
          rowKey="id"
          loading={loading}
          columns={columns}
          dataSource={failed ? [] : data?.items ?? []}
          pagination={{
            current: page,
            pageSize: 20,
            total: data?.pagination.total ?? 0,
            onChange: setPage,
            showSizeChanger: false,
          }}
        />
      </div>
      <Modal
        title={editing ? '编辑团队' : '新建团队'}
        open={open}
        onOk={() => void submit()}
        onCancel={() => setOpen(false)}
        destroyOnClose
      >
        <Form form={form} layout="vertical">
          <Form.Item name="name" label="团队名称" rules={[{ required: true, message: '请输入团队名称' }]}>
            <Input maxLength={120} />
          </Form.Item>
          <Form.Item name="slogan" label="团队标语">
            <Input maxLength={200} />
          </Form.Item>
          <Form.Item name="leader_user_id" label="团长用户 ID" extra="填写用户 public_id；团长将自动加入团队">
            <Input maxLength={40} />
          </Form.Item>
          <Form.Item name="level_code" label="团队等级" rules={[{ required: true }]}>
            <Select options={levels.map((level) => ({ value: level.code, label: `${level.code} · ${level.name}` }))} />
          </Form.Item>
          <Form.Item name="status" label="状态" rules={[{ required: true }]}>
            <Select options={Object.entries(statusMap).map(([value, item]) => ({ value, label: item.label }))} />
          </Form.Item>
          <Form.Item name="settlement_cycle" label="结算周期" rules={[{ required: true }]}>
            <Select options={[{ value: 'monthly', label: '月结' }, { value: 'weekly', label: '周结' }]} />
          </Form.Item>
          <Form.Item name="review_hours" label="入团审核时效（小时）" rules={[{ required: true }]}>
            <InputNumber min={1} max={72} style={{ width: '100%' }} />
          </Form.Item>
          <Form.Item name="member_limit" label="成员上限" rules={[{ required: true }]}>
            <InputNumber min={1} max={5000} style={{ width: '100%' }} />
          </Form.Item>
        </Form>
      </Modal>
    </div>
  )
}
