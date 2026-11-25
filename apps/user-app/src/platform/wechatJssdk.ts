/**
 * 公众号内 JS-SDK 分享。
 *
 * 仅微信内 H5 生效：小程序端由 onShareAppMessage / onShareTimeline 负责，
 * 微信外浏览器没有 JSSDK，调用会直接返回。
 */

import { apiRequest } from '@/api/http'

export interface WechatShareOptions {
  title: string
  desc?: string
  link: string
  imageUrl?: string
}

interface JssdkSignature {
  appId: string
  timestamp: number
  nonceStr: string
  signature: string
}

interface WechatJsSdk {
  config: (options: Record<string, unknown>) => void
  ready: (callback: () => void) => void
  error: (callback: (result: unknown) => void) => void
  updateAppMessageShareData?: (options: Record<string, unknown>) => void
  updateTimelineShareData?: (options: Record<string, unknown>) => void
  onMenuShareAppMessage?: (options: Record<string, unknown>) => void
  onMenuShareTimeline?: (options: Record<string, unknown>) => void
}

const SDK_SRC = 'https://res.wx.qq.com/open/js/jweixin-1.6.0.js'

/** 无封面内容分享时使用的默认卡片图。 */
export const DEFAULT_WECHAT_SHARE_IMAGE = '/static/home/hero-campus-reward.png'

let sdkPromise: Promise<WechatJsSdk | null> | null = null
let configuredSignature = ''

function isWechatBrowser(): boolean {
  return typeof navigator !== 'undefined' && /MicroMessenger/i.test(navigator.userAgent)
}

function loadSdk(): Promise<WechatJsSdk | null> {
  if (typeof window === 'undefined') return Promise.resolve(null)
  const existing = (window as unknown as { wx?: WechatJsSdk }).wx
  if (existing) return Promise.resolve(existing)
  if (sdkPromise) return sdkPromise

  sdkPromise = new Promise<WechatJsSdk | null>((resolve) => {
    const script = document.createElement('script')
    script.src = SDK_SRC
    script.async = true
    script.onload = () => resolve((window as unknown as { wx?: WechatJsSdk }).wx ?? null)
    script.onerror = () => resolve(null)
    document.head.appendChild(script)
  })

  return sdkPromise
}

/** 把站内路径转成微信分享可用的绝对地址（H5 hash 路由）。 */
export function toWechatShareLink(path: string): string {
  const value = String(path || '').trim()
  if (/^https?:\/\//i.test(value)) return value
  // #ifdef H5
  if (typeof window !== 'undefined') {
    const origin = window.location.origin
    if (value.startsWith('#')) return `${origin}/${value}`
    if (value.startsWith('/pages/')) return `${origin}/#${value}`
    return `${origin}${value.startsWith('/') ? '' : '/'}${value}`
  }
  // #endif
  // #ifndef H5
  return value
  // #endif
}

function absoluteAsset(url?: string): string {
  const value = String(url || '').trim()
  if (!value) return ''
  if (/^https?:\/\//i.test(value) || /^data:/i.test(value)) return value
  // #ifdef H5
  if (typeof window !== 'undefined' && value.startsWith('/')) return `${window.location.origin}${value}`
  // #endif
  return value
}

function signaturePath(url: string): string {
  return `/wechat/jssdk-signature?url=${encodeURIComponent(url)}`
}

/**
 * 配置公众号分享卡片。失败时静默降级（复制链接仍然可用），不影响页面。
 */
export async function enableWechatShare(options: WechatShareOptions): Promise<void> {
  // #ifndef H5
  return
  // #endif
  // #ifdef H5
  if (!isWechatBrowser() || typeof window === 'undefined') return

  try {
    const loaded = await loadSdk()
    if (!loaded) return
    const sdk = loaded as WechatJsSdk

    // 签名必须基于当前页面地址（不含 #），且每个地址只 config 一次。
    const pageUrl = window.location.href.split('#')[0]
    if (configuredSignature !== pageUrl) {
      const signature = await apiRequest<JssdkSignature>({ path: signaturePath(pageUrl) })

      await new Promise<void>((resolve, reject) => {
        const timer = setTimeout(() => reject(new Error('wechat jssdk config timeout')), 5000)
        sdk.error(() => {
          clearTimeout(timer)
          reject(new Error('wechat jssdk config failed'))
        })
        sdk.config({
          debug: false,
          appId: signature.appId,
          timestamp: signature.timestamp,
          nonceStr: signature.nonceStr,
          signature: signature.signature,
          jsApiList: [
            'updateAppMessageShareData',
            'updateTimelineShareData',
            'onMenuShareAppMessage',
            'onMenuShareTimeline',
          ],
        })
        sdk.ready(() => {
          clearTimeout(timer)
          resolve()
        })
      })
      configuredSignature = pageUrl
    }

    const link = toWechatShareLink(options.link)
    const imgUrl = absoluteAsset(options.imageUrl || DEFAULT_WECHAT_SHARE_IMAGE)
    // 朋友圈卡片标题取自页面 title，配置成功后同步一份作为兜底。
    if (typeof document !== 'undefined') document.title = options.title
    const shareData = {
      title: options.title,
      desc: options.desc || options.title,
      link,
      imgUrl,
    }
    sdk.updateAppMessageShareData?.(shareData)
    sdk.updateTimelineShareData?.({ title: shareData.title, link, imgUrl })
    // 兼容未支持新接口的旧版微信客户端。
    sdk.onMenuShareAppMessage?.(shareData)
    sdk.onMenuShareTimeline?.({ title: shareData.title, link, imgUrl })
  } catch (error) {
    console.warn('[wechat-share] 分享配置失败，已降级为复制链接。', error)
  }
  // #endif
}
