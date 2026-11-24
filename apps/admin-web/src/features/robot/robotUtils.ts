import type { XpRobotAccount, XpRobotDevice, XpRobotGroup } from '../../api/admin'
import type { PillTone } from '../../components/xq'

export const robotMessageStatusLabels: Record<string, { label: string; tone: PillTone }> = {
  SCHEDULED: { label: '待发送（定时）', tone: 'blue' },
  SUBMITTING: { label: '提交中', tone: 'blue' },
  DISPATCHING: { label: '派发中', tone: 'blue' },
  SKIPPED: { label: '已跳过', tone: 'gray' },
  SUBMIT_UNKNOWN: { label: '提交结果未知', tone: 'orange' },
  SUBMIT_FAILED: { label: '提交失败', tone: 'red' },
  QUEUED: { label: '已入队（非终态）', tone: 'blue' },
  PROCESSING: { label: '设备处理中', tone: 'blue' },
  SUCCESS: { label: '已确认送达', tone: 'green' },
  ACCEPTED: { label: '已受理（未确认）', tone: 'orange' },
  FAILED: { label: '投递失败', tone: 'red' },
  TIMEOUT: { label: '投递超时', tone: 'red' },
  CANCELED: { label: '已取消', tone: 'gray' },
  ORPHANED: { label: '孤立请求', tone: 'red' },
}

const accountTypeLabels: Record<string, string> = { ENTERPRISE: '企业微信', PERSONAL: '个人微信', WECHAT: '个人微信' }
const accountStatusLabels: Record<string, string> = { ONLINE: '在线', OFFLINE: '离线', CACHED: '缓存', EXPIRED: '已过期' }

export const robotDeviceName = (item: XpRobotDevice) => item.description?.trim() || item.device_id
export const robotAccountName = (item: XpRobotAccount) => item.nickname?.trim() || item.wxid
export const robotGroupName = (item: XpRobotGroup) => item.name?.trim() || item.nickname?.trim() || item.group_name?.trim() || item.group_id
export const robotAccountTypeLabel = (value?: string | null) => value ? (accountTypeLabels[value.toUpperCase()] ?? value) : '微信'
export const robotAccountStatusLabel = (value?: string | null) => value ? (accountStatusLabels[value.toUpperCase()] ?? value) : ''
export const robotMessageStatus = (status: string) => robotMessageStatusLabels[status] ?? { label: status || '未知状态', tone: 'gray' as PillTone }
export const formatRobotTime = (value: string | null | undefined) => value ? new Date(value).toLocaleString('zh-CN', { hour12: false }) : '—'
