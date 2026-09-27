import { createContext, useContext } from 'react'
import type { AdminMfaChallenge, AdminProfile, NavigationItem } from '../../api/admin'

export interface AdminAuthContextValue {
  admin: AdminProfile | null
  navigation: NavigationItem[]
  loading: boolean
  login: (account: string, password: string, remember: boolean) => Promise<AdminMfaChallenge | null>
  verifyMfa: (challenge: AdminMfaChallenge, code: string, remember: boolean) => Promise<void>
  logout: () => Promise<void>
}

export const AdminAuthContext = createContext<AdminAuthContextValue | null>(null)

export function useAdminAuth() {
  const context = useContext(AdminAuthContext)
  if (!context) throw new Error('useAdminAuth must be used inside AdminAuthProvider')

  return context
}
