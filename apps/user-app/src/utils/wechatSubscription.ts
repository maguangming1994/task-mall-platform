import {
  getWechatSubscriptionOptions,
  recordWechatSubscriptionResults,
  type WechatSubscriptionOptions,
} from '@/api/notifications'

/**
 * 小程序社区互动订阅授权。
 *
 * 微信要求 requestSubscribeMessage 必须在用户点击回调中同步调用，因此这里把模板 ID
 * 预先缓存在页面加载时拉取，点击评论/发布时直接拉起授权面板，不阻塞主流程。
 */
let cachedTemplateIds: string[] = []

function selectTemplateIds(options: WechatSubscriptionOptions): string[] {
  const group = options.groups.find((item) => item.context === 'community')
  if (!group) return []
  const grants = new Map(group.grants.map((grant) => [grant.template_id, grant]))

  return [...new Set(group.template_ids)]
    .filter((templateId) => {
      const grant = grants.get(templateId)
      if (!grant || grant.status === 'not_requested') return true
      // 已授权但次数用尽时允许再次申请；被微信封禁的模板不再打扰用户。
      return grant.status !== 'ban' && grant.available_count <= 0
    })
    .slice(0, 3)
}

/** 页面加载时预取社区互动模板，失败时静默降级。 */
export async function primeCommunitySubscription(token: string): Promise<void> {
  // #ifndef MP-WEIXIN
  return
  // #endif
  // #ifdef MP-WEIXIN
  if (!token) return
  try {
    cachedTemplateIds = selectTemplateIds(await getWechatSubscriptionOptions(token))
  } catch {
    cachedTemplateIds = []
  }
  // #endif
}

/** 在用户点击回调中同步调用，拉起微信一次性订阅授权面板。 */
export function requestCommunitySubscription(token: string): void {
  void requestCommunitySubscriptionAndWait(token)
}

/**
 * 与 requestCommunitySubscription 相同，但会等待用户选择结果并写入授权，
 * 供「发布成功」这类需要立即消费授权的场景使用。
 */
export async function requestCommunitySubscriptionAndWait(token: string): Promise<void> {
  // #ifndef MP-WEIXIN
  return
  // #endif
  // #ifdef MP-WEIXIN
  if (!token) return
  if (!cachedTemplateIds.length) {
    await primeCommunitySubscription(token)
    return
  }
  const templateIds = cachedTemplateIds.slice(0, 3)
  cachedTemplateIds = []
  await new Promise<void>((resolve) => {
    uni.requestSubscribeMessage({
      tmplIds: templateIds,
      success: (result) => {
        const payload = result as unknown as Record<string, unknown>
        const statuses = templateIds.map((templateId) => ({
          template_id: templateId,
          status: String(payload[templateId] || 'reject'),
        }))
        recordWechatSubscriptionResults(
          token,
          statuses,
          `wechat-subscription-${Date.now()}-${Math.random().toString(36).slice(2, 10)}`,
        ).catch(() => undefined).finally(() => resolve())
      },
      fail: () => {
        void primeCommunitySubscription(token)
        resolve()
      },
    })
  })
  // #endif
}
