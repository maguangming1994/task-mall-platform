import { useCallback, useEffect, useState } from 'react'
import { Alert, Button, Input, Modal, Select, Table, message } from 'antd'
import type { ColumnsType } from 'antd/es/table'
import {
  confirmPushSettlement,
  createPushSettlement,
  getPushSettlement,
  listPushSettlements,
  payPushSettlement,
  type PushSettlementList,
  type PushSettlementMember,
} from '../../api/admin'
import { Links, PageHead, Pill, StatCard, type PillTone } from '../../components/xq'

const settlementStatus: Record<string, { tone: PillTone; label: string }> = {
  draft: { tone: 'gray', label: '草稿' },
  pending: { tone: 'orange', label: '待打款' },
  paid: { tone: 'green', label: '已打款' },
}
const statusPill = (value: string) => settlementStatus[value] ?? { tone: 'gray' as PillTone, label: value }
const fmtDate = (value: string | null) => (value ? new Date(value).toLocaleDateString('zh-CN') : '—')

export function PushSettlementsPage() {
  const [messageApi, contextHolder] = message.useMessage()
  const [data, setData] = useState<PushSettlementList | null>(null)
  const [loading, setLoading] = useState(false)
  const [status, setStatus] = useState('')
  const [createOpen, setCreateOpen] = useState(false)
  const [period, setPeriod] = useState({ start: '', end: '' })
  const [creating, setCreating] = useState(false)
  const [detail, setDetail] = useState<PushSettlementMember | null>(null)

  const load = useCallback(async () => {
    setLoading(true)
    try {
      setData(await listPushSettlements({ status, page_size: 50 }))
    } catch {
      messageApi.error('结算批次加载失败，请稍后重试。')
    } finally {
      setLoading(false)
    }
  }, [status, messageApi])

  useEffect(() => { void load() }, [load])

  const createBatch = async () => {
    if (!period.start || !period.end) {
      messageApi.warning('请选择结算周期。')
      return
    }
    setCreating(true)
    try {
      const created = await createPushSettlement({ period_start: period.start, period_end: period.end })
      messageApi.success(`批次 ${created.batch_no} 已生成。`)
      setCreateOpen(false)
      setPeriod({ start: '', end: '' })
      await load()
      setDetail(created)
    } catch {
      messageApi.error('没有可结算的交单，或周期设置有误。')
    } finally {
      setCreating(false)
    }
  }

  const confirmBatch = (row: PushSettlementMember) => {
    Modal.confirm({
      title: `确认批次 ${row.batch_no}？`,
      content: `确认后金额 ¥${row.total_amount} 将进入待打款状态。`,
      onOk: async () => {
        try {
          await confirmPushSettlement(row.id)
          messageApi.success('批次已确认。')
          await load()
        } catch {
          messageApi.error('确认失败，请稍后重试。')
        }
      },
    })
  }

  const payBatch = (row: PushSettlementMember) => {
    Modal.confirm({
      title: `批次 ${row.batch_no} 立即打款？`,
      content: `打款金额 ¥${row.total_amount} 将进入成员现金钱包，提现仍需走提现审核。`,
      okText: '确认打款',
      onOk: async () => {
        try {
          await payPushSettlement(row.id)
          messageApi.success('打款完成，金额已进入成员钱包。')
          await load()
        } catch {
          messageApi.error('打款失败：请完成二次验证或稍后重试。')
        }
      },
    })
  }

  const openDetail = async (row: PushSettlementMember) => {
    try {
      setDetail(await getPushSettlement(row.id))
    } catch {
      messageApi.error('批次明细加载失败。')
    }
  }

  const columns: ColumnsType<PushSettlementMember> = [
    {
      title: '批次',
      render: (_, row) => (
        <span>
          <span style={{ fontWeight: 600 }}>{row.batch_no}</span>
          <span style={{ display: 'block', color: '#9ca3af', fontSize: 11 }}>{fmtDate(row.period_start)} ~ {fmtDate(row.period_end)}</span>
        </span>
      ),
    },
    { title: '交单数', width: 90, render: (_, row) => row.submission_count },
    { title: '成员佣金', width: 110, render: (_, row) => `¥${row.total_commission}` },
    { title: '团队加成', width: 110, render: (_, row) => `¥${row.total_team_bonus}` },
    { title: '合计', width: 110, render: (_, row) => <b>¥{row.total_amount}</b> },
    { title: '状态', width: 90, render: (_, row) => <Pill tone={statusPill(row.status).tone}>{statusPill(row.status).label}</Pill> },
    {
      title: '操作',
      width: 200,
      render: (_, row) => (
        <Links
          items={[
            { label: '明细', onClick: () => void openDetail(row) },
            row.status === 'draft' ? { label: '确认', onClick: () => confirmBatch(row) } : null,
            row.status !== 'paid' ? { label: '打款', onClick: () => payBatch(row) } : null,
          ]}
        />
      ),
    },
  ]

  const itemColumns: ColumnsType<NonNullable<PushSettlementMember['items']>[number]> = [
    { title: '成员', render: (_, row) => <span>{row.nickname}<span style={{ display: 'block', color: '#9ca3af', fontSize: 11 }}>{row.team_name || '—'} · {row.level_code}</span></span> },
    { title: '交单数', width: 90, render: (_, row) => row.submission_count },
    { title: '佣金', width: 110, render: (_, row) => `¥${row.commission}` },
    { title: '团队加成', width: 110, render: (_, row) => `¥${row.team_bonus}` },
    { title: '状态', width: 90, render: (_, row) => <Pill tone={row.status === 'paid' ? 'green' : 'orange'}>{row.status === 'paid' ? '已打款' : '待打款'}</Pill> },
  ]

  return (
    <div className="xq-page">
      {contextHolder}
      <PageHead
        title="地推结算批次"
        extra={<Button type="primary" onClick={() => setCreateOpen(true)}>生成结算批次</Button>}
      />
      <Alert
        type="info"
        showIcon
        style={{ marginBottom: 16 }}
        message="复用现有现金钱包与提现审核"
        description="打款后金额直接进入成员 user_asset_accounts 现金余额并写入钱包流水；提现仍走原有提现审核，不新建钱包账户。团队加成由平台承担，单独列示。"
      />
      <div className="xq-stats">
        <StatCard title="待结算金额" value={data?.summary.pending_amount ?? '—'} valueColor="#d97706" />
        <StatCard title="已打款金额" value={data?.summary.paid_amount ?? '—'} />
        <StatCard title="批次总数" value={data?.pagination.total ?? '—'} />
      </div>
      <div className="xq-card xq-filter" style={{ marginBottom: 16 }}>
        <Select
          allowClear
          style={{ width: 140 }}
          placeholder="全部状态"
          value={status || undefined}
          onChange={(value) => setStatus(value ?? '')}
          options={Object.entries(settlementStatus).map(([value, item]) => ({ value, label: item.label }))}
        />
        <Button onClick={() => void load()}>刷新</Button>
      </div>
      <div className="xq-card xq-table">
        <Table<PushSettlementMember>
          rowKey="id"
          loading={loading}
          columns={columns}
          dataSource={data?.items ?? []}
          locale={{ emptyText: '当前没有结算批次。选择周期生成第一个批次后，已通过的交单会按成员汇总到这里。' }}
          pagination={{ pageSize: 20, showSizeChanger: false }}
        />
      </div>

      <Modal
        title="生成结算批次"
        open={createOpen}
        confirmLoading={creating}
        onOk={() => void createBatch()}
        onCancel={() => setCreateOpen(false)}
      >
        <div style={{ display: 'grid', gap: 12 }}>
          <label>
            开始日期
            <Input type="date" value={period.start} onChange={(event) => setPeriod((prev) => ({ ...prev, start: event.target.value }))} />
          </label>
          <label>
            结束日期
            <Input type="date" value={period.end} onChange={(event) => setPeriod((prev) => ({ ...prev, end: event.target.value }))} />
          </label>
          <span style={{ color: '#6b7280', fontSize: 12 }}>按审核通过时间归集，已生成批次的交单不会重复结算。</span>
        </div>
      </Modal>

      <Modal
        title={detail ? `批次明细 · ${detail.batch_no}` : '批次明细'}
        open={detail !== null}
        footer={null}
        width={760}
        onCancel={() => setDetail(null)}
      >
        {detail && (
          <>
            <div style={{ display: 'flex', gap: 24, marginBottom: 12, color: '#374151' }}>
              <span>周期：{fmtDate(detail.period_start)} ~ {fmtDate(detail.period_end)}</span>
              <span>合计：<b>¥{detail.total_amount}</b></span>
              <Pill tone={statusPill(detail.status).tone}>{statusPill(detail.status).label}</Pill>
            </div>
            <Table
              rowKey="id"
              size="small"
              columns={itemColumns}
              dataSource={detail.items ?? []}
              pagination={false}
            />
          </>
        )}
      </Modal>
    </div>
  )
}
