type TimeValue = string | null | undefined

const BEIJING_OFFSET_MINUTES = 8 * 60
const pad = (value: number) => String(value).padStart(2, '0')

// API timestamps carry an offset; legacy zone-less values follow APP_TIMEZONE (Asia/Shanghai).
// Parse numeric fields explicitly so WeChat/iOS and device locales cannot change the result.
function beijingDate(value: TimeValue): Date | null {
  if (!value) return null
  const match = /^(\d{4})-(\d{2})-(\d{2})(?:[T ](\d{2}):(\d{2})(?::(\d{2})(?:\.(\d{1,9}))?)?(Z|[+-]\d{2}:?\d{2})?)?$/.exec(value.trim())
  if (!match) return null
  const [, year, month, day, hour = '0', minute = '0', second = '0', fraction = '', zone] = match
  const local = new Date(0)
  local.setUTCFullYear(Number(year), Number(month) - 1, Number(day))
  local.setUTCHours(Number(hour), Number(minute), Number(second), Number(fraction.padEnd(3, '0').slice(0, 3)))
  if (local.getUTCFullYear() !== Number(year) || local.getUTCMonth() !== Number(month) - 1
    || local.getUTCDate() !== Number(day) || local.getUTCHours() !== Number(hour)
    || local.getUTCMinutes() !== Number(minute) || local.getUTCSeconds() !== Number(second)) return null

  let offset = BEIJING_OFFSET_MINUTES
  if (zone === 'Z') offset = 0
  else if (zone) {
    const digits = zone.slice(1).replace(':', '')
    const hours = Number(digits.slice(0, 2))
    const minutes = Number(digits.slice(2))
    if (hours > 14 || minutes > 59 || (hours === 14 && minutes !== 0)) return null
    offset = (zone[0] === '+' ? 1 : -1) * (hours * 60 + minutes)
  }
  return new Date(local.getTime() + (BEIJING_OFFSET_MINUTES - offset) * 60000)
}

function datePart(date: Date): string {
  return `${String(date.getUTCFullYear()).padStart(4, '0')}-${pad(date.getUTCMonth() + 1)}-${pad(date.getUTCDate())}`
}

export function formatDate(value: TimeValue, fallback = '—'): string {
  const date = beijingDate(value)
  return date ? datePart(date) : fallback
}

export function formatDateTime(value: TimeValue, fallback = '—'): string {
  const date = beijingDate(value)
  return date ? `${datePart(date)} ${pad(date.getUTCHours())}:${pad(date.getUTCMinutes())}` : fallback
}
