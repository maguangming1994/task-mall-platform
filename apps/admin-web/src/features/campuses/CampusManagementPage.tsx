import {
  ApartmentOutlined,
  AppstoreOutlined,
  BankOutlined,
  BookOutlined,
  CheckCircleOutlined,
  CloudSyncOutlined,
  DeleteOutlined,
  EditOutlined,
  EnvironmentOutlined,
  PictureOutlined,
  PlusOutlined,
  StopOutlined,
  TeamOutlined,
  ThunderboltOutlined,
  UserOutlined,
} from '@ant-design/icons'
import {
  Alert,
  Button,
  Card,
  Descriptions,
  Form,
  Image,
  Input,
  InputNumber,
  Modal,
  Popconfirm,
  Progress,
  Select,
  Space,
  Statistic,
  Table,
  Tabs,
  Tag,
  Typography,
  message,
} from 'antd'
import { useCallback, useEffect, useMemo, useState } from 'react'
import { useAdminAuth } from '../auth/AdminAuthContext'
import {
  ApiError,
  createManagedAdmin,
  createManagedCampus,
  listCampusAgentOptions,
  listManagedCampusAgents,
  listManagedCampuses,
  updateManagedAdminStatus,
  updateManagedCampus,
  updateManagedCampusAgents,
  verifyAdminRecent,
  type CampusAgentOption,
  type ManagedCampus,
  type ManagedCampusAgentList,
  type ManagedCampusList,
} from '../../api/admin'
import { ImageSourceModal, type ImageSourceValue } from '../../components/ImageSourceModal'
import { CampusModuleDrawer } from './CampusModuleDrawer'

type CampusImageValue = ImageSourceValue | null
type CampusForm = Pick<ManagedCampus, 'code' | 'name' | 'province' | 'city' | 'district' | 'address' | 'latitude' | 'longitude' | 'status' | 'sort_order' | 'isolation_mode'> & {
  emblem_image: CampusImageValue
  logo_image: CampusImageValue
}
type AgentForm = { admin_user_ids: string[]; primary_admin_user_id?: string }
type NewAgentForm = { account: string; display_name: string; email?: string; password: string; current_password: string; mfa_code?: string }
type AgentStatusForm = { reason: string; current_password: string; mfa_code?: string }
type CampusFilters = {
  keyword: string
  province: string
  catalog_type: string
  education_level: string
  status: string
  agent_coverage: string
  branding_status: string
  isolation_mode: ManagedCampus['isolation_mode'] | undefined
  page: number
  page_size: number
}
type AgentFilters = { keyword: string; status: string; assignment: string; page: number; page_size: number }
type QuickAgentCredential = { account: string; display_name: string; password: string }
type QuickAgentVerifyForm = { current_password: string; mfa_code?: string }
type QuickAgentResult = { campus_name: string; account: string; password: string; assigned: boolean }

const emptyCampus: CampusForm = {
  code: '', name: '', province: '', city: '', district: '', address: '',
  latitude: null, longitude: null, status: 'disabled', sort_order: 100, isolation_mode: 'legacy',
  emblem_image: null, logo_image: null,
}
const initialCampusFilters: CampusFilters = {
  keyword: '', province: '', catalog_type: '', education_level: '', status: '', agent_coverage: '', branding_status: '', isolation_mode: undefined, page: 1, page_size: 20,
}
const initialAgentFilters: AgentFilters = { keyword: '', status: '', assignment: '', page: 1, page_size: 20 }

function formatTime(value: string | null) {
  return value ? new Date(value).toLocaleString() : '尚无记录'
}

// 一键建代理使用：随机后缀只取易辨识字符，避免 0/O、1/l 在人工转交时看错。
function randomChars(length: number) {
  const alphabet = 'abcdefghijkmnpqrstuvwxyz23456789'
  const values = new Uint32Array(length)
  crypto.getRandomValues(values)
  return Array.from(values, (value) => alphabet[value % alphabet.length]).join('')
}

function quickAgentCredential(campus: ManagedCampus): QuickAgentCredential {
  const slug = campus.code.replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 24) || 'campus'
  return {
    account: `agent-${slug}-${randomChars(6)}`,
    display_name: `${campus.name} · 代理测试`.slice(0, 80),
    password: `Ag${randomChars(9)}!${randomChars(4)}`,
  }
}

function catalogTypeLabel(value: ManagedCampus['catalog_type']) {
  if (value === 'ordinary') return '普通高校'
  if (value === 'adult') return '成人高校'
  return '人工创建'
}

function campusImageValue(assetId: string | null, url: string | null): CampusImageValue {
  return url ? { asset_id: assetId ?? undefined, url } : null
}

function CampusBrandImageInput({ kind, value, onChange }: {
  kind: 'emblem' | 'logo'
  value?: CampusImageValue
  onChange?: (value: CampusImageValue) => void
}) {
  const [sourceOpen, setSourceOpen] = useState(false)
  const isEmblem = kind === 'emblem'
  const label = isEmblem ? '方形校徽' : '横版 Logo'

  return <>
    <div className={`campus-brand-input campus-brand-input-${kind}`}>
      <div className={`campus-brand-input-preview campus-brand-input-preview-${kind}`}>
        {value?.url
          ? <Image src={value.url} alt={label} preview={{ mask: '放大预览' }} />
          : <span className="campus-brand-placeholder"><PictureOutlined /><small>待上传</small></span>}
      </div>
      <div className="campus-brand-input-meta">
        <Typography.Text type="secondary">{isEmblem ? '建议使用 1:1 透明底图片' : '建议使用横向透明底图片'}</Typography.Text>
        {value?.url && <Tag color={value.asset_id ? 'blue' : 'orange'}>{value.asset_id ? '素材存储' : '直接外链'}</Tag>}
        <Space wrap>
          <Button icon={value?.url ? <EditOutlined /> : <PlusOutlined />} onClick={() => setSourceOpen(true)}>{value?.url ? '更换' : '添加'}</Button>
          {value?.url && <Button danger icon={<DeleteOutlined />} onClick={() => onChange?.(null)}>移除</Button>}
        </Space>
      </div>
    </div>
    <ImageSourceModal
      title={`选择${label}`}
      open={sourceOpen}
      onCancel={() => setSourceOpen(false)}
      onSelect={(image) => onChange?.(image)}
    />
  </>
}

function CampusBrandPreview({ campus }: { campus: ManagedCampus }) {
  const items = [
    { key: 'emblem', label: '校徽', url: campus.emblem_url, width: 42 },
    { key: 'logo', label: 'Logo', url: campus.logo_url, width: 106 },
  ]

  return <div className="campus-brand-table-preview">
    {items.map((item) => <div className={`campus-brand-table-item campus-brand-table-item-${item.key}`} key={item.key}>
      <div className="campus-brand-table-frame" style={{ width: item.width }}>
        {item.url
          ? <Image src={item.url} alt={`${campus.name}${item.label}`} preview={{ mask: '预览' }} />
          : <PictureOutlined />}
      </div>
      <Typography.Text className={`campus-brand-state ${item.url ? 'is-configured' : ''}`}>
        {item.label} · {item.url ? '已配置' : '待补充'}
      </Typography.Text>
    </div>)}
  </div>
}

export function CampusManagementPage() {
  const { admin } = useAdminAuth()
  const canGovernAgents = admin?.permissions.includes('rbac.manage') ?? false
  const canManageCampuses = admin?.permissions.includes('campus.manage') ?? false
  // 隔离模式属于平台级策略，仅超级管理员可切换
  const canSwitchIsolation = admin?.roles.includes('super_admin') ?? false
  // 功能模块矩阵写入仅平台超管（campus.module_manage）；代理只读
  const canManageModules = admin?.permissions.includes('campus.module_manage') ?? false
  const [campusData, setCampusData] = useState<ManagedCampusList | null>(null)
  const [agentData, setAgentData] = useState<ManagedCampusAgentList | null>(null)
  const [agentOptions, setAgentOptions] = useState<CampusAgentOption[]>([])
  const [campusFilters, setCampusFilters] = useState<CampusFilters>(initialCampusFilters)
  const [agentFilters, setAgentFilters] = useState<AgentFilters>(initialAgentFilters)
  const [campusKeyword, setCampusKeyword] = useState('')
  const [agentKeyword, setAgentKeyword] = useState('')
  const [campusLoading, setCampusLoading] = useState(true)
  const [agentLoading, setAgentLoading] = useState(false)
  const [saving, setSaving] = useState(false)
  const [editing, setEditing] = useState<ManagedCampus | null | undefined>(undefined)
  const [agentCampus, setAgentCampus] = useState<ManagedCampus | null>(null)
  const [moduleCampus, setModuleCampus] = useState<ManagedCampus | null>(null)
  const [creatingAgent, setCreatingAgent] = useState(false)
  const [agentStatusTarget, setAgentStatusTarget] = useState<ManagedCampusAgentList['items'][number] | null>(null)
  const [quickAgentTarget, setQuickAgentTarget] = useState<ManagedCampus | null>(null)
  const [quickAgentCredentialValue, setQuickAgentCredentialValue] = useState<QuickAgentCredential | null>(null)
  const [quickAgentVerifyOpen, setQuickAgentVerifyOpen] = useState(false)
  const [quickAgentBusy, setQuickAgentBusy] = useState(false)
  const [quickAgentResult, setQuickAgentResult] = useState<QuickAgentResult | null>(null)
  const [campusForm] = Form.useForm<CampusForm>()
  const [agentForm] = Form.useForm<AgentForm>()
  const [newAgentForm] = Form.useForm<NewAgentForm>()
  const [agentStatusForm] = Form.useForm<AgentStatusForm>()
  const [quickAgentForm] = Form.useForm<QuickAgentVerifyForm>()
  const selectedAgentIds = Form.useWatch('admin_user_ids', agentForm) ?? []
  const [messageApi, contextHolder] = message.useMessage()

  const loadCampuses = useCallback(async () => {
    setCampusLoading(true)
    try {
      setCampusData(await listManagedCampuses(campusFilters))
    } catch (error) {
      messageApi.error(error instanceof ApiError ? error.message : '学校数据加载失败。')
    } finally {
      setCampusLoading(false)
    }
  }, [campusFilters, messageApi])

  const loadAgents = useCallback(async () => {
    if (!canGovernAgents) return
    setAgentLoading(true)
    try {
      const [agents, options] = await Promise.all([
        listManagedCampusAgents(agentFilters),
        listCampusAgentOptions(),
      ])
      setAgentData(agents)
      setAgentOptions(options.items)
    } catch (error) {
      messageApi.error(error instanceof ApiError ? error.message : '代理数据加载失败。')
    } finally {
      setAgentLoading(false)
    }
  }, [agentFilters, canGovernAgents, messageApi])

  useEffect(() => { void loadCampuses() }, [loadCampuses])
  useEffect(() => { void loadAgents() }, [loadAgents])

  const agentSelectOptions = useMemo(() => agentOptions.map((agent) => ({
    value: agent.id,
    label: `${agent.display_name}（${agent.account}）`,
  })), [agentOptions])
  const primaryAgentOptions = agentSelectOptions.filter((option) => selectedAgentIds.includes(option.value))

  const refreshAfterWrite = async () => {
    await Promise.all([loadCampuses(), loadAgents()])
  }

  const openCreate = () => {
    setEditing(null)
    campusForm.setFieldsValue(emptyCampus)
  }

  const openEdit = (campus: ManagedCampus) => {
    setEditing(campus)
    campusForm.setFieldsValue({
      code: campus.code,
      name: campus.name,
      emblem_image: campusImageValue(campus.emblem_asset_id, campus.emblem_url),
      logo_image: campusImageValue(campus.logo_asset_id, campus.logo_url),
      province: campus.province,
      city: campus.city,
      district: campus.district,
      address: campus.address,
      latitude: campus.latitude,
      longitude: campus.longitude,
      status: campus.status,
      sort_order: campus.sort_order,
      isolation_mode: campus.isolation_mode,
    })
  }

  const toggleIsolation = (campus: ManagedCampus) => {
    const next = campus.isolation_mode === 'strict' ? 'legacy' : 'strict'
    Modal.confirm({
      title: next === 'strict' ? `将「${campus.name}」切换为严格隔离？` : `将「${campus.name}」切换为传统模式？`,
      content: next === 'strict'
        ? '严格隔离后，该校仅展示本校用户产生的社区内容与闲置商品，跨校内容不可见。若仍有缺少校区归属的内容，切换会失败并提示先执行数据回填。'
        : '传统模式下该校用户可浏览全平台内容。',
      okText: next === 'strict' ? '确认严格隔离' : '确认切换传统',
      cancelText: '取消',
      onOk: async () => {
        try {
          await updateManagedCampus(campus.id, {
            code: campus.code,
            name: campus.name,
            emblem_asset_id: campus.emblem_asset_id,
            emblem_url: campus.emblem_url,
            logo_asset_id: campus.logo_asset_id,
            logo_url: campus.logo_url,
            province: campus.province,
            city: campus.city,
            district: campus.district,
            address: campus.address,
            latitude: campus.latitude,
            longitude: campus.longitude,
            status: campus.status,
            sort_order: campus.sort_order,
            isolation_mode: next,
          })
          messageApi.success(next === 'strict' ? '已切换为严格隔离模式。' : '已切换为传统模式。')
          await refreshAfterWrite()
        } catch (error) {
          messageApi.error(error instanceof ApiError ? error.message : '隔离模式切换失败。')
        }
      },
    })
  }

  const saveCampus = async (values: CampusForm) => {
    setSaving(true)
    try {
      const { emblem_image: emblem, logo_image: logo, ...details } = values
      const input = {
        ...details,
        emblem_asset_id: emblem?.asset_id ?? null,
        emblem_url: emblem?.url ?? null,
        logo_asset_id: logo?.asset_id ?? null,
        logo_url: logo?.url ?? null,
      }
      if (editing) await updateManagedCampus(editing.id, input)
      else await createManagedCampus(input)
      messageApi.success(editing ? '学校运营资料已更新。' : '学校已创建，默认处于待开通状态。')
      setEditing(undefined)
      await refreshAfterWrite()
    } catch (error) {
      messageApi.error(error instanceof ApiError ? error.message : '保存失败，请检查填写内容。')
    } finally {
      setSaving(false)
    }
  }

  const openAgents = (campus: ManagedCampus) => {
    setAgentCampus(campus)
    agentForm.setFieldsValue({
      admin_user_ids: campus.agents.map((agent) => agent.id),
      primary_admin_user_id: campus.agents.find((agent) => agent.is_primary)?.id,
    })
  }

  const saveAgents = async (values: AgentForm) => {
    if (!agentCampus) return
    setSaving(true)
    try {
      const primary = values.admin_user_ids.includes(values.primary_admin_user_id ?? '') ? values.primary_admin_user_id : undefined
      await updateManagedCampusAgents(agentCampus.id, values.admin_user_ids || [], primary)
      messageApi.success('学校代理范围已更新。')
      setAgentCampus(null)
      await refreshAfterWrite()
    } catch (error) {
      messageApi.error(error instanceof ApiError ? error.message : '代理设置保存失败。')
    } finally {
      setSaving(false)
    }
  }

  const createAgent = async (values: NewAgentForm) => {
    setSaving(true)
    try {
      await verifyAdminRecent(values.current_password, values.mfa_code || undefined)
      await createManagedAdmin({
        account: values.account,
        display_name: values.display_name,
        email: values.email,
        password: values.password,
        role_codes: ['campus_agent'],
      })
      messageApi.success('代理账号已创建，请在学校库中分配负责学校。')
      setCreatingAgent(false)
      newAgentForm.resetFields()
      await loadAgents()
    } catch (error) {
      messageApi.error(error instanceof ApiError ? error.message : '代理账号创建失败。')
    } finally {
      setSaving(false)
    }
  }

  // 生成随机账号后直接创建并分配到该校；若会话未在有效期内完成二次核验，再弹出密码核验。
  const createQuickAgent = async (campus: ManagedCampus, credential: QuickAgentCredential) => {
    const created = await createManagedAdmin({
      account: credential.account,
      display_name: credential.display_name,
      password: credential.password,
      role_codes: ['campus_agent'],
    })
    const agentIds = campus.agents.map((agent) => agent.id).filter((id) => id !== created.id)
    const primaryId = campus.agents.find((agent) => agent.is_primary)?.id
    let assigned = true
    try {
      await updateManagedCampusAgents(campus.id, [...agentIds, created.id], primaryId ?? created.id)
      messageApi.success(`已为「${campus.name}」创建并分配代理测试账号。`)
    } catch {
      assigned = false
      messageApi.warning(`账号 ${created.account} 已创建，但自动分配学校失败，请使用「分配代理」手动关联。`)
    }
    setQuickAgentResult({ campus_name: campus.name, account: created.account, password: credential.password, assigned })
    await refreshAfterWrite()
  }

  const startQuickAgent = async (campus: ManagedCampus) => {
    if (!canGovernAgents || quickAgentBusy) return
    const credential = quickAgentCredential(campus)
    setQuickAgentTarget(campus)
    setQuickAgentCredentialValue(credential)
    setQuickAgentBusy(true)
    try {
      await createQuickAgent(campus, credential)
      setQuickAgentTarget(null)
      setQuickAgentCredentialValue(null)
    } catch (error) {
      if (error instanceof ApiError && error.code === 'ADMIN_RECENT_VERIFICATION_REQUIRED') {
        quickAgentForm.resetFields()
        setQuickAgentVerifyOpen(true)
        return
      }
      setQuickAgentTarget(null)
      setQuickAgentCredentialValue(null)
      messageApi.error(error instanceof ApiError ? error.message : '代理测试账号创建失败。')
    } finally {
      setQuickAgentBusy(false)
    }
  }

  const confirmQuickAgent = async (values: QuickAgentVerifyForm) => {
    if (!quickAgentTarget || !quickAgentCredentialValue) return
    setQuickAgentBusy(true)
    try {
      await verifyAdminRecent(values.current_password, values.mfa_code || undefined)
      await createQuickAgent(quickAgentTarget, quickAgentCredentialValue)
      setQuickAgentVerifyOpen(false)
      setQuickAgentTarget(null)
      setQuickAgentCredentialValue(null)
      quickAgentForm.resetFields()
    } catch (error) {
      messageApi.error(error instanceof ApiError ? error.message : '代理测试账号创建失败。')
    } finally {
      setQuickAgentBusy(false)
    }
  }

  const cancelQuickAgent = () => {
    setQuickAgentVerifyOpen(false)
    setQuickAgentTarget(null)
    setQuickAgentCredentialValue(null)
    quickAgentForm.resetFields()
  }

  const changeAgentStatus = async (values: AgentStatusForm) => {
    if (!agentStatusTarget) return
    setSaving(true)
    const nextStatus = agentStatusTarget.status === 'active' ? 'disabled' : 'active'
    try {
      await verifyAdminRecent(values.current_password, values.mfa_code || undefined)
      await updateManagedAdminStatus(agentStatusTarget.id, nextStatus, values.reason)
      messageApi.success(nextStatus === 'active' ? '代理账号已恢复。' : '代理账号已停用并撤销活跃会话。')
      setAgentStatusTarget(null)
      agentStatusForm.resetFields()
      await loadAgents()
    } catch (error) {
      messageApi.error(error instanceof ApiError ? error.message : '代理账号状态更新失败。')
    } finally {
      setSaving(false)
    }
  }

  const summary = campusData?.summary
  const catalog = campusData?.catalog
  const activePercent = summary?.total ? Math.round(summary.active / summary.total * 1000) / 10 : 0
  const summaryCards = [
    { title: '学校目录', value: summary?.total ?? 0, suffix: '所', icon: <BankOutlined /> },
    { title: '已开通运营', value: summary?.active ?? 0, suffix: '所', icon: <CheckCircleOutlined /> },
    { title: '缺少主代理', value: summary?.without_primary_agent ?? 0, suffix: '所', icon: <StopOutlined /> },
    { title: '代理账号', value: summary?.agent_total ?? 0, suffix: '个', icon: <TeamOutlined /> },
    { title: '归属用户', value: summary?.user_total ?? 0, suffix: '人', icon: <UserOutlined /> },
    { title: '官方在册', value: summary?.official_listed ?? 0, suffix: '所', icon: <BookOutlined /> },
  ]

  const overviewTab = <>
    <div className="campus-stat-grid">
      {summaryCards.map((item) => <Card variant="borderless" key={item.title}><Statistic {...item} prefix={<span className="campus-stat-icon">{item.icon}</span>} /></Card>)}
    </div>
    <div className="campus-overview-grid">
      <Card variant="borderless" title="全国目录与运营进度" className="campus-ops-card">
        <div className="campus-progress-head"><span>已开通学校占目录比例</span><strong>{activePercent}%</strong></div>
        <Progress percent={activePercent} showInfo={false} strokeColor="#1769ff" railColor="#edf1f6" />
        <Descriptions column={2} size="small" className="campus-overview-descriptions">
          <Descriptions.Item label="目录版本">截至 {catalog?.as_of ?? '—'}</Descriptions.Item>
          <Descriptions.Item label="普通高校">{catalog?.counts.ordinary ?? 0} 所</Descriptions.Item>
          <Descriptions.Item label="本科学校">{catalog?.counts.undergraduate ?? 0} 所</Descriptions.Item>
          <Descriptions.Item label="高职专科">{catalog?.counts.vocational ?? 0} 所</Descriptions.Item>
          <Descriptions.Item label="成人高校">{catalog?.counts.adult ?? 0} 所</Descriptions.Item>
          <Descriptions.Item label="最后同步">{formatTime(catalog?.database.last_synced_at ?? null)}</Descriptions.Item>
        </Descriptions>
      </Card>
      <Card variant="borderless" title="运营待办" className="campus-ops-card">
        <div className="campus-pending-list">
          <div><span className="campus-pending-mark warning"><TeamOutlined /></span><span><strong>{summary?.without_primary_agent ?? 0}</strong><small>学校尚未指定主代理</small></span></div>
          <div><span className="campus-pending-mark info"><ApartmentOutlined /></span><span><strong>{summary?.disabled ?? 0}</strong><small>学校仍处于待开通或暂停状态</small></span></div>
          <div><span className="campus-pending-mark danger"><CloudSyncOutlined /></span><span><strong>{catalog?.database.unlisted ?? 0}</strong><small>学校已退出最新官方名录，需人工复核</small></span></div>
        </div>
      </Card>
    </div>
  </>

  const schoolLibraryTab = <>
    <Card variant="borderless" className="campus-filter-card">
      <div className="campus-filter-bar">
        <Input.Search
          allowClear
          value={campusKeyword}
          onChange={(event) => setCampusKeyword(event.target.value)}
          onSearch={(keyword) => setCampusFilters((current) => ({ ...current, keyword: keyword.trim(), page: 1 }))}
          placeholder="学校名称 / 标识码 / 城市 / 主管部门"
          style={{ width: 300 }}
        />
        <Select allowClear showSearch optionFilterProp="label" placeholder="省份" value={campusFilters.province || undefined} onChange={(value) => setCampusFilters((current) => ({ ...current, province: value ?? '', page: 1 }))} options={(campusData?.filters.provinces ?? []).map((province) => ({ value: province, label: province }))} style={{ width: 150 }} />
        <Select allowClear placeholder="学校类型" value={campusFilters.catalog_type || undefined} onChange={(value) => setCampusFilters((current) => ({ ...current, catalog_type: value ?? '', page: 1 }))} options={[{ value: 'ordinary', label: '普通高校' }, { value: 'adult', label: '成人高校' }, { value: 'manual', label: '人工创建' }]} style={{ width: 130 }} />
        <Select allowClear placeholder="办学层次" value={campusFilters.education_level || undefined} onChange={(value) => setCampusFilters((current) => ({ ...current, education_level: value ?? '', page: 1 }))} options={[{ value: '本科', label: '本科' }, { value: '专科', label: '高职专科' }, { value: '成人高等学校', label: '成人高校' }]} style={{ width: 130 }} />
        <Select allowClear placeholder="运营状态" value={campusFilters.status || undefined} onChange={(value) => setCampusFilters((current) => ({ ...current, status: value ?? '', page: 1 }))} options={[{ value: 'active', label: '已开通' }, { value: 'disabled', label: '待开通 / 暂停' }]} style={{ width: 145 }} />
        <Select allowClear placeholder="隔离模式" value={campusFilters.isolation_mode || undefined} onChange={(value) => setCampusFilters((current) => ({ ...current, isolation_mode: (value ?? undefined) as CampusFilters['isolation_mode'], page: 1 }))} options={[{ value: 'strict', label: '严格隔离' }, { value: 'legacy', label: '传统模式' }]} style={{ width: 130 }} />
        <Select allowClear placeholder="代理覆盖" value={campusFilters.agent_coverage || undefined} onChange={(value) => setCampusFilters((current) => ({ ...current, agent_coverage: value ?? '', page: 1 }))} options={[{ value: 'assigned', label: '已分配代理' }, { value: 'unassigned', label: '未分配代理' }, { value: 'primary_missing', label: '缺少主代理' }]} style={{ width: 140 }} />
        <Select allowClear placeholder="校徽状态" value={campusFilters.branding_status || undefined} onChange={(value) => setCampusFilters((current) => ({ ...current, branding_status: value ?? '', page: 1 }))} options={[{ value: 'configured', label: '校徽已配置' }, { value: 'missing', label: '校徽待补充' }]} style={{ width: 140 }} />
        <Button onClick={() => { setCampusKeyword(''); setCampusFilters(initialCampusFilters) }}>重置</Button>
      </div>
    </Card>
    <Card variant="borderless" className="campus-table-card">
      <Table<ManagedCampus>
        rowKey="id"
        loading={campusLoading}
        dataSource={campusData?.items ?? []}
        scroll={{ x: 1820 }}
        pagination={{
          current: campusData?.pagination.page ?? campusFilters.page,
          pageSize: campusData?.pagination.page_size ?? campusFilters.page_size,
          total: campusData?.pagination.total ?? 0,
          showSizeChanger: true,
          pageSizeOptions: [20, 50, 100],
          showTotal: (total) => `共 ${total} 所学校`,
          onChange: (page, pageSize) => setCampusFilters((current) => ({ ...current, page, page_size: pageSize })),
        }}
        locale={{ emptyText: '没有符合当前筛选条件的学校' }}
        columns={[
          { title: '学校', width: 255, render: (_, row) => <Space orientation="vertical" size={3}><Space wrap><Typography.Text strong>{row.name}</Typography.Text>{row.official_note && <Tag color="gold">{row.official_note}</Tag>}</Space><Typography.Text type="secondary" className="table-subline">{row.official_school_code ? `教育部标识码 ${row.official_school_code}` : `平台编码 ${row.code}`}</Typography.Text></Space> },
          { title: '校徽 / Logo', width: 230, render: (_, row) => <CampusBrandPreview campus={row} /> },
          { title: '学校坐标', width: 170, render: (_, row) => row.latitude !== null && row.longitude !== null ? <Tag color="green">人工维护 · WGS84</Tag> : row.reference_locations?.length ? <Tag color="blue">{row.reference_locations.length} 个参考校址</Tag> : <Tag color="gold">待补全</Tag> },
          { title: '目录属性', width: 150, render: (_, row) => <Space orientation="vertical" size={3}><Tag color={row.catalog_type === 'ordinary' ? 'blue' : row.catalog_type === 'adult' ? 'purple' : 'default'}>{catalogTypeLabel(row.catalog_type)}</Tag><Typography.Text type="secondary">{row.education_level ?? '未设置层次'}</Typography.Text></Space> },
          { title: '地区 / 主管部门', width: 230, render: (_, row) => <><span>{[row.province, row.city].filter(Boolean).join(' · ') || '地区待完善'}</span><span className="table-subline">{row.supervising_authority ?? row.address ?? '主管部门待完善'}</span></> },
          { title: '运营状态', width: 125, render: (_, row) => <Space orientation="vertical" size={4}><Tag color={row.status === 'active' ? 'success' : 'default'}>{row.status === 'active' ? '已开通' : '待开通 / 暂停'}</Tag>{row.catalog_status === 'unlisted' && <Tag color="error">已退出名录</Tag>}</Space> },
          {
            title: '隔离模式',
            width: 140,
            render: (_, row) => {
              const strict = row.isolation_mode === 'strict'
              return (
                <Space orientation="vertical" size={4}>
                  <Tag color={strict ? 'volcano' : 'default'}>{strict ? '严格隔离' : '传统模式'}</Tag>
                  {canSwitchIsolation && canManageCampuses && (
                    <Button size="small" type="link" style={{ paddingInline: 0 }} onClick={() => toggleIsolation(row)}>
                      切换为{strict ? '传统' : '严格'}
                    </Button>
                  )}
                </Space>
              )
            },
          },
          { title: '用户', dataIndex: 'users_count', width: 90, render: (value: number) => `${value} 人` },
          { title: '学校代理', width: 285, render: (_, row) => row.agents.length ? <Space wrap>{row.agents.map((agent) => <Tag key={agent.id} color={agent.is_primary ? 'blue' : undefined}>{agent.display_name}{agent.is_primary ? ' · 主代理' : ''}</Tag>)}</Space> : <Typography.Text type="secondary">尚未分配</Typography.Text> },
          { title: '更新时间', width: 170, render: (_, row) => formatTime(row.updated_at) },
          { title: '操作', fixed: 'right', width: 372, render: (_, row) => <Space><Button size="small" icon={<AppstoreOutlined />} onClick={() => setModuleCampus(row)}>功能模块</Button>{canManageCampuses && <Button size="small" onClick={() => openEdit(row)}>编辑</Button>}{canGovernAgents && <Button size="small" icon={<TeamOutlined />} onClick={() => openAgents(row)}>分配代理</Button>}{canGovernAgents && <Button size="small" icon={<ThunderboltOutlined />} loading={quickAgentBusy && quickAgentTarget?.id === row.id} onClick={() => startQuickAgent(row)}>一键建代理</Button>}</Space> },
        ]}
      />
    </Card>
  </>

  const agentManagementTab = <>
    <Alert showIcon type="info" title="代理账号只获得学校代理角色的最小权限；具体学校范围请在“学校库”中按学校分配。" />
    <div className="campus-agent-stat-grid">
      <Card variant="borderless"><Statistic title="代理总数" value={agentData?.summary.total ?? 0} suffix="人" /></Card>
      <Card variant="borderless"><Statistic title="正常账号" value={agentData?.summary.active ?? 0} suffix="人" /></Card>
      <Card variant="borderless"><Statistic title="待分配学校" value={agentData?.summary.unassigned ?? 0} suffix="人" /></Card>
      <Card variant="borderless"><Statistic title="覆盖学校" value={agentData?.summary.campuses_covered ?? 0} suffix="所" /></Card>
      <Card variant="borderless"><Statistic title="覆盖用户" value={agentData?.summary.users_covered ?? 0} suffix="人" /></Card>
    </div>
    <Card variant="borderless" className="campus-filter-card">
      <div className="campus-filter-bar">
        <Input.Search allowClear value={agentKeyword} onChange={(event) => setAgentKeyword(event.target.value)} onSearch={(keyword) => setAgentFilters((current) => ({ ...current, keyword: keyword.trim(), page: 1 }))} placeholder="代理姓名 / 账号 / 邮箱" style={{ width: 280 }} />
        <Select allowClear placeholder="账号状态" value={agentFilters.status || undefined} onChange={(value) => setAgentFilters((current) => ({ ...current, status: value ?? '', page: 1 }))} options={[{ value: 'active', label: '正常' }, { value: 'disabled', label: '已停用' }]} style={{ width: 130 }} />
        <Select allowClear placeholder="学校分配" value={agentFilters.assignment || undefined} onChange={(value) => setAgentFilters((current) => ({ ...current, assignment: value ?? '', page: 1 }))} options={[{ value: 'assigned', label: '已分配学校' }, { value: 'unassigned', label: '未分配学校' }]} style={{ width: 145 }} />
        <Button onClick={() => { setAgentKeyword(''); setAgentFilters(initialAgentFilters) }}>重置</Button>
        <Button type="primary" icon={<PlusOutlined />} onClick={() => { setCreatingAgent(true); newAgentForm.resetFields() }}>新增代理账号</Button>
      </div>
    </Card>
    <Card variant="borderless" className="campus-table-card">
      <Table
        rowKey="id"
        loading={agentLoading}
        dataSource={agentData?.items ?? []}
        scroll={{ x: 1120 }}
        pagination={{ current: agentData?.pagination.page ?? 1, pageSize: agentData?.pagination.page_size ?? 20, total: agentData?.pagination.total ?? 0, showTotal: (total) => `共 ${total} 名代理`, onChange: (page) => setAgentFilters((current) => ({ ...current, page })) }}
        columns={[
          { title: '代理账号', width: 210, render: (_, row) => <><Typography.Text strong>{row.display_name}</Typography.Text><span className="table-subline">{row.account}</span><span className="table-subline">{row.email ?? '未填写邮箱'}</span></> },
          { title: '账号状态', width: 105, render: (_, row) => <Tag color={row.status === 'active' ? 'success' : 'error'}>{row.status === 'active' ? '正常' : '已停用'}</Tag> },
          { title: '负责学校', width: 340, render: (_, row) => row.campuses.length ? <Space wrap>{row.campuses.slice(0, 4).map((campus) => <Tag key={campus.id} color={campus.is_primary ? 'blue' : undefined}>{campus.name}{campus.is_primary ? ' · 主负责' : ''}</Tag>)}{row.campuses.length > 4 && <Tag>另 {row.campuses.length - 4} 所</Tag>}</Space> : <Tag color="warning">待分配</Tag> },
          { title: '运营覆盖', width: 135, render: (_, row) => <><span>{row.campuses_count} 所学校</span><span className="table-subline">{row.covered_users_count} 名用户</span></> },
          { title: '安全状态', width: 190, render: (_, row) => <><Tag color={row.requires_mfa ? 'success' : 'warning'}>{row.requires_mfa ? 'MFA 已启用' : 'MFA 待启用'}</Tag><span className="table-subline">最近登录：{formatTime(row.last_login_at)}</span></> },
          { title: '操作', fixed: 'right', width: 105, render: (_, row) => <Popconfirm title="账号状态变更前需要再次核验当前管理员身份。" description="确认打开身份核验窗口？" onConfirm={() => { setAgentStatusTarget(row); agentStatusForm.setFieldsValue({ reason: row.status === 'active' ? '代理岗位调整，停用账号并撤销活跃会话。' : '代理重新到岗，复核后恢复账号。', current_password: '', mfa_code: '' }) }}><Button size="small" danger={row.status === 'active'}>{row.status === 'active' ? '停用' : '恢复'}</Button></Popconfirm> },
        ]}
      />
    </Card>
  </>

  const catalogTab = <>
    <Alert
      showIcon
      type="warning"
      title="全国高校目录与平台运营状态相互独立"
      description="官方目录用于检索和识别学校，新导入学校默认待开通；同步不会覆盖代理、用户、坐标、地址、排序或运营状态，也不会删除历史学校。"
    />
    <div className="campus-catalog-grid">
      <Card variant="borderless" title="数据来源" className="campus-ops-card">
        <Descriptions column={1} size="small">
          <Descriptions.Item label="来源">{catalog?.source_name ?? '—'}</Descriptions.Item>
          <Descriptions.Item label="发布日期">{catalog?.published_on ?? '—'}</Descriptions.Item>
          <Descriptions.Item label="数据截至">{catalog?.as_of ?? '—'}</Descriptions.Item>
          <Descriptions.Item label="覆盖范围">中国大陆高校，不含港澳台</Descriptions.Item>
          <Descriptions.Item label="原始公告"><Typography.Link href={catalog?.source_page_url} target="_blank" rel="noreferrer">查看教育部公告</Typography.Link></Descriptions.Item>
          <Descriptions.Item label="数据库记录">{catalog?.database.listed ?? 0} 所在册，{catalog?.database.unlisted ?? 0} 所退出名录</Descriptions.Item>
        </Descriptions>
      </Card>
      <Card variant="borderless" title="名录构成" className="campus-ops-card">
        <div className="catalog-count-grid">
          <div><strong>{catalog?.counts.total ?? 0}</strong><span>全国高校</span></div>
          <div><strong>{catalog?.counts.ordinary ?? 0}</strong><span>普通高校</span></div>
          <div><strong>{catalog?.counts.undergraduate ?? 0}</strong><span>本科学校</span></div>
          <div><strong>{catalog?.counts.vocational ?? 0}</strong><span>高职专科</span></div>
          <div><strong>{catalog?.counts.adult ?? 0}</strong><span>成人高校</span></div>
          <div><strong>{catalog?.database.unlisted ?? 0}</strong><span>退出名录待复核</span></div>
        </div>
      </Card>
    </div>
    <Card variant="borderless" title="来源文件校验" className="campus-table-card">
      <Table rowKey="catalog_type" pagination={false} dataSource={catalog?.files ?? []} columns={[
        { title: '类别', dataIndex: 'catalog_type', render: (value: 'ordinary' | 'adult') => value === 'ordinary' ? '全国普通高等学校名单' : '全国成人高等学校名单' },
        { title: '记录数', dataIndex: 'records', render: (value: number) => `${value} 所` },
        { title: 'SHA-256', dataIndex: 'sha256', render: (value: string) => <Typography.Text code copyable={{ text: value }}>{value.slice(0, 16)}…</Typography.Text> },
        { title: '官方附件', dataIndex: 'url', render: (value: string) => <Typography.Link href={value} target="_blank" rel="noreferrer">下载核验</Typography.Link> },
      ]} />
    </Card>
  </>

  const tabs = [
    { key: 'overview', label: '运营概览', children: overviewTab },
    { key: 'schools', label: `学校库${summary ? `（${summary.total}）` : ''}`, children: schoolLibraryTab },
    ...(canGovernAgents ? [{ key: 'agents', label: '代理管理', children: agentManagementTab }] : []),
    { key: 'catalog', label: '高校数据', children: catalogTab },
  ]

  return <>
    {contextHolder}
    <div className="page-heading">
      <div>
        <Typography.Text className="eyebrow">CAMPUS OPERATIONS</Typography.Text>
        <Typography.Title level={2}>学校与代理运营</Typography.Title>
        <Typography.Paragraph type="secondary">管理全国高校目录、学校开通状态、代理范围和校园用户覆盖。</Typography.Paragraph>
      </div>
      {canGovernAgents && <Button type="primary" icon={<PlusOutlined />} onClick={openCreate}>新增自定义学校</Button>}
    </div>
    <Tabs className="campus-operations-tabs" items={tabs} />

    <Modal title={editing ? `编辑学校 · ${editing.name}` : '新增自定义学校'} open={editing !== undefined} onCancel={() => setEditing(undefined)} onOk={() => campusForm.submit()} confirmLoading={saving} okText="保存" width={860} destroyOnHidden>
      {editing?.official_school_code && <Alert showIcon type="info" title="学校名称、教育部标识码、省市和主管部门来自官方目录；本页只维护平台运营状态及补充地址。" style={{ marginBottom: 16 }} />}
      <Form form={campusForm} layout="vertical" onFinish={saveCampus} initialValues={emptyCampus}>
        <div className="campus-form-grid"><Form.Item name="name" label="学校名称" rules={[{ required: true }]}><Input disabled={Boolean(editing?.official_school_code)} prefix={<EnvironmentOutlined />} placeholder="例如：江西科技学院" /></Form.Item><Form.Item name="code" label="平台学校编码" rules={[{ required: true }, { pattern: /^[a-z0-9][a-z0-9-]*$/, message: '仅支持小写字母、数字和短横线' }]}><Input disabled={Boolean(editing?.official_school_code)} placeholder="jxut" /></Form.Item></div>
        <div className="campus-brand-form-grid">
          <Form.Item name="emblem_image" label="学校校徽（方形）"><CampusBrandImageInput kind="emblem" /></Form.Item>
          <Form.Item name="logo_image" label="学校 Logo（横版）"><CampusBrandImageInput kind="logo" /></Form.Item>
        </div>
        <div className="campus-form-grid campus-form-grid-three"><Form.Item name="province" label="省"><Input disabled={Boolean(editing?.official_school_code)} /></Form.Item><Form.Item name="city" label="市"><Input disabled={Boolean(editing?.official_school_code)} /></Form.Item><Form.Item name="district" label="区 / 县"><Input /></Form.Item></div>
        <Form.Item name="address" label="详细地址"><Input /></Form.Item>
        <div className="campus-form-grid"><Form.Item name="latitude" label="纬度（WGS84）"><InputNumber style={{ width: '100%' }} min={-90} max={90} precision={7} /></Form.Item><Form.Item name="longitude" label="经度（WGS84）"><InputNumber style={{ width: '100%' }} min={-180} max={180} precision={7} /></Form.Item></div>
        {!!editing?.reference_locations?.length && <Form.Item label="公开 POI 参考校址（尚未人工核验）">
          <Table size="small" pagination={false} rowKey={(point) => `${point.latitude}:${point.longitude}`} dataSource={editing.reference_locations} columns={[
            { title: '校区 / 地址', render: (_, point) => <><Typography.Text>{point.name}</Typography.Text><span className="table-subline">{point.address}</span></> },
            { title: '纬度 / 经度', render: (_, point) => `${point.latitude}, ${point.longitude}` },
            { title: '操作', width: 106, render: (_, point) => <Button size="small" onClick={() => campusForm.setFieldsValue({ latitude: point.latitude, longitude: point.longitude, address: point.address, district: point.district })}>采用此校址</Button> },
          ]} />
        </Form.Item>}
        <div className="campus-form-grid"><Form.Item name="status" label="运营状态"><Select options={[{ value: 'active', label: '已开通运营' }, { value: 'disabled', label: '待开通 / 暂停' }]} /></Form.Item><Form.Item name="sort_order" label="前台排序"><InputNumber style={{ width: '100%' }} min={0} max={9999} /></Form.Item><Form.Item name="isolation_mode" label="隔离模式" extra={canSwitchIsolation ? undefined : '仅平台超级管理员可切换'}><Select disabled={!canSwitchIsolation} options={[{ value: 'legacy', label: '传统模式' }, { value: 'strict', label: '严格隔离' }]} /></Form.Item></div>
      </Form>
    </Modal>

    <Modal title={`分配学校代理${agentCampus ? ` · ${agentCampus.name}` : ''}`} open={Boolean(agentCampus)} onCancel={() => setAgentCampus(null)} onOk={() => agentForm.submit()} confirmLoading={saving} okText="保存代理" width={620} destroyOnHidden>
      <Alert showIcon type="info" title="主代理负责该校主要运营，协作代理拥有相同学校数据范围；分配不会产生多级分销或自动佣金。" style={{ marginBottom: 16 }} />
      <Form form={agentForm} layout="vertical" onFinish={saveAgents}>
        <Form.Item name="admin_user_ids" label="代理管理员账号"><Select mode="multiple" allowClear showSearch optionFilterProp="label" options={agentSelectOptions} placeholder="选择具有学校代理角色的后台账号" onChange={(values: string[]) => { const primary = agentForm.getFieldValue('primary_admin_user_id'); if (primary && !values.includes(primary)) agentForm.setFieldValue('primary_admin_user_id', undefined) }} /></Form.Item>
        <Form.Item name="primary_admin_user_id" label="主代理"><Select allowClear showSearch optionFilterProp="label" options={primaryAgentOptions} placeholder="从已选代理中指定一名主代理" /></Form.Item>
      </Form>
    </Modal>

    <Modal title="新增学校代理账号" open={creatingAgent} onCancel={() => { setCreatingAgent(false); newAgentForm.resetFields() }} onOk={() => newAgentForm.submit()} confirmLoading={saving} okText="验证并创建" width={620} destroyOnHidden>
      <Alert showIcon type="warning" title="账号将自动授予“学校代理”角色；创建后仍需到学校库分配学校。此操作要求核验当前管理员身份。" style={{ marginBottom: 16 }} />
      <Form form={newAgentForm} layout="vertical" onFinish={createAgent}>
        <div className="campus-form-grid"><Form.Item name="account" label="登录账号" rules={[{ required: true, min: 3, pattern: /^[a-zA-Z0-9._-]+$/ }]}><Input autoComplete="off" /></Form.Item><Form.Item name="display_name" label="代理姓名" rules={[{ required: true }]}><Input /></Form.Item></div>
        <Form.Item name="email" label="邮箱"><Input type="email" /></Form.Item>
        <Form.Item name="password" label="初始密码" rules={[{ required: true, min: 12, message: '初始密码至少 12 位' }]}><Input.Password autoComplete="new-password" /></Form.Item>
        <Form.Item name="current_password" label="当前管理员密码" rules={[{ required: true }]}><Input.Password autoComplete="current-password" /></Form.Item>
        {admin?.requires_mfa && <Form.Item name="mfa_code" label="动态验证码或恢复码" rules={[{ required: true }]}><Input autoComplete="one-time-code" /></Form.Item>}
      </Form>
    </Modal>

    <Modal title="核验管理员身份" open={quickAgentVerifyOpen} onCancel={cancelQuickAgent} onOk={() => quickAgentForm.submit()} confirmLoading={quickAgentBusy} okText="验证并创建" width={560} destroyOnHidden>
      <Alert showIcon type="warning" title="创建后台代理账号属于高风险操作，需要复核当前管理员身份；核验通过后会立即创建账号并分配到该校。" style={{ marginBottom: 16 }} />
      {quickAgentTarget && quickAgentCredentialValue && <Descriptions column={1} size="small" style={{ marginBottom: 16 }}>
        <Descriptions.Item label="负责学校">{quickAgentTarget.name}</Descriptions.Item>
        <Descriptions.Item label="预生成账号">{quickAgentCredentialValue.account}</Descriptions.Item>
      </Descriptions>}
      <Form form={quickAgentForm} layout="vertical" onFinish={confirmQuickAgent}>
        <Form.Item name="current_password" label="当前管理员密码" rules={[{ required: true }]}><Input.Password autoComplete="current-password" /></Form.Item>
        {admin?.requires_mfa && <Form.Item name="mfa_code" label="动态验证码或恢复码" rules={[{ required: true }]}><Input autoComplete="one-time-code" /></Form.Item>}
      </Form>
    </Modal>

    <Modal
      title="代理测试账号已创建"
      open={Boolean(quickAgentResult)}
      onCancel={() => setQuickAgentResult(null)}
      footer={<Button type="primary" onClick={() => setQuickAgentResult(null)}>我已记录</Button>}
      width={560}
      destroyOnHidden
    >
      {quickAgentResult && <>
        <Alert
          showIcon
          type={quickAgentResult.assigned ? 'success' : 'warning'}
          title={quickAgentResult.assigned
            ? `已加入「${quickAgentResult.campus_name}」代理范围；若该校原本没有主代理，该账号会成为主代理。`
            : `账号已创建，但未加入「${quickAgentResult.campus_name}」代理范围，请使用「分配代理」手动关联。`}
          style={{ marginBottom: 16 }}
        />
        <Descriptions column={1} size="small" bordered>
          <Descriptions.Item label="登录账号"><Typography.Text copyable>{quickAgentResult.account}</Typography.Text></Descriptions.Item>
          <Descriptions.Item label="初始密码"><Typography.Text code copyable>{quickAgentResult.password}</Typography.Text></Descriptions.Item>
        </Descriptions>
        <Typography.Paragraph type="secondary" style={{ marginTop: 12 }}>初始密码只在此处显示一次，请立即复制转交；账号具备该校真实后台权限，测试结束后请到“代理管理”停用。</Typography.Paragraph>
      </>}
    </Modal>

    <CampusModuleDrawer
      open={Boolean(moduleCampus)}
      campus={moduleCampus}
      canWrite={canManageModules}
      requiresMfa={admin?.requires_mfa}
      onClose={() => setModuleCampus(null)}
    />

    <Modal title={agentStatusTarget?.status === 'active' ? '停用代理账号' : '恢复代理账号'} open={Boolean(agentStatusTarget)} onCancel={() => { setAgentStatusTarget(null); agentStatusForm.resetFields() }} onOk={() => agentStatusForm.submit()} confirmLoading={saving} okText="验证并确认" destroyOnHidden>
      <Form form={agentStatusForm} layout="vertical" onFinish={changeAgentStatus}>
        <Form.Item name="reason" label="操作原因" rules={[{ required: true, min: 4 }]}><Input.TextArea rows={3} /></Form.Item>
        <Form.Item name="current_password" label="当前管理员密码" rules={[{ required: true }]}><Input.Password autoComplete="current-password" /></Form.Item>
        {admin?.requires_mfa && <Form.Item name="mfa_code" label="动态验证码或恢复码" rules={[{ required: true }]}><Input autoComplete="one-time-code" /></Form.Item>}
      </Form>
    </Modal>
  </>
}
