import { apiRequest } from '@/api/http'

export type PosterBackgroundType = 'gradient' | 'image' | 'solid'

export interface PosterTemplate {
  public_id: string
  code: string
  name: string
  canvas_width: number
  canvas_height: number
  background_type: PosterBackgroundType
  background_value: Record<string, unknown> | null
  banner_config: Record<string, unknown> | null
  body_config: Record<string, unknown> | null
  qrcode_config: Record<string, unknown> | null
}

export interface PosterTemplateResponse {
  template: PosterTemplate
  matched_date: string
  is_date_specific: boolean
}

export function getPosterTemplate(code: string, date?: string) {
  const path = date ? `/poster-templates/${code}?date=${encodeURIComponent(date)}` : `/poster-templates/${code}`
  return apiRequest<PosterTemplateResponse>({ path })
}
