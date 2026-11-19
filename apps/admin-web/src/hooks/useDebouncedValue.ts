import { useCallback, useEffect, useRef, useState } from 'react'

/**
 * 返回防抖后的值，以及一个立即同步（flush）函数。
 * 输入停止 delay 毫秒后值才更新；调用 flush 可立即同步，常用于搜索按钮点击时立刻应用当前输入。
 */
export function useDebouncedValue<T>(value: T, delay = 400): [T, () => void] {
  const [debounced, setDebounced] = useState(value)
  const timer = useRef<ReturnType<typeof setTimeout> | null>(null)

  const flush = useCallback(() => {
    if (timer.current !== null) {
      clearTimeout(timer.current)
      timer.current = null
    }
    setDebounced(value)
  }, [value])

  useEffect(() => {
    if (timer.current !== null) clearTimeout(timer.current)
    timer.current = setTimeout(() => {
      setDebounced(value)
      timer.current = null
    }, delay)
    return () => {
      if (timer.current !== null) clearTimeout(timer.current)
    }
  }, [value, delay])

  return [debounced, flush]
}
