import { Button, Card, Space, Table, Tabs, Tag, Typography, message } from 'antd'
import { useCallback, useEffect, useState } from 'react'
import { listCommunityAppeals, listCommunityReports, listPendingCommunityComments, listPendingCommunityPosts, moderateCommunityContent, reviewCommunityAppeal, reviewCommunityComment, reviewCommunityPost, type ManagedCommunityComment, type ManagedCommunityPost, type ManagedContentAppeal, type ManagedContentReport } from '../../api/admin'

export function CommunityManagementPage() {
  const [posts, setPosts] = useState<ManagedCommunityPost[]>([])
  const [comments, setComments] = useState<ManagedCommunityComment[]>([])
  const [reports, setReports] = useState<ManagedContentReport[]>([])
  const [appeals, setAppeals] = useState<ManagedContentAppeal[]>([])
  const [busy, setBusy] = useState('')
  const [messageApi, contextHolder] = message.useMessage()
  const load = useCallback(async () => { try { const [p, c, r, a] = await Promise.all([listPendingCommunityPosts(), listPendingCommunityComments(), listCommunityReports(), listCommunityAppeals()]); setPosts(p.items); setComments(c.items); setReports(r.items); setAppeals(a.items) } catch (error) { messageApi.error(error instanceof Error ? error.message : '内容队列加载失败') } }, [messageApi])
  useEffect(() => { void load() }, [load])
  const act = async (id: string, action: () => Promise<unknown>) => { setBusy(id); try { await action(); messageApi.success('审核完成'); await load() } catch (error) { messageApi.error(error instanceof Error ? error.message : '审核失败') } finally { setBusy('') } }
  const buttons = (id: string, type: 'post' | 'comment') => <Space><Button size="small" type="primary" loading={busy === id} onClick={() => void act(id, () => type === 'post' ? reviewCommunityPost(id, 'approve', '内容合规，准予发布。') : reviewCommunityComment(id, 'approve', '评论合规，准予发布。'))}>通过</Button><Button size="small" danger onClick={() => void act(id, () => type === 'post' ? reviewCommunityPost(id, 'reject', '内容不符合社区规则。') : reviewCommunityComment(id, 'reject', '评论不符合社区规则。'))}>驳回</Button></Space>
  return <>
    {contextHolder}
    <div className="page-heading"><div><Typography.Text className="eyebrow">COMMUNITY</Typography.Text><Typography.Title level={2}>社区内容</Typography.Title><Typography.Paragraph type="secondary">审核帖子版本与敏感评论；通过后自动执行成长积分规则。</Typography.Paragraph></div><Tag color="blue">第 25 批</Tag></div>
    <Card bordered={false}><Tabs items={[
      { key: 'posts', label: `待审帖子 (${posts.length})`, children: <Table rowKey="id" dataSource={posts} pagination={false} columns={[{ title: '作者', render: (_, row) => row.author.nickname }, { title: '标题', dataIndex: 'title' }, { title: '正文', dataIndex: 'content', ellipsis: true }, { title: '版本', dataIndex: 'current_version_no' }, { title: '操作', render: (_, row) => buttons(row.id, 'post') }]} /> },
      { key: 'comments', label: `待审评论 (${comments.length})`, children: <Table rowKey="id" dataSource={comments} pagination={false} columns={[{ title: '作者', render: (_, row) => row.author.nickname }, { title: '内容', dataIndex: 'content', ellipsis: true }, { title: '状态', render: (_, row) => <Tag color="gold">{row.status}</Tag> }, { title: '操作', render: (_, row) => buttons(row.id, 'comment') }]} /> },
      { key: 'reports', label: `举报 (${reports.length})`, children: <Table rowKey="id" dataSource={reports} pagination={false} columns={[{ title: '内容类型', dataIndex: 'content_type' }, { title: '原因', dataIndex: 'reason_code' }, { title: '补充说明', dataIndex: 'description', ellipsis: true }, { title: '操作', render: (_, row) => <Space><Button size="small" onClick={() => void act(row.id, () => moderateCommunityContent(row.content_type, row.content_id, 'warning'))}>警告</Button><Button size="small" danger onClick={() => void act(row.id, () => moderateCommunityContent(row.content_type, row.content_id, 'hide', ['mute']))}>隐藏并禁言</Button><Button size="small" danger type="primary" onClick={() => void act(row.id, () => moderateCommunityContent(row.content_type, row.content_id, 'delete', ['post', 'comment']))}>删除并限制</Button></Space> }]} /> },
      { key: 'appeals', label: `申诉 (${appeals.length})`, children: <Table rowKey="id" dataSource={appeals} pagination={false} columns={[{ title: '申诉理由', dataIndex: 'reason', ellipsis: true }, { title: '状态', render: (_, row) => <Tag color="blue">{row.status}</Tag> }, { title: '操作', render: (_, row) => <Space><Button size="small" type="primary" onClick={() => void act(row.id, () => reviewCommunityAppeal(row.id, 'approve'))}>撤销原处置</Button><Button size="small" danger onClick={() => void act(row.id, () => reviewCommunityAppeal(row.id, 'reject'))}>维持处置</Button></Space> }]} /> },
    ]} /></Card>
  </>
}
