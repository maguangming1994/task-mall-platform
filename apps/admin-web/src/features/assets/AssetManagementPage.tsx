import { CheckOutlined, CloseOutlined, SearchOutlined, SwapOutlined } from '@ant-design/icons'
import { Alert, Button, Card, Form, Input, InputNumber, Modal, Select, Space, Statistic, Table, Tag, Typography, message } from 'antd'
import { useCallback, useEffect, useState } from 'react'
import {
  ApiError,
  createAssetAdjustment,
  getAdminUserAssets,
  listAssetAdjustments,
  listWalletWithdrawals,
  reviewAssetAdjustment,
  reviewWalletWithdrawal,
  verifyAdminRecent,
  type AssetAdjustment,
  type UserAssetSummary,
  type WalletWithdrawal,
} from '../../api/admin'
import { ReconciliationPanel } from './ReconciliationPanel'

interface AdjustmentForm { asset_type: 'points' | 'wallet'; action: 'increase' | 'decrease' | 'clear'; amount?: number; reason: string; password: string; mfa_code?: string }
interface ReviewForm { note: string; password: string; mfa_code?: string }

export function AssetManagementPage() {
  const [userId, setUserId] = useState('')
  const [assets, setAssets] = useState<UserAssetSummary | null>(null)
  const [pending, setPending] = useState<AssetAdjustment[]>([])
  const [withdrawals, setWithdrawals] = useState<WalletWithdrawal[]>([])
  const [adjustOpen, setAdjustOpen] = useState(false)
  const [reviewTarget, setReviewTarget] = useState<AssetAdjustment | null>(null)
  const [withdrawalTarget, setWithdrawalTarget] = useState<WalletWithdrawal | null>(null)
  const [decision, setDecision] = useState<'approve' | 'reject'>('approve')
  const [submitting, setSubmitting] = useState(false)
  const [adjustForm] = Form.useForm<AdjustmentForm>()
  const [reviewForm] = Form.useForm<ReviewForm>()
  const [messageApi, contextHolder] = message.useMessage()
  const action = Form.useWatch('action', adjustForm)

  const loadPending = useCallback(async () => {
    try { const [adjustments, withdrawalItems] = await Promise.all([listAssetAdjustments(), listWalletWithdrawals()]); setPending(adjustments.items); setWithdrawals(withdrawalItems.items) }
    catch (cause) { messageApi.error(cause instanceof ApiError ? cause.message : '待复核申请加载失败。') }
  }, [messageApi])
  useEffect(() => { void loadPending() }, [loadPending])

  const search = async () => {
    if (!userId.trim()) return
    try { setAssets(await getAdminUserAssets(userId.trim())) }
    catch (cause) { setAssets(null); messageApi.error(cause instanceof ApiError ? cause.message : '用户资产加载失败。') }
  }

  const submitAdjustment = async (values: AdjustmentForm) => {
    if (!assets) return
    setSubmitting(true)
    try {
      await verifyAdminRecent(values.password, values.mfa_code)
      const result = await createAssetAdjustment(assets.user_id, { asset_type: values.asset_type, action: values.action, amount: values.action === 'clear' ? undefined : values.amount, reason: values.reason })
      setAdjustOpen(false); adjustForm.resetFields(); await search(); await loadPending()
      messageApi.success(result.status === 'pending' ? '已提交另一位财务管理员复核，资产尚未变动。' : '资产调整已记入不可删除流水。')
    } catch (cause) { messageApi.error(cause instanceof ApiError ? cause.message : '资产调整失败。') }
    finally { setSubmitting(false) }
  }

  const submitReview = async (values: ReviewForm) => {
    if (!reviewTarget && !withdrawalTarget) return
    setSubmitting(true)
    try {
      await verifyAdminRecent(values.password, values.mfa_code)
      if (reviewTarget) await reviewAssetAdjustment(reviewTarget.id, decision, values.note)
      if (withdrawalTarget) await reviewWalletWithdrawal(withdrawalTarget.id, decision, values.note)
      setReviewTarget(null); setWithdrawalTarget(null); reviewForm.resetFields(); await loadPending(); if (assets) await search()
      messageApi.success(withdrawalTarget ? (decision === 'approve' ? '提现已批准，等待第 19 批真实渠道出款。' : '提现已拒绝并解冻余额。') : (decision === 'approve' ? '复核通过并已记账。' : '申请已拒绝，资产未变动。'))
    } catch (cause) { messageApi.error(cause instanceof ApiError ? cause.message : '复核失败。') }
    finally { setSubmitting(false) }
  }

  return <>
    {contextHolder}
    <div className="page-heading"><div><Typography.Text className="eyebrow">ASSET LEDGERS</Typography.Text><Typography.Title level={2}>用户资产与财务复核</Typography.Title><Typography.Paragraph type="secondary">所有增减和清零都追加账本，不覆盖历史；高额操作与全部清零强制四眼复核。</Typography.Paragraph></div></div>
    <Alert showIcon type="warning" message="余额调整超过 200 元、积分调整超过 1000 分或任何清零操作，申请人与复核人必须不同。" />
    <Card bordered={false} style={{ marginTop: 16 }}><Space.Compact block><Input value={userId} onChange={(event) => setUserId(event.target.value)} placeholder="输入用户 ULID" onPressEnter={() => void search()} /><Button type="primary" icon={<SearchOutlined />} onClick={() => void search()}>查询资产</Button></Space.Compact></Card>
    {assets && <Card bordered={false} style={{ marginTop: 16 }} title={`用户 ${assets.user_id}`} extra={<Button icon={<SwapOutlined />} onClick={() => setAdjustOpen(true)}>发起调整</Button>}><Space size="large" wrap><Statistic title="可用积分" value={assets.points.available} /><Statistic title="积分欠额" value={assets.points.debt} /><Statistic title="可用余额（分）" value={assets.wallet.available_amount} /><Statistic title="冻结余额（分）" value={assets.wallet.frozen_amount} /><Statistic title="可用优惠券" value={assets.coupons.available} /></Space></Card>}
    <Card bordered={false} style={{ marginTop: 16 }} title="待财务复核"><Table rowKey="id" dataSource={pending} pagination={false} columns={[
      { title: '资产', dataIndex: 'asset_type' }, { title: '操作', dataIndex: 'action' }, { title: '数量', dataIndex: 'amount', render: (value: number | null) => value ?? '当前全部可用值' },
      { title: '原因', dataIndex: 'reason' }, { title: '状态', dataIndex: 'status', render: (value: string) => <Tag color="processing">{value}</Tag> },
      { title: '复核', render: (_: unknown, item: AssetAdjustment) => <Space><Button icon={<CheckOutlined />} onClick={() => { setDecision('approve'); setReviewTarget(item) }}>批准</Button><Button danger icon={<CloseOutlined />} onClick={() => { setDecision('reject'); setReviewTarget(item) }}>拒绝</Button></Space> },
    ]} /></Card>
    <Card bordered={false} style={{ marginTop: 16 }} title="待审核提现"><Table rowKey="id" dataSource={withdrawals} pagination={false} columns={[
      { title: '提现单', dataIndex: 'withdrawal_no' }, { title: '申请金额（分）', dataIndex: 'requested_amount' }, { title: '手续费（分）', dataIndex: 'fee_amount' }, { title: '预计到账（分）', dataIndex: 'received_amount' },
      { title: '审核', render: (_: unknown, item: WalletWithdrawal) => <Space><Button icon={<CheckOutlined />} onClick={() => { setDecision('approve'); setWithdrawalTarget(item) }}>批准</Button><Button danger icon={<CloseOutlined />} onClick={() => { setDecision('reject'); setWithdrawalTarget(item) }}>拒绝并解冻</Button></Space> },
    ]} /></Card>
    <ReconciliationPanel />
    <Modal title="发起资产调整" open={adjustOpen} onCancel={() => setAdjustOpen(false)} onOk={() => adjustForm.submit()} confirmLoading={submitting} destroyOnHidden><Form form={adjustForm} layout="vertical" onFinish={submitAdjustment} initialValues={{ asset_type: 'points', action: 'increase' }}><Form.Item name="asset_type" label="资产类型" rules={[{ required: true }]}><Select options={[{ value: 'points', label: '积分' }, { value: 'wallet', label: '余额（分）' }]} /></Form.Item><Form.Item name="action" label="操作" rules={[{ required: true }]}><Select options={[{ value: 'increase', label: '增加' }, { value: 'decrease', label: '减少' }, { value: 'clear', label: '清零（始终二审）' }]} /></Form.Item>{action !== 'clear' && <Form.Item name="amount" label="数量" rules={[{ required: true }]}><InputNumber min={1} style={{ width: '100%' }} /></Form.Item>}<Form.Item name="reason" label="原因" rules={[{ required: true }]}><Input.TextArea rows={3} /></Form.Item><Form.Item name="password" label="管理员密码" rules={[{ required: true }]}><Input.Password /></Form.Item><Form.Item name="mfa_code" label="MFA 动态码"><Input /></Form.Item></Form></Modal>
    <Modal title={decision === 'approve' ? '批准申请' : '拒绝申请'} open={reviewTarget !== null || withdrawalTarget !== null} onCancel={() => { setReviewTarget(null); setWithdrawalTarget(null) }} onOk={() => reviewForm.submit()} confirmLoading={submitting} destroyOnHidden><Form form={reviewForm} layout="vertical" onFinish={submitReview}><Form.Item name="note" label="复核意见" rules={[{ required: true }]}><Input.TextArea rows={3} /></Form.Item><Form.Item name="password" label="管理员密码" rules={[{ required: true }]}><Input.Password /></Form.Item><Form.Item name="mfa_code" label="MFA 动态码"><Input /></Form.Item></Form></Modal>
  </>
}
