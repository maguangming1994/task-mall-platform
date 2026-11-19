import { adminRequest } from './admin'

export interface MaterialCategoryRow {
  id: string
  name: string
  sort_order: number
  status: 'active' | 'disabled'
  material_count: number
}

export interface ManagedMaterial {
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

export interface MaterialListResult {
  items: ManagedMaterial[]
  pagination: { page: number; page_size: number; total: number }
}

export async function listMaterialCategories(): Promise<{ items: MaterialCategoryRow[] }> {
  return adminRequest('/admin/material/categories')
}

export async function createMaterialCategory(input: { name: string; sort_order?: number }) {
  return adminRequest<{ category: MaterialCategoryRow }>('/admin/material/categories', {
    method: 'POST',
    body: JSON.stringify(input),
  })
}

export async function updateMaterialCategory(
  id: string,
  input: { name?: string; sort_order?: number; status?: 'active' | 'disabled' },
) {
  return adminRequest<{ category: MaterialCategoryRow }>(`/admin/material/categories/${encodeURIComponent(id)}`, {
    method: 'PUT',
    body: JSON.stringify(input),
  })
}

export async function deleteMaterialCategory(id: string) {
  return adminRequest<{ deleted: boolean }>(`/admin/material/categories/${encodeURIComponent(id)}`, {
    method: 'DELETE',
  })
}

export async function listManagedMaterials(
  filters: { status?: string; category_id?: string; keyword?: string; page?: number; page_size?: number } = {},
): Promise<MaterialListResult> {
  const query = new URLSearchParams()
  if (filters.status) query.set('status', filters.status)
  if (filters.category_id) query.set('category_id', filters.category_id)
  if (filters.keyword) query.set('keyword', filters.keyword)
  if (filters.page) query.set('page', String(filters.page))
  if (filters.page_size) query.set('page_size', String(filters.page_size))
  const suffix = query.toString()
  return adminRequest(`/admin/materials${suffix ? `?${suffix}` : ''}`)
}

export async function reviewManagedMaterial(
  id: string,
  input: { decision: 'approve' | 'reject' | 'offline'; reason?: string },
) {
  return adminRequest<{ material: ManagedMaterial }>(`/admin/materials/${encodeURIComponent(id)}/review`, {
    method: 'POST',
    body: JSON.stringify(input),
  })
}

export interface MaterialFormInput {
  title: string
  description?: string
  category_id?: string
  share_text?: string
  share_url: string
  extract_code?: string
  netdisk_type: string
  file_type: string
  status: 'pending' | 'approved' | 'rejected' | 'offline'
}

export async function createManagedMaterial(input: MaterialFormInput) {
  return adminRequest<{ material: ManagedMaterial }>('/admin/materials', {
    method: 'POST',
    body: JSON.stringify(input),
  })
}

export async function updateManagedMaterial(id: string, input: MaterialFormInput) {
  return adminRequest<{ material: ManagedMaterial }>(`/admin/materials/${encodeURIComponent(id)}`, {
    method: 'PUT',
    body: JSON.stringify(input),
  })
}

export interface ManagedMaterialRequest {
  id: string
  title: string
  requirement: string
  status: 'pending' | 'processing' | 'done' | 'closed'
  admin_note: string | null
  user: { id: string; nickname: string } | null
  created_at: string | null
  handled_at: string | null
}

export async function listMaterialRequests(
  filters: { status?: string; keyword?: string; page?: number; page_size?: number } = {},
): Promise<{ items: ManagedMaterialRequest[]; pagination: { page: number; page_size: number; total: number } }> {
  const query = new URLSearchParams()
  if (filters.status) query.set('status', filters.status)
  if (filters.keyword) query.set('keyword', filters.keyword)
  if (filters.page) query.set('page', String(filters.page))
  if (filters.page_size) query.set('page_size', String(filters.page_size))
  const suffix = query.toString()
  return adminRequest(`/admin/material-requests${suffix ? `?${suffix}` : ''}`)
}

export async function updateMaterialRequest(
  id: string,
  input: { status: ManagedMaterialRequest['status']; admin_note?: string },
) {
  return adminRequest<{ request: { id: string; status: string; admin_note: string | null } }>(
    `/admin/material-requests/${encodeURIComponent(id)}`,
    { method: 'PUT', body: JSON.stringify(input) },
  )
}

export interface ManagedMaterialReport {
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

export async function listMaterialReports(
  filters: { status?: string; page?: number; page_size?: number } = {},
): Promise<{ items: ManagedMaterialReport[]; pagination: { page: number; page_size: number; total: number } }> {
  const query = new URLSearchParams()
  if (filters.status) query.set('status', filters.status)
  if (filters.page) query.set('page', String(filters.page))
  if (filters.page_size) query.set('page_size', String(filters.page_size))
  const suffix = query.toString()
  return adminRequest(`/admin/material-reports${suffix ? `?${suffix}` : ''}`)
}

export async function handleMaterialReport(
  id: string,
  input: { decision: 'upheld' | 'dismissed' | 'other'; handling_note?: string; takedown?: boolean },
) {
  return adminRequest<{ handled: boolean; idempotent?: boolean }>(
    `/admin/material-reports/${encodeURIComponent(id)}/handle`,
    { method: 'POST', body: JSON.stringify(input) },
  )
}
