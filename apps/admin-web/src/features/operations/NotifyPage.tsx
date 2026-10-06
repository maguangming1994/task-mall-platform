import { Button, Form, Input, Modal, Select, Switch, Table, Tag, message } from 'antd'
import { useCallback, useEffect, useMemo, useState } from 'react'
import {
  ApiError,
  cancelNotificationCampaign,
  createNotificationCampaign,
  listNotificationCampaigns,
  listNotificationRules,
  listNotificationTemplates,
  updateNotificationRule,
  type ManagedNotificationCampaign,
  type ManagedNotificationRule,
  type ManagedNotificationTemplate,
} from '../../api/admin'
import { Links, PageHead, Pill, StatCard, type PillTone } from '../../components/xq'

type TabKey = 'records' | 'templates' | 'rules'

const channelLabels: Record<string, string> = { in_app: '站内信', wechat_mini: '小程序订阅', sms: '短信', push: 'App 推送', email: '邮件' }
const channelLabel = (value: string) => channelLabels[value] ?? value

const campaignStatusMeta: Record<string, { label: string; tone: PillTone }> = {
  completed: { label: '已发送', tone: 'green' },
  scheduled: { label: '定时待发', tone: 'blue' },
  sending: { label: '发送中', tone: 'orange' },
  cancelled: { label: '已取消', tone: 'gray' },
  failed: { label: '发送失败', tone: 'red' },
  draft: { label: '草稿', tone: 'gray' },
}

const fmtTime = (value: string | null) => (value ? new Date(value).toLocaleString('zh-CN', { hour12: false }) : '—')

interface CampaignFormValue { name: string; title: string; body: string; channels: string[]; scheduled_at?: string }

export function NotifyPage() {
  const [tab, setTab] = useState<TabKey>('records')
  const [campaigns, setCampaigns] = useState<ManagedNotificationCampaign[]>([])
  const [templates, setTemplates] = useState<ManagedNotificationTemplate[]>([])
  const [rules, setRules] = useState<ManagedNotificationRule[]>([])
  const [loading, setLoading] = useState(true)
  const [channel, setChannel] = useState('')
  const [kw, setKw] = useState('')
  const [applied, setApplied] = useState({ channel: '', kw: '' })
  const [campaignOpen, setCampaignOpen] = useState(false)
  const [detail, setDetail] = useState<ManagedNotificationCampaign | null>(null)
  const [submitting, setSubmitting] = useState(false)
  const [campaignForm] = Form.useForm<CampaignFormValue>()
  const [messageApi, contextHolder] = message.useMessage()

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const [campaignData, templateData, ruleData] = await Promise.all([listNotificationCampaigns(), listNotificationTemplates(), listNotificationRules()])
      setCampaigns(campaignData.items)
      setTemplates(templateData.items)
      setRules(ruleData.items)
    } catch (error) {
      messageApi.error(error instanceof ApiError ? error.message : '消息推送数据加载失败。')
    } finally {
      setLoading(false)
    }
  }, [messageApi])

  useEffect(() => { void load() }, [load])

  const filteredCampaigns = useMemo(() => campaigns.filter((item) => {
    if (applied.channel && !item.channels.includes(applied.channel)) return false
    if (applied.kw && !item.title.includes(applied.kw) && !item.name.includes(applied.kw)) return false
    return true
  }), [campaigns, applied])

  const stats = useMemo(() => {
    const today = new Date().toDateString()
    const sentToday = campaigns
      .filter((item) => (item.completed_at ?? item.created_at) && new Date((item.completed_at ?? item.created_at) as string).toDateString() === today)
      .reduce((sum, item) => sum + item.sent_count, 0)
    const totalSent = campaigns.reduce((sum, item) => sum + item.sent_count, 0)
    const enabledTemplates = templates.filter((item) => item.status === 'enabled').length
    return { sentToday, totalSent, templateCount: templates.length, enabledTemplates }
  }, [campaigns, templates])

  const openCreate = (preset?: ManagedNotificationCampaign) => {
    campaignForm.setFieldsValue(preset
      ? { name: `${preset.name}（复制）`, title: preset.title, body: preset.body, channels: preset.channels, scheduled_at: undefined }
      : { name: '', title: '', body: '', channels: ['in_app'], scheduled_at: undefined })
    setCampaignOpen(true)
  }

  const saveCampaign = async () => {
    try {
      const values = await campaignForm.validateFields()
      setSubmitting(true)
      await createNotificationCampaign({
        name: values.name,
        title: values.title,
        body: values.body,
        channels: values.channels,
        scheduled_at: values.scheduled_at ? new Date(values.scheduled_at).toISOString() : null,
      })
      messageApi.success(values.scheduled_at ? '推送已进入计划队列。' : '推送已进入待发送队列。')
      setCampaignOpen(false)
      campaignForm.resetFields()
      await load()
    } catch (error) {
      if (error instanceof ApiError) messageApi.error(error.message)
    } finally {
      setSubmitting(false)
    }
  }

  const cancelCampaign = async (item: ManagedNotificationCampaign) => {
    try {
      await cancelNotificationCampaign(item.id, '运营人员在后台取消该定时推送。')
      messageApi.success('推送已取消。')
      await load()
    } catch (error) {
      messageApi.error(error instanceof ApiError ? error.message : '取消失败。')
    }
  }

  const toggleRule = async (rule: ManagedNotificationRule, enabled: boolean) => {
    try {
      await updateNotificationRule(rule.scene_code, { enabled, channels: rule.channels, expected_version: rule.lock_version, reason: `后台消息通知页${enabled ? '开启' : '关闭'}订阅场景「${rule.name}」。` })
      messageApi.success('订阅配置已更新。')
      await load()
    } catch (error) {
      messageApi.error(error instanceof ApiError ? error.message : '订阅配置更新失败。')
    }
  }

  const recordsTab = <>
    <div className="xq-stats">
      <StatCard title="今日发送" value={stats.sentToday.toLocaleString()} delta={`累计发送 ${stats.totalSent.toLocaleString()} 人次`} />
      <StatCard title="今日点击" value="—" delta="渠道未回传点击数据" />
      <StatCard title="模板总数" value={stats.templateCount} delta={<span className="up" style={{ color: '#16a34a' }}>启用 {stats.enabledTemplates}</span>} />
      <StatCard title="退订用户" value="—" delta="暂无退订统计数据" />
    </div>
    <div className="xq-card xq-filter" style={{ marginBottom: 16 }}>
      <Select allowClear placeholder="全部类型" value={channel || undefined} onChange={(value) => setChannel(value ?? '')} options={Object.entries(channelLabels).map(([value, label]) => ({ value, label }))} />
      <Input allowClear placeholder="推送标题" value={kw} onChange={(event) => setKw(event.target.value)} onPressEnter={() => setApplied({ channel, kw: kw.trim() })} />
      <Button type="primary" onClick={() => setApplied({ channel, kw: kw.trim() })}>查询</Button>
    </div>
    <div className="xq-card xq-table">
      <Table
        rowKey="id"
        size="middle"
        loading={loading}
        dataSource={filteredCampaigns}
        pagination={{ pageSize: 10, showTotal: (total) => `共 ${total} 条` }}
        columns={[
          { title: '推送标题', render: (_: unknown, item: ManagedNotificationCampaign) => <><strong>{item.title}</strong><div style={{ color: 'var(--xq-muted)', fontSize: 12 }}>活动：{item.name}</div></> },
          { title: '类型', width: 150, render: (_: unknown, item: ManagedNotificationCampaign) => item.channels.map(channelLabel).join(' / ') },
          { title: '目标人群', width: 110, render: (_: unknown, item: ManagedNotificationCampaign) => (item.recipient_count <= 1 ? '单用户' : '全量用户') },
          { title: '发送量', width: 100, render: (_: unknown, item: ManagedNotificationCampaign) => (item.sent_count ? item.sent_count.toLocaleString() : '—') },
          { title: '点击率', width: 90, render: () => '—' },
          { title: '发送时间', width: 165, render: (_: unknown, item: ManagedNotificationCampaign) => fmtTime(item.completed_at ?? item.scheduled_at ?? item.created_at) },
          { title: '状态', width: 100, dataIndex: 'status', render: (value: string) => { const meta = campaignStatusMeta[value] ?? { label: value, tone: 'gray' as PillTone }; return <Pill tone={meta.tone}>{meta.label}</Pill> } },
          {
            title: '操作', width: 120, render: (_: unknown, item: ManagedNotificationCampaign) => (
              <Links items={[
                { label: '详情', onClick: () => setDetail(item) },
                item.status === 'scheduled'
                  ? { label: '取消', danger: true, onClick: () => void cancelCampaign(item) }
                  : { label: '复制', onClick: () => openCreate(item) },
              ]} />
            ),
          },
        ]}
      />
    </div>
  </>

  const templatesTab = (
    <div className="xq-card xq-table">
      <Table
        rowKey="id"
        size="middle"
        loading={loading}
        dataSource={templates}
        pagination={{ pageSize: 10, showTotal: (total) => `共 ${total} 条` }}
        columns={[
          { title: '通知场景', dataIndex: 'scene_code' },
          { title: '渠道', width: 130, dataIndex: 'channel', render: channelLabel },
          { title: '当前版本', width: 110, render: (_: unknown, item: ManagedNotificationTemplate) => (item.current_version_no ? `v${item.current_version_no}` : '未发布') },
          { title: '状态', width: 110, dataIndex: 'status', render: (value: string) => (value === 'enabled' ? <Pill tone="green">启用</Pill> : <Pill tone="gray">停用</Pill>) },
          { title: '更新时间', width: 170, dataIndex: 'updated_at', render: fmtTime },
        ]}
      />
    </div>
  )

  const rulesTab = (
    <div className="xq-card xq-table">
      <Table
        rowKey="scene_code"
        size="middle"
        loading={loading}
        dataSource={rules}
        pagination={{ pageSize: 10, showTotal: (total) => `共 ${total} 条` }}
        columns={[
          { title: '场景名称', render: (_: unknown, rule: ManagedNotificationRule) => <><strong>{rule.name}</strong><div style={{ color: 'var(--xq-muted)', fontSize: 12 }}>{rule.scene_code}</div></> },
          { title: '业务域', width: 110, dataIndex: 'domain_name' },
          { title: '类别', width: 120, dataIndex: 'category' },
          { title: '通知渠道', render: (_: unknown, rule: ManagedNotificationRule) => <>{rule.channels.map((value) => <Tag key={value}>{channelLabel(value)}</Tag>)}</> },
          { title: '类型', width: 110, render: (_: unknown, rule: ManagedNotificationRule) => (rule.essential ? <Pill tone="orange">必需通知</Pill> : <Pill tone="gray">可退订</Pill>) },
          { title: '订阅开关', width: 100, render: (_: unknown, rule: ManagedNotificationRule) => <Switch checked={rule.enabled} onChange={(checked) => void toggleRule(rule, checked)} /> },
        ]}
      />
    </div>
  )

  return (
    <div className="xq-page">
      {contextHolder}
      <PageHead title="消息推送" extra={<Button type="primary" onClick={() => openCreate()}>+ 新建推送</Button>} />
      <div className="xq-tabs">
        <div className={`it${tab === 'records' ? ' on' : ''}`} onClick={() => setTab('records')}>发送记录</div>
        <div className={`it${tab === 'templates' ? ' on' : ''}`} onClick={() => setTab('templates')}>通知模板</div>
        <div className={`it${tab === 'rules' ? ' on' : ''}`} onClick={() => setTab('rules')}>订阅配置</div>
      </div>
      {tab === 'records' && recordsTab}
      {tab === 'templates' && templatesTab}
      {tab === 'rules' && rulesTab}

      <Modal title="新建推送" open={campaignOpen} onCancel={() => setCampaignOpen(false)} onOk={() => void saveCampaign()} okText="创建推送" confirmLoading={submitting} destroyOnHidden>
        <Form form={campaignForm} layout="vertical">
          <Form.Item name="name" label="活动名称" rules={[{ required: true }]}><Input placeholder="例如：双 11 预售抢先购" /></Form.Item>
          <Form.Item name="title" label="推送标题" rules={[{ required: true }]}><Input /></Form.Item>
          <Form.Item name="body" label="推送内容" rules={[{ required: true }]}><Input.TextArea rows={3} /></Form.Item>
          <Form.Item name="channels" label="推送渠道" rules={[{ required: true, type: 'array', min: 1 }]}>
            <Select mode="multiple" options={Object.entries(channelLabels).map(([value, label]) => ({ value, label }))} />
          </Form.Item>
          <Form.Item name="scheduled_at" label="定时发送（留空则立即进入发送队列）"><Input type="datetime-local" /></Form.Item>
        </Form>
      </Modal>

      <Modal title="推送详情" open={Boolean(detail)} onCancel={() => setDetail(null)} footer={<Button type="primary" onClick={() => setDetail(null)}>关闭</Button>}>
        {detail && (
          <div style={{ fontSize: 13, lineHeight: 2.1 }}>
            <div><b>活动名称：</b>{detail.name}</div>
            <div><b>推送标题：</b>{detail.title}</div>
            <div><b>推送内容：</b>{detail.body}</div>
            <div><b>推送渠道：</b>{detail.channels.map(channelLabel).join('、')}</div>
            <div><b>目标人数：</b>{detail.recipient_count.toLocaleString()}　<b>已发送：</b>{detail.sent_count.toLocaleString()}</div>
            <div><b>定时时间：</b>{fmtTime(detail.scheduled_at)}　<b>完成时间：</b>{fmtTime(detail.completed_at)}</div>
            <div><b>创建时间：</b>{fmtTime(detail.created_at)}</div>
          </div>
        )}
      </Modal>
    </div>
  )
}
