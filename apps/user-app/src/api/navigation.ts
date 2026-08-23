import { apiRequest } from '@/api/http'

export type NavigationTarget = 'home' | 'tasks' | 'products' | 'community' | 'account'

export interface BottomNavigationItem {
  id: string
  label: string
  target: NavigationTarget
  enabled: boolean
  icon_url: string
  active_icon_url: string
}

export interface BottomNavigationStyle {
  background_color: string
  text_color: string
  active_color: string
  border_color: string
}

export interface BottomNavigationConfig {
  items: BottomNavigationItem[]
  style: BottomNavigationStyle
  version: number | null
}

export const navigationRoutes: Record<NavigationTarget, string> = {
  home: '/pages/index/index',
  tasks: '/pages/tasks/index',
  products: '/pages/products/index',
  community: '/pages/community/index',
  account: '/pages/account/index',
}

export function loadBottomNavigation() {
  return apiRequest<BottomNavigationConfig>({ path: '/app-navigation' })
}

export function openPrimaryNavigation(target: NavigationTarget) {
  uni.reLaunch({ url: navigationRoutes[target] || navigationRoutes.home })
}
