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
let shareErrorReported = false

// iOS 微信使用「首次进入页面的 URL」校验签名，Android 使用当前 URL。
// 模块加载早于路由改写地址，因此在这里先保存进入地址。
const ENTRY_URL = typeof window !== 'undefined' ? window.location.href.split('#')[0] : ''
const IS_IOS = typeof navigator !== 'undefined' && /iPhone|iPad|iPod/i.test(navigator.userAgent)

function signatureUrl(): string {
  if (typeof window === 'undefined') return ''
  return IS_IOS ? ENTRY_URL : window.location.href.split('#')[0]
}

/** 在地址栏追加 ?wxdebug=1 可在页面底部显示 JSSDK 诊断信息，便于真机定位。 */
const DEBUG_PANEL =
  typeof window !== 'undefined' && /[?&]wxdebug=1/.test(window.location.href)

function logDiag(message: string) {
  console.log('[wx-jsapi]', message)
  if (!DEBUG_PANEL || typeof document === 'undefined') return
  let panel = document.getElementById('wx-jsapi-debug')
  if (!panel) {
    panel = document.createElement('div')
    panel.id = 'wx-jsapi-debug'
    panel.style.cssText =
      'position:fixed;left:0;right:0;bottom:0;z-index:99999;max-height:45vh;overflow:auto;' +
      'background:rgba(0,0,0,.86);color:#3f6;font:11px/1.6 monospace;padding:8px;white-space:pre-wrap;'
    document.body.appendChild(panel)
  }
  panel.textContent += `${message}\n`
}

function isWechatBrowser(): boolean {
  return typeof navigator !== 'undefined' && /MicroMessenger/i.test(navigator.userAgent)
}

type SdkGlobal = { wx?: Partial<WechatJsSdk>; jWeixin?: Partial<WechatJsSdk> }

/**
 * 微信客户端可能注入一个能力不全的 window.wx，必须校验关键方法齐全才可使用，
 * 否则会出现 config/error 不存在导致初始化崩溃。
 */
function isUsableSdk(sdk: Partial<WechatJsSdk> | undefined): sdk is WechatJsSdk {
  return (
    !!sdk &&
    typeof sdk.config === 'function' &&
    typeof sdk.ready === 'function' &&
    typeof sdk.error === 'function'
  )
}

function readSdk(): WechatJsSdk | null {
  const globalObject = window as unknown as SdkGlobal
  if (isUsableSdk(globalObject.jWeixin)) return globalObject.jWeixin
  if (isUsableSdk(globalObject.wx)) return globalObject.wx
  return null
}

function loadSdk(): Promise<WechatJsSdk | null> {
  if (typeof window === 'undefined') return Promise.resolve(null)
  const existing = readSdk()
  if (existing) return Promise.resolve(existing)
  if (sdkPromise) return sdkPromise

  sdkPromise = new Promise<WechatJsSdk | null>((resolve) => {
    const script = document.createElement('script')
    script.src = SDK_SRC
    script.async = true
    script.onload = () => resolve(readSdk())
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
  // JSSDK 不可用时微信会退化为抓取页面标题，这里先同步一份保证朋友圈卡片有标题。
  if (typeof document !== 'undefined' && options.title) document.title = options.title
  // #ifndef H5
  return
  // #endif
  // #ifdef H5
  if (!isWechatBrowser() || typeof window === 'undefined') return

  try {
    logDiag(`ios=${IS_IOS} entry=${ENTRY_URL}`)
    const loaded = await loadSdk()
    if (!loaded) {
      logDiag('sdk=missing')
      return
    }
    const sdk = loaded as WechatJsSdk

    // 签名必须基于当前页面地址（不含 #），且每个地址只 config 一次。
    const pageUrl = signatureUrl()
    logDiag(`sigUrl=${pageUrl}`)
    if (configuredSignature !== pageUrl) {
      const signature = await apiRequest<JssdkSignature>({ path: signaturePath(pageUrl) })
      logDiag(
        `appId=${signature.appId} ts=${signature.timestamp} sig=${String(signature.signature).slice(0, 12)}`,
      )

      await new Promise<void>((resolve, reject) => {
        const timer = setTimeout(() => reject(new Error('wechat jssdk config timeout')), 5000)
        sdk.error((result) => {
          clearTimeout(timer)
          const detail = (result as { errMsg?: string } | undefined)?.errMsg || 'config failed'
          logDiag(`config-error=${detail}`)
          reject(new Error(`微信分享初始化失败：${detail}`))
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
          logDiag('config-ok')
          resolve()
        })
      })
      configuredSignature = pageUrl
    }

    const link = toWechatShareLink(options.link)
    const imgUrl = absoluteAsset(options.imageUrl || DEFAULT_WECHAT_SHARE_IMAGE)
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
    // 每个会话只提示一次，便于定位签名/域名问题。
    if (!shareErrorReported) {
      shareErrorReported = true
      const message = (error as Error)?.message || '微信分享初始化失败'
      uni.showToast({ title: message, icon: 'none', duration: 5000 })
    }
  }
  // #endif
}
