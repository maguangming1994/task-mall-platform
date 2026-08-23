import { clientPlatform } from './runtime'
import { readQueryString } from '@/utils/query'

export interface WechatLoginCode {
  channel: 'mini' | 'h5'
  code: string
}

const localSubjectKey = 'task_mall_local_wechat_subject'
const oauthStateKey = 'task_mall_wechat_oauth_state'

function localCode(channel: 'mini' | 'h5'): string {
  let subject = uni.getStorageSync(localSubjectKey)
  if (typeof subject !== 'string' || !subject) {
    subject = `dev-${Date.now()}-${Math.random().toString(36).slice(2, 10)}`
    uni.setStorageSync(localSubjectKey, subject)
  }

  return `local:${channel}-${subject}:union-${subject}`
}

function miniLoginCode(): Promise<string> {
  if (import.meta.env.DEV && import.meta.env.VITE_WECHAT_MINI_REAL_LOGIN !== 'true') {
    return Promise.resolve(localCode('mini'))
  }

  return new Promise((resolve, reject) => {
    uni.login({
      provider: 'weixin',
      success: result => result.code ? resolve(result.code) : reject(new Error('未取得微信登录凭证。')),
      fail: () => reject(new Error('微信登录调用失败，请稍后重试。')),
    })
  })
}

function h5LoginCode(): string | null {
  const code = readQueryString(window.location.search, 'code')
  if (code) {
    const expectedState = uni.getStorageSync(oauthStateKey)
    const returnedState = readQueryString(window.location.search, 'state')
    uni.removeStorageSync(oauthStateKey)
    if (!import.meta.env.DEV && (!expectedState || expectedState !== returnedState)) {
      throw new Error('微信授权状态校验失败，请重新发起登录。')
    }
    return code
  }
  if (import.meta.env.DEV) return localCode('h5')

  const appId = import.meta.env.VITE_WECHAT_OA_APP_ID
  if (!appId) throw new Error('公众号网页授权尚未配置。')

  const redirectUri = encodeURIComponent(`${window.location.origin}${window.location.pathname}`)
  const stateBytes = new Uint8Array(16)
  window.crypto.getRandomValues(stateBytes)
  const state = Array.from(stateBytes, value => value.toString(16).padStart(2, '0')).join('')
  uni.setStorageSync(oauthStateKey, state)
  window.location.replace(
    `https://open.weixin.qq.com/connect/oauth2/authorize?appid=${encodeURIComponent(appId)}&redirect_uri=${redirectUri}&response_type=code&scope=snsapi_base&state=${state}#wechat_redirect`,
  )

  return null
}

export async function requestWechatLoginCode(): Promise<WechatLoginCode | null> {
  if (clientPlatform === 'wechat-mini') {
    return { channel: 'mini', code: await miniLoginCode() }
  }
  if (clientPlatform === 'h5-wechat') {
    const code = h5LoginCode()
    return code ? { channel: 'h5', code } : null
  }

  throw new Error('当前浏览器不支持微信快捷登录，请使用手机号登录。')
}
