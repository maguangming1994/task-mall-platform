import { defineStore } from 'pinia'
import {
  listCampuses,
  listNearbyCampuses,
  selectAccountCampus,
  type Campus,
  type CampusListParams,
  type NearbyCampusParams,
} from '@/api/campuses'

export const campusStorageKey = 'task_mall_selected_campus'

function storedCampus(): Campus | null {
  const value = uni.getStorageSync(campusStorageKey)
  return value && typeof value === 'object' && typeof value.id === 'string' ? value as Campus : null
}

function loadErrorMessage(error: unknown) {
  if (error instanceof Error && error.message.trim()) return error.message
  return '学校列表加载失败，请检查网络后重试。'
}

let latestLoadId = 0

export const useCampusStore = defineStore('campus', {
  state: () => ({
    selected: storedCampus(),
    items: [] as Campus[],
    loading: false,
    error: null as string | null,
    lastParams: {} as CampusListParams | NearbyCampusParams,
    lastLoadNearby: false,
  }),
  actions: {
    async load(params: CampusListParams = {}) {
      const loadId = ++latestLoadId
      this.lastParams = { ...params }
      this.lastLoadNearby = false
      this.loading = true
      this.error = null
      try {
        const result = await listCampuses(params)
        const items = Array.isArray(result.items) ? result.items : []
        if (loadId !== latestLoadId) return items
        this.items = items
        this.refreshSelectedBranding(items)
        return items
      } catch (error) {
        if (loadId !== latestLoadId) return this.items
        this.error = loadErrorMessage(error)
        throw error
      } finally {
        if (loadId === latestLoadId) this.loading = false
      }
    },
    async loadNearby(params: NearbyCampusParams) {
      const loadId = ++latestLoadId
      this.lastParams = { ...params }
      this.lastLoadNearby = true
      this.loading = true
      this.error = null
      try {
        const result = await listNearbyCampuses(params)
        const items = Array.isArray(result.items) ? result.items : []
        if (loadId !== latestLoadId) return items
        this.items = items
        this.refreshSelectedBranding(items)
        return items
      } catch (error) {
        if (loadId !== latestLoadId) return this.items
        this.error = loadErrorMessage(error)
        throw error
      } finally {
        if (loadId === latestLoadId) this.loading = false
      }
    },
    async retry() {
      return this.lastLoadNearby
        ? this.loadNearby(this.lastParams as NearbyCampusParams)
        : this.load(this.lastParams)
    },
    syncFromAccount(campus: Campus | null | undefined) {
      if (campus?.id) this.setLocal(campus)
    },
    refreshSelectedBranding(items: Campus[]) {
      if (!this.selected) return
      const current = items.find(item => item.id === this.selected?.id)
      if (!current) return
      const emblemUrl = 'emblem_url' in current ? current.emblem_url : this.selected.emblem_url
      const logoUrl = 'logo_url' in current ? current.logo_url : this.selected.logo_url
      if (emblemUrl === this.selected.emblem_url && logoUrl === this.selected.logo_url) return
      this.setLocal({ ...this.selected, emblem_url: emblemUrl, logo_url: logoUrl })
    },
    async select(campus: Campus, accessToken = '') {
      const selected = accessToken
        ? await selectAccountCampus(campus.id, accessToken)
        : campus
      this.setLocal(selected)
      return selected
    },
    async selectPromotionCampus(value: string, accessToken = '') {
      const rawValue = value.trim()
      const hint = rawValue.toLowerCase()
      if (!hint) return null
      let match = this.items.find(item => item.code.toLowerCase() === hint || item.id.toLowerCase() === hint)
      if (!match) {
        const keyword = /^[0-9a-hjkmnp-tv-z]{26}$/i.test(rawValue) ? rawValue.toUpperCase() : rawValue
        const result = await listCampuses({ keyword, limit: 10 })
        match = result.items.find(item => item.code.toLowerCase() === hint || item.id.toLowerCase() === hint)
      }
      if (!match) return null
      await this.select(match, accessToken)
      return match
    },
    setLocal(campus: Campus) {
      this.selected = campus
      uni.setStorageSync(campusStorageKey, campus)
    },
  },
})
