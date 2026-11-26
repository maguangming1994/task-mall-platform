import { apiRequest, toQueryString } from '@/api/http'

export interface Major {
  code: string
  name: string
  category: string | null
  /** 培养层次：本科(普通)/专科(高职)，学校维度数据才有 */
  level?: string | null
}

export interface MajorGroup {
  category: string
  items: { code: string; name: string }[]
}

export interface MajorListParams {
  keyword?: string
  category?: string
  /** 绑定学校后传入，接口会把该校相关学科门类排到前面 */
  campus_id?: string
  limit?: number
}

export interface MajorListResult {
  items: Major[]
  /** 无搜索词时按学科门类分组返回，便于完整浏览；有搜索词时为 null */
  groups: MajorGroup[] | null
  categories: string[]
  /** 按学校类型推荐的优先学科门类，未传 campus_id 或已有学校专业数据时为空数组 */
  recommended_categories: string[]
  /** campus=该校开设专业，catalog=全国专业目录兜底 */
  source?: 'campus' | 'catalog'
  campus_name?: string | null
}

export function fetchMajors(params: MajorListParams = {}): Promise<MajorListResult> {
  const query = toQueryString(params as Record<string, unknown>)
  return apiRequest<MajorListResult>({ path: `/majors${query ? `?${query}` : ''}` })
}
