import { adminRequest } from './http'

export interface AdminMaterial {
  id: string
  title: string
  description: string | null
  category: { id: string; name: string } | null
  netdisk_type: string
  netdisk_label: string
  share_url: string
  extract_code: string | null
  share_text: string | null
  file_type: string
  source: 'user' | 'admin'
  uploader_name: string
  uploader: { id: string; nickname: string } | null
  status: 'pending' | 'approved' | 'rejected' | 'offline'
  reject_reason: string | null
  bonus_granted: boolean
  view_count: number
  fetch_count: number
  created_at: string | null
  reviewed_at: string | null
}

export interface AdminMaterialReport {
  id: string
  material: {
    id: string
    title: string
    status: 'pending' | 'approved' | 'rejected' | 'offline'
    netdisk_type: string
    netdisk_label: string
    share_url: string
    extract_code: string | null
    fetch_count: number
    uploader_name: string
  } | null
  reporter: { id: string; nickname: string } | null
  reason: string
  detail: string | null
  status: 'pending' | 'handled'
  decision: 'upheld' | 'dismissed' | 'other' | null
  handling_note: string | null
  created_at: string | null
  handled_at: string | null
}

export interface AdminMaterialList {
  items: AdminMaterial[]
  pagination: { page: number; page_size: number; total: number }
}

export interface AdminMaterialReportList {
  items: AdminMaterialReport[]
  pagination: { page: number; page_size: number; total: number }
}

export function listManagedMaterials(params: { status?: string; keyword?: string; page?: number; page_size?: number } = {}) {
  return adminRequest<AdminMaterialList>({
    path: '/admin/materials',
    params: { status: params.status, keyword: params.keyword, page: params.page, page_size: params.page_size ?? 20 },
  })
}

export function reviewManagedMaterial(id: string, input: { decision: 'approve' | 'reject' | 'offline'; reason?: string }) {
  return adminRequest<{ material: AdminMaterial }>({
    path: `/admin/materials/${encodeURIComponent(id)}/review`,
    method: 'POST',
    data: input,
  })
}

export function listMaterialReports(params: { status?: string; page?: number; page_size?: number } = {}) {
  return adminRequest<AdminMaterialReportList>({
    path: '/admin/material-reports',
    params: { status: params.status, page: params.page, page_size: params.page_size ?? 20 },
  })
}

export function handleMaterialReport(
  id: string,
  input: { decision: 'upheld' | 'dismissed' | 'other'; handling_note?: string; takedown?: boolean },
) {
  return adminRequest<{ handled: boolean }>({
    path: `/admin/material-reports/${encodeURIComponent(id)}/handle`,
    method: 'POST',
    data: input,
  })
}
