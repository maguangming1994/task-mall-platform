import { apiRequest } from '@/api/http'

export type CampusLocationAccuracy = 'campus' | 'city' | 'unavailable'

export interface Campus {
  id: string
  code: string
  name: string
  emblem_url: string | null
  logo_url: string | null
  province: string | null
  city: string | null
  district: string | null
  address: string | null
  latitude: number | null
  longitude: number | null
  distance_km: number | null
  location_accuracy?: CampusLocationAccuracy
}

export interface CampusListParams {
  keyword?: string
  province?: string
  limit?: number
}

export interface NearbyCampusParams extends CampusListParams {
  latitude: number
  longitude: number
}

export function listCampuses(params: CampusListParams = {}) {
  const query = Object.entries(params)
    .filter(([, value]) => value !== undefined && value !== '')
    .map(([key, value]) => `${encodeURIComponent(key)}=${encodeURIComponent(String(value))}`)
    .join('&')

  return apiRequest<{ items: Campus[] }>({ path: `/campuses${query ? `?${query}` : ''}` })
}

export function listNearbyCampuses(params: NearbyCampusParams) {
  return apiRequest<{ items: Campus[] }>({
    path: '/campuses/nearby',
    method: 'POST',
    data: { ...params },
  })
}

export function selectAccountCampus(campusId: string, accessToken: string) {
  return apiRequest<Campus>({
    path: '/me/campus',
    method: 'PUT',
    accessToken,
    data: { campus_id: campusId },
  })
}
