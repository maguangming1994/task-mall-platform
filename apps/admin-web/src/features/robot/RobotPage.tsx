import { ApiOutlined, ClockCircleOutlined, FileTextOutlined, KeyOutlined, LinkOutlined, PictureOutlined, ReloadOutlined, RobotOutlined, SendOutlined, StopOutlined, SyncOutlined, UploadOutlined } from '@ant-design/icons'
import { Alert, Button, Checkbox, DatePicker, Form, Image, Input, Modal, Popconfirm, Segmented, Select, Space, Table, Tabs, Tag, Tooltip, Typography, Upload, message } from 'antd'
import type { ColumnsType } from 'antd/es/table'
import type { UploadFile } from 'antd/es/upload/interface'
import { useCallback, useEffect, useRef, useState } from 'react'
import {
  ApiError,
  cancelXpRobotMessage,
  cancelXpRobotMessageBatch,
  getXpRobotSettings,
  listXpRobotAccounts,
  listXpRobotDevices,
  listXpRobotGroups,
  listXpRobotMessages,
  refreshXpRobotMessage,
  sendXpRobotMessage,
  sendXpRobotMessageBatch,
  testXpRobotSettings,
  updateXpRobotSettings,
  uploadMediaFile,
  verifyAdminRecent,
  type XpRobotAccount,
  type XpRobotDevice,
  type XpRobotGroup,
  type XpRobotMessage,
  type XpRobotMessageBatchItemInput,
  type XpRobotMsgType,
  type XpRobotSettings,
} from '../../api/admin'
import { PageHead, Pill } from '../../components/xq'
import { useAdminAuth } from '../auth/AdminAuthContext'
import { AssistantPanel } from './AssistantPanel'
import { ForwardConfigPanel } from './ForwardConfigPanel'
import { PostBotPanel } from './PostBotPanel'
import {
  formatRobotTime as fmtTime,
  robotAccountName as accountName,
  robotAccountStatusLabel as accountStatusLabel,
  robotAccountTypeLabel as accountTypeLabel,
  robotDeviceName as deviceName,
  robotGroupName as groupName,
  robotMessageStatus as statusOf,
} from './robotUtils'

interface ConfigFormValues {
  api_key?: string
  clear_api_key?: boolean
  reason: string
  current_password: string
  mfa_code?: string
}

const configDefaults: ConfigFormValues = {
  api_key: '',
  clear_api_key: false,
  reason: '',
  current_password: '',
  mfa_code: '',
}

const sourceLabels = { backend: '后台加密凭证', env: '环境变量', none: '未配置' }
const msgTypeLabels: Record<XpRobotMsgType, string> = { text: '文本', image: '图片', link: '链接卡片' }

interface LinkFields {
  link_url: string
  title: string
  desc: string
  image_url: string
}
const emptyLink: LinkFields = { link_url: '', title: '', desc: '', image_url: '' }

interface DraftItem {
  id: string
  msgType: XpRobotMsgType
  text: string
  imageUrl: string
  link: LinkFields
}

const createDraftItem = (msgType: XpRobotMsgType = 'text'): DraftItem => ({
  id: crypto.randomUUID(),
  msgType,
  text: '',
  imageUrl: '',
  link: { ...emptyLink },
})

interface MessageTableRow {
  key: string
  record: XpRobotMessage
  batchItems: XpRobotMessage[] | null
}

export function RobotPage() {
  const { admin } = useAdminAuth()
  const [messageApi, contextHolder] = message.useMessage()
  const [configForm] = Form.useForm<ConfigFormValues>()
  const canManageSettings = admin?.permissions.includes('robot.settings_manage') ?? false
  const canSend = admin?.permissions.includes('robot.message_send') ?? false

  const [settings, setSettings] = useState<XpRobotSettings | null>(null)
  const [settingsLoading, setSettingsLoading] = useState(false)
  const [configOpen, setConfigOpen] = useState(false)
  const [configSaving, setConfigSaving] = useState(false)
  const [testing, setTesting] = useState(false)

  const [devices, setDevices] = useState<XpRobotDevice[]>([])
  const [devicesLoading, setDevicesLoading] = useState(false)
  const [deviceId, setDeviceId] = useState('')
  const [accounts, setAccounts] = useState<XpRobotAccount[]>([])
  const [accountsLoading, setAccountsLoading] = useState(false)
  const [wxid, setWxid] = useState('')
  const [groups, setGroups] = useState<XpRobotGroup[]>([])
  const [groupLoadingMode, setGroupLoadingMode] = useState<'cache' | 'realtime' | null>(null)
  const [groupId, setGroupId] = useState('')
  const accountRequestRef = useRef(0)
  const groupRequestRef = useRef(0)

  const [draftItems, setDraftItems] = useState<DraftItem[]>(() => [createDraftItem()])
  const [sendTiming, setSendTiming] = useState<'now' | 'scheduled'>('now')
  const [scheduledAt, setScheduledAt] = useState<string | null>(null)
  const [composerResetKey, setComposerResetKey] = useState(0)
  const [uploadingImage, setUploadingImage] = useState(false)
  const [sending, setSending] = useState(false)
  const submissionRef = useRef<{ fingerprint: string; key: string } | null>(null)
  const [messages, setMessages] = useState<XpRobotMessage[]>([])
  const [messagesLoading, setMessagesLoading] = useState(false)
  const [refreshingId, setRefreshingId] = useState<string | null>(null)
  const [cancelingId, setCancelingId] = useState<string | null>(null)

  const loadSettings = useCallback(async (): Promise<XpRobotSettings | null> => {
    setSettingsLoading(true)
    try {
      const value = await getXpRobotSettings()
      setSettings(value)
      return value
    } catch (error) {
      messageApi.error(error instanceof ApiError ? error.message : 'XP 配置加载失败。')
      return null
    } finally {
      setSettingsLoading(false)
    }
  }, [messageApi])

  const loadMessages = useCallback(async () => {
    setMessagesLoading(true)
    try {
      const result = await listXpRobotMessages()
      setMessages(result.items)
    } catch (error) {
      messageApi.error(error instanceof ApiError ? error.message : '本地消息记录加载失败。')
    } finally {
      setMessagesLoading(false)
    }
  }, [messageApi])

  const loadDevices = useCallback(async () => {
    accountRequestRef.current += 1
    groupRequestRef.current += 1
    setDevicesLoading(true)
    setDeviceId('')
    setAccounts([])
    setWxid('')
    setGroups([])
    setGroupId('')
    setAccountsLoading(false)
    setGroupLoadingMode(null)
    try {
      const result = await listXpRobotDevices()
      setDevices(result.items)
    } catch (error) {
      setDevices([])
      messageApi.error(error instanceof ApiError ? error.message : 'XP 设备列表加载失败。')
    } finally {
      setDevicesLoading(false)
    }
  }, [messageApi])

  const loadPage = useCallback(async () => {
    const currentSettings = await loadSettings()
    await loadMessages()
    if (currentSettings?.configured) await loadDevices()
  }, [loadDevices, loadMessages, loadSettings])

  useEffect(() => { void loadPage() }, [loadPage])

  const selectDevice = async (value: string) => {
    const requestId = ++accountRequestRef.current
    groupRequestRef.current += 1
    setDeviceId(value)
    setWxid('')
    setAccounts([])
    setGroupId('')
    setGroups([])
    setGroupLoadingMode(null)
    if (!value) return
    setAccountsLoading(true)
    try {
      const result = await listXpRobotAccounts(value)
      if (requestId === accountRequestRef.current) setAccounts(result.items)
    } catch (error) {
      if (requestId === accountRequestRef.current) {
        messageApi.error(error instanceof ApiError ? error.message : '微信账号列表加载失败。')
      }
    } finally {
      if (requestId === accountRequestRef.current) setAccountsLoading(false)
    }
  }

  const loadGroups = async (targetDeviceId: string, targetWxid: string, forceRealtime: boolean) => {
    if (!targetDeviceId || !targetWxid) return
    const requestId = ++groupRequestRef.current
    setGroupLoadingMode(forceRealtime ? 'realtime' : 'cache')
    setGroupId('')
    try {
      const result = await listXpRobotGroups(targetDeviceId, targetWxid, forceRealtime)
      if (requestId !== groupRequestRef.current) return
      setGroups(result.items)
      if (forceRealtime) messageApi.success(`实时群同步完成，共 ${result.items.length} 个群。`)
    } catch (error) {
      if (requestId === groupRequestRef.current) {
        setGroups([])
        messageApi.error(error instanceof ApiError ? error.message : '群列表加载失败。')
      }
    } finally {
      if (requestId === groupRequestRef.current) setGroupLoadingMode(null)
    }
  }

  const selectAccount = (value: string) => {
    setWxid(value)
    setGroupId('')
    setGroups([])
    void loadGroups(deviceId, value, false)
  }

  const saveSettings = async (values: ConfigFormValues) => {
    const apiKey = values.api_key?.trim() ?? ''
    const clear = values.clear_api_key ?? false
    if (apiKey && clear) {
      messageApi.warning('不能同时输入新密钥并勾选清除。')
      return
    }
    if (!apiKey && !clear) {
      messageApi.warning('请输入新的 XP API Key，或明确选择清除后台密钥。')
      return
    }
    setConfigSaving(true)
    try {
      await verifyAdminRecent(values.current_password, values.mfa_code?.trim() || undefined)
      const updated = await updateXpRobotSettings({
        reason: values.reason.trim(),
        ...(clear ? { clear_api_key: true } : { api_key: apiKey }),
      })
      setSettings(updated)
      setConfigOpen(false)
      configForm.resetFields()
      messageApi.success('XP 配置已保存；密钥不会在接口或页面中回显。')
      if (updated.configured) {
        await loadDevices()
      } else {
        setDevices([])
        setDeviceId('')
        setAccounts([])
        setWxid('')
        setGroups([])
        setGroupId('')
      }
    } catch (error) {
      messageApi.error(error instanceof ApiError ? error.message : 'XP 配置保存失败。')
    } finally {
      setConfigSaving(false)
    }
  }

  const testConnection = async () => {
    const values = configForm.getFieldsValue()
    if (!settings?.configured) {
      messageApi.warning('请先保存 XP API Key，再测试连接。')
      return
    }
    if (!values.current_password?.trim()) {
      messageApi.warning('测试连接前，请填写当前管理员密码。')
      return
    }
    setTesting(true)
    try {
      await verifyAdminRecent(values.current_password, values.mfa_code?.trim() || undefined)
      const result = await testXpRobotSettings()
      setSettings((current) => current ? {
        ...current,
        last_tested_at: result.tested_at,
        last_test_status: 'success',
        last_test_message: result.message,
      } : current)
      messageApi.success(`XP 连接成功，共 ${result.device_count} 台设备，${result.online_count} 台在线。`)
    } catch (error) {
      messageApi.error(error instanceof ApiError ? error.message : 'XP 连接测试失败。')
      const latest = await getXpRobotSettings().catch(() => null)
      if (latest) setSettings(latest)
    } finally {
      setTesting(false)
    }
  }

  const uploadImage = async (file: File, onDone: (url: string) => void) => {
    setUploadingImage(true)
    try {
      const asset = await uploadMediaFile(file)
      onDone(asset.url)
      messageApi.success('图片已上传。')
    } catch (error) {
      messageApi.error(error instanceof ApiError ? error.message : '图片上传失败。')
    } finally {
      setUploadingImage(false)
    }
  }

  const patchDraftItem = (id: string, patch: Partial<DraftItem>) => {
    setDraftItems((current) => current.map((item) => item.id === id ? { ...item, ...patch } : item))
  }

  const moveDraftItem = (index: number, offset: -1 | 1) => {
    setDraftItems((current) => {
      const target = index + offset
      if (target < 0 || target >= current.length) return current
      const next = [...current]
      const [moved] = next.splice(index, 1)
      next.splice(target, 0, moved)
      return next
    })
  }

  const mergeSavedMessages = (savedItems: XpRobotMessage[]) => {
    const incoming = new Map(savedItems.map((item) => [item.id, item]))
    setMessages((current) => {
      const currentIds = new Set(current.map((item) => item.id))
      const additions = savedItems.filter((item) => !currentIds.has(item.id)).reverse()
      return [...additions, ...current.map((item) => incoming.get(item.id) ?? item)]
    })
  }

  const resetComposer = () => {
    setDraftItems((current) => [createDraftItem(current[0]?.msgType ?? 'text')])
    setSendTiming('now')
    setScheduledAt(null)
    setComposerResetKey((key) => key + 1)
  }

  const submitMessage = async () => {
    if (!deviceId || !wxid || !groupId) {
      messageApi.warning('请依次选择设备、微信账号和群。')
      return
    }
    const selectedGroup = groups.find((item) => item.group_id === groupId)
    const resolvedGroupName = selectedGroup ? (selectedGroup.name?.trim() || selectedGroup.nickname?.trim() || selectedGroup.group_name?.trim() || '') : ''
    const itemInputs: XpRobotMessageBatchItemInput[] = []

    for (const [index, draft] of draftItems.entries()) {
      const label = `第 ${index + 1} 条`
      if (draft.msgType === 'text') {
        if (!draft.text.trim()) { messageApi.warning(`请输入${label}的群文本。`); return }
        itemInputs.push({ msg_type: 'text', message: draft.text.trim() })
      } else if (draft.msgType === 'image') {
        if (!draft.imageUrl.trim()) { messageApi.warning(`请上传${label}的图片或填写图片 URL。`); return }
        if (!/^https?:\/\//i.test(draft.imageUrl.trim())) { messageApi.warning(`${label}图片必须是 http(s) 开头的公网 URL。`); return }
        itemInputs.push({ msg_type: 'image', message: draft.imageUrl.trim() })
      } else {
        if (!draft.link.link_url.trim()) { messageApi.warning(`请填写${label}链接卡片的跳转链接。`); return }
        if (!draft.link.title.trim()) { messageApi.warning(`请填写${label}链接卡片标题。`); return }
        if (!/^https?:\/\//i.test(draft.link.link_url.trim())) { messageApi.warning(`${label}跳转链接必须是 http(s) 开头的 URL。`); return }
        if (draft.link.image_url.trim() && !/^https?:\/\//i.test(draft.link.image_url.trim())) { messageApi.warning(`${label}封面图必须是 http(s) 开头的 URL。`); return }
        itemInputs.push({
          msg_type: 'link',
          link_url: draft.link.link_url.trim(),
          title: draft.link.title.trim(),
          ...(draft.link.desc.trim() ? { desc: draft.link.desc.trim() } : {}),
          ...(draft.link.image_url.trim() ? { image_url: draft.link.image_url.trim() } : {}),
        })
      }
    }

    if (sendTiming === 'scheduled') {
      if (!scheduledAt) { messageApi.warning('请选择定时发送时间。'); return }
      if (new Date(scheduledAt).getTime() <= Date.now()) { messageApi.warning('定时时间必须晚于当前时间。'); return }
    }

    const target = {
      device_id: deviceId,
      wxid,
      group_id: groupId,
      ...(resolvedGroupName ? { group_name: resolvedGroupName } : {}),
    }
    const scheduled_at = sendTiming === 'scheduled' && scheduledAt ? scheduledAt : null
    const fingerprint = JSON.stringify({ ...target, items: itemInputs, scheduled_at })
    if (submissionRef.current?.fingerprint !== fingerprint) {
      submissionRef.current = { fingerprint, key: crypto.randomUUID() }
    }
    const idempotencyKey = submissionRef.current.key
    setSending(true)
    try {
      const savedItems = itemInputs.length === 1
        ? [await sendXpRobotMessage({ ...target, ...itemInputs[0]!, scheduled_at }, idempotencyKey)]
        : (await sendXpRobotMessageBatch({ ...target, items: itemInputs, scheduled_at }, idempotencyKey)).items
      submissionRef.current = null
      mergeSavedMessages(savedItems)
      resetComposer()
      if (scheduled_at) {
        messageApi.success(`定时发送计划已保存，共 ${savedItems.length} 条；可在列表中到点前${savedItems.length > 1 ? '整组' : ''}取消。`)
      } else if (savedItems.length > 1) {
        if (savedItems.some((item) => ['SUBMIT_UNKNOWN', 'SKIPPED'].includes(item.status))) {
          messageApi.warning('组合出现提交结果未知，后续消息已停止发送并标记为已跳过；请勿直接重发。')
        } else if (savedItems.some((item) => ['SUBMIT_FAILED', 'FAILED', 'TIMEOUT', 'CANCELED', 'ORPHANED'].includes(item.status))) {
          messageApi.error('组合中存在未成功投递的消息，请展开记录查看各子消息状态。')
        } else if (savedItems.every((item) => item.status === 'SUCCESS')) {
          messageApi.success('组合消息均已确认送达。')
        } else {
          messageApi.info(`组合共 ${savedItems.length} 条，已按顺序提交 XP；请展开记录刷新各子消息状态。`)
        }
      } else {
        const saved = savedItems[0]!
        if (saved.error_code === 'XP_RESPONSE_INCOMPLETE' || saved.status === 'SUBMIT_UNKNOWN') {
          messageApi.warning('提交结果不确定，请勿直接重发；有 cmd_id 时可刷新投递状态。')
        } else if (saved.status === 'SUCCESS') {
          messageApi.success('XP 已确认消息送达。')
        } else if (saved.status === 'ACCEPTED') {
          messageApi.warning('设备已受理，但尚未确认最终可见，请稍后刷新状态。')
        } else if (saved.status === 'QUEUED') {
          messageApi.info('消息已入队，等待设备执行；入队不代表最终送达。')
        } else if (saved.status === 'PROCESSING') {
          messageApi.info('消息正在由设备处理，请稍后刷新状态。')
        } else if (saved.status === 'SUBMIT_FAILED' || ['FAILED', 'TIMEOUT', 'CANCELED', 'ORPHANED'].includes(saved.status)) {
          messageApi.error(`消息未成功投递：${statusOf(saved.status).label}。`)
        } else {
          messageApi.info(`本地请求已保存，当前状态：${statusOf(saved.status).label}。`)
        }
      }
    } catch (error) {
      messageApi.error(error instanceof ApiError ? error.message : '网络请求未完成；使用相同内容重试会复用同一幂等键。')
    } finally {
      setSending(false)
    }
  }

  const cancelMessage = async (record: XpRobotMessage) => {
    setCancelingId(record.id)
    try {
      const updated = await cancelXpRobotMessage(record.id)
      setMessages((current) => current.map((item) => item.id === updated.id ? updated : item))
      messageApi.success('定时发送计划已取消。')
    } catch (error) {
      messageApi.error(error instanceof ApiError ? error.message : '取消定时计划失败。')
    } finally {
      setCancelingId(null)
    }
  }

  const cancelBatch = async (row: MessageTableRow) => {
    const batchId = row.batchItems?.[0]?.batch_id
    if (!batchId) return
    setCancelingId(row.key)
    try {
      const updated = await cancelXpRobotMessageBatch(batchId)
      mergeSavedMessages(updated.items)
      messageApi.success(`组合定时发送计划已整组取消，共 ${updated.items.length} 条。`)
    } catch (error) {
      messageApi.error(error instanceof ApiError ? error.message : '取消组合定时计划失败。')
    } finally {
      setCancelingId(null)
    }
  }

  const refreshMessage = async (record: XpRobotMessage) => {
    setRefreshingId(record.id)
    try {
      const updated = await refreshXpRobotMessage(record.id)
      setMessages((current) => current.map((item) => item.id === updated.id ? updated : item))
      if (updated.error_code === 'XP_STATUS_SYNC_FAILED') {
        messageApi.warning('XP 状态同步失败，本地已保留原投递状态。')
      } else if (updated.status === 'SUCCESS') {
        messageApi.success('XP 已确认消息送达。')
      } else {
        messageApi.info(`状态已同步：${statusOf(updated.status).label}。`)
      }
    } catch (error) {
      messageApi.error(error instanceof ApiError ? error.message : '消息状态刷新失败。')
    } finally {
      setRefreshingId(null)
    }
  }

  const renderContent = (record: XpRobotMessage) => {
    if (record.msg_type === 'image') {
      return <div>
        {record.message ? <Image src={record.message} alt="图片消息" width={64} height={64} style={{ objectFit: 'cover', borderRadius: 6 }} /> : <Typography.Text type="secondary">无图片</Typography.Text>}
        {record.message ? <Typography.Text type="secondary" copyable={{ text: record.message, tooltips: ['复制图片 URL', '已复制'] }} style={{ display: 'block', marginTop: 4, fontSize: 12, maxWidth: 240 }} ellipsis>{record.message}</Typography.Text> : null}
      </div>
    }
    if (record.msg_type === 'link') {
      const payload = record.payload ?? {}
      return <div style={{ maxWidth: 260 }}>
        <Typography.Text strong>{payload.title || '链接卡片'}</Typography.Text>
        {payload.link_url ? <Typography.Text type="secondary" copyable={{ text: payload.link_url, tooltips: ['复制链接', '已复制'] }} style={{ display: 'block', marginTop: 4, fontSize: 12 }} ellipsis>{payload.link_url}</Typography.Text> : null}
        {payload.desc ? <Typography.Text type="secondary" style={{ display: 'block', marginTop: 4, fontSize: 12 }} ellipsis>{payload.desc}</Typography.Text> : null}
      </div>
    }
    return <Typography.Text ellipsis={{ tooltip: record.message ?? '' }} style={{ maxWidth: 260 }}>{record.message ?? '—'}</Typography.Text>
  }

  const messageRows: MessageTableRow[] = []
  const batchRows = new Map<string, MessageTableRow>()
  messages.forEach((record) => {
    if (record.batch_id) {
      let row = batchRows.get(record.batch_id)
      if (!row) {
        row = { key: `batch-${record.batch_id}`, record, batchItems: [] }
        batchRows.set(record.batch_id, row)
        messageRows.push(row)
      }
      row.batchItems?.push(record)
    } else {
      messageRows.push({ key: record.id, record, batchItems: null })
    }
  })
  messageRows.forEach((row) => row.batchItems?.sort((a, b) => (a.batch_sequence ?? 0) - (b.batch_sequence ?? 0)))

  const contentSummary = (record: XpRobotMessage) => {
    if (record.msg_type === 'image') return `图片：${record.message ?? '—'}`
    if (record.msg_type === 'link') return `链接：${record.payload?.title || record.payload?.link_url || '链接卡片'}`
    return record.message ?? '—'
  }

  const renderRecordStatus = (record: XpRobotMessage) => {
    const status = statusOf(record.status)
    return <div>
      <Pill tone={status.tone}>{status.label}</Pill>
      {record.provider_status && record.provider_status !== record.status ? <Typography.Text type="secondary" style={{ display: 'block', marginTop: 5, fontSize: 12 }}>XP 状态：{statusOf(record.provider_status).label}</Typography.Text> : null}
      {record.error_message ? <Typography.Text type={record.status === 'SUBMIT_UNKNOWN' || record.error_code === 'XP_STATUS_SYNC_FAILED' ? 'warning' : 'danger'} style={{ display: 'block', marginTop: 5, fontSize: 12, whiteSpace: 'normal' }}>{record.error_message}</Typography.Text> : null}
    </div>
  }

  const renderCmdId = (record: XpRobotMessage) => record.cmd_id
    ? <Typography.Text copyable={{ text: record.cmd_id, tooltips: ['复制指令 ID', '已复制'] }} style={{ fontSize: 12 }}>{record.cmd_id.slice(0, 12)}…</Typography.Text>
    : <Typography.Text type="secondary">未返回</Typography.Text>

  const renderRefresh = (record: XpRobotMessage) => <Tooltip title={record.cmd_id ? '按指令 ID 查询 XP 最新状态' : '缺少指令 ID，无法查询 XP 状态'}>
    <Button type="link" size="small" icon={<SyncOutlined />} disabled={!record.cmd_id} loading={refreshingId === record.id} onClick={() => void refreshMessage(record)}>刷新</Button>
  </Tooltip>

  const renderRecordTime = (record: XpRobotMessage) => <div style={{ fontSize: 12 }}>
    {record.status === 'SCHEDULED'
      ? <div>定时：<Typography.Text strong>{fmtTime(record.scheduled_at)}</Typography.Text></div>
      : <div>提交：{fmtTime(record.submitted_at ?? record.created_at)}</div>}
    <Typography.Text type="secondary">派发：{fmtTime(record.dispatched_at)}</Typography.Text>
    {record.canceled_at ? <Typography.Text type="secondary">取消：{fmtTime(record.canceled_at)}</Typography.Text> : null}
  </div>

  const columns: ColumnsType<MessageTableRow> = [
    {
      title: '发送目标',
      width: 240,
      render: (_, row) => {
        const record = row.record
        return <div>
          <Typography.Text strong copyable={record.group_name ? false : { text: record.group_id, tooltips: ['复制群 ID', '已复制'] }} style={{ wordBreak: 'break-all' }}>{record.group_name || record.group_id}</Typography.Text>
          {record.group_name ? <Typography.Text type="secondary" copyable={{ text: record.group_id, tooltips: ['复制群 ID', '已复制'] }} style={{ display: 'block', marginTop: 4, fontSize: 12 }}>群 ID：{record.group_id}</Typography.Text> : null}
          <Typography.Text type="secondary" style={{ display: 'block', marginTop: 4, fontSize: 12 }}>账号：{record.wxid}</Typography.Text>
        </div>
      },
    },
    {
      title: '类型 / 来源',
      width: 120,
      render: (_, row) => <Space direction="vertical" size={4}>
        {row.batchItems ? <Tag color="processing">组合 · {row.batchItems.length} 条</Tag> : <Tag>{msgTypeLabels[row.record.msg_type] ?? row.record.msg_type}</Tag>}
        <Tag color={row.record.trigger_type === 'CONFIG_TEST' ? 'processing' : row.record.trigger_type === 'AUTO_FORWARD' ? 'cyan' : 'default'}>
          {row.record.trigger_type === 'CONFIG_TEST' ? '配置测试' : row.record.trigger_type === 'AUTO_FORWARD' ? '自动转发' : '手动发送'}
        </Tag>
      </Space>,
    },
    {
      title: '消息内容 / 发送顺序',
      width: 280,
      render: (_, row) => row.batchItems ? <div>
        {row.batchItems.map((item) => <Typography.Text key={item.id} ellipsis={{ tooltip: contentSummary(item) }} style={{ display: 'block', maxWidth: 260, fontSize: 12, marginBottom: 4 }}>
          {item.batch_sequence}. {msgTypeLabels[item.msg_type]} · {contentSummary(item)}
        </Typography.Text>)}
      </div> : renderContent(row.record),
    },
    {
      title: '本地 / XP 状态',
      width: 230,
      render: (_, row) => {
        if (!row.batchItems) return renderRecordStatus(row.record)
        const counts = row.batchItems.reduce<Record<string, number>>((result, item) => {
          result[item.status] = (result[item.status] ?? 0) + 1
          return result
        }, {})
        return <Space wrap size={[4, 6]}>{Object.entries(counts).map(([value, count]) => {
          const status = statusOf(value)
          return <Pill key={value} tone={status.tone}>{status.label} × {count}</Pill>
        })}</Space>
      },
    },
    {
      title: '指令 ID',
      width: 160,
      render: (_, row) => row.batchItems
        ? <Typography.Text type="secondary">已返回 {row.batchItems.filter((item) => item.cmd_id).length}/{row.batchItems.length} 条</Typography.Text>
        : renderCmdId(row.record),
    },
    {
      title: '时间',
      width: 200,
      render: (_, row) => renderRecordTime(row.record),
    },
    {
      title: '操作',
      fixed: 'right',
      width: 140,
      render: (_, row) => {
        if (row.batchItems) {
          return row.batchItems.every((item) => item.status === 'SCHEDULED')
            ? <Popconfirm title="整组取消该定时发送计划？" description={`将同时取消组内 ${row.batchItems.length} 条消息，取消后不会再派发。`} okText="整组取消" cancelText="保留" onConfirm={() => void cancelBatch(row)}>
                <Button type="link" size="small" danger icon={<StopOutlined />} loading={cancelingId === row.key}>整组取消</Button>
              </Popconfirm>
            : <Typography.Text type="secondary" style={{ fontSize: 12 }}>展开查看子消息</Typography.Text>
        }
        const record = row.record
        return record.status === 'SCHEDULED'
          ? <Popconfirm title="取消该定时发送计划？" description="到点前可取消，取消后不会再派发。" okText="取消计划" cancelText="保留" onConfirm={() => void cancelMessage(record)}>
              <Button type="link" size="small" danger icon={<StopOutlined />} loading={cancelingId === record.id}>取消</Button>
            </Popconfirm>
          : renderRefresh(record)
      },
    },
  ]

  const batchItemColumns: ColumnsType<XpRobotMessage> = [
    { title: '顺序', width: 64, render: (_, record) => <Tag>第 {record.batch_sequence} 条</Tag> },
    { title: '类型', width: 90, render: (_, record) => msgTypeLabels[record.msg_type] },
    { title: '内容', width: 260, render: (_, record) => renderContent(record) },
    { title: '状态', width: 220, render: (_, record) => renderRecordStatus(record) },
    { title: '指令 ID', width: 160, render: (_, record) => renderCmdId(record) },
    { title: '时间', width: 190, render: (_, record) => renderRecordTime(record) },
    { title: '操作', width: 90, render: (_, record) => renderRefresh(record) },
  ]

  const submitLabel = sendTiming === 'scheduled' ? '保存定时计划' : '立即发送'

  return <div className="xq-page">
    {contextHolder}
    <PageHead title="XP 机器人" extra={<Tooltip title={canManageSettings ? '' : '需要 robot.settings_manage 权限'}>
      <span><Button type="primary" icon={<KeyOutlined />} disabled={!canManageSettings} onClick={() => { configForm.setFieldsValue(configDefaults); setConfigOpen(true) }}>配置 API Key</Button></span>
    </Tooltip>} />

    <section className="xq-card" aria-label="XP 服务配置">
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', gap: 16, flexWrap: 'wrap' }}>
        <div>
          <p className="xq-card-title"><RobotOutlined /> XP 服务配置</p>
          <Space wrap>
            <Tag color={settings?.configured ? 'success' : 'default'}>{settings?.configured ? '已配置' : '未配置'}</Tag>
            {settings ? <Tag>{sourceLabels[settings.source]}</Tag> : null}
            {settings?.masked ? <Typography.Text code>{settings.masked}</Typography.Text> : null}
            {settings?.last_test_status ? <Tag color={settings.last_test_status === 'success' ? 'success' : 'error'}>{settings.last_test_status === 'success' ? '最近测试通过' : '最近测试失败'}</Tag> : <Tag>尚未测试</Tag>}
          </Space>
        </div>
        <Button icon={<ReloadOutlined />} loading={settingsLoading} onClick={() => void loadSettings()}>刷新配置</Button>
      </div>
      <Typography.Paragraph type="secondary" style={{ margin: '12px 0 0' }}>
        API Key 仅由 Laravel 后端持有并加密存储，页面只显示脱敏标识。服务地址：{settings?.base_url ?? '加载中'}；最近测试：{fmtTime(settings?.last_tested_at ?? null)}。
      </Typography.Paragraph>
      {settings?.last_test_message ? <Typography.Text type={settings.last_test_status === 'failed' ? 'danger' : 'secondary'}>{settings.last_test_message}</Typography.Text> : null}
      {settings && !settings.configured ? <Alert showIcon type="warning" message="请先配置 XP API Key，再查询设备和发送消息。" style={{ marginTop: 14 }} /> : null}
    </section>

    <Tabs
      style={{ marginTop: 16 }}
      items={[
        {
          key: 'forwards',
          label: '转发配置',
          children: <ForwardConfigPanel
            configured={settings?.configured ?? false}
            canManage={canManageSettings}
            canSend={canSend}
            requiresMfa={admin?.requires_mfa ?? false}
            onMessageSaved={(saved) => setMessages((current) => [saved, ...current.filter((item) => item.id !== saved.id)])}
          />,
        },
        {
          key: 'assistants',
          label: '助手配置',
          children: <AssistantPanel configured={settings?.configured ?? false} canManage={canManageSettings} requiresMfa={admin?.requires_mfa ?? false} />,
        },
        {
          key: 'post-bots',
          label: '发帖机器人',
          children: <PostBotPanel canManage={canManageSettings} requiresMfa={admin?.requires_mfa ?? false} />,
        },
        {
          key: 'manual',
          label: '手动发送',
          children: <>
    <section className="xq-card" aria-label="选择发送目标" style={{ marginTop: 16 }}>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: 12, flexWrap: 'wrap' }}>
        <p className="xq-card-title" style={{ margin: 0 }}>1. 选择发送目标</p>
        <Space wrap>
          <Typography.Text type="secondary">设备 {devices.length} 台 · 在线 {devices.filter((item) => item.online).length} 台</Typography.Text>
          <Button size="small" icon={<ReloadOutlined />} disabled={!settings?.configured} loading={devicesLoading} onClick={() => void loadDevices()}>刷新设备</Button>
        </Space>
      </div>
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(230px, 1fr))', gap: 16, marginTop: 18 }}>
        <div>
          <Typography.Text strong>设备</Typography.Text>
          <Select
            aria-label="选择 XP 设备"
            style={{ width: '100%', marginTop: 8 }}
            placeholder="选择设备"
            value={deviceId || undefined}
            disabled={!settings?.configured}
            loading={devicesLoading}
            showSearch
            optionFilterProp="label"
            onChange={(value) => void selectDevice(value)}
            options={devices.map((item) => ({ value: item.device_id, label: `${deviceName(item)} · ${item.online ? '在线' : '离线'}${item.enabled === false ? ' · 已停用' : ''}` }))}
            notFoundContent={devicesLoading ? '加载中…' : '暂无设备'}
          />
          {deviceId ? <Typography.Text type="secondary" copyable={{ text: deviceId, tooltips: ['复制设备 ID', '已复制'] }} style={{ display: 'block', marginTop: 6, fontSize: 12 }}>设备 ID：{deviceId}</Typography.Text> : null}
        </div>
        <div>
          <Typography.Text strong>微信账号</Typography.Text>
          <Select
            aria-label="选择微信账号"
            style={{ width: '100%', marginTop: 8 }}
            placeholder="选择账号"
            value={wxid || undefined}
            disabled={!deviceId}
            loading={accountsLoading}
            showSearch
            optionFilterProp="label"
            onChange={selectAccount}
            options={accounts.map((item) => ({ value: item.wxid, label: `${accountName(item)} · ${accountTypeLabel(item.account_type)}${item.status ? ` · ${accountStatusLabel(item.status)}` : ''}` }))}
            notFoundContent={accountsLoading ? '加载中…' : '暂无账号'}
          />
          {wxid ? <Typography.Text type="secondary" copyable={{ text: wxid, tooltips: ['复制微信 ID', '已复制'] }} style={{ display: 'block', marginTop: 6, fontSize: 12 }}>微信 ID：{wxid}</Typography.Text> : null}
        </div>
        <div>
          <Typography.Text strong>群聊</Typography.Text>
          <Select
            aria-label="选择群聊"
            style={{ width: '100%', marginTop: 8 }}
            placeholder="选择群聊"
            value={groupId || undefined}
            disabled={!wxid || groupLoadingMode !== null}
            loading={groupLoadingMode !== null}
            showSearch
            optionFilterProp="label"
            onChange={setGroupId}
            options={groups.map((item) => ({ value: item.group_id, label: `${groupName(item)}${item.member_count ? ` · ${item.member_count} 人` : ''}` }))}
            notFoundContent={groupLoadingMode ? '同步中…' : '暂无群聊'}
          />
          {groupId ? <Typography.Text type="secondary" copyable={{ text: groupId, tooltips: ['复制群 ID', '已复制'] }} style={{ display: 'block', marginTop: 6, fontSize: 12 }}>群 ID：{groupId}</Typography.Text> : null}
        </div>
      </div>
      <Space wrap style={{ marginTop: 16 }}>
        <Button icon={<ReloadOutlined />} disabled={!deviceId || !wxid} loading={groupLoadingMode === 'cache'} onClick={() => void loadGroups(deviceId, wxid, false)}>刷新群缓存</Button>
        <Popconfirm title="强制实时同步群列表？" description="这会向设备下发实时命令并增加设备压力，仅在缓存不准确时使用。" okText="强制同步" cancelText="取消" onConfirm={() => void loadGroups(deviceId, wxid, true)}>
          <Button disabled={!deviceId || !wxid} loading={groupLoadingMode === 'realtime'}>强制实时同步</Button>
        </Popconfirm>
        <Typography.Text type="secondary">选择账号时默认读取群缓存，不自动强制实时同步。</Typography.Text>
      </Space>
    </section>

    <section className="xq-card" aria-label="发送消息" style={{ marginTop: 16 }}>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: 12, flexWrap: 'wrap' }}>
        <p className="xq-card-title" style={{ margin: 0 }}>2. 发送消息 <Typography.Text type="secondary" style={{ fontWeight: 400 }}>{draftItems.length}/10 条</Typography.Text></p>
        <Button disabled={!canSend || !settings?.configured || draftItems.length >= 10} onClick={() => setDraftItems((current) => [...current, createDraftItem()])}>添加一条</Button>
      </div>
      {!canSend ? <Alert showIcon type="warning" message="当前管理员缺少 robot.message_send 权限，只能查看配置与消息记录。" style={{ margin: '14px 0' }} /> : null}
      <Alert showIcon type="info" message="1 条按原单条方式发送；2–10 条按列表顺序作为组合整组发送与管理。图片通过平台媒体存储获取公网 URL；链接卡片与图片需刷新确认最终可见。定时发送由调度器每分钟扫描并派发。" style={{ margin: '14px 0' }} />

      {draftItems.map((draft, index) => <div key={draft.id} style={{ border: '1px solid #f0f0f0', borderRadius: 8, padding: 14, marginBottom: 12, background: '#fafafa' }}>
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: 12, flexWrap: 'wrap', marginBottom: 14 }}>
          <Space wrap>
            <Tag color={draftItems.length > 1 ? 'processing' : 'default'}>第 {index + 1} 条</Tag>
            <Segmented
              value={draft.msgType}
              disabled={!canSend || !settings?.configured}
              onChange={(value) => patchDraftItem(draft.id, { msgType: value as XpRobotMsgType })}
              options={[
                { label: <span><FileTextOutlined /> 文本</span>, value: 'text' },
                { label: <span><PictureOutlined /> 图片</span>, value: 'image' },
                { label: <span><LinkOutlined /> 链接卡片</span>, value: 'link' },
                { label: <Tooltip title="XP 当前不支持微信名片发送">微信名片</Tooltip>, value: 'namecard', disabled: true },
                { label: <Tooltip title="XP 当前不支持表情包发送">表情包</Tooltip>, value: 'emoji', disabled: true },
              ]}
            />
          </Space>
          <Space wrap>
            <Button size="small" disabled={index === 0} onClick={() => moveDraftItem(index, -1)}>上移</Button>
            <Button size="small" disabled={index === draftItems.length - 1} onClick={() => moveDraftItem(index, 1)}>下移</Button>
            <Button size="small" danger type="link" disabled={draftItems.length === 1} onClick={() => setDraftItems((current) => current.filter((item) => item.id !== draft.id))}>删除</Button>
          </Space>
        </div>

        {draft.msgType === 'text' ? (
          <Input.TextArea
            aria-label={`第 ${index + 1} 条群文本内容`}
            rows={4}
            value={draft.text}
            maxLength={5000}
            showCount
            disabled={!canSend || !settings?.configured}
            placeholder="输入要发送到所选群的文本内容"
            onChange={(event) => patchDraftItem(draft.id, { text: event.target.value })}
          />
        ) : draft.msgType === 'image' ? (
          <Space direction="vertical" style={{ width: '100%' }} size={10}>
            <Space wrap>
              <Upload
                accept="image/png,image/jpeg,image/webp"
                showUploadList={false}
                disabled={!canSend || !settings?.configured || uploadingImage}
                beforeUpload={(file: UploadFile) => { void uploadImage(file as unknown as File, (url) => patchDraftItem(draft.id, { imageUrl: url })); return false }}
              >
                <Button icon={<UploadOutlined />} loading={uploadingImage} disabled={!canSend || !settings?.configured}>上传图片</Button>
              </Upload>
              <Typography.Text type="secondary">或粘贴公网图片 URL（http/https，XP 会拉取该地址）</Typography.Text>
            </Space>
            <Input
              aria-label={`第 ${index + 1} 条图片 URL`}
              value={draft.imageUrl}
              disabled={!canSend || !settings?.configured}
              placeholder="https://example.com/image.jpg"
              onChange={(event) => patchDraftItem(draft.id, { imageUrl: event.target.value })}
            />
            {draft.imageUrl ? <Image src={draft.imageUrl} alt={`第 ${index + 1} 条图片预览`} width={140} style={{ borderRadius: 6 }} /> : null}
          </Space>
        ) : (
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(260px, 1fr))', gap: 12 }}>
            <div>
              <Typography.Text strong>跳转链接 *</Typography.Text>
              <Input aria-label={`第 ${index + 1} 条链接卡片跳转链接`} style={{ marginTop: 8 }} value={draft.link.link_url} disabled={!canSend || !settings?.configured} placeholder="https://example.com/page" onChange={(event) => patchDraftItem(draft.id, { link: { ...draft.link, link_url: event.target.value } })} />
            </div>
            <div>
              <Typography.Text strong>标题 *</Typography.Text>
              <Input aria-label={`第 ${index + 1} 条链接卡片标题`} style={{ marginTop: 8 }} maxLength={200} value={draft.link.title} disabled={!canSend || !settings?.configured} placeholder="卡片标题" onChange={(event) => patchDraftItem(draft.id, { link: { ...draft.link, title: event.target.value } })} />
            </div>
            <div>
              <Typography.Text strong>描述（可选）</Typography.Text>
              <Input aria-label={`第 ${index + 1} 条链接卡片描述`} style={{ marginTop: 8 }} maxLength={500} value={draft.link.desc} disabled={!canSend || !settings?.configured} placeholder="卡片描述" onChange={(event) => patchDraftItem(draft.id, { link: { ...draft.link, desc: event.target.value } })} />
            </div>
            <div>
              <Typography.Text strong>封面图 URL（可选）</Typography.Text>
              <div style={{ display: 'flex', gap: 8, marginTop: 8 }}>
                <Input aria-label={`第 ${index + 1} 条链接卡片封面图 URL`} value={draft.link.image_url} disabled={!canSend || !settings?.configured} placeholder="https://example.com/cover.jpg" onChange={(event) => patchDraftItem(draft.id, { link: { ...draft.link, image_url: event.target.value } })} />
                <Upload
                  accept="image/png,image/jpeg,image/webp"
                  showUploadList={false}
                  disabled={!canSend || !settings?.configured || uploadingImage}
                  beforeUpload={(file: UploadFile) => { void uploadImage(file as unknown as File, (url) => patchDraftItem(draft.id, { link: { ...draft.link, image_url: url } })); return false }}
                >
                  <Button icon={<UploadOutlined />} loading={uploadingImage} disabled={!canSend || !settings?.configured}>上传</Button>
                </Upload>
              </div>
            </div>
          </div>
        )}
      </div>)}

      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: 12, marginTop: 16, flexWrap: 'wrap' }}>
        <Space wrap>
          <Segmented
            value={sendTiming}
            onChange={(value) => setSendTiming(value as 'now' | 'scheduled')}
            options={[
              { label: <span><SendOutlined /> 立即发送</span>, value: 'now' },
              { label: <span><ClockCircleOutlined /> 定时发送</span>, value: 'scheduled' },
            ]}
          />
          {sendTiming === 'scheduled' ? (
            <DatePicker
              key={composerResetKey}
              showTime
              aria-label="定时发送时间"
              disabled={!canSend || !settings?.configured}
              disabledDate={(current) => !!current && current.endOf('day').valueOf() < Date.now()}
              onChange={(value) => setScheduledAt(value ? value.toISOString() : null)}
              placeholder="选择发送时间"
              style={{ width: 220 }}
            />
          ) : null}
          {sendTiming === 'scheduled' ? <Typography.Text type="secondary">到点自动派发；未到点前可在列表{draftItems.length > 1 ? '整组' : ''}取消。</Typography.Text> : null}
        </Space>
        <Button type="primary" icon={<SendOutlined />} loading={sending} disabled={!canSend || !settings?.configured} onClick={() => void submitMessage()}>{submitLabel}</Button>
      </div>
      <Typography.Text type="secondary" style={{ display: 'block', marginTop: 10 }}>完整有序内容使用稳定幂等键；网络失败后用相同内容重试不会重复请求 XP。组合中某条失败或结果未知时，后续消息不会继续提交。</Typography.Text>
    </section>

    <section className="xq-card xq-table" aria-label="本地消息记录" style={{ marginTop: 16 }}>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: 12, marginBottom: 12 }}>
        <p className="xq-card-title" style={{ margin: 0 }}>本地消息记录 <Typography.Text type="secondary" style={{ fontWeight: 400 }}>最近 100 条 · 组合整组补齐</Typography.Text></p>
        <Button icon={<ReloadOutlined />} loading={messagesLoading} onClick={() => void loadMessages()}>刷新列表</Button>
      </div>
      <Table<MessageTableRow>
        rowKey="key"
        size="middle"
        loading={messagesLoading}
        columns={columns}
        dataSource={messageRows}
        scroll={{ x: 1280 }}
        expandable={{
          rowExpandable: (row) => row.batchItems !== null,
          expandedRowRender: (row) => row.batchItems ? <div style={{ padding: '4px 0 8px' }}>
            <Typography.Text strong style={{ display: 'block', marginBottom: 10 }}>组合内按以下顺序逐条发送，每条保留独立 XP 状态</Typography.Text>
            <Table<XpRobotMessage>
              rowKey="id"
              size="small"
              columns={batchItemColumns}
              dataSource={row.batchItems}
              pagination={false}
              scroll={{ x: 1100 }}
            />
          </div> : null,
        }}
        pagination={{ pageSize: 10, showTotal: (total) => `共 ${total} 条记录（组合按 1 条展示）` }}
        locale={{ emptyText: '暂无本地消息请求' }}
      />
    </section>
          </>,
        },
      ]}
    />

    <Modal
      title="配置 XP API Key"
      open={configOpen}
      width={560}
      onCancel={() => { if (!configSaving && !testing) { setConfigOpen(false); configForm.resetFields() } }}
      onOk={() => configForm.submit()}
      okText="保存配置"
      cancelText="取消"
      confirmLoading={configSaving}
      cancelButtonProps={{ disabled: configSaving || testing }}
      maskClosable={!configSaving && !testing}
    >
      <Alert showIcon type="warning" message="密钥安全" description="密钥只提交本次输入，服务端不会回传明文。环境变量密钥不能在后台清除，但可通过后台凭证覆盖。修改和测试都需要再次验证管理员身份。" style={{ marginBottom: 18 }} />
      <Form<ConfigFormValues> form={configForm} layout="vertical" initialValues={configDefaults} onFinish={(values) => void saveSettings(values)}>
        <Form.Item name="api_key" label={<Space>XP API Key{settings?.masked ? <Tag>{settings.masked}</Tag> : null}</Space>} extra="留空不会保留原值；如需保留现有配置，请直接取消。测试连接使用已保存配置。">
          <Input.Password autoComplete="new-password" maxLength={500} placeholder="输入新的 XP API Key" />
        </Form.Item>
        {settings?.source === 'backend' ? <Form.Item name="clear_api_key" valuePropName="checked"><Checkbox>清除后台已保存密钥（如存在环境变量，将回退到环境变量）</Checkbox></Form.Item> : null}
        <Form.Item name="reason" label="修改原因" rules={[{ required: true, min: 4, max: 500, message: '请填写 4–500 字的修改原因。' }]}>
          <Input.TextArea rows={2} showCount maxLength={500} placeholder="例如：配置 XP 机器人首个群文本发送闭环" />
        </Form.Item>
        <Form.Item name="current_password" label="当前管理员密码" rules={[{ required: true, message: '请输入当前管理员密码。' }]}>
          <Input.Password autoComplete="current-password" />
        </Form.Item>
        <Form.Item name="mfa_code" label="MFA 验证码（已开启时必填）">
          <Input inputMode="numeric" maxLength={6} autoComplete="one-time-code" />
        </Form.Item>
        <Button icon={<ApiOutlined />} loading={testing} disabled={!settings?.configured || configSaving} onClick={() => void testConnection()}>测试已保存配置</Button>
      </Form>
    </Modal>
  </div>
}
