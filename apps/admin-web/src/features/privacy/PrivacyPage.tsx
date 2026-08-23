import { Alert, Button, Card, Form, Input, Modal, Select, Space, Table, Tabs, Tag, Typography, message } from 'antd'
import { useCallback, useEffect, useState } from 'react'
import {
  ApiError,
  listPrivacyRequests,
  resolvePrivacyRequest,
  verifyAdminRecent,
  type PrivacyRequestItem,
} from '../../api/admin'
import { useAdminAuth } from '../auth/AdminAuthContext'
import { LegalDocumentManagementPanel } from './LegalDocumentManagementPanel'
import { businessLabel } from '../../constants/businessLabels'

const typeNames: Record<string, string> = {
  access: '查阅', copy: '数据副本', correction: '更正', deletion: '删除', withdraw_consent: '撤回同意',
}

const statusNames: Record<string, string> = {
  submitted: '待处理', verifying: '核验中', processing: '处理中', completed: '已完成',
  partially_completed: '部分完成', rejected: '已驳回',
}

interface ResolutionValues {
  decision: 'completed' | 'partially_completed' | 'rejected'
  resolution_note: string
  password: string
  mfa_code?: string
}

export function PrivacyPage() {
  const { admin } = useAdminAuth()
  const [items, setItems] = useState<PrivacyRequestItem[]>([])
  const [loading, setLoading] = useState(false)
  const [filters, setFilters] = useState<{ status?: string; request_type?: string }>({ status: 'submitted' })
  const [selected, setSelected] = useState<PrivacyRequestItem | null>(null)
  const [submitting, setSubmitting] = useState(false)
  const [form] = Form.useForm<ResolutionValues>()
  const [messageApi, contextHolder] = message.useMessage()
  const canManageLegal = admin?.permissions.includes('legal.manage') ?? false
  const canManagePrivacy = admin?.permissions.includes('privacy.manage') ?? false

  const load = useCallback(async () => {
    setLoading(true)
    try {
      setItems((await listPrivacyRequests(filters)).items)
    } catch (cause) {
      messageApi.error(cause instanceof ApiError ? cause.message : '隐私工单加载失败。')
    } finally {
      setLoading(false)
    }
  }, [filters, messageApi])

  useEffect(() => { if (canManagePrivacy) void load() }, [canManagePrivacy, load])

  const submit = async (values: ResolutionValues) => {
    if (!selected) return
    setSubmitting(true)
    try {
      await verifyAdminRecent(values.password, values.mfa_code)
      await resolvePrivacyRequest(selected.id, values.decision, values.resolution_note)
      messageApi.success('隐私请求已处理并写入审计日志。')
      setSelected(null)
      form.resetFields()
      await load()
    } catch (cause) {
      messageApi.error(cause instanceof ApiError ? cause.message : '处理失败，请复核后重试。')
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <>
      {contextHolder}
      <div className="page-heading">
        <div>
          <Typography.Text className="eyebrow">协议与隐私治理</Typography.Text>
          <Typography.Title level={2}>协议、隐私与账号注销</Typography.Title>
          <Typography.Paragraph type="secondary">维护用户协议与隐私政策版本、确认规则，并处理数据权利和账号注销请求。</Typography.Paragraph>
        </div>
        <Tag color="blue">全程审计</Tag>
      </div>
      <Tabs
        defaultActiveKey={canManageLegal ? 'documents' : 'requests'}
        items={[
          ...(canManageLegal ? [{ key: 'documents', label: '协议与隐私政策', children: <LegalDocumentManagementPanel /> }] : []),
          ...(canManagePrivacy ? [{ key: 'requests', label: '隐私权利请求', children: <>
      <Alert showIcon type="warning" message="法定保留或业务阻断存在时，不允许标记为全部完成；删除与注销操作必须先核验申请人身份。" />
      <Card bordered={false} style={{ marginTop: 16 }}>
        <Space wrap style={{ marginBottom: 16 }}>
          <Select
            allowClear placeholder="处理状态" style={{ width: 150 }} value={filters.status}
            options={Object.entries(statusNames).map(([value, label]) => ({ value, label }))}
            onChange={(status) => setFilters((current) => ({ ...current, status }))}
          />
          <Select
            allowClear placeholder="请求类型" style={{ width: 150 }} value={filters.request_type}
            options={Object.entries(typeNames).map(([value, label]) => ({ value, label }))}
            onChange={(request_type) => setFilters((current) => ({ ...current, request_type }))}
          />
          <Button onClick={() => void load()}>刷新</Button>
        </Space>
        <Table
          rowKey="id" loading={loading} dataSource={items} pagination={false}
          columns={[
            { title: '申请时间', dataIndex: 'requested_at', render: (value: string) => new Date(value).toLocaleString() },
            { title: '用户', dataIndex: 'user', render: (user: PrivacyRequestItem['user']) => <Space direction="vertical" size={0}><span>{user.nickname}</span><Typography.Text type="secondary" copyable>{user.id}</Typography.Text></Space> },
            { title: '类型', dataIndex: 'request_type', render: (value: string) => businessLabel(typeNames, value) },
            { title: '范围', dataIndex: 'scope', render: (value: string[]) => value.map((scope) => <Tag key={scope}>{scope}</Tag>) },
            { title: '状态', dataIndex: 'status', render: (value: string) => <Tag color={value === 'submitted' ? 'orange' : 'default'}>{businessLabel(statusNames, value)}</Tag> },
            { title: '阻断', dataIndex: 'blockers', render: (value: string[]) => value.length ? value.map((blocker) => <Tag color="red" key={blocker}>{blocker}</Tag>) : '-' },
            { title: '操作', render: (_: unknown, item: PrivacyRequestItem) => <Button disabled={!['submitted', 'verifying', 'processing'].includes(item.status)} onClick={() => { setSelected(item); form.setFieldsValue({ decision: item.blockers.length ? 'partially_completed' : 'completed' }) }}>处理</Button> },
          ]}
        />
      </Card>
          </> }] : []),
        ]}
      />
      <Modal title="处理隐私请求" open={Boolean(selected)} onCancel={() => setSelected(null)} onOk={() => form.submit()} confirmLoading={submitting} destroyOnHidden>
        <Form form={form} layout="vertical" onFinish={submit}>
          <Form.Item label="处理结论" name="decision" rules={[{ required: true }]}>
            <Select options={[
              { value: 'completed', label: '全部完成', disabled: Boolean(selected?.blockers.length) },
              { value: 'partially_completed', label: '部分完成' },
              { value: 'rejected', label: '驳回' },
            ]} />
          </Form.Item>
          <Form.Item label="处理说明" name="resolution_note" rules={[{ required: true, min: 4, max: 1000 }]}><Input.TextArea rows={4} /></Form.Item>
          <Form.Item label="当前管理员密码（二次验证）" name="password" rules={[{ required: true }]}><Input.Password autoComplete="current-password" /></Form.Item>
          <Form.Item label="MFA 动态码（已启用时必填）" name="mfa_code"><Input autoComplete="one-time-code" /></Form.Item>
        </Form>
      </Modal>
    </>
  )
}
