import { Alert, Button, Card, Statistic, Tag, Typography, message } from 'antd'
import { ArrowDownOutlined, ArrowUpOutlined, BarChartOutlined, ReloadOutlined } from '@ant-design/icons'
import { useCallback, useEffect, useMemo, useState } from 'react'
import { ApiError, getOperationsAnalytics, type OperationsAnalytics } from '../../api/admin'

const funnelGradients = [
  'linear-gradient(135deg,#12D978,#0BBF63)',
  'linear-gradient(135deg,#0BBF63,#08A152)',
  'linear-gradient(135deg,#08A152,#067A3E)',
  'linear-gradient(135deg,#067A3E,#045C2F)',
]
const shareBarColors = [
  'linear-gradient(135deg,#12D978,#0BBF63)',
  'linear-gradient(135deg,#FF9A3D,#F2620A)',
  'linear-gradient(135deg,#5B9BFF,#3B82F6)',
  'linear-gradient(135deg,#9BA8C7,#6B7688)',
  'linear-gradient(135deg,#B47CFF,#8B5CF6)',
  '#D8DCE0',
]
const alertDotColors = { critical: '#E5484D', warning: '#F2620A', info: '#0BBF63' } as const

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

function ChangeTag({ value }: { value: number }) {
  const up = value >= 0
  return (
    <Tag color={up ? 'success' : 'error'} icon={up ? <ArrowUpOutlined /> : <ArrowDownOutlined />}>
      {Math.abs(value)}% 较昨日
    </Tag>
  )
}

export function DataBoardPage() {
  const [analytics, setAnalytics] = useState<OperationsAnalytics | null>(null)
  const [loading, setLoading] = useState(false)
  const [loadError, setLoadError] = useState<string | null>(null)
  const [messageApi, contextHolder] = message.useMessage()

  const load = useCallback(async () => {
    setLoading(true)
    setLoadError(null)
    try {
      setAnalytics(await getOperationsAnalytics())
    } catch (error) {
      const errorMessage = error instanceof ApiError ? error.message : '数据看板加载失败，请检查网络后重试。'
      setLoadError(errorMessage)
      messageApi.error(errorMessage)
    } finally {
      setLoading(false)
    }
  }, [messageApi])

  useEffect(() => { void load() }, [load])

  const dauMax = useMemo(() => Math.max(1, ...(analytics?.dau_trend.map((item) => item.count) ?? [1])), [analytics])
  const funnelTop = useMemo(() => Math.max(1, ...(analytics?.funnel.map((item) => item.count) ?? [1])), [analytics])
  const today = analytics?.today

  const statCards = today ? [
    { title: '日活跃用户 DAU', value: today.dau, change: today.dau_change_pct },
    { title: '新增注册', value: today.new_users, change: today.new_users_change_pct },
    { title: '任务成交额（积分）', value: today.task_points_turnover, change: today.task_points_change_pct },
    { title: '商城 GMV', value: today.mall_gmv_cents / 100, precision: 2, prefix: '¥', change: today.mall_gmv_change_pct },
  ] : []

  return <>
    {contextHolder}
    <div className="page-heading">
      <div>
        <Typography.Text className="eyebrow">首页 / 运营与消息</Typography.Text>
        <Typography.Title level={2}>数据看板</Typography.Title>
        <Typography.Paragraph type="secondary">活跃、增长、任务转化与校区活跃一屏总览，全部指标来自真实业务数据。</Typography.Paragraph>
      </div>
      <Button icon={<ReloadOutlined />} onClick={() => void load()} loading={loading}>刷新</Button>
    </div>
    {loadError && <Alert showIcon type="error" title="数据看板暂时无法刷新" description={loadError} action={<Button size="small" onClick={() => void load()} loading={loading}>重试</Button>} style={{ marginBottom: 16 }} />}

    <div className="stat-grid stat-grid-four">
      {statCards.map((item) => (
        <Card variant="borderless" key={item.title}>
          <Statistic title={item.title} value={item.value} precision={item.precision} prefix={item.prefix} />
          <div style={{ marginTop: 8 }}><ChangeTag value={item.change} /></div>
        </Card>
      ))}
    </div>

    <div className="data-board-grid-main">
      <Card variant="borderless" title="近 7 日活跃用户趋势" extra={<Tag icon={<BarChartOutlined />} color="processing">DAU</Tag>}>
        <div className="trend-chart">
          {analytics?.dau_trend.map((item) => (
            <div className="trend-day" key={item.date}>
              <div className="trend-bars"><i className="trend-user" style={{ height: `${Math.max(4, item.count / dauMax * 120)}px` }} title={`${item.date} DAU ${item.count}`} /></div>
              <small>{item.count}</small>
              <small>{item.date}</small>
            </div>
          ))}
        </div>
      </Card>
      <Card variant="borderless" title="任务转化漏斗">
        <div style={{ display: 'flex', flexDirection: 'column', gap: 8, marginTop: 6 }}>
          {analytics?.funnel.map((item, index) => (
            <div key={item.label} style={{ background: funnelGradients[index % funnelGradients.length], borderRadius: 8, padding: '9px 14px', color: '#fff', fontSize: 12, fontWeight: 700, width: `${Math.max(28, item.count / funnelTop * 100)}%` }}>
              {item.label} {item.count.toLocaleString()}{index > 0 ? ` · ${item.ratio}%` : ''}
            </div>
          ))}
          {analytics && analytics.funnel.every((item) => item.count === 0) && <Typography.Text type="secondary">暂无可统计的任务转化数据。</Typography.Text>}
        </div>
      </Card>
    </div>

    <div className="data-board-grid-three">
      <Card variant="borderless" title="任务类型占比">
        <div style={{ display: 'flex', flexDirection: 'column', gap: 10, marginTop: 8 }}>
          {analytics?.task_type_share.map((item, index) => (
            <div key={item.label}>
              <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: 12, marginBottom: 4 }}>
                <span>{item.label}（{item.count}）</span><b>{item.ratio}%</b>
              </div>
              <div style={{ height: 8, borderRadius: 4, background: '#F2F3F5' }}>
                <div style={{ width: `${Math.min(100, item.ratio)}%`, height: '100%', borderRadius: 4, background: shareBarColors[index % shareBarColors.length] }} />
              </div>
            </div>
          ))}
          {analytics && analytics.task_type_share.length === 0 && <Typography.Text type="secondary">暂无已发布任务。</Typography.Text>}
        </div>
      </Card>
      <Card variant="borderless" title="校区活跃 TOP5">
        <div style={{ display: 'flex', flexDirection: 'column', gap: 4, marginTop: 4 }}>
          {analytics?.campus_top5.map((item, index) => (
            <div key={item.name} style={{ display: 'flex', alignItems: 'center', gap: 8, padding: '8px 0', borderBottom: '1px dashed #F0F1F3', fontSize: 13 }}>
              <span style={{ width: 8, height: 8, borderRadius: '50%', background: index < 3 ? '#0BBF63' : '#F2620A', flex: 'none' }} />
              <span style={{ fontWeight: 600 }}>{item.name}</span>
              <span style={{ marginLeft: 'auto', color: '#8a94a6', fontSize: 12 }}>DAU {item.dau.toLocaleString()} · 任务 {item.task_count.toLocaleString()}</span>
            </div>
          ))}
          {analytics && analytics.campus_top5.length === 0 && <Typography.Text type="secondary">暂无校区数据。</Typography.Text>}
        </div>
      </Card>
      <Card variant="borderless" title="实时告警">
        <div style={{ display: 'flex', flexDirection: 'column', gap: 4, marginTop: 4 }}>
          {analytics?.alerts.map((item) => (
            <div key={`${item.text}-${item.at}`} style={{ display: 'flex', alignItems: 'center', gap: 8, padding: '8px 0', borderBottom: '1px dashed #F0F1F3', fontSize: 13 }}>
              <span style={{ width: 8, height: 8, borderRadius: '50%', background: alertDotColors[item.level] ?? alertDotColors.info, flex: 'none' }} />
              <span>{item.text}</span>
              <span style={{ marginLeft: 'auto', color: '#8a94a6', fontSize: 12 }}>{relativeTime(item.at)}</span>
            </div>
          ))}
        </div>
      </Card>
    </div>
  </>
}
