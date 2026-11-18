import { useCallback, useEffect, useState } from 'react'
import { Button, Input, Modal, Select, Table, message } from 'antd'
import type { ColumnsType } from 'antd/es/table'
import {
  listPushLevels,
  listPushMembers,
  updatePushMember,
  type PushLevelItem,
  type PushMemberItem,
  type PushMemberList,
} from '../../api/admin'
import { Links, PageHead, Pill, StatCard } from '../../components/xq'

export function PushMembersPage() {
  const [messageApi, contextHolder] = message.useMessage()
  const [data, setData] = useState<PushMemberList | null>(null)
  const [levels, setLevels] = useState<PushLevelItem[]>([])
  const [loading, setLoading] = useState(false)
  const [keyword, setKeyword] = useState('')
  const [levelCode, setLevelCode] = useState('')
  const [status, setStatus] = useState('active')
  const [page, setPage] = useState(1)

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const [members, levelList] = await Promise.all([
        listPushMembers({ keyword, level_code: levelCode, status, page, page_size: 20 }),
        listPushLevels(),
      ])
      setData(members)
      setLevels(levelList.items)
    } catch {
      messageApi.error('成员数据加载失败，请稍后重试。')
    } finally {
      setLoading(false)
    }
  }, [keyword, levelCode, status, page, messageApi])

  useEffect(() => { void load() }, [load])

  const changeLevel = async (member: PushMemberItem, code: string) => {
    try {
      await updatePushMember(member.id, { level_code: code })
      messageApi.success(`已将 ${member.nickname} 调整为 ${code}。`)
      await load()
    } catch {
      messageApi.error('等级调整失败。')
    }
  }

  const removeMember = (member: PushMemberItem) => {
    Modal.confirm({
      title: `将「${member.nickname}」移出团队？`,
      content: '移出后该成员不能继续领取推广位，历史佣金不受影响。',
      okText: '确认移出',
      okButtonProps: { danger: true },
      onOk: async () => {
        try {
          await updatePushMember(member.id, { status: 'left' })
          messageApi.success('已移出团队。')
          await load()
        } catch {
          messageApi.error('操作失败，请稍后重试。')
        }
      },
    })
  }

  const columns: ColumnsType<PushMemberItem> = [
    {
      title: '成员',
      render: (_, row) => (
        <span>
          <span style={{ fontWeight: 600 }}>{row.nickname}</span>
          <span style={{ display: 'block', color: '#9ca3af', fontSize: 11 }}>{row.team_name || '—'} · {row.role === 'leader' ? '团长' : '成员'}</span>
        </span>
      ),
    },
    {
      title: '等级',
      width: 160,
      render: (_, row) => (
        <Select
          size="small"
          value={row.level_code}
          style={{ width: 120 }}
          onChange={(value) => void changeLevel(row, value)}
          options={levels.map((level) => ({ value: level.code, label: `${level.code} · ${level.name}` }))}
        />
      ),
    },
    { title: '本月单量', width: 100, render: (_, row) => row.month_orders },
    { title: '本月佣金', width: 110, render: (_, row) => `¥${row.month_commission}` },
    {
      title: '状态',
      width: 90,
      render: (_, row) => <Pill tone={row.status === 'active' ? 'green' : 'gray'}>{row.status === 'active' ? '在册' : '已退出'}</Pill>,
    },
    {
      title: '操作',
      width: 90,
      render: (_, row) => row.status === 'active' && row.role !== 'leader'
        ? <Links items={[{ label: '移出团队', danger: true, onClick: () => removeMember(row) }]} />
        : <span style={{ color: '#9ca3af' }}>—</span>,
    },
  ]

  return (
    <div className="xq-page">
      {contextHolder}
      <PageHead title="地推成员与等级" />
      <div className="xq-stats">
        <StatCard title="在册成员" value={data?.summary.active ?? '—'} />
        <StatCard title="团长数量" value={data?.summary.leaders ?? '—'} />
        <StatCard title="本月有效单" value={data?.summary.month_orders ?? '—'} />
      </div>
      <div className="xq-card xq-filter" style={{ marginBottom: 16 }}>
        <Input.Search
          allowClear
          placeholder="搜索成员昵称"
          style={{ width: 220 }}
          onSearch={(value) => { setKeyword(value); setPage(1) }}
        />
        <Select
          allowClear
          placeholder="全部等级"
          style={{ width: 150 }}
          value={levelCode || undefined}
          onChange={(value) => { setLevelCode(value ?? ''); setPage(1) }}
          options={levels.map((level) => ({ value: level.code, label: `${level.code} · ${level.name}` }))}
        />
        <Select
          style={{ width: 130 }}
          value={status}
          onChange={(value) => { setStatus(value); setPage(1) }}
          options={[{ value: 'active', label: '在册成员' }, { value: 'left', label: '已退出' }]}
        />
        <Button onClick={() => { setKeyword(''); setLevelCode(''); setStatus('active'); setPage(1) }}>重置</Button>
      </div>
      <div className="xq-card xq-table">
        <Table<PushMemberItem>
          rowKey="id"
          loading={loading}
          columns={columns}
          dataSource={data?.items ?? []}
          pagination={{
            current: page,
            pageSize: 20,
            total: data?.pagination.total ?? 0,
            onChange: setPage,
            showSizeChanger: false,
          }}
        />
      </div>
    </div>
  )
}
