import { apiRequest } from '@/api/http'

export type HomeLinkType = 'none' | 'tasks' | 'products' | 'community' | 'notifications' | 'orders' | 'assets'

export interface HomeBrand {
  logo_url: string
  logo_text: string
  name: string
  caption: string
}

export interface HomeCarouselItem {
  id: string
  kicker: string
  title: string
  description: string
  button_text: string
  link_type: HomeLinkType
  image_url: string
}

export interface HomeAnnouncement {
  enabled: boolean
  icon_text: string
  title: string
  content: string
  link_type: HomeLinkType
}

export interface HomePopup {
  enabled: boolean
  display_once: boolean
  content_id: string
  mode: 'announcement' | 'poster'
  title: string
  content: string
  image_url: string
  button_text: string
  link_type: HomeLinkType
}

export interface HomeModules {
  page_background_url: string
  notification_icon_url: string
  quick_grid_background_url: string
  quick_entries: Array<{ id: string; label: string; note: string; mark: string; tone: string; icon_url: string; link_type: HomeLinkType }>
  recommendation: { section_title: string; section_subtitle: string; title: string; description: string; tag: string; reward_text: string; time_text: string; icon_text: string; icon_url: string; background_url: string; link_type: HomeLinkType }
  product_section: { section_title: string; section_subtitle: string; cards: Array<{ id: string; eyebrow: string; title: string; price_text: string; tone: string; image_url: string; background_url: string; link_type: HomeLinkType }> }
  trust: { title: string; description: string; icon_url: string; background_url: string; link_type: HomeLinkType }
}

export interface HomeContent {
  brand: HomeBrand
  carousel: { autoplay: boolean; interval_ms: number; items: HomeCarouselItem[] }
  announcement: HomeAnnouncement
  popup: HomePopup
  modules: HomeModules
  versions: Record<string, number | null>
}

export function loadHomeContent() {
  return apiRequest<HomeContent>({ path: '/home-content' })
}
