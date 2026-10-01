import { apiRequest } from '@/api/http'

export type IconConfigCategory = 'tabbar' | 'module' | 'order' | 'community' | 'service' | 'common'
export type IconConfigTone = 'default' | 'yellow' | 'cyan' | 'green' | 'blue' | 'purple' | 'red'

export interface IconConfigItem {
  id: number
  code: string
  name: string
  category: IconConfigCategory | string
  svg_path: string
  fallback_char: string | null
  label: string | null
  description: string | null
  tone: IconConfigTone | string
  sort_order: number
  is_active: boolean
  meta: Record<string, unknown> | null
}

export function loadIconConfigs() {
  return apiRequest<{ items: IconConfigItem[] }>({ path: '/icon-configs' })
}
