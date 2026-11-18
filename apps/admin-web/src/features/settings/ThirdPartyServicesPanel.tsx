import { ApiOutlined, CloudUploadOutlined, DeleteOutlined, ReloadOutlined, SafetyCertificateOutlined } from '@ant-design/icons'
import { Alert, Button, Card, Checkbox, Form, Input, InputNumber, Popconfirm, Select, Space, Switch, Tabs, Tag, Typography, Upload, message, type UploadFile } from 'antd'
import { useCallback, useEffect, useState } from 'react'
import {
  ApiError,
  deleteWechatVerificationFile,
  getIdentityVerificationSettings,
  getWechatPaymentSettings,
  getWechatSettings,
  saveIdentityVerificationSettings,
  saveWechatAppSettings,
  saveWechatPaymentCredentials,
  saveWechatPaymentSettings,
  testWechatAppSettings,
  testWechatPaymentSettings,
  uploadWechatVerificationFile,
  verifyAdminRecent,
  type IdentityVerificationSettings,
  type SecureValueState,
  type WechatAppSettings,
  type WechatPaymentSettings,
  type WechatSettingsBundle,
} from '../../api/admin'
import { SmsManagementPanel } from './SmsManagementPanel'

type RecentValues = { reason: string; current_password: string; mfa_code?: string }
type WechatFormValues = RecentValues & {
  mini: AppFormValues
  official_account: AppFormValues
}
type AppFormValues = {
  driver: 'local' | 'wechat'; app_id: string; timeout_seconds: number; login_enabled: boolean
  app_secret: string; token: string; encoding_aes_key: string
  clear_app_secret: boolean; clear_token: boolean; clear_encoding_aes_key: boolean
}
type AppSecretField = 'app_secret' | 'token' | 'encoding_aes_key'
type PaymentFormValues = RecentValues & {
  driver: 'local' | 'wechat'; merchant_id: string; merchant_certificate_serial: string
  notify_url: string; refund_notify_url: string; timeout_seconds: number; api_v3_key: string
  clear_api_v3_key: boolean; clear_merchant_private_key: boolean; clear_platform_certificate: boolean
}
type IdentityFormValues = RecentValues & {
  driver: 'disabled' | 'manual' | 'aliyun'; collection_enabled: boolean; manual_review_enabled: boolean
  aliyun: { region: string; endpoint: string; timeout_seconds: number }
  access_key_id: string; access_key_secret: string; security_token: string
  clear_access_key_id: boolean; clear_access_key_secret: boolean; clear_security_token: boolean
}

function errorText(error: unknown, fallback: string) {
  return error instanceof ApiError ? error.message : fallback
}

function secretTag(state: SecureValueState) {
  const source = state.source === 'backend' ? '后台' : state.source === 'env' ? '环境变量' : '未配置'
  return <Tag color={state.configured ? 'success' : 'default'}>{state.configured ? `${source}${state.masked ? ` · ${state.masked}` : ''}` : source}</Tag>
}

function statusTags(ready: boolean, status: 'success' | 'failed' | null) {
  return <Space wrap>
    <Tag color={ready ? 'success' : 'warning'}>{ready ? '配置完整' : '待完善'}</Tag>
    <Tag color={status === 'success' ? 'success' : status === 'failed' ? 'error' : 'default'}>
      {status === 'success' ? '最近测试通过' : status === 'failed' ? '最近测试失败' : '尚未测试'}
    </Tag>
  </Space>
}

function RecentFields() {
  return <section className="third-party-section third-party-verification">
    <Typography.Title level={5}>管理员身份验证</Typography.Title>
    <Form.Item name="reason" label="修改原因" rules={[{ required: true, min: 4, max: 500, message: '请填写至少 4 个字的修改原因。' }]}>
      <Input.TextArea rows={2} maxLength={500} showCount />
    </Form.Item>
    <div className="third-party-two-column">
      <Form.Item name="current_password" label="当前管理员密码" rules={[{ required: true, message: '请输入当前管理员密码。' }]}><Input.Password autoComplete="current-password" /></Form.Item>
      <Form.Item name="mfa_code" label="MFA 验证码（已启用时必填）"><Input inputMode="numeric" maxLength={6} /></Form.Item>
    </div>
  </section>
}

const appDefaults: AppFormValues = {
  driver: 'local', app_id: '', timeout_seconds: 5, login_enabled: true,
  app_secret: '', token: '', encoding_aes_key: '',
  clear_app_secret: false, clear_token: false, clear_encoding_aes_key: false,
}

function appValues(settings: WechatAppSettings): AppFormValues {
  return {
    ...appDefaults, driver: settings.driver, app_id: settings.app_id,
    timeout_seconds: settings.timeout_seconds, login_enabled: settings.login_enabled,
  }
}

export function ThirdPartyServicesPanel() {
  const [wechat, setWechat] = useState<WechatSettingsBundle | null>(null)
  const [payment, setPayment] = useState<WechatPaymentSettings | null>(null)
  const [identity, setIdentity] = useState<IdentityVerificationSettings | null>(null)
  const [loading, setLoading] = useState(false)
  const [denied, setDenied] = useState(false)
  const [busy, setBusy] = useState('')
  const [verificationFile, setVerificationFile] = useState<UploadFile | null>(null)
  const [privateKey, setPrivateKey] = useState<UploadFile | null>(null)
  const [platformCertificate, setPlatformCertificate] = useState<UploadFile | null>(null)
  const [wechatForm] = Form.useForm<WechatFormValues>()
  const [paymentForm] = Form.useForm<PaymentFormValues>()
  const [identityForm] = Form.useForm<IdentityFormValues>()
  const [messageApi, contextHolder] = message.useMessage()

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const [wechatValue, paymentValue, identityValue] = await Promise.all([
        getWechatSettings(), getWechatPaymentSettings(), getIdentityVerificationSettings(),
      ])
      setWechat(wechatValue); setPayment(paymentValue); setIdentity(identityValue)
      wechatForm.setFieldsValue({ mini: appValues(wechatValue.mini), official_account: appValues(wechatValue.official_account), reason: '', current_password: '', mfa_code: '' })
      paymentForm.setFieldsValue({
        driver: paymentValue.driver, merchant_id: paymentValue.merchant_id,
        merchant_certificate_serial: paymentValue.merchant_certificate_serial,
        notify_url: paymentValue.notify_url, refund_notify_url: paymentValue.refund_notify_url,
        timeout_seconds: paymentValue.timeout_seconds, api_v3_key: '', clear_api_v3_key: false,
        clear_merchant_private_key: false, clear_platform_certificate: false, reason: '', current_password: '', mfa_code: '',
      })
      identityForm.setFieldsValue({
        driver: identityValue.driver, collection_enabled: identityValue.collection_enabled,
        manual_review_enabled: identityValue.manual_review_enabled, aliyun: identityValue.aliyun,
        access_key_id: '', access_key_secret: '', security_token: '', clear_access_key_id: false,
        clear_access_key_secret: false, clear_security_token: false, reason: '', current_password: '', mfa_code: '',
      })
    } catch (error) {
      // 非超级管理员后端返回 403：直接隐藏整个配置区，避免暴露字段结构。
      if (error instanceof ApiError && error.status === 403) { setDenied(true); return }
      messageApi.error(errorText(error, '第三方服务配置加载失败。'))
    } finally { setLoading(false) }
  }, [identityForm, messageApi, paymentForm, wechatForm])

  useEffect(() => { void load() }, [load])

  const verifyRecent = async (values: RecentValues) => {
    await verifyAdminRecent(values.current_password, values.mfa_code?.trim() || undefined)
  }

  const saveWechat = async (provider: 'mini' | 'official_account') => {
    try {
      const values = await wechatForm.validateFields()
      const app = values[provider]
      const secretFields: AppSecretField[] = provider === 'mini'
        ? ['app_secret']
        : ['app_secret', 'token', 'encoding_aes_key']
      for (const field of secretFields) {
        if ((app[field]?.trim() ?? '') && app[`clear_${field}`]) {
          messageApi.warning('密钥不能同时输入新值并勾选清除。')
          return
        }
      }
      setBusy(`wechat-${provider}`)
      await verifyRecent(values)
      const input: Record<string, unknown> = {
        reason: values.reason.trim(), driver: app.driver, app_id: app.app_id?.trim() ?? '',
        timeout_seconds: Number(app.timeout_seconds), login_enabled: Boolean(app.login_enabled),
      }
      for (const field of secretFields) {
        const value = app[field]?.trim() ?? ''
        if (value) input[field] = value
        if (app[`clear_${field}`]) input[`clear_${field}`] = true
      }
      const updated = await saveWechatAppSettings(provider, input)
      setWechat((current) => current ? { ...current, [provider]: updated } : current)
      wechatForm.setFieldValue(provider, appValues(updated))
      messageApi.success(provider === 'mini' ? '小程序配置已保存。' : '公众号配置已保存。')
    } catch (error) { messageApi.error(errorText(error, '微信配置保存失败，请刷新页面后重试。')) }
    finally { setBusy('') }
  }

  const testWechat = async (provider: 'mini' | 'official_account') => {
    try {
      if (!wechat?.[provider].ready) {
        const providerName = provider === 'mini' ? '微信小程序' : '微信公众号'
        return messageApi.warning(`请先保存完整的${providerName}配置，再测试连接。`)
      }
      const values = wechatForm.getFieldsValue()
      if (!values.current_password) return messageApi.warning('连接测试前请填写管理员密码。')
      setBusy(`test-${provider}`); await verifyRecent(values)
      const result = await testWechatAppSettings(provider); messageApi.success(result.message); await load()
    } catch (error) { messageApi.error(errorText(error, '微信连接测试失败。')) } finally { setBusy('') }
  }

  const uploadVerification = async () => {
    const file = verificationFile?.originFileObj
    if (!file) return messageApi.warning('请先选择微信验证文件。')
    try {
      const values = wechatForm.getFieldsValue()
      if (!values.current_password) return messageApi.warning('上传前请填写管理员密码。')
      setBusy('verification-upload'); await verifyRecent(values)
      await uploadWechatVerificationFile(file); setVerificationFile(null); await load(); messageApi.success('验证文件已发布到站点根路径。')
    } catch (error) { messageApi.error(errorText(error, '验证文件上传失败。')) } finally { setBusy('') }
  }

  const removeVerification = async (filename: string) => {
    try {
      const values = wechatForm.getFieldsValue(); setBusy(`delete-${filename}`); await verifyRecent(values)
      await deleteWechatVerificationFile(filename); await load(); messageApi.success('验证文件已删除。')
    } catch (error) { messageApi.error(errorText(error, '验证文件删除失败。')) } finally { setBusy('') }
  }

  const savePayment = async () => {
    try {
      const values = await paymentForm.validateFields(); setBusy('payment-save'); await verifyRecent(values)
      let updated = await saveWechatPaymentSettings({
        reason: values.reason.trim(), driver: values.driver, merchant_id: values.merchant_id.trim(),
        merchant_certificate_serial: values.merchant_certificate_serial.trim(), notify_url: values.notify_url.trim(),
        refund_notify_url: values.refund_notify_url.trim(), timeout_seconds: Number(values.timeout_seconds),
      })
      const clears = ['api_v3_key', 'merchant_private_key', 'platform_certificate'].filter((field) => values[`clear_${field}` as keyof PaymentFormValues])
      if (values.api_v3_key || privateKey?.originFileObj || platformCertificate?.originFileObj || clears.length) {
        updated = await saveWechatPaymentCredentials({
          reason: values.reason.trim(), apiV3Key: values.api_v3_key.trim() || undefined,
          privateKey: privateKey?.originFileObj, platformCertificate: platformCertificate?.originFileObj, clear: clears,
        })
      }
      setPayment(updated); setPrivateKey(null); setPlatformCertificate(null)
      paymentForm.setFieldsValue({ api_v3_key: '', clear_api_v3_key: false, clear_merchant_private_key: false, clear_platform_certificate: false, reason: '', current_password: '', mfa_code: '' })
      messageApi.success('微信支付配置、密钥和证书已保存。')
    } catch (error) { messageApi.error(errorText(error, '微信支付配置保存失败。')) } finally { setBusy('') }
  }

  const testPayment = async () => {
    try {
      const values = paymentForm.getFieldsValue(); setBusy('payment-test'); await verifyRecent(values)
      const result = await testWechatPaymentSettings(); messageApi.success(result.message); await load()
    } catch (error) { messageApi.error(errorText(error, '微信支付连接测试失败。')) } finally { setBusy('') }
  }

  const saveIdentity = async () => {
    try {
      const values = await identityForm.validateFields(); setBusy('identity-save'); await verifyRecent(values)
      const input: Record<string, unknown> = {
        reason: values.reason.trim(), driver: values.driver,
        collection_enabled: values.collection_enabled, manual_review_enabled: values.manual_review_enabled,
        aliyun: values.aliyun,
      }
      for (const field of ['access_key_id', 'access_key_secret', 'security_token'] as const) {
        if (values[field].trim()) input[field] = values[field].trim()
        if (values[`clear_${field}`]) input[`clear_${field}`] = true
      }
      const updated = await saveIdentityVerificationSettings(input)
      setIdentity(updated); messageApi.success('实名认证配置已保存。')
      identityForm.setFieldsValue({
        access_key_id: '', access_key_secret: '', security_token: '', clear_access_key_id: false,
        clear_access_key_secret: false, clear_security_token: false, reason: '', current_password: '', mfa_code: '',
      })
    } catch (error) { messageApi.error(errorText(error, '实名认证配置保存失败。')) } finally { setBusy('') }
  }

  const appSection = (provider: 'mini' | 'official_account', title: string) => {
    const state = wechat?.[provider]
    if (!state) return null
    const isOa = provider === 'official_account'
    return <section className="third-party-section">
      <div className="third-party-section-heading"><div><Typography.Title level={5}>{title}</Typography.Title>{statusTags(state.ready, state.last_test_status)}</div>
        <Button icon={<ApiOutlined />} loading={busy === `test-${provider}`} onClick={() => void testWechat(provider)}>测试连接</Button></div>
      <div className="third-party-two-column">
        <Form.Item name={[provider, 'driver']} label="运行模式"><Select options={[{ value: 'wechat', label: '真实微信' }, { value: 'local', label: '本地模拟（仅开发）' }]} /></Form.Item>
        <Form.Item name={[provider, 'timeout_seconds']} label="请求超时"><InputNumber min={1} max={30} addonAfter="秒" style={{ width: '100%' }} /></Form.Item>
      </div>
      <Form.Item name={[provider, 'app_id']} label="AppID" rules={[{ pattern: /^$|^wx[A-Za-z0-9]{6,62}$/, message: 'AppID 应以 wx 开头。' }]}><Input maxLength={64} /></Form.Item>
      <Form.Item name={[provider, 'login_enabled']} label="启用微信登录" valuePropName="checked" extra="关闭后该端登录方式列表不再提供微信登录，保存即生效。"><Switch /></Form.Item>
      {isOa && <Form.Item label="网页授权回调域名（只读核对）" extra="需与微信公众平台「网页授权域名」完全一致。">
        <Typography.Text code>{state.oauth_redirect_domain || '未配置，请检查站点域名'}</Typography.Text>
      </Form.Item>}
      <Form.Item name={[provider, 'app_secret']} label={<Space>AppSecret {secretTag(state.app_secret)}</Space>}><Input.Password autoComplete="new-password" placeholder="留空表示保留当前值" /></Form.Item>
      {state.app_secret.configured && <Form.Item name={[provider, 'clear_app_secret']} valuePropName="checked"><Checkbox>清除已保存 AppSecret</Checkbox></Form.Item>}
      {isOa && <>
        <Form.Item name={[provider, 'token']} label={<Space>消息 Token {secretTag(state.token)}</Space>}><Input.Password autoComplete="new-password" placeholder="留空表示保留当前值" /></Form.Item>
        {state.token.configured && <Form.Item name={[provider, 'clear_token']} valuePropName="checked"><Checkbox>清除已保存 Token</Checkbox></Form.Item>}
        <Form.Item name={[provider, 'encoding_aes_key']} label={<Space>EncodingAESKey {secretTag(state.encoding_aes_key)}</Space>}><Input.Password autoComplete="new-password" placeholder="留空表示保留当前值" /></Form.Item>
        {state.encoding_aes_key.configured && <Form.Item name={[provider, 'clear_encoding_aes_key']} valuePropName="checked"><Checkbox>清除已保存 EncodingAESKey</Checkbox></Form.Item>}
      </>}
      <Button type="primary" icon={<SafetyCertificateOutlined />} loading={busy === `wechat-${provider}`} onClick={() => void saveWechat(provider)}>保存{title}配置</Button>
    </section>
  }

  const wechatTab = <Form form={wechatForm} layout="vertical" initialValues={{ mini: appDefaults, official_account: appDefaults }}>
    <Alert showIcon type="warning" message="密钥不会回显；后台配置优先，未配置字段才回退服务器环境变量。" />
    <div className="third-party-grid">{appSection('mini', '微信小程序')}{appSection('official_account', '微信公众号')}</div>
    <section className="third-party-section">
      <Typography.Title level={5}>公众号验证文件</Typography.Title>
      <Space wrap>
        <Upload accept=".txt" maxCount={1} fileList={verificationFile ? [verificationFile] : []} beforeUpload={(file) => { setVerificationFile(file); return false }} onRemove={() => { setVerificationFile(null); return true }}>
          <Button icon={<CloudUploadOutlined />}>选择 MP_verify 文件</Button>
        </Upload>
        <Button type="primary" loading={busy === 'verification-upload'} onClick={() => void uploadVerification()}>发布验证文件</Button>
      </Space>
      <div className="third-party-file-list">{wechat?.verification_files.map((file) => <div key={file.filename}><a href={file.public_url} target="_blank" rel="noreferrer">{file.filename}</a><Popconfirm title="确认删除该验证文件？" onConfirm={() => void removeVerification(file.filename)}><Button danger type="text" icon={<DeleteOutlined />} loading={busy === `delete-${file.filename}`} /></Popconfirm></div>)}</div>
    </section>
    <RecentFields />
  </Form>

  const paymentTab = <Form form={paymentForm} layout="vertical">
    {payment && <div className="third-party-summary">{statusTags(payment.ready, payment.last_test_status)}<Button icon={<ApiOutlined />} loading={busy === 'payment-test'} onClick={() => void testPayment()}>测试商户连接</Button></div>}
    <div className="third-party-grid">
      <section className="third-party-section">
        <Typography.Title level={5}>商户参数</Typography.Title>
        <div className="third-party-two-column"><Form.Item name="driver" label="支付模式"><Select options={[{ value: 'wechat', label: '真实微信支付' }, { value: 'local', label: '本地模拟（仅开发）' }]} /></Form.Item><Form.Item name="timeout_seconds" label="请求超时"><InputNumber min={1} max={30} addonAfter="秒" style={{ width: '100%' }} /></Form.Item></div>
        <div className="third-party-two-column"><Form.Item name="merchant_id" label="商户号"><Input /></Form.Item><Form.Item name="merchant_certificate_serial" label="商户证书序列号"><Input /></Form.Item></div>
        <Form.Item name="notify_url" label="支付回调 HTTPS 地址"><Input /></Form.Item>
        <Form.Item name="refund_notify_url" label="退款回调 HTTPS 地址"><Input /></Form.Item>
        <Typography.Paragraph type="secondary" style={{ marginBottom: 4 }}>场景 AppID 绑定（由「微信应用」AppID 自动同步）：</Typography.Paragraph>
        <Typography.Text>小程序：{payment?.mini_app_id || '未配置'}</Typography.Text><br />
        <Typography.Text>公众号：{payment?.official_account_app_id || '未配置'}</Typography.Text>
      </section>
      <section className="third-party-section">
        <Typography.Title level={5}>密钥与证书</Typography.Title>
        {payment && <>
          <Form.Item name="api_v3_key" label={<Space>APIv3 密钥 {secretTag(payment.api_v3_key)}</Space>}><Input.Password maxLength={32} autoComplete="new-password" /></Form.Item>
          {payment.api_v3_key.configured && <Form.Item name="clear_api_v3_key" valuePropName="checked"><Checkbox>清除 APIv3 密钥</Checkbox></Form.Item>}
          <Typography.Text>商户私钥 {secretTag(payment.merchant_private_key)}</Typography.Text>
          <Upload accept=".pem,.key" maxCount={1} fileList={privateKey ? [privateKey] : []} beforeUpload={(file) => { setPrivateKey(file); return false }} onRemove={() => { setPrivateKey(null); return true }}><Button block icon={<CloudUploadOutlined />}>选择商户私钥 PEM</Button></Upload>
          {payment.merchant_private_key.configured && <Form.Item name="clear_merchant_private_key" valuePropName="checked"><Checkbox>清除商户私钥</Checkbox></Form.Item>}
          <Typography.Text>平台证书 {secretTag(payment.platform_certificate)}</Typography.Text>
          <Upload accept=".pem,.cer,.crt" maxCount={1} fileList={platformCertificate ? [platformCertificate] : []} beforeUpload={(file) => { setPlatformCertificate(file); return false }} onRemove={() => { setPlatformCertificate(null); return true }}><Button block icon={<CloudUploadOutlined />}>选择微信支付平台证书</Button></Upload>
          {payment.platform_certificate.configured && <Form.Item name="clear_platform_certificate" valuePropName="checked"><Checkbox>清除平台证书</Checkbox></Form.Item>}
        </>}
      </section>
    </div>
    <RecentFields />
    <Button type="primary" icon={<SafetyCertificateOutlined />} loading={busy === 'payment-save'} onClick={() => void savePayment()}>保存微信支付配置</Button>
  </Form>

  const identityTab = <Form form={identityForm} layout="vertical">
    <Alert showIcon type="info" message="阿里云模式使用身份二要素核验。姓名和身份证号仅在单次核验请求中使用，系统只留存掩码、不可逆摘要和认证结果。" />
    <section className="third-party-section">
      {identity && statusTags(identity.ready, null)}
      <Form.Item name="driver" label="实名认证模式"><Select options={[{ value: 'disabled', label: '关闭' }, { value: 'manual', label: '人工审核' }, { value: 'aliyun', label: '阿里云身份二要素核验' }]} /></Form.Item>
      <Form.Item name="collection_enabled" label="允许收集实名资料" valuePropName="checked"><Switch /></Form.Item>
      <Form.Item name="manual_review_enabled" label="启用人工复核" valuePropName="checked"><Switch /></Form.Item>
    </section>
    <section className="third-party-section">
      <Typography.Title level={5}>阿里云信息核验</Typography.Title>
      <div className="third-party-two-column">
        <Form.Item name={['aliyun', 'region']} label="Region"><Input placeholder="cn-hangzhou" /></Form.Item>
        <Form.Item name={['aliyun', 'timeout_seconds']} label="请求超时"><InputNumber min={1} max={30} addonAfter="秒" style={{ width: '100%' }} /></Form.Item>
      </div>
      <Form.Item name={['aliyun', 'endpoint']} label="Endpoint"><Input placeholder="cloudauth.aliyuncs.com" /></Form.Item>
      {identity && <>
        <Form.Item name="access_key_id" label={<Space>AccessKey ID {secretTag(identity.access_key_id)}</Space>}><Input.Password autoComplete="new-password" placeholder="留空表示保留当前值" /></Form.Item>
        {identity.access_key_id.configured && <Form.Item name="clear_access_key_id" valuePropName="checked"><Checkbox>清除 AccessKey ID</Checkbox></Form.Item>}
        <Form.Item name="access_key_secret" label={<Space>AccessKey Secret {secretTag(identity.access_key_secret)}</Space>}><Input.Password autoComplete="new-password" placeholder="留空表示保留当前值" /></Form.Item>
        {identity.access_key_secret.configured && <Form.Item name="clear_access_key_secret" valuePropName="checked"><Checkbox>清除 AccessKey Secret</Checkbox></Form.Item>}
        <Form.Item name="security_token" label={<Space>SecurityToken（可选） {secretTag(identity.security_token)}</Space>}><Input.Password autoComplete="new-password" placeholder="仅使用临时 STS 凭证时填写" /></Form.Item>
        {identity.security_token.configured && <Form.Item name="clear_security_token" valuePropName="checked"><Checkbox>清除 SecurityToken</Checkbox></Form.Item>}
      </>}
    </section>
    <RecentFields />
    <Button type="primary" loading={busy === 'identity-save'} onClick={() => void saveIdentity()}>保存实名认证配置</Button>
  </Form>

  if (denied) return <Card bordered={false} className="third-party-card" title={<Space><ApiOutlined />第三方服务配置</Space>}>
    <Alert showIcon type="warning" message="仅超级管理员可查看和修改微信与支付配置。" description="如需调整，请联系超级管理员操作。" />
  </Card>

  return <Card bordered={false} className="third-party-card" title={<Space><ApiOutlined />第三方服务配置</Space>} extra={<Button icon={<ReloadOutlined />} loading={loading} onClick={() => void load()}>刷新</Button>}>
    {contextHolder}
    <Tabs items={[
      { key: 'wechat', label: '微信应用', children: wechatTab },
      { key: 'payment', label: '微信支付', children: paymentTab },
      { key: 'sms', label: '短信服务', children: <SmsManagementPanel /> },
      { key: 'identity', label: '实名认证', children: identityTab },
    ]} />
  </Card>
}
