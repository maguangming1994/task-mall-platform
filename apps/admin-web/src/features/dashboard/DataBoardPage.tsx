import { Button, message } from 'antd'
import { useCallback, useEffect, useMemo, useState } from 'react'
import { ApiError, getOperationsAnalytics, type OperationsAnalytics } from '../../api/admin'
import { Card, DeltaUp, PageHead, StatCard } from '../../components/xq'

const alertDotColors: Record<string, string> = { critical: '#dc2626', warning: '#d97706', info: '#16a34a' }
const shareColors = ['#22c55e', '#f59e0b', '#3b82f6', '#94a3b8', '#8b5cf6']

function relativeTime(value: string) {
  const time = new Date(value).getTime()
  if (Number.isNaN(time)) return '—'
  const minutes = Math.max(0, Math.floor((Date.now() - time) / 60000))
  if (minutes < 1) return '刚刚'
  if (minutes < 60) return `${minutes} 分钟前`
  const hours = Math.floor(minutes / 60)
  if (hours < 24) return `${hours} 小时前`
  return `${Math.floor(hours / 24)} 天前`
}

export function DataBoardPage() {
  const [analytics, setAnalytics] = useState<OperationsAnalytics | null>(null)
  const [messageApi, contextHolder] = message.useMessage()

  const load = useCallback(async () => {
    try {
      setAnalytics(await getOperationsAnalytics())
    } catch (error) {
      messageApi.error(error instanceof ApiError ? error.message : '数据看板加载失败。')
    }
  }, [messageApi])

  useEffect(() => { void load() }, [load])

  const dauMax = useMemo(() => Math.max(1, ...(analytics?.dau_trend.map((item) => item.count) ?? [1])), [analytics])
  const funnelTop = useMemo(() => Math.max(1, ...(analytics?.funnel.map((item) => item.count) ?? [1])), [analytics])
  const hotDates = useMemo(() => {
    const sorted = [...(analytics?.dau_trend ?? [])].sort((a, b) => b.count - a.count).slice(0, 2)
    return new Set(sorted.map((item) => item.date))
  }, [analytics])
  const today = analytics?.today

  const exportCsv = () => {
    if (!analytics) return
    const rows = [['日期', '活跃用户'], ...analytics.dau_trend.map((item) => [item.date, String(item.count)])]
    const blob = new Blob([`﻿${rows.map((row) => row.join(',')).join('\n')}`], { type: 'text/csv;charset=utf-8' })
    const link = document.createElement('a')
    link.href = URL.createObjectURL(blob)
    link.download = `数据看板-${new Date().toISOString().slice(0, 10)}.csv`
    link.click()
    URL.revokeObjectURL(link.href)
  }

  return (
    <div className="xq-page">
      {contextHolder}
      <PageHead title="数据看板" extra={<Button onClick={exportCsv}>导出报表</Button>} />

      <div className="xq-stats">
        <StatCard title="日活跃用户 DAU" value={today?.dau.toLocaleString() ?? '—'} delta={<DeltaUp value={today?.dau_change_pct} />} />
        <StatCard title="新增注册" value={today?.new_users.toLocaleString() ?? '—'} delta={<DeltaUp value={today?.new_users_change_pct} />} />
        <StatCard title="任务成交额（积分）" value={today?.task_points_turnover.toLocaleString() ?? '—'} delta={<DeltaUp value={today?.task_points_change_pct} />} />
        <StatCard title="商城 GMV" value={today ? `¥${(today.mall_gmv_cents / 100).toLocaleString('zh-CN', { maximumFractionDigits: 2 })}` : '—'} delta={<DeltaUp value={today?.mall_gmv_change_pct} />} />
      </div>

      <div style={{ display: 'grid', gridTemplateColumns: '2fr 1fr', gap: 16, marginBottom: 16 }}>
        <Card title="近 7 日活跃用户趋势">
          <div className="xq-bars">
            {(analytics?.dau_trend ?? []).map((item) => (
              <div className="col" key={item.date}>
                <span className="val">{item.count.toLocaleString()}</span>
                <div className={`bar${hotDates.has(item.date) ? ' hot' : ''}`} style={{ height: `${Math.max(4, (item.count / dauMax) * 100)}%` }} />
                <span className="date">{item.date.slice(5)}</span>
              </div>
            ))}
          </div>
        </Card>
        <Card title="任务转化漏斗">
          <div style={{ display: 'flex', flexDirection: 'column', gap: 10, marginTop: 6 }}>
            {(analytics?.funnel ?? []).map((item, index) => (
              <div key={item.label} style={{ background: '#22c55e', borderRadius: 8, padding: '10px 14px', color: '#fff', fontSize: 13, fontWeight: 600, width: `${Math.max(30, (item.count / funnelTop) * 100)}%`, whiteSpace: 'nowrap' }}>
                {item.label} {item.count.toLocaleString()}{index > 0 ? ` · ${item.ratio}%` : ''}
              </div>
            ))}
            {analytics && analytics.funnel.length === 0 && <div className="xq-empty">暂无可统计的任务转化数据</div>}
          </div>
        </Card>
      </div>

      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr 1fr', gap: 16 }}>
        <Card title="任务类型占比">
          <div style={{ display: 'flex', flexDirection: 'column', gap: 14, marginTop: 6 }}>
            {(analytics?.task_type_share ?? []).map((item, index) => (
              <div key={item.label}>
                <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: 13, marginBottom: 5 }}>
                  <span>{item.label}</span><b>{item.ratio}%</b>
                </div>
                <div style={{ height: 8, borderRadius: 4, background: '#f0f3f1' }}>
                  <div style={{ width: `${Math.min(100, item.ratio)}%`, height: '100%', borderRadius: 4, background: shareColors[index % shareColors.length] }} />
                </div>
              </div>
            ))}
            {analytics && analytics.task_type_share.length === 0 && <div className="xq-empty">暂无已发布任务</div>}
          </div>
        </Card>
        <Card title="校区活跃 TOP5">
          <div style={{ display: 'flex', flexDirection: 'column' }}>
            {(analytics?.campus_top5 ?? []).map((item, index) => (
              <div key={item.name} style={{ display: 'flex', alignItems: 'center', gap: 10, padding: '11px 0', borderBottom: '1px solid var(--xq-line-soft)', fontSize: 13 }}>
                <span style={{ width: 8, height: 8, borderRadius: '50%', background: index < 3 ? '#16a34a' : '#d97706', flex: 'none' }} />
                <span style={{ fontWeight: 600 }}>{item.name}</span>
                <span style={{ marginLeft: 'auto', color: 'var(--xq-muted)', fontSize: 12 }}>DAU {item.dau.toLocaleString()} · 任务 {item.task_count.toLocaleString()}</span>
              </div>
            ))}
            {analytics && analytics.campus_top5.length === 0 && <div className="xq-empty">暂无校区数据</div>}
          </div>
        </Card>
        <Card title="实时告警">
          <div style={{ display: 'flex', flexDirection: 'column' }}>
            {(analytics?.alerts ?? []).map((item) => (
              <div key={`${item.text}-${item.at}`} style={{ display: 'flex', alignItems: 'center', gap: 10, padding: '11px 0', borderBottom: '1px solid var(--xq-line-soft)', fontSize: 13 }}>
                <span style={{ width: 8, height: 8, borderRadius: '50%', background: alertDotColors[item.level] ?? alertDotColors.info, flex: 'none' }} />
                <span>{item.text}</span>
                <span style={{ marginLeft: 'auto', color: 'var(--xq-muted)', fontSize: 12, whiteSpace: 'nowrap' }}>{relativeTime(item.at)}</span>
              </div>
            ))}
            {analytics && analytics.alerts.length === 0 && <div className="xq-empty">系统运行正常</div>}
          </div>
        </Card>
      </div>
    </div>
  )
}
