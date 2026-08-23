import { defineStore } from 'pinia'
import { loadBottomNavigation, type BottomNavigationConfig, type NavigationTarget } from '@/api/navigation'

const defaultItems: BottomNavigationConfig['items'] = [
  { id: 'home', label: '首页', target: 'home', enabled: true, icon_url: '', active_icon_url: '' },
  { id: 'tasks', label: '任务', target: 'tasks', enabled: true, icon_url: '', active_icon_url: '' },
  { id: 'products', label: '积分', target: 'products', enabled: true, icon_url: '', active_icon_url: '' },
  { id: 'community', label: '社区', target: 'community', enabled: true, icon_url: '', active_icon_url: '' },
  { id: 'account', label: '我的', target: 'account', enabled: true, icon_url: '', active_icon_url: '' },
]

const fallbackConfig: BottomNavigationConfig = {
  items: defaultItems,
  style: { background_color: '#FFFFFF', text_color: '#7A859B', active_color: '#12A66A', border_color: '#E7EBF2' },
  version: null,
}

const allowedTargets: NavigationTarget[] = ['home', 'tasks', 'products', 'community', 'account']
const defaultByTarget = new Map(defaultItems.map((item) => [item.target, item]))

function safeColor(value: unknown, fallback: string) {
  return typeof value === 'string' && /^(#[0-9a-f]{3,8}|rgba?\([\d\s.,%]+\))$/i.test(value.trim())
    ? value.trim()
    : fallback
}

function safeIconUrl(value: unknown) {
  if (typeof value !== 'string') return ''
  const url = value.trim()
  return url.startsWith('/') || /^https:\/\//i.test(url) || /^http:\/\/(127\.0\.0\.1|localhost)(:\d+)?\//i.test(url) ? url : ''
}

function normalize(config: BottomNavigationConfig): BottomNavigationConfig {
  const items = Array.isArray(config.items)
    ? config.items
      .filter((item) => item && allowedTargets.includes(item.target))
      .slice(0, 5)
      .map((item) => {
        const fallback = defaultByTarget.get(item.target)!
        return {
          ...item,
          id: String(item.id || fallback.id),
          label: String(item.label || '').trim() || fallback.label,
          icon_url: safeIconUrl(item.icon_url),
          active_icon_url: safeIconUrl(item.active_icon_url),
        }
      })
    : []
  if (items.filter((item) => item.enabled).length < 3) return fallbackConfig

  // 旧环境尚未发布过导航版本时，接口返回的是早期系统默认值。
  // 仅迁移未发布的默认配置；后台一旦发布过版本，就完全尊重后台文字和颜色。
  if (config.version === null) {
    return {
      ...config,
      items: items.map((item) => item.target === 'products' && item.label === '商城'
        ? { ...item, label: '积分' }
        : item),
      style: {
        background_color: safeColor(config.style?.background_color, fallbackConfig.style.background_color),
        text_color: safeColor(config.style?.text_color, fallbackConfig.style.text_color),
        active_color: config.style?.active_color === '#2F67DF'
          ? '#12A66A'
          : safeColor(config.style?.active_color, fallbackConfig.style.active_color),
        border_color: safeColor(config.style?.border_color, fallbackConfig.style.border_color),
      },
    }
  }

  return {
    ...config,
    items,
    style: {
      background_color: safeColor(config.style?.background_color, fallbackConfig.style.background_color),
      text_color: safeColor(config.style?.text_color, fallbackConfig.style.text_color),
      active_color: safeColor(config.style?.active_color, fallbackConfig.style.active_color),
      border_color: safeColor(config.style?.border_color, fallbackConfig.style.border_color),
    },
  }
}

export const useNavigationStore = defineStore('app-navigation', {
  state: () => ({ config: fallbackConfig as BottomNavigationConfig, loadedAt: 0, loading: false }),
  getters: {
    visibleItems: (state) => state.config.items.filter((item) => item.enabled),
  },
  actions: {
    async load(force = false) {
      if (this.loading || (!force && Date.now() - this.loadedAt < 30000)) return
      this.loading = true
      try {
        this.config = normalize(await loadBottomNavigation())
        this.loadedAt = Date.now()
      } catch {
        if (!this.loadedAt) this.config = fallbackConfig
      } finally {
        this.loading = false
      }
    },
  },
})
