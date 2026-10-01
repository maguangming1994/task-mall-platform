// 后台「图标与文案」下发配置的纯解析逻辑（不依赖 uni / pinia，便于单测）。
// 配置来源：GET /api/v1/icon-configs，每条以 code 关联包内静态 SVG。

export interface IconConfigShape {
  code: string
  svg_path: string
  fallback_char: string | null
  label: string | null
  is_active: boolean
}

export type IconConfigMap = Record<string, IconConfigShape>

// 包内静态图标形如 /static/icons/service-tasks.svg；code 即文件名。
const LOCAL_ICON_RE = /^\/static\/icons\/([a-z0-9][a-z0-9-]*)\.svg(?:[?#].*)?$/i

/** 从包内静态图标路径推导后台配置 code；非包内路径返回 null（表示该图不归图标配置管辖）。 */
export function deriveLocalIconCode(src: unknown): string | null {
  if (typeof src !== 'string') return null
  const match = src.trim().match(LOCAL_ICON_RE)
  return match ? match[1].toLowerCase() : null
}

function normalizePath(value: unknown): string {
  return typeof value === 'string' ? value.trim() : ''
}

/**
 * 解析最终图标地址。
 * 规则：
 * 1. 上层（如页面装修 /appearance-content）下发的远程图永远优先——非包内路径原样返回；
 * 2. 包内静态图标若在后台存在「启用」配置，则用后台 svg_path（可指向另一个包内 SVG 或远程图）；
 * 3. 后台停用或缺省配置时，回退包内原图。
 */
export function resolveIconSrc(
  src: string,
  map: IconConfigMap,
  toAbsolute: (path: string) => string = (path) => path,
): string {
  const original = normalizePath(src)
  const code = deriveLocalIconCode(original)
  if (!code) return original

  const config = map[code]
  if (!config || !config.is_active) return original

  const configuredPath = normalizePath(config.svg_path)
  if (!configuredPath) return original
  if (configuredPath.startsWith('/static/')) return configuredPath
  if (/^(?:https?:|data:|blob:|wxfile:)/i.test(configuredPath)) return configuredPath
  if (configuredPath.startsWith('/')) return toAbsolute(configuredPath)
  return configuredPath
}

/** 解析展示文字：仅当包内入口图标在后台启用且配置了文字时覆盖，否则沿用页面默认文字。 */
export function resolveIconLabel(fallbackLabel: string, map: IconConfigMap, localSrc?: string): string {
  const fallback = String(fallbackLabel ?? '').trim()
  const code = deriveLocalIconCode(localSrc)
  if (!code) return fallback
  const config = map[code]
  if (!config || !config.is_active) return fallback
  const label = typeof config.label === 'string' ? config.label.trim() : ''
  return label || fallback
}

function isActiveShape(value: unknown): value is IconConfigShape {
  if (!value || typeof value !== 'object') return false
  const item = value as Record<string, unknown>
  return typeof item.code === 'string'
    && item.code.trim().length > 0
    && typeof item.svg_path === 'string'
    && item.svg_path.trim().length > 0
    && item.is_active === true
}

/** 将接口 items 归一化为 code -> 配置 的映射，只保留启用且结构合法的条目。 */
export function normalizeIconConfigs(items: unknown): IconConfigMap {
  if (!Array.isArray(items)) return {}
  return items.reduce<IconConfigMap>((acc, raw) => {
    if (!isActiveShape(raw)) return acc
    const item = raw as IconConfigShape
    acc[item.code.trim().toLowerCase()] = {
      code: item.code.trim().toLowerCase(),
      svg_path: item.svg_path.trim(),
      fallback_char: typeof item.fallback_char === 'string' ? item.fallback_char : null,
      label: typeof item.label === 'string' ? item.label.trim() : null,
      is_active: true,
    }
    return acc
  }, {})
}
