import { useCallback, useEffect, useMemo, useState } from 'react'
import { Button, Form, Input, Modal, Table, Typography, message } from 'antd'
import type { ColumnsType } from 'antd/es/table'
import {
  listCommunityReports,
  listPendingCommunityComments,
  listPendingCommunityPosts,
  reviewCommunityComment,
  reviewCommunityPost,
  type ManagedCommunityComment,
  type ManagedCommunityPost,
  type ManagedContentReport,
} from '../../api/admin'
import { Links, PageHead, Pill, UCell, type PillTone } from '../../components/xq'

const fmtTime = (v?: string | null) => (v ? new Date(v).toLocaleString('zh-CN', { hour12: false }) : '—')

const contentPills: Record<string, { tone: PillTone; label: string }> = {
  pending: { tone: 'orange', label: '待审核' },
  published: { tone: 'green', label: '正常' },
  rejected: { tone: 'red', label: '已屏蔽' },
}
const contentPill = (s: string) => contentPills[s] ?? { tone: 'gray' as PillTone, label: s }

const reportReasonLabels: Record<string, string> = {
  spam: '垃圾广告', abuse: '辱骂攻击', porn: '色情低俗', illegal: '违法违规', privacy: '泄露隐私', other: '其他',
}

type TabKey = 'posts' | 'comments' | 'reports'

export function CommunityPage() {
  const [messageApi, contextHolder] = message.useMessage()
  const [tab, setTab] = useState<TabKey>('posts')
  const [posts, setPosts] = useState<ManagedCommunityPost[]>([])
  const [comments, setComments] = useState<ManagedCommunityComment[]>([])
  const [reports, setReports] = useState<ManagedContentReport[]>([])
  const [keyword, setKeyword] = useState('')
  const [appliedKeyword, setAppliedKeyword] = useState('')
  const [loading, setLoading] = useState(false)
  const [viewPost, setViewPost] = useState<ManagedCommunityPost | null>(null)
  const [viewComment, setViewComment] = useState<ManagedCommunityComment | null>(null)
  const [viewReport, setViewReport] = useState<ManagedContentReport | null>(null)
  const [reviewTarget, setReviewTarget] = useState<{ kind: 'post' | 'comment'; id: string; title: string; decision: 'approve' | 'reject' } | null>(null)
  const [submitting, setSubmitting] = useState(false)
  const [reviewForm] = Form.useForm<{ note?: string }>()

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const [p, c, r] = await Promise.all([listPendingCommunityPosts(), listPendingCommunityComments(), listCommunityReports()])
      setPosts(p.items)
      setComments(c.items)
      setReports(r.items)
    } catch {
      messageApi.error('社区内容加载失败，请稍后重试。')
    } finally {
      setLoading(false)
    }
  }, [messageApi])

  useEffect(() => { void load() }, [load])

  const k = appliedKeyword.trim()
  const filteredPosts = useMemo(() => (k ? posts.filter((p) => `${p.title} ${p.content} ${p.author.nickname}`.includes(k)) : posts), [posts, k])
  const filteredComments = useMemo(() => (k ? comments.filter((c) => `${c.content} ${c.author.nickname}`.includes(k)) : comments), [comments, k])
  const filteredReports = useMemo(() => (k ? reports.filter((r) => `${r.reason_code} ${r.description ?? ''} ${r.content_id}`.includes(k)) : reports), [reports, k])

  const openReview = (kind: 'post' | 'comment', id: string, title: string, decision: 'approve' | 'reject') => {
    reviewForm.resetFields()
    setReviewTarget({ kind, id, title, decision })
  }

  const submitReview = async (values: { note?: string }) => {
    if (!reviewTarget) return
    setSubmitting(true)
    try {
      const note = values.note?.trim() || (reviewTarget.decision === 'approve' ? '审核通过' : '内容违规，予以屏蔽')
      if (reviewTarget.kind === 'post') await reviewCommunityPost(reviewTarget.id, reviewTarget.decision, note)
      else await reviewCommunityComment(reviewTarget.id, reviewTarget.decision, note)
      messageApi.success(reviewTarget.decision === 'approve' ? '已通过审核。' : '已屏蔽该内容。')
      setReviewTarget(null)
      await load()
    } catch {
      messageApi.error('审核操作失败，请稍后重试。')
    } finally {
      setSubmitting(false)
    }
  }

  const summary = (text: string) => (
    <span>
      <Typography.Text ellipsis style={{ maxWidth: 420, display: 'block', fontWeight: 600 }}>{text}</Typography.Text>
    </span>
  )

  const postColumns: ColumnsType<ManagedCommunityPost> = [
    {
      title: '内容摘要',
      render: (_, r) => (
        <span>
          {summary(r.title)}
          <Typography.Text type="secondary" style={{ fontSize: 12 }} ellipsis={{ tooltip: r.content }}>{r.content.slice(0, 50)}</Typography.Text>
        </span>
      ),
    },
    { title: '作者', render: (_, r) => <UCell name={r.author.nickname} sub={`ID:${r.author.id}`} /> },
    { title: '评论数', dataIndex: 'comment_count', width: 80 },
    { title: '状态', width: 100, render: (_, r) => { const p = contentPill(r.status); return <Pill tone={p.tone}>{p.label}</Pill> } },
    { title: '发布时间', render: (_, r) => fmtTime(r.created_at) },
    {
      title: '操作',
      render: (_, r) => (
        <Links items={[
          { label: '查看', onClick: () => setViewPost(r) },
          r.status === 'pending' && { label: '通过', onClick: () => openReview('post', r.id, r.title, 'approve') },
          r.status === 'pending' && { label: '屏蔽', danger: true, onClick: () => openReview('post', r.id, r.title, 'reject') },
        ]} />
      ),
    },
  ]

  const commentColumns: ColumnsType<ManagedCommunityComment> = [
    {
      title: '内容摘要',
      render: (_, r) => <Typography.Text ellipsis={{ tooltip: r.content }} style={{ maxWidth: 420, display: 'block' }}>{r.content}</Typography.Text>,
    },
    { title: '作者', render: (_, r) => <UCell name={r.author.nickname} sub={`ID:${r.author.id}`} /> },
    { title: '所属帖子', render: (_, r) => <span style={{ color: '#6b7280' }}>#{r.post_id}</span> },
    { title: '状态', width: 100, render: (_, r) => { const p = contentPill(r.status); return <Pill tone={p.tone}>{p.label}</Pill> } },
    { title: '发布时间', render: (_, r) => fmtTime(r.created_at) },
    {
      title: '操作',
      render: (_, r) => (
        <Links items={[
          { label: '查看', onClick: () => setViewComment(r) },
          r.status === 'pending' && { label: '通过', onClick: () => openReview('comment', r.id, r.content.slice(0, 20), 'approve') },
          r.status === 'pending' && { label: '屏蔽', danger: true, onClick: () => openReview('comment', r.id, r.content.slice(0, 20), 'reject') },
        ]} />
      ),
    },
  ]

  const reportColumns: ColumnsType<ManagedContentReport> = [
    { title: '举报对象', render: (_, r) => `${r.content_type === 'post' ? '帖子' : '评论'} #${r.content_id}` },
    { title: '举报原因', render: (_, r) => reportReasonLabels[r.reason_code] ?? r.reason_code },
    { title: '补充描述', render: (_, r) => r.description ?? '—', ellipsis: true },
    {
      title: '状态',
      width: 100,
      render: (_, r) => (r.status === 'open' || r.status === 'pending' ? <Pill tone="orange">待处理</Pill> : <Pill tone="green">已处理</Pill>),
    },
    { title: '举报时间', render: (_, r) => fmtTime(r.created_at) },
    { title: '操作', render: (_, r) => <Links items={[{ label: '查看', onClick: () => setViewReport(r) }]} /> },
  ]

  return (
    <div className="xq-page">
      {contextHolder}
      <PageHead title="内容审核" />

      <div className="xq-tabs">
        <div className={tab === 'posts' ? 'it on' : 'it'} onClick={() => setTab('posts')}>帖子</div>
        <div className={tab === 'comments' ? 'it on' : 'it'} onClick={() => setTab('comments')}>评论</div>
        <div className={tab === 'reports' ? 'it on' : 'it'} onClick={() => setTab('reports')}>举报</div>
      </div>

      <div className="xq-card xq-filter" style={{ marginBottom: 16 }}>
        <Input placeholder="关键词搜索" value={keyword} onChange={(e) => setKeyword(e.target.value)} onPressEnter={() => setAppliedKeyword(keyword)} style={{ maxWidth: 220 }} />
        <Button type="primary" onClick={() => setAppliedKeyword(keyword)}>查询</Button>
        <Button onClick={() => { setKeyword(''); setAppliedKeyword('') }}>重置</Button>
      </div>

      <div className="xq-card xq-table">
        {tab === 'posts' && <Table rowKey="id" size="middle" loading={loading} columns={postColumns} dataSource={filteredPosts} pagination={{ pageSize: 10, showTotal: (t) => `共 ${t} 条` }} />}
        {tab === 'comments' && <Table rowKey="id" size="middle" loading={loading} columns={commentColumns} dataSource={filteredComments} pagination={{ pageSize: 10, showTotal: (t) => `共 ${t} 条` }} />}
        {tab === 'reports' && <Table rowKey="id" size="middle" loading={loading} columns={reportColumns} dataSource={filteredReports} pagination={{ pageSize: 10, showTotal: (t) => `共 ${t} 条` }} />}
      </div>

      <Modal title="帖子详情" open={!!viewPost} footer={null} onCancel={() => setViewPost(null)} width={560}>
        {viewPost && (
          <>
            <h3 style={{ marginTop: 0 }}>{viewPost.title}</h3>
            <p style={{ color: '#6b7280', fontSize: 12 }}>作者：{viewPost.author.nickname} · {fmtTime(viewPost.created_at)}</p>
            <p style={{ whiteSpace: 'pre-wrap', lineHeight: 1.8 }}>{viewPost.content}</p>
          </>
        )}
      </Modal>

      <Modal title="评论详情" open={!!viewComment} footer={null} onCancel={() => setViewComment(null)} width={480}>
        {viewComment && (
          <>
            <p style={{ color: '#6b7280', fontSize: 12 }}>作者：{viewComment.author.nickname} · 所属帖子 #{viewComment.post_id} · {fmtTime(viewComment.created_at)}</p>
            <p style={{ whiteSpace: 'pre-wrap', lineHeight: 1.8 }}>{viewComment.content}</p>
          </>
        )}
      </Modal>

      <Modal title="举报详情" open={!!viewReport} footer={null} onCancel={() => setViewReport(null)} width={480}>
        {viewReport && (
          <>
            <p>举报对象：{viewReport.content_type === 'post' ? '帖子' : '评论'} #{viewReport.content_id}</p>
            <p>举报原因：{reportReasonLabels[viewReport.reason_code] ?? viewReport.reason_code}</p>
            <p>补充描述：{viewReport.description ?? '—'}</p>
            <p style={{ color: '#6b7280', fontSize: 12 }}>举报时间：{fmtTime(viewReport.created_at)}</p>
          </>
        )}
      </Modal>

      <Modal
        title={reviewTarget?.decision === 'approve' ? '通过内容' : '屏蔽内容'}
        open={!!reviewTarget}
        onCancel={() => setReviewTarget(null)}
        onOk={() => reviewForm.submit()}
        confirmLoading={submitting}
        okText="确认"
        cancelText="取消"
      >
        <p style={{ color: '#6b7280', fontSize: 13 }}>内容：{reviewTarget?.title}</p>
        <Form form={reviewForm} layout="vertical" onFinish={submitReview}>
          <Form.Item name="note" label="审核备注" rules={reviewTarget?.decision === 'reject' ? [{ required: true, message: '屏蔽时请填写原因' }] : []}>
            <Input.TextArea rows={3} placeholder={reviewTarget?.decision === 'approve' ? '审核通过（可不填）' : '请填写屏蔽原因'} />
          </Form.Item>
        </Form>
      </Modal>
    </div>
  )
}
