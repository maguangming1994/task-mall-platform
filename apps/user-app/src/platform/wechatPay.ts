/**
 * 公众号内微信支付（JSAPI）。
 *
 * 只处理下单接口返回 `kind === 'jsapi'` 的分支（公众号 AppID + openid）：
 * - 小程序端由页面内的 uni.requestPayment 处理，本模块经条件编译剔除；
 * - 微信外浏览器由页面的 mweb_url 跳转处理；
 * - 余额支付不经过本模块。
 *
 * 本模块只负责“唤起收银台并返回结果”。支付是否真正到账仍由页面查询支付单确认。
 */

export interface WechatJsapiPayParams {
  appId?: string | null
  timeStamp?: string | null
  nonceStr?: string | null
  package?: string | null
  signType?: string | null
  paySign?: string | null
}

export type WechatJsapiPayErrorCode = 'unsupported' | 'invalid_params' | 'bridge_timeout' | 'cancelled' | 'failed'

export class WechatJsapiPayError extends Error {
  constructor(public readonly code: WechatJsapiPayErrorCode, message: string) {
    super(message)
    this.name = 'WechatJsapiPayError'
  }
}

interface WeixinJsBridge {
  invoke(
    api: string,
    params: Record<string, string>,
    callback: (response: { err_msg?: string }) => void,
  ): void
}

interface ResolvedJsapiPayParams {
  appId: string
  timeStamp: string
  nonceStr: string
  package: string
  signType: string
  paySign: string
}

const REQUIRED_FIELDS: ReadonlyArray<[keyof WechatJsapiPayParams, string]> = [
  ['appId', 'appId'],
  ['timeStamp', 'timeStamp'],
  ['nonceStr', 'nonceStr'],
  ['package', 'package'],
  ['paySign', 'paySign'],
]

const BRIDGE_READY_TIMEOUT_MS = 8000

function bridgeOrUndefined(): WeixinJsBridge | undefined {
  // #ifdef H5
  return typeof window === 'undefined'
    ? undefined
    : (window as unknown as { WeixinJSBridge?: WeixinJsBridge }).WeixinJSBridge
  // #endif
  // #ifndef H5
  return undefined
  // #endif
}

function isWechatBrowser(): boolean {
  // #ifdef H5
  return typeof navigator !== 'undefined' && /MicroMessenger/i.test(navigator.userAgent)
  // #endif
  // #ifndef H5
  return false
  // #endif
}

function waitForBridge(): Promise<WeixinJsBridge | undefined> {
  const ready = bridgeOrUndefined()
  if (ready) return Promise.resolve(ready)
  // #ifdef H5
  // 用户点击支付时 WeixinJSBridge 可能尚未注入，监听 WeixinJSBridgeReady 后再唤起。
  return new Promise<WeixinJsBridge | undefined>((resolve, reject) => {
    let timer: ReturnType<typeof setTimeout> | undefined
    const cleanup = () => {
      if (timer) clearTimeout(timer)
      document.removeEventListener('WeixinJSBridgeReady', onReady)
    }
    const onReady = () => {
      const instance = bridgeOrUndefined()
      if (!instance) return
      cleanup()
      resolve(instance)
    }
    timer = setTimeout(() => {
      cleanup()
      reject(new WechatJsapiPayError('bridge_timeout', '微信支付组件加载超时，请退出公众号页面后重新进入再试。'))
    }, BRIDGE_READY_TIMEOUT_MS)
    document.addEventListener('WeixinJSBridgeReady', onReady, false)
  })
  // #endif
  // #ifndef H5
  return Promise.resolve(undefined)
  // #endif
}

function resolveParams(payload: WechatJsapiPayParams | null | undefined): ResolvedJsapiPayParams {
  if (!isWechatBrowser()) {
    throw new WechatJsapiPayError('unsupported', '当前页面不在微信内打开，无法发起公众号支付。')
  }
  const missing = REQUIRED_FIELDS
    .filter(([key]) => !String(payload?.[key] ?? '').trim())
    .map(([, label]) => label)
  if (missing.length) {
    throw new WechatJsapiPayError(
      'invalid_params',
      `支付参数不完整（缺少 ${missing.join('、')}），请稍后重试或联系平台管理员。`,
    )
  }

  return {
    appId: String(payload?.appId).trim(),
    timeStamp: String(payload?.timeStamp).trim(),
    nonceStr: String(payload?.nonceStr).trim(),
    package: String(payload?.package).trim(),
    signType: String(payload?.signType || 'RSA').trim(),
    paySign: String(payload?.paySign).trim(),
  }
}

/**
 * 唤起公众号内微信收银台。
 *
 * - resolve：用户在收银台完成支付（仍需页面查询支付单确认到账）；
 * - reject：抛出 WechatJsapiPayError，code 区分环境不支持 / 参数缺失 / 组件超时 / 用户取消 / 支付失败。
 */
export async function invokeWechatJsapiPayment(payload: WechatJsapiPayParams | null | undefined): Promise<void> {
  const params = resolveParams(payload)
  const bridge = await waitForBridge()
  if (!bridge) {
    throw new WechatJsapiPayError('unsupported', '当前环境不支持公众号内微信支付。')
  }

  await new Promise<void>((resolve, reject) => {
    try {
      bridge.invoke('getBrandWCPayRequest', {
        appId: params.appId,
        timeStamp: params.timeStamp,
        nonceStr: params.nonceStr,
        package: params.package,
        signType: params.signType,
        paySign: params.paySign,
      }, (response) => {
        const message = String(response?.err_msg ?? '')
        if (message === 'get_brand_wcpay_request:ok') {
          resolve()
          return
        }
        if (message === 'get_brand_wcpay_request:cancel') {
          reject(new WechatJsapiPayError('cancelled', '已取消支付，可稍后重新发起。'))
          return
        }
        reject(new WechatJsapiPayError('failed', '微信支付未完成，请稍后重试。'))
      })
    } catch {
      reject(new WechatJsapiPayError('invalid_params', '微信支付参数无法受理，请稍后重试。'))
    }
  })
}
