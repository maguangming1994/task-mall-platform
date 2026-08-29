import { defineStore } from 'pinia'
import { listCampuses, selectAccountCampus, type Campus } from '@/api/campuses'

export const campusStorageKey = 'task_mall_selected_campus'

function storedCampus(): Campus | null {
  const value = uni.getStorageSync(campusStorageKey)
  return value && typeof value === 'object' && typeof value.id === 'string' ? value as Campus : null
}

export const useCampusStore = defineStore('campus', {
  state: () => ({
    selected: storedCampus(),
    items: [] as Campus[],
    loading: false,
  }),
  actions: {
    async load(params: { keyword?: string; latitude?: number; longitude?: number } = {}) {
      this.loading = true
      try {
        const result = await listCampuses(params)
        this.items = result.items
        if (!this.selected && result.items.length === 1) this.setLocal(result.items[0])
        return result.items
      } finally {
        this.loading = false
      }
    },
    syncFromAccount(campus: Campus | null | undefined) {
      if (campus?.id) this.setLocal(campus)
    },
    async select(campus: Campus, accessToken = '') {
      this.setLocal(campus)
      if (accessToken) await selectAccountCampus(campus.id, accessToken)
    },
    async selectPromotionCampus(value: string, accessToken = '') {
      const hint = value.trim().toLowerCase()
      if (!hint) return null
      const items = this.items.length ? this.items : await this.load()
      const match = items.find(item => item.code.toLowerCase() === hint || item.id.toLowerCase() === hint)
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
