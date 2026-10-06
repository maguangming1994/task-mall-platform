import type { CSSProperties, ReactNode } from 'react'

/* 校圈 Admin 设计稿共用小组件（样式类见 src/styles/xq.css） */

export function PageHead({ title, extra }: { title: string; extra?: ReactNode }) {
  return (
    <div className="xq-page-head">
      <h2>{title}</h2>
      <div>{extra}</div>
    </div>
  )
}

export type PillTone = 'green' | 'orange' | 'red' | 'gray' | 'blue'

export function Pill({ tone, children }: { tone: PillTone; children: ReactNode }) {
  return <span className={`xq-pill ${tone}`}>{children}</span>
}

export function StatCard({ title, value, delta, valueColor }: { title: string; value: ReactNode; delta?: ReactNode; valueColor?: string }) {
  return (
    <div className="xq-stat">
      <div className="t">{title}</div>
      <div className="v" style={valueColor ? { color: valueColor } : undefined}>{value}</div>
      <div className="d">{delta ?? ' '}</div>
    </div>
  )
}

export function DeltaUp({ value, suffix = '较昨日' }: { value?: number | null; suffix?: string }) {
  if (value === undefined || value === null) return <> </>
  const up = value >= 0
  return <span className={up ? 'up' : 'down'} style={{ color: up ? '#16a34a' : '#dc2626' }}>{up ? '↑' : '↓'} {Math.abs(value)}% {suffix}</span>
}

export function UCell({ name, sub }: { name: string; sub?: ReactNode }) {
  return (
    <span className="xq-ucell">
      <span className="ava">{(name || '·').slice(0, 1)}</span>
      <span>
        <span className="nm" style={{ display: 'block' }}>{name}</span>
        {sub ? <span className="id">{sub}</span> : null}
      </span>
    </span>
  )
}

export function PCell({ name, sub, img }: { name: string; sub?: ReactNode; img?: string | null }) {
  return (
    <span className="xq-pcell">
      {img ? <img src={img} alt="" /> : <span className="ph">图</span>}
      <span>
        <span className="nm" style={{ display: 'block' }}>{name}</span>
        {sub ? <span className="sku">{sub}</span> : null}
      </span>
    </span>
  )
}

export function Card({ title, children, style, bodyStyle }: { title?: ReactNode; children: ReactNode; style?: CSSProperties; bodyStyle?: CSSProperties }) {
  return (
    <div className="xq-card" style={style}>
      {title ? <p className="xq-card-title">{title}</p> : null}
      <div style={bodyStyle}>{children}</div>
    </div>
  )
}

export function Links({ items }: { items: Array<{ label: string; danger?: boolean; muted?: boolean; onClick?: () => void } | null | false> }) {
  return (
    <span style={{ whiteSpace: 'nowrap' }}>
      {items.filter(Boolean).map((it) => {
        const item = it as { label: string; danger?: boolean; muted?: boolean; onClick?: () => void }
        return <a key={item.label} className={`xq-link${item.danger ? ' danger' : ''}${item.muted ? ' muted' : ''}`} onClick={item.onClick}>{item.label}</a>
      })}
    </span>
  )
}
