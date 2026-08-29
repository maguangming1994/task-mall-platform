import { EnvironmentOutlined, PlusOutlined, TeamOutlined } from '@ant-design/icons'
import { Alert, Button, Card, Form, Input, InputNumber, Modal, Select, Space, Table, Tag, Typography, message } from 'antd'
import { useCallback, useEffect, useMemo, useState } from 'react'
import { useAdminAuth } from '../auth/AdminAuthContext'
import {
  ApiError,
  createManagedCampus,
  listCampusAgentOptions,
  listManagedCampuses,
  updateManagedCampus,
  updateManagedCampusAgents,
  type CampusAgentOption,
  type ManagedCampus,
} from '../../api/admin'

type CampusForm = Omit<ManagedCampus, 'id' | 'users_count' | 'agents'>
type AgentForm = { admin_user_ids: string[]; primary_admin_user_id?: string }

const emptyCampus: CampusForm = {
  code: '', name: '', province: '', city: '', district: '', address: '',
  latitude: null, longitude: null, status: 'active', sort_order: 100,
}

export function CampusManagementPage() {
  const { admin } = useAdminAuth()
  const canGovernAgents = admin?.permissions.includes('rbac.manage') ?? false
  const [campuses, setCampuses] = useState<ManagedCampus[]>([])
  const [agentOptions, setAgentOptions] = useState<CampusAgentOption[]>([])
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [editing, setEditing] = useState<ManagedCampus | null | undefined>(undefined)
  const [agentCampus, setAgentCampus] = useState<ManagedCampus | null>(null)
  const [campusForm] = Form.useForm<CampusForm>()
  const [agentForm] = Form.useForm<AgentForm>()
  const [messageApi, contextHolder] = message.useMessage()

  const refresh = useCallback(async () => {
    setLoading(true)
    try {
      const [campusResult, agentResult] = await Promise.all([
        listManagedCampuses(),
        canGovernAgents ? listCampusAgentOptions() : Promise.resolve({ items: [] }),
      ])
      setCampuses(campusResult.items)
      setAgentOptions(agentResult.items)
    } catch (error) {
      messageApi.error(error instanceof ApiError ? error.message : '学校数据加载失败。')
    } finally {
      setLoading(false)
    }
  }, [canGovernAgents, messageApi])

  useEffect(() => { void refresh() }, [refresh])

  const agentSelectOptions = useMemo(() => agentOptions.map((agent) => ({
    value: agent.id,
    label: `${agent.display_name}（${agent.account}）`,
  })), [agentOptions])

  const openCreate = () => {
    setEditing(null)
    campusForm.setFieldsValue(emptyCampus)
  }

  const openEdit = (campus: ManagedCampus) => {
    setEditing(campus)
    campusForm.setFieldsValue({
      code: campus.code, name: campus.name, province: campus.province, city: campus.city,
      district: campus.district, address: campus.address, latitude: campus.latitude,
      longitude: campus.longitude, status: campus.status, sort_order: campus.sort_order,
    })
  }

  const saveCampus = async (values: CampusForm) => {
    setSaving(true)
    try {
      if (editing) await updateManagedCampus(editing.id, values)
      else await createManagedCampus(values)
      messageApi.success(editing ? '学校资料已更新。' : '学校已创建。')
      setEditing(undefined)
      await refresh()
    } catch (error) {
      messageApi.error(error instanceof ApiError ? error.message : '保存失败，请检查填写内容。')
    } finally {
      setSaving(false)
    }
  }

  const openAgents = (campus: ManagedCampus) => {
    setAgentCampus(campus)
    agentForm.setFieldsValue({
      admin_user_ids: campus.agents.map((agent) => agent.id),
      primary_admin_user_id: campus.agents.find((agent) => agent.is_primary)?.id,
    })
  }

  const saveAgents = async (values: AgentForm) => {
    if (!agentCampus) return
    setSaving(true)
    try {
      await updateManagedCampusAgents(agentCampus.id, values.admin_user_ids || [], values.primary_admin_user_id)
      messageApi.success('学校代理已更新。')
      setAgentCampus(null)
      await refresh()
    } catch (error) {
      messageApi.error(error instanceof ApiError ? error.message : '代理设置保存失败。')
    } finally {
      setSaving(false)
    }
  }

  return <>
    {contextHolder}
    <div className="page-heading">
      <div>
        <Typography.Text className="eyebrow">CAMPUS OPERATIONS</Typography.Text>
        <Typography.Title level={2}>学校与代理</Typography.Title>
        <Typography.Paragraph type="secondary">按学校建立运营范围，为每所学校分配主代理和协作代理。</Typography.Paragraph>
      </div>
      {canGovernAgents && <Button type="primary" icon={<PlusOutlined />} onClick={openCreate}>新增学校</Button>}
    </div>
    <Alert showIcon type="info" message="学校代理是后台管理员账号的运营范围，不是多级分销关系。" description="代理仅负责绑定学校的内容、用户和活动运营；佣金与结算需单独经过财务规则配置。" style={{ marginBottom: 18 }} />
    <Card bordered={false}>
      <Table rowKey="id" loading={loading} dataSource={campuses} pagination={false} scroll={{ x: 1000 }} columns={[
        { title: '学校', width: 220, render: (_, row) => <Space direction="vertical" size={2}><Typography.Text strong>{row.name}</Typography.Text><Typography.Text type="secondary">{row.code}</Typography.Text></Space> },
        { title: '地区', width: 220, render: (_, row) => [row.province, row.city, row.district].filter(Boolean).join(' / ') || '未填写' },
        { title: '用户', dataIndex: 'users_count', width: 90, render: (value) => `${value} 人` },
        { title: '状态', dataIndex: 'status', width: 90, render: (value) => <Tag color={value === 'active' ? 'success' : 'default'}>{value === 'active' ? '启用' : '停用'}</Tag> },
        { title: '学校代理', width: 280, render: (_, row) => row.agents.length ? <Space wrap>{row.agents.map((agent) => <Tag key={agent.id} color={agent.is_primary ? 'blue' : undefined}>{agent.display_name}{agent.is_primary ? ' · 主代理' : ''}</Tag>)}</Space> : <Typography.Text type="secondary">尚未分配</Typography.Text> },
        { title: '操作', fixed: 'right', width: 180, render: (_, row) => <Space><Button onClick={() => openEdit(row)}>编辑</Button>{canGovernAgents && <Button icon={<TeamOutlined />} onClick={() => openAgents(row)}>代理</Button>}</Space> },
      ]} />
    </Card>

    <Modal title={editing ? '编辑学校' : '新增学校'} open={editing !== undefined} onCancel={() => setEditing(undefined)} onOk={() => campusForm.submit()} confirmLoading={saving} okText="保存" destroyOnHidden>
      <Form form={campusForm} layout="vertical" onFinish={saveCampus} initialValues={emptyCampus}>
        <Space align="start" style={{ display: 'flex' }}><Form.Item name="name" label="学校名称" rules={[{ required: true }]} style={{ flex: 1 }}><Input prefix={<EnvironmentOutlined />} placeholder="例如：江西科技学院" /></Form.Item><Form.Item name="code" label="学校编码" rules={[{ required: true }, { pattern: /^[a-z0-9][a-z0-9-]*$/, message: '仅支持小写字母、数字和短横线' }]} style={{ flex: 1 }}><Input placeholder="jxut" /></Form.Item></Space>
        <Space align="start" style={{ display: 'flex' }}><Form.Item name="province" label="省" style={{ flex: 1 }}><Input /></Form.Item><Form.Item name="city" label="市" style={{ flex: 1 }}><Input /></Form.Item><Form.Item name="district" label="区/县" style={{ flex: 1 }}><Input /></Form.Item></Space>
        <Form.Item name="address" label="详细地址"><Input /></Form.Item>
        <Space align="start" style={{ display: 'flex' }}><Form.Item name="latitude" label="纬度" style={{ flex: 1 }}><InputNumber style={{ width: '100%' }} min={-90} max={90} precision={7} /></Form.Item><Form.Item name="longitude" label="经度" style={{ flex: 1 }}><InputNumber style={{ width: '100%' }} min={-180} max={180} precision={7} /></Form.Item></Space>
        <Space align="start" style={{ display: 'flex' }}><Form.Item name="status" label="状态" style={{ flex: 1 }}><Select options={[{ value: 'active', label: '启用' }, { value: 'disabled', label: '停用' }]} /></Form.Item><Form.Item name="sort_order" label="排序" style={{ flex: 1 }}><InputNumber style={{ width: '100%' }} min={0} max={9999} /></Form.Item></Space>
      </Form>
    </Modal>

    <Modal title={`设置代理${agentCampus ? ` · ${agentCampus.name}` : ''}`} open={Boolean(agentCampus)} onCancel={() => setAgentCampus(null)} onOk={() => agentForm.submit()} confirmLoading={saving} okText="保存代理" destroyOnHidden>
      <Form form={agentForm} layout="vertical" onFinish={saveAgents}>
        <Form.Item name="admin_user_ids" label="代理管理员账号"><Select mode="multiple" allowClear showSearch optionFilterProp="label" options={agentSelectOptions} placeholder="选择可管理该学校的后台账号" /></Form.Item>
        <Form.Item name="primary_admin_user_id" label="主代理"><Select allowClear showSearch optionFilterProp="label" options={agentSelectOptions.filter((option) => (agentForm.getFieldValue('admin_user_ids') || []).includes(option.value))} placeholder="可选，负责该学校的主要运营" /></Form.Item>
      </Form>
    </Modal>
  </>
}
