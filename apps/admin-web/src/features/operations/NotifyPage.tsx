import { Button, Checkbox, Form, Input, Modal, Select, Switch, Table, Tag, message } from 'antd'
import { useCallback, useEffect, useMemo, useState } from 'react'
import {
  ApiError,
  cancelNotificationCampaign,
  createNotificationCampaign,
  listNotificationCampaigns,
  listNotificationRules,
  listNotificationTemplates,
  saveNotificationTemplate,
  updateNotificationRule,
  type ManagedNotificationCampaign,
  type ManagedNotificationRule,
  type ManagedNotificationTemplate,
} from '../../api/admin'
import { Links, PageHead, Pill, StatCard, type PillTone } from '../../components/xq'

type TabKey = 'records' | 'templates' | 'rules'

const channelLabels: Record<string, string> = { in_app: '站内信', wechat_mini: '小程序订阅', wechat_oa: '公众号订阅', sms: '短信', push: 'App 推送', email: '邮件' }
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
interface TemplateFormValue { scene_code: string; channel: string; subject: string; body: string; provider_template_id?: string; publish: boolean }

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
  const [templateForm] = Form.useForm<TemplateFormValue>()
  const [templateOpen, setTemplateOpen] = useState(false)
  const [templateSubmitting, setTemplateSubmitting] = useState(false)
  const [channelRule, setChannelRule] = useState<ManagedNotificationRule | null>(null)
  const [channelForm] = Form.useForm<{ channels: string[]; reason: string }>()
  const [channelSubmitting, setChannelSubmitting] = useState(false)
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

  const enabledRules = useMemo(() => rules.filter((item) => item.enabled).length, [rules])
  const pendingCampaigns = useMemo(
    () => campaigns.filter((item) => item.status === 'scheduled' || item.status === 'sending').length,
    [campaigns],
  )

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

  const openTemplate = (rule?: ManagedNotificationRule) => {
    const preset = rule ?? rules[0]
    templateForm.setFieldsValue({
      scene_code: preset?.scene_code ?? '',
      channel: 'wechat_oa',
      subject: preset?.default_template.subject ?? '',
      body: preset?.default_template.body ?? '',
      provider_template_id: '',
      publish: true,
    })
    setTemplateOpen(true)
  }

  const applyTemplateSceneDefaults = (sceneCode: string) => {
    const rule = rules.find((item) => item.scene_code === sceneCode)
    if (!rule) return
    templateForm.setFieldsValue({ subject: rule.default_template.subject, body: rule.default_template.body })
  }

  const saveTemplate = async () => {
    try {
      const values = await templateForm.validateFields()
      const rule = rules.find((item) => item.scene_code === values.scene_code)
      setTemplateSubmitting(true)
      await saveNotificationTemplate({
        scene_code: values.scene_code,
        channel: values.channel,
        subject: values.subject,
        body: values.body,
        allowed_variables: rule?.allowed_variables ?? [],
        provider_template_id: values.provider_template_id?.trim() || null,
        publish: values.publish,
      })
      messageApi.success('通知模板已保存并发布。')
      setTemplateOpen(false)
      await load()
    } catch (error) {
      if (error instanceof ApiError) messageApi.error(error.message)
    } finally {
      setTemplateSubmitting(false)
    }
  }

  const openChannels = (rule: ManagedNotificationRule) => {
    setChannelRule(rule)
    channelForm.setFieldsValue({ channels: rule.channels, reason: '' })
  }

  const saveChannels = async () => {
    if (!channelRule) return
    try {
      const values = await channelForm.validateFields()
      setChannelSubmitting(true)
      await updateNotificationRule(channelRule.scene_code, {
        enabled: channelRule.enabled,
        channels: values.channels,
        expected_version: channelRule.lock_version,
        reason: values.reason,
      })
      messageApi.success('通知渠道已更新。')
      setChannelRule(null)
      await load()
    } catch (error) {
      if (error instanceof ApiError) messageApi.error(error.message)
    } finally {
      setChannelSubmitting(false)
    }
  }

  const recordsTab = <>
    <div className="xq-stats">
      <StatCard title="今日发送" value={stats.sentToday.toLocaleString()} delta={`累计发送 ${stats.totalSent.toLocaleString()} 人次`} />
      <StatCard title="启用场景规则" value={enabledRules} delta={`共 ${rules.length} 条订阅规则`} />
      <StatCard title="模板总数" value={stats.templateCount} delta={<span className="up" style={{ color: '#16a34a' }}>启用 {stats.enabledTemplates}</span>} />
      <StatCard title="待发推送" value={pendingCampaigns} delta="定时待发 / 发送中" />
    </div>
    <div className="xq-card xq-filter" style={{ marginBottom: 16 }}>
      <Select allowClear placeholder="全部类型" value={channel || undefined} onChange={(value) => { const next = value ?? ''; setChannel(next); setApplied((prev) => ({ ...prev, channel: next })) }} options={Object.entries(channelLabels).map(([value, label]) => ({ value, label }))} />
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
          { title: '送达率', width: 100, render: (_: unknown, item: ManagedNotificationCampaign) => (item.recipient_count > 0 ? `${Math.round((item.sent_count / item.recipient_count) * 100)}%` : '—') },
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
    <>
    <div style={{ display: 'flex', justifyContent: 'flex-end', marginBottom: 12 }}>
      <Button type="primary" onClick={() => openTemplate()}>+ 新建 / 配置模板</Button>
    </div>
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
    </>
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
          { title: '操作', width: 170, render: (_: unknown, rule: ManagedNotificationRule) => <Links items={[
            { label: '模板', onClick: () => openTemplate(rule) },
            { label: '渠道', onClick: () => openChannels(rule) },
          ]} /> },
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

      <Modal title="配置通知模板" open={templateOpen} onCancel={() => setTemplateOpen(false)} onOk={() => void saveTemplate()} okText="保存并发布" confirmLoading={templateSubmitting} destroyOnHidden>
        <Form form={templateForm} layout="vertical">
          <Form.Item name="scene_code" label="通知场景" rules={[{ required: true }]}>
            <Select showSearch optionFilterProp="label" onChange={applyTemplateSceneDefaults} options={rules.map((rule) => ({ value: rule.scene_code, label: `${rule.name}（${rule.scene_code}）` }))} />
          </Form.Item>
          <Form.Item name="channel" label="渠道" rules={[{ required: true }]}>
            <Select options={[{ value: 'wechat_oa', label: '公众号订阅' }, { value: 'wechat_mini', label: '小程序订阅' }, { value: 'in_app', label: '站内信' }, { value: 'sms', label: '短信' }]} />
          </Form.Item>
          <Form.Item name="provider_template_id" label="微信模板 ID" extra="公众号订阅通知填写公众号后台的模板 ID；站内信、短信可留空。">
            <Input maxLength={160} />
          </Form.Item>
          <Form.Item name="subject" label="标题" rules={[{ required: true }]}><Input maxLength={160} /></Form.Item>
          <Form.Item name="body" label="正文 / 模板变量" rules={[{ required: true }]} extra='微信订阅渠道需填写关键词 JSON，例如 {"thing1":{"value":"{{task_title}}"}}；站内信可写普通文案。'>
            <Input.TextArea rows={5} maxLength={2000} />
          </Form.Item>
          <Form.Item name="publish" label="立即发布" valuePropName="checked"><Switch /></Form.Item>
        </Form>
      </Modal>

      <Modal title="配置通知渠道" open={Boolean(channelRule)} onCancel={() => setChannelRule(null)} onOk={() => void saveChannels()} okText="保存渠道" confirmLoading={channelSubmitting} destroyOnHidden>
        {channelRule && <Form form={channelForm} layout="vertical">
          <p style={{ marginTop: 0, color: 'var(--xq-muted)' }}>{channelRule.name}（{channelRule.scene_code}）</p>
          <Form.Item name="channels" label="通知渠道" rules={[{ required: true, type: 'array', min: 1, message: '至少保留站内信。' }]}>
            <Checkbox.Group options={channelRule.allowed_channels.map((value) => ({ value, label: channelLabel(value) }))} />
          </Form.Item>
          <Form.Item name="reason" label="修改原因" rules={[{ required: true, min: 5, max: 500, message: '请填写至少 5 个字的修改原因。' }]}>
            <Input.TextArea rows={2} maxLength={500} showCount />
          </Form.Item>
        </Form>}
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
