import { useEffect, useMemo, useState, type ReactNode } from 'react'
import {
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
      .catch(() => clearTokens())
      .finally(() => setLoading(false))
  }, [])

  const value = useMemo<AdminAuthContextValue>(() => ({
    admin,
    navigation,
    loading,
    login: async (account, password) => {
      const session = await loginRequest(account, password)
      if ('mfa_required' in session) return session
      setAdmin(session.admin)
      setNavigation(session.navigation)
      return null
    },
    verifyMfa: async (challenge, code) => {
      const session = await verifyAdminMfa(challenge, code)
      setAdmin(session.admin)
      setNavigation(session.navigation)
    },
    logout: async () => {
      await logoutRequest()
      setAdmin(null)
      setNavigation([])
    },
  }), [admin, navigation, loading])

  return <AdminAuthContext.Provider value={value}>{children}</AdminAuthContext.Provider>
}
