import {
  EditOutlined,
  EyeOutlined,
  PlusOutlined,
  ReloadOutlined,
} from '@ant-design/icons'
import {
  Alert,
  Button,
  DatePicker,
  Descriptions,
  Drawer,
  Form,
  Input,
  InputNumber,
  Modal,
  Popconfirm,
  Select,
  Space,
  Table,
  Tabs,
  Tag,
  Typography,
  message,
} from 'antd'
import type { ColumnsType } from 'antd/es/table'
import type { MessageInstance } from 'antd/es/message/interface'
import { useCallback, useEffect, useState } from 'react'
import {
  ADMIN_STORE_PRODUCT_STATUS_OPTIONS,
  ADMIN_STORE_SETTLEMENT_STATUS_OPTIONS,
  ADMIN_STORE_STATUS_OPTIONS,
  ApiError,
  confirmAdminStoreSettlement,
  createAdminStoreSettlement,
  getAdminStoreDetail,
  getAdminStoreSettlement,
  listAdminStoreProducts,
  listAdminStoreSettlements,
  listAdminStores,
  listManagedCampuses,
  payAdminStoreSettlement,
  previewAdminStoreSettlement,
  reviewAdminStore,
  updateAdminStoreCampus,
  updateAdminStoreProductStatus,
  updateAdminStoreSettings,
  updateAdminStoreStatus,
  type AdminStore,
  type AdminStoreCampus,
  type AdminStoreDetail,
  type AdminStoreMember,
  type AdminStoreProduct,
  type AdminStoreSettlement,
  type AdminStoreSettlementDetail,
  type AdminStoreSettlementPreview,
} from '../../api/admin'
import { PageHead, PCell, Pill } from '../../components/xq'
import { useAdminAuth } from '../auth/AdminAuthContext'

const money = (cents: number | null | undefined) => (cents === null || cents === undefined ? '—' : `¥${(cents / 100).toFixed(2)}`)
const timeText = (value?: string | null) => (value ? new Date(value).toLocaleString('zh-CN', { hour12: false }) : '—')
const percentText = (bps: number) => `${(bps / 100).toFixed(2)}%`
const storeStatusMeta = (status: string) => ADMIN_STORE_STATUS_OPTIONS.find((item) => item.value === status) ?? { label: status, color: 'default' }
const productStatusMeta = (status: string) => ADMIN_STORE_PRODUCT_STATUS_OPTIONS.find((item) => item.value === status) ?? { label: status, color: 'default' }
const settlementStatusMeta = (status: string) => ADMIN_STORE_SETTLEMENT_STATUS_OPTIONS.find((item) => item.value === status) ?? { label: status, color: 'default' }
const memberRoleText: Record<string, string> = { owner: '店主', manager: '店长', staff: '店员' }

function StoreStatusTag({ status }: { status: string }) {
  const meta = storeStatusMeta(status)
  return <Tag color={meta.color}>{meta.label}</Tag>
}

function ProductStatusTag({ status }: { status: string }) {
  const meta = productStatusMeta(status)
  return <Tag color={meta.color}>{meta.label}</Tag>
}

function SettlementStatusTag({ status }: { status: string }) {
  const meta = settlementStatusMeta(status)
  return <Tag color={meta.color}>{meta.label}</Tag>
}

function priceRange(from: number | null, to: number | null): string {
  if (from === null && to === null) return '—'
  if (from !== null && to !== null && from !== to) return `${money(from)} ~ ${money(to)}`
  return money(from ?? to)
}

function failText(error: unknown, fallback: string): string {
  return error instanceof ApiError ? error.message : fallback
}

export function StoresPage() {
  const { admin } = useAdminAuth()
  const [messageApi, contextHolder] = message.useMessage()
  const permissions = admin?.permissions ?? []
  const canReview = permissions.includes('store.review')
  const canManage = permissions.includes('store.manage')
  const canSettle = permissions.includes('store.settle')
  const isCampusAgent = Boolean(admin?.roles.includes('campus_agent') && !admin.roles.includes('super_admin'))
  const isPlatformAdmin = !isCampusAgent

  return (
    <div className="xq-page">
      {contextHolder}
      <PageHead title="门店管理" extra={<Pill tone="blue">门店审核 · 商品监督 · 结算</Pill>} />
      <Tabs
        defaultActiveKey="stores"
        items={[
          { key: 'stores', label: '门店审核', children: <StoresTab messageApi={messageApi} canReview={canReview} canManage={canManage} isPlatformAdmin={isPlatformAdmin} /> },
          { key: 'products', label: '门店商品监督', children: <ProductsTab messageApi={messageApi} canManage={canManage} /> },
          { key: 'settlements', label: '门店结算', children: <SettlementsTab messageApi={messageApi} canSettle={canSettle} /> },
        ]}
      />
    </div>
  )
}

function StoresTab({ messageApi, canReview, canManage, isPlatformAdmin }: { messageApi: MessageInstance; canReview: boolean; canManage: boolean; isPlatformAdmin: boolean }) {
  const [items, setItems] = useState<AdminStore[]>([])
  const [total, setTotal] = useState(0)
  const [page, setPage] = useState(1)
  const [status, setStatus] = useState<string | undefined>(undefined)
  const [keyword, setKeyword] = useState('')
  const [appliedKeyword, setAppliedKeyword] = useState('')
  const [loading, setLoading] = useState(false)
  const [busyId, setBusyId] = useState<string | null>(null)
  const [detailOpen, setDetailOpen] = useState(false)
  const [detail, setDetail] = useState<AdminStoreDetail | null>(null)
  const [detailLoading, setDetailLoading] = useState(false)
  const [campusOptions, setCampusOptions] = useState<Array<{ value: string; label: string }>>([])
  const [campusOptionsLoading, setCampusOptionsLoading] = useState(false)
  const [selectedCampusId, setSelectedCampusId] = useState<string | undefined>(undefined)
  const [campusBusyId, setCampusBusyId] = useState<string | null>(null)
  const [reviewTarget, setReviewTarget] = useState<AdminStore | null>(null)
  const [reviewAction, setReviewAction] = useState<'approve' | 'reject'>('approve')
  const [reviewRate, setReviewRate] = useState(5)
  const [reviewReason, setReviewReason] = useState('')
  const [reviewBusy, setReviewBusy] = useState(false)
  const [statusTarget, setStatusTarget] = useState<AdminStore | null>(null)
  const [statusAction, setStatusAction] = useState<'suspend' | 'close'>('suspend')
  const [statusReason, setStatusReason] = useState('')
  const [statusBusy, setStatusBusy] = useState(false)
  const [editTarget, setEditTarget] = useState<AdminStore | null>(null)
  const [savingSettings, setSavingSettings] = useState(false)
  const [settingsForm] = Form.useForm<{ commission_rate: number; shipping_fee: number; free_shipping_threshold?: number | null }>()

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const result = await listAdminStores({ status, keyword: appliedKeyword, page, page_size: 20 })
      setItems(result.items)
      setTotal(result.pagination.total)
    } catch (error) {
      messageApi.error(failText(error, '门店列表加载失败。'))
    } finally {
      setLoading(false)
    }
  }, [appliedKeyword, messageApi, page, status])

  useEffect(() => { void load() }, [load])

  useEffect(() => {
    if (!editTarget) return
    settingsForm.setFieldsValue({
      commission_rate: editTarget.commission_rate_bps / 100,
      shipping_fee: editTarget.shipping_fee_amount / 100,
      free_shipping_threshold: editTarget.free_shipping_threshold === null ? null : editTarget.free_shipping_threshold / 100,
    })
  }, [editTarget, settingsForm])

  const loadCampusOptions = async () => {
    if (!isPlatformAdmin || !canManage) return
    setCampusOptionsLoading(true)
    try {
      const result = await listManagedCampuses({ status: 'active', page: 1, page_size: 100 })
      setCampusOptions(result.items.map((campus) => ({ value: campus.id, label: campus.name })))
    } catch (error) {
      messageApi.error(failText(error, '校区候选加载失败。'))
    } finally {
      setCampusOptionsLoading(false)
    }
  }

  const openDetail = async (record: AdminStore) => {
    setDetailOpen(true)
    setDetail(null)
    setSelectedCampusId(undefined)
    setDetailLoading(true)
    void loadCampusOptions()
    try {
      setDetail(await getAdminStoreDetail(record.id))
    } catch (error) {
      messageApi.error(failText(error, '门店详情加载失败。'))
    } finally {
      setDetailLoading(false)
    }
  }

  const updateCampus = async (campusId: string, action: 'enable' | 'disable') => {
    if (!detail) return
    setCampusBusyId(campusId)
    try {
      await updateAdminStoreCampus(detail.id, campusId, action)
      messageApi.success(action === 'enable' ? '校区已启用。' : '校区已停用。')
      setSelectedCampusId(undefined)
      setDetail(await getAdminStoreDetail(detail.id))
    } catch (error) {
      messageApi.error(failText(error, action === 'enable' ? '校区启用失败。' : '校区停用失败。'))
    } finally {
      setCampusBusyId(null)
    }
  }

  const openReview = (record: AdminStore, action: 'approve' | 'reject') => {
    setReviewTarget(record)
    setReviewAction(action)
    setReviewRate(record.commission_rate_bps / 100)
    setReviewReason('')
  }

  const submitReview = async () => {
    if (!reviewTarget) return
    if (reviewAction === 'reject' && !reviewReason.trim()) {
      messageApi.warning('请填写驳回原因。')
      return
    }
    setReviewBusy(true)
    try {
      await reviewAdminStore(reviewTarget.id, reviewAction === 'approve'
        ? { action: 'approve', commission_rate_bps: Math.round(reviewRate * 100) }
        : { action: 'reject', reason: reviewReason.trim() })
      messageApi.success(reviewAction === 'approve' ? '门店已通过审核。' : '门店申请已驳回。')
      setReviewTarget(null)
      void load()
    } catch (error) {
      messageApi.error(failText(error, reviewAction === 'approve' ? '通过审核失败。' : '驳回失败。'))
    } finally {
      setReviewBusy(false)
    }
  }

  const openStatus = (record: AdminStore, action: 'suspend' | 'close') => {
    setStatusTarget(record)
    setStatusAction(action)
    setStatusReason('')
  }

  const submitStatus = async () => {
    if (!statusTarget) return
    if (!statusReason.trim()) {
      messageApi.warning('请填写原因。')
      return
    }
    setStatusBusy(true)
    try {
      await updateAdminStoreStatus(statusTarget.id, { action: statusAction, reason: statusReason.trim() })
      messageApi.success(statusAction === 'suspend' ? '门店已暂停营业。' : '门店已关闭。')
      setStatusTarget(null)
      void load()
    } catch (error) {
      messageApi.error(failText(error, statusAction === 'suspend' ? '暂停失败。' : '关闭失败。'))
    } finally {
      setStatusBusy(false)
    }
  }

  const activate = async (record: AdminStore) => {
    setBusyId(record.id)
    try {
      await updateAdminStoreStatus(record.id, { action: 'activate' })
      messageApi.success('门店已恢复营业。')
      void load()
    } catch (error) {
      messageApi.error(failText(error, '恢复营业失败。'))
    } finally {
      setBusyId(null)
    }
  }

  const openSettings = (record: AdminStore) => {
    setEditTarget(record)
  }

  const submitSettings = async (values: { commission_rate: number; shipping_fee: number; free_shipping_threshold?: number | null }) => {
    if (!editTarget) return
    setSavingSettings(true)
    try {
      await updateAdminStoreSettings(editTarget.id, {
        commission_rate_bps: Math.round(values.commission_rate * 100),
        shipping_fee_amount: Math.round(values.shipping_fee * 100),
        free_shipping_threshold: values.free_shipping_threshold === null || values.free_shipping_threshold === undefined
          ? null
          : Math.round(values.free_shipping_threshold * 100),
      })
      messageApi.success('门店设置已保存。')
      setEditTarget(null)
      void load()
    } catch (error) {
      messageApi.error(failText(error, '保存门店设置失败。'))
    } finally {
      setSavingSettings(false)
    }
  }

  const columns: ColumnsType<AdminStore> = [
    {
      title: '门店', width: 240,
      render: (_, record) => <PCell name={record.name} sub={record.contact_phone ?? '暂无联系电话'} img={record.logo_url} />,
    },
    {
      title: '店主', width: 170,
      render: (_, record) => record.owner
        ? <div>
          <Typography.Text>{record.owner.nickname}</Typography.Text>
          <Typography.Text type="secondary" style={{ display: 'block', fontSize: 12, marginTop: 4 }}>{record.owner.phone ?? '—'}</Typography.Text>
        </div>
        : '—',
    },
    { title: '校区', width: 140, render: (_, record) => record.campus_name ?? '—' },
    {
      title: '状态', width: 140,
      render: (_, record) => <div>
        <StoreStatusTag status={record.status} />
        {record.reject_reason
          ? <Typography.Text type="secondary" style={{ display: 'block', fontSize: 12, marginTop: 4 }} ellipsis={{ tooltip: record.reject_reason }}>{record.reject_reason}</Typography.Text>
          : null}
      </div>,
    },
    { title: '抽成比例', width: 100, render: (_, record) => percentText(record.commission_rate_bps) },
    { title: '在售商品数', width: 110, render: (_, record) => record.product_count },
    { title: '申请时间', width: 180, render: (_, record) => timeText(record.applied_at ?? record.created_at) },
    {
      title: '操作', width: 280, fixed: 'right',
      render: (_, record) => <Space size={0} wrap>
        <Button type="link" size="small" icon={<EyeOutlined />} onClick={() => void openDetail(record)}>详情</Button>
        {record.status === 'pending' ? <>
          <Button type="link" size="small" disabled={!canReview} onClick={() => openReview(record, 'approve')}>通过</Button>
          <Button type="link" size="small" danger disabled={!canReview} onClick={() => openReview(record, 'reject')}>驳回</Button>
        </> : null}
        {record.status === 'active' ? <>
          <Button type="link" size="small" icon={<EditOutlined />} disabled={!canManage} onClick={() => openSettings(record)}>编辑</Button>
          <Button type="link" size="small" disabled={!canManage} onClick={() => openStatus(record, 'suspend')}>暂停</Button>
          <Button type="link" size="small" danger disabled={!canManage} onClick={() => openStatus(record, 'close')}>关闭</Button>
        </> : null}
        {record.status === 'suspended'
          ? <Popconfirm title="确认恢复该门店营业？" onConfirm={() => void activate(record)} disabled={!canManage}>
            <Button type="link" size="small" disabled={!canManage || busyId === record.id} loading={busyId === record.id}>恢复营业</Button>
          </Popconfirm>
          : null}
      </Space>,
    },
  ]

  const memberColumns: ColumnsType<AdminStoreMember> = [
    { title: '成员', width: 160, render: (_, record) => record.nickname ?? '—' },
    { title: '角色', width: 90, render: (_, record) => <Tag>{memberRoleText[record.role] ?? record.role}</Tag> },
    { title: '手机号', width: 130, render: (_, record) => record.phone ?? '—' },
    { title: '状态', width: 90, render: (_, record) => record.status === 'active' ? <Tag color="success">正常</Tag> : <Tag>{record.status}</Tag> },
    { title: '加入时间', width: 170, render: (_, record) => timeText(record.joined_at) },
  ]

  const campusColumns: ColumnsType<AdminStoreCampus> = [
    { title: '校区', width: 150, render: (_, record) => record.campus_name },
    { title: '主校区', width: 80, render: (_, record) => record.is_primary ? <Tag color="blue">主校区</Tag> : '—' },
    { title: '状态', width: 80, render: (_, record) => record.status === 'active' ? <Tag color="success">启用</Tag> : <Tag>停用</Tag> },
    { title: '待结算净额', width: 120, render: (_, record) => money(record.unbilled?.net_amount) },
    ...(isPlatformAdmin ? [{
      title: '操作', width: 90,
      render: (_: unknown, record: AdminStoreCampus) => {
        if (record.is_primary && record.status === 'active') return '—'
        const action = record.status === 'active' ? 'disable' : 'enable'
        return <Popconfirm
          title={action === 'disable' ? `确认停用「${record.campus_name}」？` : `确认启用「${record.campus_name}」？`}
          onConfirm={() => void updateCampus(record.campus_id, action)}
          disabled={!canManage}
        >
          <Button type="link" size="small" danger={action === 'disable'} disabled={!canManage} loading={campusBusyId === record.campus_id}>
            {action === 'disable' ? '停用' : '启用'}
          </Button>
        </Popconfirm>
      },
    }] : []),
  ]

  return (
    <>
      <Space wrap style={{ marginBottom: 12 }}>
        <Select
          allowClear
          placeholder="全部状态"
          style={{ width: 140 }}
          value={status}
          onChange={(value) => { setStatus(value); setPage(1) }}
          options={ADMIN_STORE_STATUS_OPTIONS.map((item) => ({ value: item.value, label: item.label }))}
        />
        <Input.Search
          placeholder="门店名 / 联系电话"
          allowClear
          style={{ width: 240 }}
          value={keyword}
          onChange={(event) => setKeyword(event.target.value)}
          onSearch={(value) => { setAppliedKeyword(value.trim()); setPage(1) }}
        />
        <Button icon={<ReloadOutlined />} onClick={() => void load()}>刷新</Button>
      </Space>
      <Table
        rowKey="id"
        size="small"
        loading={loading}
        columns={columns}
        dataSource={items}
        scroll={{ x: 1400 }}
        pagination={{ current: page, pageSize: 20, total, showSizeChanger: false, showTotal: (value) => `共 ${value} 家门店`, onChange: setPage }}
      />

      <Drawer open={detailOpen} width={640} title={detail?.name ?? '门店详情'} onClose={() => setDetailOpen(false)} loading={detailLoading}>
        {detail ? <Space direction="vertical" size={16} style={{ width: '100%' }}>
          <Descriptions column={1} size="small" bordered items={[
            { key: 'status', label: '状态', children: <Space><StoreStatusTag status={detail.status} />{detail.reject_reason ? <Typography.Text type="secondary">{detail.reject_reason}</Typography.Text> : null}</Space> },
            { key: 'contact', label: '联系电话', children: detail.contact_phone ?? '—' },
            { key: 'address', label: '门店地址', children: detail.address ?? '—' },
            { key: 'hours', label: '营业时间', children: detail.business_hours ?? '—' },
            { key: 'campus', label: '所属校区', children: detail.campus_name ?? '—' },
            { key: 'owner', label: '店主', children: detail.owner ? `${detail.owner.nickname} · ${detail.owner.phone ?? '未绑定手机号'}` : '—' },
            { key: 'commission', label: '抽成比例', children: percentText(detail.commission_rate_bps) },
            { key: 'shipping', label: '门店运费 / 包邮门槛', children: `${money(detail.shipping_fee_amount)} / ${detail.free_shipping_threshold === null ? '未设置' : money(detail.free_shipping_threshold)}` },
            { key: 'products', label: '在售商品数', children: detail.product_count },
            { key: 'applied', label: '申请时间', children: timeText(detail.applied_at) },
            { key: 'reviewed', label: '审核时间', children: timeText(detail.reviewed_at) },
            { key: 'description', label: '门店简介', children: detail.description || '—' },
          ]} />
          <div>
            <Typography.Text strong>开通校区（{detail.campuses.length}）</Typography.Text>
            {isPlatformAdmin ? <Space.Compact style={{ width: '100%', marginTop: 8 }}>
              <Select
                showSearch
                optionFilterProp="label"
                placeholder="选择待开通校区"
                style={{ flex: 1 }}
                loading={campusOptionsLoading}
                disabled={!canManage}
                value={selectedCampusId}
                onChange={setSelectedCampusId}
                options={campusOptions.filter((option) => !detail.campuses.some((campus) => campus.campus_id === option.value))}
              />
              <Popconfirm title="确认为该门店开通所选校区？" onConfirm={() => selectedCampusId && void updateCampus(selectedCampusId, 'enable')} disabled={!canManage || !selectedCampusId}>
                <Button type="primary" disabled={!canManage || !selectedCampusId} loading={campusBusyId !== null && campusBusyId === selectedCampusId}>开通校区</Button>
              </Popconfirm>
            </Space.Compact> : null}
            <Table
              rowKey="campus_id"
              size="small"
              style={{ marginTop: 8 }}
              columns={campusColumns}
              dataSource={detail.campuses}
              pagination={false}
              locale={{ emptyText: '暂无开通校区' }}
            />
          </div>
          <div>
            <Typography.Text strong>主校区未结算汇总</Typography.Text>
            <Descriptions column={2} size="small" bordered style={{ marginTop: 8 }} items={[
              { key: 'count', label: '待结算笔数', children: detail.unbilled.item_count },
              { key: 'gross', label: '商品金额', children: money(detail.unbilled.gross_amount) },
              { key: 'refunded', label: '退款金额', children: money(detail.unbilled.refunded_amount) },
              { key: 'commission', label: '平台抽成', children: money(detail.unbilled.commission_amount) },
              { key: 'net', label: '应结金额', children: <Typography.Text strong>{money(detail.unbilled.net_amount)}</Typography.Text> },
            ]} />
          </div>
          <div>
            <Typography.Text strong>门店成员（{detail.members.length}）</Typography.Text>
            <Table
              rowKey="id"
              size="small"
              style={{ marginTop: 8 }}
              columns={memberColumns}
              dataSource={detail.members}
              pagination={false}
              locale={{ emptyText: '暂无成员' }}
            />
          </div>
        </Space> : null}
      </Drawer>

      <Modal
        open={reviewTarget !== null}
        title={`${reviewAction === 'approve' ? '通过' : '驳回'}门店申请 · ${reviewTarget?.name ?? ''}`}
        okText={reviewAction === 'approve' ? '确认通过' : '确认驳回'}
        okButtonProps={{ danger: reviewAction === 'reject' }}
        confirmLoading={reviewBusy}
        onOk={() => void submitReview()}
        onCancel={() => setReviewTarget(null)}
      >
        {reviewAction === 'approve'
          ? <Space direction="vertical" size={12} style={{ width: '100%' }}>
            <Alert type="info" showIcon message="通过后门店立即进入营业中状态，用户端可浏览下单。" />
            <div>
              <Typography.Text>平台抽成比例</Typography.Text>
              <InputNumber
                min={0}
                max={50}
                step={0.5}
                precision={2}
                addonAfter="%"
                style={{ width: '100%', marginTop: 6 }}
                value={reviewRate}
                onChange={(value) => setReviewRate(value ?? 0)}
              />
            </div>
          </Space>
          : <Input.TextArea rows={3} maxLength={500} placeholder="请填写驳回原因（必填，会展示给店主）" value={reviewReason} onChange={(event) => setReviewReason(event.target.value)} />}
      </Modal>

      <Modal
        open={statusTarget !== null}
        title={`${statusAction === 'suspend' ? '暂停' : '关闭'}门店 · ${statusTarget?.name ?? ''}`}
        okText="提交"
        okButtonProps={{ danger: true }}
        confirmLoading={statusBusy}
        onOk={() => void submitStatus()}
        onCancel={() => setStatusTarget(null)}
      >
        <Alert
          type="warning"
          showIcon
          style={{ marginBottom: 12 }}
          message={statusAction === 'suspend' ? '暂停后门店商品将不可下单，恢复营业后重新开放。' : '关闭后门店将停止营业，请谨慎操作。'}
        />
        <Input.TextArea rows={3} maxLength={500} placeholder="请填写原因（必填）" value={statusReason} onChange={(event) => setStatusReason(event.target.value)} />
      </Modal>

      <Modal
        open={editTarget !== null}
        title={`门店设置 · ${editTarget?.name ?? ''}`}
        okText="保存"
        confirmLoading={savingSettings}
        onOk={() => settingsForm.submit()}
        onCancel={() => setEditTarget(null)}
      >
        <Form form={settingsForm} layout="vertical" onFinish={submitSettings}>
          <Form.Item name="commission_rate" label="平台抽成比例（%）" rules={[{ required: true, message: '请填写抽成比例' }]}>
            <InputNumber min={0} max={50} step={0.5} precision={2} addonAfter="%" style={{ width: '100%' }} />
          </Form.Item>
          <Form.Item name="shipping_fee" label="门店运费（元）" rules={[{ required: true, message: '请填写门店运费' }]} extra="门店订单默认收取的运费，0 表示免运费。">
            <InputNumber min={0} max={1000} step={1} precision={2} addonBefore="¥" style={{ width: '100%' }} />
          </Form.Item>
          <Form.Item name="free_shipping_threshold" label="包邮门槛（元）" extra="留空表示不设置包邮门槛。">
            <InputNumber min={0} max={1000000} step={1} precision={2} addonBefore="¥" placeholder="留空表示不包邮" style={{ width: '100%' }} />
          </Form.Item>
        </Form>
      </Modal>
    </>
  )
}

function ProductsTab({ messageApi, canManage }: { messageApi: MessageInstance; canManage: boolean }) {
  const [items, setItems] = useState<AdminStoreProduct[]>([])
  const [total, setTotal] = useState(0)
  const [page, setPage] = useState(1)
  const [status, setStatus] = useState<string | undefined>(undefined)
  const [storeId, setStoreId] = useState('')
  const [appliedStoreId, setAppliedStoreId] = useState('')
  const [keyword, setKeyword] = useState('')
  const [appliedKeyword, setAppliedKeyword] = useState('')
  const [loading, setLoading] = useState(false)
  const [busyId, setBusyId] = useState<string | null>(null)
  const [offTarget, setOffTarget] = useState<AdminStoreProduct | null>(null)
  const [offReason, setOffReason] = useState('')
  const [offBusy, setOffBusy] = useState(false)

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const result = await listAdminStoreProducts({ store_id: appliedStoreId, status, keyword: appliedKeyword, page, page_size: 20 })
      setItems(result.items)
      setTotal(result.pagination.total)
    } catch (error) {
      messageApi.error(failText(error, '门店商品加载失败。'))
    } finally {
      setLoading(false)
    }
  }, [appliedKeyword, appliedStoreId, messageApi, page, status])

  useEffect(() => { void load() }, [load])

  const submitOffShelf = async () => {
    if (!offTarget) return
    if (!offReason.trim()) {
      messageApi.warning('请填写平台下架原因。')
      return
    }
    setOffBusy(true)
    try {
      await updateAdminStoreProductStatus(offTarget.id, { action: 'off_shelf', reason: offReason.trim() })
      messageApi.success('商品已平台下架。')
      setOffTarget(null)
      setOffReason('')
      void load()
    } catch (error) {
      messageApi.error(failText(error, '平台下架失败。'))
    } finally {
      setOffBusy(false)
    }
  }

  const clearBlock = async (record: AdminStoreProduct) => {
    setBusyId(record.id)
    try {
      await updateAdminStoreProductStatus(record.id, { action: 'clear_block' })
      messageApi.success('已解除平台下架限制。')
      void load()
    } catch (error) {
      messageApi.error(failText(error, '解除下架限制失败。'))
    } finally {
      setBusyId(null)
    }
  }

  const columns: ColumnsType<AdminStoreProduct> = [
    {
      title: '商品', width: 280,
      render: (_, record) => <PCell name={record.name} sub={record.id} img={record.cover_url} />,
    },
    { title: '所属门店', width: 160, render: (_, record) => record.store?.name ?? '—' },
    { title: '价格区间', width: 150, render: (_, record) => priceRange(record.price_from, record.price_to) },
    { title: '规格数', width: 90, render: (_, record) => record.sku_count },
    { title: '状态', width: 100, render: (_, record) => <ProductStatusTag status={record.status} /> },
    {
      title: '平台下架原因', width: 200,
      render: (_, record) => record.admin_off_shelf_reason
        ? <Typography.Text type="secondary" style={{ fontSize: 12 }}>{record.admin_off_shelf_reason}</Typography.Text>
        : '—',
    },
    { title: '创建时间', width: 180, render: (_, record) => timeText(record.created_at) },
    {
      title: '操作', width: 220, fixed: 'right',
      render: (_, record) => <Space size={0} wrap>
        <Button
          type="link"
          size="small"
          danger
          disabled={!canManage || record.status !== 'on_sale'}
          onClick={() => { setOffTarget(record); setOffReason('') }}
        >平台下架</Button>
        {record.admin_off_shelf_reason
          ? <Popconfirm title="解除平台下架限制？解除后门店可自行重新上架。" onConfirm={() => void clearBlock(record)} disabled={!canManage}>
            <Button type="link" size="small" disabled={!canManage || busyId === record.id} loading={busyId === record.id}>解除下架限制</Button>
          </Popconfirm>
          : null}
      </Space>,
    },
  ]

  return (
    <>
      <Space wrap style={{ marginBottom: 12 }}>
        <Input
          placeholder="门店 ID（public_id）"
          allowClear
          style={{ width: 220 }}
          value={storeId}
          onChange={(event) => setStoreId(event.target.value)}
          onPressEnter={() => { setAppliedStoreId(storeId.trim()); setPage(1) }}
        />
        <Select
          allowClear
          placeholder="全部状态"
          style={{ width: 140 }}
          value={status}
          onChange={(value) => { setStatus(value); setPage(1) }}
          options={ADMIN_STORE_PRODUCT_STATUS_OPTIONS.map((item) => ({ value: item.value, label: item.label }))}
        />
        <Input.Search
          placeholder="商品名称"
          allowClear
          style={{ width: 220 }}
          value={keyword}
          onChange={(event) => setKeyword(event.target.value)}
          onSearch={(value) => { setAppliedKeyword(value.trim()); setPage(1) }}
        />
        <Button type="primary" onClick={() => { setAppliedStoreId(storeId.trim()); setAppliedKeyword(keyword.trim()); setPage(1) }}>查询</Button>
        <Button icon={<ReloadOutlined />} onClick={() => void load()}>刷新</Button>
      </Space>
      <Table
        rowKey="id"
        size="small"
        loading={loading}
        columns={columns}
        dataSource={items}
        scroll={{ x: 1380 }}
        pagination={{ current: page, pageSize: 20, total, showSizeChanger: false, showTotal: (value) => `共 ${value} 个商品`, onChange: setPage }}
      />

      <Modal
        open={offTarget !== null}
        title={`平台下架 · ${offTarget?.name ?? ''}`}
        okText="确认下架"
        okButtonProps={{ danger: true }}
        confirmLoading={offBusy}
        onOk={() => void submitOffShelf()}
        onCancel={() => setOffTarget(null)}
      >
        <Alert type="warning" showIcon style={{ marginBottom: 12 }} message="平台下架后商品立即停止售卖，原因会展示给门店，门店需由平台解除限制后才能重新上架。" />
        <Input.TextArea rows={3} maxLength={500} placeholder="请填写平台下架原因（必填）" value={offReason} onChange={(event) => setOffReason(event.target.value)} />
      </Modal>
    </>
  )
}

function SettlementsTab({ messageApi, canSettle }: { messageApi: MessageInstance; canSettle: boolean }) {
  const [items, setItems] = useState<AdminStoreSettlement[]>([])
  const [total, setTotal] = useState(0)
  const [page, setPage] = useState(1)
  const [status, setStatus] = useState<string | undefined>(undefined)
  const [storeId, setStoreId] = useState('')
  const [appliedStoreId, setAppliedStoreId] = useState('')
  const [campusFilter, setCampusFilter] = useState<string | undefined>(undefined)
  const [campusFilterOptions, setCampusFilterOptions] = useState<Array<{ value: string; label: string }>>([])
  const [campusFilterOptionsLoading, setCampusFilterOptionsLoading] = useState(false)
  const [loading, setLoading] = useState(false)
  const [busyId, setBusyId] = useState<string | null>(null)
  const [createOpen, setCreateOpen] = useState(false)
  const [storeOptions, setStoreOptions] = useState<Array<{ value: string; label: string }>>([])
  const [storeOptionsLoading, setStoreOptionsLoading] = useState(false)
  const [createStore, setCreateStore] = useState<string | undefined>(undefined)
  const [createCampus, setCreateCampus] = useState<string | undefined>(undefined)
  const [createStoreCampuses, setCreateStoreCampuses] = useState<AdminStoreCampus[]>([])
  const [createCampusLoading, setCreateCampusLoading] = useState(false)
  const [periodStart, setPeriodStart] = useState<string | null>(null)
  const [periodEnd, setPeriodEnd] = useState<string | null>(null)
  const [rangeKey, setRangeKey] = useState(0)
  const [remark, setRemark] = useState('')
  const [preview, setPreview] = useState<AdminStoreSettlementPreview | null>(null)
  const [previewing, setPreviewing] = useState(false)
  const [creating, setCreating] = useState(false)
  const [detail, setDetail] = useState<AdminStoreSettlementDetail | null>(null)
  const [detailLoading, setDetailLoading] = useState(false)

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const result = await listAdminStoreSettlements({ status, store_id: appliedStoreId, campus_id: campusFilter, page, page_size: 20 })
      setItems(result.items)
      setTotal(result.pagination.total)
    } catch (error) {
      messageApi.error(failText(error, '结算批次加载失败。'))
    } finally {
      setLoading(false)
    }
  }, [appliedStoreId, campusFilter, messageApi, page, status])

  useEffect(() => { void load() }, [load])

  useEffect(() => {
    const loadCampusFilterOptions = async () => {
      setCampusFilterOptionsLoading(true)
      try {
        const result = await listManagedCampuses({ page: 1, page_size: 100 })
        setCampusFilterOptions(result.items.map((campus) => ({ value: campus.id, label: campus.name })))
      } catch (error) {
        messageApi.error(failText(error, '校区筛选项加载失败。'))
      } finally {
        setCampusFilterOptionsLoading(false)
      }
    }
    void loadCampusFilterOptions()
  }, [messageApi])

  const openCreate = async () => {
    setCreateOpen(true)
    setCreateStore(undefined)
    setCreateCampus(undefined)
    setCreateStoreCampuses([])
    setPeriodStart(null)
    setPeriodEnd(null)
    setRangeKey((current) => current + 1)
    setRemark('')
    setPreview(null)
    setStoreOptionsLoading(true)
    try {
      const result = await listAdminStores({ status: 'active', page: 1, page_size: 50 })
      setStoreOptions(result.items.map((store) => ({ value: store.id, label: `${store.name}${store.campus_name ? ` · ${store.campus_name}` : ''}` })))
    } catch (error) {
      messageApi.error(failText(error, '门店候选加载失败。'))
    } finally {
      setStoreOptionsLoading(false)
    }
  }

  const loadCreateStoreCampuses = async (storeId: string) => {
    setCreateCampus(undefined)
    setCreateStoreCampuses([])
    setCreateCampusLoading(true)
    try {
      const storeDetail = await getAdminStoreDetail(storeId)
      setCreateStoreCampuses(storeDetail.campuses)
      const activeCampuses = storeDetail.campuses.filter((campus) => campus.status === 'active')
      setCreateCampus((activeCampuses.find((campus) => campus.is_primary) ?? activeCampuses[0])?.campus_id)
    } catch (error) {
      messageApi.error(failText(error, '门店结算校区加载失败。'))
    } finally {
      setCreateCampusLoading(false)
    }
  }

  const runPreview = async () => {
    if (!createStore || !createCampus || !periodStart || !periodEnd) {
      messageApi.warning('请选择门店、结算校区与结算周期。')
      return
    }
    setPreviewing(true)
    try {
      setPreview(await previewAdminStoreSettlement(createStore, { period_start: periodStart, period_end: periodEnd }, createCampus))
    } catch (error) {
      messageApi.error(failText(error, '结算预览失败。'))
    } finally {
      setPreviewing(false)
    }
  }

  const submitCreate = async () => {
    if (!createStore || !createCampus || !periodStart || !periodEnd || !preview) return
    setCreating(true)
    try {
      const created = await createAdminStoreSettlement(createStore, {
        period_start: periodStart,
        period_end: periodEnd,
        ...(remark.trim() ? { remark: remark.trim() } : {}),
      }, createCampus)
      messageApi.success(`结算批次 ${created.batch_no} 已生成。`)
      setCreateOpen(false)
      void load()
    } catch (error) {
      messageApi.error(failText(error, '生成结算批次失败。'))
    } finally {
      setCreating(false)
    }
  }

  const openDetail = async (record: AdminStoreSettlement) => {
    setDetailLoading(true)
    setDetail({ ...record, items: [] })
    try {
      setDetail(await getAdminStoreSettlement(record.id))
    } catch (error) {
      messageApi.error(failText(error, '结算明细加载失败。'))
    } finally {
      setDetailLoading(false)
    }
  }

  const act = async (record: AdminStoreSettlement, action: 'confirm' | 'pay') => {
    setBusyId(record.id)
    try {
      if (action === 'confirm') await confirmAdminStoreSettlement(record.id)
      else await payAdminStoreSettlement(record.id)
      messageApi.success(action === 'confirm' ? '结算批次已确认。' : '已标记打款完成。')
      void load()
    } catch (error) {
      messageApi.error(failText(error, action === 'confirm' ? '确认失败。' : '标记打款失败。'))
    } finally {
      setBusyId(null)
    }
  }

  const columns: ColumnsType<AdminStoreSettlement> = [
    { title: '批次号', width: 220, render: (_, record) => <Typography.Text strong copyable>{record.batch_no}</Typography.Text> },
    { title: '门店', width: 160, render: (_, record) => record.store?.name ?? '—' },
    { title: '校区', width: 140, render: (_, record) => record.campus_name ?? '—' },
    { title: '结算周期', width: 200, render: (_, record) => `${record.period_start} ~ ${record.period_end}` },
    { title: '商品金额', width: 120, render: (_, record) => money(record.gross_amount) },
    { title: '退款', width: 110, render: (_, record) => money(record.refunded_amount) },
    { title: '平台抽成', width: 120, render: (_, record) => money(record.commission_amount) },
    { title: '应结金额', width: 120, render: (_, record) => <Typography.Text strong>{money(record.net_amount)}</Typography.Text> },
    { title: '笔数', width: 80, render: (_, record) => record.item_count },
    { title: '状态', width: 100, render: (_, record) => <SettlementStatusTag status={record.status} /> },
    {
      title: '确认 / 打款时间', width: 200,
      render: (_, record) => <div>
        <Typography.Text type="secondary" style={{ display: 'block', fontSize: 12 }}>确认 {timeText(record.confirmed_at)}</Typography.Text>
        <Typography.Text type="secondary" style={{ display: 'block', fontSize: 12 }}>打款 {timeText(record.paid_at)}</Typography.Text>
      </div>,
    },
    {
      title: '操作', width: 220, fixed: 'right',
      render: (_, record) => <Space size={0} wrap>
        <Button type="link" size="small" icon={<EyeOutlined />} onClick={() => void openDetail(record)}>查看明细</Button>
        {record.status === 'pending'
          ? <Popconfirm title="确认该结算批次？确认后不可修改金额。" onConfirm={() => void act(record, 'confirm')} disabled={!canSettle}>
            <Button type="link" size="small" disabled={!canSettle || busyId === record.id} loading={busyId === record.id}>确认</Button>
          </Popconfirm>
          : null}
        {record.status === 'confirmed'
          ? <Popconfirm title={`确认已将 ${money(record.net_amount)} 打款给门店？`} onConfirm={() => void act(record, 'pay')} disabled={!canSettle}>
            <Button type="link" size="small" disabled={!canSettle || busyId === record.id} loading={busyId === record.id}>标记打款完成</Button>
          </Popconfirm>
          : null}
      </Space>,
    },
  ]

  const detailItemColumns: ColumnsType<AdminStoreSettlementDetail['items'][number]> = [
    { title: '订单明细 ID', width: 130, render: (_, record) => record.order_item_id },
    { title: '订单 ID', width: 130, render: (_, record) => record.order_id },
    { title: '商品金额', width: 110, render: (_, record) => money(record.gross_amount) },
    { title: '退款', width: 100, render: (_, record) => money(record.refunded_amount) },
    { title: '平台抽成', width: 110, render: (_, record) => money(record.commission_amount) },
    { title: '应结金额', width: 110, render: (_, record) => money(record.net_amount) },
  ]

  return (
    <>
      <Space wrap style={{ marginBottom: 12 }}>
        <Select
          allowClear
          placeholder="全部状态"
          style={{ width: 140 }}
          value={status}
          onChange={(value) => { setStatus(value); setPage(1) }}
          options={ADMIN_STORE_SETTLEMENT_STATUS_OPTIONS.map((item) => ({ value: item.value, label: item.label }))}
        />
        <Select
          allowClear
          showSearch
          optionFilterProp="label"
          placeholder="全部校区"
          style={{ width: 180 }}
          loading={campusFilterOptionsLoading}
          value={campusFilter}
          onChange={(value) => { setCampusFilter(value); setPage(1) }}
          options={campusFilterOptions}
        />
        <Input
          placeholder="门店 ID（public_id）"
          allowClear
          style={{ width: 220 }}
          value={storeId}
          onChange={(event) => setStoreId(event.target.value)}
          onPressEnter={() => { setAppliedStoreId(storeId.trim()); setPage(1) }}
        />
        <Button onClick={() => { setAppliedStoreId(storeId.trim()); setPage(1) }}>查询</Button>
        <Button icon={<ReloadOutlined />} onClick={() => void load()}>刷新</Button>
        <Button type="primary" icon={<PlusOutlined />} disabled={!canSettle} onClick={() => void openCreate()}>新建结算批次</Button>
      </Space>
      <Alert
        type="info"
        showIcon
        style={{ marginBottom: 12 }}
        message="结算批次按门店与校区归集已支付且未结算的订单商品，抽成按门店当前抽成比例计算；确认后才能标记打款完成。"
      />
      <Table
        rowKey="id"
        size="small"
        loading={loading}
        columns={columns}
        dataSource={items}
        scroll={{ x: 1700 }}
        pagination={{ current: page, pageSize: 20, total, showSizeChanger: false, showTotal: (value) => `共 ${value} 个批次`, onChange: setPage }}
      />

      <Modal
        open={createOpen}
        width={620}
        title="新建结算批次"
        okText="生成结算批次"
        okButtonProps={{ disabled: preview === null }}
        confirmLoading={creating}
        onOk={() => void submitCreate()}
        onCancel={() => setCreateOpen(false)}
      >
        <Space direction="vertical" size={12} style={{ width: '100%' }}>
          <Select
            showSearch
            optionFilterProp="label"
            placeholder="选择门店"
            style={{ width: '100%' }}
            loading={storeOptionsLoading}
            value={createStore}
            onChange={(value) => { setCreateStore(value); setPreview(null); void loadCreateStoreCampuses(value) }}
            options={storeOptions}
          />
          <Select
            showSearch
            optionFilterProp="label"
            placeholder="结算校区"
            style={{ width: '100%' }}
            disabled={!createStore}
            loading={createCampusLoading}
            value={createCampus}
            onChange={(value) => { setCreateCampus(value); setPreview(null) }}
            options={createStoreCampuses.filter((campus) => campus.status === 'active').map((campus) => ({
              value: campus.campus_id,
              label: `${campus.campus_name}${campus.is_primary ? '（主校区）' : ''}`,
            }))}
          />
          <DatePicker.RangePicker
            key={rangeKey}
            style={{ width: '100%' }}
            onChange={(_, dateStrings) => {
              setPeriodStart(dateStrings[0] || null)
              setPeriodEnd(dateStrings[1] || null)
              setPreview(null)
            }}
          />
          <Input.TextArea rows={2} maxLength={500} placeholder="备注（选填）" value={remark} onChange={(event) => setRemark(event.target.value)} />
          <Space>
            <Button loading={previewing} disabled={!createStore || !createCampus || !periodStart || !periodEnd} onClick={() => void runPreview()}>预览汇总</Button>
            <Typography.Text type="secondary" style={{ fontSize: 12 }}>先预览所选周期内的应结金额，再确认生成批次。</Typography.Text>
          </Space>
          {preview
            ? <Descriptions column={2} size="small" bordered items={[
              { key: 'period', label: '结算周期', children: `${preview.period_start} ~ ${preview.period_end}` },
              { key: 'campus', label: '结算校区', children: preview.campus_name },
              { key: 'rate', label: '抽成比例', children: percentText(preview.commission_rate_bps) },
              { key: 'count', label: '待结算笔数', children: preview.item_count },
              { key: 'gross', label: '商品金额', children: money(preview.gross_amount) },
              { key: 'refunded', label: '退款金额', children: money(preview.refunded_amount) },
              { key: 'commission', label: '平台抽成', children: money(preview.commission_amount) },
              { key: 'net', label: '应结金额', children: <Typography.Text strong>{money(preview.net_amount)}</Typography.Text> },
            ]} />
            : null}
        </Space>
      </Modal>

      <Modal
        open={detail !== null}
        width={820}
        title={detail ? `结算明细 · ${detail.batch_no}` : '结算明细'}
        footer={<Button type="primary" onClick={() => setDetail(null)}>关闭</Button>}
        onCancel={() => setDetail(null)}
      >
        {detail ? <Space direction="vertical" size={12} style={{ width: '100%' }}>
          <Descriptions column={2} size="small" bordered items={[
            { key: 'store', label: '门店', children: detail.store?.name ?? '—' },
            { key: 'campus', label: '校区', children: detail.campus_name ?? '—' },
            { key: 'status', label: '状态', children: <SettlementStatusTag status={detail.status} /> },
            { key: 'period', label: '结算周期', children: `${detail.period_start} ~ ${detail.period_end}` },
            { key: 'count', label: '结算笔数', children: detail.item_count },
            { key: 'gross', label: '商品金额', children: money(detail.gross_amount) },
            { key: 'refunded', label: '退款金额', children: money(detail.refunded_amount) },
            { key: 'commission', label: '平台抽成', children: money(detail.commission_amount) },
            { key: 'net', label: '应结金额', children: <Typography.Text strong>{money(detail.net_amount)}</Typography.Text> },
            { key: 'remark', label: '备注', children: detail.remark || '—' },
            { key: 'paid', label: '打款时间', children: timeText(detail.paid_at) },
          ]} />
          <Table
            rowKey="id"
            size="small"
            loading={detailLoading}
            columns={detailItemColumns}
            dataSource={detail.items}
            pagination={false}
            locale={{ emptyText: '暂无明细' }}
          />
        </Space> : null}
      </Modal>
    </>
  )
}
