import {
  ApiError,
  type CommunityAuditStats,
  type CommunityContentSecurityProvider,
  type CommunityContentSecurityRiskLevel,
  type CommunityContentSecurityVerdict,
  type CommunityModerationReasonCode,
  type CommunityPostCategory,
  type ManagedCommunityPost,
} from '../../api/admin'

export const categoryLabels: Record<CommunityPostCategory, string> = {
  experience: '经验分享',
  guide: '实用攻略',
  question: '互助问答',
  showcase: '成果展示',
}

export const categoryOptions = Object.entries(categoryLabels).map(([value, label]) => ({ value, label })) as Array<{ value: CommunityPostCategory; label: string }>

export const auditStatusMeta = {
  pending_review: { label: '待审核', color: 'gold' },
  published: { label: '已发布', color: 'green' },
  rejected: { label: '已驳回', color: 'red' },
  hidden: { label: '已隐藏', color: 'orange' },
  deleted: { label: '已删除', color: 'default' },
} as const

export const auditStatusOptions = Object.entries(auditStatusMeta).map(([value, meta]) => ({ value, label: meta.label }))

export const moderationReasonLabels: Record<CommunityModerationReasonCode, string> = {
  spam: '垃圾广告或恶意刷屏',
  sexual: '色情低俗',
  fraud: '欺诈或诈骗',
  abuse: '辱骂攻击或骚扰',
  infringement: '侵犯知识产权或合法权益',
  illegal: '违法违规',
  other: '其他违反社区规范',
}

export const moderationReasonOptions = Object.entries(moderationReasonLabels).map(([value, label]) => ({ value, label })) as Array<{ value: CommunityModerationReasonCode; label: string }>

export const securityProviderLabels: Record<CommunityContentSecurityProvider, string> = {
  wechat: '微信内容安全',
  aliyun: '阿里云内容安全',
}

export const securityVerdictMeta: Record<CommunityContentSecurityVerdict, { label: string; color: string }> = {
  pass: { label: '通过', color: 'green' },
  review: { label: '需人工复审', color: 'gold' },
  block: { label: '拦截', color: 'red' },
  skipped: { label: '已降级跳过', color: 'default' },
}

export const securityRiskMeta: Record<CommunityContentSecurityRiskLevel, { label: string; color: string }> = {
  none: { label: '无风险', color: 'green' },
  low: { label: '低风险', color: 'blue' },
  medium: { label: '中风险', color: 'orange' },
  high: { label: '高风险', color: 'red' },
}

/**
 * 机审风险标签中文映射。
 * 阿里云 TextModerationPlus 返回的 Result[].Label 为类目 code（ad/porn/politics 等），
 * 微信 msg_sec_check 返回的是数字字符串。映射覆盖常见类目，未命中的原样显示原值。
 */
export const securityRiskLabelLabels: Record<string, string> = {
  ad: '广告',
  porn: '色情低俗',
  sexy: '性感',
  politics: '时政敏感',
  terrorism: '暴恐',
  abuse: '辱骂攻击',
  ban: '违禁内容',
  contraband: '违禁物品',
  fraud: '欺诈诈骗',
  drug: '涉毒',
  gambling: '赌博',
  violence: '暴力',
  spam: '垃圾信息',
  watermark: '水印',
  qrcode: '二维码引流',
  improper: '不当内容',
  forbidden: '违法违规',
  other: '其他风险',
  // 微信 msg_sec_check v2 的 result.label 数字编码
  '100': '正常',
  '10001': '色情',
  '20001': '诈骗',
  '20002': '辱骂',
  '20003': '政治敏感',
  '20006': '违法犯罪',
  '20008': '广告',
  '20012': '暴恐',
  '20013': '色情低俗',
  '30001': '违法违规',
  '30002': '违法违规',
}

export function securityRiskLabelLabel(value: string): string {
  return securityRiskLabelLabels[value] ?? securityRiskLabelLabels[value.toLowerCase()] ?? value
}

export const emptyAuditStats = (): CommunityAuditStats => ({
  total: 0,
  pending_review: 0,
  published: 0,
  rejected: 0,
  hidden: 0,
  deleted: 0,
})

export function formatTime(value?: string | null): string {
  if (!value) return '—'
  const date = new Date(value)
  return Number.isNaN(date.getTime()) ? value : date.toLocaleString('zh-CN', { hour12: false })
}

export function errorText(error: unknown, fallback: string): string {
  return error instanceof ApiError ? error.message : fallback
}

export function isReviewConflict(error: unknown): boolean {
  return error instanceof ApiError && error.status === 409
}

export function getPostCurrentVersion(post: ManagedCommunityPost) {
  const version = post.latest_version
  return {
    version_no: version?.version_no ?? post.current_version_no,
    title: version?.title ?? post.title,
    content: version?.content ?? post.content,
    category_code: version?.category_code ?? post.category_code,
    review_reason_code: version?.review_reason_code ?? post.review_reason_code,
    review_note: version?.review_note ?? post.review_note,
    reviewed_at: version?.reviewed_at ?? post.reviewed_at,
  }
}

export function categoryLabel(category: CommunityPostCategory): string {
  return categoryLabels[category] ?? category
}
