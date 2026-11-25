import { resolveApiAssetUrl } from '@/api/http'
import { getShareQrCode, type ShareQrTargetType } from '@/api/share'

/** 海报二维码渲染数据：小程序码优先，拿不到时退回 H5 链接本地生成二维码 */
export interface PosterQr {
  /** 小程序码图片临时路径 */
  imagePath?: string
  /** 回退用的 H5 链接 */
  link?: string
}

const REQUEST_TIMEOUT_MS = 6000

/**
 * 解析海报二维码。
 *
 * 优先使用后端生成的小程序码（微信扫码直接进入小程序对应页面）；
 * 小程序未配置/未发布或接口异常时使用 H5 链接，由海报在本地绘制普通二维码。
 */
export async function loadPosterQr(
  type: ShareQrTargetType | undefined,
  id: string | undefined,
  fallbackLink: string,
): Promise<PosterQr> {
  if (type && id) {
    try {
      const response = await withTimeout(getShareQrCode(type, id))
      if (response.mode === 'mini') {
        const source = miniCodeSource(response.image_base64, response.image_url)
        const imagePath = await toTempPath(resolveApiAssetUrl(source))
        if (imagePath) return { imagePath }
      }
      if (response.link) return { link: response.link }
    } catch {
      // 接口失败时静默回退到 H5 二维码，不阻塞海报生成
    }
  }

  return { link: fallbackLink }
}

function withTimeout<T>(task: Promise<T>): Promise<T> {
  return new Promise<T>((resolve, reject) => {
    const timer = setTimeout(() => reject(new Error('分享二维码请求超时')), REQUEST_TIMEOUT_MS)
    task.then(
      (value) => { clearTimeout(timer); resolve(value) },
      (error) => { clearTimeout(timer); reject(error) },
    )
  })
}

/** H5 用 base64 绘制避免画布跨域污染；小程序用 HTTP 地址（getImageInfo 更稳定） */
function miniCodeSource(base64: string | null, url: string | null): string {
  // #ifdef H5
  if (base64) return base64
  // #endif
  return url || ''
}

function toTempPath(src: string): Promise<string> {
  if (!src) return Promise.resolve('')

  return new Promise((resolve) => {
    uni.getImageInfo({
      src,
      success: (info) => resolve(info.path || ''),
      fail: () => resolve(''),
    })
  })
}
