import { defineStore } from 'pinia'
import { loadIconConfigs } from '@/api/icons'
import { resolveApiAssetUrl } from '@/api/http'
import {
  normalizeIconConfigs,
  resolveIconLabel,
  resolveIconSrc,
  type IconConfigMap,
} from '@/utils/iconConfig'

const STORAGE_KEY = 'task_mall_icon_configs_v1'
const REFRESH_TTL_MS = 30000

interface StoredIconConfigs {
  map: IconConfigMap
  savedAt: number
}

function isValidStored(raw: unknown): raw is StoredIconConfigs {
  if (!raw || typeof raw !== 'object') return false
  const data = raw as Record<string, unknown>
  return Boolean(data.map && typeof data.map === 'object' && typeof data.savedAt === 'number')
}

export const useIconConfigStore = defineStore('app-icon-configs', {
  state: () => ({
    map: {} as IconConfigMap,
    resolved: false,
    loadedAt: 0,
    loading: false,
    pending: null as Promise<void> | null,
  }),
  actions: {
    /** 冷启动同步恢复上次缓存，保证首屏即可使用后台配置（无需等待网络）。 */
    hydrate() {
      try {
        const raw = uni.getStorageSync(STORAGE_KEY)
        if (raw && isValidStored(raw)) {
          this.map = raw.map
          this.resolved = true
        }
      } catch {
        // 存储不可用时静默降级到包内默认图标。
      }
    },
    async load(force = false) {
      if (!force && this.resolved && Date.now() - this.loadedAt < REFRESH_TTL_MS) return
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
        const result = await loadIconConfigs()
        const next = normalizeIconConfigs(result?.items)
        this.map = next
        this.resolved = true
        this.loadedAt = Date.now()
        try {
          uni.setStorageSync(STORAGE_KEY, { map: next, savedAt: Date.now() })
        } catch {
          // 缓存写入失败不影响本次运行。
        }
      } catch {
        // 拉取失败：沿用缓存或包内默认图标，不阻断页面。
      }
    },
    /** 解析图标最终地址：装修远程图 > 后台图标配置 > 包内静态图。 */
    resolve(src: string): string {
      return resolveIconSrc(src, this.map, resolveApiAssetUrl)
    },
    /** 解析展示文字：后台配置仅在入口仍使用包内图标时生效。 */
    label(fallbackLabel: string, localSrc?: string): string {
      return resolveIconLabel(fallbackLabel, this.map, localSrc)
    },
  },
})
