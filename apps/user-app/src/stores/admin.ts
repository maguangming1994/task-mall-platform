import { defineStore } from 'pinia'
import { adminLogin, adminLogout, fetchAdminMe, type AdminProfile } from '@/api/admin/auth'
import { clearAdminSession, loadAdminSession } from '@/api/admin/http'

interface StoredAdminState {
  accessToken: string
  refreshToken: string
  expiresAt: number
  profile: AdminProfile
}

const storageKey = 'task_mall_admin_session'

function loadStored(): StoredAdminState | null {
  const stored = uni.getStorageSync(storageKey)
  return stored && typeof stored === 'object' ? stored as StoredAdminState : null
}

/**
 * 管理员会话 store：与用户会话（stores/auth）完全独立。
 * 审核工作台页面统一从这里取令牌与权限。
 */
export const useAdminStore = defineStore('admin', {
  state: () => {
    const stored = loadStored()
    return {
      accessToken: stored?.accessToken || '',
      refreshToken: stored?.refreshToken || '',
      expiresAt: stored?.expiresAt || 0,
      profile: stored?.profile || null as AdminProfile | null,
      submitting: false,
    }
  },
  getters: {
    isLoggedIn: state => Boolean(state.accessToken && state.profile),
    displayName: state => state.profile?.display_name || state.profile?.account || '管理员',
    roleNames: state => state.profile?.roles || [],
    permissions: state => state.profile?.permissions || [],
    canReviewCommunity(): boolean {
      return this.permissions.includes('community.review')
    },
    canReviewTask(): boolean {
      return this.permissions.includes('task.review')
    },
    canReviewIdentity(): boolean {
      return this.permissions.includes('identity.review')
    },
    canReviewAfterSale(): boolean {
      return this.permissions.includes('after_sale.review')
    },
    /** 工作台是否有任一审核入口可看 */
    hasAnyReviewAccess(): boolean {
      return this.canReviewCommunity || this.canReviewTask || this.canReviewIdentity || this.canReviewAfterSale
    },
  },
  actions: {
    async login(account: string, password: string) {
      this.submitting = true
      try {
        const result = await adminLogin(account.trim(), password)
        this.accessToken = result.access_token
        this.refreshToken = result.refresh_token
        this.expiresAt = Date.now() + result.expires_in * 1000
        this.profile = result.admin
        this.persist()
        return result.admin
      } finally {
        this.submitting = false
      }
    },
    async fetchMe() {
      if (!this.accessToken) return
      this.profile = await fetchAdminMe()
      this.persist()
    },
    async logout() {
      if (this.accessToken) {
        try {
          await adminLogout()
        } catch {
          // 本地凭证仍清除，过期会话由后台任务回收
        }
      }
      this.clear()
    },
    persist() {
      if (!this.profile) return
      // http 层刷新令牌时按同结构合并，档案随会话一起持久化
      uni.setStorageSync(storageKey, {
        accessToken: this.accessToken,
        refreshToken: this.refreshToken,
        expiresAt: this.expiresAt,
        profile: this.profile,
      })
    },
    clear() {
      this.accessToken = ''
      this.refreshToken = ''
      this.expiresAt = 0
      this.profile = null
      clearAdminSession()
    },
  },
})
