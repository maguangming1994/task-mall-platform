import { useEffect, useState } from 'react'
import { Result, Skeleton } from 'antd'
import { SafetyCertificateOutlined } from '@ant-design/icons'
import {
  getOperationsAnalytics,
  getOperationsDashboard,
  listSecondHandItems,
  type OperationsAnalytics,
  type OperationsMetrics,
} from '../../api/admin'
import { useAdminAuth } from '../auth/AdminAuthContext'
import { navigate } from '../../routes'

function Delta({ value, suffix = '较昨日' }: { value?: number | null; suffix?: string }) {
  if (value === undefined || value === null) return <div className="d">&nbsp;</div>
  const up = value >= 0
  return <div className={`d ${up ? 'up' : 'down'}`}>{up ? '↑' : '↓'} {Math.abs(value)}% {suffix}</div>
}

export function DashboardPage() {
  const { admin } = useAdminAuth()
  const [metrics, setMetrics] = useState<OperationsMetrics | null>(null)
  const [analytics, setAnalytics] = useState<OperationsAnalytics | null>(null)
  const [shPending, setShPending] = useState(0)
  const canView = admin?.permissions.includes('operations.manage') ?? false

  useEffect(() => {
    if (!canView) return
    void getOperationsDashboard().then(setMetrics).catch(() => setMetrics(null))
    void getOperationsAnalytics().then(setAnalytics).catch(() => setAnalytics(null))
    void listSecondHandItems({ audit_status: 'pending', page_size: 1 })
      .then((r) => setShPending(r.pagination?.total ?? r.items?.length ?? 0))
      .catch(() => setShPending(0))
  }, [canView])

  if (!canView) {
    return (
      <div className="xq-page">
        <div className="xq-page-head"><h2>工作台</h2></div>
        <div className="xq-card">
          <Result
            icon={<SafetyCertificateOutlined style={{ color: '#16a34a' }} />}
            title="请选择左侧已授权的业务模块"
            subTitle="平台运营汇总仅向具备运营管理权限的角色展示。"
          />
        </div>
      </div>
    )
  }

  const afterSaleTotal = (metrics?.after_sales.pending_refunds ?? 0) + (metrics?.after_sales.pending_exchanges ?? 0)
  const trend = analytics?.task_claim_trend ?? []
  const maxCount = Math.max(1, ...trend.map((t) => t.count))
  const hotIdx = trend.reduce((mi, t, i) => (t.count > (trend[mi]?.count ?? -1) ? i : mi), 0)

  const todos = [
    { label: '任务审核待处理', value: metrics?.tasks.pending_reviews ?? 0, color: '#22c55e', path: '/tasks/audit' },
    { label: '闲置物品审核', value: shPending, color: '#d97706', path: '/mall/second-hand' },
    { label: '提现申请待审', value: metrics?.finance.pending_withdrawals ?? 0, color: '#dc2626', path: '/finance/withdrawals' },
    { label: '售后工单待处理', value: afterSaleTotal, color: '#2563eb', path: '/after-sales' },
    { label: '用户举报待处理', value: metrics?.community.open_reports ?? 0, color: '#f59e0b', path: '/community' },
  ]

  const quick = [
    { label: '任务审核', cls: 'green', path: '/tasks/audit' },
    { label: '闲置审核', cls: 'orange', path: '/mall/second-hand' },
    { label: '提现审核', cls: 'blue', path: '/finance/withdrawals' },
    { label: '工单客服', cls: 'gray', path: '/support/tickets' },
  ]

  return (
    <div className="xq-page">
      <div className="xq-page-head"><h2>工作台</h2></div>
      {!metrics && !analytics ? <Skeleton active paragraph={{ rows: 6 }} /> : (
        <>
          <div className="xq-stats">
            <div className="xq-stat">
              <div className="t">今日新增任务</div>
              <div className="v">{metrics?.tasks.created_today ?? 0}</div>
              <div className="d up">今日 0 点起创建</div>
            </div>
            <div className="xq-stat">
              <div className="t">待审核事项</div>
              <div className="v" style={{ color: '#d97706' }}>{(metrics?.tasks.pending_reviews ?? 0) + shPending}</div>
              <div className="d" style={{ color: '#dc2626' }}>任务 + 闲置</div>
            </div>
            <div className="xq-stat">
              <div className="t">今日订单金额</div>
              <div className="v">¥{((analytics?.today.mall_gmv_cents ?? 0) / 100).toLocaleString('zh-CN')}</div>
              <Delta value={analytics?.today.mall_gmv_change_pct} />
            </div>
            <div className="xq-stat">
              <div className="t">待处理售后</div>
              <div className="v" style={{ color: '#dc2626' }}>{afterSaleTotal}</div>
              <div className="d down">退款 + 换货</div>
            </div>
          </div>

          <div style={{ display: 'grid', gridTemplateColumns: '1fr 340px', gap: 16, marginBottom: 16 }}>
            <div className="xq-card">
              <p className="xq-card-title">近7天任务与订单趋势</p>
              <div className="xq-bars">
                {trend.map((t, i) => (
                  <div className="col" key={t.date}>
                    <span className="val">{t.count}</span>
                    <div className={`bar ${i === hotIdx ? 'hot' : ''}`} style={{ height: `${Math.max(6, (t.count / maxCount) * 80)}%` }} title={`任务领取 ${t.count}`} />
                    <span className="date">{t.date.slice(5)}</span>
                  </div>
                ))}
                {!trend.length && <div className="xq-empty" style={{ flex: 1 }}>暂无趋势数据</div>}
              </div>
            </div>
            <div className="xq-card">
              <p className="xq-card-title">待办事项</p>
              <div className="xq-todo">
                {todos.map((t) => (
                  <div className="xq-todo-item" key={t.label} style={{ cursor: 'pointer' }} onClick={() => navigate(t.path)}>
                    <span><i className="dot" style={{ background: t.color }} />{t.label}</span>
                    <b>{t.value}</b>
                  </div>
                ))}
              </div>
            </div>
          </div>

          <div className="xq-card">
            <p className="xq-card-title">快捷入口</p>
            <div className="xq-quick">
              {quick.map((q) => <span key={q.label} className={q.cls} onClick={() => navigate(q.path)}>{q.label}</span>)}
            </div>
          </div>
        </>
      )}
    </div>
  )
}
