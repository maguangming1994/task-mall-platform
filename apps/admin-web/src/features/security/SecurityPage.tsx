import { Alert, Button, Card, Form, Input, Modal, Popconfirm, Result, Select, Space, Table, Tabs, Tag, Typography, message } from 'antd'
import { useCallback, useEffect, useState } from 'react'
import {
  ApiError, confirmCurrentAdminMfa, createAdminRole, createManagedAdmin, enrollCurrentAdminMfa, listAdminPermissions, listAdminRoles, listManagedAdmins,
  resetManagedAdminMfa, revokeManagedAdminSessions, updateManagedAdminRoles, updateManagedAdminStatus,
  updateAdminRole, verifyAdminRecent,
  type AdminPermission, type AdminRole, type ManagedAdmin,
} from '../../api/admin'
import { useAdminAuth } from '../auth/AdminAuthContext'

const permissionGroupColors: Record<string, string> = {
  user: 'blue', task: 'geekblue', community: 'purple', mall: 'cyan', after_sale: 'orange',
  finance: 'gold', operations: 'lime', system: 'red', audit: 'default',
}
const permissionGroupNames: Record<string, string> = {
  user: '用户与隐私', task: '任务', community: '社区', mall: '商城与履约', after_sale: '售后',
  finance: '财务', operations: '运营', system: '系统治理', audit: '审计',
}

interface RoleDefinitionForm {
  code: string; name: string; description?: string; permission_codes: string[]; reason: string;
  current_password: string; mfa_code?: string
}

export function SecurityPage() {
  const { admin } = useAdminAuth()
  const [admins, setAdmins] = useState<ManagedAdmin[]>([])
  const [roles, setRoles] = useState<AdminRole[]>([])
  const [permissions, setPermissions] = useState<AdminPermission[]>([])
  const [loading, setLoading] = useState(true)
  const [creating, setCreating] = useState(false)
  const [editing, setEditing] = useState<ManagedAdmin | null>(null)
  const [editingRole, setEditingRole] = useState<AdminRole | 'new' | null>(null)
  const [enrollment, setEnrollment] = useState<{ secret: string; otpauth_uri: string } | null>(null)
  const [recoveryCodes, setRecoveryCodes] = useState<string[]>([])
  const [verifying, setVerifying] = useState(false)
  const [form] = Form.useForm()
  const [roleForm] = Form.useForm()
  const [roleDefinitionForm] = Form.useForm<RoleDefinitionForm>()
  const [mfaForm] = Form.useForm()
  const [verifyForm] = Form.useForm()
  const [messageApi, contextHolder] = message.useMessage()
  const canManage = admin?.permissions.includes('rbac.manage') ?? false
  const permissionOptions = Object.entries(permissions.reduce<Record<string, AdminPermission[]>>((groups, permission) => {
    ;(groups[permission.group] ??= []).push(permission)
    return groups
  }, {})).map(([group, items]) => ({
    label: permissionGroupNames[group] ?? group,
    options: items.map((permission) => ({ value: permission.code, label: `${permission.name}（${permission.code}）` })),
  }))

  const load = useCallback(async () => {
    if (!canManage) { setLoading(false); return }
    setLoading(true)
    try {
      const [adminData, roleData, permissionData] = await Promise.all([listManagedAdmins(), listAdminRoles(), listAdminPermissions()])
      setAdmins(adminData.items); setRoles(roleData.items); setPermissions(permissionData.items)
    } catch (error) {
      messageApi.error(error instanceof ApiError ? error.message : '权限数据加载失败。')
    } finally { setLoading(false) }
  }, [canManage, messageApi])
  useEffect(() => { void load() }, [load])

  const run = async (action: () => Promise<unknown>, success: string) => {
    try { await action(); messageApi.success(success); await load() } catch (error) { messageApi.error(error instanceof ApiError ? error.message : '操作失败。') }
  }

  const create = async () => {
    try { const values = await form.validateFields(); await createManagedAdmin(values); messageApi.success('管理员已创建。'); setCreating(false); form.resetFields(); await load() } catch (error) { if (error instanceof ApiError) messageApi.error(error.message) }
  }
  const saveRoles = async () => {
    if (!editing) return
    try { const values = await roleForm.validateFields(); await updateManagedAdminRoles(editing.id, values.role_codes, values.reason); messageApi.success('角色已更新，后续请求立即按新权限校验。'); setEditing(null); await load() } catch (error) { if (error instanceof ApiError) messageApi.error(error.message) }
  }
  const openRoleEditor = (role: AdminRole | 'new') => {
    setEditingRole(role)
    roleDefinitionForm.setFieldsValue(role === 'new' ? {
      code: '', name: '', description: '', permission_codes: [], reason: '根据新增岗位职责配置最小必要权限。', current_password: '', mfa_code: '',
    } : {
      code: role.code, name: role.name, description: role.description ?? '', permission_codes: role.permissions,
      reason: '根据岗位职责调整角色定义和最小必要权限。', current_password: '', mfa_code: '',
    })
  }
  const saveRoleDefinition = async () => {
    if (!editingRole) return
    try {
      const values = await roleDefinitionForm.validateFields()
      await verifyAdminRecent(values.current_password, values.mfa_code || undefined)
      const input = { name: values.name, description: values.description, permission_codes: values.permission_codes, reason: values.reason }
      if (editingRole === 'new') await createAdminRole({ code: values.code, ...input })
      else await updateAdminRole(editingRole.code, input)
      messageApi.success(editingRole === 'new' ? '新角色已创建，可立即分配给管理员。' : '角色定义和权限已更新。')
      setEditingRole(null)
      roleDefinitionForm.resetFields()
      await load()
    } catch (error) { if (error instanceof ApiError) messageApi.error(error.message) }
  }
  const startMfa = async () => {
    try { setEnrollment(await enrollCurrentAdminMfa()); setRecoveryCodes([]) } catch (error) { messageApi.error(error instanceof ApiError ? error.message : 'MFA 初始化失败。') }
  }
  const confirmMfa = async () => {
    try { const values = await mfaForm.validateFields(); const result = await confirmCurrentAdminMfa(values.code); setRecoveryCodes(result.recovery_codes); messageApi.success('MFA 已启用。') } catch (error) { if (error instanceof ApiError) messageApi.error(error.message) }
  }
  const verifyRecent = async () => {
    try { const values = await verifyForm.validateFields(); await verifyAdminRecent(values.password, values.mfa_code); messageApi.success('近期验证已刷新，可继续高风险操作。'); setVerifying(false); verifyForm.resetFields() } catch (error) { if (error instanceof ApiError) messageApi.error(error.message) }
  }

  const accountTab = <>
    <div className="security-toolbar"><Typography.Paragraph type="secondary">管理员与普通用户完全隔离；角色变化、停用和会话撤销立即生效。</Typography.Paragraph><Button type="primary" onClick={() => setCreating(true)}>新增管理员</Button></div>
    <Table rowKey="id" loading={loading} dataSource={admins} scroll={{ x: 1000 }} columns={[
      { title: '管理员', render: (_: unknown, item: ManagedAdmin) => <><strong>{item.display_name}</strong><Typography.Text className="setting-key" type="secondary">{item.account}</Typography.Text></> },
      { title: '角色', dataIndex: 'roles', render: (values: string[]) => <Space wrap>{values.map((value) => <Tag key={value}>{roles.find((role) => role.code === value)?.name ?? value}</Tag>)}</Space> },
      { title: '状态', dataIndex: 'status', render: (value: string) => <Tag color={value === 'active' ? 'green' : 'red'}>{value === 'active' ? '正常' : '停用'}</Tag> },
      { title: 'MFA', dataIndex: 'requires_mfa', render: (value: boolean) => value ? <Tag color="blue">已启用</Tag> : '未启用' },
      { title: '活跃会话', dataIndex: 'active_sessions_count' },
      { title: '操作', render: (_: unknown, item: ManagedAdmin) => <Space wrap>
        <Button size="small" onClick={() => { setEditing(item); roleForm.setFieldsValue({ role_codes: item.roles, reason: '根据岗位职责调整角色权限。' }) }}>角色</Button>
        <Popconfirm title="撤销该管理员全部会话？" onConfirm={() => void run(() => revokeManagedAdminSessions(item.id, '后台安全操作撤销全部活跃会话。'), '会话已撤销。')}><Button size="small">下线</Button></Popconfirm>
        {item.requires_mfa && <Popconfirm title="重置 MFA 会同时撤销全部会话，确认继续？" onConfirm={() => void run(() => resetManagedAdminMfa(item.id, '管理员身份核验后重置丢失的验证器。'), 'MFA 已重置。')}><Button size="small">重置 MFA</Button></Popconfirm>}
        {item.id !== admin?.id && <Popconfirm title={item.status === 'active' ? '确认停用并撤销会话？' : '确认恢复该账号？'} onConfirm={() => void run(() => updateManagedAdminStatus(item.id, item.status === 'active' ? 'disabled' : 'active', item.status === 'active' ? '后台账号停用并撤销会话。' : '完成复核后恢复后台账号。'), '账号状态已更新。')}><Button danger={item.status === 'active'} size="small">{item.status === 'active' ? '停用' : '恢复'}</Button></Popconfirm>}
      </Space> },
    ]} />
  </>

  const roleTab = <><div className="security-toolbar"><Typography.Paragraph type="secondary">角色可以按岗位新增和维护；权限调整会立即影响已分配该角色的管理员。</Typography.Paragraph><Button type="primary" onClick={() => openRoleEditor('new')}>新增角色</Button></div><Table rowKey="code" dataSource={roles} pagination={false} tableLayout="fixed" columns={[
    { title: '角色', width: 200, render: (_: unknown, role: AdminRole) => <Space direction="vertical" size={3}><Space><strong>{role.name}</strong>{role.system_role ? <Tag>系统角色</Tag> : <Tag color="blue">自定义角色</Tag>}</Space><Typography.Text code type="secondary" style={{ whiteSpace: 'nowrap', fontSize: 11 }}>{role.code}</Typography.Text>{role.description && <Typography.Text type="secondary">{role.description}</Typography.Text>}</Space> },
    { title: '权限', render: (_: unknown, role: AdminRole) => <Space wrap size={[6, 8]}>{(role.permission_items ?? role.permissions.map((code) => ({ code, name: code, group: 'default' }))).map((permission) => <Tag key={permission.code} color={permissionGroupColors[permission.group]} title={`权限编码：${permission.code}`}>{permission.name}</Tag>)}</Space> },
    { title: '操作', width: 90, render: (_: unknown, role: AdminRole) => <Button size="small" onClick={() => openRoleEditor(role)}>编辑</Button> },
  ]} /></>

  if (!canManage) return <Result status="403" title="审计入口" subTitle="当前角色可以查看审计记录；管理员与角色治理仅向具备 RBAC 管理权限的账号开放，审计列表将在运营批次接入。" />

  return <>
    {contextHolder}
    <div className="page-heading"><div><Typography.Text className="eyebrow">IDENTITY & ACCESS</Typography.Text><Typography.Title level={2}>权限与安全</Typography.Title><Typography.Paragraph type="secondary">账号、角色、会话和二次验证采用服务端强制校验与审计。</Typography.Paragraph></div><Space><Button onClick={() => setVerifying(true)}>刷新近期验证</Button><Button onClick={() => void startMfa()}>为当前账号启用 MFA</Button></Space></div>
    <Alert showIcon type="warning" message="新增管理员、角色变更、停用、MFA 重置等高风险操作要求近期验证；系统阻止停用自己或移除自己的超级管理员角色。" />
    <Card bordered={false} className="settings-table-card"><Tabs items={[{ key: 'accounts', label: '管理员账号', children: accountTab }, { key: 'roles', label: '角色权限', children: roleTab }]} /></Card>

    <Modal title="新增管理员" open={creating} onCancel={() => setCreating(false)} onOk={() => void create()} okText="创建账号">
      <Form form={form} layout="vertical"><Form.Item name="account" label="登录账号" rules={[{ required: true, min: 3 }]}><Input /></Form.Item><Form.Item name="display_name" label="显示名称" rules={[{ required: true }]}><Input /></Form.Item><Form.Item name="email" label="邮箱"><Input /></Form.Item><Form.Item name="password" label="初始密码" rules={[{ required: true, min: 12 }]}><Input.Password /></Form.Item><Form.Item name="role_codes" label="初始角色" rules={[{ required: true }]}><Select mode="multiple" options={roles.map((role) => ({ value: role.code, label: role.name }))} /></Form.Item></Form>
    </Modal>
    <Modal title={editing ? `调整角色：${editing.display_name}` : '调整角色'} open={Boolean(editing)} onCancel={() => setEditing(null)} onOk={() => void saveRoles()}><Form form={roleForm} layout="vertical"><Form.Item name="role_codes" label="角色" rules={[{ required: true }]}><Select mode="multiple" options={roles.map((role) => ({ value: role.code, label: role.name }))} /></Form.Item><Form.Item name="reason" label="调整原因" rules={[{ required: true, min: 4 }]}><Input.TextArea /></Form.Item></Form></Modal>
    <Modal title={editingRole === 'new' ? '新增角色' : editingRole ? `编辑角色：${editingRole.name}` : '角色管理'} open={Boolean(editingRole)} onCancel={() => setEditingRole(null)} onOk={() => void saveRoleDefinition()} okText={editingRole === 'new' ? '创建角色' : '保存修改'} width={760} destroyOnHidden>
      <Form form={roleDefinitionForm} layout="vertical">
        <Space align="start" style={{ display: 'flex' }}>
          <Form.Item name="name" label="角色名称" rules={[{ required: true, max: 80 }]} style={{ flex: 1 }}><Input placeholder="例如：商品运营专员" /></Form.Item>
          <Form.Item name="code" label="角色编码" rules={[{ required: true, min: 3, max: 50, pattern: /^[a-z][a-z0-9_]+$/, message: '使用小写字母、数字和下划线，且必须以字母开头' }]} style={{ flex: 1 }}><Input disabled={editingRole !== 'new'} placeholder="product_operator" /></Form.Item>
        </Space>
        <Form.Item name="description" label="职责说明"><Input.TextArea rows={2} maxLength={500} showCount placeholder="说明该角色适用的岗位和职责边界" /></Form.Item>
        <Form.Item name="permission_codes" label="角色权限" rules={[{ required: true, type: 'array', min: 1, message: '至少选择一项权限' }]}>
          <Select mode="multiple" allowClear showSearch optionFilterProp="label" maxTagCount="responsive" placeholder="选择最小必要权限" options={permissionOptions} />
        </Form.Item>
        <Form.Item name="reason" label="新增或调整原因" rules={[{ required: true, min: 4, max: 500 }]}><Input.TextArea rows={2} /></Form.Item>
        <Alert showIcon type="warning" message="角色权限变更属于高风险操作，保存后立即对所有关联管理员生效。" style={{ marginBottom: 16 }} />
        <Form.Item name="current_password" label="当前管理员密码" rules={[{ required: true }]}><Input.Password autoComplete="current-password" /></Form.Item>
        {admin?.requires_mfa && <Form.Item name="mfa_code" label="动态验证码或恢复码" rules={[{ required: true }]}><Input autoComplete="one-time-code" /></Form.Item>}
      </Form>
    </Modal>
    <Modal title="启用验证器 MFA" open={Boolean(enrollment)} onCancel={() => setEnrollment(null)} footer={recoveryCodes.length ? <Button type="primary" onClick={() => setEnrollment(null)}>我已安全保存恢复码</Button> : undefined} onOk={recoveryCodes.length ? undefined : () => void confirmMfa()} okText="确认启用" closable={recoveryCodes.length === 0}>
      {enrollment && !recoveryCodes.length && <><Alert showIcon type="info" message="将以下密钥添加到任意兼容 TOTP 的验证器，密钥只在本次设置过程中展示。" /><Typography.Paragraph copyable code className="mfa-secret">{enrollment.secret}</Typography.Paragraph><Form form={mfaForm} layout="vertical"><Form.Item name="code" label="6 位动态验证码" rules={[{ required: true, len: 6 }]}><Input autoComplete="one-time-code" /></Form.Item></Form></>}
      {recoveryCodes.length > 0 && <><Alert showIcon type="warning" message="恢复码只展示一次，每个只能使用一次。请离线保存，禁止发送到聊天群。" /><div className="recovery-grid">{recoveryCodes.map((code) => <Typography.Text code copyable key={code}>{code}</Typography.Text>)}</div></>}
    </Modal>
    <Modal title="刷新高风险操作验证" open={verifying} onCancel={() => setVerifying(false)} onOk={() => void verifyRecent()} okText="完成验证"><Form form={verifyForm} layout="vertical"><Form.Item name="password" label="当前密码" rules={[{ required: true }]}><Input.Password autoComplete="current-password" /></Form.Item>{admin?.requires_mfa && <Form.Item name="mfa_code" label="动态验证码或恢复码" rules={[{ required: true }]}><Input autoComplete="one-time-code" /></Form.Item>}</Form></Modal>
  </>
}
