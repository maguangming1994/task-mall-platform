import { Button, Form, Input, Modal, Select, Switch, Table, Tooltip, message } from 'antd'
import { useCallback, useEffect, useState } from 'react'
import {
  ApiError,
  createManagedCampus,
  listManagedCampuses,
  updateManagedCampus,
  type ManagedCampus,
  type ManagedCampusList,
} from '../../api/admin'
import { Links, PageHead, Pill, StatCard, UCell } from '../../components/xq'
import { useAdminAuth } from '../auth/AdminAuthContext'
import { CampusModuleDrawer } from './CampusModuleDrawer'

const DEFAULT_CAMPUS_ID = 'default-campus'

function writeInputOf(row: ManagedCampus, override: Partial<Pick<ManagedCampus, 'code' | 'name' | 'status' | 'isolation_mode'>> = {}) {
  return {
    code: row.code,
    name: row.name,
    emblem_asset_id: row.emblem_asset_id,
    emblem_url: row.emblem_url,
    logo_asset_id: row.logo_asset_id,
    logo_url: row.logo_url,
    province: row.province,
    city: row.city,
    district: row.district,
    address: row.address,
    latitude: row.latitude,
    longitude: row.longitude,
    status: row.status,
    isolation_mode: row.isolation_mode,
    sort_order: row.sort_order,
    ...override,
  }
}

export function CampusesPage() {
  const { admin } = useAdminAuth()
  const [moduleCampus, setModuleCampus] = useState<ManagedCampus | null>(null)
  const [data, setData] = useState<ManagedCampusList | null>(null)
  const [loading, setLoading] = useState(true)
  const [page, setPage] = useState(1)
  const [status, setStatus] = useState('')
  const [isolation, setIsolation] = useState('')
  const [kw, setKw] = useState('')
  const [applied, setApplied] = useState({ status: '', isolation: '', kw: '' })
  const [creating, setCreating] = useState(false)
  const [detail, setDetail] = useState<ManagedCampus | null>(null)
  const [editing, setEditing] = useState<ManagedCampus | null>(null)
  const [submitting, setSubmitting] = useState(false)
  const [isolationBusy, setIsolationBusy] = useState<string | null>(null)
  const [createForm] = Form.useForm<{ name: string; code: string }>()
  const [editForm] = Form.useForm<{ name: string; code: string }>()
  const [messageApi, contextHolder] = message.useMessage()

  const load = useCallback(async () => {
    setLoading(true)
    try {
      setData(await listManagedCampuses({
        status: applied.status || undefined,
        isolation_mode: (applied.isolation as 'legacy' | 'strict') || undefined,
        keyword: applied.kw || undefined,
        page,
        page_size: 10,
      }))
    } catch (error) {
      messageApi.error(error instanceof ApiError ? error.message : '校区数据加载失败。')
    } finally {
      setLoading(false)
    }
  }, [applied, messageApi, page])

  useEffect(() => { void load() }, [load])

  const summary = data?.summary

  const createCampus = async () => {
    try {
      const values = await createForm.validateFields()
      setSubmitting(true)
      await createManagedCampus({
        code: values.code,
        name: values.name,
        emblem_asset_id: null,
        emblem_url: null,
        logo_asset_id: null,
        logo_url: null,
        province: null,
        city: null,
        district: null,
        address: null,
        latitude: null,
        longitude: null,
        status: 'active',
        sort_order: 0,
      })
      messageApi.success('校区已创建并开通。')
      setCreating(false)
      createForm.resetFields()
      await load()
    } catch (error) {
      if (error instanceof ApiError) messageApi.error(error.message)
    } finally {
      setSubmitting(false)
    }
  }

  const saveEdit = async () => {
    if (!editing) return
    try {
      const values = await editForm.validateFields()
      setSubmitting(true)
      await updateManagedCampus(editing.id, writeInputOf(editing, values))
      messageApi.success('校区信息已更新。')
      setEditing(null)
      await load()
    } catch (error) {
      if (error instanceof ApiError) messageApi.error(error.message)
    } finally {
      setSubmitting(false)
    }
  }

  const openCampus = async (row: ManagedCampus) => {
    try {
      await updateManagedCampus(row.id, writeInputOf(row, { status: 'active' }))
      messageApi.success(`「${row.name}」已开通运营。`)
      await load()
    } catch (error) {
      messageApi.error(error instanceof ApiError ? error.message : '开通失败。')
    }
  }

  const toggleIsolation = (row: ManagedCampus) => {
    if (row.id === DEFAULT_CAMPUS_ID) return
    const next: ManagedCampus['isolation_mode'] = row.isolation_mode === 'strict' ? 'legacy' : 'strict'
    Modal.confirm({
      title: next === 'strict' ? `将「${row.name}」切换为严格隔离？` : `将「${row.name}」切换为传统模式？`,
      content: next === 'strict'
        ? '严格隔离后，该校仅展示本校用户产生的社区内容与闲置商品，跨校内容不可见。若该校仍有缺少校区归属的内容，切换会失败并提示先执行数据回填。'
        : '传统模式下该校用户可浏览全平台内容。切换后立即生效，并写入操作审计。',
      okText: next === 'strict' ? '确认严格隔离' : '确认切换传统',
      cancelText: '取消',
      onOk: async () => {
        setIsolationBusy(row.id)
        try {
          await updateManagedCampus(row.id, writeInputOf(row, { isolation_mode: next }))
          messageApi.success(next === 'strict' ? '已切换为严格隔离模式。' : '已切换为传统模式。')
          await load()
        } catch (error) {
          messageApi.error(error instanceof ApiError ? error.message : '隔离模式切换失败。')
        } finally {
          setIsolationBusy(null)
        }
      },
    })
  }

  const primaryAgentOf = (row: ManagedCampus) => row.agents.find((agent) => agent.is_primary) ?? row.agents[0] ?? null

  return (
    <div className="xq-page">
      {contextHolder}
      <PageHead title="校区管理" extra={<Button type="primary" onClick={() => setCreating(true)}>+ 新增校区</Button>} />

      <div className="xq-stats" style={{ gridTemplateColumns: 'repeat(5, minmax(0, 1fr))' }}>
        <StatCard title="已开通校区" value={summary?.active ?? '—'} delta={summary ? `覆盖 ${summary.total} 所高校` : undefined} />
        <StatCard title="校园代理" value={summary?.agent_total ?? '—'} delta={summary ? `缺少主代理 ${summary.without_primary_agent} 所校区` : undefined} />
        <StatCard title="校区总用户" value={summary ? summary.user_total.toLocaleString() : '—'} delta={summary ? `已停用校区 ${summary.disabled} 所` : undefined} />
        <StatCard title="严格隔离校区" value={summary?.isolation_strict ?? '—'} delta={summary ? `传统模式 ${summary.isolation_legacy} 所` : undefined} />
        <StatCard title="待审核代理申请" value="—" delta="暂无待审核申请" />
      </div>

      <div className="xq-card xq-filter" style={{ marginBottom: 16 }}>
        <Select allowClear placeholder="全部状态" value={status || undefined} onChange={(value) => { const next = value ?? ''; setStatus(next); setPage(1); setApplied((prev) => ({ ...prev, status: next })) }} options={[{ value: 'active', label: '运营中' }, { value: 'disabled', label: '已暂停' }]} />
        <Select allowClear placeholder="全部隔离模式" value={isolation || undefined} onChange={(value) => { const next = value ?? ''; setIsolation(next); setPage(1); setApplied((prev) => ({ ...prev, isolation: next })) }} options={[{ value: 'strict', label: '严格隔离' }, { value: 'legacy', label: '传统模式' }]} />
        <Input allowClear placeholder="校区名称/代理" value={kw} onChange={(event) => setKw(event.target.value)} onPressEnter={() => { setPage(1); setApplied({ status, isolation, kw: kw.trim() }) }} />
        <Button type="primary" onClick={() => { setPage(1); setApplied({ status, isolation, kw: kw.trim() }) }}>查询</Button>
      </div>

      <div className="xq-card xq-table">
        <Table
          rowKey="id"
          size="middle"
          loading={loading}
          dataSource={data?.items ?? []}
          pagination={{ current: page, pageSize: 10, total: data?.pagination.total ?? 0, showTotal: (total) => `共 ${total} 条`, onChange: setPage }}
          columns={[
            { title: '校区名称', render: (_: unknown, row: ManagedCampus) => <><strong>{row.name}</strong><div style={{ color: 'var(--xq-muted)', fontSize: 12 }}>编码 {row.code}</div></> },
            { title: '校园代理', width: 170, render: (_: unknown, row: ManagedCampus) => { const agent = primaryAgentOf(row); return agent ? <UCell name={agent.display_name} sub={agent.account} /> : '—' } },
            { title: '用户数', width: 100, render: (_: unknown, row: ManagedCampus) => row.users_count.toLocaleString() },
            { title: '进行中任务', width: 110, render: () => '—' },
            { title: '今日订单', width: 100, render: () => '—' },
            { title: '状态', width: 100, dataIndex: 'status', render: (value: ManagedCampus['status']) => (value === 'active' ? <Pill tone="green">运营中</Pill> : <Pill tone="gray">已暂停</Pill>) },
            {
              title: '隔离模式',
              width: 150,
              render: (_: unknown, row: ManagedCampus) => {
                const strict = row.isolation_mode === 'strict'
                const isDefault = row.id === DEFAULT_CAMPUS_ID
                return (
                  <span style={{ display: 'inline-flex', alignItems: 'center', gap: 8 }}>
                    <Tooltip title={isDefault ? '默认学校是未确认用户的兜底校区，必须保持传统模式' : undefined}>
                      <span>
                        <Switch size="small" checked={strict} disabled={isDefault} loading={isolationBusy === row.id} onChange={() => toggleIsolation(row)} />
                      </span>
                    </Tooltip>
                    <Pill tone={strict ? 'blue' : 'gray'}>{strict ? '严格' : '传统'}</Pill>
                  </span>
                )
              },
            },
            {
              title: '操作', width: 200, render: (_: unknown, row: ManagedCampus) => (
                <Links items={[
                  { label: '详情', onClick: () => setDetail(row) },
                  { label: '编辑', onClick: () => { setEditing(row); editForm.setFieldsValue({ name: row.name, code: row.code }) } },
                  { label: '功能模块', onClick: () => setModuleCampus(row) },
                  row.status === 'disabled' && { label: '开通', onClick: () => void openCampus(row) },
                ]} />
              ),
            },
          ]}
        />
      </div>

      <Modal title="新增校区" open={creating} onCancel={() => setCreating(false)} onOk={() => void createCampus()} okText="创建校区" confirmLoading={submitting} destroyOnHidden>
        <Form form={createForm} layout="vertical">
          <Form.Item name="name" label="校区名称" rules={[{ required: true }]}><Input placeholder="例如：西校区" /></Form.Item>
          <Form.Item name="code" label="校区代码" rules={[{ required: true, min: 2 }]}><Input placeholder="例如：west-campus" /></Form.Item>
        </Form>
      </Modal>

      <Modal title={editing ? `编辑校区：${editing.name}` : '编辑校区'} open={Boolean(editing)} onCancel={() => setEditing(null)} onOk={() => void saveEdit()} okText="保存修改" confirmLoading={submitting} destroyOnHidden>
        <Form form={editForm} layout="vertical">
          <Form.Item name="name" label="校区名称" rules={[{ required: true }]}><Input /></Form.Item>
          <Form.Item name="code" label="校区代码" rules={[{ required: true, min: 2 }]}><Input /></Form.Item>
        </Form>
      </Modal>

      <Modal title="校区详情" open={Boolean(detail)} onCancel={() => setDetail(null)} footer={<Button type="primary" onClick={() => setDetail(null)}>关闭</Button>}>
        {detail && (
          <div style={{ fontSize: 13, lineHeight: 2.1 }}>
            <div><b>校区名称：</b>{detail.name}</div>
            <div><b>校区代码：</b>{detail.code}</div>
            <div><b>所在地区：</b>{[detail.province, detail.city, detail.district].filter(Boolean).join(' · ') || '未设置'}</div>
            <div><b>隔离模式：</b>{detail.isolation_mode === 'strict' ? '严格隔离（仅本校内容可见）' : '传统模式（全平台内容可见）'}</div>
            <div><b>详细地址：</b>{detail.address ?? '未设置'}</div>
            <div><b>归属用户：</b>{detail.users_count.toLocaleString()} 人</div>
            <div><b>校园代理：</b>{detail.agents.length ? detail.agents.map((agent) => `${agent.display_name}${agent.is_primary ? '（主代理）' : ''}`).join('、') : '尚未分配'}</div>
          </div>
        )}
      </Modal>

      <CampusModuleDrawer
        open={Boolean(moduleCampus)}
        campus={moduleCampus}
        canWrite={admin?.permissions.includes('campus.module_manage') ?? false}
        requiresMfa={admin?.requires_mfa}
        onClose={() => setModuleCampus(null)}
      />
    </div>
  )
}
