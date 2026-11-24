import { CheckOutlined, ReloadOutlined, SearchOutlined, StopOutlined } from '@ant-design/icons'
import { Alert, Button, Descriptions, Form, Input, Modal, Select, Space, Table, Tag, Tooltip, Typography, message } from 'antd'
import type { ColumnsType } from 'antd/es/table'
import { useCallback, useEffect, useState } from 'react'
import {
  batchReviewCommunityPosts,
  forwardCommunityPost,
  listCommunityPosts,
  moderateCommunityContent,
  reviewCommunityPost,
  type CommunityAuditStats,
  type CommunityAuditStatus,
  type CommunityBatchReviewResult,
  type CommunityModerationReasonCode,
  type CommunityPostCategory,
  type CommunityPostForwardGroup,
  type CommunityReviewDecision,
  type ManagedCommunityPost,
} from '../../api/admin'
import { PageHead, StatCard } from '../../components/xq'
import {
  auditStatusMeta,
  auditStatusOptions,
  categoryLabel,
  categoryOptions,
  emptyAuditStats,
  errorText,
  formatTime,
  getPostCurrentVersion,
  isReviewConflict,
  moderationReasonLabels,
  moderationReasonOptions,
} from './shared'

interface PostFilters {
  id: string
  keyword: string
  category_code?: CommunityPostCategory
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

const emptyFilters: PostFilters = { id: '', keyword: '' }

const forwardGroupLabels: Record<CommunityPostForwardGroup['status'], string> = {
  success: '成功',
  failed: '失败',
  unknown: '结果未知',
}

export function PostManagePage() {
  const [messageApi, contextHolder] = message.useMessage()
  const [items, setItems] = useState<ManagedCommunityPost[]>([])
  const [stats, setStats] = useState<CommunityAuditStats>(emptyAuditStats)
  const [filters, setFilters] = useState<PostFilters>(emptyFilters)
  const [appliedFilters, setAppliedFilters] = useState<PostFilters>(emptyFilters)
  const [selectedRowKeys, setSelectedRowKeys] = useState<string[]>([])
  const [detail, setDetail] = useState<ManagedCommunityPost | null>(null)
  const [page, setPage] = useState(1)
  const [pageSize, setPageSize] = useState(20)
  const [total, setTotal] = useState(0)
  const [loading, setLoading] = useState(false)
  const [loadError, setLoadError] = useState('')
  const [submitting, setSubmitting] = useState(false)
  const [reviewTarget, setReviewTarget] = useState<ReviewTarget | null>(null)
  // 弹窗关闭动画期间 reviewTarget 已置空，保留最近一次决策，避免标题/按钮闪成“驳回”。
  const [reviewDecision, setReviewDecision] = useState<CommunityReviewDecision>('approve')
  const [deleteTarget, setDeleteTarget] = useState<ManagedCommunityPost | null>(null)
  const [forwardingId, setForwardingId] = useState<string | null>(null)
  const [reviewForm] = Form.useForm<ReviewFormValues>()
  const [deleteForm] = Form.useForm<{ reason_code: CommunityModerationReasonCode; note: string }>()

  const load = useCallback(async () => {
    setSelectedRowKeys([])
    setLoading(true)
    setLoadError('')
    try {
      const result = await listCommunityPosts({
        page,
        page_size: pageSize,
        id: appliedFilters.id.trim() || undefined,
        keyword: appliedFilters.keyword.trim() || undefined,
        category_code: appliedFilters.category_code,
        audit_status: appliedFilters.audit_status,
      })
      setItems(result.items)
      setStats(result.stats)
      setTotal(result.pagination.total)
      if (page > result.pagination.last_page && result.pagination.last_page > 0) setPage(result.pagination.last_page)
    } catch (error) {
      const text = errorText(error, '帖子列表加载失败，请稍后重试。')
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
    setAppliedFilters({ ...filters, id: filters.id.trim(), keyword: filters.keyword.trim() })
    setPage(1)
  }

  const resetFilters = () => {
    setFilters({ ...emptyFilters })
    setAppliedFilters({ ...emptyFilters })
    setPage(1)
  }

  const openReview = (ids: string[], decision: CommunityReviewDecision, subject: string, batch = false) => {
    reviewForm.resetFields()
    reviewForm.setFieldsValue({ note: decision === 'approve' ? '人工审核通过，内容符合社区规范。' : '内容不符合社区规范，请修改后重新提交。' })
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
        await reviewCommunityPost(reviewTarget.ids[0], reviewTarget.decision, values.note.trim(), values.reason_code)
        messageApi.success(reviewTarget.decision === 'approve' ? '帖子已审核通过。' : '帖子已驳回。')
      } else {
        const result = await batchReviewCommunityPosts({
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

  const openDelete = (item: ManagedCommunityPost) => {
    deleteForm.resetFields()
    setDeleteTarget(item)
  }

  const submitDelete = async (values: { reason_code: CommunityModerationReasonCode; note: string }) => {
    if (!deleteTarget) return
    setSubmitting(true)
    try {
      await moderateCommunityContent('post', deleteTarget.id, 'delete', [], values.reason_code, values.note.trim())
      messageApi.success('帖子已通过内容处置流程删除，统计数据已刷新。')
      if (detail?.id === deleteTarget.id) setDetail(null)
      setDeleteTarget(null)
      deleteForm.resetFields()
      await load()
    } catch (error) {
      messageApi.error(errorText(error, '删除帖子失败，请稍后重试。'))
    } finally {
      setSubmitting(false)
    }
  }

  const resendForward = (item: ManagedCommunityPost) => {
    const title = getPostCurrentVersion(item).title
    Modal.confirm({
      title: '重发帖子到转发群',
      content: `将按当前启用中的转发配置把「${title}」重新发送到目标群，可能产生重复消息。确认继续？`,
      okText: '确认重发',
      cancelText: '取消',
      onOk: async () => {
        setForwardingId(item.id)
        try {
          const result = await forwardCommunityPost(item.id)
          const failed = result.groups.filter((group) => group.status !== 'success')
          if (failed.length) {
            messageApi.warning(`重发完成：成功 ${result.groups.length - failed.length} 个群，失败 ${failed.length} 个群。`)
          } else {
            messageApi.success(`已重发到 ${result.groups.length} 个群。`)
          }
        } catch (error) {
          messageApi.error(errorText(error, '重发失败，请稍后重试。'))
        } finally {
          setForwardingId(null)
          await load()
        }
      },
    })
  }

  const detailVersion = detail ? getPostCurrentVersion(detail) : null

  const columns: ColumnsType<ManagedCommunityPost> = [
    { title: '帖子 ID', dataIndex: 'id', width: 190, render: (value: string) => <Typography.Text copyable={{ text: value }}>{value}</Typography.Text> },
    {
      title: '帖子信息',
      width: 280,
      render: (_, item) => {
        const version = getPostCurrentVersion(item)
        return <div>
          <Typography.Text strong ellipsis={{ tooltip: version.title }} style={{ display: 'block' }}>{version.title}</Typography.Text>
          <Typography.Text type="secondary" ellipsis={{ tooltip: version.content }} style={{ display: 'block', fontSize: 12 }}>{version.content}</Typography.Text>
          <div style={{ color: '#8c8c8c', fontSize: 12, marginTop: 4 }}>{item.author.nickname} · {item.author.campus_name || '未设置校区'} · {item.location_name || '未设置位置'}</div>
        </div>
      },
    },
    { title: '分类', width: 110, render: (_, item) => <Tag color="blue">{categoryLabel(getPostCurrentVersion(item).category_code)}</Tag> },
    {
      title: '内容统计',
      width: 155,
      render: (_, item) => <div style={{ fontSize: 12, lineHeight: 1.8 }}>
        <div>点赞 {item.like_count} · 评论 {item.comment_count}</div>
        <div>收藏 {item.favorite_count}</div>
      </div>,
    },
    {
      title: '审核状态',
      dataIndex: 'audit_status',
      width: 120,
      render: (status: CommunityAuditStatus) => <Tag color={auditStatusMeta[status].color}>{auditStatusMeta[status].label}</Tag>,
    },
    {
      title: '转发状态',
      width: 120,
      render: (_, item) => {
        const forward = item.forward
        if (forward.status === 'none') return <Tag>未转发</Tag>
        const meta = forward.status === 'success' ? { color: 'green', label: '已转发' } : { color: 'red', label: '转发失败' }
        const detail = (
          <div style={{ maxWidth: 320 }}>
            {forward.groups.map((group) => (
              <div key={group.group_id}>
                {(group.group_name || group.group_id)}：{forwardGroupLabels[group.status]}
                {group.error_message ? `（${group.error_message}）` : ''}
                {group.manual ? ' · 手动' : ''}
              </div>
            ))}
          </div>
        )
        return <Tooltip title={detail}><Tag color={meta.color} style={{ cursor: 'help' }}>{meta.label}</Tag></Tooltip>
      },
    },
    { title: '发布时间', dataIndex: 'published_at', width: 175, render: formatTime },
    {
      title: '操作',
      fixed: 'right',
      width: 220,
      render: (_, item) => <Space size={0} wrap>
        <Button type="link" size="small" onClick={() => setDetail(item)}>查看</Button>
        {item.audit_status === 'published' && <Button type="link" size="small" loading={forwardingId === item.id} onClick={() => resendForward(item)}>重发</Button>}
        {item.audit_status === 'pending_review' && <>
          <Button type="link" size="small" onClick={() => openReview([item.id], 'approve', getPostCurrentVersion(item).title)}>通过</Button>
          <Button type="link" size="small" danger onClick={() => openReview([item.id], 'reject', getPostCurrentVersion(item).title)}>驳回</Button>
        </>}
        {item.audit_status !== 'deleted' && <Button type="link" size="small" danger onClick={() => openDelete(item)}>删除</Button>}
      </Space>,
    },
  ]

  return (
    <div className="xq-page">
      {contextHolder}
      <PageHead title="帖子管理" extra={<Button icon={<ReloadOutlined />} loading={loading} onClick={() => void load()}>刷新</Button>} />

      {loadError && <Alert type="error" showIcon message={loadError} action={<Button size="small" onClick={() => void load()}>重试</Button>} style={{ marginBottom: 16 }} />}

      <div className="xq-card" style={{ marginBottom: 16 }}>
        <Form layout="inline" onFinish={applyFilters} style={{ rowGap: 12 }}>
          <Form.Item label="帖子 ID" style={{ marginInlineEnd: 12 }}>
            <Input value={filters.id} onChange={(event) => setFilters((current) => ({ ...current, id: event.target.value }))} placeholder="输入完整或部分 ID" allowClear style={{ width: 190 }} />
          </Form.Item>
          <Form.Item label="关键词" style={{ marginInlineEnd: 12 }}>
            <Input value={filters.keyword} onChange={(event) => setFilters((current) => ({ ...current, keyword: event.target.value }))} placeholder="标题 / 正文 / 作者昵称" allowClear style={{ width: 220 }} />
          </Form.Item>
          <Form.Item label="分类" style={{ marginInlineEnd: 12 }}>
            <Select value={filters.category_code} onChange={(value?: CommunityPostCategory) => setFilters((current) => ({ ...current, category_code: value }))} options={categoryOptions} placeholder="全部分类" allowClear style={{ width: 140 }} />
          </Form.Item>
          <Form.Item label="审核状态" style={{ marginInlineEnd: 12 }}>
            <Select value={filters.audit_status} onChange={(value?: CommunityAuditStatus) => setFilters((current) => ({ ...current, audit_status: value }))} options={auditStatusOptions} placeholder="全部状态" allowClear style={{ width: 150 }} />
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
        <StatCard title="总帖子" value={stats.total} delta="当前校区全部帖子" />
        <StatCard title="待审核" value={stats.pending_review} delta="当前版本等待处理" valueColor="var(--xq-warning)" />
        <StatCard title="已发布" value={stats.published} delta="当前版本已发布" valueColor="var(--xq-green-deep)" />
        <StatCard title="已驳回" value={stats.rejected} delta="当前版本已驳回" valueColor="var(--xq-danger)" />
        <StatCard title="已隐藏" value={stats.hidden} delta="社区处置隐藏" />
        <StatCard title="已删除" value={stats.deleted} delta="保留处置与申诉记录" />
      </div>

      <div className="xq-card xq-table">
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: 12, flexWrap: 'wrap', marginBottom: 16 }}>
          <Space wrap>
            <Button type="primary" icon={<CheckOutlined />} disabled={!selectedRowKeys.length} onClick={() => openReview(selectedRowKeys, 'approve', `${selectedRowKeys.length} 条帖子`, true)}>批量通过</Button>
            <Button danger icon={<StopOutlined />} disabled={!selectedRowKeys.length} onClick={() => openReview(selectedRowKeys, 'reject', `${selectedRowKeys.length} 条帖子`, true)}>批量驳回</Button>
          </Space>
          <Typography.Text type="secondary">仅可选择当前页待审核帖子 · 已选择 {selectedRowKeys.length} 项</Typography.Text>
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
          locale={{ emptyText: '没有符合当前条件的帖子' }}
          scroll={{ x: 1490 }}
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

      <Modal title="帖子详情" open={!!detail} footer={<Button type="primary" onClick={() => setDetail(null)}>关闭</Button>} onCancel={() => setDetail(null)} width={760}>
        {detail && detailVersion && <>
          {detail.audit_status === 'pending_review' && detail.published_version_no !== null && <Alert
            type="info"
            showIcon
            message="当前编辑版本待审核"
            description={`公开版本为 v${detail.published_version_no}，当前审核版本为 v${detailVersion.version_no}；审核通过前仍展示原公开版本。`}
            style={{ marginBottom: 16 }}
          />}
          <Descriptions bordered size="small" column={2} items={[
            { key: 'id', label: '帖子 ID', children: detail.id, span: 2 },
            { key: 'versions', label: '版本', children: `当前版本 v${detailVersion.version_no} / 公开版本 ${detail.published_version_no === null ? '暂无' : `v${detail.published_version_no}`}`, span: 2 },
            { key: 'title', label: '当前版本标题', children: detailVersion.title, span: 2 },
            { key: 'content', label: '当前版本正文', children: <Typography.Paragraph style={{ whiteSpace: 'pre-wrap', marginBottom: 0 }}>{detailVersion.content}</Typography.Paragraph>, span: 2 },
            { key: 'author', label: '发布人', children: `${detail.author.nickname}（${detail.author.id}）` },
            { key: 'campus', label: '校区', children: detail.author.campus_name || '未设置' },
            { key: 'category', label: '分类', children: categoryLabel(detailVersion.category_code) },
            { key: 'location', label: '位置', children: detail.location_name || '未设置' },
            { key: 'status', label: '管理状态', children: <Tag color={auditStatusMeta[detail.audit_status].color}>{auditStatusMeta[detail.audit_status].label}</Tag> },
            { key: 'raw-status', label: '真实状态', children: detail.status },
            { key: 'stats', label: '内容统计', children: `点赞 ${detail.like_count} / 评论 ${detail.comment_count} / 收藏 ${detail.favorite_count}` },
            { key: 'reward', label: '奖励状态', children: detail.reward_granted ? '已发放' : '未发放或已撤销' },
            { key: 'published', label: '发布时间', children: formatTime(detail.published_at) },
            { key: 'created', label: '创建时间', children: formatTime(detail.created_at) },
            { key: 'reason', label: '审核原因', children: detailVersion.review_reason_code ? moderationReasonLabels[detailVersion.review_reason_code] : '暂无', span: 2 },
            { key: 'note', label: '审核备注', children: detailVersion.review_note || '暂无', span: 2 },
            { key: 'reviewed', label: '审核时间', children: formatTime(detailVersion.reviewed_at), span: 2 },
          ]} />
        </>}
      </Modal>

      <Modal
        title={reviewDecision === 'approve' ? '通过帖子审核' : '驳回帖子'}
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
          <Alert type={reviewTarget.decision === 'approve' ? 'info' : 'warning'} showIcon message={`审核对象：${reviewTarget.subject}`} description={reviewTarget.batch ? '批量审核逐条执行，单条失败不会撤销其他成功项。' : `帖子 ID：${reviewTarget.ids[0]}`} style={{ marginBottom: 16 }} />
          {reviewTarget.decision === 'reject' && <Form.Item name="reason_code" label="驳回原因" rules={[{ required: true, message: '请选择合法的驳回原因' }]}>
            <Select options={moderationReasonOptions} placeholder="请选择驳回原因" />
          </Form.Item>}
          <Form.Item name="note" label="审核备注" rules={[{ required: true, whitespace: true, message: '请填写审核备注' }, { max: 500, message: '审核备注不能超过 500 字' }]}>
            <Input.TextArea rows={4} maxLength={500} showCount placeholder="说明审核结论或需修改的内容" />
          </Form.Item>
        </Form>}
      </Modal>

      <Modal
        title="删除帖子"
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
