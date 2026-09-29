import { defineStore } from 'pinia'
import { loadClientCapabilities } from '@/platform/capabilities'
import { MODULE_CODES, type ModuleCode, MODULE_REGISTRY } from '@/platform/modules'

const STORAGE_KEY = 'task_mall_module_flags'

interface StoredFlags {
  flags: Partial<Record<ModuleCode, boolean>>
  savedAt: number
}

function isValidStoredFlags(raw: unknown): raw is StoredFlags {
  if (!raw || typeof raw !== 'object') return false
  const obj = raw as Record<string, unknown>
  if (!obj.flags || typeof obj.flags !== 'object') return false
  if (typeof obj.savedAt !== 'number') return false
  return true
}

export const useModulesStore = defineStore('app-modules', {
  state: () => ({
    flags: {} as Partial<Record<ModuleCode, boolean>>,
    resolved: false,
    loadedAt: 0,
    loading: false,
    pending: null as Promise<void> | null,
  }),
  actions: {
    hydrate() {
      try {
        const raw = uni.getStorageSync(STORAGE_KEY)
        if (raw && isValidStoredFlags(raw)) {
          this.flags = raw.flags
          this.resolved = true
        }
      } catch {
        // ignore storage errors
      }
    },
    async load(force = false) {
      if (!force && this.resolved && Date.now() - this.loadedAt < 30000) return
      if (this.pending) return this.pending

      this.loading = true
      this.pending = this._doLoad()
      try {
        await this.pending
      } finally {
        this.loading = false
        this.pending = null
      }
    },
    async _doLoad() {
      try {
        const caps = await loadClientCapabilities()
        const incoming = caps.feature_flags || {}
        const next: Partial<Record<ModuleCode, boolean>> = {}
        for (const code of MODULE_CODES) {
          if (typeof incoming[code] === 'boolean') {
            next[code] = incoming[code]
          }
        }
        this.flags = next
        try {
          uni.setStorageSync(STORAGE_KEY, { flags: next, savedAt: Date.now() })
        } catch {
          // ignore storage errors
        }
        this.loadedAt = Date.now()
      } catch {
        // request failed: keep existing flags (fail-open)
        if (!this.resolved) {
          // first ever load failed: open all modules so user is not blocked
          this.flags = {}
        }
      } finally {
        this.resolved = true
      }
    },
    async ensureReady() {
      if (this.resolved) {
        void this.load(false)
        return
      }
      await this.load(true)
    },
    isEnabled(code: ModuleCode): boolean {
      // check self and parent chain
      let cur: ModuleCode | null = code
      while (cur) {
        const key: ModuleCode = cur
        const val = this.flags[key]
        // undefined = not yet received = fail-open (treat as enabled)
        if (val === false) return false
        cur = MODULE_REGISTRY[key].parent
      }
      return true
    },
  },
})
