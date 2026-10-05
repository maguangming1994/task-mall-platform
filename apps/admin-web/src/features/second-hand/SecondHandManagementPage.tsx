import { Alert, Button, Card, Form, Image, Input, Modal, Radio, Select, Space, Table, Tabs, Tag, Typography, message } from 'antd'
import { useCallback, useEffect, useState } from 'react'
import {
  ApiError,
  arbitrateSecondHandRefund,
  listSecondHandItems,
  listSecondHandRefunds,
  reviewSecondHandItem,
  type ManagedSecondHandItem,
  type ManagedSecondHandRefund,
} from '../../api/admin'
import { useAdminAuth } from '../auth/AdminAuthContext'

type ReviewDecision = 'approve' | 'reject'

interface ItemReviewForm {
  decision: ReviewDecision
  reason?: string
}

const ITEM_REJECT_PRESETS = [
  '图片与实物不符，存在误导性展示',
  '商品包含违禁违规内容，违反平台规则',
  '价格或描述异常，疑似引流或欺诈',
  '疑似虚假信息或重复发布的无效商品',
]

interface RefundArbitrateForm {
  decision: ReviewDecision
  note: string
}

const itemStatusMeta: Record<string, { label: string; color: string }> = {
  pending: { label: '待审核', color: 'gold' },
  approved: { label: '已通过', color: 'success' },
  rejected: { label: '已驳回', color: 'error' },
  on_sale: { label: '在售', color: 'success' },
  sold: { label: '已售出', color: 'default' },
  off_shelf: { label: '已下架', color: 'default' },
}

const refundStatusMeta: Record<string, { label: string; color: string }> = {
  pending: { label: '待处理', color: 'gold' },
  seller_approved: { label: '卖家已同意', color: 'success' },
  seller_rejected: { label: '卖家已拒绝', color: 'warning' },
  arbitrating: { label: '平台仲裁中', color: 'processing' },
  arbitrated_approved: { label: '仲裁通过', color: 'success' },
  arbitrated_rejected: { label: '仲裁拒绝', color: 'error' },
}

function formatDate(value?: string | null) {
  return value ? new Date(value).toLocaleString('zh-CN', { hour12: false }) : '—'
}

function formatAmount(cents: number) {
  return `¥${(cents / 100).toFixed(2)}`
}

/* 闲置商品价格后端单位为元（price_fen 才是分），直接按元格式化 */
function formatYuan(yuan: number) {
  return `¥${Number(yuan || 0).toFixed(2)}`
}

function mediaUrl(publicId?: string | null) {
  return publicId ? `/api/v1/media/assets/${publicId}` : ''
}

function compactError(error: unknown, fallback: string) {
  return error instanceof ApiError || error instanceof Error ? error.message : fallback
}

function itemStatusTag(value: string) {
  const meta = itemStatusMeta[value] ?? { label: value || '未知状态', color: 'default' }
  return <Tag color={meta.color}>{meta.label}</Tag>
}

function refundStatusTag(value: string) {
  const meta = refundStatusMeta[value] ?? { label: value || '未知状态', color: 'default' }
  return <Tag color={meta.color}>{meta.label}</Tag>
}

export function SecondHandManagementPage() {
  const [items, setItems] = useState<ManagedSecondHandItem[]>([])
  const [itemsPagination, setItemsPagination] = useState({ page: 1, page_size: 20, total: 0 })
  const [itemStatus, setItemStatus] = useState('pending')
  const [itemsLoading, setItemsLoading] = useState(false)
  const [refunds, setRefunds] = useState<ManagedSecondHandRefund[]>([])
  const [refundsPagination, setRefundsPagination] = useState({ page: 1, page_size: 20, total: 0 })
  const [refundStatus, setRefundStatus] = useState('pending')
  const [refundsLoading, setRefundsLoading] = useState(false)
  const [busy, setBusy] = useState('')
  const [reviewingItem, setReviewingItem] = useState<ManagedSecondHandItem | null>(null)
  const [viewingItem, setViewingItem] = useState<ManagedSecondHandItem | null>(null)
  const [arbitratingRefund, setArbitratingRefund] = useState<ManagedSecondHandRefund | null>(null)
  const [reviewForm] = Form.useForm<ItemReviewForm>()
  const [arbitrateForm] = Form.useForm<RefundArbitrateForm>()
  const [messageApi, contextHolder] = message.useMessage()
  const reviewDecision = Form.useWatch('decision', reviewForm)
  const { admin } = useAdminAuth()
  // 商品审核需 community.review；退款仲裁需 after_sale.review，按权限裁剪 Tab 与请求，避免无权限 403
  const canReviewItems = admin?.permissions?.includes('community.review') ?? false
  const canReviewRefunds = admin?.permissions?.includes('after_sale.review') ?? false

  const loadItems = useCallback(async (page: number, status: string) => {
    setItemsLoading(true)
    try {
      const result = await listSecondHandItems({ audit_status: status, page, page_size: 20 })
      setItems(result.items)
      setItemsPagination({ page: result.pagination.page, page_size: result.pagination.page_size, total: result.pagination.total })
    } catch (error) {
      messageApi.error(compactError(error, '闲置商品列表加载失败。'))
    } finally {
      setItemsLoading(false)
    }
  }, [messageApi])

  const loadRefunds = useCallback(async (page: number, status: string) => {
    setRefundsLoading(true)
    try {
      const result = await listSecondHandRefunds({ status, page, page_size: 20 })
      setRefunds(result.items)
      setRefundsPagination({ page: result.pagination.page, page_size: result.pagination.page_size, total: result.pagination.total })
    } catch (error) {
      messageApi.error(compactError(error, '闲置退款列表加载失败。'))
    } finally {
      setRefundsLoading(false)
    }
  }, [messageApi])

  useEffect(() => { if (canReviewItems) void loadItems(1, itemStatus) }, [loadItems, itemStatus, canReviewItems])
  useEffect(() => { if (canReviewRefunds) void loadRefunds(1, refundStatus) }, [loadRefunds, refundStatus, canReviewRefunds])

  const submitItemReview = async (values: ItemReviewForm) => {
    if (!reviewingItem) return
    setBusy(`item-${reviewingItem.id}`)
    try {
      await reviewSecondHandItem(reviewingItem.id, { decision: values.decision, reason: values.decision === 'reject' ? values.reason?.trim() : undefined })
      messageApi.success(values.decision === 'approve' ? '商品已通过审核。' : '商品已驳回。')
      setReviewingItem(null)
      reviewForm.resetFields()
      await loadItems(itemsPagination.page, itemStatus)
    } catch (error) {
      messageApi.error(compactError(error, '审核操作失败，请稍后重试。'))
    } finally {
      setBusy('')
    }
  }

  const submitRefundArbitrate = async (values: RefundArbitrateForm) => {
    if (!arbitratingRefund) return
    setBusy(`refund-${arbitratingRefund.id}`)
    try {
      await arbitrateSecondHandRefund(arbitratingRefund.id, { decision: values.decision, note: values.note.trim() })
      messageApi.success(values.decision === 'approve' ? '仲裁已通过，退款将执行。' : '仲裁已拒绝退款。')
      setArbitratingRefund(null)
      arbitrateForm.resetFields()
      await loadRefunds(refundsPagination.page, refundStatus)
    } catch (error) {
      messageApi.error(compactError(error, '仲裁操作失败，请稍后重试。'))
    } finally {
      setBusy('')
    }
  }

  return <>
    {contextHolder}
    <div className="page-heading">
      <div>
        <Typography.Text className="eyebrow">SECOND-HAND MARKETPLACE</Typography.Text>
        <Typography.Title level={2}>闲置审核</Typography.Title>
        <Typography.Paragraph type="secondary">审核闲置商品发布内容，并对买卖双方协商不成的退款申请进行平台仲裁。</Typography.Paragraph>
      </div>
      <Space wrap><Tag color="gold">内容审核</Tag><Tag color="processing">退款仲裁</Tag></Space>
    </div>

    <Card variant="borderless">
      <Tabs items={[
        ...(canReviewItems ? [{
        key: 'items',
        label: `商品审核 (${itemsPagination.total})`,
          children: <>
            <Space style={{ marginBottom: 16 }} wrap>
              <Typography.Text type="secondary">审核状态</Typography.Text>
              <Select
                value={itemStatus}
                style={{ width: 160 }}
                options={[
                  { value: 'pending', label: '待审核' },
                  { value: 'approved', label: '已通过' },
                  { value: 'rejected', label: '已驳回' },
                ]}
                onChange={(value) => setItemStatus(value)}
              />
              <Button onClick={() => void loadItems(itemsPagination.page, itemStatus)}>刷新</Button>
            </Space>
            <Table
              rowKey="id"
              loading={itemsLoading}
              dataSource={items}
              scroll={{ x: 1380 }}
              locale={{ emptyText: '当前没有符合条件的闲置商品' }}
              pagination={{
                current: itemsPagination.page,
                pageSize: itemsPagination.page_size,
                total: itemsPagination.total,
                showSizeChanger: false,
                onChange: (page) => void loadItems(page, itemStatus),
              }}
              columns={[
                { title: '商品标题', width: 260, render: (_: unknown, row: ManagedSecondHandItem) => <Space><Image src={mediaUrl(row.cover)} alt={row.title} width={48} height={48} style={{ objectFit: 'cover', borderRadius: 8 }} fallback="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='48' height='48'%3E%3C/svg%3E" /><Space direction="vertical" size={2}><strong>{row.title}</strong><Typography.Text type="secondary">{row.category?.name ?? '未分类'}</Typography.Text>{row.audit_status === 'rejected' && row.rejected_reason ? <Typography.Text type="danger" ellipsis={{ tooltip: row.rejected_reason }} style={{ maxWidth: 200, fontSize: 12 }}>驳回：{row.rejected_reason}</Typography.Text> : null}</Space></Space> },
                { title: '价格', width: 110, render: (_: unknown, row: ManagedSecondHandItem) => formatYuan(row.price) },
                { title: '成色', width: 100, dataIndex: 'condition_label' },
                { title: '卖家 / 信用', width: 220, render: (_: unknown, row: ManagedSecondHandItem) => <Space direction="vertical" size={2}><Space size={4}><strong>{row.seller.nickname}</strong>{row.seller.identity_verified ? <Tag color="success" style={{ marginInlineEnd: 0 }}>已实名</Tag> : <Tag style={{ marginInlineEnd: 0 }}>未实名</Tag>}</Space><Typography.Text type="secondary" style={{ fontSize: 12 }}>信用 {row.seller.rating_average ?? '—'}（{row.seller.rating_count ?? 0}）· 在售 {row.seller.on_sale_count ?? 0} · 成交 {row.seller.sold_count ?? 0}</Typography.Text></Space> },
                { title: '商品状态', width: 100, render: (_: unknown, row: ManagedSecondHandItem) => itemStatusTag(row.status) },
                { title: '审核状态', width: 110, render: (_: unknown, row: ManagedSecondHandItem) => itemStatusTag(row.audit_status) },
                { title: '提交时间', width: 170, render: (_: unknown, row: ManagedSecondHandItem) => formatDate(row.submitted_at || row.created_at) },
                { title: '审核留痕', width: 200, render: (_: unknown, row: ManagedSecondHandItem) => row.audit_status === 'pending'
                  ? <Typography.Text type="secondary">待审核</Typography.Text>
                  : <Space direction="vertical" size={0}><Typography.Text style={{ fontSize: 12 }}>{row.reviewed_by_name ?? '系统'}</Typography.Text><Typography.Text type="secondary" style={{ fontSize: 12 }}>{formatDate(row.reviewed_at)}</Typography.Text></Space> },
                { title: '操作', width: 100, fixed: 'right', render: (_: unknown, row: ManagedSecondHandItem) => row.audit_status === 'pending'
                  ? <Button size="small" type="primary" onClick={() => { reviewForm.setFieldsValue({ decision: 'approve', reason: '' }); setReviewingItem(row) }}>审核</Button>
                  : <Button size="small" onClick={() => setViewingItem(row)}>查看</Button> },
              ]}
            />
          </>,
        },
        ] : []),
        ...(canReviewRefunds ? [{
        key: 'refunds',
        label: `退款仲裁 (${refundsPagination.total})`,
          children: <>
            <Space style={{ marginBottom: 16 }} wrap>
              <Typography.Text type="secondary">退款状态</Typography.Text>
              <Select
                value={refundStatus}
                style={{ width: 180 }}
                options={[
                  { value: 'pending', label: '待处理' },
                  { value: 'seller_approved', label: '卖家已同意' },
                  { value: 'seller_rejected', label: '卖家已拒绝' },
                  { value: 'arbitrating', label: '平台仲裁中' },
                  { value: 'arbitrated_approved', label: '仲裁通过' },
                  { value: 'arbitrated_rejected', label: '仲裁拒绝' },
                ]}
                onChange={(value) => setRefundStatus(value)}
              />
              <Button onClick={() => void loadRefunds(refundsPagination.page, refundStatus)}>刷新</Button>
            </Space>
            <Table
              rowKey="id"
              loading={refundsLoading}
              dataSource={refunds}
              scroll={{ x: 1080 }}
              locale={{ emptyText: '当前没有符合条件的退款申请' }}
              pagination={{
                current: refundsPagination.page,
                pageSize: refundsPagination.page_size,
                total: refundsPagination.total,
                showSizeChanger: false,
                onChange: (page) => void loadRefunds(page, refundStatus),
              }}
              columns={[
                { title: '订单号', width: 200, dataIndex: 'order_id' },
                { title: '金额', width: 110, render: (_: unknown, row: ManagedSecondHandRefund) => formatAmount(row.amount) },
                { title: '退款原因', dataIndex: 'reason', ellipsis: true },
                { title: '买家', width: 130, render: (_: unknown, row: ManagedSecondHandRefund) => row.buyer.nickname },
                { title: '卖家', width: 130, render: (_: unknown, row: ManagedSecondHandRefund) => row.seller.nickname },
                { title: '状态', width: 120, render: (_: unknown, row: ManagedSecondHandRefund) => refundStatusTag(row.status) },
                { title: '创建时间', width: 180, dataIndex: 'created_at', render: formatDate },
                { title: '操作', width: 100, fixed: 'right', render: (_: unknown, row: ManagedSecondHandRefund) => <Button size="small" type="primary" disabled={!['pending', 'arbitrating'].includes(row.status)} onClick={() => { arbitrateForm.setFieldsValue({ decision: 'approve', note: '' }); setArbitratingRefund(row) }}>仲裁</Button> },
              ]}
            />
          </>,
        },
        ] : []),
      ]} />
    </Card>

    <Modal
      title={`审核商品：${reviewingItem?.title ?? ''}`}
      open={reviewingItem !== null}
      onCancel={() => { setReviewingItem(null); reviewForm.resetFields() }}
      onOk={() => reviewForm.submit()}
      okText="提交审核"
      okButtonProps={{ danger: reviewDecision === 'reject' }}
      confirmLoading={Boolean(reviewingItem && busy === `item-${reviewingItem.id}`)}
      cancelText="取消"
      destroyOnHidden
    >
      {reviewingItem && <Space direction="vertical" size={12} style={{ width: '100%', marginBottom: 16 }}>
        <Space align="start">
          <Image src={mediaUrl(reviewingItem.cover)} alt={reviewingItem.title} width={72} height={72} style={{ objectFit: 'cover', borderRadius: 8 }} fallback="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='72' height='72'%3E%3C/svg%3E" />
          <Space direction="vertical" size={2}>
            <strong>{reviewingItem.title}</strong>
            <Typography.Text type="secondary">{formatYuan(reviewingItem.price)} · {reviewingItem.condition_label} · {reviewingItem.seller.nickname}</Typography.Text>
            <Typography.Text type="secondary">{reviewingItem.location}</Typography.Text>
          </Space>
        </Space>
        <Typography.Paragraph type="secondary" style={{ margin: 0 }} ellipsis={{ rows: 3, expandable: true }}>{reviewingItem.description}</Typography.Paragraph>
      </Space>}
      <Form form={reviewForm} layout="vertical" onFinish={submitItemReview}>
        <Form.Item name="decision" label="审核结论" rules={[{ required: true, message: '请选择审核结论' }]}>
          <Radio.Group options={[{ value: 'approve', label: '通过' }, { value: 'reject', label: '驳回' }]} optionType="button" buttonStyle="solid" />
        </Form.Item>
        {reviewDecision === 'reject' && <Form.Item
          name="reason"
          label="驳回原因"
          rules={[{ required: true, min: 5, message: '请填写至少 5 个字的驳回原因' }]}
          extra={<Space size={[8, 8]} wrap style={{ marginTop: 8 }}>
            <Typography.Text type="secondary" style={{ fontSize: 12 }}>快捷填写：</Typography.Text>
            {ITEM_REJECT_PRESETS.map((preset) => <Tag.CheckableTag
              key={preset}
              checked={reviewForm.getFieldValue('reason') === preset}
              style={{ border: '1px solid #ffd8bf', background: '#fff7e6', padding: '2px 10px', fontSize: 12 }}
              onChange={() => reviewForm.setFieldValue('reason', preset)}
            >{preset.split('，')[0]}</Tag.CheckableTag>)}
          </Space>}
        ><Input.TextArea rows={4} maxLength={500} showCount placeholder="驳回原因会反馈给卖家，请写清不符合规则的具体点；可点击上方标签快捷填入后再调整" /></Form.Item>}
      </Form>
    </Modal>

    <Modal
      title={`审核记录：${viewingItem?.title ?? ''}`}
      open={viewingItem !== null}
      onCancel={() => setViewingItem(null)}
      footer={<Button onClick={() => setViewingItem(null)}>关闭</Button>}
      cancelText="关闭"
      destroyOnHidden
    >
      {viewingItem && <Space direction="vertical" size={12} style={{ width: '100%' }}>
        <Alert
          showIcon
          type={viewingItem.audit_status === 'approved' ? 'success' : 'error'}
          message={viewingItem.audit_status === 'approved' ? '该商品已通过审核' : '该商品已被驳回'}
          description={viewingItem.audit_status === 'rejected' && viewingItem.rejected_reason ? `驳回原因：${viewingItem.rejected_reason}` : undefined}
        />
        <Typography.Text>审核人：{viewingItem.reviewed_by_name ?? '系统'}</Typography.Text>
        <Typography.Text>审核时间：{formatDate(viewingItem.reviewed_at)}</Typography.Text>
        <Typography.Text type="secondary">提交时间：{formatDate(viewingItem.submitted_at || viewingItem.created_at)}</Typography.Text>
      </Space>}
    </Modal>

    <Modal
      title={`仲裁退款：订单 ${arbitratingRefund?.order_id ?? ''}`}
      open={arbitratingRefund !== null}
      onCancel={() => { setArbitratingRefund(null); arbitrateForm.resetFields() }}
      onOk={() => arbitrateForm.submit()}
      okText="提交仲裁"
      confirmLoading={Boolean(arbitratingRefund && busy === `refund-${arbitratingRefund.id}`)}
      cancelText="取消"
      width={640}
      destroyOnHidden
    >
      {arbitratingRefund && <Space direction="vertical" size={12} style={{ width: '100%', marginBottom: 16 }}>
        <Alert showIcon type="info" message={`退款金额 ${formatAmount(arbitratingRefund.amount)}`} description={`买家 ${arbitratingRefund.buyer.nickname} 申请退款，卖家 ${arbitratingRefund.seller.nickname} ${arbitratingRefund.seller_responded_at ? `已于 ${formatDate(arbitratingRefund.seller_responded_at)} 响应` : '暂未响应'}。`} />
        <div>
          <Typography.Text strong>退款原因</Typography.Text>
          <Typography.Paragraph style={{ marginTop: 4, marginBottom: 0 }}>{arbitratingRefund.reason}</Typography.Paragraph>
        </div>
        {arbitratingRefund.images.length > 0 && <div>
          <Typography.Text strong>买家凭证</Typography.Text>
          <div style={{ marginTop: 8 }}><Image.PreviewGroup><Space wrap>{arbitratingRefund.images.map((image, index) => <Image key={image} src={mediaUrl(image)} alt={`退款凭证 ${index + 1}`} width={72} height={72} style={{ objectFit: 'cover', borderRadius: 8 }} />)}</Space></Image.PreviewGroup></div>
        </div>}
        {arbitratingRefund.seller_note && <div>
          <Typography.Text strong>卖家说明</Typography.Text>
          <Typography.Paragraph style={{ marginTop: 4, marginBottom: 0 }}>{arbitratingRefund.seller_note}</Typography.Paragraph>
        </div>}
      </Space>}
      <Form form={arbitrateForm} layout="vertical" onFinish={submitRefundArbitrate}>
        <Form.Item name="decision" label="仲裁结论" rules={[{ required: true, message: '请选择仲裁结论' }]}>
          <Radio.Group options={[{ value: 'approve', label: '同意退款' }, { value: 'reject', label: '拒绝退款' }]} optionType="button" buttonStyle="solid" />
        </Form.Item>
        <Form.Item name="note" label="仲裁说明" rules={[{ required: true, min: 5, message: '请填写至少 5 个字的仲裁说明' }]}><Input.TextArea rows={4} maxLength={500} showCount placeholder="仲裁说明会同步给买卖双方，并记录在审计日志中" /></Form.Item>
      </Form>
    </Modal>
  </>
}
