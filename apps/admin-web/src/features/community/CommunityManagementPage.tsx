import {
  ArrowDownOutlined,
  ArrowUpOutlined,
  CheckOutlined,
  CloseOutlined,
  DeleteOutlined,
  EditOutlined,
  PlusOutlined,
  StopOutlined,
} from '@ant-design/icons'
import { Alert, Button, Card, Empty, Form, Input, Modal, Select, Space, Switch, Table, Tabs, Tag, Typography, message, type FormInstance } from 'antd'
import { useCallback, useEffect, useState } from 'react'
import {
  ApiError,
  closeCommunityActivity,
  getCommunityHomepageConfig,
  listCommunityActivities,
  listCommunityAppeals,
  listCommunityReports,
  listPendingCommunityComments,
  listPendingCommunityPosts,
  moderateCommunityContent,
  removeCommunityActivityParticipant,
  reviewCommunityActivity,
  reviewCommunityAppeal,
  reviewCommunityComment,
  reviewCommunityPost,
  updateCommunityHomepageConfig,
  type CommunityHomepageConfig,
  type CommunityHomepageConfigInput,
  type CommunityHomepageLinkType,
  type CommunityQuickTarget,
  type ManagedCommunityActivity,
  type ManagedCommunityActivityParticipant,
  type ManagedCommunityComment,
  type ManagedCommunityPost,
  type ManagedContentAppeal,
  type ManagedContentReport,
} from '../../api/admin'
import { MediaGalleryInput, type GalleryImageValue } from '../../components/MediaGalleryInput'

type ActivityReviewDecision = 'approve' | 'reject'

interface CommunityConfigForm extends Omit<CommunityHomepageConfigInput, 'expected_version'> {
  expected_version?: number | null
}

interface ActivityReviewForm {
  note: string
  reason_code?: string
}

interface ActivityCloseForm {
  reason: string
}

interface ParticipantRemoveForm {
  reason: string
}

const activityStatusMeta: Record<string, { label: string; color: string }> = {
  pending_review: { label: '待审核', color: 'gold' },
  approved: { label: '已通过', color: 'success' },
  rejected: { label: '已驳回', color: 'error' },
  closed: { label: '已关闭', color: 'default' },
}

const contentStatusMeta: Record<string, { label: string; color: string }> = {
  pending_review: { label: '待审核', color: 'gold' },
  published: { label: '已发布', color: 'success' },
  approved: { label: '已通过', color: 'success' },
  rejected: { label: '已驳回', color: 'error' },
  hidden: { label: '已隐藏', color: 'default' },
  resolved: { label: '已处理', color: 'success' },
  pending: { label: '待处理', color: 'gold' },
}

const quickTargetOptions: Array<{ value: CommunityQuickTarget; label: string }> = [
  { value: 'publish_post', label: '发布帖子' },
  { value: 'activities', label: '校园组团' },
  { value: 'tasks', label: '任务大厅' },
  { value: 'posts', label: '社区帖子' },
  { value: 'notifications', label: '消息中心' },
]

const bannerLinkOptions: Array<{ value: CommunityHomepageLinkType; label: string }> = [
  { value: 'posts', label: '跳转到社区帖子' },
  { value: 'activities', label: '跳转到校园组团' },
  { value: 'tasks', label: '跳转到任务大厅' },
  { value: 'none', label: '不跳转' },
]

const campusColors = ['#20D96B', '#FFE13B', '#FF5D5D', '#48B8FF', '#9A65FF', '#FF9A36', '#14C9B2', '#FF70B6']

function formatDate(value?: string | null) {
  return value ? new Date(value).toLocaleString('zh-CN', { hour12: false }) : '—'
}

function safeCampusColor(value: string | null | undefined, index = 0) {
  return /^#[0-9A-Fa-f]{6}$/.test(value ?? '') ? value as string : campusColors[index % campusColors.length]
}

function statusTag(value: string) {
  const meta = activityStatusMeta[value] ?? contentStatusMeta[value] ?? { label: value || '未知状态', color: 'default' }
  return <Tag color={meta.color}>{meta.label}</Tag>
}

function compactError(error: unknown, fallback: string) {
  return error instanceof ApiError || error instanceof Error ? error.message : fallback
}

function imageValue(url?: string | null, label = ''): GalleryImageValue[] {
  return url ? [{ url, alt: label, sort_order: 0 }] : []
}

type CommunityConfigFieldPath = Parameters<FormInstance<CommunityConfigForm>['getFieldValue']>[0]

function CommunityConfigImageInput({
  form,
  path,
  label,
  helperText,
}: {
  form: FormInstance<CommunityConfigForm>
  path: CommunityConfigFieldPath
  label: string
  helperText: string
}) {
  return <Form.Item noStyle shouldUpdate>
    {() => <MediaGalleryInput
      value={imageValue(String(form.getFieldValue(path) ?? ''), label)}
      onChange={(items) => form.setFieldValue(path, items[0]?.url ?? '')}
      max={1}
      primaryLabel={label}
      helperText={helperText}
    />}
  </Form.Item>
}

function CommunityHomepagePreview({ config }: { config: CommunityHomepageConfig }) {
  const bannerColor = safeCampusColor(config.banner.accent_color, 1)
  const entries = config.quick_entries.filter((entry) => entry.enabled)
  const topics = config.hot_topics.filter((topic) => topic.enabled)

  return <div style={{ display: 'grid', gridTemplateColumns: 'minmax(0, 360px) minmax(0, 1fr)', gap: 24, alignItems: 'center' }}>
    <div style={{ width: '100%', maxWidth: 360, minHeight: 670, margin: '0 auto', overflow: 'hidden', border: '3px solid #151515', borderRadius: 28, background: 'linear-gradient(180deg, #FFF8C8 0%, #FFFFFF 38%)', boxShadow: '8px 8px 0 #151515' }}>
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', padding: '15px 18px 12px', color: '#151515' }}>
        <strong style={{ fontSize: 18, letterSpacing: '-.04em' }}>任务校园 · 圈子</strong>
        <span style={{ display: 'inline-grid', width: 30, height: 30, placeItems: 'center', border: '2px solid #151515', borderRadius: 10, background: '#fff' }}>⌕</span>
      </div>
      <div style={{ position: 'relative', minHeight: 166, margin: '0 14px', overflow: 'hidden', border: '3px solid #151515', borderRadius: 18, background: `linear-gradient(135deg, ${bannerColor} 0%, #fff 160%)`, boxShadow: '4px 4px 0 #151515' }}>
        {config.banner.image_url && <img src={config.banner.image_url} alt="校园社区横幅" style={{ position: 'absolute', inset: 0, width: '100%', height: '100%', objectFit: 'cover', opacity: .32 }} />}
        <div style={{ position: 'relative', padding: 18 }}>
          <span style={{ display: 'inline-block', padding: '4px 8px', border: '2px solid #151515', borderRadius: 999, background: '#fff', fontSize: 11, fontWeight: 800 }}>校园社区</span>
          <div style={{ maxWidth: 260, marginTop: 12, color: '#151515', fontSize: 27, fontWeight: 900, lineHeight: 1.12 }}>{config.banner.title || '校园互助社区'}</div>
          <div style={{ marginTop: 8, color: '#232323', fontSize: 12, fontWeight: 600 }}>{config.banner.subtitle || '求助、经验、组团，让校园生活更简单'}</div>
        </div>
      </div>
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(4, 1fr)', gap: 8, padding: '18px 14px 16px' }}>
        {entries.slice(0, 4).map((entry, index) => <div key={entry.id} style={{ minWidth: 0, textAlign: 'center' }}>
          <div style={{ display: 'grid', width: 48, height: 48, margin: '0 auto 6px', overflow: 'hidden', placeItems: 'center', border: '2px solid #151515', borderRadius: 15, background: safeCampusColor(entry.color, index), boxShadow: '3px 3px 0 #151515', fontWeight: 900 }}>
            {entry.icon_url ? <img src={entry.icon_url} alt="" style={{ width: '100%', height: '100%', objectFit: 'cover' }} /> : entry.label.slice(0, 1)}
          </div>
          <div style={{ overflow: 'hidden', color: '#151515', fontSize: 12, fontWeight: 800, textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{entry.label}</div>
        </div>)}
      </div>
      <div style={{ margin: '0 14px', padding: '12px 13px', border: '2px solid #151515', borderRadius: 14, background: '#fff', boxShadow: '3px 3px 0 #151515' }}>
        <strong style={{ color: '#151515' }}>校园热榜</strong>
        <div style={{ display: 'flex', gap: 7, marginTop: 8, overflow: 'hidden' }}>
          {topics.slice(0, 3).map((topic, index) => <span key={topic.id} style={{ flexShrink: 0, padding: '4px 8px', border: '1px solid #151515', borderRadius: 999, background: safeCampusColor(topic.color, index + 4), color: '#151515', fontSize: 11, fontWeight: 800 }}>{topic.label}</span>)}
          {!topics.length && <span style={{ color: '#777', fontSize: 12 }}>配置热榜话题后展示</span>}
        </div>
      </div>
      <div style={{ margin: '18px 14px 0', padding: 14, borderTop: '2px solid #151515', color: '#151515', fontSize: 13, fontWeight: 700 }}>全部 · 圈子动态 · 有偿求助 · 校园组团</div>
      <div style={{ display: 'flex', justifyContent: 'space-around', marginTop: 18, padding: '13px 10px 16px', borderTop: '2px solid #151515', background: '#fff', color: '#151515', fontSize: 11, fontWeight: 800 }}><span>首页</span><span>任务</span><span style={{ display: 'grid', width: 36, height: 36, marginTop: -18, placeItems: 'center', border: '2px solid #151515', borderRadius: 18, background: '#20D96B', boxShadow: '2px 2px 0 #151515', fontSize: 22 }}>+</span><span>消息</span><span>我的</span></div>
    </div>
    <Space direction="vertical" size={12} style={{ maxWidth: 550 }}>
      <Typography.Title level={4} style={{ margin: 0 }}>校园风移动端实时预览</Typography.Title>
      <Typography.Paragraph type="secondary" style={{ margin: 0 }}>采用明亮黄绿底色、黑色粗边与高饱和快捷入口。用户端会读取同一份配置；保存后按配置版本即时生效。</Typography.Paragraph>
      <Alert showIcon type="info" message="素材策略" description="Banner 与快捷入口图标支持本地上传或 HTTPS 图片网址导入，都会转存到当前启用的对象存储，不依赖外部链接。" />
      <Space wrap>{entries.map((entry, index) => <Tag key={entry.id} color="default" style={{ borderColor: '#151515', color: '#151515', background: safeCampusColor(entry.color, index), fontWeight: 700 }}>{entry.label} · {quickTargetOptions.find((option) => option.value === entry.target)?.label ?? entry.target}</Tag>)}</Space>
    </Space>
  </div>
}

export function CommunityManagementPage() {
  const [posts, setPosts] = useState<ManagedCommunityPost[]>([])
  const [comments, setComments] = useState<ManagedCommunityComment[]>([])
  const [reports, setReports] = useState<ManagedContentReport[]>([])
  const [appeals, setAppeals] = useState<ManagedContentAppeal[]>([])
  const [activities, setActivities] = useState<ManagedCommunityActivity[]>([])
  const [config, setConfig] = useState<CommunityHomepageConfig | null>(null)
  const [configError, setConfigError] = useState<string | null>(null)
  const [loading, setLoading] = useState(false)
  const [busy, setBusy] = useState('')
  const [configOpen, setConfigOpen] = useState(false)
  const [configSaving, setConfigSaving] = useState(false)
  const [activityReview, setActivityReview] = useState<{ activity: ManagedCommunityActivity; decision: ActivityReviewDecision } | null>(null)
  const [activityClosing, setActivityClosing] = useState<ManagedCommunityActivity | null>(null)
  const [activityParticipants, setActivityParticipants] = useState<ManagedCommunityActivity | null>(null)
  const [participantRemoving, setParticipantRemoving] = useState<{ activity: ManagedCommunityActivity; participant: ManagedCommunityActivityParticipant } | null>(null)
  const [configForm] = Form.useForm<CommunityConfigForm>()
  const [reviewForm] = Form.useForm<ActivityReviewForm>()
  const [closeForm] = Form.useForm<ActivityCloseForm>()
  const [participantForm] = Form.useForm<ParticipantRemoveForm>()
  const [messageApi, contextHolder] = message.useMessage()

  const load = useCallback(async () => {
    setLoading(true)
    const [postsResult, commentsResult, reportsResult, appealsResult, activitiesResult, configResult] = await Promise.allSettled([
      listPendingCommunityPosts(),
      listPendingCommunityComments(),
      listCommunityReports(),
      listCommunityAppeals(),
      listCommunityActivities(),
      getCommunityHomepageConfig(),
    ])
    const failures = [postsResult, commentsResult, reportsResult, appealsResult, activitiesResult].filter((result) => result.status === 'rejected')
    if (postsResult.status === 'fulfilled') setPosts(postsResult.value.items)
    if (commentsResult.status === 'fulfilled') setComments(commentsResult.value.items)
    if (reportsResult.status === 'fulfilled') setReports(reportsResult.value.items)
    if (appealsResult.status === 'fulfilled') setAppeals(appealsResult.value.items)
    if (activitiesResult.status === 'fulfilled') setActivities(activitiesResult.value.items)
    if (configResult.status === 'fulfilled') {
      setConfig(configResult.value)
      setConfigError(null)
    } else {
      setConfigError(compactError(configResult.reason, '校园社区装修配置加载失败。'))
    }
    if (failures.length) messageApi.error('部分社区运营数据加载失败，请刷新后重试。')
    setLoading(false)
  }, [messageApi])

  useEffect(() => { void load() }, [load])

  const act = async (key: string, action: () => Promise<unknown>, success: string) => {
    setBusy(key)
    try {
      await action()
      messageApi.success(success)
      await load()
      return true
    } catch (error) {
      messageApi.error(compactError(error, '操作失败，请稍后重试。'))
      return false
    } finally {
      setBusy('')
    }
  }

  const contentReviewButtons = (id: string, type: 'post' | 'comment') => <Space wrap>
    <Button size="small" type="primary" icon={<CheckOutlined />} loading={busy === `${type}-${id}`} onClick={() => void act(`${type}-${id}`, () => type === 'post' ? reviewCommunityPost(id, 'approve', '内容符合校园社区规则，准予发布。') : reviewCommunityComment(id, 'approve', '评论符合校园社区规则，准予发布。'), '内容已审核通过。')}>通过</Button>
    <Button size="small" danger icon={<CloseOutlined />} loading={busy === `${type}-${id}`} onClick={() => void act(`${type}-${id}`, () => type === 'post' ? reviewCommunityPost(id, 'reject', '内容不符合校园社区规则。') : reviewCommunityComment(id, 'reject', '评论不符合校园社区规则。'), '内容已驳回。')}>驳回</Button>
  </Space>

  const openConfig = () => {
    if (!config) return
    configForm.setFieldsValue({
      banner: { ...config.banner, image_url: config.banner.image_url ?? '', accent_color: config.banner.accent_color ?? '#FFE13B' },
      quick_entries: config.quick_entries.map((entry, index) => ({ ...entry, icon_url: entry.icon_url ?? '', color: entry.color ?? campusColors[index % campusColors.length], sort_order: entry.sort_order ?? index })),
      hot_topics: config.hot_topics.map((topic, index) => ({ ...topic, color: topic.color ?? campusColors[(index + 3) % campusColors.length] })),
      expected_version: config.version,
      reason: '',
    })
    setConfigOpen(true)
  }

  const saveConfig = async (values: CommunityConfigForm) => {
    setConfigSaving(true)
    try {
      const input: CommunityHomepageConfigInput = {
        banner: { ...values.banner, image_url: values.banner.image_url || '', accent_color: values.banner.accent_color || undefined },
        quick_entries: values.quick_entries.map((entry, index) => ({ ...entry, id: entry.id.trim(), label: entry.label.trim(), description: entry.description?.trim() || '', icon_url: entry.icon_url || '', color: entry.color || undefined, sort_order: index })),
        hot_topics: values.hot_topics.map((topic) => ({ ...topic, id: topic.id.trim(), label: topic.label.trim(), target: topic.target.trim(), color: topic.color || undefined })),
        expected_version: config?.version ?? undefined,
        reason: values.reason.trim(),
      }
      const next = await updateCommunityHomepageConfig(input)
      setConfig(next)
      setConfigOpen(false)
      messageApi.success('校园社区装修已发布，移动端会读取新版本。')
    } catch (error) {
      messageApi.error(compactError(error, '校园社区装修保存失败。'))
    } finally {
      setConfigSaving(false)
    }
  }

  const submitActivityReview = async (values: ActivityReviewForm) => {
    if (!activityReview) return
    const { activity, decision } = activityReview
    const success = await act(`activity-${activity.id}`, () => reviewCommunityActivity(activity.id, decision, values.note.trim(), values.reason_code?.trim()), decision === 'approve' ? '组团活动已通过审核。' : '组团活动已驳回。')
    if (success) {
      setActivityReview(null)
      reviewForm.resetFields()
    }
  }

  const submitActivityClose = async (values: ActivityCloseForm) => {
    if (!activityClosing) return
    const success = await act(`activity-${activityClosing.id}`, () => closeCommunityActivity(activityClosing.id, values.reason.trim()), '组团活动已关闭，已记录运营原因。')
    if (success) {
      setActivityClosing(null)
      closeForm.resetFields()
    }
  }

  const submitParticipantRemove = async (values: ParticipantRemoveForm) => {
    if (!participantRemoving?.participant.participant_id) return
    const { activity, participant } = participantRemoving
    const success = await act(`participant-${participant.participant_id}`, () => removeCommunityActivityParticipant(activity.id, participant.participant_id as number, values.reason.trim()), '参与人已移出活动，并已收到原因通知。')
    if (success) {
      setParticipantRemoving(null)
      setActivityParticipants(null)
      participantForm.resetFields()
    }
  }

  return <>
    {contextHolder}
    <div className="page-heading">
      <div>
        <Typography.Text className="eyebrow">CAMPUS COMMUNITY</Typography.Text>
        <Typography.Title level={2}>校园社区运营</Typography.Title>
        <Typography.Paragraph type="secondary">帖子、评论、举报与申诉闭环治理；新增校园组团审核和高饱和社区首页装修。</Typography.Paragraph>
      </div>
      <Space wrap><Tag color="magenta">校园项目</Tag><Tag color="gold">高饱和装修</Tag></Space>
    </div>

    <Card bordered={false}>
      <Tabs items={[
        {
          key: 'posts',
          label: `待审帖子 (${posts.length})`,
          children: <Table rowKey="id" loading={loading} dataSource={posts} pagination={false} scroll={{ x: 900 }} locale={{ emptyText: '当前没有待审核的帖子' }} columns={[
            { title: '作者', width: 140, render: (_: unknown, row: ManagedCommunityPost) => row.author.nickname },
            { title: '标题', width: 190, dataIndex: 'title' },
            { title: '正文', dataIndex: 'content', ellipsis: true },
            { title: '互动', width: 110, render: (_: unknown, row: ManagedCommunityPost) => `${row.comment_count} 条评论` },
            { title: '版本', width: 90, render: (_: unknown, row: ManagedCommunityPost) => `v${row.current_version_no}` },
            { title: '创建时间', width: 180, dataIndex: 'created_at', render: formatDate },
            { title: '操作', width: 170, fixed: 'right', render: (_: unknown, row: ManagedCommunityPost) => contentReviewButtons(row.id, 'post') },
          ]} />,
        },
        {
          key: 'comments',
          label: `待审评论 (${comments.length})`,
          children: <Table rowKey="id" loading={loading} dataSource={comments} pagination={false} scroll={{ x: 760 }} locale={{ emptyText: '当前没有待审核的评论' }} columns={[
            { title: '作者', width: 150, render: (_: unknown, row: ManagedCommunityComment) => row.author.nickname },
            { title: '评论内容', dataIndex: 'content', ellipsis: true },
            { title: '状态', width: 110, render: (_: unknown, row: ManagedCommunityComment) => statusTag(row.status) },
            { title: '创建时间', width: 180, dataIndex: 'created_at', render: formatDate },
            { title: '操作', width: 170, fixed: 'right', render: (_: unknown, row: ManagedCommunityComment) => contentReviewButtons(row.id, 'comment') },
          ]} />,
        },
        {
          key: 'activities',
          label: `组团活动管理 (${activities.length})`,
          children: <>
            <Alert showIcon type="info" message="活动全状态运营" description="这里同时展示待审核、报名中、已驳回和已关闭活动；可查看参与名单、处理异常参与人，所有移出操作均需填写原因并写入审计。" style={{ marginBottom: 16 }} />
            <Table rowKey="id" loading={loading} dataSource={activities} pagination={{ pageSize: 20, showSizeChanger: true }} scroll={{ x: 1260 }} locale={{ emptyText: '当前没有校园组团活动' }} columns={[
              { title: '活动', width: 240, render: (_: unknown, row: ManagedCommunityActivity) => <Space direction="vertical" size={2}><strong>{row.title}</strong><Typography.Text type="secondary">{row.category_label} · {row.creator.nickname}</Typography.Text></Space> },
              { title: '时间与地点', width: 240, render: (_: unknown, row: ManagedCommunityActivity) => <Space direction="vertical" size={2}><span>{formatDate(row.starts_at)}</span><Typography.Text type="secondary">{row.location}</Typography.Text></Space> },
              { title: '人数', width: 120, render: (_: unknown, row: ManagedCommunityActivity) => <Space direction="vertical" size={2}><span>{row.participant_count} / {row.participant_limit} 人</span><Typography.Text type="secondary">余 {row.remaining_slots} 位</Typography.Text></Space> },
              { title: '活动说明', width: 260, dataIndex: 'details', ellipsis: true },
              { title: '状态', width: 110, render: (_: unknown, row: ManagedCommunityActivity) => statusTag(row.status) },
              { title: '审核意见', width: 220, render: (_: unknown, row: ManagedCommunityActivity) => row.review_note ? <Typography.Text ellipsis={{ tooltip: row.review_note }}>{row.review_note}</Typography.Text> : '—' },
              { title: '操作', width: 300, fixed: 'right', render: (_: unknown, row: ManagedCommunityActivity) => <Space wrap><Button size="small" onClick={() => setActivityParticipants(row)}>参与名单</Button>{row.status === 'pending_review' && <><Button size="small" type="primary" icon={<CheckOutlined />} loading={busy === `activity-${row.id}`} onClick={() => { reviewForm.setFieldsValue({ note: '活动内容符合校园社区规则，可公开展示。', reason_code: '' }); setActivityReview({ activity: row, decision: 'approve' }) }}>通过</Button><Button size="small" danger icon={<CloseOutlined />} loading={busy === `activity-${row.id}`} onClick={() => { reviewForm.setFieldsValue({ note: '', reason_code: 'activity_not_compliant' }); setActivityReview({ activity: row, decision: 'reject' }) }}>驳回</Button></>}{row.status === 'approved' && <Button size="small" danger icon={<StopOutlined />} loading={busy === `activity-${row.id}`} onClick={() => { closeForm.setFieldsValue({ reason: '' }); setActivityClosing(row) }}>关闭活动</Button>}</Space> },
            ]} />
          </>,
        },
        {
          key: 'reports',
          label: `举报 (${reports.length})`,
          children: <Table rowKey="id" loading={loading} dataSource={reports} pagination={false} scroll={{ x: 960 }} locale={{ emptyText: '当前没有待处理举报' }} columns={[
            { title: '内容类型', width: 120, render: (_: unknown, row: ManagedContentReport) => row.content_type === 'post' ? '帖子' : '评论' },
            { title: '举报原因', width: 170, dataIndex: 'reason_code' },
            { title: '补充说明', dataIndex: 'description', ellipsis: true },
            { title: '状态', width: 110, render: (_: unknown, row: ManagedContentReport) => statusTag(row.status) },
            { title: '创建时间', width: 180, dataIndex: 'created_at', render: formatDate },
            { title: '操作', width: 290, fixed: 'right', render: (_: unknown, row: ManagedContentReport) => <Space wrap><Button size="small" onClick={() => void act(`report-${row.id}`, () => moderateCommunityContent(row.content_type, row.content_id, 'warning'), '已向内容作者发出警告。')}>警告</Button><Button size="small" danger onClick={() => void act(`report-${row.id}`, () => moderateCommunityContent(row.content_type, row.content_id, 'hide', ['mute']), '内容已隐藏并限制互动。')}>隐藏并限制</Button><Button size="small" danger type="primary" onClick={() => void act(`report-${row.id}`, () => moderateCommunityContent(row.content_type, row.content_id, 'delete', ['post', 'comment']), '内容已删除并记录治理处置。')}>删除并限制</Button></Space> },
          ]} />,
        },
        {
          key: 'appeals',
          label: `申诉 (${appeals.length})`,
          children: <Table rowKey="id" loading={loading} dataSource={appeals} pagination={false} scroll={{ x: 820 }} locale={{ emptyText: '当前没有待处理申诉' }} columns={[
            { title: '申诉理由', dataIndex: 'reason', ellipsis: true },
            { title: '申诉状态', width: 130, render: (_: unknown, row: ManagedContentAppeal) => statusTag(row.status) },
            { title: '复核意见', width: 250, dataIndex: 'review_note', ellipsis: true },
            { title: '提交时间', width: 180, dataIndex: 'created_at', render: formatDate },
            { title: '操作', width: 220, fixed: 'right', render: (_: unknown, row: ManagedContentAppeal) => <Space><Button size="small" type="primary" onClick={() => void act(`appeal-${row.id}`, () => reviewCommunityAppeal(row.id, 'approve'), '已撤销原社区处置。')}>撤销原处置</Button><Button size="small" danger onClick={() => void act(`appeal-${row.id}`, () => reviewCommunityAppeal(row.id, 'reject'), '已维持原社区处置。')}>维持处置</Button></Space> },
          ]} />,
        },
        {
          key: 'decoration',
          label: '校园社区装修',
          children: <Card bordered={false} size="small" title="校园社区首页装修" extra={<Space><Button onClick={() => void load()}>刷新配置</Button><Button type="primary" icon={<EditOutlined />} disabled={!config} onClick={openConfig}>编辑装修</Button></Space>}>
            <Alert showIcon type="success" message="已启用高饱和校园风" description="运营人员可维护 Banner、快捷入口与热榜话题；所有图片会经素材存储统一管理，文本、颜色、启用状态和跳转会随配置版本发布。" style={{ marginBottom: 20 }} />
            {configError && <Alert showIcon type="warning" message="配置暂不可读取" description={configError} action={<Button size="small" onClick={() => void load()}>重试</Button>} style={{ marginBottom: 16 }} />}
            {config ? <CommunityHomepagePreview config={config} /> : <Empty description="正在读取校园社区装修配置" />}
          </Card>,
        },
      ]} />
    </Card>

    <Modal
      title={activityReview?.decision === 'approve' ? `通过活动：${activityReview.activity.title}` : `驳回活动：${activityReview?.activity.title ?? ''}`}
      open={activityReview !== null}
      onCancel={() => { setActivityReview(null); reviewForm.resetFields() }}
      onOk={() => reviewForm.submit()}
      okText={activityReview?.decision === 'approve' ? '确认通过' : '确认驳回'}
      okButtonProps={{ danger: activityReview?.decision === 'reject' }}
      confirmLoading={Boolean(activityReview && busy === `activity-${activityReview.activity.id}`)}
      cancelText="取消"
      destroyOnHidden
    >
      <Form form={reviewForm} layout="vertical" onFinish={submitActivityReview}>
        <Alert showIcon type={activityReview?.decision === 'approve' ? 'info' : 'warning'} message={activityReview?.decision === 'approve' ? '通过后，活动将在校园组团频道公开展示并允许符合条件的用户报名。' : '驳回原因会反馈给发起人。请写清需修改或不符合规则的具体点。'} style={{ marginBottom: 16 }} />
        {activityReview?.decision === 'reject' && <Form.Item name="reason_code" label="原因分类" rules={[{ required: true, message: '请选择驳回原因分类' }]}><Select options={[{ value: 'activity_not_compliant', label: '不符合社区规则' }, { value: 'unsafe_activity', label: '存在安全风险' }, { value: 'insufficient_information', label: '活动信息不完整' }, { value: 'other', label: '其他原因' }]} /></Form.Item>}
        <Form.Item name="note" label="审核说明" rules={[{ required: true, min: 5, message: '请填写至少 5 个字的审核说明' }]}><Input.TextArea rows={4} maxLength={500} showCount placeholder="说明审核结论或需修改的内容" /></Form.Item>
      </Form>
    </Modal>

    <Modal
      title={`关闭活动：${activityClosing?.title ?? ''}`}
      open={activityClosing !== null}
      onCancel={() => { setActivityClosing(null); closeForm.resetFields() }}
      onOk={() => closeForm.submit()}
      okText="确认关闭"
      okButtonProps={{ danger: true }}
      confirmLoading={Boolean(activityClosing && busy === `activity-${activityClosing.id}`)}
      cancelText="取消"
      destroyOnHidden
    >
      <Form form={closeForm} layout="vertical" onFinish={submitActivityClose}>
        <Alert showIcon type="warning" message="关闭后将停止新的报名。该操作会记录至审计日志，并向已报名用户展示关闭原因。" style={{ marginBottom: 16 }} />
        <Form.Item name="reason" label="关闭原因" rules={[{ required: true, min: 5, message: '请填写至少 5 个字的关闭原因' }]}><Input.TextArea rows={4} maxLength={500} showCount placeholder="例如：场地临时不可用，活动改期后将重新发布" /></Form.Item>
      </Form>
    </Modal>

    <Modal
      title={`参与名单：${activityParticipants?.title ?? ''}`}
      open={activityParticipants !== null}
      onCancel={() => setActivityParticipants(null)}
      footer={<Button onClick={() => setActivityParticipants(null)}>关闭</Button>}
      width={760}
      destroyOnHidden
    >
      <Alert showIcon type="info" message={`当前人数 ${activityParticipants?.participant_count ?? 0} / ${activityParticipants?.participant_limit ?? 0}`} description="发起人始终计入活动人数；普通参与人可在异常、骚扰或违反活动规则时由运营移出。" style={{ marginBottom: 16 }} />
      <Table rowKey={(row: ManagedCommunityActivityParticipant) => `${row.role}-${row.participant_id ?? row.user_id}`} dataSource={activityParticipants?.participants ?? []} pagination={false} locale={{ emptyText: '暂无参与记录' }} columns={[
        { title: '同学', render: (_: unknown, row: ManagedCommunityActivityParticipant) => <Space direction="vertical" size={1}><strong>{row.nickname}</strong><Typography.Text type="secondary">{row.role === 'creator' ? '活动发起人' : '参与人'}</Typography.Text></Space> },
        { title: '状态', width: 110, render: (_: unknown, row: ManagedCommunityActivityParticipant) => <Tag color={row.status === 'joined' ? 'success' : row.status === 'removed' ? 'error' : 'default'}>{row.status === 'joined' ? '参与中' : row.status === 'removed' ? '已被移出' : '已退出'}</Tag> },
        { title: '加入时间', width: 180, dataIndex: 'joined_at', render: formatDate },
        { title: '操作', width: 110, render: (_: unknown, row: ManagedCommunityActivityParticipant) => row.role === 'participant' && row.status === 'joined' && activityParticipants ? <Button size="small" danger loading={busy === `participant-${row.participant_id}`} onClick={() => { participantForm.setFieldsValue({ reason: '' }); setParticipantRemoving({ activity: activityParticipants, participant: row }) }}>移出活动</Button> : '—' },
      ]} />
    </Modal>

    <Modal
      title={`移出参与人：${participantRemoving?.participant.nickname ?? ''}`}
      open={participantRemoving !== null}
      onCancel={() => { setParticipantRemoving(null); participantForm.resetFields() }}
      onOk={() => participantForm.submit()}
      okText="确认移出"
      okButtonProps={{ danger: true }}
      confirmLoading={Boolean(participantRemoving && busy === `participant-${participantRemoving.participant.participant_id}`)}
      cancelText="取消"
      destroyOnHidden
    >
      <Form form={participantForm} layout="vertical" onFinish={submitParticipantRemove}>
        <Alert showIcon type="warning" message="该同学将无法自行重新加入本活动" description="移出原因会通过站内消息发送给用户，并记录在后台审计日志中。" style={{ marginBottom: 16 }} />
        <Form.Item name="reason" label="移出原因" rules={[{ required: true, min: 5, message: '请填写至少 5 个字的移出原因' }]}><Input.TextArea rows={4} maxLength={500} showCount placeholder="例如：多次发布无关广告，已影响活动正常组织" /></Form.Item>
      </Form>
    </Modal>

    <Modal
      title="编辑校园社区装修"
      open={configOpen}
      onCancel={() => setConfigOpen(false)}
      onOk={() => configForm.submit()}
      okText="保存并发布"
      cancelText="取消"
      confirmLoading={configSaving}
      width={1120}
      styles={{ body: { maxHeight: '72vh', overflowY: 'auto', paddingRight: 8 } }}
      destroyOnHidden
    >
      <Form form={configForm} layout="vertical" onFinish={saveConfig}>
        <Alert showIcon type="info" message="保存会发布新配置版本" description="活动审核、社区治理与装修配置均会记录运营理由。图片使用“从电脑上传”或“图片网址导入”后，会自动转存到当前启用的素材存储。" style={{ marginBottom: 18 }} />
        <Card size="small" title="校园 Banner" style={{ marginBottom: 16 }}>
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(240px,1fr))', gap: 12 }}>
            <Form.Item name={['banner', 'title']} label="主标题" rules={[{ required: true, message: '请输入 Banner 主标题' }]}><Input maxLength={80} placeholder="例如：校园互助社区" /></Form.Item>
            <Form.Item name={['banner', 'subtitle']} label="副标题" rules={[{ required: true, message: '请输入 Banner 副标题' }]}><Input maxLength={160} placeholder="例如：求助、经验、组团，让校园生活更简单" /></Form.Item>
            <Form.Item name={['banner', 'link_type']} label="点击跳转" rules={[{ required: true }]}><Select options={bannerLinkOptions} /></Form.Item>
            <Form.Item name={['banner', 'accent_color']} label="高饱和强调色" rules={[{ pattern: /^#[0-9A-Fa-f]{6}$/, message: '请填写 #RRGGBB 格式的颜色值' }]}><Input maxLength={7} placeholder="#FFE13B" /></Form.Item>
          </div>
          <Typography.Text strong>Banner 背景图</Typography.Text>
          <CommunityConfigImageInput form={configForm} path={['banner', 'image_url']} label="校园 Banner" helperText="不上传时使用高饱和色块；上传后作为 Banner 背景图，文字仍保持可读。" />
        </Card>

        <Form.List name="quick_entries" rules={[{ validator: async (_, items: CommunityConfigForm['quick_entries']) => {
          if (!items?.length) throw new Error('至少保留一个快捷入口')
          if (items.length > 8) throw new Error('快捷入口最多 8 个')
          if (new Set(items.map((item) => item.id.trim())).size !== items.length) throw new Error('快捷入口标识不能重复')
        } }]}>
          {(fields, { add, remove, move }) => <Card size="small" title="快捷入口" extra={<Button type="dashed" icon={<PlusOutlined />} onClick={() => add({ id: `entry-${Date.now()}`, label: '新快捷入口', description: '', icon_url: '', target: 'posts', enabled: true, color: campusColors[fields.length % campusColors.length] })}>新增入口</Button>} style={{ marginBottom: 16 }}>
            <Typography.Paragraph type="secondary">用于社区首页的圆角高饱和图标入口。顺序决定移动端展示顺序，最多展示前 8 个。</Typography.Paragraph>
            <Space direction="vertical" size={12} style={{ width: '100%' }}>
              {fields.map((field, index) => <Card key={field.key} size="small" title={`入口 ${index + 1}`} extra={<Space><Button size="small" icon={<ArrowUpOutlined />} disabled={index === 0} onClick={() => move(index, index - 1)}>上移</Button><Button size="small" icon={<ArrowDownOutlined />} disabled={index === fields.length - 1} onClick={() => move(index, index + 1)}>下移</Button><Button size="small" danger icon={<DeleteOutlined />} onClick={() => remove(field.name)}>删除</Button></Space>}>
                <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(200px,1fr))', gap: 12 }}>
                  <Form.Item name={[field.name, 'id']} label="入口标识" rules={[{ required: true, message: '请输入入口标识' }, { pattern: /^[a-z0-9_-]+$/i, message: '仅允许字母、数字、下划线和短横线' }]}><Input maxLength={32} /></Form.Item>
                  <Form.Item name={[field.name, 'label']} label="名称" rules={[{ required: true, message: '请输入入口名称' }]}><Input maxLength={24} /></Form.Item>
                  <Form.Item name={[field.name, 'target']} label="点击跳转" rules={[{ required: true }]}><Select options={quickTargetOptions} /></Form.Item>
                  <Form.Item name={[field.name, 'color']} label="高饱和颜色" rules={[{ pattern: /^#[0-9A-Fa-f]{6}$/, message: '请填写 #RRGGBB 格式的颜色值' }]}><Input maxLength={7} placeholder="#20D96B" /></Form.Item>
                  <Form.Item name={[field.name, 'description']} label="说明"><Input maxLength={60} placeholder="例如：分享、吐槽、求助" /></Form.Item>
                  <Form.Item name={[field.name, 'enabled']} label="显示在社区首页" valuePropName="checked"><Switch checkedChildren="显示" unCheckedChildren="隐藏" /></Form.Item>
                </div>
                <div style={{ marginTop: 12 }}><Typography.Text strong>入口图标</Typography.Text><CommunityConfigImageInput form={configForm} path={['quick_entries', field.name, 'icon_url']} label={`快捷入口 ${index + 1}`} helperText="建议上传透明背景的正方形 PNG 或 WebP；未上传时使用入口名称首字作为默认图标。" /></div>
              </Card>)}
            </Space>
          </Card>}
        </Form.List>

        <Form.List name="hot_topics" rules={[{ validator: async (_, items: CommunityConfigForm['hot_topics']) => {
          if (!items?.length) throw new Error('至少保留一个热榜话题')
          if (items.length > 12) throw new Error('热榜话题最多 12 个')
          if (new Set(items.map((item) => item.id.trim())).size !== items.length) throw new Error('热榜话题标识不能重复')
        } }]}>
          {(fields, { add, remove, move }) => <Card size="small" title="校园热榜话题" extra={<Button type="dashed" icon={<PlusOutlined />} onClick={() => add({ id: `topic-${Date.now()}`, label: '#校园新话题', target: 'campus', enabled: true, color: campusColors[(fields.length + 4) % campusColors.length] })}>新增话题</Button>} style={{ marginBottom: 16 }}>
            <Typography.Paragraph type="secondary">热榜话题会以彩色标签展示在社区首页，可用于聚合同类帖子内容。</Typography.Paragraph>
            <Space direction="vertical" size={12} style={{ width: '100%' }}>
              {fields.map((field, index) => <Card key={field.key} size="small" title={`话题 ${index + 1}`} extra={<Space><Button size="small" icon={<ArrowUpOutlined />} disabled={index === 0} onClick={() => move(index, index - 1)}>上移</Button><Button size="small" icon={<ArrowDownOutlined />} disabled={index === fields.length - 1} onClick={() => move(index, index + 1)}>下移</Button><Button size="small" danger icon={<DeleteOutlined />} onClick={() => remove(field.name)}>删除</Button></Space>}>
                <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(190px,1fr))', gap: 12 }}>
                  <Form.Item name={[field.name, 'id']} label="话题标识" rules={[{ required: true, message: '请输入话题标识' }]}><Input maxLength={32} /></Form.Item>
                  <Form.Item name={[field.name, 'label']} label="用户端文案" rules={[{ required: true, message: '请输入用户端文案' }]}><Input maxLength={32} placeholder="#校园互助" /></Form.Item>
                  <Form.Item name={[field.name, 'target']} label="内容筛选标识" rules={[{ required: true, message: '请输入内容筛选标识' }]}><Input maxLength={32} placeholder="campus-help" /></Form.Item>
                  <Form.Item name={[field.name, 'color']} label="高饱和颜色" rules={[{ pattern: /^#[0-9A-Fa-f]{6}$/, message: '请填写 #RRGGBB 格式的颜色值' }]}><Input maxLength={7} placeholder="#FF5D5D" /></Form.Item>
                  <Form.Item name={[field.name, 'enabled']} label="显示在校园热榜" valuePropName="checked"><Switch checkedChildren="显示" unCheckedChildren="隐藏" /></Form.Item>
                </div>
              </Card>)}
            </Space>
          </Card>}
        </Form.List>

        <Form.Item name="reason" label="本次发布说明" rules={[{ required: true, min: 5, message: '请填写至少 5 个字的发布说明' }]} extra="会写入配置版本与后台审计记录。"><Input.TextArea rows={3} maxLength={500} showCount placeholder="例如：迎新季启用校园绿色 Banner，新增自习搭子和校园拼车入口。" /></Form.Item>
      </Form>
    </Modal>
  </>
}
