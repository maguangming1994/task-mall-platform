import { CloudSyncOutlined, DatabaseOutlined, SettingOutlined, SwapOutlined } from '@ant-design/icons'
import { Alert, Button, Card, Checkbox, Form, Input, Modal, Progress, Space, Switch, Table, Tag, Typography, message } from 'antd'
import { useCallback, useEffect, useState } from 'react'
import {
  ApiError,
  activateMediaStorageProvider,
  configureMediaStorageProvider,
  listMediaStorageMigrations,
  listMediaStorageProviders,
  startMediaStorageMigration,
  testMediaStorageProvider,
  verifyAdminRecent,
  type MediaStorageMigration,
  type MediaStorageProvider,
} from '../../api/admin'

interface ProviderForm {
  enabled: boolean; endpoint?: string; region?: string; bucket?: string; private_bucket?: string; public_base_url?: string;
  access_key?: string; secret_key?: string; current_password: string; mfa_code?: string; test_after_save: boolean
}
interface MigrationForm { delete_source: boolean; current_password: string; mfa_code?: string }

const migrationStatus: Record<string, { label: string; color: string }> = {
  pending: { label: '等待执行', color: 'default' }, running: { label: '迁移中', color: 'processing' },
  completed: { label: '迁移完成', color: 'success' }, completed_with_errors: { label: '部分失败', color: 'warning' }, failed: { label: '迁移失败', color: 'error' },
}

export function StorageManagementPanel() {
  const [providers, setProviders] = useState<MediaStorageProvider[]>([])
  const [migrations, setMigrations] = useState<MediaStorageMigration[]>([])
  const [editing, setEditing] = useState<MediaStorageProvider | null>(null)
  const [migratingTo, setMigratingTo] = useState<MediaStorageProvider | null>(null)
  const [loading, setLoading] = useState(false)
  const [submitting, setSubmitting] = useState(false)
  const [providerForm] = Form.useForm<ProviderForm>()
  const [migrationForm] = Form.useForm<MigrationForm>()
  const [messageApi, contextHolder] = message.useMessage()

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const [providerResult, migrationResult] = await Promise.all([listMediaStorageProviders(), listMediaStorageMigrations()])
      setProviders(providerResult.items)
      setMigrations(migrationResult.items)
    } catch (error) {
      messageApi.error(error instanceof ApiError ? error.message : '图片存储配置加载失败。')
    } finally {
      setLoading(false)
    }
  }, [messageApi])
  useEffect(() => { void load() }, [load])
  useEffect(() => {
    if (!migrations.some((item) => ['pending', 'running'].includes(item.status))) return
    const timer = window.setInterval(() => { void load() }, 3000)
    return () => window.clearInterval(timer)
  }, [migrations, load])

  const openProvider = (provider: MediaStorageProvider) => {
    setEditing(provider)
    providerForm.setFieldsValue({
      enabled: provider.enabled, endpoint: provider.endpoint ?? undefined, region: provider.region ?? undefined,
      bucket: provider.bucket ?? undefined, private_bucket: provider.private_bucket ?? undefined, public_base_url: provider.public_base_url ?? undefined,
      access_key: '', secret_key: '', current_password: '', mfa_code: '', test_after_save: provider.provider !== 'local',
    })
  }
  const saveProvider = async () => {
    if (!editing) return
    try {
      const values = await providerForm.validateFields()
      setSubmitting(true)
      await verifyAdminRecent(values.current_password, values.mfa_code || undefined)
      await configureMediaStorageProvider(editing.provider, {
        enabled: values.enabled, endpoint: values.endpoint || null, region: values.region || null,
        bucket: values.bucket || null, private_bucket: values.private_bucket || null, public_base_url: values.public_base_url || null,
        access_key: values.access_key || null, secret_key: values.secret_key || null,
      })
      if (values.test_after_save && values.enabled) await testMediaStorageProvider(editing.provider)
      messageApi.success(values.test_after_save && values.enabled ? '配置已保存，连通性测试通过。' : '存储配置已保存。')
      setEditing(null)
      await load()
    } catch (error) {
      if (error instanceof ApiError) messageApi.error(error.message)
    } finally {
      setSubmitting(false)
    }
  }
  const activate = (provider: MediaStorageProvider) => Modal.confirm({
    title: `切换到${provider.display_name}？`,
    content: provider.provider === 'local'
      ? '公共素材将切回服务器本地；受控私有上传继续遵循服务器 UPLOAD_DISK 配置，已有文件不受影响。'
      : '系统会再次测试公共素材桶和私有上传桶。切换后新素材与新受控上传写入该存储，已有文件仍保留在原存储。',
    okText: '测试并切换', cancelText: '取消',
    onOk: async () => {
      try {
        await activateMediaStorageProvider(provider.provider)
        messageApi.success(`当前图片存储已切换为${provider.display_name}。`)
        await load()
      } catch (error) { messageApi.error(error instanceof ApiError ? error.message : '存储切换失败。') }
    },
  })
  const openMigration = (provider: MediaStorageProvider) => {
    setMigratingTo(provider)
    migrationForm.setFieldsValue({ delete_source: false, current_password: '', mfa_code: '' })
  }
  const migrate = async () => {
    if (!migratingTo) return
    try {
      const values = await migrationForm.validateFields()
      setSubmitting(true)
      await verifyAdminRecent(values.current_password, values.mfa_code || undefined)
      await startMediaStorageMigration(migratingTo.provider, values.delete_source)
      messageApi.success(`新图片已切换到${migratingTo.display_name}，旧图片迁移任务已创建。`)
      setMigratingTo(null)
      await load()
    } catch (error) {
      if (error instanceof ApiError) messageApi.error(error.message)
    } finally {
      setSubmitting(false)
    }
  }

  return <Card bordered={false} style={{ marginBottom: 16 }} title={<Space><DatabaseOutlined />图片与对象存储</Space>} extra={<Button icon={<CloudSyncOutlined />} onClick={() => void load()}>刷新状态</Button>}>
    {contextHolder}
    <Alert showIcon type="info" message="全站运营图片统一进入公共素材存储；头像、帖子图片和任务/售后凭证使用隔离的私有存储并继续安全扫描。启用 COS 或 Kodo 时必须配置独立私有桶，密钥加密保存且不回显。" style={{ marginBottom: 16 }} />
    <Table rowKey="provider" loading={loading} dataSource={providers} pagination={false} columns={[
      { title: '存储', render: (_: unknown, item: MediaStorageProvider) => <Space direction="vertical" size={0}><strong>{item.display_name}</strong><Typography.Text type="secondary">{item.provider}</Typography.Text></Space> },
      { title: '状态', render: (_: unknown, item: MediaStorageProvider) => <Space>{item.active && <Tag color="blue">当前使用</Tag>}<Tag color={item.enabled ? 'success' : 'default'}>{item.enabled ? '已启用' : '已停用'}</Tag></Space> },
      { title: '空间 / 区域', render: (_: unknown, item: MediaStorageProvider) => item.provider === 'local' ? <Space direction="vertical" size={0}><span>公共：服务器本地</span><Typography.Text type="secondary">私有：沿用服务器 UPLOAD_DISK 配置</Typography.Text></Space> : <Space direction="vertical" size={0}><span>公共：{item.bucket || '未配置'}</span><Typography.Text type="secondary">私有：{item.private_bucket || '未配置'} · {item.region || '未配置区域'}</Typography.Text></Space> },
      { title: '最近测试', render: (_: unknown, item: MediaStorageProvider) => item.last_test_status ? <Tag color={item.last_test_status === 'success' ? 'success' : 'error'}>{item.last_test_status === 'success' ? '测试通过' : '测试失败'}</Tag> : '尚未测试' },
      { title: '操作', render: (_: unknown, item: MediaStorageProvider) => <Space wrap>
        <Button icon={<SettingOutlined />} onClick={() => openProvider(item)}>配置</Button>
        {!item.active && item.enabled && <Button onClick={() => activate(item)}>切换使用</Button>}
        {!item.active && item.enabled && <Button icon={<SwapOutlined />} onClick={() => openMigration(item)}>迁移并切换</Button>}
      </Space> },
    ]} />
    {migrations.length > 0 && <><Typography.Title level={5} style={{ marginTop: 24 }}>最近迁移任务</Typography.Title><Table rowKey="id" dataSource={migrations} pagination={false} columns={[
      { title: '迁移方向', render: (_: unknown, item: MediaStorageMigration) => `${item.from_provider} → ${item.to_provider}` },
      { title: '状态', render: (_: unknown, item: MediaStorageMigration) => { const meta = migrationStatus[item.status] ?? { label: item.status, color: 'default' }; return <Tag color={meta.color}>{meta.label}</Tag> } },
      { title: '进度', render: (_: unknown, item: MediaStorageMigration) => <Progress size="small" percent={item.total_assets ? Math.round(((item.processed_assets + item.failed_assets) / item.total_assets) * 100) : 100} status={item.status === 'failed' ? 'exception' : item.status === 'completed' ? 'success' : 'active'} format={() => `${item.processed_assets}/${item.total_assets}`} /> },
      { title: '失败', dataIndex: 'failed_assets', render: (count: number) => count ? <Tag color="error">{count} 个</Tag> : '0' },
      { title: '源文件', dataIndex: 'delete_source', render: (value: boolean) => value ? '成功后删除' : '保留' },
    ]} /></>}

    <Modal title={editing ? `配置${editing.display_name}` : '配置存储'} open={Boolean(editing)} onCancel={() => setEditing(null)} onOk={() => void saveProvider()} confirmLoading={submitting} okText="保存配置" cancelText="取消" destroyOnHidden>
      {editing && <Form form={providerForm} layout="vertical">
        <Form.Item name="enabled" label="启用该存储" valuePropName="checked"><Switch disabled={editing.active} /></Form.Item>
        {editing.provider === 'local' ? <Form.Item name="public_base_url" label="公开访问地址" rules={[{ required: true }, { type: 'url' }]}><Input placeholder="http://127.0.0.1:8000/storage" /></Form.Item> : <>
          <Form.Item name="endpoint" label="S3 兼容端点" rules={[{ required: true }, { type: 'url' }]}><Input placeholder={editing.provider === 'tencent_cos' ? 'https://cos.ap-guangzhou.myqcloud.com' : 'https://s3.cn-south-1.qiniucs.com'} /></Form.Item>
          <Form.Item name="region" label="区域 Region" rules={[{ required: true }]}><Input placeholder={editing.provider === 'tencent_cos' ? 'ap-guangzhou' : 'cn-south-1'} /></Form.Item>
          <Form.Item name="bucket" label="存储桶 / 空间名称" rules={[{ required: true }]}><Input /></Form.Item>
          <Form.Item name="private_bucket" label="私有上传存储桶 / 空间名称" extra="必须与公共素材桶不同，并设置为私有读写；用于头像、帖子附件、任务及售后凭证。" dependencies={['bucket']} rules={[{ required: true }, ({ getFieldValue }) => ({ validator: async (_, value) => { if (value && value === getFieldValue('bucket')) throw new Error('私有上传桶不能与公共素材桶相同') } })]}><Input /></Form.Item>
          <Form.Item name="public_base_url" label="公开访问域名或 CDN 域名" rules={[{ required: true }, { type: 'url' }]}><Input placeholder="https://img.example.com" /></Form.Item>
          <Form.Item name="access_key" label={`访问密钥${editing.access_key_configured ? `（已配置：${editing.access_key_masked}）` : ''}`} rules={[{ required: !editing.access_key_configured }]}><Input autoComplete="off" placeholder={editing.access_key_configured ? '留空表示不修改' : '请输入 AccessKey'} /></Form.Item>
          <Form.Item name="secret_key" label={`密钥 Secret${editing.secret_key_configured ? '（已配置）' : ''}`} rules={[{ required: !editing.secret_key_configured }]}><Input.Password autoComplete="new-password" placeholder={editing.secret_key_configured ? '留空表示不修改' : '请输入 SecretKey'} /></Form.Item>
          <Form.Item name="test_after_save" valuePropName="checked"><Checkbox>保存后立即执行连通性测试</Checkbox></Form.Item>
        </>}
        <Alert type="warning" showIcon message="修改存储凭据属于高风险操作，需要再次验证管理员身份。" style={{ marginBottom: 16 }} />
        <Form.Item name="current_password" label="当前管理员密码" rules={[{ required: true }]}><Input.Password /></Form.Item>
        <Form.Item name="mfa_code" label="MFA 验证码（已开启时必填）"><Input inputMode="numeric" maxLength={6} /></Form.Item>
      </Form>}
    </Modal>
    <Modal title={migratingTo ? `迁移旧图片到${migratingTo.display_name}` : '迁移旧图片'} open={Boolean(migratingTo)} onCancel={() => setMigratingTo(null)} onOk={() => void migrate()} confirmLoading={submitting} okText="开始迁移" cancelText="取消" destroyOnHidden>
      <Alert type="warning" showIcon message="开始后，新上传图片会立即写入目标存储；旧图片在后台逐个复制和校验，成功后才更新素材地址。迁移失败的图片仍保留原地址，不影响访问。" style={{ marginBottom: 16 }} />
      <Form form={migrationForm} layout="vertical">
        <Form.Item name="delete_source" valuePropName="checked"><Checkbox>校验成功并更新地址后删除源文件（建议首次迁移先保留）</Checkbox></Form.Item>
        <Form.Item name="current_password" label="当前管理员密码" rules={[{ required: true }]}><Input.Password /></Form.Item>
        <Form.Item name="mfa_code" label="MFA 验证码（已开启时必填）"><Input inputMode="numeric" maxLength={6} /></Form.Item>
      </Form>
    </Modal>
  </Card>
}
