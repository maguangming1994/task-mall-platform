import { CopyOutlined, EditOutlined, PlusOutlined, SendOutlined } from '@ant-design/icons'
import { Alert, Button, Form, Input, Modal, Select, Space, Switch, Table, Tag, Typography, message } from 'antd'
import { useCallback, useEffect, useState } from 'react'
import {
  ApiError, createManagedLegalDocument, listManagedLegalDocuments, publishManagedLegalDocument,
  updateManagedLegalDocument, verifyAdminRecent, type ManagedLegalDocument,
} from '../../api/admin'
import { RichTextEditor } from '../../components/RichTextEditor'
import { useAdminAuth } from '../auth/AdminAuthContext'

const documentTypeNames: Record<string, string> = {
  user_agreement: '用户协议', privacy_policy: '隐私政策', identity_service: '实名认证服务协议', payment_service: '支付服务协议',
  task_rules: '任务参与规则', points_rules: '积分规则',
  wallet_rules: '余额与提现规则', mall_after_sale: '商城售后规则', community_rules: '社区规则', cancellation_rules: '账号注销规则',
}
const documentTypeOptions = Object.entries(documentTypeNames).map(([value, label]) => ({ value, label }))

interface DocumentForm {
  document_type: string; version: string; title: string; content: string; consent_required: boolean;
  withdrawal_allowed: boolean; effective_at: string; change_summary: string
}
interface PublishForm { reason: string; password: string; mfa_code?: string }

function nextEffectiveTime() {
  const value = new Date(Date.now() + 24 * 60 * 60 * 1000)
  const offset = value.getTimezoneOffset() * 60 * 1000
  return new Date(value.getTime() - offset).toISOString().slice(0, 16)
}

function toDateTimeInput(value: string | null) {
  if (!value) return nextEffectiveTime()
  const date = new Date(value)
  const offset = date.getTimezoneOffset() * 60 * 1000
  return new Date(date.getTime() - offset).toISOString().slice(0, 16)
}

export function LegalDocumentManagementPanel() {
  const { admin } = useAdminAuth()
  const [items, setItems] = useState<ManagedLegalDocument[]>([])
  const [loading, setLoading] = useState(false)
  const [editing, setEditing] = useState<ManagedLegalDocument | 'new' | null>(null)
  const [publishing, setPublishing] = useState<ManagedLegalDocument | null>(null)
  const [submitting, setSubmitting] = useState(false)
  const [form] = Form.useForm<DocumentForm>()
  const [publishForm] = Form.useForm<PublishForm>()
  const [messageApi, contextHolder] = message.useMessage()

  const load = useCallback(async () => {
    setLoading(true)
    try { setItems((await listManagedLegalDocuments()).items) }
    catch (error) { messageApi.error(error instanceof ApiError ? error.message : '协议版本加载失败。') }
    finally { setLoading(false) }
  }, [messageApi])
  useEffect(() => { void load() }, [load])

  const openEditor = (document?: ManagedLegalDocument, asNewVersion = false) => {
    setEditing(document && !asNewVersion ? document : 'new')
    form.resetFields()
    form.setFieldsValue(document ? {
      document_type: document.document_type,
      version: asNewVersion ? '' : document.version,
      title: document.title,
      content: document.content,
      consent_required: document.consent_required,
      withdrawal_allowed: document.withdrawal_allowed,
      effective_at: asNewVersion ? nextEffectiveTime() : toDateTimeInput(document.effective_at),
      change_summary: asNewVersion ? '' : document.change_summary ?? '',
    } : {
      document_type: 'user_agreement', version: '', title: '用户协议', content: '', consent_required: true,
      withdrawal_allowed: true, effective_at: nextEffectiveTime(), change_summary: '',
    })
  }
  const save = async (values: DocumentForm) => {
    setSubmitting(true)
    try {
      const payload = { ...values, content_format: 'rich_text_html', effective_at: new Date(values.effective_at).toISOString() }
      if (editing !== 'new' && editing) await updateManagedLegalDocument(editing.id, payload)
      else await createManagedLegalDocument(payload)
      messageApi.success(editing === 'new' ? '协议新版本草稿已创建。' : '协议草稿已保存。')
      setEditing(null)
      await load()
    } catch (error) { messageApi.error(error instanceof ApiError ? error.message : '协议草稿保存失败。') }
    finally { setSubmitting(false) }
  }
  const publish = async (values: PublishForm) => {
    if (!publishing) return
    setSubmitting(true)
    try {
      await verifyAdminRecent(values.password, values.mfa_code || undefined)
      await publishManagedLegalDocument(publishing.id, values.reason)
      messageApi.success('协议版本已发布，将在设置的生效时间成为当前版本。')
      setPublishing(null); publishForm.resetFields(); await load()
    } catch (error) { messageApi.error(error instanceof ApiError ? error.message : '协议发布失败。') }
    finally { setSubmitting(false) }
  }

  return <>
    {contextHolder}
    <div className="security-toolbar"><Typography.Paragraph type="secondary">已发布正文不可修改；调整内容时创建新版本，发布并生效后需要用户重新确认。</Typography.Paragraph><Button type="primary" icon={<PlusOutlined />} onClick={() => openEditor()}>新建协议版本</Button></div>
    <Alert showIcon type="info" message="首要维护《用户协议》和《隐私政策》。正文支持标题、列表、链接、图片和表格，发布前请完成法务与隐私合规复核。" style={{ marginBottom: 16 }} />
    <Table rowKey="id" loading={loading} dataSource={items} pagination={{ pageSize: 10 }} columns={[
      { title: '协议', render: (_: unknown, item: ManagedLegalDocument) => <Space direction="vertical" size={0}><strong>{documentTypeNames[item.document_type] ?? item.document_type}</strong><Typography.Text type="secondary">{item.title}</Typography.Text></Space> },
      { title: '版本', dataIndex: 'version' },
      { title: '状态', dataIndex: 'status', render: (status: string) => <Tag color={status === 'published' ? 'success' : 'orange'}>{status === 'published' ? '已发布' : '草稿'}</Tag> },
      { title: '生效时间', dataIndex: 'effective_at', render: (value: string) => value ? new Date(value).toLocaleString() : '未设置' },
      { title: '需要同意', dataIndex: 'consent_required', render: (value: boolean) => value ? <Tag color="blue">需要勾选</Tag> : '仅展示' },
      { title: '操作', width: 260, render: (_: unknown, item: ManagedLegalDocument) => <Space wrap>
        {item.status === 'draft' ? <><Button size="small" icon={<EditOutlined />} onClick={() => openEditor(item)}>编辑草稿</Button><Button size="small" type="primary" icon={<SendOutlined />} onClick={() => { setPublishing(item); publishForm.setFieldsValue({ reason: '已完成法务、隐私和业务影响复核。' }) }}>发布</Button></> : <Button size="small" icon={<CopyOutlined />} onClick={() => openEditor(item, true)}>创建新版本</Button>}
      </Space> },
    ]} />

    <Modal title={editing === 'new' ? '新建协议版本草稿' : editing ? `编辑草稿：${editing.title}` : '协议草稿'} open={Boolean(editing)} onCancel={() => setEditing(null)} onOk={() => form.submit()} confirmLoading={submitting} okText="保存草稿" width={1000} styles={{ body: { maxHeight: '75vh', overflowY: 'auto', paddingRight: 8 } }} destroyOnHidden>
      <Form form={form} layout="vertical" onFinish={save}>
        <Space align="start" style={{ display: 'flex' }}>
          <Form.Item name="document_type" label="协议类型" rules={[{ required: true }]} style={{ flex: 1 }}><Select options={documentTypeOptions} disabled={editing !== 'new'} /></Form.Item>
          <Form.Item name="version" label="版本号" rules={[{ required: true, max: 40 }]} style={{ flex: 1 }}><Input placeholder="例如：1.0.0" /></Form.Item>
          <Form.Item name="effective_at" label="生效时间" rules={[{ required: true }]} style={{ flex: 1 }}><Input type="datetime-local" /></Form.Item>
        </Space>
        <Form.Item name="title" label="显示标题" rules={[{ required: true, max: 160 }]}><Input /></Form.Item>
        <Form.Item name="content" label="协议正文" rules={[{ required: true, message: '请输入协议正文' }]}><RichTextEditor placeholder="请输入协议正文…" /></Form.Item>
        <Space><Form.Item name="consent_required" valuePropName="checked" label="用户授权"><Switch checkedChildren="需要勾选" unCheckedChildren="仅展示" /></Form.Item><Form.Item name="withdrawal_allowed" valuePropName="checked" label="允许在线撤回"><Switch /></Form.Item></Space>
        <Form.Item name="change_summary" label="版本变更摘要" rules={[{ required: true, min: 4, max: 500 }]}><Input.TextArea rows={3} showCount maxLength={500} /></Form.Item>
      </Form>
    </Modal>
    <Modal title={publishing ? `发布：${publishing.title} ${publishing.version}` : '发布协议'} open={Boolean(publishing)} onCancel={() => setPublishing(null)} onOk={() => publishForm.submit()} confirmLoading={submitting} okText="确认发布" destroyOnHidden>
      <Alert showIcon type="warning" message="发布后正文不可修改；到达生效时间后，尚未同意该版本的用户将被要求重新确认。" style={{ marginBottom: 16 }} />
      <Form form={publishForm} layout="vertical" onFinish={publish}>
        <Form.Item name="reason" label="法务复核与发布说明" rules={[{ required: true, min: 4, max: 500 }]}><Input.TextArea rows={3} /></Form.Item>
        <Form.Item name="password" label="当前管理员密码" rules={[{ required: true }]}><Input.Password autoComplete="current-password" /></Form.Item>
        {admin?.requires_mfa && <Form.Item name="mfa_code" label="动态验证码或恢复码" rules={[{ required: true }]}><Input autoComplete="one-time-code" /></Form.Item>}
      </Form>
    </Modal>
  </>
}
