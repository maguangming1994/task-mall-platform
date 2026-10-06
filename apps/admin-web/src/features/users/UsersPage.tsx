import { useCallback, useEffect, useMemo, useState } from 'react'
import { Button, Descriptions, Input, Modal, Select, Table, message } from 'antd'
import type { ColumnsType } from 'antd/es/table'
import { listManagedEndUsers, type ManagedEndUser } from '../../api/admin'
import { Links, PageHead, Pill, UCell, type PillTone } from '../../components/xq'

const fmtDate = (v?: string | null) => (v ? new Date(v).toLocaleDateString('zh-CN') : '—')
const fmtTime = (v?: string | null) => (v ? new Date(v).toLocaleString('zh-CN', { hour12: false }) : '—')

const statusPills: Record<string, { tone: PillTone; label: string }> = {
  active: { tone: 'green', label: '正常' },
  disabled: { tone: 'gray', label: '禁用' },
  cancelled: { tone: 'orange', label: '注销中' },
}

export function UsersPage() {
  const [messageApi, contextHolder] = message.useMessage()
  const [users, setUsers] = useState<ManagedEndUser[]>([])
  const [status, setStatus] = useState('')
  const [role, setRole] = useState('')
  const [keyword, setKeyword] = useState('')
  const [applied, setApplied] = useState({ status: '', keyword: '' })
  const [appliedRole, setAppliedRole] = useState('')
  const [loading, setLoading] = useState(false)
  const [detail, setDetail] = useState<ManagedEndUser | null>(null)

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const result = await listManagedEndUsers({ keyword: applied.keyword || undefined, status: applied.status || undefined })
      setUsers(result.items)
    } catch {
      messageApi.error('用户数据加载失败，请稍后重试。')
    } finally {
      setLoading(false)
    }
  }, [applied, messageApi])

  useEffect(() => { void load() }, [load])

  const roleOptions = useMemo(() => {
    const names = Array.from(new Set(users.map((u) => u.level?.name).filter((n): n is string => Boolean(n))))
    return names.map((n) => ({ value: n, label: n }))
  }, [users])

  const rows = useMemo(() => (appliedRole ? users.filter((u) => u.level?.name === appliedRole) : users), [users, appliedRole])

  const exportCsv = () => {
    if (!rows.length) { messageApi.info('当前没有可导出的用户。'); return }
    const header = '用户ID,昵称,角色,手机号,注册时间,积分余额,状态\n'
    const body = rows.map((u) => [u.id, u.nickname, u.level?.name ?? '', u.phone_masked ?? '', fmtDate(u.created_at), u.assets.points_available, statusPills[u.status]?.label ?? u.status].join(',')).join('\n')
    const blob = new Blob([`﻿${header}${body}`], { type: 'text/csv;charset=utf-8' })
    const a = document.createElement('a')
    a.href = URL.createObjectURL(blob)
    a.download = `用户列表_${new Date().toISOString().slice(0, 10)}.csv`
    a.click()
    URL.revokeObjectURL(a.href)
  }

  const toggleDisabled = (u: ManagedEndUser) => {
    Modal.info({
      title: u.status === 'disabled' ? '启用用户' : '禁用用户',
      content: `「${u.nickname}」的${u.status === 'disabled' ? '启用' : '禁用'}操作需超级管理员在权限与审计模块执行，当前版本暂未在此开放。`,
      okText: '知道了',
    })
  }

  const columns: ColumnsType<ManagedEndUser> = [
    { title: '用户ID', dataIndex: 'id' },
    { title: '用户信息', render: (_, r) => <UCell name={r.nickname} sub={r.level?.name ?? '普通用户'} /> },
    { title: '手机号', render: (_, r) => r.phone_masked ?? '—' },
    { title: '注册时间', render: (_, r) => fmtDate(r.created_at) },
    { title: '积分余额', render: (_, r) => r.assets.points_available.toLocaleString() },
    { title: '状态', render: (_, r) => { const p = statusPills[r.status] ?? { tone: 'gray' as PillTone, label: r.status }; return <Pill tone={p.tone}>{p.label}</Pill> } },
    {
      title: '操作',
      render: (_, r) => (
        <Links items={[
          { label: '详情', onClick: () => setDetail(r) },
          { label: '编辑', onClick: () => setDetail(r) },
          r.status !== 'cancelled' && (r.status === 'disabled'
            ? { label: '启用', onClick: () => toggleDisabled(r) }
            : { label: '禁用', danger: true, onClick: () => toggleDisabled(r) }),
        ]} />
      ),
    },
  ]

  return (
    <div className="xq-page">
      {contextHolder}
      <PageHead title="用户管理" />

      <div className="xq-card xq-filter" style={{ marginBottom: 16 }}>
        <Select
          value={status || undefined}
          onChange={(v) => setStatus(v ?? '')}
          allowClear
          placeholder="全部状态"
          style={{ minWidth: 120 }}
          options={[
            { value: 'active', label: '正常' },
            { value: 'disabled', label: '禁用' },
            { value: 'cancelled', label: '注销中' },
          ]}
        />
        <Select
          value={role || undefined}
          onChange={(v) => setRole(v ?? '')}
          allowClear
          placeholder="全部角色"
          style={{ minWidth: 120 }}
          options={roleOptions}
        />
        <Input placeholder="昵称/手机号/ID" value={keyword} onChange={(e) => setKeyword(e.target.value)} onPressEnter={() => { setApplied({ status, keyword }); setAppliedRole(role) }} style={{ maxWidth: 220 }} />
        <Button type="primary" onClick={() => { setApplied({ status, keyword }); setAppliedRole(role) }}>查询</Button>
        <Button onClick={() => { setStatus(''); setRole(''); setKeyword(''); setApplied({ status: '', keyword: '' }); setAppliedRole('') }}>重置</Button>
        <Button onClick={exportCsv}>导出</Button>
      </div>

      <div className="xq-card xq-table">
        <Table rowKey="id" size="middle" loading={loading} columns={columns} dataSource={rows} pagination={{ pageSize: 10, showTotal: (t) => `共 ${t} 条` }} />
      </div>

      <Modal title="用户详情" open={!!detail} footer={null} onCancel={() => setDetail(null)} width={560}>
        {detail && (
          <Descriptions
            column={1}
            size="small"
            bordered
            items={[
              { key: 'id', label: '用户ID', children: detail.id },
              { key: 'nick', label: '昵称', children: detail.nickname },
              { key: 'level', label: '角色等级', children: detail.level?.name ?? '普通用户' },
              { key: 'phone', label: '手机号', children: detail.phone_masked ?? '—' },
              { key: 'status', label: '状态', children: statusPills[detail.status]?.label ?? detail.status },
              { key: 'points', label: '积分', children: `可用 ${detail.assets.points_available.toLocaleString()} / 冻结 ${detail.assets.points_frozen.toLocaleString()}` },
              { key: 'wallet', label: '钱包', children: `可用 ¥${(detail.assets.wallet_available_cents / 100).toFixed(2)} / 冻结 ¥${(detail.assets.wallet_frozen_cents / 100).toFixed(2)}` },
              { key: 'coupon', label: '可用优惠券', children: detail.assets.coupons_available },
              { key: 'task', label: '任务数据', children: `领取 ${detail.task_metrics.claimed} 次 / 通过 ${detail.task_metrics.approved} 次` },
              { key: 'verify', label: '认证情况', children: `手机 ${detail.verification.phone ? '已验证' : '未验证'} / 资料 ${detail.verification.profile ? '已完善' : '未完善'} / 微信 ${detail.verification.wechat ? '已绑定' : '未绑定'}` },
              { key: 'created', label: '注册时间', children: fmtTime(detail.created_at) },
              { key: 'login', label: '最近登录', children: fmtTime(detail.last_login_at) },
            ]}
          />
        )}
      </Modal>
    </div>
  )
}
