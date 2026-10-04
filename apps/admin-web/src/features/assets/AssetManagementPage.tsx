import { CheckOutlined, CloseOutlined, CopyOutlined, PlusOutlined, SearchOutlined, SwapOutlined } from '@ant-design/icons'
import { Alert, Button, Card, Descriptions, Form, Input, InputNumber, Modal, Select, Space, Statistic, Table, Tag, Typography, message } from 'antd'
import { useCallback, useEffect, useState } from 'react'
import {
  ApiError,
  createAssetAdjustment,
  createWalletRechargeCards,
  downloadWalletRechargeProof,
  getAdminUserAssets,
  listAssetAdjustments,
  listWalletWithdrawals,
  listWalletRecharges,
  listWalletRechargeCards,
  recordManualWithdrawalResult,
  reviewAssetAdjustment,
  reviewWalletWithdrawal,
  reviewWalletRecharge,
  verifyAdminRecent,
  type AssetAdjustment,
  type UserAssetSummary,
  type WalletWithdrawal,
  type WalletRecharge,
  type WalletRechargeCard,
} from '../../api/admin'
import { ReconciliationPanel } from './ReconciliationPanel'
import { BusinessStatusTag } from '../../components/BusinessStatusTag'
import { assetAdjustmentActionLabels, assetAdjustmentStatusLabels, assetTypeLabels, businessLabel, withdrawalStatusLabels } from '../../constants/businessLabels'

interface AdjustmentForm { asset_type: 'points' | 'wallet'; action: 'increase' | 'decrease' | 'clear'; amount?: number; reason: string; password: string; mfa_code?: string }
interface ReviewForm { note: string; password: string; mfa_code?: string }
interface CardBatchForm { amount_yuan: number; quantity: number; expires_at?: string; password: string; mfa_code?: string }
interface ManualPayoutForm { reference?: string; note?: string; password: string; mfa_code?: string }

const yuan = (cents: number) => `¥${(cents / 100).toFixed(2)}`
const renderWithdrawalChannel = (item: WalletWithdrawal) => {
  if (item.channel === 'bank_card' && item.bank_card) {
    const c = item.bank_card
    return <Space direction="vertical" size={0}>
      <Tag color="orange">银行卡</Tag>
      <Typography.Text type="secondary" style={{ fontSize: 12 }}>{c.bank_name} {c.card_masked}</Typography.Text>
      <Typography.Text type="secondary" style={{ fontSize: 12 }}>{c.cardholder_name} · {c.phone_masked}</Typography.Text>
    </Space>
  }
  return <Tag color="green">微信零钱</Tag>
}

export function AssetManagementPage() {
  const [userId, setUserId] = useState('')
  const [assets, setAssets] = useState<UserAssetSummary | null>(null)
  const [pending, setPending] = useState<AssetAdjustment[]>([])
  const [withdrawals, setWithdrawals] = useState<WalletWithdrawal[]>([])
  const [bankPayouts, setBankPayouts] = useState<WalletWithdrawal[]>([])
  const [recharges, setRecharges] = useState<WalletRecharge[]>([])
  const [rechargeCards, setRechargeCards] = useState<WalletRechargeCard[]>([])
  const [generatedCards, setGeneratedCards] = useState<WalletRechargeCard[]>([])
  const [adjustOpen, setAdjustOpen] = useState(false)
  const [reviewTarget, setReviewTarget] = useState<AssetAdjustment | null>(null)
  const [withdrawalTarget, setWithdrawalTarget] = useState<WalletWithdrawal | null>(null)
  const [rechargeTarget, setRechargeTarget] = useState<WalletRecharge | null>(null)
  const [manualTarget, setManualTarget] = useState<WalletWithdrawal | null>(null)
  const [manualDecision, setManualDecision] = useState<'paid' | 'failed'>('paid')
  const [cardOpen, setCardOpen] = useState(false)
  const [decision, setDecision] = useState<'approve' | 'reject'>('approve')
  const [submitting, setSubmitting] = useState(false)
  const [adjustForm] = Form.useForm<AdjustmentForm>()
  const [reviewForm] = Form.useForm<ReviewForm>()
  const [manualForm] = Form.useForm<ManualPayoutForm>()
  const [cardForm] = Form.useForm<CardBatchForm>()
  const [messageApi, contextHolder] = message.useMessage()
  const action = Form.useWatch('action', adjustForm)

  const loadPending = useCallback(async () => {
    try {
      const [adjustments, withdrawalItems, processingItems, rechargeItems, cards] = await Promise.all([
        listAssetAdjustments(), listWalletWithdrawals(), listWalletWithdrawals('processing'), listWalletRecharges(), listWalletRechargeCards(),
      ])
      setPending(adjustments.items)
      setWithdrawals(withdrawalItems.items)
      setBankPayouts(processingItems.items.filter((item) => item.channel === 'bank_card'))
      setRecharges(rechargeItems.items)
      setRechargeCards(cards.items)
    }
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
    if (!reviewTarget && !withdrawalTarget && !rechargeTarget) return
    setSubmitting(true)
    try {
      await verifyAdminRecent(values.password, values.mfa_code)
      if (reviewTarget) await reviewAssetAdjustment(reviewTarget.id, decision, values.note)
      if (withdrawalTarget) await reviewWalletWithdrawal(withdrawalTarget.id, decision, values.note)
      if (rechargeTarget) await reviewWalletRecharge(rechargeTarget.id, decision, values.note)
      setReviewTarget(null); setWithdrawalTarget(null); setRechargeTarget(null); reviewForm.resetFields(); await loadPending(); if (assets) await search()
      messageApi.success(rechargeTarget ? (decision === 'approve' ? '线下付款已核验，余额已经到账。' : '线下充值已拒绝，余额未变动。') : withdrawalTarget ? (decision === 'approve' ? (withdrawalTarget.channel === 'bank_card' ? '已通过审核，请在「待人工打款」中登记银行转账结果。' : '提现已批准，等待真实渠道出款。') : '提现已拒绝并解冻余额。') : (decision === 'approve' ? '复核通过并已记账。' : '申请已拒绝，资产未变动。'))
    } catch (cause) { messageApi.error(cause instanceof ApiError ? cause.message : '复核失败。') }
    finally { setSubmitting(false) }
  }

  const submitManualPayout = async (values: ManualPayoutForm) => {
    if (!manualTarget) return
    setSubmitting(true)
    try {
      await verifyAdminRecent(values.password, values.mfa_code)
      await recordManualWithdrawalResult(manualTarget.id, manualDecision, {
        ...(manualDecision === 'paid' ? { reference: values.reference?.trim() } : { note: values.note?.trim() }),
      })
      setManualTarget(null); manualForm.resetFields(); await loadPending()
      messageApi.success(manualDecision === 'paid' ? '人工打款已登记，提现单完成。' : '打款失败已登记，金额已解冻退回用户余额。')
    } catch (cause) { messageApi.error(cause instanceof ApiError ? cause.message : '登记打款结果失败。') }
    finally { setSubmitting(false) }
  }

  const submitCardBatch = async (values: CardBatchForm) => {
    setSubmitting(true)
    try {
      await verifyAdminRecent(values.password, values.mfa_code)
      const result = await createWalletRechargeCards({ amount_cents: Math.round(values.amount_yuan * 100), quantity: values.quantity, ...(values.expires_at ? { expires_at: new Date(values.expires_at).toISOString() } : {}) })
      setGeneratedCards(result.cards); setCardOpen(false); cardForm.resetFields(); await loadPending()
      messageApi.success(`已生成 ${result.cards.length} 张卡密，明文仅本次展示。`)
    } catch (cause) { messageApi.error(cause instanceof ApiError ? cause.message : '卡密生成失败。') }
    finally { setSubmitting(false) }
  }

  const copyGeneratedCards = async () => {
    await navigator.clipboard.writeText(generatedCards.map((item) => `${item.code}\t¥${(item.amount_cents / 100).toFixed(2)}`).join('\n'))
    messageApi.success('本批卡密已复制，请保存到受控位置。')
  }

  const openRechargeProof = async (rechargeId: string, fileId: string) => {
    try { const blob = await downloadWalletRechargeProof(rechargeId, fileId); window.open(URL.createObjectURL(blob), '_blank', 'noopener,noreferrer') }
    catch (cause) { messageApi.error(cause instanceof ApiError ? cause.message : '付款凭证打开失败。') }
  }

  return <>
    {contextHolder}
    <div className="page-heading"><div><Typography.Text className="eyebrow">校园资产账本</Typography.Text><Typography.Title level={2}>用户资产与财务复核</Typography.Title><Typography.Paragraph type="secondary">所有增减和清零都追加账本，不覆盖历史；高额操作与全部清零强制四眼复核。</Typography.Paragraph></div></div>
    <Alert showIcon type="warning" message="余额调整超过 200 元、积分调整超过 1000 分或任何清零操作，申请人与复核人必须不同。" />
    <Card bordered={false} style={{ marginTop: 16 }}><Space.Compact block><Input value={userId} onChange={(event) => setUserId(event.target.value)} placeholder="输入用户 ULID" onPressEnter={() => void search()} /><Button type="primary" icon={<SearchOutlined />} onClick={() => void search()}>查询资产</Button></Space.Compact></Card>
    {assets && <Card bordered={false} style={{ marginTop: 16 }} title={`用户 ${assets.user_id}`} extra={<Button icon={<SwapOutlined />} onClick={() => setAdjustOpen(true)}>发起调整</Button>}><Space size="large" wrap><Statistic title="可用积分" value={assets.points.available} /><Statistic title="积分欠额" value={assets.points.debt} /><Statistic title="可用余额（分）" value={assets.wallet.available_amount} /><Statistic title="冻结余额（分）" value={assets.wallet.frozen_amount} /><Statistic title="可用优惠券" value={assets.coupons.available} /></Space></Card>}
    <Card bordered={false} style={{ marginTop: 16 }} title="待财务复核"><Table rowKey="id" dataSource={pending} pagination={false} scroll={{ x: 860 }} columns={[
      { title: '资产', dataIndex: 'asset_type', render: (value: string) => businessLabel(assetTypeLabels, value) }, { title: '操作', dataIndex: 'action', render: (value: string) => businessLabel(assetAdjustmentActionLabels, value) }, { title: '数量', dataIndex: 'amount', render: (value: number | null) => value ?? '当前全部可用值' },
      { title: '原因', dataIndex: 'reason' }, { title: '状态', dataIndex: 'status', render: (value: string) => <BusinessStatusTag status={value} label={businessLabel(assetAdjustmentStatusLabels, value)} /> },
      { title: '复核', render: (_: unknown, item: AssetAdjustment) => <Space><Button icon={<CheckOutlined />} onClick={() => { setDecision('approve'); setReviewTarget(item) }}>批准</Button><Button danger icon={<CloseOutlined />} onClick={() => { setDecision('reject'); setReviewTarget(item) }}>拒绝</Button></Space> },
    ]} /></Card>
    <Card bordered={false} style={{ marginTop: 16 }} title="待审核提现"><Table rowKey="id" dataSource={withdrawals} pagination={false} scroll={{ x: 1000 }} columns={[
      { title: '提现单', dataIndex: 'withdrawal_no' }, { title: '申请金额', dataIndex: 'requested_amount', render: (value: number) => yuan(value) }, { title: '手续费', dataIndex: 'fee_amount', render: (value: number) => yuan(value) }, { title: '预计到账', dataIndex: 'received_amount', render: (value: number) => yuan(value) },
      { title: '到账方式', render: (_: unknown, item: WalletWithdrawal) => renderWithdrawalChannel(item) },
      { title: '状态', dataIndex: 'status', render: (value: string) => <BusinessStatusTag status={value} label={businessLabel(withdrawalStatusLabels, value)} /> },
      { title: '审核', render: (_: unknown, item: WalletWithdrawal) => <Space><Button icon={<CheckOutlined />} onClick={() => { setDecision('approve'); setWithdrawalTarget(item) }}>批准</Button><Button danger icon={<CloseOutlined />} onClick={() => { setDecision('reject'); setWithdrawalTarget(item) }}>拒绝并解冻</Button></Space> },
    ]} /></Card>
    <Card bordered={false} style={{ marginTop: 16 }} title={<Space>待人工打款（银行卡提现）<Tag color="orange">{bankPayouts.length}</Tag></Space>} extra={<Typography.Text type="secondary" style={{ fontSize: 12 }}>审核通过的银行卡提现需线下转账后在此登记</Typography.Text>}>
      <Alert showIcon type="info" message="请按收款信息完成线下银行转账，再登记银行流水号；转账失败请登记失败原因，系统会自动解冻退回用户余额。" style={{ marginBottom: 12 }} />
      <Table rowKey="id" dataSource={bankPayouts} pagination={false} scroll={{ x: 1000 }} locale={{ emptyText: '暂无待人工打款的银行卡提现' }} columns={[
        { title: '提现单', dataIndex: 'withdrawal_no' },
        { title: '收款人', render: (_: unknown, item: WalletWithdrawal) => item.bank_card ? <Space direction="vertical" size={0}><Typography.Text strong>{item.bank_card.cardholder_name}</Typography.Text><Typography.Text type="secondary" style={{ fontSize: 12 }}>{item.bank_card.phone_masked}</Typography.Text></Space> : '—' },
        { title: '收款账户', render: (_: unknown, item: WalletWithdrawal) => item.bank_card ? <Space direction="vertical" size={0}><Typography.Text strong>{item.bank_card.bank_name}</Typography.Text><Typography.Text type="secondary" style={{ fontSize: 12 }}>{item.bank_card.card_masked}</Typography.Text></Space> : '—' },
        { title: '到账金额', dataIndex: 'received_amount', render: (value: number) => <Typography.Text strong>{yuan(value)}</Typography.Text> },
        { title: '提交时间', dataIndex: 'created_at', render: (value: string) => new Date(value).toLocaleString() },
        { title: '登记', render: (_: unknown, item: WalletWithdrawal) => <Space><Button type="primary" icon={<CheckOutlined />} onClick={() => { setManualDecision('paid'); setManualTarget(item); manualForm.resetFields() }}>已转账</Button><Button danger icon={<CloseOutlined />} onClick={() => { setManualDecision('failed'); setManualTarget(item); manualForm.resetFields() }}>打款失败</Button></Space> },
      ]} />
    </Card>
    <Card bordered={false} style={{ marginTop: 16 }} title="线下充值审核"><Table rowKey="id" dataSource={recharges} pagination={false} scroll={{ x: 1100 }} columns={[
      { title: '充值单', dataIndex: 'recharge_no' }, { title: '用户', dataIndex: 'user_id' }, { title: '金额', dataIndex: 'amount_cents', render: (value: number) => `¥${(value / 100).toFixed(2)}` }, { title: '付款人', dataIndex: 'payer_name' }, { title: '转账单号', dataIndex: 'offline_reference', render: (value: string | null) => value || '未填写' }, { title: '凭证', render: (_: unknown, item: WalletRecharge) => <Space wrap>{item.offline_proof_file_ids.map((fileId, index) => <Button size="small" key={fileId} onClick={() => void openRechargeProof(item.id, fileId)}>凭证 {index + 1}</Button>)}</Space> },
      { title: '审核', render: (_: unknown, item: WalletRecharge) => <Space><Button icon={<CheckOutlined />} onClick={() => { setDecision('approve'); setRechargeTarget(item) }}>确认到账</Button><Button danger icon={<CloseOutlined />} onClick={() => { setDecision('reject'); setRechargeTarget(item) }}>拒绝</Button></Space> },
    ]} /></Card>
    <Card bordered={false} style={{ marginTop: 16 }} title="充值卡密管理" extra={<Button type="primary" icon={<PlusOutlined />} onClick={() => setCardOpen(true)}>批量生成卡密</Button>}><Table rowKey="id" dataSource={rechargeCards} pagination={{ pageSize: 10 }} scroll={{ x: 760 }} columns={[
      { title: '卡密掩码', dataIndex: 'code_mask' }, { title: '面额', dataIndex: 'amount_cents', render: (value: number) => `¥${(value / 100).toFixed(2)}` }, { title: '状态', dataIndex: 'status', render: (value: string) => <BusinessStatusTag status={value} label={({ active: '未使用', redeemed: '已兑换', disabled: '已停用', expired: '已过期' } as Record<string,string>)[value] || '状态更新中'} /> }, { title: '有效期', dataIndex: 'expires_at', render: (value: string | null) => value ? new Date(value).toLocaleString() : '长期有效' },
    ]} /></Card>
    <ReconciliationPanel />
    <Modal title="发起资产调整" open={adjustOpen} onCancel={() => setAdjustOpen(false)} onOk={() => adjustForm.submit()} confirmLoading={submitting} destroyOnHidden><Form form={adjustForm} layout="vertical" onFinish={submitAdjustment} initialValues={{ asset_type: 'points', action: 'increase' }}><Form.Item name="asset_type" label="资产类型" rules={[{ required: true }]}><Select options={[{ value: 'points', label: '积分' }, { value: 'wallet', label: '余额（分）' }]} /></Form.Item><Form.Item name="action" label="操作" rules={[{ required: true }]}><Select options={[{ value: 'increase', label: '增加' }, { value: 'decrease', label: '减少' }, { value: 'clear', label: '清零（始终二审）' }]} /></Form.Item>{action !== 'clear' && <Form.Item name="amount" label="数量" rules={[{ required: true }]}><InputNumber min={1} style={{ width: '100%' }} /></Form.Item>}<Form.Item name="reason" label="原因" rules={[{ required: true }]}><Input.TextArea rows={3} /></Form.Item><Form.Item name="password" label="管理员密码" rules={[{ required: true }]}><Input.Password /></Form.Item><Form.Item name="mfa_code" label="MFA 动态码"><Input /></Form.Item></Form></Modal>
    <Modal title={decision === 'approve' ? '批准申请' : '拒绝申请'} open={reviewTarget !== null || withdrawalTarget !== null || rechargeTarget !== null} onCancel={() => { setReviewTarget(null); setWithdrawalTarget(null); setRechargeTarget(null) }} onOk={() => reviewForm.submit()} confirmLoading={submitting} destroyOnHidden><Form form={reviewForm} layout="vertical" onFinish={submitReview}><Form.Item name="note" label="复核意见" rules={[{ required: true }]}><Input.TextArea rows={3} /></Form.Item><Form.Item name="password" label="管理员密码" rules={[{ required: true }]}><Input.Password /></Form.Item><Form.Item name="mfa_code" label="MFA 动态码"><Input /></Form.Item></Form></Modal>
    <Modal title={manualDecision === 'paid' ? '登记银行卡人工打款：已转账' : '登记银行卡人工打款：打款失败'} open={manualTarget !== null} onCancel={() => setManualTarget(null)} onOk={() => manualForm.submit()} confirmLoading={submitting} destroyOnHidden>
      {manualTarget?.bank_card && <Descriptions size="small" column={1} bordered style={{ marginBottom: 16 }} items={[
        { key: 'withdrawal', label: '提现单号', children: manualTarget.withdrawal_no },
        { key: 'payee', label: '收款人', children: `${manualTarget.bank_card.cardholder_name}（${manualTarget.bank_card.phone_masked}）` },
        { key: 'account', label: '收款账户', children: `${manualTarget.bank_card.bank_name} ${manualTarget.bank_card.card_masked}` },
        { key: 'amount', label: '到账金额', children: yuan(manualTarget.received_amount) },
      ]} />}
      <Form form={manualForm} layout="vertical" onFinish={submitManualPayout}>
        {manualDecision === 'paid'
          ? <><Alert showIcon type="success" message="确认银行转账已完成并到账后再提交，提交后提现单立即完结、不可撤销。" style={{ marginBottom: 16 }} /><Form.Item name="reference" label="银行转账流水号/凭证号" rules={[{ required: true, message: '请填写银行转账流水号' }]}><Input placeholder="银行回单上的交易流水号" /></Form.Item></>
          : <><Alert showIcon type="error" message="登记失败后提现单关闭，冻结金额自动解冻退回用户余额。" style={{ marginBottom: 16 }} /><Form.Item name="note" label="打款失败原因" rules={[{ required: true, message: '请填写打款失败原因' }]}><Input.TextArea rows={3} placeholder="如：账户信息有误/银行退票/用户信息不符等" /></Form.Item></>}
        <Form.Item name="password" label="管理员密码" rules={[{ required: true }]}><Input.Password /></Form.Item>
        <Form.Item name="mfa_code" label="MFA 动态码"><Input /></Form.Item>
      </Form>
    </Modal>
    <Modal title="批量生成充值卡密" open={cardOpen} onCancel={() => setCardOpen(false)} onOk={() => cardForm.submit()} confirmLoading={submitting} destroyOnHidden><Alert showIcon type="warning" message="卡密明文只会展示一次，生成后请立即复制并妥善保管。" style={{ marginBottom: 16 }} /><Form form={cardForm} layout="vertical" onFinish={submitCardBatch} initialValues={{ amount_yuan: 100, quantity: 10 }}><Form.Item name="amount_yuan" label="单张面额（元）" rules={[{ required: true }]}><InputNumber min={1} max={50000} precision={2} style={{ width: '100%' }} /></Form.Item><Form.Item name="quantity" label="生成数量" rules={[{ required: true }]}><InputNumber min={1} max={100} style={{ width: '100%' }} /></Form.Item><Form.Item name="expires_at" label="有效期（选填）"><Input type="datetime-local" /></Form.Item><Form.Item name="password" label="管理员密码" rules={[{ required: true }]}><Input.Password /></Form.Item><Form.Item name="mfa_code" label="MFA 动态码"><Input /></Form.Item></Form></Modal>
    <Modal title="本批充值卡密（仅展示一次）" open={generatedCards.length > 0} onCancel={() => setGeneratedCards([])} footer={<Space><Button icon={<CopyOutlined />} onClick={() => void copyGeneratedCards()}>复制全部</Button><Button type="primary" onClick={() => setGeneratedCards([])}>我已安全保存</Button></Space>} width={720}><Alert showIcon type="error" message="关闭后后台只能看到掩码，无法找回明文。" /><Input.TextArea style={{ marginTop: 16 }} autoSize={{ minRows: 8, maxRows: 16 }} readOnly value={generatedCards.map((item) => `${item.code}    ¥${(item.amount_cents / 100).toFixed(2)}`).join('\n')} /></Modal>
  </>
}
