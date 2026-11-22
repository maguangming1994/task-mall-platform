import { defineStore } from 'pinia'
import {
  adminLogin, adminLogout, adminVerifyMfa, fetchAdminMe,
  type AdminLoginResult, type AdminMfaChallenge, type AdminProfile,
} from '@/api/admin/auth'
import { clearAdminSession, loadAdminSession } from '@/api/admin/http'
import { loginByWechat } from '@/api/admin/wechat'

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

function isMfaChallenge(result: AdminLoginResult | AdminMfaChallenge): result is AdminMfaChallenge {
  return 'mfa_required' in result && result.mfa_required === true
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
    canManageMaterial(): boolean {
      return this.permissions.includes('material.manage')
    },
    canViewPush(): boolean {
      return this.permissions.includes('push.view')
    },
    canReviewPush(): boolean {
      return this.permissions.includes('push.view') && this.permissions.includes('push.review')
    },
    canResolveIdentityConflict(): boolean {
      return this.permissions.includes('identity.resolve')
    },
    /** 工作台是否有任一审核入口可看 */
    hasAnyReviewAccess(): boolean {
      return this.canReviewCommunity || this.canReviewTask || this.canReviewIdentity
        || this.canReviewAfterSale || this.canManageMaterial || this.canReviewPush
        || this.canResolveIdentityConflict
    },
  },
  actions: {
    async login(account: string, password: string) {
      this.submitting = true
      try {
        const result = await adminLogin(account.trim(), password)
        if (!isMfaChallenge(result)) this.applySession(result)
        return result
      } finally {
        this.submitting = false
      }
    },
    /** 二次验证：用登录返回的挑战换取正式会话 */
    async verifyMfa(challenge: AdminMfaChallenge, code: string) {
      this.submitting = true
      try {
        const result = await adminVerifyMfa(challenge, code.trim())
        this.applySession(result)
        return result
      } finally {
        this.submitting = false
      }
    },
    /** 公众号免密登录：微信授权 code 匹配已绑定账号 */
    async loginWithWechat(code: string) {
      this.submitting = true
      try {
        const result = await loginByWechat(code)
        if (!isMfaChallenge(result)) this.applySession(result)
        return result
      } finally {
        this.submitting = false
      }
    },
    applySession(result: AdminLoginResult) {
      this.accessToken = result.access_token
      this.refreshToken = result.refresh_token
      this.expiresAt = Date.now() + result.expires_in * 1000
      this.profile = result.admin
      this.persist()
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
