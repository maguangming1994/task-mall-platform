import { useCallback, useEffect, useMemo, useState } from 'react'
import { Button, Descriptions, Form, Image, Input, Modal, Select, Table, message } from 'antd'
import type { ColumnsType } from 'antd/es/table'
import {
  listSecondHandItems,
  reviewSecondHandItem,
  type ManagedSecondHandItem,
} from '../../api/admin'
import { Links, PageHead, Pill, PCell, UCell, type PillTone } from '../../components/xq'

const yuan = (cents?: number | null) => `¥${((cents ?? 0) / 100).toFixed(2)}`
const fmtTime = (v?: string | null) => (v ? new Date(v).toLocaleString('zh-CN', { hour12: false }) : '—')

const auditPills: Record<string, { tone: PillTone; label: string }> = {
  pending: { tone: 'orange', label: '待审核' },
  approved: { tone: 'green', label: '已通过' },
  rejected: { tone: 'red', label: '已驳回' },
}

export function SecondHandAuditPage() {
  const [messageApi, contextHolder] = message.useMessage()
  const [status, setStatus] = useState('pending')
  const [category, setCategory] = useState('')
  const [keyword, setKeyword] = useState('')
  const [applied, setApplied] = useState({ category: '', keyword: '' })
  const [items, setItems] = useState<ManagedSecondHandItem[]>([])
  const [total, setTotal] = useState(0)
  const [page, setPage] = useState(1)
  const [loading, setLoading] = useState(false)
  const [selectedKeys, setSelectedKeys] = useState<string[]>([])
  const [detail, setDetail] = useState<ManagedSecondHandItem | null>(null)
  const [rejectTarget, setRejectTarget] = useState<ManagedSecondHandItem | null>(null)
  const [submitting, setSubmitting] = useState(false)
  const [rejectForm] = Form.useForm<{ reason: string }>()

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const result = await listSecondHandItems({ audit_status: status, page, page_size: 10 })
      setItems(result.items)
      setTotal(result.pagination.total)
    } catch {
      messageApi.error('闲置物品数据加载失败，请稍后重试。')
    } finally {
      setLoading(false)
    }
  }, [status, page, messageApi])

  useEffect(() => { void load() }, [load])

  const categoryOptions = useMemo(() => {
    const names = Array.from(new Set(items.map((i) => i.category?.name).filter((n): n is string => Boolean(n))))
    return names.map((n) => ({ value: n, label: n }))
  }, [items])

  const rows = useMemo(() => items.filter((i) => {
    if (applied.category && i.category?.name !== applied.category) return false
    if (applied.keyword.trim()) {
      const k = applied.keyword.trim()
      if (!`${i.title} ${i.seller.nickname}`.includes(k)) return false
    }
    return true
  }), [items, applied])

  const approveOne = async (item: ManagedSecondHandItem) => {
    try {
      await reviewSecondHandItem(item.id, { decision: 'approve' })
      messageApi.success(`已通过「${item.title}」的审核。`)
      setSelectedKeys((keys) => keys.filter((k) => k !== item.id))
      await load()
    } catch {
      messageApi.error('审核操作失败，请稍后重试。')
    }
  }

  const batchApprove = () => {
    const targets = items.filter((i) => selectedKeys.includes(i.id) && i.audit_status === 'pending')
    if (!targets.length) { messageApi.info('请先勾选待审核的商品。'); return }
    Modal.confirm({
      title: `批量通过 ${targets.length} 件商品？`,
      content: '确认后这些商品将直接上架展示。',
      okText: '批量通过',
      cancelText: '取消',
      onOk: async () => {
        try {
          await Promise.all(targets.map((i) => reviewSecondHandItem(i.id, { decision: 'approve' })))
          messageApi.success(`已批量通过 ${targets.length} 件商品。`)
          setSelectedKeys([])
          await load()
        } catch {
          messageApi.error('批量通过部分失败，请刷新后确认。')
          await load()
        }
      },
    })
  }

  const submitReject = async (values: { reason: string }) => {
    if (!rejectTarget) return
    setSubmitting(true)
    try {
      await reviewSecondHandItem(rejectTarget.id, { decision: 'reject', reason: values.reason.trim() })
      messageApi.success('已驳回该商品。')
      setRejectTarget(null)
      await load()
    } catch {
      messageApi.error('驳回操作失败，请稍后重试。')
    } finally {
      setSubmitting(false)
    }
  }

  const columns: ColumnsType<ManagedSecondHandItem> = [
    { title: '商品信息', render: (_, r) => <PCell name={r.title} sub={`分类：${r.category?.name ?? '未分类'}`} img={r.cover} /> },
    { title: '发布人', render: (_, r) => <UCell name={r.seller.nickname} sub={`ID:${r.seller.id}`} /> },
    { title: '价格', render: (_, r) => yuan(r.price) },
    { title: '状态', render: (_, r) => { const p = auditPills[r.audit_status] ?? { tone: 'gray' as PillTone, label: r.audit_status }; return <Pill tone={p.tone}>{p.label}</Pill> } },
    { title: '提交时间', render: (_, r) => fmtTime(r.submitted_at ?? r.created_at) },
    {
      title: '操作',
      render: (_, r) => (
        <Links items={[
          { label: '查看', onClick: () => setDetail(r) },
          r.audit_status === 'pending' && { label: '通过', onClick: () => void approveOne(r) },
          r.audit_status === 'pending' && { label: '驳回', danger: true, onClick: () => { rejectForm.resetFields(); setRejectTarget(r) } },
        ]} />
      ),
    },
  ]

  return (
    <div className="xq-page">
      {contextHolder}
      <PageHead
        title="闲置物品审核"
        extra={<Button type="primary" onClick={batchApprove} disabled={!selectedKeys.length}>+ 批量通过</Button>}
      />

      <div className="xq-card xq-filter" style={{ marginBottom: 16 }}>
        <Select
          value={status}
          onChange={(v) => { setStatus(v); setPage(1); setSelectedKeys([]) }}
          style={{ minWidth: 120 }}
          options={[
            { value: 'pending', label: '待审核' },
            { value: 'approved', label: '已通过' },
            { value: 'rejected', label: '已驳回' },
          ]}
        />
        <Select
          value={category || undefined}
          onChange={(v) => { const next = v ?? ''; setCategory(next); setPage(1); setApplied((prev) => ({ ...prev, category: next })) }}
          allowClear
          placeholder="全部分类"
          style={{ minWidth: 120 }}
          options={categoryOptions}
        />
        <Input placeholder="商品标题/发布人" value={keyword} onChange={(e) => setKeyword(e.target.value)} onPressEnter={() => setApplied({ category, keyword })} style={{ maxWidth: 220 }} />
        <Button type="primary" onClick={() => setApplied({ category, keyword })}>查询</Button>
        <Button onClick={() => { setCategory(''); setKeyword(''); setApplied({ category: '', keyword: '' }); setStatus('pending'); setPage(1) }}>重置</Button>
      </div>

      <div className="xq-card xq-table">
        <Table
          rowKey="id"
          size="middle"
          loading={loading}
          columns={columns}
          dataSource={rows}
          rowSelection={status === 'pending' ? { selectedRowKeys: selectedKeys, onChange: (keys) => setSelectedKeys(keys as string[]) } : undefined}
          pagination={{ current: page, pageSize: 10, total, onChange: setPage, showTotal: (t) => `共 ${t} 条` }}
        />
      </div>

      <Modal title="商品详情" open={!!detail} footer={null} onCancel={() => setDetail(null)} width={560}>
        {detail && (
          <>
            {detail.cover && <Image src={detail.cover} width={120} style={{ borderRadius: 8, marginBottom: 14 }} />}
            <Descriptions
              column={1}
              size="small"
              bordered
              items={[
                { key: 'title', label: '商品标题', children: detail.title },
                { key: 'price', label: '价格', children: yuan(detail.price) },
                { key: 'category', label: '分类', children: detail.category?.name ?? '未分类' },
                { key: 'condition', label: '成色', children: detail.condition_label },
                { key: 'trade', label: '交易地点', children: detail.location || '—' },
                { key: 'seller', label: '发布人', children: `${detail.seller.nickname}（ID:${detail.seller.id}）` },
                { key: 'desc', label: '商品描述', children: detail.description || '—' },
                { key: 'status', label: '审核状态', children: auditPills[detail.audit_status]?.label ?? detail.audit_status },
                ...(detail.rejected_reason ? [{ key: 'reason', label: '驳回原因', children: detail.rejected_reason }] : []),
                { key: 'created', label: '提交时间', children: fmtTime(detail.submitted_at ?? detail.created_at) },
              ]}
            />
          </>
        )}
      </Modal>

      <Modal
        title="驳回商品"
        open={!!rejectTarget}
        onCancel={() => setRejectTarget(null)}
        onOk={() => rejectForm.submit()}
        confirmLoading={submitting}
        okText="确认驳回"
        cancelText="取消"
      >
        <p style={{ color: '#6b7280', fontSize: 13 }}>商品：{rejectTarget?.title}</p>
        <Form form={rejectForm} layout="vertical" onFinish={submitReject}>
          <Form.Item name="reason" label="驳回原因" rules={[{ required: true, message: '请填写驳回原因' }]}>
            <Input.TextArea rows={3} placeholder="将展示给发布人" />
          </Form.Item>
        </Form>
      </Modal>
    </div>
  )
}
