import { EditOutlined, EyeOutlined, PlusOutlined, RedoOutlined, SettingOutlined, StopOutlined } from '@ant-design/icons'
import { Alert, Button, Card, Checkbox, Descriptions, Form, Input, Modal, Select, Space, Statistic, Table, Tabs, Tag, Tooltip, Typography, message } from 'antd'
import { useCallback, useEffect, useState } from 'react'
import {
  cancelNotificationCampaign,
  createNotificationCampaign,
  getNotificationTemplate,
  getOperationsDashboard,
  getOperationsResilience,
  getReleaseControl,
  listNotificationCampaigns,
  listNotificationDeliveries,
  listNotificationRules,
  listNotificationTemplates,
  listUatRuns,
  retryNotificationDelivery,
  saveNotificationTemplate,
  updateNotificationRule,
  updateFeatureFlag,
  type ManagedFeatureFlag,
  type ManagedNotificationCampaign,
  type ManagedNotificationDelivery,
  type ManagedNotificationRule,
  type ManagedNotificationTemplate,
  type ManagedRelease,
  type OperationsMetrics,
  type RuntimeResilience,
  type UatRun,
} from '../../api/admin'
import { useAdminAuth } from '../auth/AdminAuthContext'

interface TemplateFormValue { scene_code: string; channel: string; subject: string; body: string; allowed_variables: string; provider_template_id?: string }
interface RuleFormValue { enabled: boolean; channels: string[]; reason: string }
interface CampaignFormValue { name: string; title: string; body: string; channels: string[]; scheduled_at?: string }
interface CancelFormValue { reason: string }

const environmentNames: Record<string, string> = { local: '本地开发环境', testing: '自动测试环境', staging: '预发布环境', production: '生产环境' }
const uatStatusNames: Record<string, string> = { passed: '全部验收通过', passed_local: '本地自动验收通过', failed: '验收未通过' }
const featureModeNames: Record<string, string> = { disabled: '已关闭', percentage: '灰度开放', enabled: '已全量开放' }
const releaseStatusNames: Record<string, string> = { prepared: '待发布', canary: '灰度发布中', deployed: '已完成发布', rolled_back: '已回滚' }
const readinessCheckNames: Record<string, string> = { database: '数据库', cache: '缓存服务', storage: '文件存储', migrations: '数据库结构' }
const channelNames: Record<string, string> = { in_app: '站内消息', wechat: '微信消息（旧版）', wechat_mini: '小程序订阅消息', sms: '短信' }
const templateStatusNames: Record<string, string> = { draft: '草稿', published: '已发布' }
const campaignStatusNames: Record<string, string> = {
  draft: '草稿', ready: '待发送', scheduled: '计划中', sending: '发送中', completed: '已完成', cancelled: '已取消', failed: '发送失败',
}
const deliveryStatusNames: Record<string, string> = { retrying: '等待重试', failed: '发送失败' }
const sceneNames: Record<string, string> = {
  campaign_launch: '活动上线通知', marketing_campaign: '营销活动触达', payment_succeeded: '订单支付成功',
  task_reviewed: '任务审核结果', task_claim_expiring: '任务即将到期', refund_succeeded: '退款成功',
  post_review_approved: '帖子审核通过', post_review_rejected: '帖子审核未通过', content_moderated: '社区内容处置',
}

function sceneName(value: string) { return sceneNames[value] ?? '自定义业务场景' }
function formatDate(value?: string | null) { return value ? new Date(value).toLocaleString() : '—' }

function wechatTemplateBodyError(value: string) {
  let parsed: unknown
  try { parsed = JSON.parse(value) }
  catch { return '请输入有效的 JSON 对象' }
  if (!parsed || typeof parsed !== 'object' || Array.isArray(parsed) || !Object.keys(parsed).length) return '微信关键词内容必须是非空 JSON 对象'
  const entries = Object.entries(parsed)
  if (entries.some(([key]) => !/^(thing|character_string|phrase|name|phone_number|amount|number|date|time|letter|symbol|car_number)\d+$/.test(key))) return '关键词名不符合微信格式，例如 thing1、date2'
  const invalidValue = entries.some(([, item]) => {
    if (typeof item === 'string') return false
    return !item || typeof item !== 'object' || Array.isArray(item) || typeof (item as Record<string, unknown>).value !== 'string'
  })
  return invalidValue ? '每个关键词值必须是字符串，或包含字符串 value 字段的对象' : null
}

function releaseVersionName(value: string) {
  if (value === 'product-preview-v1') return '商品功能预览版 v1'
  const batch = value.match(/^batch(\d+)-local$/)
  if (batch) return `第 ${batch[1]} 批本地验收`
  return value
}

function releaseVersion(value: string) {
  const label = releaseVersionName(value)
  return <Space direction="vertical" size={0}><span>{label}</span>{label !== value && <Typography.Text type="secondary">标识：{value}</Typography.Text>}</Space>
}

export function OperationsPage() {
  const { admin } = useAdminAuth()
  const canManage = admin?.permissions.includes('operations.manage') ?? false
  const canDeploy = admin?.permissions.includes('deployment.manage') ?? false
  const [metrics, setMetrics] = useState<OperationsMetrics | null>(null)
  const [rules, setRules] = useState<ManagedNotificationRule[]>([])
  const [templates, setTemplates] = useState<ManagedNotificationTemplate[]>([])
  const [campaigns, setCampaigns] = useState<ManagedNotificationCampaign[]>([])
  const [deliveries, setDeliveries] = useState<ManagedNotificationDelivery[]>([])
  const [resilience, setResilience] = useState<RuntimeResilience | null>(null)
  const [uatRuns, setUatRuns] = useState<UatRun[]>([])
  const [featureFlags, setFeatureFlags] = useState<ManagedFeatureFlag[]>([])
  const [releases, setReleases] = useState<ManagedRelease[]>([])
  const [templateOpen, setTemplateOpen] = useState(false)
  const [editingRule, setEditingRule] = useState<ManagedNotificationRule | null>(null)
  const [editingTemplate, setEditingTemplate] = useState<ManagedNotificationTemplate | null>(null)
  const [templateDetail, setTemplateDetail] = useState<ManagedNotificationTemplate | null>(null)
  const [campaignOpen, setCampaignOpen] = useState(false)
  const [campaignDetail, setCampaignDetail] = useState<ManagedNotificationCampaign | null>(null)
  const [cancellingCampaign, setCancellingCampaign] = useState<ManagedNotificationCampaign | null>(null)
  const [submitting, setSubmitting] = useState(false)
  const [templateForm] = Form.useForm<TemplateFormValue>()
  const [ruleForm] = Form.useForm<RuleFormValue>()
  const [campaignForm] = Form.useForm<CampaignFormValue>()
  const [cancelForm] = Form.useForm<CancelFormValue>()
  const [messageApi, contextHolder] = message.useMessage()
  const templateChannel = Form.useWatch('channel', templateForm)
  const managedSceneName = (value: string) => rules.find((rule) => rule.scene_code === value)?.name ?? sceneName(value)

  const load = useCallback(async () => {
    try {
      const releaseRequest = canDeploy ? getReleaseControl() : Promise.resolve({ feature_flags: [], releases: [] })
      const [m, ruleResult, t, c, d, r, u, release] = await Promise.all([
        getOperationsDashboard(), listNotificationRules(), listNotificationTemplates(), listNotificationCampaigns(), listNotificationDeliveries(),
        getOperationsResilience(), listUatRuns(), releaseRequest,
      ])
      setMetrics(m); setRules(ruleResult.items); setTemplates(t.items); setCampaigns(c.items); setDeliveries(d.items); setResilience(r); setUatRuns(u.items)
      setFeatureFlags(release.feature_flags); setReleases(release.releases)
    } catch (error) { messageApi.error(error instanceof Error ? error.message : '运营数据加载失败') }
  }, [canDeploy, messageApi])

  useEffect(() => { void load() }, [load])

  const openTemplateEditor = async (item?: ManagedNotificationTemplate, preset?: { sceneCode: string; channel: string }) => {
    if (!canManage) return
    setSubmitting(true)
    try {
      const detail = item ? await getNotificationTemplate(item.id) : null
      setEditingTemplate(detail)
      const rule = preset ? rules.find((entry) => entry.scene_code === preset.sceneCode) : undefined
      templateForm.setFieldsValue(detail?.current_version ? {
        scene_code: detail.scene_code,
        channel: detail.channel,
        subject: detail.current_version.subject,
        body: detail.current_version.body,
        allowed_variables: detail.current_version.allowed_variables.join(','),
        provider_template_id: detail.current_version.provider_template_id ?? undefined,
      } : rule ? {
        scene_code: rule.scene_code,
        channel: preset?.channel ?? 'in_app',
        subject: rule.default_template.subject,
        body: preset?.channel === 'wechat_mini' ? '{\n  "thing1": "{{status_text}}",\n  "thing2": "{{occurred_at}}"\n}' : rule.default_template.body,
        allowed_variables: rule.allowed_variables.join(','),
        provider_template_id: undefined,
      } : { scene_code: '', channel: 'in_app', subject: '', body: '', allowed_variables: '' })
      setTemplateOpen(true)
    } catch (error) { messageApi.error(error instanceof Error ? error.message : '模板详情加载失败') }
    finally { setSubmitting(false) }
  }

  const openRuleEditor = (rule: ManagedNotificationRule) => {
    if (!canManage) return
    setEditingRule(rule)
    ruleForm.setFieldsValue({ enabled: rule.enabled, channels: rule.channels, reason: '' })
  }

  const saveRule = async (values: RuleFormValue) => {
    if (!editingRule || !canManage) return
    setSubmitting(true)
    try {
      await updateNotificationRule(editingRule.scene_code, { ...values, expected_version: editingRule.lock_version })
      setEditingRule(null); ruleForm.resetFields(); await load(); messageApi.success('通知规则已更新')
    } catch (error) { messageApi.error(error instanceof Error ? error.message : '通知规则更新失败') }
    finally { setSubmitting(false) }
  }

  const configureRuleTemplate = (rule: ManagedNotificationRule, channel: string) => {
    const templateId = rule.templates[channel]?.template_id
    const existing = templateId ? templates.find((item) => item.id === templateId) : undefined
    void openTemplateEditor(existing, { sceneCode: rule.scene_code, channel })
  }

  const viewTemplate = async (item: ManagedNotificationTemplate) => {
    setSubmitting(true)
    try { setTemplateDetail(await getNotificationTemplate(item.id)) }
    catch (error) { messageApi.error(error instanceof Error ? error.message : '模板详情加载失败') }
    finally { setSubmitting(false) }
  }

  const saveTemplate = async (values: TemplateFormValue) => {
    if (!canManage) return
    setSubmitting(true)
    try {
      await saveNotificationTemplate({
        ...values,
        provider_template_id: values.provider_template_id?.trim() || null,
        allowed_variables: values.allowed_variables.split(/[,，]/).map((value) => value.trim()).filter(Boolean),
        publish: true,
      })
      setTemplateOpen(false); setEditingTemplate(null); templateForm.resetFields(); await load(); messageApi.success('新的模板版本已发布')
    } catch (error) { messageApi.error(error instanceof Error ? error.message : '模板版本发布失败') }
    finally { setSubmitting(false) }
  }

  const createCampaign = async (values: CampaignFormValue) => {
    if (!canManage) return
    setSubmitting(true)
    try {
      await createNotificationCampaign({ ...values, scheduled_at: values.scheduled_at ? new Date(values.scheduled_at).toISOString() : null })
      setCampaignOpen(false); campaignForm.resetFields(); await load(); messageApi.success(values.scheduled_at ? '营销触达已进入计划队列' : '营销触达已进入待发送队列')
    } catch (error) { messageApi.error(error instanceof Error ? error.message : '营销触达创建失败') }
    finally { setSubmitting(false) }
  }

  const cancelCampaign = async (values: CancelFormValue) => {
    if (!cancellingCampaign || !canManage) return
    setSubmitting(true)
    try {
      await cancelNotificationCampaign(cancellingCampaign.id, values.reason)
      setCancellingCampaign(null); cancelForm.resetFields(); await load(); messageApi.success('营销触达已取消')
    } catch (error) { messageApi.error(error instanceof Error ? error.message : '营销触达取消失败') }
    finally { setSubmitting(false) }
  }

  const retryDelivery = async (delivery: ManagedNotificationDelivery) => {
    if (!canManage) return
    Modal.confirm({
      title: `重新投递“${delivery.title}”`,
      content: '该失败记录会重新进入发送队列，原失败次数和错误信息仍可从审计日志追溯。',
      okText: '确认重新投递', cancelText: '取消',
      onOk: async () => {
        await retryNotificationDelivery(delivery.id, '运营人员核对渠道配置后重新投递。')
        await load(); messageApi.success('已重新加入投递队列')
      },
    })
  }

  const setFlag = async (flag: ManagedFeatureFlag, percentage: number) => {
    if (!canDeploy) return
    try {
      await updateFeatureFlag(flag.code, { mode: percentage === 0 ? 'disabled' : percentage === 100 ? 'enabled' : 'percentage', rollout_percentage: percentage, audience: flag.audience ?? {}, expected_version: flag.version, reason: `后台发布控制台将灰度比例调整为 ${percentage}%。` })
      await load(); messageApi.success('功能开关已更新并留存版本')
    } catch (error) { messageApi.error(error instanceof Error ? error.message : '开关更新失败') }
  }

  return <>
    {contextHolder}
    <div className="page-heading"><div><Typography.Text className="eyebrow">校园运营与消息</Typography.Text><Typography.Title level={2}>运营与消息</Typography.Title><Typography.Paragraph type="secondary">核心业务指标、运行韧性、通知模板、营销订阅触达与失败投递统一管理。</Typography.Paragraph></div><Tag color="cyan">运营工作台</Tag></div>
    {metrics && <div className="stat-grid"><Card bordered={false}><Statistic title="活跃用户" value={metrics.users.active_total} /></Card><Card bordered={false}><Statistic title="今日支付订单" value={metrics.orders.paid_today} /></Card><Card bordered={false}><Statistic title="待审核内容" value={metrics.community.pending_reviews} /></Card><Card bordered={false}><Statistic title="失败通知" value={metrics.notifications.failed_deliveries} /></Card></div>}
    <Card bordered={false} title="运营待办" style={{ marginTop: 16 }}><Space wrap><Tag color="gold">任务待审 {metrics?.tasks.pending_reviews ?? 0}</Tag><Tag color="orange">待履约 {metrics?.orders.pending_fulfillment ?? 0}</Tag><Tag color="red">售后 {metrics?.after_sales.pending_refunds ?? 0}</Tag><Tag color="purple">举报 {metrics?.community.open_reports ?? 0}</Tag><Tag color="volcano">账务差异 {metrics?.finance.reconciliation_differences ?? 0}</Tag></Space></Card>
    <Card bordered={false} title="系统运行状态" style={{ marginTop: 16 }}><Space wrap><Tag color={resilience?.readiness.status === 'ready' ? 'green' : 'red'}>基础服务：{resilience?.readiness.status === 'ready' ? '运行正常' : '检查中或异常'}</Tag><Tag color={resilience?.consistency.healthy ? 'green' : 'red'}>业务数据：{resilience?.consistency.healthy ? '校验通过' : '存在异常'}</Tag>{Object.entries(resilience?.readiness.checks ?? {}).map(([name, check]) => <Tag key={name} color={check.ready ? 'cyan' : 'red'}>{readinessCheckNames[name] ?? '其他服务'}：{check.ready ? '正常' : '异常'}（{check.latency_ms} 毫秒）</Tag>)}</Space></Card>
    <Card bordered={false} title="业务状态通知规则" style={{ marginTop: 16 }}>
      <Alert showIcon type="info" message="任务、订单和帖子状态由系统统一触发；站内消息始终保留，小程序和短信需先发布对应渠道模板。" style={{ marginBottom: 16 }} />
      <Tabs items={[
        { key: 'task', label: '任务进度' }, { key: 'order', label: '订单状态' }, { key: 'community', label: '帖子发布' },
      ].map((tab) => ({
        ...tab,
        children: <Table rowKey="scene_code" size="small" dataSource={rules.filter((rule) => rule.domain === tab.key)} pagination={false} columns={[
          { title: '业务阶段', render: (_: unknown, row: ManagedNotificationRule) => <Space direction="vertical" size={0}><span>{row.name}</span><Typography.Text type="secondary">{row.scene_code}</Typography.Text></Space> },
          { title: '级别', render: (_: unknown, row: ManagedNotificationRule) => row.essential ? <Tag color="red">关键状态</Tag> : <Tag>普通状态</Tag> },
          { title: '启用渠道', render: (_: unknown, row: ManagedNotificationRule) => <Space size={[0, 4]} wrap>{row.channels.map((channel) => <Tag key={channel} color={channel === 'wechat_mini' ? 'green' : channel === 'sms' ? 'blue' : undefined}>{channelNames[channel] ?? channel}</Tag>)}</Space> },
          { title: '模板完整度', render: (_: unknown, row: ManagedNotificationRule) => <Space size={[0, 4]} wrap>{row.channels.map((channel) => <Tag key={channel} color={row.templates[channel]?.ready ? 'success' : 'error'}>{channelNames[channel] ?? channel}：{row.templates[channel]?.ready ? `v${row.templates[channel].version_no}` : '待配置'}</Tag>)}</Space> },
          { title: '状态', render: (_: unknown, row: ManagedNotificationRule) => <Tag color={row.enabled ? 'success' : 'default'}>{row.enabled ? '已启用' : '已停用'}</Tag> },
          { title: '操作', render: (_: unknown, row: ManagedNotificationRule) => canManage && <Space wrap><Button size="small" icon={<SettingOutlined />} onClick={() => openRuleEditor(row)}>规则</Button><Button size="small" onClick={() => configureRuleTemplate(row, 'in_app')}>站内模板</Button><Button size="small" onClick={() => configureRuleTemplate(row, 'wechat_mini')}>小程序模板</Button></Space> },
        ]} />,
      }))} />
    </Card>
    <Card bordered={false} title="通知模板" extra={canManage && <Button type="primary" icon={<PlusOutlined />} onClick={() => void openTemplateEditor()}>新增模板</Button>} style={{ marginTop: 16 }}>
      <Alert showIcon type="info" message="编辑不会覆盖已发布内容，而是基于当前内容生成并发布一个新的不可变版本。" style={{ marginBottom: 16 }} />
      <Table rowKey="id" dataSource={templates} pagination={false} columns={[
        { title: '业务场景', render: (_: unknown, row: ManagedNotificationTemplate) => <Space direction="vertical" size={0}><span>{managedSceneName(row.scene_code)}</span><Typography.Text type="secondary">场景标识：{row.scene_code}</Typography.Text></Space> },
        { title: '发送渠道', dataIndex: 'channel', render: (value: string) => channelNames[value] ?? '其他渠道' },
        { title: '当前版本', dataIndex: 'current_version_no', render: (value: number) => `v${value}` },
        { title: '状态', dataIndex: 'status', render: (value: string) => <Tag color={value === 'published' ? 'success' : 'default'}>{templateStatusNames[value] ?? '未知状态'}</Tag> },
        { title: '操作', render: (_: unknown, row: ManagedNotificationTemplate) => <Space><Button icon={<EyeOutlined />} loading={submitting} onClick={() => void viewTemplate(row)}>查看</Button>{canManage && <Button icon={<EditOutlined />} onClick={() => void openTemplateEditor(row)}>编辑并发布新版本</Button>}</Space> },
      ]} />
    </Card>
    <Card bordered={false} title="营销触达" extra={canManage && <Button type="primary" icon={<PlusOutlined />} onClick={() => { campaignForm.resetFields(); campaignForm.setFieldValue('channels', ['in_app']); setCampaignOpen(true) }}>创建触达</Button>} style={{ marginTop: 16 }}>
      <Alert showIcon type="warning" message="只向已订阅营销消息的活跃用户发送；发送中或已完成的触达不能取消。" style={{ marginBottom: 16 }} />
      <Table rowKey="id" dataSource={campaigns} pagination={false} columns={[
        { title: '名称', dataIndex: 'name' }, { title: '标题', dataIndex: 'title' },
        { title: '渠道', dataIndex: 'channels', render: (values: string[]) => <Space wrap>{values.map((value) => <Tag key={value}>{channelNames[value] ?? '其他渠道'}</Tag>)}</Space> },
        { title: '状态', dataIndex: 'status', render: (value: string) => <Tag color={value === 'completed' ? 'success' : value === 'cancelled' ? 'default' : value === 'failed' ? 'error' : 'processing'}>{campaignStatusNames[value] ?? '未知状态'}</Tag> },
        { title: '触达进度', render: (_: unknown, row: ManagedNotificationCampaign) => `${row.sent_count} / ${row.recipient_count}` },
        { title: '计划时间', dataIndex: 'scheduled_at', render: formatDate },
        { title: '操作', render: (_: unknown, row: ManagedNotificationCampaign) => <Space><Button icon={<EyeOutlined />} onClick={() => setCampaignDetail(row)}>查看</Button>{canManage && ['draft', 'ready', 'scheduled'].includes(row.status) && <Button danger icon={<StopOutlined />} onClick={() => { setCancellingCampaign(row); cancelForm.setFieldValue('reason', '') }}>取消</Button>}</Space> },
      ]} />
    </Card>
    <Card bordered={false} title="失败与重试中的投递" style={{ marginTop: 16 }}>
      <Table rowKey="id" dataSource={deliveries} pagination={false} locale={{ emptyText: '当前没有失败或等待重试的通知' }} columns={[
        { title: '消息', render: (_: unknown, row: ManagedNotificationDelivery) => <Space direction="vertical" size={0}><span>{row.title}</span><Typography.Text type="secondary">{managedSceneName(row.scene_code)}</Typography.Text></Space> },
        { title: '渠道', dataIndex: 'channel', render: (value: string) => channelNames[value] ?? '其他渠道' },
        { title: '状态', dataIndex: 'status', render: (value: string) => <Tag color={value === 'failed' ? 'error' : 'processing'}>{deliveryStatusNames[value] ?? '未知状态'}</Tag> },
        { title: '失败次数', dataIndex: 'retry_count' },
        { title: '错误说明', render: (_: unknown, row: ManagedNotificationDelivery) => row.error_message || '等待重新投递' },
        { title: '操作', render: (_: unknown, row: ManagedNotificationDelivery) => canManage && row.status === 'failed' ? <Button icon={<RedoOutlined />} onClick={() => void retryDelivery(row)}>重新投递</Button> : <Typography.Text type="secondary">系统队列处理中</Typography.Text> },
      ]} />
    </Card>
    <Card bordered={false} title="用户验收测试（UAT）记录" style={{ marginTop: 16 }}><Table rowKey="id" dataSource={uatRuns} pagination={false} columns={[{ title: '验收版本', dataIndex: 'release_version', render: releaseVersion }, { title: '运行环境', dataIndex: 'environment', render: (value: string) => environmentNames[value] ?? '其他环境' }, { title: '验收状态', render: (_: unknown, row: UatRun) => <Tag color={row.status === 'failed' ? 'red' : row.status === 'passed' ? 'green' : 'cyan'}>{uatStatusNames[row.status] ?? '未知状态'}</Tag> }, { title: '通过/阻塞', render: (_: unknown, row: UatRun) => `${row.summary.passed} 项通过 / ${row.summary.blocked} 项阻塞` }, { title: '报告校验码', render: (_: unknown, row: UatRun) => <Tooltip title="用于验证验收报告未被修改，点击可复制完整校验码"><Typography.Text code copyable={{ text: row.report_hash }}>报告 #{row.report_hash.slice(0, 8)}</Typography.Text></Tooltip> }, { title: '完成时间', dataIndex: 'completed_at', render: formatDate }]} /></Card>
    {canDeploy && <Card bordered={false} title="功能灰度发布" style={{ marginTop: 16 }}><Table rowKey="code" dataSource={featureFlags} pagination={false} columns={[{ title: '功能', render: (_: unknown, row: ManagedFeatureFlag) => <>{row.name}<Typography.Text type="secondary"> · {row.code}</Typography.Text></> }, { title: '开放状态', dataIndex: 'mode', render: (value: string) => <Tag color={value === 'disabled' ? 'default' : value === 'enabled' ? 'green' : 'cyan'}>{featureModeNames[value] ?? '未知状态'}</Tag> }, { title: '开放比例', render: (_: unknown, row: ManagedFeatureFlag) => `${row.rollout_percentage}% · 配置版本 v${row.version}` }, { title: '操作', render: (_: unknown, row: ManagedFeatureFlag) => <Space><Button size="small" onClick={() => void setFlag(row, 0)}>关闭</Button><Button size="small" onClick={() => void setFlag(row, 10)}>开放 10%</Button><Button size="small" type="primary" onClick={() => void setFlag(row, 100)}>全部开放</Button></Space> }]} /></Card>}
    {canDeploy && <Card bordered={false} title="系统发布记录" style={{ marginTop: 16 }}><Table rowKey="id" dataSource={releases} pagination={false} columns={[{ title: '发布版本', dataIndex: 'release_version', render: releaseVersion }, { title: '运行环境', dataIndex: 'environment', render: (value: string) => environmentNames[value] ?? '其他环境' }, { title: '发布状态', render: (_: unknown, row: ManagedRelease) => <Tag color={row.status === 'rolled_back' ? 'red' : row.status === 'deployed' ? 'green' : 'cyan'}>{releaseStatusNames[row.status] ?? '未知状态'}</Tag> }, { title: '开放比例', render: (_: unknown, row: ManagedRelease) => `${row.rollout_percentage}%` }, { title: '代码版本', render: (_: unknown, row: ManagedRelease) => <Tooltip title="代码提交标识"><Typography.Text code copyable={{ text: row.commit_sha }}>{row.commit_sha.slice(0, 10)}</Typography.Text></Tooltip> }]} /></Card>}

    <Modal title={editingRule ? `配置通知规则 · ${editingRule.name}` : '配置通知规则'} open={editingRule !== null} onCancel={() => setEditingRule(null)} onOk={() => ruleForm.submit()} okText="保存规则" cancelText="取消" confirmLoading={submitting} destroyOnHidden>
      {editingRule && <Form form={ruleForm} layout="vertical" onFinish={saveRule}>
        <Descriptions size="small" bordered column={1} items={[
          { key: 'scene', label: '业务场景', children: `${editingRule.domain_name} / ${editingRule.name}` },
          { key: 'level', label: '通知级别', children: editingRule.essential ? '关键业务状态（仅绕过免打扰，不绕过用户渠道选择）' : '普通业务状态' },
        ]} style={{ marginBottom: 16 }} />
        <Form.Item name="enabled" valuePropName="checked"><Checkbox>启用这个业务状态通知</Checkbox></Form.Item>
        <Form.Item name="channels" label="发送渠道" rules={[{ required: true, type: 'array', min: 1, message: '至少保留站内消息' }]}>
          <Checkbox.Group options={editingRule.allowed_channels.map((value) => ({ value, label: channelNames[value] ?? value, disabled: value === 'in_app' }))} />
        </Form.Item>
        {editingRule.allowed_channels.filter((channel) => channel !== 'in_app').map((channel) => !editingRule.templates[channel]?.ready && <Alert key={channel} showIcon type="warning" message={`${channelNames[channel]}模板尚未就绪，启用该渠道前请先发布模板。`} style={{ marginBottom: 12 }} />)}
        <Form.Item name="reason" label="变更原因" rules={[{ required: true, min: 5, message: '请填写至少 5 个字符的变更原因' }]}><Input.TextArea rows={3} maxLength={500} showCount /></Form.Item>
      </Form>}
    </Modal>
    <Modal title={editingTemplate ? `编辑模板 · 发布 v${editingTemplate.current_version_no + 1}` : '新增通知模板'} open={templateOpen} onCancel={() => { setTemplateOpen(false); setEditingTemplate(null) }} onOk={() => templateForm.submit()} okText="发布新版本" cancelText="取消" confirmLoading={submitting} destroyOnHidden>
      <Form form={templateForm} layout="vertical" onFinish={saveTemplate}>
        {editingTemplate && <Alert showIcon type="info" message="场景和渠道用于唯一识别模板，编辑时不可变更。" style={{ marginBottom: 16 }} />}
        <Form.Item name="scene_code" label="业务场景" rules={[{ required: true, message: '请选择业务场景' }]}><Select showSearch disabled={Boolean(editingTemplate)} optionFilterProp="label" options={[...rules.map((rule) => ({ value: rule.scene_code, label: `${rule.domain_name} / ${rule.name}` })), { value: 'campaign_launch', label: '营销 / 活动上线通知' }, { value: 'marketing_campaign', label: '营销 / 活动触达' }]} /></Form.Item>
        <Form.Item name="channel" label="发送渠道" rules={[{ required: true }]}><Select disabled={Boolean(editingTemplate)} options={Object.entries(channelNames).map(([value, label]) => ({ value, label }))} /></Form.Item>
        <Form.Item name="subject" label="消息标题" rules={[{ required: true, message: '请输入消息标题' }]}><Input maxLength={160} /></Form.Item>
        <Form.Item name="body" label={templateChannel === 'wechat_mini' ? '微信关键词内容（JSON）' : '消息正文'} extra={templateChannel === 'wechat_mini' ? '字段名必须与微信公众平台所选模板一致，字段值可使用下方白名单变量。' : undefined} rules={[
          { required: true, message: '请输入消息正文' },
          { validator: async (_, value?: string) => {
            if (templateChannel !== 'wechat_mini' || !value?.trim()) return
            const validationError = wechatTemplateBodyError(value)
            if (validationError) throw new Error(validationError)
          } },
        ]}><Input.TextArea rows={6} maxLength={2000} showCount /></Form.Item>
        <Form.Item name="allowed_variables" label="变量白名单" extra="多个变量用逗号分隔；正文中的 {{变量名}} 必须出现在白名单中。"><Input placeholder="nickname,order_no" /></Form.Item>
        <Form.Item name="provider_template_id" label={templateChannel === 'wechat_mini' ? '微信模板 ID' : '渠道模板编号（选填）'} rules={templateChannel === 'wechat_mini' ? [{ required: true, whitespace: true, message: '请输入微信模板 ID' }] : []}><Input maxLength={160} placeholder={templateChannel === 'wechat_mini' ? '请输入微信公众平台订阅消息模板 ID' : '微信或短信服务商模板编号'} /></Form.Item>
      </Form>
    </Modal>
    <Modal title="通知模板详情" width={760} open={templateDetail !== null} onCancel={() => setTemplateDetail(null)} footer={<Button onClick={() => setTemplateDetail(null)}>关闭</Button>}>
      {templateDetail && <><Descriptions bordered size="small" column={2} items={[
        { key: 'scene', label: '业务场景', children: managedSceneName(templateDetail.scene_code) },
        { key: 'channel', label: '发送渠道', children: channelNames[templateDetail.channel] ?? '其他渠道' },
        { key: 'version', label: '当前版本', children: `v${templateDetail.current_version_no}` },
        { key: 'status', label: '状态', children: templateStatusNames[templateDetail.status] ?? '未知状态' },
        { key: 'subject', label: '当前标题', span: 2, children: templateDetail.current_version?.subject ?? '—' },
        { key: 'body', label: '当前正文', span: 2, children: <Typography.Paragraph style={{ whiteSpace: 'pre-wrap', margin: 0 }}>{templateDetail.current_version?.body ?? '—'}</Typography.Paragraph> },
      ]} /><Typography.Title level={5} style={{ marginTop: 20 }}>版本记录</Typography.Title><Table rowKey="version_no" size="small" dataSource={templateDetail.versions ?? []} pagination={false} columns={[
        { title: '版本', dataIndex: 'version_no', render: (value: number) => `v${value}` },
        { title: '状态', dataIndex: 'status', render: (value: string) => templateStatusNames[value] ?? '未知状态' },
        { title: '标题', dataIndex: 'subject' },
        { title: '发布时间', dataIndex: 'published_at', render: formatDate },
      ]} /></>}
    </Modal>
    <Modal title="创建营销触达" open={campaignOpen} onCancel={() => setCampaignOpen(false)} onOk={() => campaignForm.submit()} okText="创建触达" cancelText="取消" confirmLoading={submitting} destroyOnHidden>
      <Form form={campaignForm} layout="vertical" onFinish={createCampaign} initialValues={{ channels: ['in_app'] }}>
        <Form.Item name="name" label="活动名称" rules={[{ required: true, message: '请输入活动名称' }]}><Input maxLength={120} /></Form.Item>
        <Form.Item name="title" label="消息标题" rules={[{ required: true, message: '请输入消息标题' }]}><Input maxLength={160} /></Form.Item>
        <Form.Item name="body" label="消息正文" rules={[{ required: true, message: '请输入消息正文' }]}><Input.TextArea rows={5} maxLength={2000} showCount /></Form.Item>
        <Form.Item name="channels" label="发送渠道" rules={[{ required: true, type: 'array', min: 1, message: '至少选择一个发送渠道' }]}><Select mode="multiple" options={Object.entries(channelNames).map(([value, label]) => ({ value, label }))} /></Form.Item>
        <Form.Item name="scheduled_at" label="计划发送时间（选填）" extra="留空则进入待发送队列；填写后将在计划时间由后台队列处理。"><Input type="datetime-local" /></Form.Item>
      </Form>
    </Modal>
    <Modal title="营销触达详情" open={campaignDetail !== null} onCancel={() => setCampaignDetail(null)} footer={<Button onClick={() => setCampaignDetail(null)}>关闭</Button>}>
      {campaignDetail && <Descriptions bordered size="small" column={1} items={[
        { key: 'name', label: '活动名称', children: campaignDetail.name }, { key: 'title', label: '消息标题', children: campaignDetail.title },
        { key: 'body', label: '消息正文', children: <Typography.Paragraph style={{ whiteSpace: 'pre-wrap', margin: 0 }}>{campaignDetail.body}</Typography.Paragraph> },
        { key: 'channels', label: '发送渠道', children: campaignDetail.channels.map((value) => channelNames[value] ?? '其他渠道').join('、') },
        { key: 'status', label: '当前状态', children: campaignStatusNames[campaignDetail.status] ?? '未知状态' },
        { key: 'progress', label: '触达进度', children: `${campaignDetail.sent_count} / ${campaignDetail.recipient_count}` },
        { key: 'scheduled', label: '计划时间', children: formatDate(campaignDetail.scheduled_at) },
        { key: 'completed', label: '完成时间', children: formatDate(campaignDetail.completed_at) },
      ]} />}
    </Modal>
    <Modal title="取消营销触达" open={cancellingCampaign !== null} onCancel={() => setCancellingCampaign(null)} onOk={() => cancelForm.submit()} okText="确认取消" cancelText="返回" okButtonProps={{ danger: true }} confirmLoading={submitting} destroyOnHidden>
      <Form form={cancelForm} layout="vertical" onFinish={cancelCampaign}><Alert showIcon type="warning" message="取消后不会再进入发送队列，操作会写入审计日志。" style={{ marginBottom: 16 }} /><Form.Item name="reason" label="取消原因" rules={[{ required: true, min: 5, message: '请填写至少 5 个字符的取消原因' }]}><Input.TextArea rows={4} maxLength={500} showCount /></Form.Item></Form>
    </Modal>
  </>
}
