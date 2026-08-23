import { ApiOutlined, ReloadOutlined, SafetyCertificateOutlined, SendOutlined } from '@ant-design/icons'
import { Alert, Button, Card, Checkbox, Form, Input, InputNumber, Select, Space, Tag, Typography, message } from 'antd'
import { useCallback, useEffect, useState } from 'react'
import {
  ApiError,
  getSmsServiceSettings,
  saveSmsServiceSettings,
  sendSmsServiceTest,
  verifyAdminRecent,
  type SmsSecretField,
  type SmsSecretState,
  type SmsServiceDriver,
  type SmsServiceSettings,
  type SmsServiceSettingsInput,
} from '../../api/admin'

interface SmsFormValues {
  driver: SmsServiceDriver
  access_key_id: string
  access_key_secret: string
  security_token: string
  clear_access_key_id: boolean
  clear_access_key_secret: boolean
  clear_security_token: boolean
  region: string
  endpoint: string
  sign_name: string
  template_code: string
  verification_variables: string
  timeout_seconds: number
  ttl_seconds: number
  resend_interval_seconds: number
  hourly_limit: number
  daily_limit: number
  max_attempts: number
  reason: string
  current_password: string
  mfa_code?: string
}

const secretFields: Array<{ key: SmsSecretField; label: string; formName: keyof SmsFormValues }> = [
  { key: 'access_key_id', label: 'AccessKey ID', formName: 'access_key_id' },
  { key: 'access_key_secret', label: 'AccessKey Secret', formName: 'access_key_secret' },
  { key: 'security_token', label: 'SecurityToken', formName: 'security_token' },
]

const defaultValues: SmsFormValues = {
  driver: 'aliyun',
  access_key_id: '',
  access_key_secret: '',
  security_token: '',
  clear_access_key_id: false,
  clear_access_key_secret: false,
  clear_security_token: false,
  region: '',
  endpoint: '',
  sign_name: '',
  template_code: '',
  verification_variables: 'code, minutes',
  timeout_seconds: 5,
  ttl_seconds: 300,
  resend_interval_seconds: 60,
  hourly_limit: 5,
  daily_limit: 50,
  max_attempts: 5,
  reason: '',
  current_password: '',
  mfa_code: '',
}

function toFormValues(value: SmsServiceSettings): SmsFormValues {
  return {
    ...defaultValues,
    driver: value.driver,
    region: value.region ?? '',
    endpoint: value.endpoint ?? '',
    sign_name: value.sign_name ?? '',
    template_code: value.template_code ?? '',
    verification_variables: (value.verification_variables ?? []).join(', '),
    timeout_seconds: value.timeout_seconds,
    ttl_seconds: value.ttl_seconds,
    resend_interval_seconds: value.resend_interval_seconds,
    hourly_limit: value.hourly_limit,
    daily_limit: value.daily_limit,
    max_attempts: value.max_attempts,
  }
}

function secretStateLabel(state: SmsSecretState) {
  return state.configured ? `已配置${state.masked ? ` · ${state.masked}` : ''}` : '未配置'
}

function parseVariables(value: string) {
  return Array.from(new Set(value.split(/[\s,，、\n]+/).map((item) => item.trim()).filter(Boolean)))
}

function nullable(value: string) {
  const next = value.trim()
  return next === '' ? null : next
}

function formatDate(value: string | null) {
  return value ? new Date(value).toLocaleString() : '—'
}

function isValidPhone(value: string) {
  return /^\+?[0-9]{6,20}$/.test(value.trim())
}

export function SmsManagementPanel() {
  const [settings, setSettings] = useState<SmsServiceSettings | null>(null)
  const [testPhone, setTestPhone] = useState('')
  const [loading, setLoading] = useState(false)
  const [submitting, setSubmitting] = useState(false)
  const [testing, setTesting] = useState(false)
  const [unavailable, setUnavailable] = useState(false)
  const [form] = Form.useForm<SmsFormValues>()
  const [messageApi, contextHolder] = message.useMessage()

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const value = await getSmsServiceSettings()
      setSettings(value)
      setUnavailable(false)
      form.setFieldsValue(toFormValues(value))
    } catch (error) {
      if (error instanceof ApiError && error.status === 404) {
        setUnavailable(true)
      } else {
        messageApi.error(error instanceof ApiError ? error.message : '短信服务配置加载失败。')
      }
    } finally {
      setLoading(false)
    }
  }, [form, messageApi])

  useEffect(() => { void load() }, [load])

  const buildInput = (values: SmsFormValues): SmsServiceSettingsInput => {
    const input: SmsServiceSettingsInput = {
      reason: values.reason.trim(),
      driver: values.driver,
      region: nullable(values.region),
      endpoint: nullable(values.endpoint),
      sign_name: nullable(values.sign_name),
      template_code: nullable(values.template_code),
      verification_variables: parseVariables(values.verification_variables),
      timeout_seconds: Number(values.timeout_seconds),
      ttl_seconds: Number(values.ttl_seconds),
      resend_interval_seconds: Number(values.resend_interval_seconds),
      hourly_limit: Number(values.hourly_limit),
      daily_limit: Number(values.daily_limit),
      max_attempts: Number(values.max_attempts),
    }
    if (values.access_key_id.trim()) input.access_key_id = values.access_key_id.trim()
    if (values.access_key_secret.trim()) input.access_key_secret = values.access_key_secret.trim()
    if (values.security_token.trim()) input.security_token = values.security_token.trim()
    if (values.clear_access_key_id) input.clear_access_key_id = true
    if (values.clear_access_key_secret) input.clear_access_key_secret = true
    if (values.clear_security_token) input.clear_security_token = true
    return input
  }

  const save = async (values: SmsFormValues) => {
    const conflicts = secretFields.filter(({ key, formName }) => String(values[formName] ?? '').trim() && Boolean(values[`clear_${key}` as keyof SmsFormValues]))
    if (conflicts.length > 0) {
      messageApi.error(`${conflicts.map(({ label }) => label).join('、')}不能同时输入新值并勾选清除。`)
      return
    }
    try {
      setSubmitting(true)
      await verifyAdminRecent(values.current_password, values.mfa_code?.trim() || undefined)
      const updated = await saveSmsServiceSettings(buildInput(values))
      setSettings(updated)
      form.setFieldsValue({ ...toFormValues(updated), reason: '', current_password: '', mfa_code: '' })
      messageApi.success('短信服务配置已保存；密钥只提交本次输入且不会回显。')
    } catch (error) {
      messageApi.error(error instanceof ApiError ? error.message : '短信服务配置保存失败。')
    } finally {
      setSubmitting(false)
    }
  }

  const sendTest = async () => {
    if (!isValidPhone(testPhone)) {
      messageApi.warning('请输入有效的测试手机号。')
      return
    }
    const values = form.getFieldsValue()
    if (!values.current_password?.trim()) {
      messageApi.warning('发送测试短信前，请填写当前管理员密码并完成身份验证。')
      return
    }
    try {
      setTesting(true)
      await verifyAdminRecent(values.current_password, values.mfa_code?.trim() || undefined)
      const result = await sendSmsServiceTest(testPhone.trim())
      setSettings((current) => current ? {
        ...current,
        last_tested_at: result.sent_at ?? new Date().toISOString(),
        last_test_status: result.accepted ? 'success' : 'failed',
        last_test_message: result.message,
      } : current)
      if (result.accepted) messageApi.success(result.message || '测试短信已受理。')
      else messageApi.error(result.message || '测试短信未受理。')
    } catch (error) {
      messageApi.error(error instanceof ApiError ? error.message : '测试短信发送失败。')
    } finally {
      setTesting(false)
    }
  }

  return <Card
    bordered={false}
    className="sms-settings-card"
    title={<Space><ApiOutlined />短信服务</Space>}
    extra={<Button icon={<ReloadOutlined />} loading={loading} onClick={() => void load()}>刷新状态</Button>}
  >
    {contextHolder}
    {unavailable ? <Alert
      showIcon
      type="warning"
      message="短信配置服务暂不可用"
      description="请确认服务端已完成数据库迁移，并检查当前管理员是否拥有超级管理员和配置管理权限。"
    /> : <>
      <Alert
        showIcon
        type="warning"
        icon={<SafetyCertificateOutlined />}
        message="密钥安全提醒"
        description="AccessKey、Secret 和 SecurityToken 只在保存时提交新输入，服务端不应回传原值。留空表示保留原密钥；勾选清除后才会删除已保存的密钥。修改与发送测试短信均需要再次验证管理员身份。"
        style={{ marginBottom: 16 }}
      />
      {settings && <div className="sms-settings-summary">
        <Space wrap>
          <Tag color={settings.driver === 'aliyun' ? 'blue' : settings.driver === 'webhook' ? 'cyan' : 'default'}>{settings.driver === 'aliyun' ? '阿里云短信' : settings.driver === 'webhook' ? '短信网关 Webhook' : '本地日志（仅开发）'}</Tag>
          <Tag color={settings.last_test_status === 'success' ? 'success' : settings.last_test_status === 'failed' ? 'error' : 'default'}>{settings.last_test_status === 'success' ? '最近测试通过' : settings.last_test_status === 'failed' ? '最近测试失败' : '尚未测试'}</Tag>
          <Typography.Text type="secondary">最近测试：{formatDate(settings.last_tested_at)}</Typography.Text>
        </Space>
        {settings.last_test_message && <Typography.Text type="secondary" className="sms-settings-summary-message">{settings.last_test_message}</Typography.Text>}
      </div>}
      <Form form={form} layout="vertical" initialValues={defaultValues} onFinish={(values) => void save(values)}>
        <div className="sms-settings-grid">
          <Card size="small" title="供应商与凭据">
            <Form.Item name="driver" label="短信驱动" rules={[{ required: true, message: '请选择短信驱动。' }]}>
              <Select options={[
                { value: 'aliyun', label: '阿里云短信（推荐）' },
                { value: 'webhook', label: '短信网关 Webhook' },
                { value: 'log', label: '本地日志（仅开发/测试）' },
              ]} />
            </Form.Item>
            <Typography.Paragraph type="secondary" className="sms-settings-help">生产环境建议使用阿里云短信官方服务或受控的内部 Webhook 适配器。不要在用户端或浏览器中配置供应商密钥。</Typography.Paragraph>
            {settings && secretFields.map(({ key, label, formName }) => {
              const state = settings[key]
              return <div className="sms-secret-row" key={key}>
                <Form.Item name={formName} label={<Space>{label}<Tag color={state.configured ? 'success' : 'default'}>{secretStateLabel(state)}</Tag></Space>}>
                  <Input.Password autoComplete="new-password" placeholder={state.configured ? '留空表示不修改' : '仅在保存时输入'} />
                </Form.Item>
                {state.configured && <Form.Item name={`clear_${key}`} valuePropName="checked" className="sms-clear-item"><Checkbox>清除已保存值</Checkbox></Form.Item>}
              </div>
            })}
          </Card>
          <Card size="small" title="阿里云短信参数">
            <div className="sms-two-column">
              <Form.Item name="region" label="Region" extra="例如 cn-hangzhou；使用阿里云默认区域时按服务端约定填写。"><Input placeholder="cn-hangzhou" /></Form.Item>
              <Form.Item name="endpoint" label="Endpoint" extra="可留空使用 SDK 默认地址；自定义网关时填写 HTTPS 地址。"><Input placeholder="dysmsapi.aliyuncs.com" /></Form.Item>
            </div>
            <div className="sms-two-column">
              <Form.Item name="sign_name" label="短信签名" rules={[{ required: true, message: '请输入短信签名。' }]}><Input maxLength={64} placeholder="任务商城" /></Form.Item>
              <Form.Item name="template_code" label="验证码模板 Code" rules={[{ required: true, message: '请输入验证码模板 Code。' }]}><Input maxLength={128} placeholder="SMS_123456789" /></Form.Item>
            </div>
            <Form.Item name="verification_variables" label="验证码变量" extra="按逗号分隔，例如 code, minutes；只保存变量名，不保存验证码值。" rules={[{ required: true, message: '至少填写一个验证码变量。' }]}>
              <Input placeholder="code, minutes" />
            </Form.Item>
          </Card>
          <Card size="small" title="验证码策略">
            <div className="sms-two-column">
              <Form.Item name="timeout_seconds" label="请求超时" rules={[{ required: true, type: 'number', min: 1, max: 30, message: '请输入 1–30 秒。' }]}><InputNumber min={1} max={30} precision={0} addonAfter="秒" style={{ width: '100%' }} /></Form.Item>
              <Form.Item name="ttl_seconds" label="验证码有效期" rules={[{ required: true, type: 'number', min: 60, max: 900, message: '请输入 60–900 秒。' }]}><InputNumber min={60} max={900} precision={0} addonAfter="秒" style={{ width: '100%' }} /></Form.Item>
            </div>
            <div className="sms-two-column">
              <Form.Item name="resend_interval_seconds" label="重发间隔" rules={[{ required: true, type: 'number', min: 30, max: 600, message: '请输入 30–600 秒。' }]}><InputNumber min={30} max={600} precision={0} addonAfter="秒" style={{ width: '100%' }} /></Form.Item>
              <Form.Item name="max_attempts" label="最大校验尝试次数" rules={[{ required: true, type: 'number', min: 1, max: 10, message: '请输入 1–10 次。' }]}><InputNumber min={1} max={10} precision={0} addonAfter="次" style={{ width: '100%' }} /></Form.Item>
            </div>
            <div className="sms-two-column">
              <Form.Item name="hourly_limit" label="每手机号每小时上限" rules={[{ required: true, type: 'number', min: 1, max: 100, message: '请输入 1–100 次。' }]}><InputNumber min={1} max={100} precision={0} addonAfter="次" style={{ width: '100%' }} /></Form.Item>
              <Form.Item name="daily_limit" label="每手机号每日上限" rules={[{ required: true, type: 'number', min: 1, max: 1000, message: '请输入 1–1000 次。' }]}><InputNumber min={1} max={1000} precision={0} addonAfter="次" style={{ width: '100%' }} /></Form.Item>
            </div>
            <Typography.Paragraph type="secondary" className="sms-settings-help">服务端必须继续执行限流、验证码哈希存储和失败次数限制；这些配置不能只依赖前端倒计时。</Typography.Paragraph>
          </Card>
          <Card size="small" title="发送测试短信">
            <Typography.Paragraph type="secondary">测试会使用当前已保存的配置向指定手机号发送真实短信，可能产生费用；修改后的新密钥需先保存再测试。</Typography.Paragraph>
            <Space.Compact block>
              <Input value={testPhone} onChange={(event) => setTestPhone(event.target.value)} placeholder="测试手机号，例如 13800138000" maxLength={20} inputMode="tel" />
              <Button type="primary" icon={<SendOutlined />} loading={testing} onClick={() => void sendTest()}>发送测试短信</Button>
            </Space.Compact>
          </Card>
        </div>
        <Card size="small" title="保存前身份验证" className="sms-verification-card">
          <Typography.Paragraph type="secondary">配置凭据、清除密钥和发送测试短信都属于高风险操作。</Typography.Paragraph>
          <Form.Item name="reason" label="修改原因" rules={[{ required: true, min: 4, max: 500, message: '请填写至少 4 个字的修改原因。' }]}>
            <Input.TextArea rows={2} showCount maxLength={500} placeholder="例如：启用正式环境阿里云短信验证码服务" />
          </Form.Item>
          <div className="sms-two-column">
            <Form.Item name="current_password" label="当前管理员密码" rules={[{ required: true, message: '请输入当前管理员密码。' }]}><Input.Password autoComplete="current-password" /></Form.Item>
            <Form.Item name="mfa_code" label="MFA 验证码（已开启时必填）"><Input inputMode="numeric" maxLength={6} /></Form.Item>
          </div>
          <Button type="primary" htmlType="submit" loading={submitting} icon={<SafetyCertificateOutlined />}>保存短信服务配置</Button>
        </Card>
      </Form>
    </>}
  </Card>
}
