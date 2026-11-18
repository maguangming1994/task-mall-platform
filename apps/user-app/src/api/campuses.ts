import { apiRequest } from '@/api/http'

export type CampusLocationAccuracy = 'campus' | 'reference' | 'city' | 'unavailable'

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
  location_name?: string
  coordinate_system?: 'WGS84'
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

export interface CampusBindingPayload {
  campus_id: string
  grade_year?: number
  major?: string
  college?: string
}

export interface CampusBindingResult extends Campus {
  /** 换校成功后需要清理业务缓存并重启 */
  require_restart?: boolean
}

/** 绑校 / 换校：每次提交都会写入年级、专业等档案字段 */
export function bindAccountCampus(payload: CampusBindingPayload, accessToken: string) {
  return apiRequest<CampusBindingResult>({
    path: '/me/campus',
    method: 'PUT',
    accessToken,
    data: { ...payload },
  })
}
