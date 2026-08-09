import { defineStore } from 'pinia'
import { loadBottomNavigation, type BottomNavigationConfig, type NavigationTarget } from '@/api/navigation'

const defaultItems: BottomNavigationConfig['items'] = [
  { id: 'home', label: '首页', target: 'home', enabled: true, icon_url: '', active_icon_url: '' },
  { id: 'tasks', label: '任务', target: 'tasks', enabled: true, icon_url: '', active_icon_url: '' },
  { id: 'products', label: '商城', target: 'products', enabled: true, icon_url: '', active_icon_url: '' },
  { id: 'community', label: '社区', target: 'community', enabled: true, icon_url: '', active_icon_url: '' },
  { id: 'account', label: '我的', target: 'account', enabled: true, icon_url: '', active_icon_url: '' },
]

const fallbackConfig: BottomNavigationConfig = {
  items: defaultItems,
  style: { background_color: '#FFFFFF', text_color: '#7A859B', active_color: '#2F67DF', border_color: '#E7EBF2' },
  version: null,
}

const allowedTargets: NavigationTarget[] = ['home', 'tasks', 'products', 'community', 'account']

function normalize(config: BottomNavigationConfig): BottomNavigationConfig {
  const items = Array.isArray(config.items)
    ? config.items.filter((item) => item && allowedTargets.includes(item.target)).slice(0, 5)
    : []
  return items.filter((item) => item.enabled).length >= 3 ? { ...config, items } : fallbackConfig
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
