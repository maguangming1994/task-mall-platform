import { Button, Form, Input, Modal, Popconfirm, Select, Table, message } from 'antd'
import { useCallback, useEffect, useMemo, useState } from 'react'
import {
  ApiError,
  createAdminRole,
  createManagedAdmin,
  listAdminAuditLogs,
  listAdminPermissions,
  listAdminRoles,
  listManagedAdmins,
  updateAdminRole,
  updateManagedAdminStatus,
  type AdminAuditLog,
  type AdminPermission,
  type AdminRole,
  type ManagedAdmin,
} from '../../api/admin'
import { auditActionLabels, businessLabel } from '../../constants/businessLabels'
import { Links, PageHead, Pill, UCell } from '../../components/xq'

type TabKey = 'roles' | 'accounts' | 'logs'

const permissionGroupNames: Record<string, string> = {
  user: '用户与隐私', task: '任务', community: '社区', mall: '商城与履约', after_sale: '售后',
  finance: '财务', operations: '运营', system: '系统治理', audit: '审计',
}

const subjectLabels: Record<string, string> = {
  AdminUser: '管理员', Role: '角色', Task: '任务', Product: '商品', Order: '订单',
  Refund: '退款单', Exchange: '换货单', SystemSetting: '系统配置', MediaStorageProvider: '对象存储',
}
function subjectLabel(value: string | null) {
  if (!value) return '系统事件'
  const short = value.split('\\').pop() ?? value
  return subjectLabels[short] ?? short
}

const fmtTime = (value: string | null) => (value ? new Date(value).toLocaleString('zh-CN', { hour12: false }) : '—')

interface RoleFormValue { code: string; name: string; description?: string; permission_codes: string[]; reason: string }
interface AccountFormValue { account: string; display_name: string; email?: string; password: string; role_codes: string[] }

export function RolesPage() {
  const [tab, setTab] = useState<TabKey>('roles')
  const [roles, setRoles] = useState<AdminRole[]>([])
  const [admins, setAdmins] = useState<ManagedAdmin[]>([])
  const [permissions, setPermissions] = useState<AdminPermission[]>([])
  const [logs, setLogs] = useState<AdminAuditLog[]>([])
  const [logTotal, setLogTotal] = useState(0)
  const [logPage, setLogPage] = useState(1)
  const [loading, setLoading] = useState(true)
  const [logLoading, setLogLoading] = useState(false)
  const [roleKw, setRoleKw] = useState('')
  const [appliedKw, setAppliedKw] = useState('')
  const [editingRole, setEditingRole] = useState<AdminRole | 'new' | null>(null)
  const [creatingAccount, setCreatingAccount] = useState(false)
  const [submitting, setSubmitting] = useState(false)
  const [roleForm] = Form.useForm<RoleFormValue>()
  const [accountForm] = Form.useForm<AccountFormValue>()
  const [messageApi, contextHolder] = message.useMessage()

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const [roleData, adminData, permissionData] = await Promise.all([listAdminRoles(), listManagedAdmins(), listAdminPermissions()])
      setRoles(roleData.items)
      setAdmins(adminData.items)
      setPermissions(permissionData.items)
    } catch (error) {
      messageApi.error(error instanceof ApiError ? error.message : '权限数据加载失败。')
    } finally {
      setLoading(false)
    }
  }, [messageApi])

  const loadLogs = useCallback(async (page: number) => {
    setLogLoading(true)
    try {
      const result = await listAdminAuditLogs({ page, page_size: 10 })
      setLogs(result.items)
      setLogTotal(result.pagination.total)
    } catch (error) {
      messageApi.error(error instanceof ApiError ? error.message : '操作日志加载失败。')
    } finally {
      setLogLoading(false)
    }
  }, [messageApi])

  useEffect(() => { void load() }, [load])
  useEffect(() => { if (tab === 'logs') void loadLogs(logPage) }, [tab, logPage, loadLogs])

  const permissionOptions = useMemo(() => Object.entries(permissions.reduce<Record<string, AdminPermission[]>>((groups, permission) => {
    ;(groups[permission.group] ??= []).push(permission)
    return groups
  }, {})).map(([group, items]) => ({
    label: permissionGroupNames[group] ?? group,
    options: items.map((permission) => ({ value: permission.code, label: `${permission.name}（${permission.code}）` })),
  })), [permissions])

  const filteredRoles = useMemo(
    () => roles.filter((role) => !appliedKw || role.name.includes(appliedKw) || role.code.includes(appliedKw)),
    [roles, appliedKw],
  )

  const openRoleEditor = (role: AdminRole | 'new') => {
    setEditingRole(role)
    roleForm.setFieldsValue(role === 'new'
      ? { code: '', name: '', description: '', permission_codes: [], reason: '根据新增岗位职责配置最小必要权限。' }
      : { code: role.code, name: role.name, description: role.description ?? '', permission_codes: role.permissions, reason: '根据岗位职责调整角色定义和最小必要权限。' })
  }

  const saveRole = async () => {
    if (!editingRole) return
    try {
      const values = await roleForm.validateFields()
      setSubmitting(true)
      const input = { name: values.name, description: values.description, permission_codes: values.permission_codes, reason: values.reason }
      if (editingRole === 'new') await createAdminRole({ code: values.code, ...input })
      else await updateAdminRole(editingRole.code, input)
      messageApi.success(editingRole === 'new' ? '新角色已创建。' : '角色定义已更新。')
      setEditingRole(null)
      roleForm.resetFields()
      await load()
    } catch (error) {
      if (error instanceof ApiError) messageApi.error(error.message)
    } finally {
      setSubmitting(false)
    }
  }

  const saveAccount = async () => {
    try {
      const values = await accountForm.validateFields()
      setSubmitting(true)
      await createManagedAdmin(values)
      messageApi.success('后台账号已创建。')
      setCreatingAccount(false)
      accountForm.resetFields()
      await load()
    } catch (error) {
      if (error instanceof ApiError) messageApi.error(error.message)
    } finally {
      setSubmitting(false)
    }
  }

  const toggleAdminStatus = async (item: ManagedAdmin) => {
    const next = item.status === 'active' ? 'disabled' : 'active'
    try {
      await updateManagedAdminStatus(item.id, next, next === 'disabled' ? '后台账号停用并撤销会话。' : '完成复核后恢复后台账号。')
      messageApi.success('账号状态已更新。')
      await load()
    } catch (error) {
      messageApi.error(error instanceof ApiError ? error.message : '账号状态更新失败。')
    }
  }

  const roleNameOf = (code: string) => roles.find((role) => role.code === code)?.name ?? code

  const rolesTab = <>
    <div className="xq-card xq-filter" style={{ marginBottom: 16 }}>
      <Input allowClear placeholder="角色名称" value={roleKw} onChange={(event) => setRoleKw(event.target.value)} onPressEnter={() => setAppliedKw(roleKw.trim())} />
      <Button type="primary" onClick={() => setAppliedKw(roleKw.trim())}>查询</Button>
      <Button type="primary" onClick={() => openRoleEditor('new')}>+ 新增角色</Button>
    </div>
    <div className="xq-card xq-table">
      <Table
        rowKey="code"
        size="middle"
        loading={loading}
        dataSource={filteredRoles}
        pagination={{ pageSize: 10, showTotal: (total) => `共 ${total} 条` }}
        columns={[
          { title: '角色名称', render: (_: unknown, role: AdminRole) => <strong>{role.name}</strong> },
          { title: '权限数', width: 110, render: (_: unknown, role: AdminRole) => (role.system_role && role.code === 'super_admin' ? '全部' : role.permissions.length) },
          { title: '人数', width: 90, render: (_: unknown, role: AdminRole) => admins.filter((admin) => admin.roles.includes(role.code)).length },
          { title: '描述', render: (_: unknown, role: AdminRole) => role.description ?? '—' },
          {
            title: '操作', width: 130, render: (_: unknown, role: AdminRole) => (
              <Links items={[
                { label: '编辑', onClick: () => openRoleEditor(role) },
                !role.system_role && { label: '删除', danger: true, onClick: () => messageApi.info('角色删除能力暂未开放，可通过编辑收回全部权限。') },
              ]} />
            ),
          },
        ]}
      />
    </div>
  </>

  const accountsTab = <>
    <div className="xq-card xq-filter" style={{ marginBottom: 16 }}>
      <span className="grow" />
      <Button type="primary" onClick={() => setCreatingAccount(true)}>+ 新增账号</Button>
    </div>
    <div className="xq-card xq-table">
      <Table
        rowKey="id"
        size="middle"
        loading={loading}
        dataSource={admins}
        pagination={{ pageSize: 10, showTotal: (total) => `共 ${total} 条` }}
        columns={[
          { title: '管理员', render: (_: unknown, item: ManagedAdmin) => <UCell name={item.display_name} sub={item.account} /> },
          { title: '角色', render: (_: unknown, item: ManagedAdmin) => item.roles.map(roleNameOf).join('、') || '—' },
          { title: '状态', width: 100, dataIndex: 'status', render: (value: string) => (value === 'active' ? <Pill tone="green">正常</Pill> : <Pill tone="red">停用</Pill>) },
          { title: 'MFA', width: 100, dataIndex: 'requires_mfa', render: (value: boolean) => (value ? <Pill tone="blue">已启用</Pill> : <Pill tone="gray">未启用</Pill>) },
          { title: '最近登录', width: 170, dataIndex: 'last_login_at', render: fmtTime },
          {
            title: '操作', width: 100, render: (_: unknown, item: ManagedAdmin) => (
              <Popconfirm title={item.status === 'active' ? '确认停用该账号并撤销会话？' : '确认恢复该账号？'} onConfirm={() => void toggleAdminStatus(item)}>
                <a className={`xq-link${item.status === 'active' ? ' danger' : ''}`}>{item.status === 'active' ? '禁用' : '启用'}</a>
              </Popconfirm>
            ),
          },
        ]}
      />
    </div>
  </>

  const logsTab = (
    <div className="xq-card xq-table">
      <Table
        rowKey="id"
        size="middle"
        loading={logLoading}
        dataSource={logs}
        pagination={{ current: logPage, pageSize: 10, total: logTotal, showTotal: (total) => `共 ${total} 条`, onChange: setLogPage }}
        columns={[
          { title: '时间', width: 170, render: (_: unknown, item: AdminAuditLog) => fmtTime(item.created_at) },
          { title: '操作人', width: 170, render: (_: unknown, item: AdminAuditLog) => <UCell name={item.actor.display_name || '系统任务'} sub={item.actor.account} /> },
          { title: '操作', width: 160, render: (_: unknown, item: AdminAuditLog) => businessLabel(auditActionLabels, item.action, item.action) },
          { title: '业务对象', width: 150, render: (_: unknown, item: AdminAuditLog) => subjectLabel(item.subject_type) },
          { title: '操作原因', render: (_: unknown, item: AdminAuditLog) => item.reason ?? '未填写' },
          { title: '请求编号', width: 130, render: (_: unknown, item: AdminAuditLog) => (item.request_id ? `${item.request_id.slice(0, 12)}…` : '—') },
        ]}
      />
    </div>
  )

  return (
    <div className="xq-page">
      {contextHolder}
      <PageHead title="权限与审计" />
      <div className="xq-tabs">
        <div className={`it${tab === 'roles' ? ' on' : ''}`} onClick={() => setTab('roles')}>角色管理</div>
        <div className={`it${tab === 'accounts' ? ' on' : ''}`} onClick={() => setTab('accounts')}>后台账号</div>
        <div className={`it${tab === 'logs' ? ' on' : ''}`} onClick={() => setTab('logs')}>操作日志</div>
      </div>
      {tab === 'roles' && rolesTab}
      {tab === 'accounts' && accountsTab}
      {tab === 'logs' && logsTab}

      <Modal
        title={editingRole === 'new' ? '新增角色' : editingRole ? `编辑角色：${editingRole.name}` : '角色管理'}
        open={Boolean(editingRole)}
        onCancel={() => setEditingRole(null)}
        onOk={() => void saveRole()}
        okText={editingRole === 'new' ? '创建角色' : '保存修改'}
        confirmLoading={submitting}
        width={720}
        destroyOnHidden
      >
        <Form form={roleForm} layout="vertical">
          <Form.Item name="name" label="角色名称" rules={[{ required: true, max: 80 }]}><Input placeholder="例如：商品运营专员" /></Form.Item>
          <Form.Item name="code" label="角色编码" rules={[{ required: true, min: 3, max: 50, pattern: /^[a-z][a-z0-9_]+$/, message: '使用小写字母、数字和下划线，且必须以字母开头' }]}>
            <Input disabled={editingRole !== 'new'} placeholder="product_operator" />
          </Form.Item>
          <Form.Item name="description" label="描述"><Input.TextArea rows={2} maxLength={500} placeholder="说明该角色适用的岗位和职责边界" /></Form.Item>
          <Form.Item name="permission_codes" label="权限码" rules={[{ required: true, type: 'array', min: 1, message: '至少选择一项权限' }]}>
            <Select mode="multiple" allowClear showSearch optionFilterProp="label" maxTagCount="responsive" placeholder="选择最小必要权限" options={permissionOptions} />
          </Form.Item>
          <Form.Item name="reason" label="新增或调整原因" rules={[{ required: true, min: 4, max: 500 }]}><Input.TextArea rows={2} /></Form.Item>
        </Form>
      </Modal>

      <Modal title="新增后台账号" open={creatingAccount} onCancel={() => setCreatingAccount(false)} onOk={() => void saveAccount()} okText="创建账号" confirmLoading={submitting} destroyOnHidden>
        <Form form={accountForm} layout="vertical">
          <Form.Item name="account" label="登录账号" rules={[{ required: true, min: 3 }]}><Input /></Form.Item>
          <Form.Item name="display_name" label="显示名称" rules={[{ required: true }]}><Input /></Form.Item>
          <Form.Item name="email" label="邮箱"><Input /></Form.Item>
          <Form.Item name="password" label="初始密码" rules={[{ required: true, min: 12 }]}><Input.Password /></Form.Item>
          <Form.Item name="role_codes" label="初始角色" rules={[{ required: true }]}>
            <Select mode="multiple" options={roles.map((role) => ({ value: role.code, label: role.name }))} />
          </Form.Item>
        </Form>
      </Modal>
    </div>
  )
}
