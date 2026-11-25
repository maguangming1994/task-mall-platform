import { clientPlatform } from './runtime'
import { loadClientCapabilities } from './capabilities'

export type AdminWechatMode = 'login' | 'bind'

export interface AdminWechatCallback {
  mode: AdminWechatMode
  code: string
}

const stateKey = 'task_mall_admin_oauth_state'
const boundKey = 'task_mall_admin_wechat_bound'
const localSubjectKey = 'task_mall_local_wechat_subject'

/** 是否处于微信公众号网页环境 */
export function isWechatH5(): boolean {
  // #ifdef H5
  return clientPlatform === 'h5-wechat'
  // #endif
  // #ifndef H5
  return false
  // #endif
}

/** 本机是否曾成功绑定微信（仅用于决定是否尝试免密，最终以后端校验为准） */
export function hasWechatBindingFlag(): boolean {
  return uni.getStorageSync(boundKey) === true
}

export function setWechatBindingFlag(bound: boolean) {
  if (bound) uni.setStorageSync(boundKey, true)
  else uni.removeStorageSync(boundKey)
}

function randomState(): string {
  // #ifdef H5
  const bytes = new Uint8Array(16)
  window.crypto.getRandomValues(bytes)
  return Array.from(bytes, value => value.toString(16).padStart(2, '0')).join('')
  // #endif
  // #ifndef H5
  return `${Date.now()}-${Math.random().toString(36).slice(2, 10)}`
  // #endif
}

/**
 * 发起公众号网页授权。
 *
 * 回调地址保留当前 hash 路由，微信追加的 code/state 会落在页面参数里，
 * 由登录页的 onLoad 消费；state 使用独立存储键，避免与用户端 OAuth 冲突。
 */
let appIdCache: string | null = null
let appIdPromise: Promise<string | null> | null = null

/**
 * 公众号 AppID 由后台「第三方服务配置」下发，构建产物里没有该变量，
 * 因此运行时从 /client-capabilities 读取，并做内存缓存。
 */
export function primeAdminWechatAppId(): Promise<string | null> {
  if (appIdCache) return Promise.resolve(appIdCache)
  if (appIdPromise) return appIdPromise

  appIdPromise = loadClientCapabilities()
    .then((capabilities) => {
      const appId = capabilities.wechat_official_account_app_id
      appIdCache = typeof appId === 'string' && appId ? appId : null
      return appIdCache
    })
    .catch(() => null)
    .finally(() => {
      appIdPromise = null
    })

  return appIdPromise
}

export async function startAdminWechatAuth(mode: AdminWechatMode): Promise<boolean> {
  // #ifdef H5
  if (!isWechatH5()) {
    uni.showToast({ title: '请在微信中打开本页面', icon: 'none' })
    return false
  }
  const appId = import.meta.env.VITE_WECHAT_OA_APP_ID || (await primeAdminWechatAppId())
  if (!appId) {
    uni.showToast({ title: '公众号网页授权尚未配置', icon: 'none' })
    return false
  }

  const state = `${mode}.${randomState()}`
  uni.setStorageSync(stateKey, state)
  const redirectUri = encodeURIComponent(
    `${window.location.origin}${window.location.pathname}${window.location.hash}`,
  )
  window.location.replace(
    `https://open.weixin.qq.com/connect/oauth2/authorize?appid=${encodeURIComponent(appId)}&redirect_uri=${redirectUri}&response_type=code&scope=snsapi_base&state=${state}#wechat_redirect`,
  )

  return true
  // #endif
  // #ifndef H5
  return false
  // #endif
}

/** 校验并消费授权回调；返回 null 表示当前页面不是回调进入 */
export function consumeAdminWechatCallback(
  query: Record<string, string | undefined> | undefined,
): AdminWechatCallback | null {
  const code = query?.code
  if (!code) return null

  const returnedState = typeof query?.state === 'string' ? query.state : ''
  const expectedState = uni.getStorageSync(stateKey)
  uni.removeStorageSync(stateKey)

  if (typeof expectedState !== 'string' || !expectedState || expectedState !== returnedState) {
    return null
  }

  return {
    mode: returnedState.startsWith('bind.') ? 'bind' : 'login',
    code,
  }
}

/** 本地开发时模拟公众号授权码，便于在普通浏览器联调 */
export function localAdminWechatCode(): string | null {
  if (import.meta.env.MODE !== 'development') return null

  let subject = uni.getStorageSync(localSubjectKey)
  if (typeof subject !== 'string' || !subject) {
    subject = `dev-${Date.now()}-${Math.random().toString(36).slice(2, 10)}`
    uni.setStorageSync(localSubjectKey, subject)
  }

  return `local:h5-${subject}:union-${subject}`
}
