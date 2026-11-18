import { defineStore } from 'pinia'
import {
  loadClientCapabilities,
  type CampusCapability,
  type CampusChangeCapability,
  type CampusProfileCapability,
} from '@/platform/capabilities'

/** 能力缓存有效期：切校 / 绑校后会显式 force 刷新 */
const FRESH_MS = 30_000

let latestSeq = 0

/**
 * 账号维度的校区状态（campus_required / campus / campus_change / campus_profile）。
 * 与 campus store（学校列表 + 本地选校）分离：这里只反映服务端账号归属。
 */
export const useCampusProfileStore = defineStore('campus-profile', {
  state: () => ({
    required: false,
    campus: null as CampusCapability | null,
    change: null as CampusChangeCapability | null,
    profile: null as CampusProfileCapability | null,
    loaded: false,
    loading: false,
    loadedAt: 0,
    pending: null as Promise<void> | null,
  }),
  getters: {
    isolationMode: state => state.campus?.isolation_mode ?? 'legacy',
    isStrict: state => state.campus?.isolation_mode === 'strict',
    campusName: state => state.campus?.name ?? '',
    canChange: state => state.change?.can_change !== false,
  },
  actions: {
    async load(force = false) {
      if (!force && this.loaded && Date.now() - this.loadedAt < FRESH_MS) return
      if (this.pending && !force) {
        await this.pending
        return
      }

      const seq = ++latestSeq
      this.loading = true
      const promise = (async () => {
        try {
          const caps = await loadClientCapabilities()
          // 在途期间发生了新的强制刷新：丢弃旧响应
          if (seq !== latestSeq) return
          this.required = Boolean(caps.campus_required)
          this.campus = caps.campus ?? null
          this.change = caps.campus_change ?? null
          this.profile = caps.campus_profile ?? null
          this.loaded = true
          this.loadedAt = Date.now()
        } catch {
          // 能力接口失败时保留上一次结果，避免误触发绑校拦截
        } finally {
          if (seq === latestSeq) {
            this.loading = false
            this.pending = null
          }
        }
      })()
      this.pending = promise
      await promise
    },
    ensure() {
      if (!this.loaded) return this.load()
    },
    clear() {
      this.required = false
      this.campus = null
      this.change = null
      this.profile = null
      this.loaded = false
      this.loadedAt = 0
    },
  },
})
