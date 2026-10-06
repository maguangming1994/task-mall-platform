import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { Button, Input, Select, message } from 'antd'
import {
  listManagedSupportTickets,
  replyManagedSupportTicket,
  updateManagedSupportTicket,
  type ManagedSupportTicket,
} from '../../api/admin'
import { PageHead, Pill, type PillTone } from '../../components/xq'

const statusLabels: Record<string, string> = {
  open: '待回复',
  processing: '处理中',
  waiting_user: '待用户回复',
  waiting_service: '待客服回复',
  resolved: '已解决',
  closed: '已关闭',
}
const statusPills: Record<string, PillTone> = {
  open: 'orange',
  processing: 'blue',
  waiting_user: 'orange',
  waiting_service: 'orange',
  resolved: 'green',
  closed: 'gray',
}

const fmtShort = (v?: string | null) => {
  if (!v) return ''
  const d = new Date(v)
  const today = new Date()
  if (d.toDateString() === today.toDateString()) return d.toLocaleTimeString('zh-CN', { hour: '2-digit', minute: '2-digit', hour12: false })
  return `${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}
const fmtTime = (v?: string | null) => (v ? new Date(v).toLocaleString('zh-CN', { hour12: false }) : '')

function Avatar({ text, color }: { text: string; color: { bg: string; fg: string } }) {
  return (
    <span style={{
      width: 34, height: 34, borderRadius: '50%', background: color.bg, color: color.fg, flex: 'none',
      display: 'inline-flex', alignItems: 'center', justifyContent: 'center', fontWeight: 700, fontSize: 14,
    }}
    >
      {text}
    </span>
  )
}

export function SupportPage() {
  const [messageApi, contextHolder] = message.useMessage()
  const [tickets, setTickets] = useState<ManagedSupportTicket[]>([])
  const [filterStatus, setFilterStatus] = useState('')
  const [keyword, setKeyword] = useState('')
  const [activeId, setActiveId] = useState<string | null>(null)
  const [loading, setLoading] = useState(false)
  const [input, setInput] = useState('')
  const [sending, setSending] = useState(false)
  const scrollRef = useRef<HTMLDivElement>(null)

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const result = await listManagedSupportTickets(filterStatus ? { status: filterStatus } : {})
      setTickets(result.items)
      setActiveId((prev) => (prev && result.items.some((t) => t.id === prev) ? prev : (result.items[0]?.id ?? null)))
    } catch {
      messageApi.error('工单数据加载失败，请稍后重试。')
    } finally {
      setLoading(false)
    }
  }, [filterStatus, messageApi])

  useEffect(() => { void load() }, [load])

  const filtered = useMemo(() => {
    const k = keyword.trim()
    return k ? tickets.filter((t) => `${t.subject} ${t.id} ${t.category}`.includes(k)) : tickets
  }, [tickets, keyword])

  const active = tickets.find((t) => t.id === activeId) ?? null

  useEffect(() => {
    scrollRef.current?.scrollTo({ top: scrollRef.current.scrollHeight })
  }, [active?.messages.length, activeId])

  const send = async () => {
    if (!active || !input.trim()) return
    setSending(true)
    try {
      await replyManagedSupportTicket(active.id, input.trim(), 'processing')
      setInput('')
      await load()
    } catch {
      messageApi.error('回复发送失败，请稍后重试。')
    } finally {
      setSending(false)
    }
  }

  const markResolved = async () => {
    if (!active) return
    try {
      await updateManagedSupportTicket(active.id, { status: 'resolved' })
      messageApi.success('该工单已标记为已解决。')
      await load()
    } catch {
      messageApi.error('操作失败，请稍后重试。')
    }
  }

  return (
    <div className="xq-page">
      {contextHolder}
      <PageHead title="工单客服" />

      <div className="xq-split">
        <div className="xq-card" style={{ width: 300, flex: 'none', padding: 14 }}>
          <Select
            value={filterStatus || undefined}
            onChange={(v) => setFilterStatus(v ?? '')}
            allowClear
            placeholder="全部状态"
            style={{ width: '100%', marginBottom: 10 }}
            options={Object.entries(statusLabels).map(([value, label]) => ({ value, label }))}
          />
          <Input placeholder="搜索工单" value={keyword} onChange={(e) => setKeyword(e.target.value)} style={{ marginBottom: 8 }} />
          <div style={{ display: 'flex', flexDirection: 'column', maxHeight: 560, overflowY: 'auto' }}>
            {filtered.map((t) => (
              <div
                key={t.id}
                onClick={() => setActiveId(t.id)}
                style={{
                  display: 'flex', gap: 10, alignItems: 'center', padding: '10px 8px', borderRadius: 10, cursor: 'pointer',
                  background: t.id === activeId ? '#eaf7ee' : 'transparent',
                  borderLeft: t.id === activeId ? '3px solid #22c55e' : '3px solid transparent',
                }}
              >
                <Avatar text={(t.subject || '工').slice(0, 1)} color={{ bg: '#d9f2e3', fg: '#15803d' }} />
                <span style={{ flex: 1, minWidth: 0 }}>
                  <span style={{ display: 'block', fontWeight: 600, fontSize: 13, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{t.subject}</span>
                  <span style={{ display: 'block', color: '#6b7280', fontSize: 11 }}>{t.category} · {statusLabels[t.status] ?? t.status}</span>
                </span>
                <span style={{ color: '#9ca3af', fontSize: 11, flex: 'none' }}>{fmtShort(t.updated_at)}</span>
              </div>
            ))}
            {!filtered.length && <div className="xq-empty">{loading ? '加载中…' : '暂无工单'}</div>}
          </div>
        </div>

        <div className="xq-card" style={{ flex: 1, minWidth: 0, display: 'flex', flexDirection: 'column' }}>
          {active ? (
            <>
              <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', paddingBottom: 14, borderBottom: '1px solid #f0f3f1' }}>
                <span style={{ display: 'flex', gap: 10, alignItems: 'center' }}>
                  <Avatar text={(active.subject || '工').slice(0, 1)} color={{ bg: '#d9f2e3', fg: '#15803d' }} />
                  <span>
                    <span style={{ display: 'block', fontWeight: 700 }}>{active.subject}</span>
                    <span style={{ color: '#6b7280', fontSize: 11 }}>工单号 {active.id} · {active.category}{active.related_public_id ? ` · 关联单 ${active.related_public_id}` : ''}</span>
                  </span>
                </span>
                <Pill tone={statusPills[active.status] ?? 'gray'}>{statusLabels[active.status] ?? active.status}</Pill>
              </div>

              <div ref={scrollRef} style={{ flex: 1, minHeight: 420, maxHeight: 520, overflowY: 'auto', padding: '16px 4px' }}>
                {active.messages.map((m) => {
                  const isUser = m.sender_type === 'user'
                  return (
                    <div key={m.id} style={{ display: 'flex', justifyContent: isUser ? 'flex-start' : 'flex-end', gap: 10, marginBottom: 16 }}>
                      {isUser && <Avatar text="用" color={{ bg: '#d9f2e3', fg: '#15803d' }} />}
                      <span style={{ maxWidth: '70%' }}>
                        <span style={{ display: 'block', fontSize: 11, color: '#9ca3af', textAlign: isUser ? 'left' : 'right', marginBottom: 4 }}>{fmtTime(m.created_at)}</span>
                        <span style={{
                          display: 'inline-block', borderRadius: 10, padding: '10px 14px', fontSize: 13, lineHeight: 1.7,
                          background: isUser ? '#dcfce7' : '#f3f4f6', color: '#1f2937', whiteSpace: 'pre-wrap', wordBreak: 'break-word',
                        }}
                        >
                          {m.content}
                        </span>
                      </span>
                      {!isUser && <Avatar text="客" color={{ bg: '#fdf0d5', fg: '#b45309' }} />}
                    </div>
                  )
                })}
                {!active.messages.length && <div className="xq-empty">暂无会话记录</div>}
              </div>

              <div style={{ display: 'flex', gap: 10, paddingTop: 14, borderTop: '1px solid #f0f3f1' }}>
                <Input
                  placeholder="输入回复内容…"
                  value={input}
                  onChange={(e) => setInput(e.target.value)}
                  onPressEnter={() => void send()}
                  disabled={active.status === 'resolved' || active.status === 'closed'}
                />
                <Button type="primary" loading={sending} onClick={() => void send()} disabled={!input.trim() || active.status === 'resolved' || active.status === 'closed'}>发送</Button>
                <Button onClick={() => void markResolved()} disabled={active.status === 'resolved' || active.status === 'closed'}>标记解决</Button>
              </div>
            </>
          ) : (
            <div className="xq-empty" style={{ margin: 'auto' }}>请选择左侧工单开始回复</div>
          )}
        </div>
      </div>
    </div>
  )
}
