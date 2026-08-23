import { defineStore } from 'pinia'
import { ApiError, apiRequest } from '@/api/http'

export interface CurrentUser {
  id: string
  nickname: string
  avatar_url: string | null
  avatar_file_id: string | null
  phone_masked: string | null
  phone_bound: boolean
  status: 'active' | 'disabled' | 'cancelled'
  profile_completed: boolean
}

interface AuthTokens {
  access_token: string
  refresh_token: string
  token_type: 'Bearer'
  expires_in: number
  user: CurrentUser
}

interface StoredAuth {
  accessToken: string
  refreshToken: string
  expiresAt: number
  user: CurrentUser
}

const storageKey = 'task_mall_auth'

function loadStoredAuth(): StoredAuth | null {
  const stored = uni.getStorageSync(storageKey)
  return stored && typeof stored === 'object' ? stored as StoredAuth : null
}

export const useAuthStore = defineStore('auth', {
  state: () => {
    const stored = loadStoredAuth()
    return {
      accessToken: stored?.accessToken || '',
      refreshToken: stored?.refreshToken || '',
      expiresAt: stored?.expiresAt || 0,
      user: stored?.user || null as CurrentUser | null,
      submitting: false,
    }
  },
  getters: {
    isAuthenticated: state => Boolean(state.accessToken && state.user),
  },
  actions: {
    async requestPhoneCode(phone: string, purpose: 'login' | 'bind_phone' = 'login') {
      await apiRequest<void>({
        path: '/auth/phone/verification-codes',
        method: 'POST',
        data: { phone, purpose },
      })
    },
    async loginWithPhone(phone: string, verificationCode: string) {
      this.submitting = true
      try {
        const tokens = await apiRequest<AuthTokens>({
          path: '/auth/phone/login',
          method: 'POST',
          data: { phone, verification_code: verificationCode },
        })
        this.applyTokens(tokens)
      } finally {
        this.submitting = false
      }
    },
    async loginWithWechat(channel: 'mini' | 'h5', code: string) {
      this.submitting = true
      try {
        const tokens = await apiRequest<AuthTokens>({
          path: channel === 'mini' ? '/auth/wechat-mini/login' : '/auth/wechat-h5/login',
          method: 'POST',
          data: { code },
        })
        this.applyTokens(tokens)

        return tokens.user
      } finally {
        this.submitting = false
      }
    },
    async bindPhone(phone: string, verificationCode: string) {
      if (!this.accessToken) throw new Error('请先完成微信登录。')
      this.submitting = true
      try {
        this.user = await apiRequest<CurrentUser>({
          path: '/me/phone',
          method: 'PUT',
          accessToken: this.accessToken,
          data: { new_phone: phone, new_phone_verification_code: verificationCode },
        })
        this.persist()

        return this.user
      } finally {
        this.submitting = false
      }
    },
    async bindWechatPhone(phoneCode: string) {
      if (!this.accessToken) throw new Error('请先完成微信登录。')
      this.submitting = true
      try {
        this.user = await apiRequest<CurrentUser>({
          path: '/me/phone/wechat',
          method: 'PUT',
          accessToken: this.accessToken,
          data: { code: phoneCode },
        })
        this.persist()

        return this.user
      } finally {
        this.submitting = false
      }
    },
    async fetchCurrentUser() {
      if (!this.accessToken) return
      try {
        if (this.expiresAt <= Date.now() && this.refreshToken) {
          await this.refreshSession()
        }
        this.user = await apiRequest<CurrentUser>({
          path: '/me',
          accessToken: this.accessToken,
        })
        this.persist()
      } catch (error) {
        if (error instanceof ApiError && error.statusCode === 401 && this.refreshToken) {
          try {
            await this.refreshSession()
            this.user = await apiRequest<CurrentUser>({
              path: '/me',
              accessToken: this.accessToken,
            })
            this.persist()
            return
          } catch {
            // 刷新失败后统一清理本地凭证。
          }
        }
        this.clear()
      }
    },
    async refreshSession() {
      const tokens = await apiRequest<AuthTokens>({
        path: '/auth/refresh',
        method: 'POST',
        data: { refresh_token: this.refreshToken },
      })
      this.accessToken = tokens.access_token
      this.refreshToken = tokens.refresh_token
      this.expiresAt = Date.now() + tokens.expires_in * 1000
      this.user = tokens.user
      this.persist()
    },
    async logout() {
      if (this.accessToken) {
        try {
          await apiRequest<void>({
            path: '/auth/logout',
            method: 'POST',
            accessToken: this.accessToken,
          })
        } catch {
          // 本地凭证仍需清除，服务端过期会话由后台任务回收。
        }
      }
      this.clear()
    },
    persist() {
      if (!this.user) return
      uni.setStorageSync(storageKey, {
        accessToken: this.accessToken,
        refreshToken: this.refreshToken,
        expiresAt: this.expiresAt,
        user: this.user,
      })
    },
    applyTokens(tokens: AuthTokens) {
      this.accessToken = tokens.access_token
      this.refreshToken = tokens.refresh_token
      this.expiresAt = Date.now() + tokens.expires_in * 1000
      this.user = tokens.user
      this.persist()
    },
    clear() {
      this.accessToken = ''
      this.refreshToken = ''
      this.expiresAt = 0
      this.user = null
      uni.removeStorageSync(storageKey)
    },
  },
})
