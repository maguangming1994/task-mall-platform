export type ClientPlatform = 'wechat-mini' | 'h5-wechat' | 'h5-browser'

let detectedPlatform: ClientPlatform = 'wechat-mini'

// #ifdef H5
detectedPlatform = /MicroMessenger/i.test(navigator.userAgent) ? 'h5-wechat' : 'h5-browser'
// #endif

export const clientPlatform: ClientPlatform = detectedPlatform
export const isWechatRuntime = clientPlatform !== 'h5-browser'
