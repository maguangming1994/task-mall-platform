export type QueryValue = string | number | boolean | null | undefined

export function buildQueryString(values: Record<string, QueryValue>): string {
  return Object.entries(values)
    .filter(([, value]) => value !== undefined && value !== null && value !== '')
    .map(([key, value]) => `${encodeURIComponent(key)}=${encodeURIComponent(String(value))}`)
    .join('&')
}

export function readQueryString(search: string, key: string): string | null {
  const query = search.replace(/^\?/, '')
  if (!query) return null

  for (const pair of query.split('&')) {
    if (!pair) continue
    const separator = pair.indexOf('=')
    const rawKey = separator >= 0 ? pair.slice(0, separator) : pair
    if (decodeURIComponent(rawKey.replace(/\+/g, ' ')) !== key) continue
    const rawValue = separator >= 0 ? pair.slice(separator + 1) : ''
    return decodeURIComponent(rawValue.replace(/\+/g, ' '))
  }

  return null
}
