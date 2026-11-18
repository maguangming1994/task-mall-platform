import { useCallback, useEffect, useMemo, useState } from 'react'
import { Alert, Button, Descriptions, Empty, Form, Input, InputNumber, Modal, Select, Spin, Table, Timeline, message } from 'antd'
import type { ColumnsType } from 'antd/es/table'
import {
  ApiError,
  campusOptions,
  forceUserCampus,
  listManagedEndUsers,
  userCampusHistory,
  verifyAdminRecent,
  type ManagedEndUser,
  type UserCampusHistory,
  type UserCampusOption,
} from '../../api/admin'
import { Links, PageHead, Pill, UCell, type PillTone } from '../../components/xq'
import { useAdminAuth } from '../auth/AdminAuthContext'

const fmtDate = (v?: string | null) => (v ? new Date(v).toLocaleDateString('zh-CN') : '—')
const fmtTime = (v?: string | null) => (v ? new Date(v).toLocaleString('zh-CN', { hour12: false }) : '—')

interface ForceCampusFormValues {
  campus_id: string
  grade_year?: number | null
  major?: string
  college?: string
  reason: string
  current_password: string
  mfa_code?: string
}

const statusPills: Record<string, { tone: PillTone; label: string }> = {
  active: { tone: 'green', label: '正常' },
  disabled: { tone: 'gray', label: '禁用' },
  cancelled: { tone: 'orange', label: '注销中' },
}

export function UsersPage() {
  const { admin } = useAdminAuth()
  const [messageApi, contextHolder] = message.useMessage()
  const [users, setUsers] = useState<ManagedEndUser[]>([])
  const [status, setStatus] = useState('')
  const [role, setRole] = useState('')
  const [keyword, setKeyword] = useState('')
  const [applied, setApplied] = useState({ status: '', keyword: '' })
  const [appliedRole, setAppliedRole] = useState('')
  const [loading, setLoading] = useState(false)
  const [detail, setDetail] = useState<ManagedEndUser | null>(null)
  const [forceTarget, setForceTarget] = useState<ManagedEndUser | null>(null)
  const [forceSubmitting, setForceSubmitting] = useState(false)
  const [campusOpts, setCampusOpts] = useState<UserCampusOption[] | null>(null)
  const [optsLoading, setOptsLoading] = useState(false)
  const [historyTarget, setHistoryTarget] = useState<ManagedEndUser | null>(null)
  const [history, setHistory] = useState<UserCampusHistory | null>(null)
  const [historyLoading, setHistoryLoading] = useState(false)
  const [forceForm] = Form.useForm<ForceCampusFormValues>()

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

  const ensureCampusOptions = useCallback(async () => {
    if (campusOpts) return
    setOptsLoading(true)
    try {
      setCampusOpts((await campusOptions()).items)
    } catch (error) {
      messageApi.error(error instanceof ApiError ? error.message : '学校候选加载失败。')
    } finally {
      setOptsLoading(false)
    }
  }, [campusOpts, messageApi])

  useEffect(() => {
    if (!forceTarget) return
    forceForm.setFieldsValue({
      campus_id: forceTarget.campus?.id,
      grade_year: forceTarget.campus_profile?.grade_year ?? null,
      major: forceTarget.campus_profile?.major ?? '',
      college: forceTarget.campus_profile?.college ?? '',
      reason: '',
      current_password: '',
      mfa_code: '',
    })
  }, [forceTarget, forceForm])

  const openForceCampus = (u: ManagedEndUser) => {
    setForceTarget(u)
    void ensureCampusOptions()
  }

  const submitForceCampus = async (values: ForceCampusFormValues) => {
    if (!forceTarget) return
    setForceSubmitting(true)
    try {
      await verifyAdminRecent(values.current_password, values.mfa_code || undefined)
      await forceUserCampus(forceTarget.id, {
        campus_id: values.campus_id,
        grade_year: values.grade_year ?? null,
        major: values.major?.trim() || null,
        college: values.college?.trim() || null,
        reason: values.reason.trim(),
      })
      messageApi.success(`「${forceTarget.nickname}」已改校，操作已写入审计。`)
      setForceTarget(null)
      forceForm.resetFields()
      await load()
    } catch (error) {
      messageApi.error(error instanceof ApiError ? error.message : '强制改校失败。')
    } finally {
      setForceSubmitting(false)
    }
  }

  const openCampusHistory = async (u: ManagedEndUser) => {
    setHistoryTarget(u)
    setHistory(null)
    setHistoryLoading(true)
    try {
      setHistory(await userCampusHistory(u.id))
    } catch (error) {
      messageApi.error(error instanceof ApiError ? error.message : '换校历史加载失败。')
    } finally {
      setHistoryLoading(false)
    }
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
          { label: '强制改校', onClick: () => openForceCampus(r) },
          { label: '换校历史', onClick: () => void openCampusHistory(r) },
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
          onChange={(v) => { const next = v ?? ''; setStatus(next); setApplied((prev) => ({ ...prev, status: next })) }}
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
          onChange={(v) => { const next = v ?? ''; setRole(next); setAppliedRole(next) }}
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

      <Modal
        title={forceTarget ? `强制改校：${forceTarget.nickname}` : '强制改校'}
        open={Boolean(forceTarget)}
        onCancel={() => { setForceTarget(null); forceForm.resetFields() }}
        onOk={() => forceForm.submit()}
        okText="确认改校"
        confirmLoading={forceSubmitting}
        destroyOnHidden
        width={560}
      >
        <Alert
          showIcon
          type="warning"
          style={{ marginBottom: 16 }}
          message="强制改校会跳过 30 天冷却与未履约订单校验，并写入审计日志；若目标学校为严格隔离，用户此后仅能浏览该校内容。"
        />
        <Form form={forceForm} layout="vertical" onFinish={submitForceCampus}>
          <Form.Item name="campus_id" label="目标学校" rules={[{ required: true, message: '请选择目标学校' }]}>
            <Select
              showSearch
              optionFilterProp="label"
              loading={optsLoading}
              placeholder="选择目标学校"
              options={(campusOpts ?? []).map((campus) => ({
                value: campus.id,
                label: `${campus.name}${campus.city ? ` · ${campus.city}` : ''}${campus.isolation_mode === 'strict' ? '（严格隔离）' : ''}`,
              }))}
            />
          </Form.Item>
          <div style={{ display: 'flex', gap: 12 }}>
            <Form.Item name="grade_year" label="年级" style={{ flex: 1 }}>
              <InputNumber min={1900} max={new Date().getFullYear()} placeholder="如 2024" style={{ width: '100%' }} />
            </Form.Item>
            <Form.Item name="major" label="专业" style={{ flex: 1 }}>
              <Input maxLength={30} placeholder="选填" />
            </Form.Item>
          </div>
          <Form.Item name="college" label="学院">
            <Input maxLength={60} placeholder="选填" />
          </Form.Item>
          <Form.Item name="reason" label="改校原因" rules={[{ required: true, whitespace: true, min: 5, max: 255, message: '请填写至少 5 个字的改校原因' }]}>
            <Input.TextArea rows={3} maxLength={255} showCount placeholder="说明本次改校原因，将写入审计日志" />
          </Form.Item>
          <Form.Item name="current_password" label="当前管理员密码" rules={[{ required: true, message: '请输入当前管理员密码' }]}>
            <Input.Password autoComplete="current-password" />
          </Form.Item>
          {admin?.requires_mfa && (
            <Form.Item name="mfa_code" label="动态验证码或恢复码" rules={[{ required: true, message: '请输入动态验证码或恢复码' }]}>
              <Input autoComplete="one-time-code" />
            </Form.Item>
          )}
        </Form>
      </Modal>

      <Modal
        title={historyTarget ? `换校历史：${historyTarget.nickname}` : '换校历史'}
        open={Boolean(historyTarget)}
        onCancel={() => { setHistoryTarget(null); setHistory(null) }}
        footer={<Button type="primary" onClick={() => { setHistoryTarget(null); setHistory(null) }}>关闭</Button>}
        width={620}
      >
        <Spin spinning={historyLoading}>
          {history && (
            <div>
              <div style={{ marginBottom: 16, fontSize: 13, lineHeight: 2 }}>
                <div>
                  <b>当前学校：</b>
                  {history.current
                    ? <>{history.current.name} <Pill tone={history.current.isolation_mode === 'strict' ? 'blue' : 'gray'}>{history.current.isolation_mode === 'strict' ? '严格隔离' : '传统模式'}</Pill></>
                    : '未绑定'}
                </div>
                <div><b>年级：</b>{history.profile.grade_year ?? '未设置'}　<b>专业：</b>{history.profile.major ?? '未设置'}　<b>学院：</b>{history.profile.college ?? '未设置'}</div>
              </div>
              {history.items.length ? (
                <Timeline
                  items={history.items.map((item) => ({
                    color: item.changed_by_admin ? 'blue' : 'green',
                    children: (
                      <div>
                        <div style={{ fontWeight: 600 }}>
                          {item.from?.name ?? '未绑定'} → {item.to.name}{' '}
                          <Pill tone={item.changed_by_admin ? 'blue' : 'gray'}>{item.changed_by_admin ? '管理员操作' : '用户自助'}</Pill>
                        </div>
                        <div style={{ color: 'var(--xq-muted)', fontSize: 12 }}>{fmtTime(item.created_at)}</div>
                        <div style={{ fontSize: 13 }}>原因：{item.reason}</div>
                      </div>
                    ),
                  }))}
                />
              ) : <Empty description="暂无换校记录" />}
            </div>
          )}
        </Spin>
      </Modal>

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
