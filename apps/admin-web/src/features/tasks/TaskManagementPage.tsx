import { CheckOutlined, CloseOutlined, PlusOutlined, RocketOutlined } from '@ant-design/icons'
import { Alert, Button, Card, Checkbox, Divider, Form, Input, InputNumber, Modal, Select, Space, Table, Tag, Typography, message } from 'antd'
import { useCallback, useEffect, useState } from 'react'
import {
  ApiError,
  createManagedTask,
  createRewardBudgetPool,
  listManagedTasks,
  listRewardBudgetPools,
  listTaskSubmissions,
  listTaskAppeals,
  listTaskRiskCases,
  publishManagedTask,
  reviewManagedTaskPublish,
  reviewTaskSubmission,
  reviewTaskAppeal,
  reviewTaskRiskCase,
  verifyAdminRecent,
  type ManagedTask,
  type ManagedTaskSubmission,
  type ManagedTaskAppeal,
  type ManagedTaskRiskCase,
  type RewardBudgetPool,
  type ManagedTaskStep,
} from '../../api/admin'
import { useAdminAuth } from '../auth/AdminAuthContext'
import { RichTextEditor } from '../../components/RichTextEditor'
import { MediaGalleryInput, type GalleryImageValue } from '../../components/MediaGalleryInput'
import { TaskStepBuilder } from './TaskStepBuilder'

interface PoolForm { name: string; asset_type: 'points' | 'wallet_cash'; total_amount: number; reason: string; password: string; mfa_code?: string }
interface TaskForm { project_name?: string; cover_images: GalleryImageValue[]; title: string; description: string; category: string; tags: string[]; task_type: string; supported_devices: string[]; points: number; points_pool_id: string; wallet_amount?: number; wallet_pool_id?: string; quota: number; virtual_view_count: number; completion_window_hours: number; submission_limit: number; review_deadline_hours: number; starts_at: string; claim_ends_at: string; submit_ends_at: string; steps: ManagedTaskStep[] }
interface ApprovalForm { note: string; password: string; mfa_code?: string }
interface SubmissionReviewForm { reason_code?: string; note: string }

const taskCategoryOptions = [
  { value: 'onboarding', label: '新手任务' },
  { value: 'daily', label: '日常任务' },
  { value: 'community', label: '社区任务' },
  { value: 'commerce', label: '商城任务' },
  { value: 'research', label: '调研任务' },
  { value: 'campaign', label: '活动任务' },
]

const taskCategoryLabels = Object.fromEntries(taskCategoryOptions.map((item) => [item.value, item.label])) as Record<string, string>

const defaultTaskSteps: ManagedTaskStep[] = [{ id: 'step-result', kind: 'verification_image', title: '上传完成截图', description: '请上传能够证明任务已完成的清晰截图，敏感信息可先打码。', input_type: 'image', required: true, min_files: 1, max_files: 3 }]

function validateTaskSteps(steps: ManagedTaskStep[] = []) {
  if (!steps.length) return Promise.reject(new Error('至少添加一个任务步骤'))
  for (let index = 0; index < steps.length; index += 1) {
    const step = steps[index]
    if (!step.title.trim() || !(step.description ?? '').trim()) return Promise.reject(new Error(`请完整填写第 ${index + 1} 步的标题和说明`))
    if (step.kind === 'link' && !step.link_url?.startsWith('https://')) return Promise.reject(new Error(`第 ${index + 1} 步必须填写 HTTPS 地址`))
    if (step.kind === 'copy_text' && !step.copy_text?.trim()) return Promise.reject(new Error(`第 ${index + 1} 步必须填写复制内容`))
    if (['qr_code', 'image_guide'].includes(step.kind) && !step.example_image_url) return Promise.reject(new Error(`第 ${index + 1} 步必须上传图片`))
    if (step.kind === 'verification_image' && (step.min_files ?? 1) > (step.max_files ?? 3)) return Promise.reject(new Error(`第 ${index + 1} 步的最少上传数不能大于最多上传数`))
  }
  return Promise.resolve()
}

const taskStatusMeta: Record<string, { label: string; color: string }> = {
  draft: { label: '草稿', color: 'default' },
  pending_approval: { label: '待财务复核', color: 'processing' },
  scheduled: { label: '已排期', color: 'blue' },
  published: { label: '已发布', color: 'success' },
  paused: { label: '已暂停', color: 'warning' },
  ended: { label: '已结束', color: 'default' },
  cancelled: { label: '已取消', color: 'error' },
}

const approvalStatusLabels: Record<string, string> = {
  pending: '待复核', approved: '已批准', rejected: '已拒绝', cancelled: '已取消',
}

export function TaskManagementPage() {
  const { admin } = useAdminAuth()
  const [tasks, setTasks] = useState<ManagedTask[]>([])
  const [pools, setPools] = useState<RewardBudgetPool[]>([])
  const [submissions, setSubmissions] = useState<ManagedTaskSubmission[]>([])
  const [appeals, setAppeals] = useState<ManagedTaskAppeal[]>([])
  const [riskCases, setRiskCases] = useState<ManagedTaskRiskCase[]>([])
  const [loading, setLoading] = useState(false)
  const [poolOpen, setPoolOpen] = useState(false)
  const [taskOpen, setTaskOpen] = useState(false)
  const [approvalTask, setApprovalTask] = useState<ManagedTask | null>(null)
  const [approvalDecision, setApprovalDecision] = useState<'approve' | 'reject'>('approve')
  const [reviewSubmission, setReviewSubmission] = useState<ManagedTaskSubmission | null>(null)
  const [submissionDecision, setSubmissionDecision] = useState<'approve' | 'reject'>('approve')
  const [submitting, setSubmitting] = useState(false)
  const [poolForm] = Form.useForm<PoolForm>()
  const [taskForm] = Form.useForm<TaskForm>()
  const [approvalForm] = Form.useForm<ApprovalForm>()
  const [submissionReviewForm] = Form.useForm<SubmissionReviewForm>()
  const [messageApi, contextHolder] = message.useMessage()

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const canReview = admin?.permissions.includes('task.review') ?? false
      const [taskResult, poolResult, submissionResult, appealResult, riskResult] = await Promise.all([listManagedTasks(), listRewardBudgetPools(), canReview ? listTaskSubmissions() : Promise.resolve({ items: [] }), canReview ? listTaskAppeals() : Promise.resolve({ items: [] }), canReview ? listTaskRiskCases() : Promise.resolve({ items: [] })])
      setTasks(taskResult.items)
      setPools(poolResult.items)
      setSubmissions(submissionResult.items)
      setAppeals(appealResult.items)
      setRiskCases(riskResult.items)
    } catch (cause) { messageApi.error(cause instanceof ApiError ? cause.message : '任务数据加载失败。') }
    finally { setLoading(false) }
  }, [admin?.permissions, messageApi])
  useEffect(() => { void load() }, [load])
  const canFinance = admin?.permissions.includes('finance.manage') ?? false
  const canReview = admin?.permissions.includes('task.review') ?? false

  const createPool = async (values: PoolForm) => {
    setSubmitting(true)
    try {
      await verifyAdminRecent(values.password, values.mfa_code)
      await createRewardBudgetPool(values)
      setPoolOpen(false); poolForm.resetFields(); await load(); messageApi.success('预算池已创建。')
    } catch (cause) { messageApi.error(cause instanceof ApiError ? cause.message : '预算池创建失败。') }
    finally { setSubmitting(false) }
  }

  const createTask = async (values: TaskForm) => {
    if (values.wallet_amount && !values.wallet_pool_id) {
      messageApi.error('填写余额奖励后，必须选择余额预算池；如不发放余额，请清空“每次余额”。')
      return
    }
    if (!values.wallet_amount && values.wallet_pool_id) {
      messageApi.error('选择余额预算池后，必须填写每次余额奖励。')
      return
    }

    const startsAt = new Date(values.starts_at)
    const claimEndsAt = new Date(values.claim_ends_at)
    const submitEndsAt = new Date(values.submit_ends_at)
    if ([startsAt, claimEndsAt, submitEndsAt].some((value) => Number.isNaN(value.getTime()))) {
      messageApi.error('请完整填写领取开始、领取截止和提交截止时间。')
      return
    }
    if (!(startsAt < claimEndsAt && claimEndsAt <= submitEndsAt)) {
      messageApi.error('时间必须满足：领取开始 < 领取截止 ≤ 提交截止。')
      return
    }

    try {
      await validateTaskSteps(values.steps)
    } catch (cause) {
      messageApi.error(cause instanceof Error ? cause.message : '请检查任务步骤配置。')
      return
    }
    const rewards: Array<Record<string, unknown>> = [{ type: 'points', points: values.points }]
    const rewardBudgets = [{ reward_sequence: 1, budget_pool_id: values.points_pool_id }]
    if (values.wallet_amount && values.wallet_pool_id) {
      rewards.push({ type: 'wallet_balance', balance_amount: values.wallet_amount })
      rewardBudgets.push({ reward_sequence: 2, budget_pool_id: values.wallet_pool_id })
    }
    setSubmitting(true)
    try {
      await createManagedTask({
        business_scenario: 'platform_task', project_name: values.project_name?.trim() || null, cover_image_url: values.cover_images[0]?.url ?? null,
        supported_devices: values.supported_devices, visibility: 'public', category: values.category, task_type: values.task_type, participation_mode: 'claim_and_submit',
        verification_mode: 'manual', title: values.title, description: values.description,
        tags: [...new Set((values.tags ?? []).map((tag) => tag.trim()).filter(Boolean))], rewards,
        reward_budgets: rewardBudgets, reward_quota_total: values.quota, reward_quota_per_cycle: values.task_type === 'one_time' ? null : values.quota,
        virtual_view_count: values.virtual_view_count,
        review_deadline_hours: values.review_deadline_hours, appeal_deadline_days: 7, appeal_limit: 1,
        completion_window_hours: values.completion_window_hours, submission_limit: values.submission_limit,
        attachment_rules: { max_count: 20, max_images: 20, max_image_bytes: 10485760 }, steps: values.steps,
        claim_starts_at: startsAt.toISOString(), claim_ends_at: claimEndsAt.toISOString(),
        submit_ends_at: submitEndsAt.toISOString(), rules: [],
      })
      setTaskOpen(false); taskForm.resetFields(); await load(); messageApi.success('任务草稿已创建。')
    } catch (cause) { messageApi.error(cause instanceof ApiError ? cause.message : '任务草稿创建失败。') }
    finally { setSubmitting(false) }
  }

  const publish = async (task: ManagedTask) => {
    Modal.confirm({
      title: `发布“${task.title}”`, content: '发布会原子锁定所有奖励预算；任一组件不足都会整体失败。',
      onOk: async () => {
        const result = await publishManagedTask(task.id, '后台确认内容与预算后发布。'); await load()
        messageApi.success(result.status === 'pending_approval' ? '已提交财务二次复核，暂未锁定预算。' : '任务已发布或进入排期。')
      },
    })
  }

  const reviewApproval = async (values: ApprovalForm) => {
    if (!approvalTask) return
    setSubmitting(true)
    try {
      await verifyAdminRecent(values.password, values.mfa_code)
      await reviewManagedTaskPublish(approvalTask.id, approvalDecision, values.note)
      setApprovalTask(null); approvalForm.resetFields(); await load()
      messageApi.success(approvalDecision === 'approve' ? '财务复核已批准，任务可继续发布。' : '财务复核已拒绝。')
    } catch (cause) { messageApi.error(cause instanceof ApiError ? cause.message : '财务复核失败。') }
    finally { setSubmitting(false) }
  }

  const submitReview = async (values: SubmissionReviewForm) => {
    if (!reviewSubmission) return
    setSubmitting(true)
    try {
      await reviewTaskSubmission(reviewSubmission.id, submissionDecision, values.reason_code || null, values.note || '')
      setReviewSubmission(null); submissionReviewForm.resetFields(); await load()
      messageApi.success(submissionDecision === 'approve' ? '任务已通过，奖励进入独立发放流程。' : '任务已驳回，用户可在剩余次数和期限内重新提交。')
    } catch (cause) { messageApi.error(cause instanceof ApiError ? cause.message : '任务提交审核失败。') }
    finally { setSubmitting(false) }
  }

  return <>
    {contextHolder}
    <div className="page-heading"><div><Typography.Text className="eyebrow">TASK FOUNDATION</Typography.Text><Typography.Title level={2}>任务与奖励预算</Typography.Title><Typography.Paragraph type="secondary">草稿版本不可覆盖；发布时由服务端重新计算并全量锁定积分、优惠券和余额预算。</Typography.Paragraph></div><Space>{canFinance && <Button icon={<PlusOutlined />} onClick={() => setPoolOpen(true)}>新建预算池</Button>}<Button type="primary" icon={<PlusOutlined />} onClick={() => setTaskOpen(true)}>新建任务</Button></Space></div>
    <Alert showIcon type="info" message="单次余额奖励超过 200 元或任务余额总预算超过 5000 元时，先由另一位财务管理员复核；批准后再次发布才会原子锁定全部预算。" />
    <Card bordered={false} style={{ marginTop: 16 }} title="平台预算池">
      <Table rowKey="id" loading={loading} dataSource={pools} pagination={false} columns={[
        { title: '名称', dataIndex: 'name' }, { title: '类型', dataIndex: 'asset_type' }, { title: '总量', dataIndex: 'total_amount' },
        { title: '已锁定', dataIndex: 'locked_amount' }, { title: '可用', dataIndex: 'available_amount' }, { title: '版本', dataIndex: 'version' },
      ]} />
    </Card>
    <Card bordered={false} style={{ marginTop: 16 }} title="任务草稿与版本">
      <Table rowKey="id" loading={loading} dataSource={tasks} pagination={false} columns={[
        { title: '任务', dataIndex: 'title', render: (value: string, item: ManagedTask) => <Space direction="vertical" size={0}><strong>{value}</strong><Typography.Text type="secondary">v{item.task_version.version_no} · {item.task_version.change_type}</Typography.Text></Space> },
        { title: '分类', dataIndex: 'category', render: (value: string) => taskCategoryLabels[value] ?? value }, { title: '奖励次数', dataIndex: 'reward_quota_total' },
        { title: '状态', dataIndex: 'status', render: (value: string, item: ManagedTask) => { const meta = taskStatusMeta[value] ?? { label: value, color: 'default' }; return <Space direction="vertical" size={0}><Tag color={meta.color}>{meta.label}</Tag>{item.publish_approval && <Typography.Text type="secondary">财务复核：{approvalStatusLabels[item.publish_approval.status] ?? item.publish_approval.status}</Typography.Text>}</Space> } },
        { title: '操作', render: (_: unknown, item: ManagedTask) => <Space wrap><Button icon={<RocketOutlined />} disabled={item.status !== 'draft' && item.publish_approval?.status !== 'approved'} onClick={() => void publish(item)}>{item.publish_approval?.status === 'approved' ? '复核后发布' : '发布并锁定预算'}</Button>{canFinance && item.publish_approval?.status === 'pending' && <><Button icon={<CheckOutlined />} onClick={() => { setApprovalDecision('approve'); setApprovalTask(item) }}>批准</Button><Button danger icon={<CloseOutlined />} onClick={() => { setApprovalDecision('reject'); setApprovalTask(item) }}>拒绝</Button></>}</Space> },
      ]} />
    </Card>
    {canReview && <Card bordered={false} style={{ marginTop: 16 }} title="待审核任务提交">
      <Alert showIcon type="info" message="审核期限逾期只提醒，不会自动通过；通过后各奖励组件独立发放，失败组件进入重试队列。" style={{ marginBottom: 16 }} />
      <Table rowKey="id" loading={loading} dataSource={submissions} pagination={false} columns={[
        { title: '任务', dataIndex: 'task_title' },
        { title: '用户', dataIndex: 'user_id' },
        { title: '提交', render: (_: unknown, item: ManagedTaskSubmission) => <Space direction="vertical" size={0}><span>第 {item.submission_no} 次</span><Typography.Text type="secondary">{item.content?.text || `${item.file_ids.length} 个附件`}</Typography.Text></Space> },
        { title: '审核期限', dataIndex: 'review_due_at', render: (value: string, item: ManagedTaskSubmission) => <Tag color={item.overdue ? 'error' : 'processing'}>{new Date(value).toLocaleString()}</Tag> },
        { title: '操作', render: (_: unknown, item: ManagedTaskSubmission) => <Space><Button type="primary" icon={<CheckOutlined />} onClick={() => { setSubmissionDecision('approve'); setReviewSubmission(item) }}>通过</Button><Button danger icon={<CloseOutlined />} onClick={() => { setSubmissionDecision('reject'); setReviewSubmission(item) }}>驳回</Button></Space> },
      ]} />
    </Card>}
    {canReview && <Card bordered={false} style={{ marginTop: 16 }} title="任务申诉（原审核人不可复核）">
      <Table rowKey="id" dataSource={appeals} pagination={false} columns={[
        { title: '领取记录', dataIndex: 'claim_id' }, { title: '申诉原因', dataIndex: 'reason' }, { title: '提交时间', dataIndex: 'created_at', render: (value: string) => new Date(value).toLocaleString() },
        { title: '操作', render: (_: unknown, item: ManagedTaskAppeal) => <Space><Button type="primary" onClick={async () => { await reviewTaskAppeal(item.id, 'approve', '异人复核确认原结论需纠正'); await load() }}>通过</Button><Button danger onClick={async () => { await reviewTaskAppeal(item.id, 'reject', '异人复核维持原审核结论'); await load() }}>驳回</Button></Space> },
      ]} />
    </Card>}
    {canReview && <Card bordered={false} style={{ marginTop: 16 }} title="任务风险案件">
      <Table rowKey="id" dataSource={riskCases} pagination={false} columns={[
        { title: '任务', dataIndex: 'task_id' }, { title: '来源', dataIndex: 'source' }, { title: '等级', dataIndex: 'risk_level', render: (value: string) => <Tag color={value === 'high' ? 'error' : 'warning'}>{value}</Tag> },
        { title: '响应期限', dataIndex: 'response_due_at', render: (value: string, item: ManagedTaskRiskCase) => <Tag color={item.overdue ? 'error' : 'processing'}>{new Date(value).toLocaleString()}</Tag> },
        { title: '操作', render: (_: unknown, item: ManagedTaskRiskCase) => <Space><Button onClick={async () => { await reviewTaskRiskCase(item.id, 'clear', ['restore'], '人工核查未发现违规'); await load() }}>解除</Button><Button danger onClick={async () => { await reviewTaskRiskCase(item.id, 'confirm_violation', ['stop_claiming', 'freeze_reward'], '确认违规并暂停领取'); await load() }}>确认违规</Button></Space> },
      ]} />
    </Card>}
    <Modal title="新建平台预算池" open={poolOpen} onCancel={() => setPoolOpen(false)} onOk={() => poolForm.submit()} confirmLoading={submitting} destroyOnHidden><Form form={poolForm} layout="vertical" onFinish={createPool} initialValues={{ asset_type: 'points' }}><Form.Item name="name" label="名称" rules={[{ required: true }]}><Input /></Form.Item><Form.Item name="asset_type" label="资产类型" rules={[{ required: true }]}><Select options={[{ value: 'points', label: '积分' }, { value: 'wallet_cash', label: '余额（分）' }]} /></Form.Item><Form.Item name="total_amount" label="总预算" rules={[{ required: true }]}><InputNumber min={1} style={{ width: '100%' }} /></Form.Item><Form.Item name="reason" label="创建原因" rules={[{ required: true }]}><Input /></Form.Item><Form.Item name="password" label="管理员密码（二次验证）" rules={[{ required: true }]}><Input.Password /></Form.Item><Form.Item name="mfa_code" label="MFA 动态码"><Input /></Form.Item></Form></Modal>
    <Modal title="新建任务草稿" width={1080} open={taskOpen} onCancel={() => setTaskOpen(false)} onOk={() => taskForm.submit()} okText="创建草稿" cancelText="取消" confirmLoading={submitting} destroyOnHidden styles={{ body: { maxHeight: '76vh', overflowY: 'auto', paddingRight: 10 } }}>
      <Form form={taskForm} layout="vertical" onFinish={createTask} initialValues={{ category: 'campaign', tags: [], task_type: 'one_time', supported_devices: ['android', 'ios'], quota: 100, points: 10, virtual_view_count: 0, completion_window_hours: 24, submission_limit: 3, review_deadline_hours: 48, cover_images: [], steps: defaultTaskSteps }}>
        <Alert showIcon type="info" message="任务说明负责整体背景，任务步骤负责可执行流程和审核材料。发布后步骤会进入不可变版本快照，已领取用户不会受后续修改影响。" style={{ marginBottom: 18 }} />
        <Divider titlePlacement="start">一、基础信息</Divider>
        <Space align="start" wrap style={{ width: '100%' }}><Form.Item name="project_name" label="项目名称"><Input style={{ width: 250 }} maxLength={120} placeholder="用于后台归类，可选" /></Form.Item><Form.Item name="title" label="任务标题" rules={[{ required: true }]}><Input style={{ width: 420 }} maxLength={160} /></Form.Item><Form.Item name="category" label="任务分类" rules={[{ required: true }]}><Select style={{ width: 180 }} options={taskCategoryOptions} /></Form.Item></Space>
        <Form.Item name="tags" label="任务标签（选填）" extra="用于搜索、筛选和运营分组；输入后按回车创建标签，最多 10 个。" rules={[{ validator: async (_, tags: string[] = []) => { if (tags.length > 10) throw new Error('最多添加 10 个任务标签'); if (tags.some((tag) => tag.trim().length > 30)) throw new Error('每个标签不能超过 30 个字符') } }]}><Select mode="tags" tokenSeparators={[',', '，']} placeholder="例如：新手、限时、调研" style={{ width: '100%' }} /></Form.Item>
        <Form.Item name="cover_images" label="任务封面图" required extra="支持电脑本地上传和图片网址导入，导入后统一保存到当前素材存储。" rules={[{ validator: async (_, images) => { if (!images?.length) throw new Error('请上传任务封面图') } }]}><MediaGalleryInput max={1} primaryLabel="任务封面" itemLabel="任务图片" /></Form.Item>
        <Form.Item name="description" label="任务说明" rules={[{ required: true, message: '请输入任务说明' }]}><RichTextEditor placeholder="填写参与条件、整体背景、注意事项和审核原则；具体操作请放到任务步骤中。" minHeight={240} /></Form.Item>
        <Space align="start" wrap><Form.Item name="task_type" label="参与频率" rules={[{ required: true }]}><Select style={{ width: 180 }} options={[{ value: 'one_time', label: '每人一次' }, { value: 'daily', label: '每日一次' }, { value: 'weekly', label: '每周一次' }, { value: 'repeatable', label: '可重复领取' }]} /></Form.Item><Form.Item name="supported_devices" label="支持设备" rules={[{ required: true, type: 'array', min: 1 }]}><Checkbox.Group options={[{ label: 'Android', value: 'android' }, { label: 'iOS / 微信', value: 'ios' }]} /></Form.Item></Space>
        <Divider titlePlacement="start">二、奖励、名额与时限</Divider>
        <Space align="start" wrap><Form.Item name="points" label="每次积分"><InputNumber min={1} /></Form.Item><Form.Item name="points_pool_id" label="积分预算池" rules={[{ required: true }]}><Select style={{ width: 240 }} options={pools.filter((pool) => pool.asset_type === 'points').map((pool) => ({ value: pool.id, label: `${pool.name}（可用 ${pool.available_amount}）` }))} /></Form.Item><Form.Item name="wallet_amount" label="每次余额（分，选填）" dependencies={['wallet_pool_id']} rules={[({ getFieldValue }) => ({ validator: async (_, value) => { if (value && !getFieldValue('wallet_pool_id')) throw new Error('请选择余额预算池') } })]}><InputNumber min={1} placeholder="不发余额请留空" /></Form.Item><Form.Item name="wallet_pool_id" label="余额预算池（选填）" dependencies={['wallet_amount']} rules={[({ getFieldValue }) => ({ validator: async (_, value) => { if (value && !getFieldValue('wallet_amount')) throw new Error('请填写每次余额奖励') } })]}><Select allowClear placeholder={pools.some((pool) => pool.asset_type === 'wallet_cash') ? '发余额时必选' : '暂无余额预算池'} disabled={!pools.some((pool) => pool.asset_type === 'wallet_cash')} style={{ width: 240 }} options={pools.filter((pool) => pool.asset_type === 'wallet_cash').map((pool) => ({ value: pool.id, label: `${pool.name}（可用 ${pool.available_amount}）` }))} /></Form.Item><Form.Item name="quota" label="奖励总名额" rules={[{ required: true }]}><InputNumber min={1} /></Form.Item><Form.Item name="virtual_view_count" label="虚拟浏览数" extra="只作为前端热度基数，不影响真实领取、完成、名额和财务统计。" rules={[{ required: true }]}><InputNumber min={0} max={100000000} precision={0} style={{ width: 150 }} /></Form.Item></Space>
        <Space wrap align="start"><Form.Item name="completion_window_hours" label="领取后完成时限（小时）" rules={[{ required: true }]}><InputNumber min={1} max={720} /></Form.Item><Form.Item name="submission_limit" label="最多提交次数" rules={[{ required: true }]}><InputNumber min={1} max={20} /></Form.Item><Form.Item name="review_deadline_hours" label="审核期限（小时）" rules={[{ required: true }]}><InputNumber min={1} max={720} /></Form.Item></Space>
        <Space wrap><Form.Item name="starts_at" label="领取开始" rules={[{ required: true }]}><Input type="datetime-local" /></Form.Item><Form.Item name="claim_ends_at" label="领取截止" rules={[{ required: true }]}><Input type="datetime-local" /></Form.Item><Form.Item name="submit_ends_at" label="全局最晚提交" rules={[{ required: true }]}><Input type="datetime-local" /></Form.Item></Space>
        <Divider titlePlacement="start">三、自定义任务步骤</Divider>
        <Form.Item name="steps"><TaskStepBuilder /></Form.Item>
      </Form>
    </Modal>
    <Modal title={approvalDecision === 'approve' ? '批准任务发布' : '拒绝任务发布'} open={approvalTask !== null} onCancel={() => setApprovalTask(null)} onOk={() => approvalForm.submit()} confirmLoading={submitting} destroyOnHidden><Form form={approvalForm} layout="vertical" onFinish={reviewApproval}><Alert showIcon type="warning" message="必须由非申请人的财务管理员完成，并进行密码/MFA 二次验证。" style={{ marginBottom: 16 }} /><Form.Item name="note" label="复核意见" rules={[{ required: true }]}><Input.TextArea rows={3} /></Form.Item><Form.Item name="password" label="管理员密码" rules={[{ required: true }]}><Input.Password /></Form.Item><Form.Item name="mfa_code" label="MFA 动态码"><Input /></Form.Item></Form></Modal>
    <Modal title={submissionDecision === 'approve' ? '通过任务提交' : '驳回任务提交'} open={reviewSubmission !== null} onCancel={() => setReviewSubmission(null)} onOk={() => submissionReviewForm.submit()} confirmLoading={submitting} destroyOnHidden>
      <Form form={submissionReviewForm} layout="vertical" onFinish={submitReview}>
        {reviewSubmission && <Card size="small" title="按步骤核验材料" style={{ marginBottom: 16 }}><Space direction="vertical" style={{ width: '100%' }}>
          {reviewSubmission.task_steps.map((step, index) => {
            const fields = reviewSubmission.content?.fields ?? {}
            const answers = (fields.steps ?? {}) as Record<string, string>
            const checks = (fields.completed_steps ?? {}) as Record<string, boolean>
            const stepFiles = (fields.step_files ?? {}) as Record<string, string[]>
            return <div key={step.id} style={{ paddingBottom: 10, borderBottom: '1px solid #f0f0f0' }}><Space><Tag>{index + 1}</Tag><strong>{step.title}</strong>{step.required && <Tag color="red">必填</Tag>}</Space><Typography.Paragraph style={{ margin: '6px 0 0' }}>{answers[step.id] || (checks[step.id] ? '用户已确认完成' : '无文字结果')}</Typography.Paragraph><Typography.Text type="secondary">凭证 {stepFiles[step.id]?.length ?? 0} 个</Typography.Text></div>
          })}
          <Typography.Text type="secondary">本次提交共 {reviewSubmission.file_ids.length} 个附件；审核时应逐项核对，不应只看总附件数。</Typography.Text>
        </Space></Card>}
        {submissionDecision === 'reject' && <Form.Item name="reason_code" label="驳回原因" rules={[{ required: true }]}><Select options={[{ value: 'evidence_unclear', label: '证明材料不清晰' }, { value: 'requirements_not_met', label: '未满足任务要求' }, { value: 'content_mismatch', label: '提交内容与任务不符' }, { value: 'duplicate_or_fraud', label: '疑似重复或作弊' }, { value: 'other', label: '其他' }]} /></Form.Item>}
        <Form.Item name="note" label={submissionDecision === 'approve' ? '审核备注（可选）' : '驳回说明'} rules={submissionDecision === 'reject' ? [{ required: true }] : []}><Input.TextArea rows={4} /></Form.Item>
      </Form>
    </Modal>
  </>
}
