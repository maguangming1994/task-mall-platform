import { apiRequest, toQueryString } from '@/api/http'

export type MaterialNetdiskType = 'baidu' | 'quark' | 'aliyun' | 'other'
export type MaterialFileType = 'pdf' | 'word' | 'excel' | 'ppt' | 'video' | 'archive' | 'other'
export type MaterialStatus = 'pending' | 'approved' | 'rejected' | 'offline'

export interface MaterialCategory {
  id: string
  name: string
  sort_order: number
}

export interface MaterialItem {
  id: string
  title: string
  description?: string | null
  category: { id: string; name: string } | null
  netdisk_type: MaterialNetdiskType
  netdisk_label: string
  file_type: MaterialFileType
  source: 'user' | 'admin'
  uploader_name: string
  is_admin_upload: boolean
  status: MaterialStatus
  view_count: number
  fetch_count: number
  created_at: string
  share_url?: string
  extract_code?: string | null
  reject_reason?: string | null
  is_owner?: boolean
  already_fetched?: boolean
}

export interface MaterialQuota {
  daily_limit: number
  daily_used: number
  daily_remaining: number
  bonus_quota: number
  total_remaining: number
}

export interface MaterialMeta {
  categories: MaterialCategory[]
  stats: { total: number }
  daily_limit: number
  bonus_per_approved: number
  quota: MaterialQuota | null
}

export interface MaterialListResult {
  items: MaterialItem[]
  pagination: { page: number; page_size: number; total: number }
}

export function getMaterialMeta(accessToken?: string | null) {
  return apiRequest<MaterialMeta>({ path: '/materials/meta', accessToken })
}

export function listMaterials(
  accessToken: string | null | undefined,
  filters: { keyword?: string; category_id?: string; sort?: 'latest' | 'hot'; page?: number; page_size?: number } = {},
) {
  const query = toQueryString(filters)
  return apiRequest<MaterialListResult>({ path: `/materials${query ? `?${query}` : ''}`, accessToken })
}

export function getMaterial(accessToken: string | null | undefined, id: string) {
  return apiRequest<MaterialItem>({ path: `/materials/${encodeURIComponent(id)}`, accessToken })
}

export interface MaterialFetchResult {
  share_url: string
  extract_code: string | null
  netdisk_type: MaterialNetdiskType
  netdisk_label: string
  already_owned: boolean
  quota: MaterialQuota
}

export function fetchMaterial(accessToken: string, id: string) {
  return apiRequest<MaterialFetchResult>({
    path: `/materials/${encodeURIComponent(id)}/fetch`,
    method: 'POST',
    accessToken,
  })
}

export interface MaterialParsed {
  share_url: string | null
  extract_code: string | null
  netdisk_type: MaterialNetdiskType
  title: string
  file_type: MaterialFileType
}

export interface MaterialSubmitResult {
  material: MaterialItem
  parsed: MaterialParsed
  review_note: string
}

export function submitMaterial(
  accessToken: string,
  input: { share_text: string; title?: string; description?: string; category_id?: string; agreement_accepted: boolean },
) {
  return apiRequest<MaterialSubmitResult>({ path: '/materials', method: 'POST', data: input, accessToken })
}

export function listMyMaterials(accessToken: string, page = 1, pageSize = 20) {
  return apiRequest<MaterialListResult>({
    path: `/my-materials?${toQueryString({ page, page_size: pageSize })}`,
    accessToken,
  })
}

export interface MaterialFetchedItem {
  id: string
  fetched_at: string
  material: MaterialItem
}

export function listMyMaterialFetches(accessToken: string, page = 1, pageSize = 20) {
  return apiRequest<{ items: MaterialFetchedItem[]; pagination: { page: number; page_size: number; total: number } }>({
    path: `/my-material-fetches?${toQueryString({ page, page_size: pageSize })}`,
    accessToken,
  })
}

export interface MaterialRequestItem {
  id: string
  title: string
  requirement: string
  status: 'pending' | 'processing' | 'done' | 'closed'
  admin_note?: string | null
  created_at: string
  handled_at?: string | null
}

export function submitMaterialRequest(accessToken: string, input: { title: string; requirement: string }) {
  return apiRequest<{ request: MaterialRequestItem; message: string }>({
    path: '/material-requests',
    method: 'POST',
    data: input,
    accessToken,
  })
}

export function listMyMaterialRequests(accessToken: string, page = 1, pageSize = 20) {
  return apiRequest<{ items: MaterialRequestItem[]; pagination: { page: number; page_size: number; total: number } }>({
    path: `/my-material-requests?${toQueryString({ page, page_size: pageSize })}`,
    accessToken,
  })
}

export function reportMaterial(accessToken: string, id: string, input: { reason: string; detail?: string }) {
  return apiRequest<{ message: string }>({
    path: `/materials/${encodeURIComponent(id)}/reports`,
    method: 'POST',
    data: input,
    accessToken,
  })
}

/** 客户端侧的轻量解析预览（服务端会再次权威解析） */
export function parseShareText(text: string): MaterialParsed {
  const urlMatch = text.match(
    /https?:\/\/(?:[a-z0-9-]+\.)*(?:pan\.baidu\.com|eyun\.baidu\.com|pan\.quark\.cn|aliyundrive\.com|alipan\.com|115\.com|cloud\.189\.cn|caiyun\.139\.com|pan\.xunlei\.com|123pan\.com)\/[^\s，。、；;）)】\]"'<>]*/i,
  )
  const shareUrl = urlMatch ? urlMatch[0].replace(/[，。、；;）)】\]$]+$/, '') : null

  let code: string | null = null
  if (shareUrl) {
    const pwd = shareUrl.match(/[?&](?:pwd|password|code)=([a-zA-Z0-9]{2,12})/i)
    if (pwd) code = pwd[1]
  }
  if (!code) {
    const match = text.match(/(?:提取码|提取碼|访问码|訪問碼|密码|密碼)\s*[:：]?\s*([a-zA-Z0-9]{2,12})/i)
    if (match) code = match[1]
  }

  let netdiskType: MaterialNetdiskType = 'other'
  if (shareUrl) {
    if (/pan\.baidu\.com|eyun\.baidu\.com/i.test(shareUrl)) netdiskType = 'baidu'
    else if (/pan\.quark\.cn/i.test(shareUrl)) netdiskType = 'quark'
    else if (/aliyundrive\.com|alipan\.com/i.test(shareUrl)) netdiskType = 'aliyun'
  }

  let title = text
    .replace(shareUrl ?? '', ' ')
    .replace(/(?:提取码|提取碼|访问码|訪問碼|密码|密碼)\s*[:：]?\s*[a-zA-Z0-9]{2,12}/gi, ' ')
    .replace(/https?:\/\/\S+/gi, ' ')
    .replace(/(?:复制这段内容|复制链接|点击链接|打开网盘|下载后查看|来自[:：]?)/gi, ' ')
  title = (title.split(/[\r\n]+/).map((line) => line.replace(/\s+/g, ' ').trim()).find((line) => line.length >= 2) ?? '')
    .replace(/^[\s\-—_·•.,，。:：;；【】\[\]()（）]+|[\s\-—_·•.,，。:：;；【】\[\]()（）]+$/g, '')
    .slice(0, 60)

  const extMatch = `${text} ${title}`.match(/\.([a-z0-9]{2,5})(?:\b|$)/i)
  const ext = extMatch ? extMatch[1].toLowerCase() : ''
  const fileTypeMap: Record<string, MaterialFileType> = {
    pdf: 'pdf', doc: 'word', docx: 'word', xls: 'excel', xlsx: 'excel', csv: 'excel',
    ppt: 'ppt', pptx: 'ppt', mp4: 'video', mov: 'video', avi: 'video', zip: 'archive', rar: 'archive', '7z': 'archive',
  }

  return {
    share_url: shareUrl,
    extract_code: code,
    netdisk_type: netdiskType,
    title: title || '学习资料分享',
    file_type: fileTypeMap[ext] ?? 'other',
  }
}
