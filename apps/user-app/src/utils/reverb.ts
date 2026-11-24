/**
 * 轻量 Reverb/Pusher 协议 WebSocket 客户端（uni-app 兼容）
 *
 * 协议要点：
 *  - 连接 ws://host:port/app/{key}?protocol=7&client=js&version=7.0.0
 *  - 服务端返回 pusher:connection_established，其中含 socket_id
 *  - 订阅私有频道需要先调 /broadcasting/auth 换取 auth 签名
 *  - 发送 pusher:subscribe 帧，事件名为 message.sent
 */

import { apiUrl } from '@/api/http'

export interface ReverbConfig {
  appKey: string
  wsHost: string
  wsPort: number
  scheme: 'ws' | 'wss'
  /** 返回当前用户的访问令牌 */
  getAccessToken: () => string | null
}

export interface ChatMessagePayload {
  message: {
    id: string
    conversation_id: string
    sender_id: string
    type: 'text' | 'image'
    content: string
    created_at: string
  }
  conversation: {
    id: string
    last_message: string | null
    last_message_type: string
    last_message_at: string | null
  }
}

type MessageHandler = (payload: ChatMessagePayload) => void

interface Frame { event: string; channel?: string; data?: unknown }

export class ReverbChatClient {
  private socketTask: UniApp.SocketTask | null = null
  private socketId: string | null = null
  private connected = false
  private reconnectTimer: ReturnType<typeof setTimeout> | null = null
  private reconnectAttempts = 0
  private currentConversationId: string | null = null
  private handlers = new Set<MessageHandler>()
  private closedByUser = false

  constructor(private readonly config: ReverbConfig) {}

  /** 注册消息回调 */
  onMessage(handler: MessageHandler): () => void {
    this.handlers.add(handler)
    return () => this.handlers.delete(handler)
  }

  /** 连接并订阅会话私有频道 */
  async connect(conversationId: string): Promise<void> {
    this.closedByUser = false
    this.currentConversationId = conversationId
    if (this.socketTask && this.connected) {
      await this.subscribe(conversationId)
      return
    }
    this.openSocket()
  }

  /** 主动断开（页面卸载时调用） */
  disconnect(): void {
    this.closedByUser = true
    this.clearReconnect()
    if (this.socketTask) {
      try { this.socketTask.close({}) } catch { /* ignore */ }
      this.socketTask = null
    }
    this.connected = false
    this.socketId = null
  }

  private wsUrl(): string {
    const { appKey, wsHost, wsPort, scheme } = this.config
    return `${scheme}://${wsHost}:${wsPort}/app/${appKey}?protocol=7&client=uni-app&version=1.0.0`
  }

  private openSocket(): void {
    const url = this.wsUrl()
    const task = uni.connectSocket({ url, complete: () => { /* noop */ } })
    this.socketTask = task

    task.onOpen(() => {
      this.reconnectAttempts = 0
    })

    task.onMessage((res) => {
      try {
        const frame = typeof res.data === 'string' ? (JSON.parse(res.data) as Frame) : null
        if (!frame) return
        this.handleFrame(frame)
      } catch { /* ignore malformed */ }
    })

    task.onClose(() => {
      this.connected = false
      this.socketId = null
      if (!this.closedByUser) this.scheduleReconnect()
    })

    task.onError(() => {
      this.connected = false
    })
  }

  private async handleFrame(frame: Frame): Promise<void> {
    if (frame.event === 'pusher:connection_established') {
      const data = typeof frame.data === 'string' ? JSON.parse(frame.data) : frame.data
      this.socketId = (data as { socket_id?: string })?.socket_id ?? null
      this.connected = true
      if (this.currentConversationId) {
        await this.subscribe(this.currentConversationId)
      }
      return
    }
    if (frame.event === 'pusher_internal:subscription_succeeded') {
      return
    }
    if (frame.event === 'message.sent' && frame.channel?.startsWith('private-chat.conversation.')) {
      const data = typeof frame.data === 'string' ? JSON.parse(frame.data) : frame.data
      this.handlers.forEach((h) => {
        try { h(data as ChatMessagePayload) } catch { /* ignore */ }
      })
    }
  }

  private async subscribe(conversationId: string): Promise<void> {
    if (!this.socketTask || !this.connected || !this.socketId) return
    const token = this.config.getAccessToken()
    if (!token) return

    const channel = `private-chat.conversation.${conversationId}`

    const auth = await this.requestAuth(channel, this.socketId, token)
    if (!auth) return

    this.socketTask.send({
      data: JSON.stringify({
        event: 'pusher:subscribe',
        data: { auth, channel },
      }),
    })
  }

  private async requestAuth(channel: string, socketId: string, token: string): Promise<string | null> {
    return new Promise((resolve) => {
      uni.request({
        url: apiUrl('/broadcasting/auth'),
        method: 'POST',
        header: {
          'Content-Type': 'application/x-www-form-urlencoded',
          Authorization: `Bearer ${token}`,
        },
        data: `socket_id=${encodeURIComponent(socketId)}&channel_name=${encodeURIComponent(channel)}`,
        success: (res) => {
          const data = res.data as { auth?: string } | { data?: { auth?: string } }
          const auth = (data as { auth?: string }).auth
            ?? (data as { data?: { auth?: string } }).data?.auth
            ?? null
          resolve(auth)
        },
        fail: () => resolve(null),
      })
    })
  }

  private scheduleReconnect(): void {
    this.clearReconnect()
    if (this.reconnectAttempts >= 6) return
    const delay = Math.min(15000, 1000 * 2 ** this.reconnectAttempts)
    this.reconnectAttempts += 1
    this.reconnectTimer = setTimeout(() => {
      if (!this.closedByUser) this.openSocket()
    }, delay)
  }

  private clearReconnect(): void {
    if (this.reconnectTimer) {
      clearTimeout(this.reconnectTimer)
      this.reconnectTimer = null
    }
  }
}

let shared: ReverbChatClient | null = null

export function getReverbClient(getAccessToken: () => string | null): ReverbChatClient {
  if (shared) return shared

  const apiBase = import.meta.env.VITE_API_BASE_URL || 'https://rwsc.jxgxnxs.cn/api/v1'
  const origin = apiBase.match(/^(https?):\/\/([^/:]+)/i)
  let host = import.meta.env.VITE_REVERB_HOST || origin?.[2] || ''
  // #ifdef H5
  // 生产 H5 的 API 基址是相对路径 /api/v1，此时回落到当前页面域名。
  if (!host && typeof window !== 'undefined') host = window.location.hostname
  // #endif
  if (!host) host = '127.0.0.1'
  const scheme = import.meta.env.VITE_REVERB_SCHEME
    || ((origin?.[1]?.toLowerCase() === 'https'
      // #ifdef H5
      || (typeof window !== 'undefined' && window.location.protocol === 'https:')
      // #endif
    ) ? 'wss' : 'ws')
  const isSecure = scheme === 'wss'
  const port = Number(import.meta.env.VITE_REVERB_PORT || (isSecure ? 443 : 8080))

  shared = new ReverbChatClient({
    appKey: import.meta.env.VITE_REVERB_APP_KEY || 'local-key',
    wsHost: host,
    wsPort: port,
    scheme,
    getAccessToken,
  })
  return shared
}
