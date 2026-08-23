import { CheckOutlined, CloseOutlined, EditOutlined, HistoryOutlined, PlusOutlined, RocketOutlined } from '@ant-design/icons'
import { Alert, Button, Card, Checkbox, Divider, Form, Image, Input, InputNumber, Modal, Select, Space, Table, Tag, Typography, message } from 'antd'
import { useCallback, useEffect, useState } from 'react'
import {
  ApiError,
  createManagedTask,
  createRewardBudgetPool,
  downloadTaskSubmissionFile,
  listManagedTasks,
  listManagedTaskVersions,
  listRewardCouponTemplates,
  listRewardBudgetPools,
  listTaskSubmissions,
  listTaskRewardDeliveries,
  listTaskAppeals,
  listTaskRiskCases,
  listTaskDataBatches,
  listTaskReviewReasons,
  publishManagedTask,
  reviewManagedTaskPublish,
  reviewTaskSubmission,
  retryTaskRewardDelivery,
  reviewTaskAppeal,
  reviewTaskRiskCase,
  rollbackManagedTaskVersion,
  releaseTaskSubmissionReview,
  startTaskSubmissionReview,
  transferTaskSubmissionReview,
  transitionManagedTask,
  updateManagedTask,
  verifyAdminRecent,
  type ManagedTask,
  type ManagedCouponTemplate,
  type ManagedTaskSubmission,
  type ManagedTaskRewardDelivery,
  type ManagedTaskAppeal,
  type ManagedTaskRiskCase,
  type RewardBudgetPool,
  type ManagedTaskStep,
  type ManagedTaskVersionSummary,
  type TaskDataBatch,
  type TaskReviewReason,
} from '../../api/admin'
import { useAdminAuth } from '../auth/AdminAuthContext'
import { RichTextEditor } from '../../components/RichTextEditor'
import { MediaGalleryInput, type GalleryImageValue } from '../../components/MediaGalleryInput'
import { TaskStepBuilder } from './TaskStepBuilder'
import { TaskTemplatePanel } from './TaskTemplatePanel'
import { TaskDataBatchPanel } from './TaskDataBatchPanel'
import { businessLabel, riskLevelLabels, taskRiskSourceLabels } from '../../constants/businessLabels'

interface PoolForm { name: string; asset_type: 'points' | 'wallet_cash' | 'coupon'; coupon_template_id?: string; total_amount: number; reason: string; password: string; mfa_code?: string }
interface TaskForm { project_name?: string; cover_images: GalleryImageValue[]; title: string; description: string; category: string; tags: string[]; task_type: string; supported_devices: string[]; points: number; points_pool_id: string; wallet_amount?: number; wallet_pool_id?: string; coupon_template_id?: string; coupon_quantity?: number; coupon_pool_id?: string; quota: number; virtual_view_count: number; progress_target?: number; progress_event_type?: string; data_batch_id?: string; completion_window_hours: number; submission_limit: number; review_deadline_hours: number; starts_at: string; claim_ends_at: string; submit_ends_at: string; steps: ManagedTaskStep[] }
interface ApprovalForm { note: string; password: string; mfa_code?: string }
interface SubmissionReviewForm { note: string; step_results?: Record<string, { decision: 'approve' | 'reject'; reason_code?: string; note?: string }> }
interface ReviewTransferForm { target_admin_id: string; reason: string }
interface TaskTransitionForm { reason: string; existing_claim_policy?: 'continue' | 'terminate_without_compensation' | 'terminate_with_compensation' }
interface RewardRetryForm { reason: string }

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
  closed: { label: '已关闭', color: 'default' },
  expired: { label: '已到期', color: 'default' },
  archived: { label: '已归档', color: 'default' },
}

const approvalStatusLabels: Record<string, string> = {
  pending: '待复核', approved: '已批准', rejected: '已拒绝', cancelled: '已取消',
}

const taskVersionChangeTypeLabels: Record<string, string> = {
  initial: '初始版本',
  minor: '一般调整',
  major: '重要调整',
  rollback: '历史回滚',
  content: '内容调整',
  reward: '奖励调整',
  schedule: '时间调整',
  workflow: '步骤调整',
}

function SubmissionEvidenceGallery({ submissionId, fileIds }: { submissionId: string; fileIds: string[] }) {
  const [files, setFiles] = useState<Record<string, { url: string; type: string } | null>>({})
  const fileKey = fileIds.join(',')

  useEffect(() => {
    let active = true
    const objectUrls: string[] = []
    const ids = fileKey ? fileKey.split(',') : []
    setFiles({})
    void Promise.all(ids.map(async (fileId) => {
      try {
        const blob = await downloadTaskSubmissionFile(submissionId, fileId)
        const url = URL.createObjectURL(blob)
        objectUrls.push(url)
        return [fileId, { url, type: blob.type }] as const
      } catch {
        return [fileId, null] as const
      }
    })).then((items) => {
      if (active) setFiles(Object.fromEntries(items))
    })

    return () => {
      active = false
      objectUrls.forEach((url) => URL.revokeObjectURL(url))
    }
  }, [fileKey, submissionId])

  if (!fileIds.length) return <Typography.Text type="secondary">未提交凭证</Typography.Text>

  return <Image.PreviewGroup><Space wrap style={{ marginTop: 8 }}>
    {fileIds.map((fileId, index) => {
      const file = files[fileId]
      if (file === undefined) return <Tag key={fileId} color="processing">凭证 {index + 1} 加载中</Tag>
      if (file === null) return <Tag key={fileId} color="error">凭证 {index + 1} 无法预览</Tag>
      if (file.type.startsWith('image/')) return <Image key={fileId} width={96} height={96} src={file.url} alt={`任务凭证 ${index + 1}`} style={{ objectFit: 'cover', borderRadius: 8 }} />
      return <Button key={fileId} href={file.url} target="_blank">打开凭证 {index + 1}</Button>
    })}
  </Space></Image.PreviewGroup>
}

export function TaskManagementPage() {
  const { admin } = useAdminAuth()
  const [tasks, setTasks] = useState<ManagedTask[]>([])
  const [pools, setPools] = useState<RewardBudgetPool[]>([])
  const [couponTemplates, setCouponTemplates] = useState<ManagedCouponTemplate[]>([])
  const [submissions, setSubmissions] = useState<ManagedTaskSubmission[]>([])
  const [rewardFailures, setRewardFailures] = useState<ManagedTaskRewardDelivery[]>([])
  const [appeals, setAppeals] = useState<ManagedTaskAppeal[]>([])
  const [riskCases, setRiskCases] = useState<ManagedTaskRiskCase[]>([])
  const [dataBatches, setDataBatches] = useState<TaskDataBatch[]>([])
  const [reviewReasons, setReviewReasons] = useState<TaskReviewReason[]>([])
  const [reviewAdmins, setReviewAdmins] = useState<Array<{ id: string; account: string; display_name: string }>>([])
  const [loading, setLoading] = useState(false)
  const [poolOpen, setPoolOpen] = useState(false)
  const [taskOpen, setTaskOpen] = useState(false)
  const [editingTask, setEditingTask] = useState<ManagedTask | null>(null)
  const [versionTask, setVersionTask] = useState<ManagedTask | null>(null)
  const [taskVersions, setTaskVersions] = useState<ManagedTaskVersionSummary[]>([])
  const [approvalTask, setApprovalTask] = useState<ManagedTask | null>(null)
  const [approvalDecision, setApprovalDecision] = useState<'approve' | 'reject'>('approve')
  const [reviewSubmission, setReviewSubmission] = useState<ManagedTaskSubmission | null>(null)
  const [transferSubmission, setTransferSubmission] = useState<ManagedTaskSubmission | null>(null)
  const [transitionTask, setTransitionTask] = useState<ManagedTask | null>(null)
  const [transitionAction, setTransitionAction] = useState<'pause' | 'resume' | 'close' | 'archive'>('pause')
  const [retryDelivery, setRetryDelivery] = useState<ManagedTaskRewardDelivery | null>(null)
  const [submitting, setSubmitting] = useState(false)
  const [poolForm] = Form.useForm<PoolForm>()
  const [taskForm] = Form.useForm<TaskForm>()
  const [approvalForm] = Form.useForm<ApprovalForm>()
  const [submissionReviewForm] = Form.useForm<SubmissionReviewForm>()
  const [reviewTransferForm] = Form.useForm<ReviewTransferForm>()
  const [taskTransitionForm] = Form.useForm<TaskTransitionForm>()
  const [rewardRetryForm] = Form.useForm<RewardRetryForm>()
  const [messageApi, contextHolder] = message.useMessage()
  const canManage = admin?.permissions.includes('task.manage') ?? false
  const canReview = admin?.permissions.includes('task.review') ?? false
  const canFinance = admin?.permissions.includes('finance.manage') ?? false

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const [taskResult, poolResult, couponTemplateResult, submittedResult, reviewingResult, rewardFailureResult, appealResult, riskResult, batchResult, reasonResult] = await Promise.all([listManagedTasks(), listRewardBudgetPools(), listRewardCouponTemplates(), canReview ? listTaskSubmissions('submitted') : Promise.resolve({ items: [] }), canReview ? listTaskSubmissions('reviewing') : Promise.resolve({ items: [] }), canReview ? listTaskRewardDeliveries('failed') : Promise.resolve({ items: [] }), canReview ? listTaskAppeals() : Promise.resolve({ items: [] }), canReview ? listTaskRiskCases() : Promise.resolve({ items: [] }), canManage ? listTaskDataBatches() : Promise.resolve({ items: [] }), canReview ? listTaskReviewReasons() : Promise.resolve({ items: [], reviewers: [] })])
      setTasks(taskResult.items)
      setPools(poolResult.items)
      setCouponTemplates(couponTemplateResult.items)
      setSubmissions([...reviewingResult.items, ...submittedResult.items])
      setRewardFailures(rewardFailureResult.items)
      setAppeals(appealResult.items)
      setRiskCases(riskResult.items)
      setDataBatches(batchResult.items)
      setReviewReasons(reasonResult.items)
      setReviewAdmins(reasonResult.reviewers)
    } catch (cause) { messageApi.error(cause instanceof ApiError ? cause.message : '任务数据加载失败。') }
    finally { setLoading(false) }
  }, [canManage, canReview, messageApi])
  useEffect(() => { void load() }, [load])

  const openCreateTask = () => {
    if (!canManage) {
      messageApi.warning('当前角色没有任务管理权限，不能新建任务。')
      return
    }
    setEditingTask(null)
    taskForm.resetFields()
    setTaskOpen(true)
  }

  const openEditTask = (task: ManagedTask) => {
    if (!canManage) {
      messageApi.warning('当前角色没有任务管理权限，不能编辑任务。')
      return
    }
    const pointsIndex = task.rewards.findIndex((reward) => reward.type === 'points')
    const walletIndex = task.rewards.findIndex((reward) => reward.type === 'wallet_balance')
    const couponIndex = task.rewards.findIndex((reward) => reward.type === 'coupon')
    const bindingFor = (sequence: number) => task.reward_budget_bindings?.find((binding) => binding.reward_sequence === sequence)?.budget_pool_id
    const localDateTime = (value: string) => value ? new Date(value).toISOString().slice(0, 16) : ''
    setEditingTask(task)
    taskForm.setFieldsValue({
      project_name: task.project_name ?? undefined,
      cover_images: task.cover_image_url ? [{ url: task.cover_image_url, alt: task.title, sort_order: 0 }] : [],
      title: task.title,
      description: task.description,
      category: task.category,
      tags: task.tags ?? [],
      task_type: task.task_type,
      supported_devices: task.supported_devices ?? ['android', 'ios'],
      points: task.rewards[pointsIndex]?.points ?? 1,
      points_pool_id: pointsIndex >= 0 ? bindingFor(pointsIndex + 1) : undefined,
      wallet_amount: walletIndex >= 0 ? task.rewards[walletIndex]?.balance_amount : undefined,
      wallet_pool_id: walletIndex >= 0 ? bindingFor(walletIndex + 1) : undefined,
      coupon_template_id: couponIndex >= 0 ? task.rewards[couponIndex]?.coupon_template_id : undefined,
      coupon_quantity: couponIndex >= 0 ? task.rewards[couponIndex]?.quantity ?? 1 : undefined,
      coupon_pool_id: couponIndex >= 0 ? bindingFor(couponIndex + 1) : undefined,
      quota: task.reward_quota_total,
      virtual_view_count: task.virtual_view_count ?? 0,
      progress_target: task.progress_target ?? 1,
      progress_event_type: task.progress_event_type ?? undefined,
      data_batch_id: task.data_batch?.id,
      completion_window_hours: task.completion_window_hours,
      submission_limit: task.submission_limit,
      review_deadline_hours: task.review_deadline_hours,
      starts_at: localDateTime(task.claim_starts_at),
      claim_ends_at: localDateTime(task.claim_ends_at),
      submit_ends_at: localDateTime(task.submit_ends_at),
      steps: task.steps,
    })
    setTaskOpen(true)
  }

  const openVersions = async (task: ManagedTask) => {
    setVersionTask(task)
    try { setTaskVersions(await listManagedTaskVersions(task.id)) }
    catch (cause) { messageApi.error(cause instanceof ApiError ? cause.message : '版本记录加载失败。') }
  }

  const createPool = async (values: PoolForm) => {
    if (!canFinance) {
      messageApi.warning('当前角色没有财务管理权限，不能新建预算池。')
      return
    }
    if (values.asset_type === 'coupon' && !values.coupon_template_id) {
      messageApi.error('优惠券预算池必须选择优惠券模板。')
      return
    }
    setSubmitting(true)
    try {
      await verifyAdminRecent(values.password, values.mfa_code)
      await createRewardBudgetPool(values)
      setPoolOpen(false); poolForm.resetFields(); await load(); messageApi.success('预算池已创建。')
    } catch (cause) { messageApi.error(cause instanceof ApiError ? cause.message : '预算池创建失败。') }
    finally { setSubmitting(false) }
  }

  const saveTask = async (values: TaskForm) => {
    if (!canManage) {
      messageApi.warning('当前角色没有任务管理权限，不能保存任务。')
      return
    }
    if (values.wallet_amount && !values.wallet_pool_id) {
      messageApi.error('填写余额奖励后，必须选择余额预算池；如不发放余额，请清空“每次余额”。')
      return
    }
    if (!values.wallet_amount && values.wallet_pool_id) {
      messageApi.error('选择余额预算池后，必须填写每次余额奖励。')
      return
    }
    const hasCouponReward = !!(values.coupon_template_id || values.coupon_pool_id)
    if (hasCouponReward && (!values.coupon_template_id || !values.coupon_pool_id || !values.coupon_quantity)) {
      messageApi.error('发放优惠券时必须同时选择模板、填写每次数量并绑定同模板预算池。')
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
    if (hasCouponReward && values.coupon_template_id && values.coupon_pool_id && values.coupon_quantity) {
      rewards.push({ type: 'coupon', coupon_template_id: values.coupon_template_id, quantity: values.coupon_quantity })
      rewardBudgets.push({ reward_sequence: rewards.length, budget_pool_id: values.coupon_pool_id })
    }
    setSubmitting(true)
    const payload = {
        business_scenario: 'platform_task', project_name: values.project_name?.trim() || null, cover_image_url: values.cover_images[0]?.url ?? null,
        supported_devices: values.supported_devices, visibility: 'public', category: values.category, task_type: values.task_type, participation_mode: 'claim_and_submit',
        verification_mode: 'manual', title: values.title, description: values.description,
        tags: [...new Set((values.tags ?? []).map((tag) => tag.trim()).filter(Boolean))], rewards,
        reward_budgets: rewardBudgets, reward_quota_total: values.quota, reward_quota_per_cycle: values.task_type === 'one_time' ? null : values.quota,
        virtual_view_count: values.virtual_view_count,
        progress_target: values.progress_target || 1, progress_event_type: values.progress_event_type?.trim() || null,
        data_batch_id: values.data_batch_id || null,
        review_deadline_hours: values.review_deadline_hours, appeal_deadline_days: 7, appeal_limit: 1,
        completion_window_hours: values.completion_window_hours, submission_limit: values.submission_limit,
        attachment_rules: { max_count: 20, max_images: 20, max_image_bytes: 10485760 }, steps: values.steps,
        claim_starts_at: startsAt.toISOString(), claim_ends_at: claimEndsAt.toISOString(),
        submit_ends_at: submitEndsAt.toISOString(), rules: [],
    }
    try {
      if (editingTask) await updateManagedTask(editingTask.id, payload, editingTask.version)
      else await createManagedTask(payload)
      setTaskOpen(false); setEditingTask(null); taskForm.resetFields(); await load(); messageApi.success(editingTask ? '已生成新的任务草稿版本。' : '任务草稿已创建。')
    } catch (cause) { messageApi.error(cause instanceof ApiError ? cause.message : editingTask ? '任务版本保存失败。' : '任务草稿创建失败。') }
    finally { setSubmitting(false) }
  }

  const publish = async (task: ManagedTask) => {
    if (!canManage) {
      messageApi.warning('当前角色没有任务管理权限，不能发布任务。')
      return
    }
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
    if (!canFinance) {
      messageApi.warning('当前角色没有财务管理权限，不能复核任务发布。')
      return
    }
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
    if (!canReview) {
      messageApi.warning('当前角色没有任务审核权限，不能处理任务提交。')
      return
    }
    setSubmitting(true)
    try {
      const scope = reviewSubmission.revision_step_ids?.length ? reviewSubmission.revision_step_ids : reviewSubmission.task_steps.map((step) => step.id)
      const stepResults = scope.map((stepId) => ({ step_id: stepId, decision: values.step_results?.[stepId]?.decision ?? 'approve', reason_code: values.step_results?.[stepId]?.reason_code ?? null, note: values.step_results?.[stepId]?.note ?? null }))
      const decision = stepResults.some((item) => item.decision === 'reject') ? 'reject' : 'approve'
      await reviewTaskSubmission(reviewSubmission.id, decision, null, values.note || '', stepResults)
      setReviewSubmission(null); submissionReviewForm.resetFields(); await load()
      messageApi.success(decision === 'approve' ? '全部步骤通过，奖励进入独立发放流程。' : '已仅驳回失败步骤，用户补交时会复用其他已通过材料。')
    } catch (cause) { messageApi.error(cause instanceof ApiError ? cause.message : '任务提交审核失败。') }
    finally { setSubmitting(false) }
  }

  const releaseReview = (item: ManagedTaskSubmission) => Modal.confirm({ title: '释放审核任务', content: '释放后该提交回到待接单队列，操作会写入审计日志。', okText: '确认释放', cancelText: '取消', onOk: async () => { await releaseTaskSubmissionReview(item.id, '当前审核员释放任务，由其他审核员继续处理。'); await load(); messageApi.success('审核任务已释放。') } })

  const transferReview = async (values: ReviewTransferForm) => {
    if (!transferSubmission) return
    setSubmitting(true)
    try { await transferTaskSubmissionReview(transferSubmission.id, values.target_admin_id, values.reason); setTransferSubmission(null); reviewTransferForm.resetFields(); await load(); messageApi.success('审核任务已转派。') }
    catch (cause) { messageApi.error(cause instanceof ApiError ? cause.message : '审核任务转派失败。') }
    finally { setSubmitting(false) }
  }

  const openSubmissionReview = async (item: ManagedTaskSubmission) => {
    if (!canReview) return messageApi.warning('当前角色没有任务审核权限。')
    setSubmitting(true)
    try {
      const reviewing = item.status === 'submitted' ? await startTaskSubmissionReview(item.id) : item
      setReviewSubmission(reviewing)
      submissionReviewForm.resetFields()
      if (item.status === 'submitted') {
        setSubmissions((current) => current.map((submission) => submission.id === item.id ? { ...submission, ...reviewing, status: 'reviewing' } : submission))
        messageApi.success('已接单并开始审核，用户不能再撤回本次提交。')
      }
    } catch (cause) { messageApi.error(cause instanceof ApiError ? cause.message : '开始审核失败，请刷新队列后重试。') }
    finally { setSubmitting(false) }
  }

  const openTaskTransition = (task: ManagedTask, action: 'pause' | 'resume' | 'close' | 'archive') => {
    setTransitionTask(task)
    setTransitionAction(action)
    taskTransitionForm.setFieldsValue({
      reason: '',
      existing_claim_policy: action === 'close' ? 'continue' : undefined,
    })
  }

  const submitTaskTransition = async (values: TaskTransitionForm) => {
    if (!transitionTask) return
    setSubmitting(true)
    try {
      await transitionManagedTask(transitionTask.id, transitionAction, values.reason, transitionAction === 'close' ? values.existing_claim_policy : undefined)
      setTransitionTask(null); taskTransitionForm.resetFields(); await load()
      messageApi.success(({ pause: '任务已暂停领取。', resume: '任务已恢复。', close: '任务已关闭，未分配预算已释放。', archive: '任务已归档。' } as const)[transitionAction])
    } catch (cause) { messageApi.error(cause instanceof ApiError ? cause.message : '任务状态变更失败。') }
    finally { setSubmitting(false) }
  }

  const submitRewardRetry = async (values: RewardRetryForm) => {
    if (!retryDelivery) return
    setSubmitting(true)
    try {
      const result = await retryTaskRewardDelivery(retryDelivery.id, values.reason)
      setRetryDelivery(null); rewardRetryForm.resetFields(); await load()
      messageApi.success(result.status === 'delivered' ? '奖励已补发到账。' : '已重新进入奖励发放队列。')
    } catch (cause) { messageApi.error(cause instanceof ApiError ? cause.message : '奖励重试失败。') }
    finally { setSubmitting(false) }
  }

  return <>
    {contextHolder}
    <div className="page-heading"><div><Typography.Text className="eyebrow">校园任务管理</Typography.Text><Typography.Title level={2}>任务与奖励预算</Typography.Title><Typography.Paragraph type="secondary">草稿版本不可覆盖；发布时由服务端重新计算并全量锁定积分、优惠券和余额预算。</Typography.Paragraph></div><Space>{canFinance && <Button icon={<PlusOutlined />} onClick={() => setPoolOpen(true)}>新建预算池</Button>}{canManage && <Button type="primary" icon={<PlusOutlined />} onClick={openCreateTask}>新建任务</Button>}</Space></div>
    <Alert showIcon type="info" message="单次余额奖励超过 200 元或任务余额总预算超过 5000 元时，先由另一位财务管理员复核；批准后再次发布才会原子锁定全部预算。" />
    <TaskTemplatePanel tasks={tasks} pools={pools} canManage={canManage} onTaskCreated={load} />
    <TaskDataBatchPanel canManage={canManage} onChanged={load} />
    <Card bordered={false} style={{ marginTop: 16 }} title="平台预算池">
      <Table rowKey="id" loading={loading} dataSource={pools} pagination={false} columns={[
        { title: '名称', dataIndex: 'name' }, { title: '类型', dataIndex: 'asset_type', render: (value: string) => ({ points: '积分', wallet_cash: '余额', coupon: '优惠券' } as Record<string, string>)[value] ?? value },
        { title: '优惠券模板', dataIndex: 'coupon_template_id', render: (value: string | null) => value ? couponTemplates.find((item) => item.id === value)?.name ?? value : '—' }, { title: '总量', dataIndex: 'total_amount' },
        { title: '已锁定', dataIndex: 'locked_amount' }, { title: '可用', dataIndex: 'available_amount' }, { title: '版本', dataIndex: 'version' },
      ]} />
    </Card>
    <Card bordered={false} style={{ marginTop: 16 }} title="任务生命周期与版本">
      <Table rowKey="id" loading={loading} dataSource={tasks} pagination={false} columns={[
        { title: '任务', dataIndex: 'title', render: (value: string, item: ManagedTask) => <Space direction="vertical" size={0}><strong>{value}</strong><Typography.Text type="secondary">v{item.task_version.version_no} · {businessLabel(taskVersionChangeTypeLabels, item.task_version.change_type)}</Typography.Text></Space> },
        { title: '分类', dataIndex: 'category', render: (value: string) => taskCategoryLabels[value] ?? value }, { title: '奖励次数', dataIndex: 'reward_quota_total' },
        { title: '状态', dataIndex: 'status', render: (value: string, item: ManagedTask) => { const meta = taskStatusMeta[value] ?? { label: '未识别状态', color: 'default' }; return <Space direction="vertical" size={0}><Space size={4}><Tag color={meta.color}>{meta.label}</Tag>{item.has_unpublished_changes && <Tag color="gold">待发布版本</Tag>}</Space>{item.publish_approval && <Typography.Text type="secondary">财务复核：{businessLabel(approvalStatusLabels, item.publish_approval.status)}</Typography.Text>}</Space> } },
        { title: '操作', render: (_: unknown, item: ManagedTask) => <Space wrap>
          {canManage && <Button icon={<EditOutlined />} disabled={!['draft', 'pending_approval', 'published', 'scheduled', 'paused'].includes(item.status)} onClick={() => openEditTask(item)}>编辑新版本</Button>}
          <Button icon={<HistoryOutlined />} onClick={() => void openVersions(item)}>版本记录</Button>
          {canManage && <Button icon={<RocketOutlined />} disabled={item.status !== 'draft' && item.publish_approval?.status !== 'approved'} onClick={() => void publish(item)}>{item.publish_approval?.status === 'approved' ? '复核后发布' : '发布并锁定预算'}</Button>}
          {canManage && ['published', 'scheduled'].includes(item.status) && <Button onClick={() => openTaskTransition(item, 'pause')}>暂停发布</Button>}
          {canManage && item.status === 'paused' && <Button type="primary" ghost onClick={() => openTaskTransition(item, 'resume')}>恢复发布</Button>}
          {canManage && ['draft', 'scheduled', 'published', 'paused'].includes(item.status) && <Button danger onClick={() => openTaskTransition(item, 'close')}>结束任务</Button>}
          {canManage && ['closed', 'expired'].includes(item.status) && <Button onClick={() => openTaskTransition(item, 'archive')}>归档</Button>}
          {canFinance && item.publish_approval?.status === 'pending' && <><Button icon={<CheckOutlined />} onClick={() => { setApprovalDecision('approve'); setApprovalTask(item) }}>批准</Button><Button danger icon={<CloseOutlined />} onClick={() => { setApprovalDecision('reject'); setApprovalTask(item) }}>拒绝</Button></>}
        </Space> },
      ]} />
    </Card>
    {canReview && <Card bordered={false} style={{ marginTop: 16 }} title={`任务提交审核队列（待接单 ${submissions.filter((item) => item.status === 'submitted').length} / 审核中 ${submissions.filter((item) => item.status === 'reviewing').length}）`}>
      <Alert showIcon type="info" message="打开审核即接单并切换为“审核中”，此后用户不能撤回；审核期限逾期只提醒，不会自动通过。" style={{ marginBottom: 16 }} />
      <Table rowKey="id" loading={loading} dataSource={submissions} pagination={false} columns={[
        { title: '任务', dataIndex: 'task_title' },
        { title: '用户', dataIndex: 'user_id' },
        { title: '提交', render: (_: unknown, item: ManagedTaskSubmission) => <Space direction="vertical" size={0}><span>第 {item.submission_no} 次</span><Typography.Text type="secondary">{item.content?.text || `${item.file_ids.length} 个附件`}</Typography.Text></Space> },
        { title: '执行 / 审核状态', render: (_: unknown, item: ManagedTaskSubmission) => <Space direction="vertical" size={2}><Space wrap size={4}><Tag color="blue">执行：{item.execution_status_label || '审核中'}</Tag><Tag color={item.status === 'reviewing' ? 'processing' : 'warning'}>审核：{item.review_status_label || (item.status === 'reviewing' ? '审核中' : '待接单')}</Tag></Space>{item.reviewer && <Typography.Text type="secondary">审核员：{item.reviewer.display_name}</Typography.Text>}{item.review_lock_expires_at && <Typography.Text type="secondary">占用至 {new Date(item.review_lock_expires_at).toLocaleTimeString()}</Typography.Text>}</Space> },
        { title: '审核期限', dataIndex: 'review_due_at', render: (value: string, item: ManagedTaskSubmission) => <Tag color={item.overdue ? 'error' : 'processing'}>{new Date(value).toLocaleString()}</Tag> },
        { title: '操作', render: (_: unknown, item: ManagedTaskSubmission) => <Space wrap><Button type="primary" icon={<CheckOutlined />} loading={submitting && reviewSubmission?.id === item.id} onClick={() => void openSubmissionReview(item)}>{item.status === 'submitted' ? '接单并审核' : '继续审核'}</Button>{item.status === 'reviewing' && <><Button onClick={() => releaseReview(item)}>释放</Button><Button onClick={() => { setTransferSubmission(item); reviewTransferForm.resetFields() }}>转派</Button></>}</Space> },
      ]} />
    </Card>}
    {canReview && <Card bordered={false} style={{ marginTop: 16 }} title={`奖励发放失败队列（${rewardFailures.length}）`}>
      <Alert showIcon type={rewardFailures.length ? 'warning' : 'success'} message={rewardFailures.length ? '任务审核结果与奖励发放相互独立。请先查看失败原因，确认依赖恢复后再人工重试，重试具有幂等保护。' : '当前没有待处理的奖励发放失败记录。'} style={{ marginBottom: 16 }} />
      <Table rowKey="id" loading={loading} dataSource={rewardFailures} pagination={false} locale={{ emptyText: '暂无失败奖励' }} columns={[
        { title: '任务 / 用户', render: (_: unknown, item: ManagedTaskRewardDelivery) => <Space direction="vertical" size={0}><strong>{item.task_title || '任务奖励'}</strong><Typography.Text type="secondary">用户 {item.user_id || '—'} · 领取 {item.claim_id || '—'}</Typography.Text></Space> },
        { title: '奖励组件', render: (_: unknown, item: ManagedTaskRewardDelivery) => <Space direction="vertical" size={0}><Tag>{({ points: '积分', wallet_cash: '余额', coupon: '优惠券' } as Record<string, string>)[item.asset_type] ?? item.asset_type}</Tag><strong>{item.asset_type === 'wallet_cash' ? `¥${(item.amount / 100).toFixed(2)}` : item.asset_type === 'coupon' ? `${item.amount} 张` : `${item.amount} 积分`}</strong></Space> },
        { title: '失败详情', render: (_: unknown, item: ManagedTaskRewardDelivery) => <Space direction="vertical" size={0} style={{ maxWidth: 360 }}><Typography.Text type="danger">{item.last_error_message || '发放组件返回未知错误'}</Typography.Text><Typography.Text type="secondary">错误码：{item.last_error_code || 'UNKNOWN'} · 已尝试 {item.attempt_count} 次</Typography.Text><Typography.Text type="secondary">下次自动重试：{item.next_retry_at ? new Date(item.next_retry_at).toLocaleString() : '等待调度'}</Typography.Text></Space> },
        { title: '操作', render: (_: unknown, item: ManagedTaskRewardDelivery) => <Button type="primary" onClick={() => { setRetryDelivery(item); rewardRetryForm.setFieldsValue({ reason: '' }) }}>人工重试</Button> },
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
        { title: '任务', dataIndex: 'task_id' }, { title: '来源', dataIndex: 'source', render: (value: string) => businessLabel(taskRiskSourceLabels, value) }, { title: '等级', dataIndex: 'risk_level', render: (value: string) => <Tag color={value === 'high' ? 'error' : value === 'medium' ? 'warning' : 'success'}>{businessLabel(riskLevelLabels, value)}</Tag> },
        { title: '响应期限', dataIndex: 'response_due_at', render: (value: string, item: ManagedTaskRiskCase) => <Tag color={item.overdue ? 'error' : 'processing'}>{new Date(value).toLocaleString()}</Tag> },
        { title: '操作', render: (_: unknown, item: ManagedTaskRiskCase) => <Space><Button onClick={async () => { await reviewTaskRiskCase(item.id, 'clear', ['restore'], '人工核查未发现违规'); await load() }}>解除</Button><Button danger onClick={async () => { await reviewTaskRiskCase(item.id, 'confirm_violation', ['stop_claiming', 'freeze_reward'], '确认违规并暂停领取'); await load() }}>确认违规</Button></Space> },
      ]} />
    </Card>}
    {canFinance && <Modal title="新建平台预算池" open={poolOpen} onCancel={() => setPoolOpen(false)} onOk={() => poolForm.submit()} confirmLoading={submitting} destroyOnHidden><Form form={poolForm} layout="vertical" onFinish={createPool} initialValues={{ asset_type: 'points' }}><Form.Item name="name" label="名称" rules={[{ required: true }]}><Input /></Form.Item><Form.Item name="asset_type" label="资产类型" rules={[{ required: true }]}><Select onChange={() => poolForm.setFieldValue('coupon_template_id', undefined)} options={[{ value: 'points', label: '积分' }, { value: 'wallet_cash', label: '余额（分）' }, { value: 'coupon', label: '优惠券（张）' }]} /></Form.Item><Form.Item noStyle shouldUpdate>{({ getFieldValue }) => getFieldValue('asset_type') === 'coupon' ? <Form.Item name="coupon_template_id" label="优惠券模板" rules={[{ required: true, message: '请选择优惠券模板' }]} extra="预算总量不能超过模板剩余发行量，也不能与其他预算池重复占用。"><Select options={couponTemplates.filter((item) => item.status === 'active' && item.remaining_quantity > 0).map((item) => ({ value: item.id, label: `${item.name}（剩余 ${item.remaining_quantity} 张）` }))} /></Form.Item> : null}</Form.Item><Form.Item name="total_amount" label="总预算" rules={[{ required: true }]}><InputNumber min={1} style={{ width: '100%' }} /></Form.Item><Form.Item name="reason" label="创建原因" rules={[{ required: true }]}><Input /></Form.Item><Form.Item name="password" label="管理员密码（二次验证）" rules={[{ required: true }]}><Input.Password /></Form.Item><Form.Item name="mfa_code" label="MFA 动态码"><Input /></Form.Item></Form></Modal>}
    {canManage && <Modal title={editingTask ? `编辑任务 · 生成 v${editingTask.task_version.version_no + 1}` : '新建任务草稿'} width={1080} open={taskOpen} onCancel={() => { setTaskOpen(false); setEditingTask(null) }} onOk={() => taskForm.submit()} okText={editingTask ? '保存新版本' : '创建草稿'} cancelText="取消" confirmLoading={submitting} destroyOnHidden styles={{ body: { maxHeight: '76vh', overflowY: 'auto', paddingRight: 10 } }}>
      <Form form={taskForm} layout="vertical" onFinish={saveTask} initialValues={{ category: 'campaign', tags: [], task_type: 'one_time', supported_devices: ['android', 'ios'], quota: 100, points: 10, virtual_view_count: 0, progress_target: 1, completion_window_hours: 24, submission_limit: 3, review_deadline_hours: 48, cover_images: [], steps: defaultTaskSteps }}>
        <Alert showIcon type="info" message="任务说明负责整体背景，任务步骤负责可执行流程和审核材料。发布后步骤会进入不可变版本快照，已领取用户不会受后续修改影响。" style={{ marginBottom: 18 }} />
        <Divider titlePlacement="start">一、基础信息</Divider>
        <Space align="start" wrap style={{ width: '100%' }}><Form.Item name="project_name" label="项目名称"><Input style={{ width: 250 }} maxLength={120} placeholder="用于后台归类，可选" /></Form.Item><Form.Item name="title" label="任务标题" rules={[{ required: true }]}><Input style={{ width: 420 }} maxLength={160} /></Form.Item><Form.Item name="category" label="任务分类" rules={[{ required: true }]}><Select style={{ width: 180 }} options={taskCategoryOptions} /></Form.Item></Space>
        <Form.Item name="tags" label="任务标签（选填）" extra="用于搜索、筛选和运营分组；输入后按回车创建标签，最多 10 个。" rules={[{ validator: async (_, tags: string[] = []) => { if (tags.length > 10) throw new Error('最多添加 10 个任务标签'); if (tags.some((tag) => tag.trim().length > 30)) throw new Error('每个标签不能超过 30 个字符') } }]}><Select mode="tags" tokenSeparators={[',', '，']} placeholder="例如：新手、限时、调研" style={{ width: '100%' }} /></Form.Item>
        <Form.Item name="cover_images" label="任务封面图" required extra="支持电脑本地上传和图片网址导入，导入后统一保存到当前素材存储。" rules={[{ validator: async (_, images) => { if (!images?.length) throw new Error('请上传任务封面图') } }]}><MediaGalleryInput max={1} primaryLabel="任务封面" itemLabel="任务图片" /></Form.Item>
        <Form.Item name="description" label="任务说明" rules={[{ required: true, message: '请输入任务说明' }]}><RichTextEditor placeholder="填写参与条件、整体背景、注意事项和审核原则；具体操作请放到任务步骤中。" minHeight={240} /></Form.Item>
        <Space align="start" wrap><Form.Item name="task_type" label="参与频率" rules={[{ required: true }]}><Select style={{ width: 180 }} options={[{ value: 'one_time', label: '每人一次' }, { value: 'daily', label: '每日成长' }, { value: 'weekly', label: '每周成长' }, { value: 'monthly', label: '每月成长' }, { value: 'milestone', label: '永久里程碑' }, { value: 'repeatable', label: '可重复领取' }]} /></Form.Item><Form.Item name="progress_target" label="周期目标次数" rules={[{ required: true }]}><InputNumber min={1} max={100000} /></Form.Item><Form.Item name="progress_event_type" label="自动进度事件（选填）" extra="例如 forum_post、comment、profile_complete；留空则人工提交。"><Input style={{ width: 240 }} /></Form.Item><Form.Item name="supported_devices" label="支持设备" rules={[{ required: true, type: 'array', min: 1 }]}><Checkbox.Group options={[{ label: 'Android', value: 'android' }, { label: 'iOS / 微信', value: 'ios' }]} /></Form.Item></Space>
        <Divider titlePlacement="start">二、奖励、名额与时限</Divider>
        <Space align="start" wrap><Form.Item name="points" label="每次积分"><InputNumber min={1} /></Form.Item><Form.Item name="points_pool_id" label="积分预算池" rules={[{ required: true }]}><Select style={{ width: 240 }} options={pools.filter((pool) => pool.asset_type === 'points').map((pool) => ({ value: pool.id, label: `${pool.name}（可用 ${pool.available_amount}）` }))} /></Form.Item><Form.Item name="wallet_amount" label="每次余额（分，选填）" dependencies={['wallet_pool_id']} rules={[({ getFieldValue }) => ({ validator: async (_, value) => { if (value && !getFieldValue('wallet_pool_id')) throw new Error('请选择余额预算池') } })]}><InputNumber min={1} placeholder="不发余额请留空" /></Form.Item><Form.Item name="wallet_pool_id" label="余额预算池（选填）" dependencies={['wallet_amount']} rules={[({ getFieldValue }) => ({ validator: async (_, value) => { if (value && !getFieldValue('wallet_amount')) throw new Error('请填写每次余额奖励') } })]}><Select allowClear placeholder={pools.some((pool) => pool.asset_type === 'wallet_cash') ? '发余额时必选' : '暂无余额预算池'} disabled={!pools.some((pool) => pool.asset_type === 'wallet_cash')} style={{ width: 240 }} options={pools.filter((pool) => pool.asset_type === 'wallet_cash').map((pool) => ({ value: pool.id, label: `${pool.name}（可用 ${pool.available_amount}）` }))} /></Form.Item><Form.Item name="quota" label="奖励总名额" rules={[{ required: true }]}><InputNumber min={1} /></Form.Item><Form.Item name="virtual_view_count" label="虚拟浏览数" extra="只作为前端热度基数，不影响真实领取、完成、名额和财务统计。" rules={[{ required: true }]}><InputNumber min={0} max={100000000} precision={0} style={{ width: 150 }} /></Form.Item></Space>
        <Card size="small" title="优惠券奖励（选填）" style={{ marginBottom: 16 }}><Alert showIcon type="info" message="优惠券按每次完成数量 × 奖励总名额锁定预算；模板与预算池必须一致，任一预算不足都会阻止整项任务发布。" style={{ marginBottom: 12 }} /><Space align="start" wrap><Form.Item name="coupon_template_id" label="优惠券模板"><Select allowClear placeholder="不发优惠券请留空" style={{ width: 260 }} onChange={() => taskForm.setFieldValue('coupon_pool_id', undefined)} options={couponTemplates.filter((item) => item.status === 'active' && item.remaining_quantity > 0).map((item) => ({ value: item.id, label: `${item.name}（剩 ${item.remaining_quantity} 张）` }))} /></Form.Item><Form.Item name="coupon_quantity" label="每次发放数量" dependencies={['coupon_template_id']} rules={[({ getFieldValue }) => ({ validator: async (_, value) => { if (getFieldValue('coupon_template_id') && !value) throw new Error('请填写每次优惠券数量') } })]}><InputNumber min={1} max={100} placeholder="默认 1 张" /></Form.Item><Form.Item noStyle shouldUpdate>{({ getFieldValue }) => { const templateId = getFieldValue('coupon_template_id'); const options = pools.filter((pool) => pool.asset_type === 'coupon' && pool.coupon_template_id === templateId); return <Form.Item name="coupon_pool_id" label="优惠券预算池" dependencies={['coupon_template_id']} rules={[({ getFieldValue: read }) => ({ validator: async (_, value) => { if (read('coupon_template_id') && !value) throw new Error('请选择同模板优惠券预算池') } })]}><Select allowClear disabled={!templateId} placeholder={templateId ? (options.length ? '选择同模板预算池' : '暂无同模板预算池，请先新建') : '请先选择模板'} style={{ width: 280 }} options={options.map((pool) => ({ value: pool.id, label: `${pool.name}（可用 ${pool.available_amount} 张）` }))} /></Form.Item> }}</Form.Item></Space></Card>
        <Space wrap align="start"><Form.Item name="completion_window_hours" label="领取后完成时限（小时）" rules={[{ required: true }]}><InputNumber min={1} max={720} /></Form.Item><Form.Item name="submission_limit" label="最多提交次数" rules={[{ required: true }]}><InputNumber min={1} max={20} /></Form.Item><Form.Item name="review_deadline_hours" label="审核期限（小时）" rules={[{ required: true }]}><InputNumber min={1} max={720} /></Form.Item></Space>
        <Form.Item name="data_batch_id" label="绑定批量数据（选填）" extra="仅能选择预检通过且未被锁定的批次；发布时批次有效行数必须不少于奖励总名额。"><Select allowClear showSearch optionFilterProp="label" placeholder="普通任务无需选择" options={dataBatches.filter((item) => item.status === 'ready' && item.invalid_count === 0).map((item) => ({ value: item.id, label: `${item.name}（${item.available_count} 条可用）` }))} /></Form.Item>
        <Space wrap><Form.Item name="starts_at" label="领取开始" rules={[{ required: true }]}><Input type="datetime-local" /></Form.Item><Form.Item name="claim_ends_at" label="领取截止" rules={[{ required: true }]}><Input type="datetime-local" /></Form.Item><Form.Item name="submit_ends_at" label="全局最晚提交" rules={[{ required: true }]}><Input type="datetime-local" /></Form.Item></Space>
        <Divider titlePlacement="start">三、自定义任务步骤</Divider>
        <Form.Item name="steps"><TaskStepBuilder /></Form.Item>
      </Form>
    </Modal>}
    <Modal title={versionTask ? `“${versionTask.title}”的版本记录` : '任务版本记录'} width={820} open={versionTask !== null} footer={null} onCancel={() => { setVersionTask(null); setTaskVersions([]) }} destroyOnHidden>
      <Alert showIcon type="info" message="每次编辑或回滚都会生成新草稿版本，已经领取任务的用户继续使用原发布快照。" style={{ marginBottom: 16 }} />
      <Table rowKey="version_no" dataSource={taskVersions} pagination={false} columns={[
        { title: '版本', dataIndex: 'version_no', render: (value: number) => `v${value}` },
        { title: '变更类型', dataIndex: 'change_type', render: (value: string) => businessLabel(taskVersionChangeTypeLabels, value, '综合调整') },
        { title: '变更字段', dataIndex: 'changed_fields', render: (value: string[]) => value?.length ? value.join('、') : '—' },
        { title: '创建时间', dataIndex: 'created_at', render: (value: string | null) => value ? new Date(value).toLocaleString() : '—' },
        { title: '操作', render: (_: unknown, item: ManagedTaskVersionSummary) => canManage ? <Button disabled={!versionTask || item.version_no === versionTask.task_version.version_no || !['draft', 'pending_approval'].includes(versionTask.status)} onClick={() => versionTask && Modal.confirm({ title: `回滚到 v${item.version_no}`, content: '系统不会覆盖历史记录，而是基于该版本生成一个新的草稿版本。', okText: '确认回滚', cancelText: '取消', onOk: async () => { await rollbackManagedTaskVersion(versionTask.id, item.version_no, versionTask.version, `后台选择回滚到 v${item.version_no}`); setVersionTask(null); setTaskVersions([]); await load(); messageApi.success('已生成回滚草稿版本。') } })}>回滚为新草稿</Button> : <Typography.Text type="secondary">仅查看</Typography.Text> },
      ]} />
    </Modal>
    {canFinance && <Modal title={approvalDecision === 'approve' ? '批准任务发布' : '拒绝任务发布'} open={approvalTask !== null} onCancel={() => setApprovalTask(null)} onOk={() => approvalForm.submit()} confirmLoading={submitting} destroyOnHidden><Form form={approvalForm} layout="vertical" onFinish={reviewApproval}><Alert showIcon type="warning" message="必须由非申请人的财务管理员完成，并进行密码/MFA 二次验证。" style={{ marginBottom: 16 }} /><Form.Item name="note" label="复核意见" rules={[{ required: true }]}><Input.TextArea rows={3} /></Form.Item><Form.Item name="password" label="管理员密码" rules={[{ required: true }]}><Input.Password /></Form.Item><Form.Item name="mfa_code" label="MFA 动态码"><Input /></Form.Item></Form></Modal>}
    {canReview && <Modal title="逐步骤审核任务提交" width={900} open={reviewSubmission !== null} onCancel={() => setReviewSubmission(null)} onOk={() => submissionReviewForm.submit()} okText="提交审核结论" confirmLoading={submitting} destroyOnHidden>
      <Form form={submissionReviewForm} layout="vertical" onFinish={submitReview}>
        {reviewSubmission && <Card size="small" title="按步骤核验材料" style={{ marginBottom: 16 }}><Space direction="vertical" style={{ width: '100%' }}>
          {reviewSubmission.task_steps.map((step, index) => {
            const fields = reviewSubmission.content?.fields ?? {}
            const answers = (fields.steps ?? {}) as Record<string, string>
            const checks = (fields.completed_steps ?? {}) as Record<string, boolean>
            const stepFiles = (fields.step_files ?? {}) as Record<string, string[]>
            const evidenceIds = stepFiles[step.id] ?? []
            const disabled = !!reviewSubmission.revision_step_ids?.length && !reviewSubmission.revision_step_ids.includes(step.id)
            return <div key={step.id} style={{ paddingBottom: 16, borderBottom: '1px solid #f0f0f0', opacity: disabled ? 0.55 : 1 }}><Space><Tag>{index + 1}</Tag><strong>{step.title}</strong>{step.required && <Tag color="red">必填</Tag>}{disabled && <Tag color="success">上次已通过</Tag>}</Space><Typography.Paragraph style={{ margin: '6px 0 0' }}>{answers[step.id] || (checks[step.id] ? '用户已确认完成' : '无文字结果')}</Typography.Paragraph><Typography.Text type="secondary">凭证 {evidenceIds.length} 个</Typography.Text><SubmissionEvidenceGallery submissionId={reviewSubmission.id} fileIds={evidenceIds} />{!disabled && <Form.Item name={['step_results', step.id, 'decision']} label="本步骤结论" initialValue="approve" rules={[{ required: true }]} style={{ marginTop: 10 }}><Select options={[{ value: 'approve', label: '通过本步骤' }, { value: 'reject', label: '驳回并要求补交' }]} /></Form.Item>}<Form.Item noStyle shouldUpdate>{({ getFieldValue }) => !disabled && getFieldValue(['step_results', step.id, 'decision']) === 'reject' ? <Space align="start" wrap><Form.Item name={['step_results', step.id, 'reason_code']} label="驳回原因" rules={[{ required: true }]}><Select style={{ width: 220 }} options={reviewReasons.map((reason) => ({ value: reason.code, label: reason.label }))} /></Form.Item><Form.Item name={['step_results', step.id, 'note']} label="补交说明" rules={[{ required: true }]}><Input.TextArea style={{ width: 430 }} rows={2} maxLength={500} /></Form.Item></Space> : null}</Form.Item></div>
          })}
          <div><Typography.Text strong>全部提交附件（{reviewSubmission.file_ids.length}）</Typography.Text><SubmissionEvidenceGallery submissionId={reviewSubmission.id} fileIds={reviewSubmission.file_ids} /></div>
          <Typography.Text type="secondary">请逐项核对步骤说明、文字结果与原始凭证，预览失败时不得直接判定通过。</Typography.Text>
        </Space></Card>}
        <Form.Item name="note" label="整单审核备注（可选）"><Input.TextArea rows={3} maxLength={500} /></Form.Item>
      </Form>
    </Modal>}
    {canReview && <Modal title="转派审核任务" open={transferSubmission !== null} onCancel={() => setTransferSubmission(null)} onOk={() => reviewTransferForm.submit()} okText="确认转派" confirmLoading={submitting} destroyOnHidden><Form form={reviewTransferForm} layout="vertical" onFinish={transferReview}><Alert showIcon type="info" message="转派后当前审核员立即失去占用权，新审核员获得 30 分钟处理锁。" style={{ marginBottom: 16 }} /><Form.Item name="target_admin_id" label="目标审核员" rules={[{ required: true }]}><Select options={reviewAdmins.filter((item) => item.id !== admin?.id).map((item) => ({ value: item.id, label: `${item.display_name}（${item.account}）` }))} /></Form.Item><Form.Item name="reason" label="转派原因" rules={[{ required: true, min: 2 }]}><Input.TextArea rows={3} /></Form.Item></Form></Modal>}
    {canManage && <Modal title={`${({ pause: '暂停发布', resume: '恢复发布', close: '结束', archive: '归档' } as const)[transitionAction]}任务`} open={transitionTask !== null} onCancel={() => { setTransitionTask(null); taskTransitionForm.resetFields() }} onOk={() => taskTransitionForm.submit()} okText="确认执行" cancelText="取消" confirmLoading={submitting} destroyOnHidden>
      <Form form={taskTransitionForm} layout="vertical" onFinish={submitTaskTransition}>
        {transitionTask && <Alert showIcon type={transitionAction === 'close' ? 'warning' : 'info'} message={`当前任务：${transitionTask.title}`} description={transitionAction === 'close' ? '关闭后停止新领取并释放未分配预算；必须明确已有领取记录的处理方式，操作会写入审计日志。' : transitionAction === 'archive' ? '只有已关闭或已到期且不存在奖励预留的任务可以归档。' : '该操作不会改写已领取用户看到的任务版本快照。'} style={{ marginBottom: 16 }} />}
        <Form.Item name="reason" label="操作原因" rules={[{ required: true, min: 2, message: '请填写操作原因' }]}><Input.TextArea rows={3} maxLength={500} showCount placeholder="说明本次状态变更的业务原因" /></Form.Item>
        {transitionAction === 'close' && <Form.Item name="existing_claim_policy" label="已有领取记录处理方式" rules={[{ required: true, message: '请选择已有领取记录处理方式' }]}><Select options={[
          { value: 'continue', label: '继续履约：允许已领取用户按原截止时间完成并发奖' },
          { value: 'terminate_without_compensation', label: '终止且不补偿：停止已有领取记录（高风险）' },
          { value: 'terminate_with_compensation', label: '终止并补偿：停止已有领取记录并进入补偿流程' },
        ]} /></Form.Item>}
      </Form>
    </Modal>}
    {canReview && <Modal title="人工重试奖励发放" open={retryDelivery !== null} onCancel={() => { setRetryDelivery(null); rewardRetryForm.resetFields() }} onOk={() => rewardRetryForm.submit()} okText="确认重试" cancelText="取消" confirmLoading={submitting} destroyOnHidden>
      <Form form={rewardRetryForm} layout="vertical" onFinish={submitRewardRetry}>
        {retryDelivery && <Alert showIcon type="warning" message={`${({ points: '积分', wallet_cash: '余额', coupon: '优惠券' } as Record<string, string>)[retryDelivery.asset_type] ?? '奖励'}发放失败`} description={retryDelivery.last_error_message || '未返回详细错误，请确认奖励依赖服务状态。'} style={{ marginBottom: 16 }} />}
        <Form.Item name="reason" label="重试原因 / 处理记录" rules={[{ required: true, min: 2, message: '请填写重试原因' }]}><Input.TextArea rows={4} maxLength={500} showCount placeholder="例如：优惠券模板已重新启用，确认执行补发" /></Form.Item>
      </Form>
    </Modal>}
  </>
}
