import { useEffect, useRef } from 'react'

/** 页面可见时定时静默刷新，重新回到标签页时立即同步，并避免轮询请求重叠。 */
export function useAutoRefresh(refresh: (silent?: boolean) => Promise<void>, intervalMs = 15000) {
  const inFlight = useRef(false)

  useEffect(() => {
    let disposed = false

    const run = async () => {
      if (disposed || inFlight.current || document.visibilityState !== 'visible') return
      inFlight.current = true
      try {
        await refresh(true)
      } catch {
        // 列表加载函数负责展示错误，避免后台轮询产生未处理异常。
      } finally {
        inFlight.current = false
      }
    }
    const handleVisibilityChange = () => {
      if (document.visibilityState === 'visible') void run()
    }

    const timer = window.setInterval(() => { void run() }, intervalMs)
    document.addEventListener('visibilitychange', handleVisibilityChange)
    return () => {
      disposed = true
      window.clearInterval(timer)
      document.removeEventListener('visibilitychange', handleVisibilityChange)
    }
  }, [intervalMs, refresh])
}
