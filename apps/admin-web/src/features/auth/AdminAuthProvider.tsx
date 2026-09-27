import { useEffect, useMemo, useState, type ReactNode } from 'react'
import {
  ApiError,
  clearTokens,
  hasStoredSession,
  loadSession,
  login as loginRequest,
  verifyAdminMfa,
  logout as logoutRequest,
  type AdminProfile,
  type NavigationItem,
} from '../../api/admin'
import { AdminAuthContext, type AdminAuthContextValue } from './AdminAuthContext'

export function AdminAuthProvider({ children }: { children: ReactNode }) {
  const [admin, setAdmin] = useState<AdminProfile | null>(null)
  const [navigation, setNavigation] = useState<NavigationItem[]>([])
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    if (!hasStoredSession()) {
      setLoading(false)
      return
    }

    loadSession()
      .then((session) => {
        setAdmin(session.admin)
        setNavigation(session.navigation)
      })
      .catch((cause) => {
        if (cause instanceof ApiError && cause.status === 401) clearTokens()
      })
      .finally(() => setLoading(false))
  }, [])

  const value = useMemo<AdminAuthContextValue>(() => ({
    admin,
    navigation,
    loading,
    login: async (account, password, remember) => {
      const session = await loginRequest(account, password, remember)
      if ('mfa_required' in session) return session
      setAdmin(session.admin)
      setNavigation(session.navigation)
      return null
    },
    verifyMfa: async (challenge, code, remember) => {
      const session = await verifyAdminMfa(challenge, code, remember)
      setAdmin(session.admin)
      setNavigation(session.navigation)
    },
    logout: async () => {
      try {
        await logoutRequest()
      } finally {
        setAdmin(null)
        setNavigation([])
      }
    },
  }), [admin, navigation, loading])

  return <AdminAuthContext.Provider value={value}>{children}</AdminAuthContext.Provider>
}
