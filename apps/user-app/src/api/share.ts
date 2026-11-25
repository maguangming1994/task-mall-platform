import { apiRequest } from '@/api/http'

/** 分享二维码目标类型，与后端 ShareQrCodeController 白名单保持一致 */
export type ShareQrTargetType = 'community_post' | 'task' | 'product' | 'material' | 'second_hand' | 'referral'

export interface ShareQrCodeResponse {
  /** mini：使用小程序码图片；h5：使用 link 在本地生成二维码 */
  mode: 'mini' | 'h5'
  image_url: string | null
  /** H5 端绘制用（data URI，避免跨域污染画布） */
  image_base64: string | null
  link: string | null
}

export function getShareQrCode(type: ShareQrTargetType, id: string) {
  const query = `type=${encodeURIComponent(type)}&id=${encodeURIComponent(id)}`

  return apiRequest<ShareQrCodeResponse>({ path: `/share/qrcode?${query}` })
}
