import { Button, Input, Switch, Table, message } from 'antd'
import { useCallback, useEffect, useMemo, useState } from 'react'
import {
  ApiError,
  listSettings,
  listShippingTemplates,
  publishSetting,
  saveSettingDraft,
  type ManagedShippingChargeType,
  type ManagedShippingTemplate,
  type SystemSetting,
} from '../../api/admin'
import { Card, PageHead, Pill } from '../../components/xq'

type SubKey = 'flags' | 'global' | 'shipping' | 'points'

const subnavItems: Array<{ key: SubKey; label: string }> = [
  { key: 'flags', label: '灰度开关' },
  { key: 'global', label: '全局配置' },
  { key: 'shipping', label: '运费模板' },
  { key: 'points', label: '积分规则' },
]

/* 全局配置 4 个输入框：按名称关键字匹配真实配置项 */
const globalFields = [
  { keyword: '兑换比例', label: '积分兑换比例', unit: '元' },
  { keyword: '注册', label: '新用户注册奖励', unit: '积分' },
  { keyword: '签到', label: '每日签到奖励', unit: '积分' },
  { keyword: '提现最低', label: '提现最低金额', unit: '元' },
]

const chargeTypeLabels: Record<ManagedShippingChargeType, string> = { fixed: '固定运费', by_quantity: '按件计费', by_weight: '按重计费' }

function chargeSummary(item: ManagedShippingTemplate) {
  if (item.charge_type === 'fixed') return `固定 ¥${((item.fixed_amount ?? 0) / 100).toFixed(2)}`
  const unit = item.charge_type === 'by_quantity' ? '件' : '千克'
  return `首${unit} ${item.first_unit ?? '—'} · ¥${((item.first_amount ?? 0) / 100).toFixed(2)}，续${unit} ¥${((item.additional_amount ?? 0) / 100).toFixed(2)}`
}

function displayNumber(setting: SystemSetting) {
  const value = setting.current_value
  if (setting.value_type === 'money' && typeof value === 'number') return String(value / 100)
  return typeof value === 'number' ? String(value) : ''
}

export function ConfigPage() {
  const [sub, setSub] = useState<SubKey>('flags')
  const [settings, setSettings] = useState<SystemSetting[]>([])
  const [templates, setTemplates] = useState<ManagedShippingTemplate[]>([])
  const [loading, setLoading] = useState(true)
  const [submitting, setSubmitting] = useState(false)
  const [edits, setEdits] = useState<Record<string, string>>({})
  const [messageApi, contextHolder] = message.useMessage()

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const [settingData, templateData] = await Promise.all([listSettings(), listShippingTemplates()])
      setSettings(settingData.items)
      setTemplates(templateData.items)
      const next: Record<string, string> = {}
      settingData.items.forEach((item) => {
        if (item.value_type !== 'boolean') next[item.key] = displayNumber(item)
      })
      setEdits(next)
    } catch (error) {
      messageApi.error(error instanceof ApiError ? error.message : '配置数据加载失败。')
    } finally {
      setLoading(false)
    }
  }, [messageApi])

  useEffect(() => { void load() }, [load])

  const flags = useMemo(() => settings.filter((item) => item.value_type === 'boolean'), [settings])
  const globalTargets = useMemo(
    () => globalFields
      .map((field) => ({ field, setting: settings.find((item) => item.value_type !== 'boolean' && item.name.includes(field.keyword)) }))
      .filter((entry): entry is { field: (typeof globalFields)[number]; setting: SystemSetting } => Boolean(entry.setting)),
    [settings],
  )

  /* 草稿 + 发布流程；高风险配置（四眼复核）保存草稿后由另一位管理员复核 */
  const saveAndPublish = async (setting: SystemSetting, value: boolean | number, reason: string) => {
    const draft = await saveSettingDraft(setting.key, value, reason, setting.draft?.lock_version)
    if (draft.review_status === 'pending') {
      messageApi.warning(`「${setting.name}」为高风险配置，草稿已保存，需另一位管理员复核后才能发布。`)
      return
    }
    await publishSetting(setting.key)
    messageApi.success(`「${setting.name}」已发布生效。`)
  }

  const toggleFlag = async (setting: SystemSetting, next: boolean) => {
    setSubmitting(true)
    try {
      await saveAndPublish(setting, next, `配置中心灰度开关${next ? '开启' : '关闭'}「${setting.name}」。`)
      await load()
    } catch (error) {
      messageApi.error(error instanceof ApiError ? error.message : '开关更新失败，请稍后重试。')
    } finally {
      setSubmitting(false)
    }
  }

  const saveGlobal = async () => {
    const changed = globalTargets.filter(({ setting }) => (edits[setting.key] ?? '') !== displayNumber(setting))
    if (!changed.length) {
      messageApi.info('配置没有变化，无需保存。')
      return
    }
    setSubmitting(true)
    try {
      for (const { field, setting } of changed) {
        const raw = Number(edits[setting.key])
        if (Number.isNaN(raw)) {
          messageApi.error(`「${field.label}」请输入数字。`)
          continue
        }
        const value = setting.value_type === 'money' ? Math.round(raw * 100) : raw
        await saveAndPublish(setting, value, `配置中心调整${field.label}为 ${edits[setting.key]} ${field.unit}。`)
      }
      await load()
    } catch (error) {
      messageApi.error(error instanceof ApiError ? error.message : '全局配置保存失败。')
    } finally {
      setSubmitting(false)
    }
  }

  const flagCard = (
    <Card title="功能灰度开关">
      {flags.length === 0 && !loading && <div className="xq-empty">暂无灰度开关配置</div>}
      {flags.map((item, index) => (
        <div key={item.key} style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', padding: '14px 0', borderBottom: index === flags.length - 1 ? 'none' : '1px solid var(--xq-line-soft)' }}>
          <div>
            <div style={{ fontWeight: 600, color: 'var(--xq-text)' }}>{item.name}</div>
            <div style={{ color: 'var(--xq-muted)', fontSize: 12, marginTop: 3 }}>{item.description ?? `配置键 ${item.key}`}</div>
          </div>
          <Switch checked={Boolean(item.current_value)} disabled={submitting} onChange={(checked) => void toggleFlag(item, checked)} />
        </div>
      ))}
    </Card>
  )

  const globalCard = (
    <Card title="全局配置">
      {globalTargets.length === 0 && !loading && <div className="xq-empty">未匹配到全局配置项</div>}
      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '16px 20px' }}>
        {globalTargets.map(({ field, setting }) => (
          <div key={setting.key}>
            <div style={{ fontSize: 13, color: 'var(--xq-text)', marginBottom: 6 }}>{field.label}</div>
            <Input
              value={edits[setting.key] ?? ''}
              addonAfter={setting.unit ?? field.unit}
              onChange={(event) => setEdits((prev) => ({ ...prev, [setting.key]: event.target.value }))}
            />
          </div>
        ))}
      </div>
      <div style={{ marginTop: 16, display: 'flex', gap: 10 }}>
        <Button type="primary" loading={submitting} onClick={() => void saveGlobal()}>保存</Button>
        <Button onClick={() => {
          const next: Record<string, string> = {}
          settings.forEach((item) => { if (item.value_type !== 'boolean') next[item.key] = displayNumber(item) })
          setEdits(next)
        }}>重置</Button>
      </div>
    </Card>
  )

  const shippingCard = (
    <div className="xq-card xq-table">
      <p className="xq-card-title">运费模板</p>
      <Table
        rowKey="id"
        size="middle"
        loading={loading}
        dataSource={templates}
        pagination={{ pageSize: 10, showTotal: (total) => `共 ${total} 条` }}
        columns={[
          { title: '模板名称', render: (_: unknown, item: ManagedShippingTemplate) => <><strong>{item.name}</strong><div style={{ color: 'var(--xq-muted)', fontSize: 12 }}>规则版本 v{item.definition_version}</div></> },
          { title: '计费方式', width: 260, render: (_: unknown, item: ManagedShippingTemplate) => <>{chargeTypeLabels[item.charge_type]}<div style={{ color: 'var(--xq-muted)', fontSize: 12 }}>{chargeSummary(item)}</div></> },
          { title: '配送范围', width: 160, render: (_: unknown, item: ManagedShippingTemplate) => `${item.regions.length} 条区域规则` },
          { title: '包邮规则', width: 140, render: (_: unknown, item: ManagedShippingTemplate) => item.free_rules.length ? `${item.free_rules.length} 条包邮规则` : '无包邮' },
          { title: '状态', width: 110, dataIndex: 'status', render: (value: ManagedShippingTemplate['status']) => value === 'active' ? <Pill tone="green">启用中</Pill> : value === 'draft' ? <Pill tone="orange">草稿</Pill> : <Pill tone="gray">已停用</Pill> },
        ]}
      />
    </div>
  )

  const pointsCard = (
    <Card title="积分规则说明">
      <div style={{ color: 'var(--xq-muted)', fontSize: 13, lineHeight: 2 }}>
        <p style={{ margin: 0 }}>积分获取与消耗规则由「全局配置」中的参数统一控制：</p>
        <p style={{ margin: 0 }}>· 新用户注册奖励、每日签到奖励：控制注册与签到场景发放的积分数量；</p>
        <p style={{ margin: 0 }}>· 积分兑换比例：控制积分在商城兑换与提现时的折算比例；</p>
        <p style={{ margin: 0 }}>· 提现最低金额：控制用户发起提现申请的最低门槛；</p>
        <p style={{ margin: 0 }}>· 任务类积分奖励在「任务中心」按任务单独配置，商城抵扣规则在「商城中心」维护。</p>
        <p style={{ margin: '8px 0 0' }}>调整上述参数后即时发布生效，仅影响新业务，历史积分与订单不重算。</p>
      </div>
    </Card>
  )

  return (
    <div className="xq-page">
      {contextHolder}
      <PageHead title="配置中心" />
      <div className="xq-split">
        <div className="xq-card xq-subnav">
          {subnavItems.map((item) => (
            <div key={item.key} className={`it${sub === item.key ? ' on' : ''}`} onClick={() => setSub(item.key)}>{item.label}</div>
          ))}
        </div>
        <div className="main">
          {sub === 'flags' && <>{flagCard}<div style={{ marginTop: 16 }}>{globalCard}</div></>}
          {sub === 'global' && globalCard}
          {sub === 'shipping' && shippingCard}
          {sub === 'points' && pointsCard}
        </div>
      </div>
    </div>
  )
}
