import { defineStore } from 'pinia'
import { loadClientCapabilities, type ModulePlaceholderCopy } from '@/platform/capabilities'
import { MODULE_CODES, type ModuleCode, MODULE_REGISTRY } from '@/platform/modules'

const STORAGE_KEY = 'task_mall_module_flags'

interface StoredFlags {
  flags: Partial<Record<ModuleCode, boolean>>
  placeholders: Partial<Record<ModuleCode, ModulePlaceholderCopy>>
  savedAt: number
}

function isValidStoredFlags(raw: unknown): raw is StoredFlags {
  if (!raw || typeof raw !== 'object') return false
  const obj = raw as Record<string, unknown>
  if (!obj.flags || typeof obj.flags !== 'object') return false
  if (typeof obj.savedAt !== 'number') return false
  return true
}

function defaultPlaceholder(code: ModuleCode): ModulePlaceholderCopy {
  return { title: '功能升级中', desc: `${MODULE_REGISTRY[code].name}正在升级优化，稍作等待，精彩马上回来。` }
}

export const useModulesStore = defineStore('app-modules', {
  state: () => ({
    flags: {} as Partial<Record<ModuleCode, boolean>>,
    placeholders: {} as Partial<Record<ModuleCode, ModulePlaceholderCopy>>,
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
          this.placeholders = raw.placeholders || {}
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
        this.placeholders = caps.module_placeholders || {}
        try {
          uni.setStorageSync(STORAGE_KEY, { flags: next, placeholders: this.placeholders, savedAt: Date.now() })
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
    placeholder(code: ModuleCode): ModulePlaceholderCopy {
      return this.placeholders[code] || defaultPlaceholder(code)
    },
  },
})
