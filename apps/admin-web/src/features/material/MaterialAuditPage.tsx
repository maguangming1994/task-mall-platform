import { useCallback, useEffect, useState } from 'react'
import { Button, Descriptions, Form, Input, Modal, Select, Table, Typography, message } from 'antd'
import type { ColumnsType } from 'antd/es/table'
import {
  listManagedMaterials,
  listMaterialCategories,
  reviewManagedMaterial,
  type ManagedMaterial,
  type MaterialCategoryRow,
} from '../../api/material'
import { Links, PageHead, Pill, type PillTone } from '../../components/xq'

const fmtTime = (v?: string | null) => (v ? new Date(v).toLocaleString('zh-CN', { hour12: false }) : '—')

const statusPills: Record<string, { tone: PillTone; label: string }> = {
  pending: { tone: 'orange', label: '待审核' },
  approved: { tone: 'green', label: '已通过' },
  rejected: { tone: 'red', label: '已驳回' },
  offline: { tone: 'gray', label: '已下架' },
}

export function MaterialAuditPage() {
  const [messageApi, contextHolder] = message.useMessage()
  const [status, setStatus] = useState('pending')
  const [categoryId, setCategoryId] = useState('')
  const [keyword, setKeyword] = useState('')
  const [items, setItems] = useState<ManagedMaterial[]>([])
  const [categories, setCategories] = useState<MaterialCategoryRow[]>([])
  const [total, setTotal] = useState(0)
  const [page, setPage] = useState(1)
  const [loading, setLoading] = useState(false)
  const [detail, setDetail] = useState<ManagedMaterial | null>(null)
  const [rejectTarget, setRejectTarget] = useState<ManagedMaterial | null>(null)
  const [submitting, setSubmitting] = useState(false)
  const [rejectForm] = Form.useForm<{ reason: string }>()

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const result = await listManagedMaterials({
        status,
        category_id: categoryId || undefined,
        keyword: keyword.trim() || undefined,
        page,
        page_size: 10,
      })
      setItems(result.items)
      setTotal(result.pagination.total)
    } catch {
      messageApi.error('资料数据加载失败，请稍后重试。')
    } finally {
      setLoading(false)
    }
  }, [status, categoryId, keyword, page, messageApi])

  useEffect(() => { void load() }, [load])

  useEffect(() => {
    listMaterialCategories().then((res) => setCategories(res.items)).catch(() => undefined)
  }, [])

  const review = async (item: ManagedMaterial, decision: 'approve' | 'offline') => {
    try {
      await reviewManagedMaterial(item.id, { decision })
      messageApi.success(decision === 'approve' ? `已通过「${item.title}」。` : `已下架「${item.title}」。`)
      setDetail(null)
      await load()
    } catch {
      messageApi.error('操作失败，请稍后重试。')
    }
  }

  const submitReject = async (values: { reason: string }) => {
    if (!rejectTarget) return
    setSubmitting(true)
    try {
      await reviewManagedMaterial(rejectTarget.id, { decision: 'reject', reason: values.reason.trim() })
      messageApi.success('已驳回该资料。')
      setRejectTarget(null)
      await load()
    } catch {
      messageApi.error('驳回操作失败，请稍后重试。')
    } finally {
      setSubmitting(false)
    }
  }

  const columns: ColumnsType<ManagedMaterial> = [
    {
      title: '资料信息',
      render: (_, r) => (
        <div>
          <Typography.Text strong>{r.title}</Typography.Text>
          <div style={{ color: '#8c8c8c', fontSize: 12, marginTop: 2 }}>
            {r.source === 'admin' ? '平台上传' : `用户投喂 · ${r.uploader?.nickname ?? r.uploader_name}`}
          </div>
        </div>
      ),
    },
    { title: '分类', width: 120, render: (_, r) => r.category?.name ?? '未分类' },
    { title: '网盘', width: 130, render: (_, r) => `${r.netdisk_label}${r.extract_code ? ` · ${r.extract_code}` : ''}` },
    {
      title: '状态',
      width: 110,
      render: (_, r) => {
        const p = statusPills[r.status] ?? { tone: 'gray' as PillTone, label: r.status }
        return <Pill tone={p.tone}>{p.label}</Pill>
      },
    },
    { title: '浏览/获取', width: 110, render: (_, r) => `${r.view_count} / ${r.fetch_count}` },
    { title: '提交时间', width: 170, render: (_, r) => fmtTime(r.created_at) },
    {
      title: '操作',
      width: 190,
      render: (_, r) => (
        <Links items={[
          { label: '查看', onClick: () => setDetail(r) },
          r.status === 'pending' && { label: '通过', onClick: () => void review(r, 'approve') },
          r.status === 'pending' && { label: '驳回', danger: true, onClick: () => { rejectForm.resetFields(); setRejectTarget(r) } },
          r.status === 'approved' && { label: '下架', danger: true, onClick: () => void review(r, 'offline') },
        ]} />
      ),
    },
  ]

  return (
    <div className="xq-page">
      {contextHolder}
      <PageHead title="资料审核" extra={<Button onClick={() => void load()}>刷新</Button>} />

      <div className="xq-card xq-filter" style={{ marginBottom: 16 }}>
        <Select
          value={status}
          onChange={(v) => { setStatus(v); setPage(1) }}
          style={{ minWidth: 130 }}
          options={[
            { value: 'pending', label: '待审核' },
            { value: 'approved', label: '已通过' },
            { value: 'rejected', label: '已驳回' },
            { value: 'offline', label: '已下架' },
          ]}
        />
        <Select
          value={categoryId || undefined}
          onChange={(v) => { setCategoryId(v ?? ''); setPage(1) }}
          allowClear
          placeholder="全部分类"
          style={{ minWidth: 130 }}
          options={categories.map((c) => ({ value: c.id, label: c.name }))}
        />
        <Input.Search
          placeholder="资料名称 / 投喂人"
          value={keyword}
          onChange={(e) => setKeyword(e.target.value)}
          onSearch={() => setPage(1)}
          style={{ maxWidth: 240 }}
          allowClear
        />
      </div>

      <div className="xq-card xq-table">
        <Table
          rowKey="id"
          size="middle"
          loading={loading}
          columns={columns}
          dataSource={items}
          pagination={{ current: page, pageSize: 10, total, onChange: setPage, showTotal: (t) => `共 ${t} 条` }}
        />
      </div>

      <Modal title="资料详情" open={!!detail} footer={null} onCancel={() => setDetail(null)} width={620}>
        {detail && (
          <Descriptions
            column={1}
            size="small"
            bordered
            items={[
              { key: 'title', label: '资料名称', children: detail.title },
              { key: 'category', label: '分类', children: detail.category?.name ?? '未分类' },
              { key: 'type', label: '网盘类型', children: detail.netdisk_label },
              { key: 'url', label: '分享链接', children: <Typography.Text copyable>{detail.share_url}</Typography.Text> },
              { key: 'code', label: '提取码', children: detail.extract_code ? <Typography.Text copyable>{detail.extract_code}</Typography.Text> : '无' },
              { key: 'uploader', label: '投喂人', children: detail.source === 'admin' ? '平台上传' : `${detail.uploader?.nickname ?? detail.uploader_name}（ID:${detail.uploader?.id ?? '—'}）` },
              { key: 'desc', label: '资料说明', children: detail.description || '—' },
              { key: 'status', label: '审核状态', children: statusPills[detail.status]?.label ?? detail.status },
              { key: 'bonus', label: '额度奖励', children: detail.bonus_granted ? '已赠送 10 次长期额度' : '未赠送' },
              ...(detail.reject_reason ? [{ key: 'reason', label: '驳回原因', children: detail.reject_reason }] : []),
              { key: 'count', label: '浏览 / 获取', children: `${detail.view_count} / ${detail.fetch_count}` },
              { key: 'created', label: '提交时间', children: fmtTime(detail.created_at) },
              { key: 'reviewed', label: '审核时间', children: fmtTime(detail.reviewed_at) },
            ]}
          />
        )}
      </Modal>

      <Modal
        title="驳回资料"
        open={!!rejectTarget}
        onCancel={() => setRejectTarget(null)}
        onOk={() => rejectForm.submit()}
        confirmLoading={submitting}
        okText="确认驳回"
        cancelText="取消"
      >
        <p style={{ color: '#6b7280', fontSize: 13 }}>资料：{rejectTarget?.title}</p>
        <Form form={rejectForm} layout="vertical" onFinish={submitReject}>
          <Form.Item name="reason" label="驳回原因" rules={[{ required: true, message: '请填写驳回原因' }]}>
            <Input.TextArea rows={3} placeholder="将展示给投喂用户" />
          </Form.Item>
        </Form>
      </Modal>
    </div>
  )
}
