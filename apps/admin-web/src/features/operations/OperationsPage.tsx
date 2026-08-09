import { Button, Card, Form, Input, Modal, Select, Space, Statistic, Table, Tag, Tooltip, Typography, message } from 'antd'
import { useCallback, useEffect, useState } from 'react'
import { createNotificationCampaign, getOperationsDashboard, getOperationsResilience, getReleaseControl, listNotificationCampaigns, listNotificationTemplates, listUatRuns, saveNotificationTemplate, updateFeatureFlag, type ManagedFeatureFlag, type ManagedNotificationCampaign, type ManagedNotificationTemplate, type ManagedRelease, type OperationsMetrics, type RuntimeResilience, type UatRun } from '../../api/admin'

const environmentNames: Record<string, string> = {
  local: '本地开发环境', testing: '自动测试环境', staging: '预发布环境', production: '生产环境',
}
const uatStatusNames: Record<string, string> = {
  passed: '全部验收通过', passed_local: '本地自动验收通过', failed: '验收未通过',
}
const featureModeNames: Record<string, string> = {
  disabled: '已关闭', percentage: '灰度开放', enabled: '已全量开放',
}
const releaseStatusNames: Record<string, string> = {
  prepared: '待发布', canary: '灰度发布中', deployed: '已完成发布', rolled_back: '已回滚',
}
const readinessCheckNames: Record<string, string> = {
  database: '数据库', cache: '缓存服务', storage: '文件存储', migrations: '数据库结构',
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
  const [metrics, setMetrics] = useState<OperationsMetrics | null>(null)
  const [templates, setTemplates] = useState<ManagedNotificationTemplate[]>([])
  const [campaigns, setCampaigns] = useState<ManagedNotificationCampaign[]>([])
  const [resilience, setResilience] = useState<RuntimeResilience | null>(null)
  const [uatRuns, setUatRuns] = useState<UatRun[]>([])
  const [featureFlags, setFeatureFlags] = useState<ManagedFeatureFlag[]>([]); const [releases, setReleases] = useState<ManagedRelease[]>([])
  const [templateOpen, setTemplateOpen] = useState(false); const [campaignOpen, setCampaignOpen] = useState(false)
  const [templateForm] = Form.useForm(); const [campaignForm] = Form.useForm(); const [messageApi, contextHolder] = message.useMessage()
  const load = useCallback(async () => { try { const [m, t, c, r, u, release] = await Promise.all([getOperationsDashboard(), listNotificationTemplates(), listNotificationCampaigns(), getOperationsResilience(), listUatRuns(), getReleaseControl().catch(() => ({ feature_flags: [], releases: [] }))]); setMetrics(m); setTemplates(t.items); setCampaigns(c.items); setResilience(r); setUatRuns(u.items); setFeatureFlags(release.feature_flags); setReleases(release.releases) } catch (error) { messageApi.error(error instanceof Error ? error.message : '运营数据加载失败') } }, [messageApi])
  useEffect(() => { void load() }, [load])
  const saveTemplate = async (values: Record<string, unknown>) => { try { await saveNotificationTemplate({ ...values, allowed_variables: String(values.allowed_variables || '').split(',').map((v) => v.trim()).filter(Boolean), publish: true }); setTemplateOpen(false); templateForm.resetFields(); await load(); messageApi.success('模板版本已发布') } catch (error) { messageApi.error(error instanceof Error ? error.message : '模板保存失败') } }
  const createCampaign = async (values: Record<string, unknown>) => { try { await createNotificationCampaign({ ...values, channels: ['in_app'] }); setCampaignOpen(false); campaignForm.resetFields(); await load(); messageApi.success('运营触达已创建') } catch (error) { messageApi.error(error instanceof Error ? error.message : '活动创建失败') } }
  const setFlag = async (flag: ManagedFeatureFlag, percentage: number) => { try { await updateFeatureFlag(flag.code, { mode: percentage === 0 ? 'disabled' : percentage === 100 ? 'enabled' : 'percentage', rollout_percentage: percentage, audience: flag.audience ?? {}, expected_version: flag.version, reason: `后台发布控制台将灰度比例调整为 ${percentage}%。` }); await load(); messageApi.success('功能开关已更新并留存版本') } catch (error) { messageApi.error(error instanceof Error ? error.message : '开关更新失败') } }
  return <>
    {contextHolder}<div className="page-heading"><div><Typography.Text className="eyebrow">OPERATIONS</Typography.Text><Typography.Title level={2}>运营与消息</Typography.Title><Typography.Paragraph type="secondary">核心业务指标、运行韧性、通知模板、营销订阅触达与失败投递统一管理。</Typography.Paragraph></div><Tag color="blue">第 27–28 批</Tag></div>
    {metrics && <div className="stat-grid"><Card bordered={false}><Statistic title="活跃用户" value={metrics.users.active_total} /></Card><Card bordered={false}><Statistic title="今日支付订单" value={metrics.orders.paid_today} /></Card><Card bordered={false}><Statistic title="待审核内容" value={metrics.community.pending_reviews} /></Card><Card bordered={false}><Statistic title="失败通知" value={metrics.notifications.failed_deliveries} /></Card></div>}
    <Card bordered={false} title="运营待办" style={{ marginTop: 16 }}><Space wrap><Tag color="gold">任务待审 {metrics?.tasks.pending_reviews ?? 0}</Tag><Tag color="orange">待履约 {metrics?.orders.pending_fulfillment ?? 0}</Tag><Tag color="red">售后 {metrics?.after_sales.pending_refunds ?? 0}</Tag><Tag color="purple">举报 {metrics?.community.open_reports ?? 0}</Tag><Tag color="volcano">账务差异 {metrics?.finance.reconciliation_differences ?? 0}</Tag></Space></Card>
    <Card bordered={false} title="系统运行状态" style={{ marginTop: 16 }}><Space wrap><Tag color={resilience?.readiness.status === 'ready' ? 'green' : 'red'}>基础服务：{resilience?.readiness.status === 'ready' ? '运行正常' : '检查中或异常'}</Tag><Tag color={resilience?.consistency.healthy ? 'green' : 'red'}>业务数据：{resilience?.consistency.healthy ? '校验通过' : '存在异常'}</Tag>{Object.entries(resilience?.readiness.checks ?? {}).map(([name, check]) => <Tag key={name} color={check.ready ? 'blue' : 'red'}>{readinessCheckNames[name] ?? name}：{check.ready ? '正常' : '异常'}（{check.latency_ms} 毫秒）</Tag>)}</Space></Card>
    <Card bordered={false} title="用户验收测试（UAT）记录" style={{ marginTop: 16 }}><Table rowKey="id" dataSource={uatRuns} pagination={false} columns={[{ title: '验收版本', dataIndex: 'release_version', render: releaseVersion }, { title: '运行环境', dataIndex: 'environment', render: (value: string) => environmentNames[value] ?? value }, { title: '验收状态', render: (_, row) => <Tag color={row.status === 'failed' ? 'red' : row.status === 'passed' ? 'green' : 'blue'}>{uatStatusNames[row.status] ?? row.status}</Tag> }, { title: '通过/阻塞', render: (_, row) => `${row.summary.passed} 项通过 / ${row.summary.blocked} 项阻塞` }, { title: '报告校验码', render: (_, row) => <Tooltip title="用于验证验收报告未被修改，点击可复制完整校验码"><Typography.Text code copyable={{ text: row.report_hash }}>报告 #{row.report_hash.slice(0, 8)}</Typography.Text></Tooltip> }, { title: '完成时间', dataIndex: 'completed_at', render: (value: string) => new Date(value).toLocaleString() }]} /></Card>
    <Card bordered={false} title="功能灰度发布" style={{ marginTop: 16 }}><Table rowKey="code" dataSource={featureFlags} pagination={false} columns={[{ title: '功能', render: (_, row) => <>{row.name}<Typography.Text type="secondary"> · {row.code}</Typography.Text></> }, { title: '开放状态', dataIndex: 'mode', render: (value: string) => <Tag color={value === 'disabled' ? 'default' : value === 'enabled' ? 'green' : 'blue'}>{featureModeNames[value] ?? value}</Tag> }, { title: '开放比例', render: (_, row) => `${row.rollout_percentage}% · 配置版本 v${row.version}` }, { title: '操作', render: (_, row) => <Space><Button size="small" onClick={() => void setFlag(row, 0)}>关闭</Button><Button size="small" onClick={() => void setFlag(row, 10)}>开放 10%</Button><Button size="small" type="primary" onClick={() => void setFlag(row, 100)}>全部开放</Button></Space> }]} /></Card>
    <Card bordered={false} title="系统发布记录" style={{ marginTop: 16 }}><Table rowKey="id" dataSource={releases} pagination={false} columns={[{ title: '发布版本', dataIndex: 'release_version', render: releaseVersion }, { title: '运行环境', dataIndex: 'environment', render: (value: string) => environmentNames[value] ?? value }, { title: '发布状态', render: (_, row) => <Tag color={row.status === 'rolled_back' ? 'red' : row.status === 'deployed' ? 'green' : 'blue'}>{releaseStatusNames[row.status] ?? row.status}</Tag> }, { title: '开放比例', render: (_, row) => `${row.rollout_percentage}%` }, { title: '代码版本', render: (_, row) => <Tooltip title="代码提交标识"><Typography.Text code copyable={{ text: row.commit_sha }}>{row.commit_sha.slice(0, 10)}</Typography.Text></Tooltip> }]} /></Card>
    <Card bordered={false} title="通知模板" extra={<Button type="primary" onClick={() => setTemplateOpen(true)}>新增并发布模板</Button>} style={{ marginTop: 16 }}><Table rowKey="id" dataSource={templates} pagination={false} columns={[{ title: '场景', dataIndex: 'scene_code' }, { title: '渠道', dataIndex: 'channel' }, { title: '版本', dataIndex: 'current_version_no' }, { title: '状态', render: (_, row) => <Tag color="green">{row.status}</Tag> }]} /></Card>
    <Card bordered={false} title="营销触达" extra={<Button type="primary" onClick={() => setCampaignOpen(true)}>创建触达</Button>} style={{ marginTop: 16 }}><Table rowKey="id" dataSource={campaigns} pagination={false} columns={[{ title: '名称', dataIndex: 'name' }, { title: '标题', dataIndex: 'title' }, { title: '状态', dataIndex: 'status' }, { title: '订阅用户', dataIndex: 'recipient_count' }, { title: '已生成', dataIndex: 'sent_count' }]} /></Card>
    <Modal title="通知模板" open={templateOpen} onCancel={() => setTemplateOpen(false)} onOk={() => templateForm.submit()}><Form form={templateForm} layout="vertical" onFinish={saveTemplate}><Form.Item name="scene_code" label="场景代码" rules={[{ required: true }]}><Input /></Form.Item><Form.Item name="channel" label="渠道" rules={[{ required: true }]}><Select options={[{ value: 'in_app', label: '站内信' }, { value: 'wechat', label: '微信' }, { value: 'sms', label: '短信' }]} /></Form.Item><Form.Item name="subject" label="标题" rules={[{ required: true }]}><Input /></Form.Item><Form.Item name="body" label="正文" rules={[{ required: true }]}><Input.TextArea rows={4} /></Form.Item><Form.Item name="allowed_variables" label="变量白名单（逗号分隔）"><Input placeholder="nickname,order_no" /></Form.Item></Form></Modal>
    <Modal title="创建营销触达" open={campaignOpen} onCancel={() => setCampaignOpen(false)} onOk={() => campaignForm.submit()}><Form form={campaignForm} layout="vertical" onFinish={createCampaign}><Form.Item name="name" label="活动名称" rules={[{ required: true }]}><Input /></Form.Item><Form.Item name="title" label="消息标题" rules={[{ required: true }]}><Input /></Form.Item><Form.Item name="body" label="消息正文" rules={[{ required: true }]}><Input.TextArea rows={4} /></Form.Item></Form></Modal>
  </>
}
