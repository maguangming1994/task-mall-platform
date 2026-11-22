import { adminRequest } from './admin'

export interface PosterTemplateRow {
  id: number
  public_id: string
  code: string
  name: string
  description: string | null
  canvas_width: number
  canvas_height: number
  background_type: 'gradient' | 'image' | 'solid'
  background_value: Record<string, unknown> | null
  banner_config: Record<string, unknown> | null
  body_config: Record<string, unknown> | null
  qrcode_config: Record<string, unknown> | null
  date_rule: PosterDateRule | null
  priority: number
  is_default: boolean
  is_active: boolean
  created_at: string
  updated_at: string
}

export interface PosterDateRule {
  mode: 'range' | 'monthday' | 'weekday'
  ranges?: { start: string; end: string }[]
  monthdays?: string[]
  weekdays?: number[]
}

export interface PosterTemplateInput {
  code: string
  name: string
  description?: string | null
  canvas_width?: number
  canvas_height?: number
  background_type: 'gradient' | 'image' | 'solid'
  background_value?: Record<string, unknown> | null
  banner_config?: Record<string, unknown> | null
  body_config?: Record<string, unknown> | null
  qrcode_config?: Record<string, unknown> | null
  date_rule?: PosterDateRule | null
  priority?: number
  is_default?: boolean
  is_active?: boolean
}

export async function listPosterTemplates(filters: { code?: string; is_active?: boolean } = {}): Promise<{ items: PosterTemplateRow[] }> {
  const params = new URLSearchParams()
  if (filters.code) params.set('code', filters.code)
  if (typeof filters.is_active === 'boolean') params.set('is_active', filters.is_active ? '1' : '0')
  const qs = params.toString()
  return adminRequest(`/admin/poster-templates${qs ? `?${qs}` : ''}`)
}

export async function getPosterTemplate(publicId: string): Promise<{ template: PosterTemplateRow }> {
  return adminRequest(`/admin/poster-templates/${encodeURIComponent(publicId)}`)
}

export async function createPosterTemplate(input: PosterTemplateInput): Promise<{ template: PosterTemplateRow }> {
  return adminRequest('/admin/poster-templates', { method: 'POST', body: JSON.stringify(input) })
}

export async function updatePosterTemplate(publicId: string, input: Partial<PosterTemplateInput>): Promise<{ template: PosterTemplateRow }> {
  return adminRequest(`/admin/poster-templates/${encodeURIComponent(publicId)}`, { method: 'PUT', body: JSON.stringify(input) })
}

export async function deletePosterTemplate(publicId: string): Promise<{ deleted: boolean }> {
  return adminRequest(`/admin/poster-templates/${encodeURIComponent(publicId)}`, { method: 'DELETE' })
}
