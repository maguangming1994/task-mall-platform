import { useCallback, useEffect, useMemo, useState } from 'react'
import { Button, Descriptions, Form, Input, InputNumber, Modal, Select, Table, message } from 'antd'
import type { ColumnsType } from 'antd/es/table'
import {
  createAssetAdjustment,
  listAssetAdjustments,
  listManagedEndUsers,
  listRewardBudgetPools,
  reviewAssetAdjustment,
  type AssetAdjustment,
  type ManagedEndUser,
  type RewardBudgetPool,
} from '../../api/admin'
import { assetAdjustmentActionLabels, assetTypeLabels, businessLabel } from '../../constants/businessLabels'
import { Links, PageHead, Pill, StatCard, UCell, type PillTone } from '../../components/xq'

const yuan = (cents?: number | null) => `¥${((cents ?? 0) / 100).toFixed(2)}`
const fmtTime = (v?: string | null) => (v ? new Date(v).toLocaleString('zh-CN', { hour12: false }) : '—')
const isToday = (v: string) => new Date(v).toDateString() === new Date().toDateString()

const adjustmentPills: Record<string, { tone: PillTone; label: string }> = {
  pending: { tone: 'orange', label: '待审核' },
  approved: { tone: 'green', label: '已通过' },
  rejected: { tone: 'red', label: '已拒绝' },
  applied: { tone: 'blue', label: '已记账' },
}

interface AdjustFormValue { user_id: string; action: 'increase' | 'decrease'; amount: number; reason: string }

export function PointsPage() {
  const [messageApi, contextHolder] = message.useMessage()
  const [tab, setTab] = useState<'accounts' | 'reviews'>('accounts')
  const [pools, setPools] = useState<RewardBudgetPool[]>([])
  const [users, setUsers] = useState<ManagedEndUser[]>([])
  const [adjustments, setAdjustments] = useState<AssetAdjustment[]>([])
  const [reviewStatus, setReviewStatus] = useState('pending')
  const [keyword, setKeyword] = useState('')
  const [appliedKeyword, setAppliedKeyword] = useState('')
  const [loading, setLoading] = useState(false)
  const [detail, setDetail] = useState<ManagedEndUser | null>(null)
  const [adjustOpen, setAdjustOpen] = useState(false)
  const [reviewTarget, setReviewTarget] = useState<{ item: AssetAdjustment; decision: 'approve' | 'reject' } | null>(null)
  const [submitting, setSubmitting] = useState(false)
  const [adjustForm] = Form.useForm<AdjustFormValue>()
  const [reviewForm] = Form.useForm<{ note?: string }>()

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const [poolResult, userResult, adjustmentResult] = await Promise.all([
        listRewardBudgetPools(),
        listManagedEndUsers(appliedKeyword ? { keyword: appliedKeyword } : {}),
        listAssetAdjustments(reviewStatus),
      ])
      setPools(poolResult.items)
      setUsers(userResult.items)
      setAdjustments(adjustmentResult.items)
    } catch {
      messageApi.error('积分数据加载失败，请稍后重试。')
    } finally {
      setLoading(false)
    }
  }, [appliedKeyword, messageApi, reviewStatus])

  useEffect(() => { void load() }, [load])

  const pointPools = useMemo(() => pools.filter((p) => p.asset_type === 'points'), [pools])
  const stats = useMemo(() => ({
    issued: pointPools.reduce((s, p) => s + p.issued_amount, 0),
    total: pointPools.reduce((s, p) => s + p.total_amount, 0),
    available: pointPools.reduce((s, p) => s + p.available_amount, 0),
    todayAdjust: adjustments.filter((a) => isToday(a.created_at)).length,
  }), [pointPools, adjustments])

  const openAdjust = (user?: ManagedEndUser) => {
    adjustForm.resetFields()
    if (user) adjustForm.setFieldsValue({ user_id: user.id, action: 'increase' })
    setAdjustOpen(true)
  }

  const submitAdjust = async (values: AdjustFormValue) => {
    setSubmitting(true)
    try {
      await createAssetAdjustment(values.user_id.trim(), { asset_type: 'points', action: values.action, amount: values.amount, reason: values.reason.trim() })
      messageApi.success('积分调整单已提交，待财务复核。')
      setAdjustOpen(false)
      await load()
    } catch {
      messageApi.error('积分调整提交失败，请稍后重试。')
    } finally {
      setSubmitting(false)
    }
  }

  const submitReview = async (values: { note?: string }) => {
    if (!reviewTarget) return
    setSubmitting(true)
    try {
      await reviewAssetAdjustment(reviewTarget.item.id, reviewTarget.decision, values.note?.trim() || (reviewTarget.decision === 'approve' ? '复核通过' : '复核拒绝'))
      messageApi.success(reviewTarget.decision === 'approve' ? '已批准该调整单。' : '已拒绝该调整单。')
      setReviewTarget(null)
      await load()
    } catch {
      messageApi.error('复核操作失败，请稍后重试。')
    } finally {
      setSubmitting(false)
    }
  }

  const accountColumns: ColumnsType<ManagedEndUser> = [
    { title: '用户', render: (_, r) => <UCell name={r.nickname} sub={`ID:${r.id}`} /> },
    { title: '当前积分', render: (_, r) => <span style={{ color: '#16a34a', fontWeight: 700 }}>{r.assets.points_available.toLocaleString()}</span> },
    { title: '冻结积分', render: (_, r) => r.assets.points_frozen.toLocaleString() },
    { title: '钱包余额', render: (_, r) => yuan(r.assets.wallet_available_cents) },
    { title: '可用优惠券', render: (_, r) => r.assets.coupons_available },
    { title: '注册时间', render: (_, r) => fmtTime(r.created_at) },
    {
      title: '操作',
      render: (_, r) => (
        <Links items={[
          { label: '明细', onClick: () => setDetail(r) },
          { label: '调整', onClick: () => openAdjust(r) },
        ]} />
      ),
    },
  ]

  const reviewColumns: ColumnsType<AssetAdjustment> = [
    { title: '调整单号', dataIndex: 'id' },
    { title: '资产', render: (_, r) => businessLabel(assetTypeLabels, r.asset_type) },
    { title: '动作', render: (_, r) => businessLabel(assetAdjustmentActionLabels, r.action) },
    { title: '数量', render: (_, r) => (r.amount === null ? '当前全部可用值' : r.amount.toLocaleString()) },
    { title: '原因', dataIndex: 'reason', ellipsis: true },
    { title: '状态', render: (_, r) => { const p = adjustmentPills[r.status] ?? { tone: 'gray' as PillTone, label: r.status }; return <Pill tone={p.tone}>{p.label}</Pill> } },
    { title: '提交时间', render: (_, r) => fmtTime(r.created_at) },
    {
      title: '操作',
      render: (_, r) => (
        <Links items={[
          r.status === 'pending' && { label: '通过', onClick: () => { reviewForm.resetFields(); setReviewTarget({ item: r, decision: 'approve' }) } },
          r.status === 'pending' && { label: '驳回', danger: true, onClick: () => { reviewForm.resetFields(); setReviewTarget({ item: r, decision: 'reject' }) } },
        ]} />
      ),
    },
  ]

  return (
    <div className="xq-page">
      {contextHolder}
      <PageHead
        title="积分管理"
        extra={<Button type="primary" onClick={() => openAdjust()}>+ 人工调整</Button>}
      />

      <div className="xq-stats">
        <StatCard title="积分发放总量" value={stats.issued.toLocaleString()} delta="全部积分预算池合计" />
        <StatCard title="积分预算池总额" value={stats.total.toLocaleString()} delta={`共 ${pointPools.length} 个积分池`} />
        <StatCard title="平台积分负债" value={stats.available.toLocaleString()} valueColor="#d97706" delta="预算池可用余量" />
        <StatCard title="今日人工调整" value={stats.todayAdjust} delta="需二次审批" />
      </div>

      <div className="xq-tabs">
        <div className={tab === 'accounts' ? 'it on' : 'it'} onClick={() => setTab('accounts')}>积分账户</div>
        <div className={tab === 'reviews' ? 'it on' : 'it'} onClick={() => setTab('reviews')}>调整审批</div>
      </div>

      <div className="xq-card xq-filter" style={{ marginBottom: 16 }}>
        {tab === 'accounts' ? (
          <>
            <Input placeholder="用户昵称/ID" value={keyword} onChange={(e) => setKeyword(e.target.value)} onPressEnter={() => setAppliedKeyword(keyword)} style={{ maxWidth: 220 }} />
            <Button type="primary" onClick={() => setAppliedKeyword(keyword)}>查询</Button>
            <Button onClick={() => { setKeyword(''); setAppliedKeyword('') }}>重置</Button>
          </>
        ) : (
          <>
            <Select
              value={reviewStatus}
              onChange={setReviewStatus}
              style={{ minWidth: 120 }}
              options={[
                { value: 'pending', label: '待审核' },
                { value: 'approved', label: '已批准' },
                { value: 'rejected', label: '已拒绝' },
                { value: 'applied', label: '已记账' },
              ]}
            />
            <Button onClick={() => setReviewStatus('pending')}>重置</Button>
          </>
        )}
      </div>

      <div className="xq-card xq-table">
        {tab === 'accounts' ? (
          <Table rowKey="id" size="middle" loading={loading} columns={accountColumns} dataSource={users} pagination={{ pageSize: 10, showTotal: (t) => `共 ${t} 条` }} />
        ) : (
          <Table rowKey="id" size="middle" loading={loading} columns={reviewColumns} dataSource={adjustments} pagination={{ pageSize: 10, showTotal: (t) => `共 ${t} 条` }} />
        )}
      </div>

      <Modal title="积分明细" open={!!detail} footer={null} onCancel={() => setDetail(null)} width={520}>
        {detail && (
          <Descriptions
            column={1}
            size="small"
            bordered
            items={[
              { key: 'user', label: '用户', children: `${detail.nickname}（ID:${detail.id}）` },
              { key: 'available', label: '当前积分', children: detail.assets.points_available.toLocaleString() },
              { key: 'frozen', label: '冻结积分', children: detail.assets.points_frozen.toLocaleString() },
              { key: 'wallet', label: '钱包余额', children: `${yuan(detail.assets.wallet_available_cents)}（冻结 ${yuan(detail.assets.wallet_frozen_cents)}）` },
              { key: 'coupon', label: '可用优惠券', children: detail.assets.coupons_available },
              { key: 'task', label: '任务数据', children: `领取 ${detail.task_metrics.claimed} 次 / 通过 ${detail.task_metrics.approved} 次` },
            ]}
          />
        )}
      </Modal>

      <Modal title="人工积分调整" open={adjustOpen} onCancel={() => setAdjustOpen(false)} onOk={() => adjustForm.submit()} confirmLoading={submitting} okText="提交调整" cancelText="取消">
        <Form form={adjustForm} layout="vertical" onFinish={submitAdjust} initialValues={{ action: 'increase' }}>
          <Form.Item name="user_id" label="用户 ID" rules={[{ required: true, message: '请输入用户 ID' }]}>
            <Input placeholder="接收调整的用户 ID" />
          </Form.Item>
          <Form.Item name="action" label="调整方向" rules={[{ required: true }]}>
            <Select options={[{ value: 'increase', label: '增加积分' }, { value: 'decrease', label: '扣减积分' }]} />
          </Form.Item>
          <Form.Item name="amount" label="积分数量" rules={[{ required: true, message: '请输入积分数量' }]}>
            <InputNumber min={1} precision={0} style={{ width: '100%' }} placeholder="正整数" />
          </Form.Item>
          <Form.Item name="reason" label="调整原因" rules={[{ required: true, message: '请填写调整原因' }]}>
            <Input.TextArea rows={2} placeholder="将写入审核留痕" />
          </Form.Item>
        </Form>
      </Modal>

      <Modal
        title={reviewTarget?.decision === 'approve' ? '批准积分调整' : '拒绝积分调整'}
        open={!!reviewTarget}
        onCancel={() => setReviewTarget(null)}
        onOk={() => reviewForm.submit()}
        confirmLoading={submitting}
        okText="确认"
        cancelText="取消"
      >
        <p style={{ color: '#6b7280', fontSize: 13 }}>
          调整单号：{reviewTarget?.item.id}，原因：{reviewTarget?.item.reason}
        </p>
        <Form form={reviewForm} layout="vertical" onFinish={submitReview}>
          <Form.Item name="note" label="复核备注" rules={reviewTarget?.decision === 'reject' ? [{ required: true, message: '拒绝时请填写原因' }] : []}>
            <Input.TextArea rows={3} placeholder={reviewTarget?.decision === 'approve' ? '复核通过（可不填）' : '请填写拒绝原因'} />
          </Form.Item>
        </Form>
      </Modal>
    </div>
  )
}
