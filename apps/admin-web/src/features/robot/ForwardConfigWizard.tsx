import { DeleteOutlined, PlusOutlined, ReloadOutlined } from '@ant-design/icons'
import { Alert, Button, Card, Checkbox, Descriptions, Form, Input, InputNumber, Modal, Popconfirm, Select, Space, Steps, Switch, Tag, Tooltip, Typography, message } from 'antd'
import type { TextAreaRef } from 'antd/es/input/TextArea'
import { useCallback, useEffect, useRef, useState } from 'react'
import {
  createXpRobotForward,
  listXpRobotForwardFilterOptions,
  listXpRobotAccounts,
  listXpRobotDevices,
  listXpRobotGroups,
  updateXpRobotForward,
  XP_ROBOT_FORWARD_LINK_FORMAT_OPTIONS,
  XP_ROBOT_FORWARD_POST_STATUS_OPTIONS,
  XP_ROBOT_FORWARD_TASK_STATUS_OPTIONS,
  type XpRobotAccount,
  type XpRobotDevice,
  type XpRobotForwardConfig,
  type XpRobotForwardConfigInput,
  type XpRobotForwardGroup,
  type XpRobotForwardPostStatus,
  type XpRobotForwardTarget,
  type XpRobotForumCategory,
  type XpRobotForwardTaskStatus,
  type XpRobotGroup,
  type XpRobotSchoolOption,
  type XpRobotTaskCategory,
} from '../../api/admin'
import { robotAccountName, robotAccountStatusLabel, robotAccountTypeLabel, robotDeviceName, robotGroupName } from './robotUtils'
import { useRobotRecentVerification } from './useRobotRecentVerification'

const postTemplateVariables = [
  { variable: '${index}', label: '序号', source: '本条在本次转发列表中的序号，从 1 开始。' },
  { variable: '${title}', label: '标题', source: '帖子标题（community_posts.title）。' },
  { variable: '${content}', label: '正文', source: '帖子正文，按“正文截取长度”截取。' },
  { variable: '${categoryName}', label: '分类', source: '帖子分类：经验分享、实用攻略、互助问答、成果展示。' },
  { variable: '${nickname}', label: '昵称', source: '发帖用户昵称。' },
  { variable: '${mobile}', label: '手机号', source: '发帖用户手机号，脱敏展示。' },
  { variable: '${likeNum}', label: '点赞数', source: '帖子点赞数。' },
  { variable: '${commentNum}', label: '评论数', source: '帖子评论数。' },
  { variable: '${favoriteNum}', label: '收藏数', source: '帖子收藏数。' },
  { variable: '${wappLink}', label: '小程序链接', source: '该帖子的链接：优先微信 URL Link（mp://），未配置小程序凭据或生成失败时回退为 H5 帖子链接。' },
  { variable: '${addTime}', label: '发布时间', source: '帖子发布时间。' },
  { variable: '${total}', label: '总数', source: '本次转发聚合的帖子总数。' },
] as const

const taskTemplateVariables = [
  { variable: '${index}', label: '序号', source: '本条在本次转发列表中的序号，从 1 开始。' },
  { variable: '${title}', label: '标题', source: '任务标题（任务当前发布版本标题）。' },
  { variable: '${content}', label: '任务说明', source: '任务描述，按“正文截取长度”截取。' },
  { variable: '${categoryName}', label: '任务分类', source: '任务分类：新手任务、日常任务、社区任务、商城任务、调研任务、活动任务。' },
  { variable: '${reward}', label: '任务奖励', source: '任务奖励摘要，例如 +10 积分 或 +¥5.00 余额。' },
  { variable: '${nickname}', label: '发布者', source: '任务发布者显示名称。' },
  { variable: '${campusName}', label: '学校', source: '任务所属学校名称。' },
  { variable: '${lookNum}', label: '浏览数', source: '任务浏览量。' },
  { variable: '${wappLink}', label: '小程序链接', source: '该任务的链接：优先微信 URL Link（mp://），未配置小程序凭据或生成失败时回退为 H5 任务详情链接。' },
  { variable: '${addTime}', label: '发布时间', source: '任务发布时间。' },
  { variable: '${total}', label: '总数', source: '本次转发聚合的任务总数。' },
] as const

const templatePresets = [
  { key: 'concise', label: '简洁', template: '${index}. ${title}\n📞 ${mobile}' },
  {
    key: 'detailed',
    label: '详细',
    template: '【${categoryName}】${title}\n\n${content}\n\n📞 联系方式：${mobile}\n👤 发布者：${nickname}\n👀 ${lookNum}浏览  👍 ${likeNum}点赞',
  },
  {
    key: 'tail',
    label: '带尾巴',
    template: '${index}/${total}. ${title}\n📞 ${mobile}\n---END---\n💡 更多同城信息，持续更新中',
  },
] as const

const weekdayOptions = [
  { value: 1, label: '周一' }, { value: 2, label: '周二' }, { value: 3, label: '周三' },
  { value: 4, label: '周四' }, { value: 5, label: '周五' }, { value: 6, label: '周六' }, { value: 7, label: '周日' },
]

const createTarget = (): XpRobotForwardTarget => ({
  target_id: crypto.randomUUID(),
  device_id: '',
  device_name: null,
  wxid: '',
  account_name: null,
  groups: [],
})

const createDefaults = (): XpRobotForwardConfigInput => ({
  name: '',
  description: '',
  enabled: true,
  filter_rules: {
    content_type: 'community_post',
    platforms: [],
    campus_id: null,
    campus_name: null,
    categories: [],
    post_statuses: ['published'] as XpRobotForwardPostStatus[],
    task_statuses: ['published'] as XpRobotForwardTaskStatus[],
    aggregate_interval: 30,
    min_messages: 1,
    max_messages: 20,
    quiet_start: null,
    quiet_end: null,
    active_weekdays: [1, 2, 3, 4, 5, 6, 7],
  },
  template_config: {
    template: templatePresets[0].template,
    footer: '',
    keywords: [],
    content_max_length: 500,
    link_format: 'url_link',
  },
  robot_targets: [createTarget()],
})

const listText = (items: string[]) => items.length ? items.join('、') : '不限'
const quietText = (start: string | null, end: string | null) => start || end ? `${start ?? '—'} 至 ${end ?? '—'}` : '未设置'

export function ForwardConfigSummary({ value }: { value: XpRobotForwardConfigInput }) {
  const filter = value.filter_rules
  const template = value.template_config
  const isTask = filter.content_type === 'task'
  const statusOptions = isTask ? XP_ROBOT_FORWARD_TASK_STATUS_OPTIONS : XP_ROBOT_FORWARD_POST_STATUS_OPTIONS
  return <div>
    <Descriptions
      bordered
      size="small"
      column={{ xs: 1, md: 2 }}
      items={[
        { key: 'name', label: '配置名称', children: value.name },
        { key: 'enabled', label: '启用状态', children: value.enabled ? <Tag color="success">启用</Tag> : <Tag>停用</Tag> },
        { key: 'description', label: '描述', span: 2, children: value.description || '—' },
        { key: 'content_type', label: '内容类型', children: isTask ? '校园任务' : '社区帖子' },
        { key: 'platforms', label: '平台', children: listText(filter.platforms) },
        { key: 'campus', label: '学校', children: filter.campus_name || filter.campus_id || '不限' },
        { key: 'categories', label: isTask ? '任务分类' : '论坛分类', children: listText(filter.categories.map((category) => category.name || category.code)) },
        { key: 'statuses', label: isTask ? '任务状态' : '帖子状态', children: listText((isTask ? filter.task_statuses ?? ['published'] : filter.post_statuses ?? ['published']).map((status) => statusOptions.find((item) => item.value === status)?.label ?? status)) },
        { key: 'keywords', label: '关键词', children: listText(template.keywords) },
        { key: 'aggregate', label: '聚合间隔', children: `${filter.aggregate_interval} 分钟` },
        { key: 'messages', label: '消息数范围', children: `${filter.min_messages} – ${filter.max_messages} 条` },
        { key: 'quiet', label: '静默时段', children: quietText(filter.quiet_start, filter.quiet_end) },
        { key: 'weekdays', label: '活跃星期', children: filter.active_weekdays.map((day) => weekdayOptions.find((item) => item.value === day)?.label ?? day).join('、') },
        { key: 'length', label: '正文截取长度', children: template.content_max_length === 0 ? '不截取' : `${template.content_max_length} 字` },
        { key: 'link_format', label: '小程序短链', children: (template.link_format ?? 'url_link') === 'short_link' ? 'Short Link（mp://）' : 'URL Link' },
        { key: 'footer', label: '结尾文案', span: 2, children: template.footer ? <Typography.Text style={{ whiteSpace: 'pre-wrap' }}>{template.footer}</Typography.Text> : '—' },
        { key: 'template', label: '消息模板', span: 2, children: <Typography.Text style={{ whiteSpace: 'pre-wrap' }}>{template.template}</Typography.Text> },
      ]}
    />
    <Typography.Title level={5} style={{ marginTop: 20 }}>机器人目标（{value.robot_targets.length} 条）</Typography.Title>
    <Space direction="vertical" size={10} style={{ width: '100%' }}>
      {value.robot_targets.map((target, index) => <Card size="small" key={target.target_id} title={`目标 ${index + 1}：${target.device_name || target.device_id}`}>
        <Typography.Text copyable={{ text: target.wxid }}>账号：{target.account_name || target.wxid}（{target.wxid}）</Typography.Text>
        <div style={{ marginTop: 10 }}>
          <Typography.Text strong>目标群（{target.groups.length}）：</Typography.Text>
          <Space wrap size={[6, 6]} style={{ marginTop: 6 }}>
            {target.groups.map((group) => <Tag key={group.group_id}>{group.group_name || group.group_id}</Tag>)}
          </Space>
        </div>
      </Card>)}
    </Space>
  </div>
}

interface Props {
  open: boolean
  config: XpRobotForwardConfig | null
  configured: boolean
  requiresMfa: boolean
  onClose: () => void
  onSaved: (config: XpRobotForwardConfig) => void
}

export function ForwardConfigWizard({ open, config, configured, requiresMfa, onClose, onSaved }: Props) {
  const [form] = Form.useForm<XpRobotForwardConfigInput>()
  const [messageApi, messageContext] = message.useMessage()
  const { runWrite, verificationContext } = useRobotRecentVerification(requiresMfa)
  const [current, setCurrent] = useState(0)
  const [saving, setSaving] = useState(false)
  const [schools, setSchools] = useState<XpRobotSchoolOption[]>([])
  const [forumCategories, setForumCategories] = useState<XpRobotForumCategory[]>([])
  const [taskCategories, setTaskCategories] = useState<XpRobotTaskCategory[]>([])
  const [filterOptionsLoading, setFilterOptionsLoading] = useState(false)
  const [devices, setDevices] = useState<XpRobotDevice[]>([])
  const [devicesLoading, setDevicesLoading] = useState(false)
  const [accountsByTarget, setAccountsByTarget] = useState<Record<string, XpRobotAccount[]>>({})
  const [groupsByTarget, setGroupsByTarget] = useState<Record<string, XpRobotGroup[]>>({})
  const [accountsLoading, setAccountsLoading] = useState<Record<string, boolean>>({})
  const [groupLoading, setGroupLoading] = useState<Record<string, 'cache' | 'realtime' | undefined>>({})
  const accountRequests = useRef<Record<string, number>>({})
  const groupRequests = useRef<Record<string, number>>({})
  const filterOptionRequest = useRef(0)
  const templateRef = useRef<TextAreaRef>(null)
  const watchedValues = Form.useWatch([], form) as XpRobotForwardConfigInput | undefined
  const watchedCampusId = Form.useWatch(['filter_rules', 'campus_id'], form) as string | null | undefined
  const watchedContentType = Form.useWatch(['filter_rules', 'content_type'], form) as 'community_post' | 'task' | undefined
  const isTaskContent = watchedContentType === 'task'

  useEffect(() => {
    if (!open) return
    setCurrent(0)
    form.setFieldsValue(config ? structuredClone(config) : createDefaults())
    setTaskCategories([])
    setAccountsByTarget({})
    setGroupsByTarget({})
    setAccountsLoading({})
    setGroupLoading({})
  }, [open, config, form])

  const loadFilterOptions = useCallback(async (campusId: string | null = null) => {
    const requestId = ++filterOptionRequest.current
    setFilterOptionsLoading(true)
    try {
      const result = await listXpRobotForwardFilterOptions(campusId ?? undefined)
      if (filterOptionRequest.current !== requestId) return
      setSchools(result.schools)
      setForumCategories(result.categories)
      setTaskCategories(result.task_categories ?? [])
    } catch (error) {
      if (filterOptionRequest.current === requestId) {
        messageApi.error(error instanceof Error ? error.message : '学校或论坛分类加载失败，已保留原配置快照。')
      }
    } finally {
      if (filterOptionRequest.current === requestId) setFilterOptionsLoading(false)
    }
  }, [messageApi])

  useEffect(() => {
    if (open) void loadFilterOptions(watchedCampusId ?? null)
  }, [open, watchedCampusId, loadFilterOptions])

  const loadDevices = useCallback(async () => {
    if (!configured) {
      setDevices([])
      return
    }
    setDevicesLoading(true)
    try {
      const result = await listXpRobotDevices()
      setDevices(result.items)
    } catch (error) {
      messageApi.error(error instanceof Error ? error.message : 'XP 设备列表加载失败，已保留原配置快照。')
    } finally {
      setDevicesLoading(false)
    }
  }, [configured, messageApi])

  useEffect(() => {
    if (open) void loadDevices()
  }, [open, loadDevices])

  const loadAccounts = useCallback(async (targetId: string, deviceId: string) => {
    if (!deviceId) return
    const requestId = (accountRequests.current[targetId] ?? 0) + 1
    accountRequests.current[targetId] = requestId
    setAccountsLoading((currentValue) => ({ ...currentValue, [targetId]: true }))
    try {
      const result = await listXpRobotAccounts(deviceId)
      if (accountRequests.current[targetId] === requestId) {
        setAccountsByTarget((currentValue) => ({ ...currentValue, [targetId]: result.items }))
      }
    } catch (error) {
      if (accountRequests.current[targetId] === requestId) {
        messageApi.error(error instanceof Error ? error.message : '账号列表加载失败，已保存的账号快照仍可使用。')
      }
    } finally {
      if (accountRequests.current[targetId] === requestId) {
        setAccountsLoading((currentValue) => ({ ...currentValue, [targetId]: false }))
      }
    }
  }, [messageApi])

  const loadGroups = useCallback(async (targetId: string, deviceId: string, wxid: string, realtime: boolean) => {
    if (!deviceId || !wxid) return
    const requestId = (groupRequests.current[targetId] ?? 0) + 1
    groupRequests.current[targetId] = requestId
    setGroupLoading((currentValue) => ({ ...currentValue, [targetId]: realtime ? 'realtime' : 'cache' }))
    try {
      const result = await listXpRobotGroups(deviceId, wxid, realtime)
      if (groupRequests.current[targetId] !== requestId) return
      setGroupsByTarget((currentValue) => ({ ...currentValue, [targetId]: result.items }))
      if (realtime) messageApi.success(`实时群同步完成，共 ${result.items.length} 个群。`)
    } catch (error) {
      if (groupRequests.current[targetId] === requestId) {
        messageApi.error(error instanceof Error ? error.message : '群列表加载失败，已选择的群和名称快照均已保留。')
      }
    } finally {
      if (groupRequests.current[targetId] === requestId) {
        setGroupLoading((currentValue) => ({ ...currentValue, [targetId]: undefined }))
      }
    }
  }, [messageApi])

  const updateTarget = (fieldName: number, patch: Partial<XpRobotForwardTarget>) => {
    const target = form.getFieldValue(['robot_targets', fieldName]) as XpRobotForwardTarget
    form.setFieldValue(['robot_targets', fieldName], { ...target, ...patch })
  }

  const selectDevice = (fieldName: number, targetId: string, deviceId: string) => {
    const device = devices.find((item) => item.device_id === deviceId)
    updateTarget(fieldName, {
      device_id: deviceId,
      device_name: device ? robotDeviceName(device) : deviceId,
      wxid: '',
      account_name: null,
      groups: [],
    })
    setAccountsByTarget((currentValue) => ({ ...currentValue, [targetId]: [] }))
    setGroupsByTarget((currentValue) => ({ ...currentValue, [targetId]: [] }))
    void loadAccounts(targetId, deviceId)
  }

  const selectAccount = (fieldName: number, targetId: string, wxid: string) => {
    const account = (accountsByTarget[targetId] ?? []).find((item) => item.wxid === wxid)
    const deviceId = (form.getFieldValue(['robot_targets', fieldName, 'device_id']) as string | undefined) ?? ''
    updateTarget(fieldName, { wxid, account_name: account ? robotAccountName(account) : wxid, groups: [] })
    setGroupsByTarget((currentValue) => ({ ...currentValue, [targetId]: [] }))
    void loadGroups(targetId, deviceId, wxid, false)
  }

  const deviceOptions = (target: XpRobotForwardTarget | undefined) => {
    const options = devices.map((item) => ({
      value: item.device_id,
      label: `${robotDeviceName(item)} · ${item.online ? '在线' : '离线'}${item.enabled === false ? ' · 已停用' : ''}`,
    }))
    if (target?.device_id && !options.some((item) => item.value === target.device_id)) {
      options.push({ value: target.device_id, label: `${target.device_name || target.device_id} · 已保存快照` })
    }
    return options
  }

  const accountOptions = (target: XpRobotForwardTarget | undefined) => {
    const accounts = target ? (accountsByTarget[target.target_id] ?? []) : []
    const options = accounts.map((item) => ({
      value: item.wxid,
      label: `${robotAccountName(item)} · ${robotAccountTypeLabel(item.account_type)}${item.status ? ` · ${robotAccountStatusLabel(item.status)}` : ''}`,
    }))
    if (target?.wxid && !options.some((item) => item.value === target.wxid)) {
      options.push({ value: target.wxid, label: `${target.account_name || target.wxid} · 已保存快照` })
    }
    return options
  }

  const groupOptions = (target: XpRobotForwardTarget | undefined) => {
    if (!target) return []
    const groups = groupsByTarget[target.target_id] ?? []
    const options = groups.map((item) => ({ value: item.group_id, label: `${robotGroupName(item)}${item.member_count ? ` · ${item.member_count} 人` : ''}` }))
    target.groups.forEach((group) => {
      if (!options.some((item) => item.value === group.group_id)) options.push({ value: group.group_id, label: `${group.group_name || group.group_id} · 已保存快照` })
    })
    return options
  }

  const schoolOptions = schools.map((school) => ({
    value: school.id,
    label: `${school.name}${school.city ? ` · ${school.city}` : ''}`,
  }))
  const selectedFilter = watchedValues?.filter_rules
  if (selectedFilter?.campus_id && !schoolOptions.some((item) => item.value === selectedFilter.campus_id)) {
    schoolOptions.push({ value: selectedFilter.campus_id, label: `${selectedFilter.campus_name || selectedFilter.campus_id} · 已保存快照` })
  }
  const forumCategoryOptions = forumCategories.map((category) => ({ value: category.code, label: category.name }))
  selectedFilter?.categories.forEach((category) => {
    if (!forumCategoryOptions.some((item) => item.value === category.code)) {
      forumCategoryOptions.push({ value: category.code, label: `${category.name || category.code} · 已保存快照` })
    }
  })
  const taskCategoryOptions = taskCategories.map((category) => ({ value: category.code, label: category.name }))
  selectedFilter?.categories.forEach((category) => {
    if (!taskCategoryOptions.some((item) => item.value === category.code)) {
      taskCategoryOptions.push({ value: category.code, label: `${category.name || category.code} · 已保存快照` })
    }
  })

  const selectContentType = (type: 'community_post' | 'task') => {
    const currentFilter = form.getFieldValue('filter_rules') as XpRobotForwardConfigInput['filter_rules']
    form.setFieldsValue({
      filter_rules: {
        ...currentFilter,
        content_type: type,
        categories: [],
        post_statuses: type === 'community_post' ? ['published'] : [],
        task_statuses: type === 'task' ? ['published'] : [],
      },
    })
  }

  const selectSchool = (campusId: string | undefined) => {
    const school = schools.find((item) => item.id === campusId)
    const currentFilter = form.getFieldValue('filter_rules') as XpRobotForwardConfigInput['filter_rules']
    form.setFieldsValue({
      filter_rules: {
        ...currentFilter,
        campus_id: campusId ?? null,
        campus_name: school?.name ?? null,
        categories: [],
      },
    })
    setForumCategories([])
  }

  const insertVariable = (variable: string) => {
    const namePath: ['template_config', 'template'] = ['template_config', 'template']
    const currentValue = (form.getFieldValue(namePath) as string | undefined) ?? ''
    const textarea = templateRef.current?.resizableTextArea?.textArea
    const start = textarea?.selectionStart ?? currentValue.length
    const end = textarea?.selectionEnd ?? start
    const nextValue = `${currentValue.slice(0, start)}${variable}${currentValue.slice(end)}`
    form.setFieldValue(namePath, nextValue)
    requestAnimationFrame(() => {
      textarea?.focus()
      textarea?.setSelectionRange(start + variable.length, start + variable.length)
    })
  }

  const nextStep = async () => {
    try {
      if (current === 0) await form.validateFields(['name', 'description'])
      if (current === 1) await form.validateFields(['filter_rules'])
      if (current === 2) await form.validateFields(['template_config'])
      if (current === 3) await form.validateFields(['robot_targets'])
      setCurrent((step) => Math.min(step + 1, 4))
    } catch {
      messageApi.warning('请先完成当前步骤的必填项并修正校验错误。')
    }
  }

  const save = async () => {
    try {
      await form.validateFields()
    } catch {
      messageApi.warning('配置尚未完整，请返回对应步骤修正校验错误。')
      return
    }
    const values = form.getFieldsValue()
    const input: XpRobotForwardConfigInput = {
      name: values.name.trim(),
      description: values.description?.trim() || null,
      enabled: values.enabled,
      filter_rules: {
        ...values.filter_rules,
        platforms: values.filter_rules.platforms.map((item) => item.trim()).filter(Boolean),
        campus_id: values.filter_rules.campus_id || null,
        campus_name: values.filter_rules.campus_name || null,
        content_type: values.filter_rules.content_type === 'task' ? 'task' : 'community_post',
        categories: values.filter_rules.categories.map((category) => ({ code: category.code.trim(), name: category.name.trim() })).filter((category) => category.code),
        post_statuses: (values.filter_rules.post_statuses ?? []).filter((status) => XP_ROBOT_FORWARD_POST_STATUS_OPTIONS.some((option) => option.value === status)),
        task_statuses: (values.filter_rules.task_statuses ?? []).filter((status) => XP_ROBOT_FORWARD_TASK_STATUS_OPTIONS.some((option) => option.value === status)),
        quiet_start: values.filter_rules.quiet_start || null,
        quiet_end: values.filter_rules.quiet_end || null,
      },
      template_config: {
        ...values.template_config,
        template: values.template_config.template.trim(),
        footer: (values.template_config.footer ?? '').trim(),
        keywords: values.template_config.keywords.map((item) => item.trim()).filter(Boolean),
        link_format: values.template_config.link_format === 'short_link' ? 'short_link' : 'url_link',
      },
      robot_targets: values.robot_targets.map((target) => ({
        ...target,
        device_name: target.device_name || target.device_id,
        account_name: target.account_name || target.wxid,
        groups: target.groups.map((group) => ({ group_id: group.group_id, group_name: group.group_name || group.group_id })),
      })),
    }
    setSaving(true)
    try {
      const saved = await runWrite(
        () => config ? updateXpRobotForward(config.id, input) : createXpRobotForward(input),
        '转发配置保存失败。',
      )
      if (!saved) return
      messageApi.success(config ? '转发配置已保存。' : '转发配置已创建。')
      onSaved(saved)
      onClose()
    } finally {
      setSaving(false)
    }
  }

  const close = () => { if (!saving) onClose() }

  return <>
    {messageContext}
    {verificationContext}
    <Modal
      title={config ? `编辑转发配置：${config.name}` : '新增转发配置'}
      open={open}
      onCancel={close}
      width={1080}
      maskClosable={!saving}
      footer={<Space>
        <Button onClick={close} disabled={saving}>取消</Button>
        {current > 0 ? <Button onClick={() => setCurrent((step) => step - 1)} disabled={saving}>上一步</Button> : null}
        {current < 4
          ? <Button type="primary" onClick={() => void nextStep()}>下一步</Button>
          : <Button type="primary" loading={saving} onClick={() => void save()}>保存配置</Button>}
      </Space>}
    >
      <Steps
        current={current}
        onChange={(step) => { if (step < current) setCurrent(step) }}
        items={[{ title: '基本信息' }, { title: '过滤条件' }, { title: '消息模板' }, { title: '机器人目标' }, { title: '汇总确认' }]}
        style={{ marginBottom: 24 }}
      />
      {!configured ? <Alert showIcon type="warning" message="XP API Key 尚未配置，仍可编辑已有快照；新增目标前请先完成 XP 配置。" style={{ marginBottom: 16 }} /> : null}
      <Form<XpRobotForwardConfigInput> form={form} layout="vertical" initialValues={createDefaults()}>
        <div style={{ display: current === 0 ? undefined : 'none' }}>
          <Form.Item name="name" label="配置名称" rules={[{ required: true, whitespace: true, max: 191, message: '请输入不超过 191 字的配置名称。' }]}>
            <Input maxLength={191} showCount placeholder="例如：任务资讯群转发" />
          </Form.Item>
          <Form.Item name="description" label="配置描述" rules={[{ max: 2000, message: '描述不能超过 2000 字。' }]}>
            <Input.TextArea rows={3} maxLength={2000} showCount placeholder="说明用途、内容来源和适用群范围" />
          </Form.Item>
          <Form.Item name="enabled" label="启用状态" valuePropName="checked" extra="停用只保留配置状态，不代表已实现外部自动调度。">
            <Switch checkedChildren="启用" unCheckedChildren="停用" />
          </Form.Item>
        </div>

        <div style={{ display: current === 1 ? undefined : 'none' }}>
          <Alert showIcon type="info" message="这些规则用于持久化配置与人工测试模板；本期不执行外部采集或自动聚合调度。" style={{ marginBottom: 18 }} />
          <Form.Item name={['filter_rules', 'content_type']} label="内容类型" extra="选择该配置转发的内容来源：社区帖子或校园任务；切换后分类与状态选项会同步切换。">
            <Select
              options={[
                { value: 'community_post', label: '社区帖子' },
                { value: 'task', label: '校园任务' },
              ]}
              onChange={selectContentType}
            />
          </Form.Item>
          <Form.Item name={['filter_rules', 'campus_name']} hidden><Input /></Form.Item>
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(240px, 1fr))', gap: '0 16px' }}>
            <Form.Item name={['filter_rules', 'platforms']} label="平台筛选" extra="输入后回车，可添加多个；留空表示不限。">
              <Select mode="tags" open={false} tokenSeparators={[',', '，']} placeholder="例如：抖音、小红书" />
            </Form.Item>
            <Form.Item name={['filter_rules', 'campus_id']} label="学校筛选" extra="留空表示不限；选择学校后可筛选该校论坛分类。">
              <Select
                allowClear
                showSearch
                optionFilterProp="label"
                loading={filterOptionsLoading}
                options={schoolOptions}
                placeholder="选择系统学校"
                onChange={selectSchool}
                onOpenChange={(isOpen) => { if (isOpen && schools.length === 0 && !filterOptionsLoading) void loadFilterOptions(watchedCampusId ?? null) }}
              />
            </Form.Item>
            <Form.Item
              name={['filter_rules', 'categories']}
              label={isTaskContent ? '任务分类' : '本校论坛分类'}
              getValueProps={(categories: XpRobotForumCategory[] | undefined) => ({ value: (categories ?? []).map((category) => category.code) })}
              getValueFromEvent={(codes: string[]) => {
                const currentCategories = (form.getFieldValue(['filter_rules', 'categories']) ?? []) as XpRobotForumCategory[]
                const source = isTaskContent ? taskCategories : forumCategories
                return codes.map((code) => {
                  const snapshot = currentCategories.find((category) => category.code === code)
                  if (snapshot) return snapshot
                  const category = source.find((item) => item.code === code)
                  return { code, name: category?.name ?? code }
                })
              }}
            >
              <Select
                mode="multiple"
                showSearch
                optionFilterProp="label"
                disabled={!isTaskContent && !watchedCampusId}
                loading={filterOptionsLoading}
                options={isTaskContent ? taskCategoryOptions : forumCategoryOptions}
                placeholder={isTaskContent ? '选择任务分类，可多选' : watchedCampusId ? '选择该校论坛分类，可多选' : '请先选择学校'}
                onOpenChange={(isOpen) => { if (isOpen && !isTaskContent && watchedCampusId && forumCategories.length === 0 && !filterOptionsLoading) void loadFilterOptions(watchedCampusId) }}
              />
            </Form.Item>
            {isTaskContent ? (
              <Form.Item name={['filter_rules', 'task_statuses']} label="任务状态" extra="默认仅转发已发布（进行中）的任务；请谨慎勾选其他状态，避免把未发布内容发到群里。" rules={[{ required: true, type: 'array', min: 1, message: '至少选择一个任务状态。' }]}>
                <Select mode="multiple" options={XP_ROBOT_FORWARD_TASK_STATUS_OPTIONS} placeholder="选择任务状态，至少一项" style={{ width: '100%' }} />
              </Form.Item>
            ) : (
              <Form.Item name={['filter_rules', 'post_statuses']} label="帖子状态" extra="默认仅转发已发布、审核通过的帖子；请谨慎勾选其他状态，避免把待审核内容发到群里。" rules={[{ required: true, type: 'array', min: 1, message: '至少选择一个帖子状态。' }]}>
                <Select mode="multiple" options={XP_ROBOT_FORWARD_POST_STATUS_OPTIONS} placeholder="选择帖子状态，至少一项" style={{ width: '100%' }} />
              </Form.Item>
            )}
            <Form.Item name={['filter_rules', 'aggregate_interval']} label="聚合间隔（分钟）" rules={[{ required: true, message: '请设置聚合间隔。' }]}>
              <InputNumber min={1} max={1440} precision={0} style={{ width: '100%' }} />
            </Form.Item>
            <Form.Item name={['filter_rules', 'min_messages']} label="最少消息数" rules={[{ required: true, message: '请设置最少消息数。' }]}>
              <InputNumber min={1} max={1000} precision={0} style={{ width: '100%' }} />
            </Form.Item>
            <Form.Item
              name={['filter_rules', 'max_messages']}
              label="最多消息数"
              dependencies={[[ 'filter_rules', 'min_messages' ]]}
              rules={[
                { required: true, message: '请设置最多消息数。' },
                { validator: (_, value: number) => value >= form.getFieldValue(['filter_rules', 'min_messages']) ? Promise.resolve() : Promise.reject(new Error('最多消息数不能小于最少消息数。')) },
              ]}
            >
              <InputNumber min={1} max={1000} precision={0} style={{ width: '100%' }} />
            </Form.Item>
            <Form.Item name={['filter_rules', 'quiet_start']} label="静默开始时间" rules={[{ pattern: /^(?:[01]\d|2[0-3]):[0-5]\d$/, message: '请输入 HH:mm，例如 22:00。' }]}>
              <Input maxLength={5} placeholder="例如：22:00" />
            </Form.Item>
            <Form.Item name={['filter_rules', 'quiet_end']} label="静默结束时间" rules={[{ pattern: /^(?:[01]\d|2[0-3]):[0-5]\d$/, message: '请输入 HH:mm，例如 08:00。' }]}>
              <Input maxLength={5} placeholder="例如：08:00" />
            </Form.Item>
          </div>
          <Form.Item name={['filter_rules', 'active_weekdays']} label="活跃星期" rules={[{ required: true, type: 'array', min: 1, message: '至少选择一个星期。' }]}>
            <Checkbox.Group options={weekdayOptions} />
          </Form.Item>
        </div>

        <div style={{ display: current === 2 ? undefined : 'none' }}>
          <Alert showIcon type="info" message="配置转发消息的格式模板；可选择预设后继续编辑，切换预设会替换当前模板内容。" style={{ marginBottom: 18 }} />
          <Form.Item label="插入模板变量" extra={isTaskContent ? '变量取自校园任务真实字段，仅替换以下固定白名单变量，不执行表达式或脚本。' : '变量取自社区帖子真实字段，仅替换以下固定白名单变量，不执行表达式或脚本；${lookNum}、${shareNum} 为历史模板兼容，固定输出 0。'}>
            <Space wrap>{(isTaskContent ? taskTemplateVariables : postTemplateVariables).map((item) => <Tooltip key={item.variable} title={item.source}><Button size="small" onClick={() => insertVariable(item.variable)}>{item.label} {item.variable}</Button></Tooltip>)}</Space>
          </Form.Item>
          <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', flexWrap: 'wrap', gap: 8, marginBottom: 8 }}>
            <Typography.Text strong>消息模板</Typography.Text>
            <Space size={4} wrap aria-label="消息模板预设">
              {templatePresets.map((preset) => <Button
                key={preset.key}
                size="small"
                type={watchedValues?.template_config?.template === preset.template ? 'primary' : 'default'}
                aria-pressed={watchedValues?.template_config?.template === preset.template}
                onClick={() => {
                  form.setFieldValue(['template_config', 'template'], preset.template)
                  templateRef.current?.focus()
                }}
              >{preset.label}</Button>)}
            </Space>
          </div>
          <Form.Item name={['template_config', 'template']} rules={[{ required: true, whitespace: true, max: 5000, message: '请输入不超过 5000 字的消息模板。' }]}>
            <Input.TextArea aria-label="消息模板" ref={templateRef} rows={9} maxLength={5000} showCount />
          </Form.Item>
          <Form.Item name={['template_config', 'footer']} label="结尾文案（只追加一次）" extra="所有帖子拼接完成后统一追加在消息末尾，适合放小尾巴、联系方式；留空则不追加。" rules={[{ max: 500, message: '结尾文案不超过 500 字。' }]}>
            <Input.TextArea rows={2} maxLength={500} showCount placeholder="例如：我是小尾巴" />
          </Form.Item>
          <Form.Item name={['template_config', 'keywords']} label="关键词过滤" extra="输入后回车，可添加多个；留空表示不按关键词过滤。">
            <Select mode="tags" open={false} tokenSeparators={[',', '，']} placeholder="例如：兼职、任务" />
          </Form.Item>
          <Form.Item name={['template_config', 'content_max_length']} label="正文截取长度（0 表示不截取）" rules={[{ required: true, message: '请设置正文截取长度。' }]}>
            <InputNumber min={0} max={5000} precision={0} style={{ width: 240 }} />
          </Form.Item>
          <Form.Item name={['template_config', 'link_format']} label="小程序短链形式" extra="URL Link 全场景可点（短信/网页/微信内外），微信内点击会先经过官方 H5 中间页；Short Link 仅微信内有效，群聊里点击直跳小程序页面，更短、不经过中间页。">
            <Select
              style={{ maxWidth: 360 }}
              options={XP_ROBOT_FORWARD_LINK_FORMAT_OPTIONS.map((item) => ({ value: item.value, label: item.label }))}
            />
          </Form.Item>
        </div>

        <div style={{ display: current === 3 ? undefined : 'none' }}>
          <Alert showIcon type="info" message="每条目标可绑定一个设备、一个微信账号和多个群。编辑时会保留名称快照；实时同步仅在手动确认后执行。" style={{ marginBottom: 16 }} />
          <Form.List name="robot_targets">
            {(fields, { add, remove }) => <Space direction="vertical" size={14} style={{ width: '100%' }}>
              {fields.map((field) => {
                const target = form.getFieldValue(['robot_targets', field.name]) as XpRobotForwardTarget | undefined
                const targetId = target?.target_id ?? String(field.key)
                const deviceId = target?.device_id ?? ''
                const wxid = target?.wxid ?? ''
                const loadingMode = groupLoading[targetId]
                return <Card
                  key={field.key}
                  size="small"
                  title={`机器人目标 ${field.name + 1}`}
                  extra={<Button type="text" danger icon={<DeleteOutlined />} aria-label={`删除目标 ${field.name + 1}`} disabled={fields.length <= 1} onClick={() => remove(field.name)} />}
                >
                  <Form.Item name={[field.name, 'target_id']} hidden><Input /></Form.Item>
                  <Form.Item name={[field.name, 'device_name']} hidden><Input /></Form.Item>
                  <Form.Item name={[field.name, 'account_name']} hidden><Input /></Form.Item>
                  <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(230px, 1fr))', gap: '0 14px' }}>
                    <Form.Item name={[field.name, 'device_id']} label="XP 设备" rules={[{ required: true, message: '请选择 XP 设备。' }]}>
                      <Select
                        showSearch
                        optionFilterProp="label"
                        loading={devicesLoading}
                        options={deviceOptions(target)}
                        placeholder="选择设备"
                        onChange={(value) => selectDevice(field.name, targetId, value)}
                      />
                    </Form.Item>
                    <Form.Item name={[field.name, 'wxid']} label="微信账号" rules={[{ required: true, message: '请选择微信账号。' }]}>
                      <Select
                        showSearch
                        optionFilterProp="label"
                        disabled={!deviceId}
                        loading={accountsLoading[targetId]}
                        options={accountOptions(target)}
                        placeholder="选择账号"
                        onOpenChange={(isOpen) => { if (isOpen && deviceId && !accountsByTarget[targetId]) void loadAccounts(targetId, deviceId) }}
                        onChange={(value) => selectAccount(field.name, targetId, value)}
                      />
                    </Form.Item>
                  </div>
                  <Form.Item
                    name={[field.name, 'groups']}
                    label="目标群（可多选）"
                    rules={[{ required: true, type: 'array', min: 1, message: '每个目标至少选择一个群。' }]}
                    getValueProps={(groups: XpRobotForwardGroup[] | undefined) => ({ value: (groups ?? []).map((group) => group.group_id) })}
                    getValueFromEvent={(groupIds: string[]) => {
                      const currentGroups = (form.getFieldValue(['robot_targets', field.name, 'groups']) ?? []) as XpRobotForwardGroup[]
                      return groupIds.map((groupId) => {
                        const snapshot = currentGroups.find((group) => group.group_id === groupId)
                        if (snapshot) return snapshot
                        const group = (groupsByTarget[targetId] ?? []).find((item) => item.group_id === groupId)
                        return { group_id: groupId, group_name: group ? robotGroupName(group) : groupId }
                      })
                    }}
                  >
                    <Select
                      mode="multiple"
                      showSearch
                      optionFilterProp="label"
                      disabled={!wxid || loadingMode !== undefined}
                      loading={loadingMode !== undefined}
                      options={groupOptions(target)}
                      placeholder="选择一个或多个群"
                      onOpenChange={(isOpen) => { if (isOpen && deviceId && wxid && !groupsByTarget[targetId]) void loadGroups(targetId, deviceId, wxid, false) }}
                    />
                  </Form.Item>
                  <Space wrap>
                    <Button size="small" icon={<ReloadOutlined />} disabled={!deviceId || !wxid} loading={loadingMode === 'cache'} onClick={() => void loadGroups(targetId, deviceId, wxid, false)}>刷新群缓存</Button>
                    <Popconfirm title="强制实时同步该目标群列表？" description="会向对应设备下发实时命令，仅在缓存不准确时使用。" okText="强制同步" cancelText="取消" onConfirm={() => void loadGroups(targetId, deviceId, wxid, true)}>
                      <Button size="small" disabled={!deviceId || !wxid} loading={loadingMode === 'realtime'}>强制实时同步</Button>
                    </Popconfirm>
                    <Button size="small" icon={<ReloadOutlined />} disabled={!deviceId} loading={accountsLoading[targetId]} onClick={() => void loadAccounts(targetId, deviceId)}>刷新账号</Button>
                  </Space>
                </Card>
              })}
              <Button type="dashed" block icon={<PlusOutlined />} disabled={fields.length >= 20} onClick={() => add(createTarget())}>添加机器人目标（{fields.length}/20）</Button>
            </Space>}
          </Form.List>
        </div>

        <div style={{ display: current === 4 ? undefined : 'none' }}>
          <Alert showIcon type="warning" message="请核对完整配置。保存仅管理本地规则，不表示外部数据已接入或自动转发已启动。" style={{ marginBottom: 16 }} />
          {watchedValues?.filter_rules && watchedValues.template_config && watchedValues.robot_targets
            ? <ForwardConfigSummary value={watchedValues} />
            : null}
        </div>
      </Form>
    </Modal>
  </>
}
