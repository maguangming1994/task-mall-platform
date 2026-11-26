import { defineStore } from 'pinia'
import { loadClientCapabilities, type ModulePlaceholderCopy } from '@/platform/capabilities'
import { MODULE_CODES, type ModuleCode, MODULE_REGISTRY } from '@/platform/modules'
import { campusStorageKey } from './campus'

const STORAGE_KEY = 'task_mall_module_flags'

interface StoredFlags {
  /** 缓存所属校区 id；切换校区后旧缓存不得复用 */
  campusId: string
  flags: Partial<Record<ModuleCode, boolean>>
  placeholders: Partial<Record<ModuleCode, ModulePlaceholderCopy>>
  /** 小程序审核中：钱包相关文案与入口需按积分呈现 */
  reviewing?: boolean
  /** 外部订阅与投递；缺值时保持关闭 */
  externalNotifications?: boolean
  savedAt: number
}

/** 当前本地选中的校区 id（直接读缓存，避免 store 间循环依赖） */
function currentCampusId(): string {
  const campus = uni.getStorageSync(campusStorageKey)
  return campus && typeof campus === 'object' && typeof campus.id === 'string' ? campus.id : ''
}

function isValidStoredFlags(raw: unknown): raw is StoredFlags {
  if (!raw || typeof raw !== 'object') return false
  const obj = raw as Record<string, unknown>
  if (!obj.flags || typeof obj.flags !== 'object') return false
  if (typeof obj.savedAt !== 'number') return false
  return true
}

function defaultPlaceholder(code: ModuleCode): ModulePlaceholderCopy {
  return { title: '开发中', desc: `${MODULE_REGISTRY[code].name}正在开发中，敬请期待。` }
}

export const useModulesStore = defineStore('app-modules', {
  state: () => ({
    flags: {} as Partial<Record<ModuleCode, boolean>>,
    placeholders: {} as Partial<Record<ModuleCode, ModulePlaceholderCopy>>,
    reviewing: false,
    externalNotifications: false,
    resolved: false,
    loadedAt: 0,
    loading: false,
    pending: null as Promise<void> | null,
    campusId: '',
    // 请求序号：切换校区强制刷新时，作废在途的旧校区响应，防止其晚返回覆盖新开关
    loadSeq: 0,
  }),
  actions: {
    hydrate() {
      try {
        const raw = uni.getStorageSync(STORAGE_KEY)
        // 仅当缓存与当前选中校区一致时才复用，防止切校区后沿用旧开关
        if (raw && isValidStoredFlags(raw) && raw.campusId === currentCampusId()) {
          this.flags = raw.flags
          this.placeholders = raw.placeholders || {}
          this.reviewing = raw.reviewing === true
          this.externalNotifications = raw.externalNotifications === true
          this.campusId = raw.campusId
          this.resolved = true
        }
      } catch {
        // ignore storage errors
      }
    },
    /**
     * 切换校区：立即进入未解析态（受控页展示骨架而非旧校区开关），
     * 并强制重新拉取新校区的 capabilities。
     */
    reloadForCampus() {
      this.resolved = false
      this.flags = {}
      this.placeholders = {}
      this.reviewing = false
      this.externalNotifications = false
      this.loadedAt = 0
      this.pending = null
      void this.load(true)
    },
    async load(force = false) {
      if (!force && this.resolved && Date.now() - this.loadedAt < 30000) return
      // 非强制刷新时复用在途请求；强制刷新（如切校区）允许并发新请求，旧请求由 loadSeq 作废
      if (this.pending && !force) return this.pending

      const seq = ++this.loadSeq
      this.loading = true
      const promise = this._doLoad(seq)
      this.pending = promise
      try {
        await promise
      } finally {
        // 仅最新一次请求负责收尾，避免旧请求清空新请求的 pending/loading
        if (this.loadSeq === seq) {
          this.loading = false
          this.pending = null
        }
      }
    },
    async _doLoad(seq: number) {
      try {
        const caps = await loadClientCapabilities()
        // 在途期间发生了切校区/强制刷新：丢弃旧校区响应
        if (seq !== this.loadSeq) return
        const incoming = caps.feature_flags || {}
        const next: Partial<Record<ModuleCode, boolean>> = {}
        for (const code of MODULE_CODES) {
          if (typeof incoming[code] === 'boolean') {
            next[code] = incoming[code]
          }
        }
        this.flags = next
        this.placeholders = caps.module_placeholders || {}
        this.reviewing = caps.review_status === 'reviewing'
        this.externalNotifications = incoming.external_notifications === true
        const campusId = caps.campus_id ?? currentCampusId()
        this.campusId = campusId ?? ''
        try {
          uni.setStorageSync(STORAGE_KEY, { campusId: this.campusId, flags: next, placeholders: this.placeholders, reviewing: this.reviewing, externalNotifications: this.externalNotifications, savedAt: Date.now() })
        } catch {
          // ignore storage errors
        }
        this.loadedAt = Date.now()
      } catch {
        if (seq !== this.loadSeq) return
        // request failed: keep existing flags (fail-open)
        if (!this.resolved) {
          // first ever load failed: open all modules so user is not blocked
          this.flags = {}
        }
      } finally {
        if (seq === this.loadSeq) this.resolved = true
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
    /**
     * 仅当服务端明确开启时才展示：用于「暂未上线」功能的入口隐藏。
     * 与 isEnabled 不同，缺失开关记录（未下发/请求失败）时保持隐藏（fail-close）。
     */
    isLaunched(code: ModuleCode): boolean {
      let cur: ModuleCode | null = code
      while (cur) {
        const key: ModuleCode = cur
        if (this.flags[key] !== true) return false
        cur = MODULE_REGISTRY[key].parent
      }
      return true
    },
    placeholder(code: ModuleCode): ModulePlaceholderCopy {
      return this.placeholders[code] || defaultPlaceholder(code)
    },
  },
})
