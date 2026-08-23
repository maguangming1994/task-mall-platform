import { Alert, Button, DatePicker, Drawer, Input, Select, Space, Table, Tag, Typography, message } from 'antd'
import { useCallback, useEffect, useState } from 'react'
import { ApiError, listAdminAuditLogs, type AdminAuditLog } from '../../api/admin'
import { auditActionLabels, businessLabel } from '../../constants/businessLabels'

const formatDate = (value: string) => new Date(value).toLocaleString('zh-CN', { hour12: false })

const subjectLabels: Record<string, string> = {
  AdminUser: '管理员',
  Role: '角色',
  Task: '任务',
  Product: '商品',
  Order: '订单',
  Refund: '退款单',
  Exchange: '换货单',
  SystemSetting: '系统配置',
  MediaStorageProvider: '对象存储',
}
function subjectLabel(value: string | null) {
  if (!value) return '系统事件'
  const short = value.split('\\').pop() ?? value
  return subjectLabels[short] ?? short
}

function JsonSnapshot({ value }: { value: Record<string, unknown> | null }) {
  return value
    ? <pre className="audit-json">{JSON.stringify(value, null, 2)}</pre>
    : <Typography.Text type="secondary">无快照</Typography.Text>
}

export function AuditLogPanel() {
  const [items, setItems] = useState<AdminAuditLog[]>([])
  const [loading, setLoading] = useState(true)
  const [keyword, setKeyword] = useState('')
  const [action, setAction] = useState('')
  const [subjectType, setSubjectType] = useState('')
  const [range, setRange] = useState<[string, string] | null>(null)
  const [page, setPage] = useState(1)
  const [total, setTotal] = useState(0)
  const [scope, setScope] = useState<'all' | 'own'>('own')
  const [selected, setSelected] = useState<AdminAuditLog | null>(null)
  const [messageApi, contextHolder] = message.useMessage()

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const result = await listAdminAuditLogs({
        keyword,
        action,
        subject_type: subjectType,
        started_at: range?.[0],
        ended_at: range?.[1],
        page,
        page_size: 20,
      })
      setItems(result.items)
      setTotal(result.pagination.total)
      setScope(result.scope)
    } catch (error) {
      messageApi.error(error instanceof ApiError ? error.message : '审计日志加载失败。')
    } finally { setLoading(false) }
  }, [action, keyword, messageApi, page, range, subjectType])

  useEffect(() => { void load() }, [load])

  return <>
    {contextHolder}
    <Alert showIcon type="info" message={scope === 'all' ? '当前账号可查看全平台审计日志；敏感字段已在服务端脱敏。' : '当前账号仅可查看本人操作记录；敏感字段已在服务端脱敏。'} style={{ marginBottom: 16 }} />
    <div className="audit-filter-bar">
      <Input.Search allowClear placeholder="请求编号、对象编号、原因或操作人" onSearch={(value) => { setKeyword(value.trim()); setPage(1) }} style={{ width: 300 }} />
      <Select allowClear showSearch placeholder="操作类型" value={action || undefined} onChange={(value) => { setAction(value ?? ''); setPage(1) }} style={{ width: 190 }} options={Object.entries(auditActionLabels).map(([value, label]) => ({ value, label }))} />
      <Select allowClear placeholder="业务对象" value={subjectType || undefined} onChange={(value) => { setSubjectType(value ?? ''); setPage(1) }} style={{ width: 150 }} options={Object.entries(subjectLabels).map(([value, label]) => ({ value, label }))} />
      <DatePicker.RangePicker showTime onChange={(values) => { setRange(values ? [values[0]!.toISOString(), values[1]!.toISOString()] : null); setPage(1) }} />
      <Button onClick={() => void load()}>刷新</Button>
    </div>
    <Table rowKey="id" loading={loading} dataSource={items} scroll={{ x: 1100 }} pagination={{ current: page, pageSize: 20, total, showSizeChanger: false, showTotal: (value) => `共 ${value} 条`, onChange: setPage }} columns={[
      { title: '时间', width: 180, render: (_: unknown, item: AdminAuditLog) => formatDate(item.created_at) },
      { title: '操作人', width: 150, render: (_: unknown, item: AdminAuditLog) => <Space direction="vertical" size={2}><strong>{item.actor.display_name || '系统任务'}</strong>{item.actor.account && <Typography.Text type="secondary">{item.actor.account}</Typography.Text>}</Space> },
      { title: '操作', width: 180, render: (_: unknown, item: AdminAuditLog) => <Tag color="cyan">{businessLabel(auditActionLabels, item.action, item.action)}</Tag> },
      { title: '业务对象', width: 190, render: (_: unknown, item: AdminAuditLog) => <Space direction="vertical" size={2}><span>{subjectLabel(item.subject_type)}</span><Typography.Text copyable={Boolean(item.subject_id)} type="secondary">{item.subject_id ?? '—'}</Typography.Text></Space> },
      { title: '操作原因', dataIndex: 'reason', ellipsis: true, render: (value: string | null) => value || '未填写' },
      { title: '请求编号', width: 180, dataIndex: 'request_id', render: (value: string | null) => value ? <Typography.Text copyable>{value}</Typography.Text> : '—' },
      { title: '操作', fixed: 'right', width: 90, render: (_: unknown, item: AdminAuditLog) => <Button size="small" onClick={() => setSelected(item)}>查看差异</Button> },
    ]} />
    <Drawer title="操作审计详情" width={760} open={Boolean(selected)} onClose={() => setSelected(null)} destroyOnHidden>
      {selected && <Space direction="vertical" size={18} style={{ width: '100%' }}>
        <div className="audit-detail-meta">
          <div><Typography.Text type="secondary">操作</Typography.Text><strong>{businessLabel(auditActionLabels, selected.action, selected.action)}</strong></div>
          <div><Typography.Text type="secondary">操作人</Typography.Text><strong>{selected.actor.display_name || '系统任务'}</strong></div>
          <div><Typography.Text type="secondary">时间</Typography.Text><strong>{formatDate(selected.created_at)}</strong></div>
          <div><Typography.Text type="secondary">对象</Typography.Text><strong>{subjectLabel(selected.subject_type)} · {selected.subject_id ?? '—'}</strong></div>
        </div>
        <Alert showIcon type="warning" message="审计记录只追加不可修改；密码、令牌、密钥、身份证号和完整手机号等字段不会在此展示。" />
        <div><Typography.Title level={5}>操作原因</Typography.Title><Typography.Paragraph>{selected.reason || '未填写操作原因'}</Typography.Paragraph></div>
        <div className="audit-diff-grid"><section><Typography.Title level={5}>修改前</Typography.Title><JsonSnapshot value={selected.before} /></section><section><Typography.Title level={5}>修改后</Typography.Title><JsonSnapshot value={selected.after} /></section></div>
      </Space>}
    </Drawer>
  </>
}
