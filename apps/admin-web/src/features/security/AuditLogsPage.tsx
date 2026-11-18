import { Button, DatePicker, Input, Select, Table, message } from 'antd'
import { useCallback, useEffect, useState } from 'react'
import { ApiError, listAdminAuditLogs, type AdminAuditLog } from '../../api/admin'
import { auditActionLabels, businessLabel } from '../../constants/businessLabels'
import { PageHead, Pill, UCell, type PillTone } from '../../components/xq'

const subjectLabels: Record<string, string> = {
  AdminUser: '管理员', admin_user: '管理员', Role: '角色', role: '角色', Task: '任务', task: '任务', Product: '商品', product: '商品', Order: '订单', order: '订单',
  Refund: '退款单', refund: '退款单', Exchange: '换货单', exchange: '换货单', SystemSetting: '系统配置', system_setting: '系统配置', MediaStorageProvider: '对象存储',
}
function subjectLabel(value: string | null) {
  if (!value) return '系统事件'
  const short = value.split('\\').pop() ?? value
  return subjectLabels[short] ?? short
}

type Risk = 'high' | 'sensitive' | 'normal'
const riskMeta: Record<Risk, { label: string; tone: PillTone }> = {
  high: { label: '高危', tone: 'red' },
  sensitive: { label: '敏感', tone: 'orange' },
  normal: { label: '普通', tone: 'gray' },
}
function riskOf(action: string): Risk {
  if (/admin\.(role|user)\.|setting\.published|delete|permission/.test(action)) return 'high'
  if (/reviewed|withdraw|asset|export|manual|status_updated/.test(action)) return 'sensitive'
  return 'normal'
}

const fmtTime = (value: string) => new Date(value).toLocaleString('zh-CN', { month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false }).replace(/\//g, '-')

type DayRange = { start: string; end: string }

interface AppliedFilters { subjectType: string; actor: string; risk: string; keyword: string; day: DayRange | null }

export function AuditLogsPage() {
  const [items, setItems] = useState<AdminAuditLog[]>([])
  const [loading, setLoading] = useState(true)
  const [page, setPage] = useState(1)
  const [total, setTotal] = useState(0)
  const [subjectType, setSubjectType] = useState('')
  const [actor, setActor] = useState('')
  const [risk, setRisk] = useState('')
  const [keyword, setKeyword] = useState('')
  const [day, setDay] = useState<DayRange | null>(null)
  const [applied, setApplied] = useState<AppliedFilters>({ subjectType: '', actor: '', risk: '', keyword: '', day: null })
  const [messageApi, contextHolder] = message.useMessage()

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const result = await listAdminAuditLogs({
        subject_type: applied.subjectType || undefined,
        keyword: applied.keyword || undefined,
        started_at: applied.day?.start,
        ended_at: applied.day?.end,
        page,
        page_size: 10,
      })
      let list = result.items
      if (applied.actor) list = list.filter((item) => item.actor.display_name.includes(applied.actor) || (item.actor.account ?? '').includes(applied.actor))
      if (applied.risk) list = list.filter((item) => riskOf(item.action) === applied.risk)
      setItems(list)
      setTotal(result.pagination.total)
    } catch (error) {
      messageApi.error(error instanceof ApiError ? error.message : '操作日志加载失败。')
    } finally {
      setLoading(false)
    }
  }, [applied, messageApi, page])

  useEffect(() => { void load() }, [load])

  const search = () => {
    setPage(1)
    setApplied({ subjectType, actor: actor.trim(), risk, keyword: keyword.trim(), day })
  }

  const reset = () => {
    setSubjectType('')
    setActor('')
    setRisk('')
    setKeyword('')
    setDay(null)
    setPage(1)
    setApplied({ subjectType: '', actor: '', risk: '', keyword: '', day: null })
  }

  const exportCsv = () => {
    const rows = [['时间', '操作人', '账号', '模块', '操作内容', '原因', '风险', '请求ID']]
    items.forEach((item) => {
      rows.push([
        new Date(item.created_at).toLocaleString('zh-CN', { hour12: false }),
        item.actor.display_name || '系统任务',
        item.actor.account ?? '',
        subjectLabel(item.subject_type),
        businessLabel(auditActionLabels, item.action, item.action),
        item.reason ?? '',
        riskMeta[riskOf(item.action)].label,
        item.request_id ?? '',
      ])
    })
    const blob = new Blob([`﻿${rows.map((row) => row.map((cell) => `"${cell.replace(/"/g, '""')}"`).join(',')).join('\n')}`], { type: 'text/csv;charset=utf-8' })
    const link = document.createElement('a')
    link.href = URL.createObjectURL(blob)
    link.download = `操作日志-${new Date().toISOString().slice(0, 10)}.csv`
    link.click()
    URL.revokeObjectURL(link.href)
  }

  return (
    <div className="xq-page">
      {contextHolder}
      <PageHead title="操作日志" extra={<Button onClick={exportCsv}>导出日志</Button>} />

      <div className="xq-card xq-filter" style={{ marginBottom: 16 }}>
        <Select allowClear placeholder="全部模块" value={subjectType || undefined} onChange={(value) => { const next = value ?? ''; setSubjectType(next); setPage(1); setApplied((prev) => ({ ...prev, subjectType: next })) }} options={Object.entries(subjectLabels).map(([value, label]) => ({ value, label }))} />
        <Input allowClear placeholder="操作人" style={{ maxWidth: 160 }} value={actor} onChange={(event) => setActor(event.target.value)} />
        <Select allowClear placeholder="风险等级" value={risk || undefined} onChange={(value) => { const next = value ?? ''; setRisk(next); setPage(1); setApplied((prev) => ({ ...prev, risk: next })) }} options={(Object.entries(riskMeta) as Array<[Risk, (typeof riskMeta)[Risk]]>).map(([value, meta]) => ({ value, label: meta.label }))} />
        <Input allowClear placeholder="操作内容/对象" value={keyword} onChange={(event) => setKeyword(event.target.value)} onPressEnter={search} />
        <DatePicker onChange={(value) => { const next = value ? { start: value.startOf('day').toISOString(), end: value.endOf('day').toISOString() } : null; setDay(next); setPage(1); setApplied((prev) => ({ ...prev, day: next })) }} placeholder="年 / 月 / 日" />
        <Button type="primary" onClick={search}>查询</Button>
        <Button onClick={reset}>重置</Button>
      </div>

      <div className="xq-card xq-table">
        <Table
          rowKey="id"
          size="middle"
          loading={loading}
          dataSource={items}
          pagination={{ current: page, pageSize: 10, total, showTotal: (value) => `共 ${value} 条`, onChange: setPage }}
          columns={[
            { title: '时间', width: 140, render: (_: unknown, item: AdminAuditLog) => fmtTime(item.created_at) },
            { title: '操作人', width: 160, render: (_: unknown, item: AdminAuditLog) => <UCell name={item.actor.display_name || '系统任务'} sub={item.actor.account} /> },
            { title: '模块', width: 110, render: (_: unknown, item: AdminAuditLog) => subjectLabel(item.subject_type) },
            {
              title: '操作内容', render: (_: unknown, item: AdminAuditLog) => (
                <span>
                  {businessLabel(auditActionLabels, item.action, item.action)}
                  {item.reason && <span style={{ color: 'var(--xq-muted)' }}>，{item.reason}</span>}
                </span>
              ),
            },
            { title: 'IP 指纹', width: 130, render: (_: unknown, item: AdminAuditLog) => (item.ip_fingerprint ? <span style={{ fontSize: 12 }}>{item.ip_fingerprint}</span> : '—') },
            { title: '风险', width: 90, render: (_: unknown, item: AdminAuditLog) => { const meta = riskMeta[riskOf(item.action)]; return <Pill tone={meta.tone}>{meta.label}</Pill> } },
            { title: '请求ID', width: 120, render: (_: unknown, item: AdminAuditLog) => (item.request_id ? <span style={{ color: 'var(--xq-link)', fontSize: 12 }}>{item.request_id.slice(0, 10)}…</span> : '—') },
          ]}
        />
      </div>
    </div>
  )
}
