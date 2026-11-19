import { apiRequest, toQueryString } from '@/api/http'

export interface Major {
  code: string
  name: string
  category: string
}

export interface MajorGroup {
  category: string
  items: { code: string; name: string }[]
}

export interface MajorListParams {
  keyword?: string
  category?: string
  limit?: number
}

export interface MajorListResult {
  items: Major[]
  /** 无搜索词时按学科门类分组返回，便于完整浏览；有搜索词时为 null */
  groups: MajorGroup[] | null
  categories: string[]
}

export function fetchMajors(params: MajorListParams = {}): Promise<MajorListResult> {
  const query = toQueryString(params as Record<string, unknown>)
  return apiRequest<MajorListResult>({ path: `/majors${query ? `?${query}` : ''}` })
}
