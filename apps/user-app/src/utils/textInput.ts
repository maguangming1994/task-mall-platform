export interface TextEditResult {
  value: string
  cursor: number
  changed: boolean
}

export function unicodeLength(value: string) {
  return Array.from(value).length
}

export function insertTextAtCursor(value: string, insertion: string, cursor: number, maxLength: number): TextEditResult {
  const safeCursor = Math.max(0, Math.min(Number.isFinite(cursor) ? cursor : value.length, value.length))
  const nextValue = `${value.slice(0, safeCursor)}${insertion}${value.slice(safeCursor)}`
  if (unicodeLength(nextValue) > maxLength) {
    return { value, cursor: safeCursor, changed: false }
  }

  return {
    value: nextValue,
    cursor: safeCursor + insertion.length,
    changed: true,
  }
}
