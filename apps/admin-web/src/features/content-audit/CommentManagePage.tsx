import { CheckOutlined, ReloadOutlined, SearchOutlined, StopOutlined } from '@ant-design/icons'
import { Alert, Button, Descriptions, Form, Input, Modal, Select, Space, Table, Tag, Typography, message } from 'antd'
import type { ColumnsType } from 'antd/es/table'
import { useCallback, useEffect, useState } from 'react'
import {
  batchReviewCommunityComments,
  listCommunityComments,
  moderateCommunityContent,
  reviewCommunityComment,
  type CommunityAuditStats,
  type CommunityAuditStatus,
  type CommunityBatchReviewResult,
  type CommunityModerationReasonCode,
  type CommunityReviewDecision,
  type ManagedCommunityComment,
} from '../../api/admin'
import { PageHead, StatCard } from '../../components/xq'
import {
  auditStatusMeta,
  auditStatusOptions,
  emptyAuditStats,
  errorText,
  formatTime,
  isReviewConflict,
  moderationReasonLabels,
  moderationReasonOptions,
} from './shared'

interface CommentFilters {
  keyword: string
  audit_status?: CommunityAuditStatus
}

interface ReviewFormValues {
  note: string
  reason_code?: CommunityModerationReasonCode
}

interface ReviewTarget {
  ids: string[]
  decision: CommunityReviewDecision
  subject: string
  batch: boolean
}

const emptyFilters: CommentFilters = { keyword: '' }

export function CommentManagePage() {
  const [messageApi, contextHolder] = message.useMessage()
  const [items, setItems] = useState<ManagedCommunityComment[]>([])
  const [stats, setStats] = useState<CommunityAuditStats>(emptyAuditStats)
  const [filters, setFilters] = useState<CommentFilters>(emptyFilters)
  const [appliedFilters, setAppliedFilters] = useState<CommentFilters>(emptyFilters)
  const [selectedRowKeys, setSelectedRowKeys] = useState<string[]>([])
  const [detail, setDetail] = useState<ManagedCommunityComment | null>(null)
  const [page, setPage] = useState(1)
  const [pageSize, setPageSize] = useState(20)
  const [total, setTotal] = useState(0)
  const [loading, setLoading] = useState(false)
  const [loadError, setLoadError] = useState('')
  const [submitting, setSubmitting] = useState(false)
  const [reviewTarget, setReviewTarget] = useState<ReviewTarget | null>(null)
  // 弹窗关闭动画期间 reviewTarget 已置空，保留最近一次决策，避免标题/按钮闪成“驳回”。
  const [reviewDecision, setReviewDecision] = useState<CommunityReviewDecision>('approve')
  const [deleteTarget, setDeleteTarget] = useState<ManagedCommunityComment | null>(null)
  const [reviewForm] = Form.useForm<ReviewFormValues>()
  const [deleteForm] = Form.useForm<{ reason_code: CommunityModerationReasonCode; note: string }>()

  const load = useCallback(async () => {
    setSelectedRowKeys([])
    setLoading(true)
    setLoadError('')
    try {
      const result = await listCommunityComments({
        page,
        page_size: pageSize,
        keyword: appliedFilters.keyword.trim() || undefined,
        audit_status: appliedFilters.audit_status,
      })
      setItems(result.items)
      setStats(result.stats)
      setTotal(result.pagination.total)
      if (page > result.pagination.last_page && result.pagination.last_page > 0) setPage(result.pagination.last_page)
    } catch (error) {
      const text = errorText(error, '评论列表加载失败，请稍后重试。')
      setLoadError(text)
      messageApi.error(text)
    } finally {
      setLoading(false)
    }
  }, [appliedFilters, messageApi, page, pageSize])

  useEffect(() => {
    setSelectedRowKeys([])
    void load()
  }, [load])

  const applyFilters = () => {
    setAppliedFilters({ ...filters, keyword: filters.keyword.trim() })
    setPage(1)
  }

  const resetFilters = () => {
    setFilters({ ...emptyFilters })
    setAppliedFilters({ ...emptyFilters })
    setPage(1)
  }

  const openReview = (ids: string[], decision: CommunityReviewDecision, subject: string, batch = false) => {
    reviewForm.resetFields()
    reviewForm.setFieldsValue({ note: decision === 'approve' ? '人工审核通过，评论符合社区规范。' : '评论不符合社区规范，请修改后重新提交。' })
    setReviewTarget({ ids: [...ids], decision, subject, batch })
    setReviewDecision(decision)
  }

  const showBatchResult = (results: CommunityBatchReviewResult[]) => {
    const failed = results.filter((item) => !item.success)
    const succeeded = results.length - failed.length
    if (!failed.length) {
      messageApi.success(`批量审核完成：成功 ${succeeded} 条，失败 0 条。`)
      return
    }
    Modal.warning({
      title: '批量审核未全部成功',
      width: 600,
      content: (
        <div>
          <Typography.Paragraph>成功 {succeeded} 条，失败 {failed.length} 条。已成功项不会回滚。</Typography.Paragraph>
          <ul style={{ marginBottom: 0, paddingInlineStart: 20 }}>
            {failed.map((item) => <li key={item.id}><Typography.Text copyable={{ text: item.id }}>{item.id}</Typography.Text>：{item.code === 'REVIEW_ALREADY_FINISHED' ? '已被其他管理员处理' : item.message || item.code}</li>)}
          </ul>
        </div>
      ),
    })
  }

  const submitReview = async (values: ReviewFormValues) => {
    if (!reviewTarget) return
    setSubmitting(true)
    try {
      if (!reviewTarget.batch) {
        await reviewCommunityComment(reviewTarget.ids[0], reviewTarget.decision, values.note.trim(), values.reason_code)
        messageApi.success(reviewTarget.decision === 'approve' ? '评论已审核通过。' : '评论已驳回。')
      } else {
        const result = await batchReviewCommunityComments({
          ids: reviewTarget.ids,
          decision: reviewTarget.decision,
          note: values.note.trim(),
          reasonCode: values.reason_code,
        })
        showBatchResult(result.items)
      }
      setReviewTarget(null)
      reviewForm.resetFields()
      await load()
    } catch (error) {
      if (isReviewConflict(error)) {
        messageApi.warning('已被其他管理员处理，列表已刷新。')
        setReviewTarget(null)
        await load()
      } else {
        messageApi.error(errorText(error, '审核操作失败，请稍后重试。'))
      }
    } finally {
      setSubmitting(false)
    }
  }

  const openDelete = (item: ManagedCommunityComment) => {
    deleteForm.resetFields()
    setDeleteTarget(item)
  }

  const submitDelete = async (values: { reason_code: CommunityModerationReasonCode; note: string }) => {
    if (!deleteTarget) return
    setSubmitting(true)
    try {
      await moderateCommunityContent('comment', deleteTarget.id, 'delete', [], values.reason_code, values.note.trim())
      messageApi.success('评论已通过内容处置流程删除，统计数据已刷新。')
      if (detail?.id === deleteTarget.id) setDetail(null)
      setDeleteTarget(null)
      deleteForm.resetFields()
      await load()
    } catch (error) {
      messageApi.error(errorText(error, '删除评论失败，请稍后重试。'))
    } finally {
      setSubmitting(false)
    }
  }

  const columns: ColumnsType<ManagedCommunityComment> = [
    { title: '评论 ID', dataIndex: 'id', width: 190, render: (value: string) => <Typography.Text copyable={{ text: value }}>{value}</Typography.Text> },
    {
      title: '评论内容',
      dataIndex: 'content',
      width: 280,
      render: (value: string) => <Typography.Paragraph style={{ marginBottom: 0 }} ellipsis={{ rows: 2, tooltip: value }}>{value}</Typography.Paragraph>,
    },
    {
      title: '作者',
      width: 145,
      render: (_, item) => <div><div>{item.author.nickname}</div><Typography.Text type="secondary" style={{ fontSize: 12 }}>{item.author.campus_name || '未设置校区'}</Typography.Text></div>,
    },
    {
      title: '所属帖子',
      width: 230,
      render: (_, item) => <div>
        <Typography.Text ellipsis={{ tooltip: item.post_title }} style={{ display: 'block' }}>{item.post_title || '帖子已不可用'}</Typography.Text>
        <Typography.Text type="secondary" style={{ fontSize: 12 }}>{item.post_id}</Typography.Text>
      </div>,
    },
    {
      title: '审核状态',
      dataIndex: 'audit_status',
      width: 115,
      render: (status: CommunityAuditStatus) => <Tag color={auditStatusMeta[status].color}>{auditStatusMeta[status].label}</Tag>,
    },
    {
      title: '审核说明',
      width: 180,
      render: (_, item) => item.review_note ? <Typography.Text ellipsis={{ tooltip: item.review_note }}>{item.review_note}</Typography.Text> : <Typography.Text type="secondary">暂无</Typography.Text>,
    },
    { title: '发布时间', dataIndex: 'published_at', width: 175, render: formatTime },
    {
      title: '操作',
      fixed: 'right',
      width: 220,
      render: (_, item) => <Space size={0} wrap>
        <Button type="link" size="small" onClick={() => setDetail(item)}>查看</Button>
        {item.audit_status === 'pending_review' && <>
          <Button type="link" size="small" onClick={() => openReview([item.id], 'approve', item.content)}>通过</Button>
          <Button type="link" size="small" danger onClick={() => openReview([item.id], 'reject', item.content)}>驳回</Button>
        </>}
        {item.audit_status !== 'deleted' && <Button type="link" size="small" danger onClick={() => openDelete(item)}>删除</Button>}
      </Space>,
    },
  ]

  return (
    <div className="xq-page">
      {contextHolder}
      <PageHead title="评论管理" extra={<Button icon={<ReloadOutlined />} loading={loading} onClick={() => void load()}>刷新</Button>} />

      {loadError && <Alert type="error" showIcon message={loadError} action={<Button size="small" onClick={() => void load()}>重试</Button>} style={{ marginBottom: 16 }} />}

      <div className="xq-card" style={{ marginBottom: 16 }}>
        <Form layout="inline" onFinish={applyFilters} style={{ rowGap: 12 }}>
          <Form.Item label="审核状态" style={{ marginInlineEnd: 12 }}>
            <Select value={filters.audit_status} onChange={(value?: CommunityAuditStatus) => setFilters((current) => ({ ...current, audit_status: value }))} options={auditStatusOptions} placeholder="全部状态" allowClear style={{ width: 160 }} />
          </Form.Item>
          <Form.Item label="关键词" style={{ marginInlineEnd: 12 }}>
            <Input value={filters.keyword} onChange={(event) => setFilters((current) => ({ ...current, keyword: event.target.value }))} placeholder="评论内容 / 作者 / 所属帖子" allowClear style={{ width: 280 }} />
          </Form.Item>
          <Form.Item>
            <Space>
              <Button type="primary" htmlType="submit" icon={<SearchOutlined />}>查询</Button>
              <Button icon={<ReloadOutlined />} onClick={resetFilters}>重置</Button>
            </Space>
          </Form.Item>
        </Form>
      </div>

      <div className="xq-stats" style={{ gridTemplateColumns: 'repeat(auto-fit, minmax(135px, 1fr))', marginBottom: 16 }}>
        <StatCard title="总评论" value={stats.total} delta="当前校区全部评论" />
        <StatCard title="待审核" value={stats.pending_review} delta="等待人工处理" valueColor="var(--xq-warning)" />
        <StatCard title="已发布" value={stats.published} delta="正常公开展示" valueColor="var(--xq-green-deep)" />
        <StatCard title="已驳回" value={stats.rejected} delta="审核未通过" valueColor="var(--xq-danger)" />
        <StatCard title="已隐藏" value={stats.hidden} delta="社区处置隐藏" />
        <StatCard title="已删除" value={stats.deleted} delta="保留处置与申诉记录" />
      </div>

      <div className="xq-card xq-table">
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: 12, flexWrap: 'wrap', marginBottom: 16 }}>
          <Space wrap>
            <Button type="primary" icon={<CheckOutlined />} disabled={!selectedRowKeys.length} onClick={() => openReview(selectedRowKeys, 'approve', `${selectedRowKeys.length} 条评论`, true)}>批量通过</Button>
            <Button danger icon={<StopOutlined />} disabled={!selectedRowKeys.length} onClick={() => openReview(selectedRowKeys, 'reject', `${selectedRowKeys.length} 条评论`, true)}>批量驳回</Button>
          </Space>
          <Typography.Text type="secondary">仅可选择当前页待审核评论 · 已选择 {selectedRowKeys.length} 项</Typography.Text>
        </div>
        <Table
          rowKey="id"
          size="middle"
          loading={loading}
          columns={columns}
          dataSource={items}
          rowSelection={{
            selectedRowKeys,
            onChange: (keys) => setSelectedRowKeys(keys.map(String)),
            getCheckboxProps: (item) => ({ disabled: item.audit_status !== 'pending_review' }),
          }}
          locale={{ emptyText: '没有符合当前条件的评论' }}
          scroll={{ x: 1560 }}
          pagination={{
            current: page,
            pageSize,
            total,
            showSizeChanger: true,
            pageSizeOptions: [10, 20, 50],
            showTotal: (value) => `共 ${value} 条`,
            onChange: (nextPage, nextPageSize) => {
              setSelectedRowKeys([])
              if (nextPageSize !== pageSize) {
                setPageSize(nextPageSize)
                setPage(1)
              } else {
                setPage(nextPage)
              }
            },
          }}
        />
      </div>

      <Modal title="评论详情" open={!!detail} footer={<Button type="primary" onClick={() => setDetail(null)}>关闭</Button>} onCancel={() => setDetail(null)} width={720}>
        {detail && <Descriptions bordered size="small" column={2} items={[
          { key: 'id', label: '评论 ID', children: detail.id, span: 2 },
          { key: 'content', label: '评论内容', children: <Typography.Paragraph style={{ whiteSpace: 'pre-wrap', marginBottom: 0 }}>{detail.content}</Typography.Paragraph>, span: 2 },
          { key: 'author', label: '作者', children: `${detail.author.nickname}（${detail.author.id}）` },
          { key: 'campus', label: '校区', children: detail.author.campus_name || '未设置' },
          { key: 'post', label: '所属帖子', children: detail.post_title || '帖子已不可用', span: 2 },
          { key: 'post-id', label: '帖子 ID', children: detail.post_id, span: 2 },
          { key: 'parent', label: '上级评论 ID', children: detail.parent_comment_id || '无', span: 2 },
          { key: 'status', label: '管理状态', children: <Tag color={auditStatusMeta[detail.audit_status].color}>{auditStatusMeta[detail.audit_status].label}</Tag> },
          { key: 'raw-status', label: '真实状态', children: detail.status },
          { key: 'reward', label: '奖励状态', children: detail.reward_granted ? '已发放' : '未发放或已撤销' },
          { key: 'reviewed', label: '审核时间', children: formatTime(detail.reviewed_at) },
          { key: 'reason', label: '审核原因', children: detail.review_reason_code ? moderationReasonLabels[detail.review_reason_code] : '暂无', span: 2 },
          { key: 'note', label: '审核备注', children: detail.review_note || '暂无', span: 2 },
          { key: 'published', label: '发布时间', children: formatTime(detail.published_at) },
          { key: 'created', label: '创建时间', children: formatTime(detail.created_at) },
        ]} />}
      </Modal>

      <Modal
        title={reviewDecision === 'approve' ? '通过评论审核' : '驳回评论'}
        open={!!reviewTarget}
        onOk={() => reviewForm.submit()}
        onCancel={() => { if (!submitting) setReviewTarget(null) }}
        confirmLoading={submitting}
        okText={reviewDecision === 'approve' ? '确认通过' : '确认驳回'}
        cancelText="取消"
        okButtonProps={{ danger: reviewDecision === 'reject' }}
        forceRender
      >
        {reviewTarget && <Form form={reviewForm} layout="vertical" onFinish={(values) => void submitReview(values)}>
          <Alert type={reviewTarget.decision === 'approve' ? 'info' : 'warning'} showIcon message={`审核对象：${reviewTarget.subject}`} description={reviewTarget.batch ? '批量审核逐条执行，单条失败不会撤销其他成功项。' : `评论 ID：${reviewTarget.ids[0]}`} style={{ marginBottom: 16 }} />
          {reviewTarget.decision === 'reject' && <Form.Item name="reason_code" label="驳回原因" rules={[{ required: true, message: '请选择合法的驳回原因' }]}>
            <Select options={moderationReasonOptions} placeholder="请选择驳回原因" />
          </Form.Item>}
          <Form.Item name="note" label="审核备注" rules={[{ required: true, whitespace: true, message: '请填写审核备注' }, { max: 500, message: '审核备注不能超过 500 字' }]}>
            <Input.TextArea rows={4} maxLength={500} showCount placeholder="说明审核结论或需修改的内容" />
          </Form.Item>
        </Form>}
      </Modal>

      <Modal
        title="删除评论"
        open={!!deleteTarget}
        onOk={() => deleteForm.submit()}
        onCancel={() => { if (!submitting) setDeleteTarget(null) }}
        confirmLoading={submitting}
        okText="确认删除"
        cancelText="取消"
        okButtonProps={{ danger: true }}
        forceRender
      >
        <Alert type="warning" showIcon message="删除将通过现有社区内容处置流程执行" description="内容不会物理移除，处置记录与后续申诉能力会保留。" style={{ marginBottom: 16 }} />
        <Form form={deleteForm} layout="vertical" onFinish={(values) => void submitDelete(values)}>
          <Form.Item name="reason_code" label="删除原因" rules={[{ required: true, message: '请选择删除原因' }]}>
            <Select options={moderationReasonOptions} placeholder="请选择删除原因" />
          </Form.Item>
          <Form.Item name="note" label="处置备注" rules={[{ required: true, whitespace: true, message: '请填写处置备注' }, { max: 500, message: '处置备注不能超过 500 字' }]}>
            <Input.TextArea rows={4} maxLength={500} showCount placeholder="请说明删除依据" />
          </Form.Item>
        </Form>
      </Modal>
    </div>
  )
}
