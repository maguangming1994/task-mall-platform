import { DeleteOutlined, EditOutlined, ExperimentOutlined, EyeOutlined, PlusOutlined, ReloadOutlined, SyncOutlined } from '@ant-design/icons'
import { Alert, Button, Form, Input, InputNumber, Modal, Popconfirm, Select, Space, Switch, Table, Tag, Tooltip, Typography, message } from 'antd'
import type { ColumnsType } from 'antd/es/table'
import { useCallback, useEffect, useRef, useState } from 'react'
import {
  batchUpdateXpRobotForwardStatus,
  deleteXpRobotForward,
  listXpRobotForwards,
  refreshXpRobotMessage,
  testXpRobotForward,
  updateXpRobotForwardStatus,
  type XpRobotForwardConfig,
  type XpRobotMessage,
} from '../../api/admin'
import { Pill } from '../../components/xq'
import { ForwardConfigSummary, ForwardConfigWizard } from './ForwardConfigWizard'
import { formatRobotTime, robotMessageStatus } from './robotUtils'
import { useRobotRecentVerification } from './useRobotRecentVerification'

interface Props {
  configured: boolean
  canManage: boolean
  canSend: boolean
  requiresMfa: boolean
  onMessageSaved: (record: XpRobotMessage) => void
}

interface FilterValues {
  name?: string
  enabled?: boolean
}

export function ForwardConfigPanel({ configured, canManage, canSend, requiresMfa, onMessageSaved }: Props) {
  const [filterForm] = Form.useForm<FilterValues>()
  const [messageApi, messageContext] = message.useMessage()
  const { runWrite, verificationContext } = useRobotRecentVerification(requiresMfa)
  const [items, setItems] = useState<XpRobotForwardConfig[]>([])
  const [filters, setFilters] = useState<FilterValues>({})
  const [loading, setLoading] = useState(false)
  const [wizardOpen, setWizardOpen] = useState(false)
  const [editing, setEditing] = useState<XpRobotForwardConfig | null>(null)
  const [detail, setDetail] = useState<XpRobotForwardConfig | null>(null)
  const [selectedIds, setSelectedIds] = useState<string[]>([])
  const [busyId, setBusyId] = useState<string | null>(null)
  const [batchBusy, setBatchBusy] = useState(false)
  const [testConfig, setTestConfig] = useState<XpRobotForwardConfig | null>(null)
  const [testTargetId, setTestTargetId] = useState('')
  const [testGroupId, setTestGroupId] = useState('')
  const [testPostCount, setTestPostCount] = useState(3)
  const [testMessage, setTestMessage] = useState<XpRobotMessage | null>(null)
  const [testing, setTesting] = useState(false)
  const [refreshing, setRefreshing] = useState(false)
  const testSubmission = useRef<{ fingerprint: string; key: string } | null>(null)

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const result = await listXpRobotForwards(filters)
      setItems(result.items)
      setSelectedIds((current) => current.filter((id) => result.items.some((item) => item.id === id)))
    } catch (error) {
      messageApi.error(error instanceof Error ? error.message : '转发配置加载失败。')
    } finally {
      setLoading(false)
    }
  }, [filters, messageApi])

  useEffect(() => { void load() }, [load])

  const replaceItem = (updated: XpRobotForwardConfig) => {
    setItems((current) => current.map((item) => item.id === updated.id ? updated : item))
    if (detail?.id === updated.id) setDetail(updated)
    if (testConfig?.id === updated.id) setTestConfig(updated)
  }

  const changeStatus = async (record: XpRobotForwardConfig, enabled: boolean) => {
    setBusyId(record.id)
    try {
      const updated = await runWrite(() => updateXpRobotForwardStatus(record.id, enabled), '转发配置状态调整失败。')
      if (!updated) return
      replaceItem(updated)
      messageApi.success(enabled ? '转发配置已启用。' : '转发配置已停用。')
    } finally {
      setBusyId(null)
    }
  }

  const remove = async (record: XpRobotForwardConfig) => {
    setBusyId(record.id)
    try {
      const result = await runWrite(() => deleteXpRobotForward(record.id), '转发配置删除失败。')
      if (!result) return
      setItems((current) => current.filter((item) => item.id !== record.id))
      setSelectedIds((current) => current.filter((id) => id !== record.id))
      messageApi.success('转发配置已删除。')
    } finally {
      setBusyId(null)
    }
  }

  const batchStatus = async (enabled: boolean) => {
    if (!selectedIds.length) return
    setBatchBusy(true)
    try {
      const result = await runWrite(() => batchUpdateXpRobotForwardStatus(selectedIds, enabled), '批量状态调整失败。')
      if (!result) return
      result.items.forEach(replaceItem)
      setSelectedIds([])
      messageApi.success(`已批量${enabled ? '启用' : '停用'} ${result.items.length} 条配置。`)
    } finally {
      setBatchBusy(false)
    }
  }

  const openWizard = (record: XpRobotForwardConfig | null) => {
    setEditing(record)
    setWizardOpen(true)
  }

  const openTest = (record: XpRobotForwardConfig) => {
    const target = record.robot_targets[0]
    setTestConfig(record)
    setTestTargetId(target?.target_id ?? '')
    setTestGroupId(target?.groups[0]?.group_id ?? '')
    setTestPostCount(3)
    setTestMessage(null)
    testSubmission.current = null
  }

  const submitTest = async () => {
    if (!testConfig || !testTargetId || !testGroupId) {
      messageApi.warning('请选择已保存的机器人目标和群。')
      return
    }
    const input = { target_id: testTargetId, group_id: testGroupId, post_count: testPostCount }
    const fingerprint = JSON.stringify({ id: testConfig.id, ...input })
    if (testSubmission.current?.fingerprint !== fingerprint) {
      testSubmission.current = { fingerprint, key: crypto.randomUUID() }
    }
    const idempotencyKey = testSubmission.current.key
    setTesting(true)
    try {
      const saved = await runWrite(() => testXpRobotForward(testConfig.id, input, idempotencyKey), '测试请求未完成；重试将沿用同一幂等键。')
      if (!saved) return
      testSubmission.current = null
      setTestMessage(saved)
      onMessageSaved(saved)
      void load()
      const status = robotMessageStatus(saved.status)
      if (saved.status === 'SUCCESS') messageApi.success('XP 已确认测试消息送达。')
      else if (saved.status === 'QUEUED' || saved.status === 'PROCESSING') messageApi.info(`测试消息${status.label}，请稍后刷新终态。`)
      else if (saved.status === 'SUBMIT_UNKNOWN') messageApi.warning('提交结果不确定，请勿直接重发；可按指令 ID 刷新状态。')
      else messageApi.warning(`测试消息当前状态：${status.label}。`)
    } finally {
      setTesting(false)
    }
  }

  const refreshTest = async () => {
    if (!testMessage?.cmd_id) return
    setRefreshing(true)
    try {
      const updated = await refreshXpRobotMessage(testMessage.id)
      setTestMessage(updated)
      onMessageSaved(updated)
      void load()
      messageApi.info(`状态已同步：${robotMessageStatus(updated.status).label}。`)
    } catch (error) {
      messageApi.error(error instanceof Error ? error.message : '消息状态刷新失败。')
    } finally {
      setRefreshing(false)
    }
  }

  const columns: ColumnsType<XpRobotForwardConfig> = [
    {
      title: '配置名称', width: 210, fixed: 'left',
      render: (_, record) => <div>
        <Typography.Text strong>{record.name}</Typography.Text>
        <Typography.Text type="secondary" ellipsis={{ tooltip: record.description }} style={{ display: 'block', marginTop: 4, fontSize: 12 }}>{record.description || '无描述'}</Typography.Text>
      </div>,
    },
    {
      title: '状态', width: 90,
      render: (_, record) => <Switch
        size="small"
        checked={record.enabled}
        disabled={!canManage || busyId === record.id}
        loading={busyId === record.id}
        checkedChildren="启用"
        unCheckedChildren="停用"
        onChange={(enabled) => void changeStatus(record, enabled)}
      />,
    },
    {
      title: '筛选摘要', width: 230,
      render: (_, record) => {
        const school = record.filter_rules.campus_name || record.filter_rules.campus_id
        const values = [
          ...record.filter_rules.platforms,
          ...(school ? [school] : []),
          ...record.filter_rules.categories.map((category) => category.name || category.code),
        ]
        return <div>
          <Space wrap size={[4, 4]}>{values.length ? values.slice(0, 4).map((value) => <Tag key={value}>{value}</Tag>) : <Tag>不限内容</Tag>}{values.length > 4 ? <Tag>+{values.length - 4}</Tag> : null}</Space>
          <Typography.Text type="secondary" style={{ display: 'block', marginTop: 6, fontSize: 12 }}>{record.filter_rules.aggregate_interval} 分钟 · {record.filter_rules.min_messages}–{record.filter_rules.max_messages} 条</Typography.Text>
        </div>
      },
    },
    {
      title: '消息模板', width: 240,
      render: (_, record) => <div>
        <Typography.Text ellipsis={{ tooltip: record.template_config.template }} style={{ maxWidth: 220 }}>{record.template_config.template}</Typography.Text>
        <Typography.Text type="secondary" style={{ display: 'block', marginTop: 4, fontSize: 12 }}>关键词 {record.template_config.keywords.length} 个 · 截取 {record.template_config.content_max_length || '不限'}</Typography.Text>
      </div>,
    },
    {
      title: '机器人目标', width: 150,
      render: (_, record) => <div>
        <Typography.Text strong>{record.robot_targets.length} 条目标</Typography.Text>
        <Typography.Text type="secondary" style={{ display: 'block', marginTop: 4, fontSize: 12 }}>共 {record.robot_targets.reduce((total, target) => total + target.groups.length, 0)} 个群</Typography.Text>
      </div>,
    },
    {
      title: '人工测试统计', width: 180,
      render: (_, record) => <div>
        <Typography.Text>测试 {record.stats.test_count} 次 · 确认成功 {record.stats.success_count} 次</Typography.Text>
        <Typography.Text type="secondary" style={{ display: 'block', marginTop: 4, fontSize: 12 }}>最后测试：{formatRobotTime(record.stats.last_tested_at)}</Typography.Text>
      </div>,
    },
    { title: '最近自动转发', width: 170, render: (_, record) => formatRobotTime(record.last_forwarded_at) },
    { title: '更新时间', width: 170, render: (_, record) => formatRobotTime(record.updated_at) },
    {
      title: '操作', width: 300, fixed: 'right',
      render: (_, record) => <Space size={0} wrap>
        <Button type="link" size="small" icon={<EyeOutlined />} onClick={() => setDetail(record)}>查看</Button>
        <Button type="link" size="small" icon={<EditOutlined />} disabled={!canManage} onClick={() => openWizard(record)}>编辑</Button>
        <Tooltip title={canManage && canSend ? '' : '需要 robot.settings_manage 与 robot.message_send 权限'}>
          <Button type="link" size="small" icon={<ExperimentOutlined />} disabled={!canManage || !canSend || !record.robot_targets.length} onClick={() => openTest(record)}>测试</Button>
        </Tooltip>
        <Popconfirm title="删除该转发配置？" description="删除后不再显示，历史测试消息仍保留来源标识。" okText="删除" cancelText="取消" okButtonProps={{ danger: true }} onConfirm={() => void remove(record)}>
          <Button type="link" size="small" danger icon={<DeleteOutlined />} disabled={!canManage}>删除</Button>
        </Popconfirm>
      </Space>,
    },
  ]

  const selectedTestTarget = testConfig?.robot_targets.find((target) => target.target_id === testTargetId)
  const testStatus = testMessage ? robotMessageStatus(testMessage.status) : null

  return <section className="xq-card xq-table" aria-label="转发配置管理">
    {messageContext}
    {verificationContext}
    <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: 12, flexWrap: 'wrap', marginBottom: 16 }}>
      <div>
        <p className="xq-card-title" style={{ margin: 0 }}>转发配置</p>
        <Typography.Text type="secondary">最多展示最近 100 条；自动采集与调度不在本期范围内。</Typography.Text>
      </div>
      <Space wrap>
        <Button icon={<ReloadOutlined />} loading={loading} onClick={() => void load()}>刷新</Button>
        <Button type="primary" icon={<PlusOutlined />} disabled={!canManage} onClick={() => openWizard(null)}>新增配置</Button>
      </Space>
    </div>
    {!canManage ? <Alert showIcon type="warning" message="当前管理员只有查看权限，新增、编辑、删除、启停和测试需要 robot.settings_manage 权限。" style={{ marginBottom: 16 }} /> : null}
    <Form<FilterValues> form={filterForm} layout="inline" style={{ marginBottom: 16, gap: 8 }} onFinish={(values) => setFilters({ name: values.name?.trim() || undefined, enabled: values.enabled })}>
      <Form.Item name="name" label="配置名称"><Input allowClear placeholder="按名称搜索" style={{ width: 220 }} /></Form.Item>
      <Form.Item name="enabled" label="状态"><Select allowClear placeholder="全部状态" style={{ width: 130 }} options={[{ value: true, label: '启用' }, { value: false, label: '停用' }]} /></Form.Item>
      <Form.Item><Space><Button type="primary" htmlType="submit">查询</Button><Button onClick={() => { filterForm.resetFields(); setFilters({}) }}>重置</Button></Space></Form.Item>
    </Form>
    <Space wrap style={{ marginBottom: 12 }}>
      <Button disabled={!canManage || !selectedIds.length} loading={batchBusy} onClick={() => void batchStatus(true)}>批量启用{selectedIds.length ? `（${selectedIds.length}）` : ''}</Button>
      <Button disabled={!canManage || !selectedIds.length} loading={batchBusy} onClick={() => void batchStatus(false)}>批量停用</Button>
      {selectedIds.length ? <Button type="link" onClick={() => setSelectedIds([])}>取消选择</Button> : null}
    </Space>
    <Table<XpRobotForwardConfig>
      rowKey="id"
      loading={loading}
      columns={columns}
      dataSource={items}
      scroll={{ x: 1640 }}
      rowSelection={canManage ? { selectedRowKeys: selectedIds, onChange: (keys) => setSelectedIds(keys.map(String)) } : undefined}
      pagination={{ pageSize: 10, showTotal: (total) => `共 ${total} 条` }}
      locale={{ emptyText: '暂无转发配置' }}
    />

    <ForwardConfigWizard
      open={wizardOpen}
      config={editing}
      configured={configured}
      requiresMfa={requiresMfa}
      onClose={() => setWizardOpen(false)}
      onSaved={(saved) => { replaceItem(saved); void load() }}
    />

    <Modal title={detail ? `转发配置详情：${detail.name}` : '转发配置详情'} open={!!detail} onCancel={() => setDetail(null)} footer={<Button onClick={() => setDetail(null)}>关闭</Button>} width={920}>
      {detail ? <ForwardConfigSummary value={detail} /> : null}
    </Modal>

    <Modal
      title={testConfig ? `人工测试转发：${testConfig.name}` : '人工测试转发'}
      open={!!testConfig}
      onCancel={() => { if (!testing) setTestConfig(null) }}
      width={640}
      maskClosable={!testing}
      footer={<Space>
        <Button onClick={() => setTestConfig(null)} disabled={testing}>关闭</Button>
        <Button type="primary" icon={<ExperimentOutlined />} loading={testing} disabled={!testTargetId || !testGroupId} onClick={() => void submitTest()}>提交一条测试消息</Button>
      </Space>}
    >
      <Alert showIcon type="warning" message="测试边界" description="从符合当前筛选条件的历史帖子中按发布时间倒序取指定条数，用模板变量渲染为一条文本提交到一个已保存目标群；不模拟聚合、静默或外部数据过滤。入队不等于最终送达。" style={{ marginBottom: 18 }} />
      <Form layout="vertical">
        <Form.Item label="机器人目标" required>
          <Select
            value={testTargetId || undefined}
            options={testConfig?.robot_targets.map((target) => ({ value: target.target_id, label: `${target.device_name || target.device_id} · ${target.account_name || target.wxid}` }))}
            onChange={(value) => {
              setTestTargetId(value)
              setTestGroupId(testConfig?.robot_targets.find((target) => target.target_id === value)?.groups[0]?.group_id ?? '')
              setTestMessage(null)
            }}
          />
        </Form.Item>
        <Form.Item label="目标群" required>
          <Select
            value={testGroupId || undefined}
            options={selectedTestTarget?.groups.map((group) => ({ value: group.group_id, label: `${group.group_name || group.group_id}（${group.group_id}）` }))}
            onChange={(value) => { setTestGroupId(value); setTestMessage(null) }}
          />
        </Form.Item>
        <Form.Item label="历史帖子条数" required extra="取符合筛选条件的已发布帖子，按发布时间倒序，最多 10 条。">
          <InputNumber min={1} max={10} precision={0} value={testPostCount} onChange={(value) => { setTestPostCount(value ?? 1); setTestMessage(null) }} style={{ width: 120 }} />
        </Form.Item>
      </Form>
      {testMessage && testStatus ? <Alert
        type={testMessage.status === 'SUCCESS' ? 'success' : ['FAILED', 'TIMEOUT', 'SUBMIT_FAILED', 'SUBMIT_UNKNOWN'].includes(testMessage.status) ? 'warning' : 'info'}
        showIcon
        message={<Space wrap><Pill tone={testStatus.tone}>{testStatus.label}</Pill><Typography.Text>消息 ID：{testMessage.id}</Typography.Text></Space>}
        description={<div>
          <div>指令 ID：{testMessage.cmd_id || '未返回'}</div>
          <div>提交时间：{formatRobotTime(testMessage.submitted_at ?? testMessage.created_at)}</div>
          {testMessage.error_message ? <div>{testMessage.error_message}</div> : null}
          <Button size="small" icon={<SyncOutlined />} loading={refreshing} disabled={!testMessage.cmd_id} onClick={() => void refreshTest()} style={{ marginTop: 10 }}>刷新 XP 终态</Button>
        </div>}
      /> : null}
    </Modal>
  </section>
}
