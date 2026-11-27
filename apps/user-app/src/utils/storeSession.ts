import type { StoreCampus, StoreManagerSession } from '@/api/stores'

const storageKey = 'task_mall_store_manager_session'
const campusStorageKey = (storeId: string) => `store_campus_${storeId}`

export interface StoreSessionCache {
  storeId: string
  token: string
  storeName: string
  expiresAt: string
  role: StoreManagerSession['member']['role']
  canManage: boolean
  campuses?: StoreCampus[]
}

export function getStoreSession(): StoreSessionCache | null {
  const raw = uni.getStorageSync(storageKey)
  if (!raw || typeof raw !== 'object') return null
  const session = raw as StoreSessionCache
  if (!session.token || !session.storeId) return null
  if (session.expiresAt && new Date(session.expiresAt).getTime() <= Date.now()) {
    clearStoreSession()
    return null
  }

  return session
}

export function saveStoreSession(session: StoreManagerSession): StoreSessionCache {
  const cache: StoreSessionCache = {
    storeId: session.store.id,
    token: session.access_token,
    storeName: session.store.name,
    expiresAt: session.expires_at,
    role: session.member.role,
    canManage: session.member.can_manage,
    campuses: session.store.campuses || [],
  }
  uni.setStorageSync(storageKey, cache)
  getSelectedStoreCampus(cache.storeId, cache.campuses)

  return cache
}

export function clearStoreSession() {
  uni.removeStorageSync(storageKey)
}

export function requireStoreSession(): StoreSessionCache | null {
  const session = getStoreSession()
  if (!session) {
    uni.redirectTo({ url: '/pages-store/index' })
    return null
  }

  return session
}

export function saveSelectedStoreCampus(storeId: string, campus: StoreCampus) {
  uni.setStorageSync(campusStorageKey(storeId), campus)
}

export function getSelectedStoreCampus(storeId: string, campuses?: StoreCampus[]): StoreCampus | null {
  const cached = uni.getStorageSync(campusStorageKey(storeId)) as StoreCampus | null
  const cachedId = cached && typeof cached === 'object' ? cached.campus_id : ''
  let availableCampuses = campuses
  if (availableCampuses === undefined) {
    const session = getStoreSession()
    if (session?.storeId === storeId) availableCampuses = session.campuses
  }

  if (availableCampuses !== undefined) {
    const selected = availableCampuses.find((campus) => campus.campus_id === cachedId)
      || availableCampuses.find((campus) => campus.is_primary)
      || availableCampuses[0]
    if (selected) {
      if (selected.campus_id !== cachedId) saveSelectedStoreCampus(storeId, selected)
      return selected
    }
    if (cachedId) uni.removeStorageSync(campusStorageKey(storeId))
    return null
  }

  return cachedId ? cached : null
}

export function syncStoreCampuses(storeId: string, campuses: StoreCampus[]): StoreCampus | null {
  const session = getStoreSession()
  if (session?.storeId === storeId) uni.setStorageSync(storageKey, { ...session, campuses })
  return getSelectedStoreCampus(storeId, campuses)
}
