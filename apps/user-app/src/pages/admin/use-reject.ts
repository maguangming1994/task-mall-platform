/**
 * 审核驳回原因选择（闲置/社区/任务共用）：
 * 先给预设原因快捷选择，选「其他原因」时弹输入框且必填。
 * 返回原因文案；用户取消返回 null。
 */

export const SECOND_HAND_REJECT_REASONS = [
  '图片与实物不符',
  '包含违禁违规内容',
  '价格或描述异常',
  '疑似虚假信息',
]

export const COMMUNITY_REJECT_REASONS = [
  '包含违规违法内容',
  '广告营销引流',
  '抄袭搬运他人内容',
  '辱骂引战不友善',
]

export const TASK_REJECT_REASONS = [
  '提交材料不清晰',
  '与任务要求不符',
  '疑似作弊或刷单',
  '超过提交时限',
]

const OTHER_LABEL = '其他原因（手动填写）'

export async function chooseRejectReason(presets: string[]): Promise<string | null> {
  const action = await uni.showActionSheet({
    itemList: [...presets, OTHER_LABEL],
  }).catch(() => null)
  if (!action) return null

  const tapped = action.tapIndex
  if (tapped < presets.length) {
    return presets[tapped]
  }

  // 其他原因：必填手动输入
  for (let attempt = 0; attempt < 3; attempt += 1) {
    // eslint-disable-next-line no-await-in-loop
    const modal = await uni.showModal({
      title: '驳回原因',
      editable: true,
      placeholderText: '请输入具体驳回原因（必填，5-100字）',
      confirmText: '确认驳回',
      confirmColor: '#E54D42',
    }).catch(() => null)
    if (!modal || !modal.confirm) return null
    const reason = String(modal.content || '').trim()
    if (reason.length < 5) {
      // eslint-disable-next-line no-await-in-loop
      await uni.showToast({ title: '原因不少于 5 个字', icon: 'none' })
      continue
    }
    return reason.slice(0, 100)
  }
  return null
}
