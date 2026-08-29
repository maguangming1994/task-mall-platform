import { apiRequest } from '@/api/http'

export interface Campus {
  id: string
  code: string
  name: string
  province: string | null
  city: string | null
  district: string | null
  address: string | null
  latitude: number | null
  longitude: number | null
  distance_km: number | null
}

export function listCampuses(params: { keyword?: string; latitude?: number; longitude?: number } = {}) {
  const query = Object.entries(params)
    .filter(([, value]) => value !== undefined && value !== '')
    .map(([key, value]) => `${encodeURIComponent(key)}=${encodeURIComponent(String(value))}`)
    .join('&')

  return apiRequest<{ items: Campus[] }>({ path: `/campuses${query ? `?${query}` : ''}` })
}

export function selectAccountCampus(campusId: string, accessToken: string) {
  return apiRequest<Campus>({
    path: '/me/campus',
    method: 'PUT',
    accessToken,
    data: { campus_id: campusId },
  })
}
