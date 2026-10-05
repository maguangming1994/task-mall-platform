/** 审核工作台通用展示工具 */

/** ISO 时间 → 「刚刚 / N分钟前 / N小时前 / N天前 / MM-DD」 */
export function formatRelativeTime(iso?: string | null): string {
  if (!iso) return ''
  const time = Date.parse(iso)
  if (Number.isNaN(time)) return ''
  const diff = Date.now() - time
  if (diff < 60 * 1000) return '刚刚'
  if (diff < 60 * 60 * 1000) return `${Math.floor(diff / 60000)}分钟前`
  if (diff < 24 * 60 * 60 * 1000) return `${Math.floor(diff / 3600000)}小时前`
  if (diff < 7 * 24 * 60 * 60 * 1000) return `${Math.floor(diff / 86400000)}天前`

  const date = new Date(time)
  const month = String(date.getMonth() + 1).padStart(2, '0')
  const day = String(date.getDate()).padStart(2, '0')
  return `${month}-${day}`
}

/** ISO 时间 → 「YYYY-MM-DD HH:mm」 */
export function formatDateTime(iso?: string | null): string {
  if (!iso) return ''
  const time = Date.parse(iso)
  if (Number.isNaN(time)) return ''
  const date = new Date(time)
  const y = date.getFullYear()
  const m = String(date.getMonth() + 1).padStart(2, '0')
  const d = String(date.getDate()).padStart(2, '0')
  const hh = String(date.getHours()).padStart(2, '0')
  const mm = String(date.getMinutes()).padStart(2, '0')
  return `${y}-${m}-${d} ${hh}:${mm}`
}
