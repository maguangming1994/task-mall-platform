import { CopyOutlined, HistoryOutlined, PlusOutlined, RocketOutlined } from '@ant-design/icons'
import { Alert, Button, Card, Form, Input, InputNumber, Modal, Select, Space, Table, Tag, Typography, message } from 'antd'
import { useCallback, useEffect, useState } from 'react'
import {
  ApiError,
  changeManagedTaskTemplateStatus,
  copyManagedTaskTemplate,
  createManagedTaskTemplate,
  createTaskFromManagedTemplate,
  getManagedTaskTemplate,
  listManagedTaskTemplates,
  listManagedTaskTemplateVersions,
  type ManagedTask,
  type ManagedTaskTemplate,
  type ManagedTaskTemplateVersion,
  type RewardBudgetPool,
} from '../../api/admin'

interface Props { tasks: ManagedTask[]; pools: RewardBudgetPool[]; canManage: boolean; onTaskCreated: () => Promise<void> }
interface CreateForm { source_task_id: string; code: string; name: string; description?: string }
interface CopyForm { code: string; name: string }
interface ApplyForm { reward_pool_ids: string[]; reward_quota_total?: number; claim_starts_at: string; claim_ends_at: string; submit_ends_at: string }

function reusableContent(task: ManagedTask): Record<string, unknown> {
  const ignored = new Set(['id', 'status', 'version', 'task_version', 'reward_budgets', 'reward_budget_bindings', 'reward_budget_summaries', 'claim_starts_at', 'claim_ends_at', 'submit_ends_at', 'publisher', 'publish_approval', 'source_task_template', 'creation_channel'])
  return Object.fromEntries(Object.entries(task).filter(([key]) => !ignored.has(key)))
}

function rewardPoolType(rewardType: string) { return rewardType === 'wallet_balance' ? 'wallet_cash' : rewardType }

export function TaskTemplatePanel({ tasks, pools, canManage, onTaskCreated }: Props) {
  const [items, setItems] = useState<ManagedTaskTemplate[]>([])
  const [loading, setLoading] = useState(false)
  const [createOpen, setCreateOpen] = useState(false)
  const [copying, setCopying] = useState<ManagedTaskTemplate | null>(null)
  const [applying, setApplying] = useState<ManagedTaskTemplate | null>(null)
  const [versionsOf, setVersionsOf] = useState<ManagedTaskTemplate | null>(null)
  const [versions, setVersions] = useState<ManagedTaskTemplateVersion[]>([])
  const [createForm] = Form.useForm<CreateForm>()
  const [copyForm] = Form.useForm<CopyForm>()
  const [applyForm] = Form.useForm<ApplyForm>()
  const [messageApi, contextHolder] = message.useMessage()

  const load = useCallback(async () => {
    setLoading(true)
    try { setItems((await listManagedTaskTemplates()).items) }
    catch (cause) { messageApi.error(cause instanceof ApiError ? cause.message : '任务模板加载失败') }
    finally { setLoading(false) }
  }, [messageApi])
  useEffect(() => { void load() }, [load])

  const createFromTask = async (values: CreateForm) => {
    const task = tasks.find((item) => item.id === values.source_task_id)
    if (!task) return
    try {
      await createManagedTaskTemplate({ code: values.code, name: values.name, description: values.description, category: task.category, content: reusableContent(task) })
      setCreateOpen(false); createForm.resetFields(); await load(); messageApi.success('任务模板已创建')
    } catch (cause) { messageApi.error(cause instanceof ApiError ? cause.message : '创建任务模板失败') }
  }

  const copyTemplate = async (values: CopyForm) => {
    if (!copying) return
    try { await copyManagedTaskTemplate(copying.id, values); setCopying(null); copyForm.resetFields(); await load(); messageApi.success('模板副本已创建') }
    catch (cause) { messageApi.error(cause instanceof ApiError ? cause.message : '复制模板失败') }
  }

  const toggle = async (item: ManagedTaskTemplate) => {
    try {
      await changeManagedTaskTemplateStatus(item.id, { action: item.status === 'active' ? 'disable' : 'enable', version: item.version, reason: item.status === 'active' ? '后台暂停使用该模板' : '后台恢复使用该模板' })
      await load(); messageApi.success(item.status === 'active' ? '模板已停用' : '模板已启用')
    } catch (cause) { messageApi.error(cause instanceof ApiError ? cause.message : '模板状态更新失败') }
  }

  const openVersions = async (item: ManagedTaskTemplate) => {
    setVersionsOf(item)
    try { setVersions(await listManagedTaskTemplateVersions(item.id)) }
    catch (cause) { messageApi.error(cause instanceof ApiError ? cause.message : '版本记录加载失败') }
  }

  const openApply = async (item: ManagedTaskTemplate) => {
    try {
      const detail = await getManagedTaskTemplate(item.id)
      setApplying(detail)
      const start = new Date(Date.now() + 5 * 60 * 1000)
      const claimEnd = new Date(Date.now() + 5 * 86400000)
      const submitEnd = new Date(Date.now() + 7 * 86400000)
      applyForm.setFieldsValue({ reward_pool_ids: [], reward_quota_total: Number(detail.content?.reward_quota_total ?? 100), claim_starts_at: start.toISOString().slice(0, 16), claim_ends_at: claimEnd.toISOString().slice(0, 16), submit_ends_at: submitEnd.toISOString().slice(0, 16) })
    } catch (cause) { messageApi.error(cause instanceof ApiError ? cause.message : '模板详情加载失败') }
  }

  const applyTemplate = async (values: ApplyForm) => {
    if (!applying) return
    const rewards = Array.isArray(applying.content?.rewards) ? applying.content?.rewards as Array<{ type: string; coupon_template_id?: string }> : []
    try {
      await createTaskFromManagedTemplate(applying.id, {
        reward_budgets: rewards.map((_, index) => ({ reward_sequence: index + 1, budget_pool_id: values.reward_pool_ids[index] })),
        reward_quota_total: values.reward_quota_total,
        claim_starts_at: new Date(values.claim_starts_at).toISOString(), claim_ends_at: new Date(values.claim_ends_at).toISOString(), submit_ends_at: new Date(values.submit_ends_at).toISOString(),
      })
      setApplying(null); applyForm.resetFields(); await onTaskCreated(); messageApi.success('已从模板生成独立任务草稿')
    } catch (cause) { messageApi.error(cause instanceof ApiError ? cause.message : '套用模板失败') }
  }

  const rewards = Array.isArray(applying?.content?.rewards) ? applying?.content?.rewards as Array<{ type: string; coupon_template_id?: string }> : []
  return <>
    {contextHolder}
    <Card bordered={false} style={{ marginTop: 16 }} title={<Space direction="vertical" size={0}><span>任务模板中心</span><Typography.Text type="secondary">复用步骤和规则，实际预算池与时间在套用时重新选择</Typography.Text></Space>} extra={canManage && <Button type="primary" icon={<PlusOutlined />} onClick={() => setCreateOpen(true)}>从已有任务创建模板</Button>}>
      <Alert showIcon type="info" message="模板版本不可覆盖；模板停用或更新不会改变已经生成、发布或领取的任务。" style={{ marginBottom: 16 }} />
      <Table rowKey="id" loading={loading} dataSource={items} pagination={false} locale={{ emptyText: '暂无模板，可从现有任务创建第一个模板' }} columns={[
        { title: '模板', render: (_: unknown, item: ManagedTaskTemplate) => <Space direction="vertical" size={0}><strong>{item.name}</strong><Typography.Text type="secondary">{item.code} · 内容 v{item.current_version_no}</Typography.Text></Space> },
        { title: '分类', dataIndex: 'category', render: (value: string | null) => value || '未分类' },
        { title: '状态', render: (_: unknown, item: ManagedTaskTemplate) => <Tag color={item.status === 'active' ? 'success' : 'default'}>{item.status_label}</Tag> },
        { title: '操作', render: (_: unknown, item: ManagedTaskTemplate) => <Space wrap><Button icon={<HistoryOutlined />} onClick={() => void openVersions(item)}>版本</Button>{canManage && <><Button icon={<CopyOutlined />} onClick={() => { setCopying(item); copyForm.setFieldsValue({ code: `${item.code}-copy`, name: `${item.name}副本` }) }}>复制</Button><Button onClick={() => void toggle(item)}>{item.status === 'active' ? '停用' : '启用'}</Button><Button type="primary" icon={<RocketOutlined />} disabled={item.status !== 'active'} onClick={() => void openApply(item)}>套用为任务草稿</Button></>}</Space> },
      ]} />
    </Card>
    <Modal title="从已有任务创建模板" open={createOpen} onCancel={() => setCreateOpen(false)} onOk={() => createForm.submit()} okText="创建模板" destroyOnHidden><Form form={createForm} layout="vertical" onFinish={createFromTask}><Form.Item name="source_task_id" label="来源任务" rules={[{ required: true }]}><Select options={tasks.map((item) => ({ value: item.id, label: `${item.title} · v${item.task_version.version_no}` }))} /></Form.Item><Form.Item name="code" label="模板编码" rules={[{ required: true }, { pattern: /^[a-z0-9][a-z0-9._-]{2,63}$/, message: '仅支持小写字母、数字、点、下划线和短横线' }]}><Input placeholder="campus-research-basic" /></Form.Item><Form.Item name="name" label="模板名称" rules={[{ required: true }]}><Input /></Form.Item><Form.Item name="description" label="适用场景"><Input.TextArea rows={3} /></Form.Item></Form></Modal>
    <Modal title={`复制模板 · ${copying?.name ?? ''}`} open={copying !== null} onCancel={() => setCopying(null)} onOk={() => copyForm.submit()} okText="创建副本" destroyOnHidden><Form form={copyForm} layout="vertical" onFinish={copyTemplate}><Form.Item name="code" label="副本编码" rules={[{ required: true }]}><Input /></Form.Item><Form.Item name="name" label="副本名称" rules={[{ required: true }]}><Input /></Form.Item></Form></Modal>
    <Modal title={`套用模板 · ${applying?.name ?? ''}`} open={applying !== null} onCancel={() => setApplying(null)} onOk={() => applyForm.submit()} okText="生成任务草稿" width={720} destroyOnHidden><Form form={applyForm} layout="vertical" onFinish={applyTemplate}><Alert showIcon type="warning" message="套用只生成草稿，不锁定预算；正式发布时仍会执行预算复核与原子锁定。" style={{ marginBottom: 16 }} />{rewards.map((reward, index) => { const options = pools.filter((pool) => pool.asset_type === rewardPoolType(reward.type) && (reward.type !== 'coupon' || pool.coupon_template_id === reward.coupon_template_id)); return <Form.Item key={index} name={['reward_pool_ids', index]} label={`奖励 ${index + 1} 预算池`} rules={[{ required: true }]}><Select options={options.map((pool) => ({ value: pool.id, label: `${pool.name}（可用 ${pool.available_amount}）` }))} /></Form.Item> })}<Form.Item name="reward_quota_total" label="本次奖励总名额" rules={[{ required: true }]}><InputNumber min={1} style={{ width: '100%' }} /></Form.Item><Space wrap><Form.Item name="claim_starts_at" label="领取开始" rules={[{ required: true }]}><Input type="datetime-local" /></Form.Item><Form.Item name="claim_ends_at" label="领取截止" rules={[{ required: true }]}><Input type="datetime-local" /></Form.Item><Form.Item name="submit_ends_at" label="提交截止" rules={[{ required: true }]}><Input type="datetime-local" /></Form.Item></Space></Form></Modal>
    <Modal title={`模板版本 · ${versionsOf?.name ?? ''}`} open={versionsOf !== null} footer={null} onCancel={() => { setVersionsOf(null); setVersions([]) }} width={800}><Table rowKey="version_no" dataSource={versions} pagination={false} columns={[{ title: '版本', dataIndex: 'version_no', render: (value: number) => `v${value}` }, { title: '来源版本', dataIndex: 'source_version_no', render: (value: number | null) => value ? `v${value}` : '初始版本' }, { title: '变更字段', dataIndex: 'changed_fields', render: (value: string[]) => value.join('、') }, { title: '创建时间', dataIndex: 'created_at', render: (value: string) => new Date(value).toLocaleString() }]} /></Modal>
  </>
}
