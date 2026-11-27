import { apiRequest } from '@/api/http'

export type PageDecorationKey = 'home' | 'community' | 'mall' | 'account'

export type DecorationChannel = 'h5' | 'miniapp'

export interface DecorationSpacing {
  top: number
  right: number
  bottom: number
  left: number
}

export interface DecorationCorners {
  top_left: number
  top_right: number
  bottom_left: number
  bottom_right: number
}

export interface DecorationStyle {
  margin: DecorationSpacing
  padding: DecorationSpacing
  radius: number
  corner_radius: boolean
  radius_corners: DecorationCorners
  opacity: number
  display_daily_start: string
  display_daily_end: string
  background_type: 'none' | 'color' | 'image' | 'gradient'
  background_color: string
  background_image: string
  background_gradient: string
  border: boolean
  border_color: string
  shadow: boolean
  channels: DecorationChannel[]
  display_start: string
  display_end: string
}

export interface DecorationSection {
  id: string
  type: string
  enabled: boolean
  style: DecorationStyle
  props: Record<string, unknown>
}

export interface DecorationPageConfig {
  name: string
  title: string
  description: string
  cover_url: string
  background_color: string
  background_url: string
  sections: DecorationSection[]
}

export interface PageDecoration {
  schema_version: number
  pages: Record<PageDecorationKey, DecorationPageConfig>
  version: number | null
}

export function emptyDecorationPage(): DecorationPageConfig {
  return {
    name: '',
    title: '',
    description: '',
    cover_url: '',
    background_color: '',
    background_url: '',
    sections: [],
  }
}

export function emptyPageDecoration(): PageDecoration {
  return {
    schema_version: 1,
    version: null,
    pages: {
      home: emptyDecorationPage(),
      community: emptyDecorationPage(),
      mall: emptyDecorationPage(),
      account: emptyDecorationPage(),
    },
  }
}

export async function loadPageDecoration(): Promise<PageDecoration> {
  try {
    return await apiRequest<PageDecoration>({ path: '/page-decoration' })
  } catch {
    return emptyPageDecoration()
  }
}
