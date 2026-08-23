import { apiRequest } from '@/api/http'

export interface AppearanceIcon {
  id: string
  mark: string
  icon_url: string
}

export interface MallAppearance {
  brand: {
    logo_url: string
    logo_text: string
    name: string
    caption: string
  }
  assurances: Array<AppearanceIcon & {
    label: string
    description: string
    tone: string
  }>
}

export interface AccountAppearance {
  profile_icon_url: string
  service_icons: AppearanceIcon[]
}

export interface AppearanceContent {
  mall: MallAppearance
  account: AccountAppearance
  versions: Record<string, number | null>
}

export function loadAppearanceContent() {
  return apiRequest<AppearanceContent>({ path: '/appearance-content' })
}
