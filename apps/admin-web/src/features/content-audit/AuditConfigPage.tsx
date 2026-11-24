import { ApiOutlined, ReloadOutlined, SaveOutlined } from '@ant-design/icons'
import { Alert, Button, Checkbox, Col, Form, Input, Row, Select, Space, Spin, Switch, Tag, Typography, message } from 'antd'
import { useCallback, useEffect, useState } from 'react'
import {
  getCommunityContentSecuritySettings,
  saveCommunityContentSecuritySettings,
  testCommunityContentSecuritySettings,
  type CommunityContentSecurityProvider,
  type CommunityContentSecuritySettings,
  type CommunityContentSecuritySettingsInput,
} from '../../api/admin'
import { PageHead, Pill } from '../../components/xq'
import { errorText, formatTime, securityProviderLabels } from './shared'

interface AuditConfigValues {
  enabled: boolean
  provider: CommunityContentSecurityProvider
  access_key_id: string
  access_key_secret: string
  clear_credentials: boolean
  region: string
  endpoint: string
  service: string
  reason: string
}

const defaultValues: AuditConfigValues = {
  enabled: true,
  provider: 'wechat',
  access_key_id: '',
  access_key_secret: '',
  clear_credentials: false,
  region: 'cn-shanghai',
  endpoint: 'green-cip.cn-shanghai.aliyuncs.com',
  service: 'ugc_moderation_byllm',
  reason: '',
}

const sourceLabels = { backend: '数据库加密配置', env: '环境变量兜底', none: '未配置' } as const

export function AuditConfigPage() {
  const [messageApi, contextHolder] = message.useMessage()
  const [settings, setSettings] = useState<CommunityContentSecuritySettings | null>(null)
  const [loading, setLoading] = useState(false)
  const [saving, setSaving] = useState(false)
  const [testing, setTesting] = useState(false)
  const [dirty, setDirty] = useState(false)
  const [loadError, setLoadError] = useState('')
  const [form] = Form.useForm<AuditConfigValues>()
  const provider = Form.useWatch('provider', form) ?? 'wechat'
  const clearCredentials = Form.useWatch('clear_credentials', form) ?? false

  const applySnapshot = useCallback((value: CommunityContentSecuritySettings) => {
    setSettings(value)
    form.resetFields()
    form.setFieldsValue({
      enabled: value.enabled,
      provider: value.provider,
      access_key_id: '',
      access_key_secret: '',
      clear_credentials: false,
      region: value.region || defaultValues.region,
      endpoint: value.endpoint || defaultValues.endpoint,
      service: value.service || defaultValues.service,
      reason: '',
    })
    setDirty(false)
  }, [form])

  const load = useCallback(async () => {
    setLoading(true)
    setLoadError('')
    try {
      applySnapshot(await getCommunityContentSecuritySettings())
    } catch (error) {
      const text = errorText(error, '内容安全配置加载失败，请稍后重试。')
      setLoadError(text)
      messageApi.error(text)
    } finally {
      setLoading(false)
    }
  }, [applySnapshot, messageApi])

  useEffect(() => { void load() }, [load])

  const saveConfig = async (values: AuditConfigValues) => {
    if (values.clear_credentials && (values.access_key_id.trim() || values.access_key_secret.trim())) {
      messageApi.error('不能同时填写新凭证和勾选清除已保存凭证。')
      return
    }
    const input: CommunityContentSecuritySettingsInput = {
      enabled: values.enabled,
      provider: values.provider,
      reason: values.reason.trim(),
      region: (values.region ?? settings?.region ?? defaultValues.region).trim(),
      endpoint: (values.endpoint ?? settings?.endpoint ?? defaultValues.endpoint).trim(),
      service: (values.service ?? settings?.service ?? defaultValues.service).trim(),
      clear_credentials: values.provider === 'aliyun' && values.clear_credentials,
    }
    if (values.provider === 'aliyun' && !values.clear_credentials) {
      if (values.access_key_id.trim()) input.access_key_id = values.access_key_id.trim()
      if (values.access_key_secret.trim()) input.access_key_secret = values.access_key_secret.trim()
    }

    setSaving(true)
    try {
      applySnapshot(await saveCommunityContentSecuritySettings(input))
      messageApi.success('内容安全配置已保存；凭证输入已清空，旧测试状态由后端重新计算。')
    } catch (error) {
      messageApi.error(errorText(error, '内容安全配置保存失败，请检查后重试。'))
    } finally {
      setSaving(false)
    }
  }

  const testSavedConfig = async () => {
    if (!settings) return
    if (dirty) {
      messageApi.warning('配置已修改，请先保存后再测试已保存配置。')
      return
    }
    const values = await form.validateFields(['reason'])
    setTesting(true)
    try {
      const result = await testCommunityContentSecuritySettings(values.reason.trim())
      applySnapshot(result)
      const detail = result.last_test_message || '测试已完成。'
      if (result.last_test_status === 'failed') {
        messageApi.error(detail)
      } else if (result.provider === 'wechat' || result.last_test_status === 'credential_only') {
        messageApi.info(`微信测试为 credential_only：仅验证小程序访问凭证，未执行真实用户文本检测。${detail}`)
      } else {
        messageApi.success(`阿里云 TextModerationPlus 实际检测已完成。${detail}`)
      }
    } catch (error) {
      messageApi.error(errorText(error, '配置测试失败，请检查已保存配置后重试。'))
      await load()
    } finally {
      setTesting(false)
    }
  }

  const testStatus = settings?.last_test_status
  const lastTestTag = !settings?.last_tested_at
    ? <Tag>尚未测试</Tag>
    : <Tag color={testStatus === 'failed' ? 'error' : testStatus === 'credential_only' ? 'blue' : 'success'}>
      {testStatus === 'failed' ? '最近测试失败' : testStatus === 'credential_only' ? '最近完成凭证校验' : '最近测试通过'}
    </Tag>

  return (
    <div className="xq-page">
      {contextHolder}
      <PageHead
        title="审核配置"
        extra={<Space><Pill tone={settings?.enabled ? 'green' : 'gray'}>{settings?.enabled ? '外部机审已启用' : '外部机审已关闭'}</Pill><Button icon={<ReloadOutlined />} loading={loading} onClick={() => void load()}>刷新</Button></Space>}
      />

      {loadError && <Alert type="error" showIcon message={loadError} action={<Button size="small" onClick={() => void load()}>重试</Button>} style={{ marginBottom: 16 }} />}

      <Spin spinning={loading && !settings}>
        <Form<AuditConfigValues>
          form={form}
          layout="vertical"
          initialValues={defaultValues}
          onFinish={(values) => void saveConfig(values)}
          onValuesChange={(changed) => {
            if (Object.keys(changed).some((key) => key !== 'reason')) setDirty(true)
          }}
        >
          <div className="xq-card" style={{ marginBottom: 16 }}>
            <p className="xq-card-title">内容安全服务</p>
            <Alert
              type="info"
              showIcon
              message="启用开关仅控制微信或阿里云外部机审"
              description="关闭外部机审不会关闭人工审核、本地敏感词或既有发布状态机；同一时刻仅一个服务商生效，厂商异常按既有规则降级跳过。"
              style={{ marginBottom: 20 }}
            />
            <Row gutter={[24, 0]}>
              <Col xs={24} md={12}>
                <Form.Item name="enabled" label="启用外部内容机审" valuePropName="checked" extra="默认使用微信；关闭后机审记录会标记 skipped/disabled。">
                  <Switch checkedChildren="开启" unCheckedChildren="关闭" />
                </Form.Item>
              </Col>
              <Col xs={24} md={12}>
                <Form.Item name="provider" label="审核服务商" rules={[{ required: true, message: '请选择审核服务商' }]}>
                  <Select options={[
                    { value: 'wechat', label: '微信内容安全（默认）' },
                    { value: 'aliyun', label: '阿里云内容安全' },
                  ]} />
                </Form.Item>
              </Col>
            </Row>

            {provider === 'wechat' ? <Alert
              type="info"
              showIcon
              message="微信内容安全沿用现有小程序配置"
              description="文本检测需要发布用户具有微信 OpenID；未配置、无 OpenID 或厂商异常时按现有策略降级。此模式不需要填写阿里云 AccessKey。测试仅验证小程序 access token 获取能力，结果为 credential_only。"
            /> : <>
              <Alert
                type="info"
                showIcon
                message="阿里云 Green 2022-03-02 / TextModerationPlus"
                description="AccessKey 留空表示保留已保存值，后端只返回掩码。勾选清除才会删除数据库凭证；测试会调用已保存配置执行固定无害文本检测。"
                style={{ marginBottom: 16 }}
              />
              {settings && <div className="xq-card" style={{ marginBottom: 20, background: '#fafafa' }}>
                <Space wrap>
                  <Tag color={settings.aliyun.configured ? 'success' : 'warning'}>{settings.aliyun.configured ? '凭证已配置' : '凭证未配置'}</Tag>
                  <Tag>{sourceLabels[settings.aliyun.source]}</Tag>
                  <Typography.Text type="secondary">AccessKey ID：{settings.aliyun.access_key_id_masked || '—'}</Typography.Text>
                  <Typography.Text type="secondary">AccessKey Secret：{settings.aliyun.access_key_secret_masked || '—'}</Typography.Text>
                </Space>
              </div>}
              <Row gutter={[24, 0]}>
                <Col xs={24} md={12}>
                  <Form.Item name="access_key_id" label="AccessKey ID" extra="输入框始终留空；仅本次输入的新值会提交，掩码不会作为新凭证回传。">
                    <Input
                      placeholder={settings?.aliyun.access_key_id_masked ? `已配置 ${settings.aliyun.access_key_id_masked}，留空保持不变` : '请输入新的 AccessKey ID'}
                      autoComplete="off"
                      disabled={clearCredentials}
                    />
                  </Form.Item>
                </Col>
                <Col xs={24} md={12}>
                  <Form.Item name="access_key_secret" label="AccessKey Secret" extra="服务端加密保存，接口、审计和日志均不回显明文。">
                    <Input.Password
                      placeholder={settings?.aliyun.access_key_secret_masked ? `已配置 ${settings.aliyun.access_key_secret_masked}，留空保持不变` : '请输入新的 AccessKey Secret'}
                      autoComplete="new-password"
                      disabled={clearCredentials}
                    />
                  </Form.Item>
                </Col>
                <Col xs={24}>
                  <Form.Item name="clear_credentials" valuePropName="checked" extra="仅明确勾选时清除数据库中已保存的阿里云凭证；环境变量兜底状态仍由服务端如实展示。">
                    <Checkbox onChange={(event) => {
                      if (event.target.checked) form.setFieldsValue({ access_key_id: '', access_key_secret: '' })
                    }}>清除已保存凭证</Checkbox>
                  </Form.Item>
                </Col>
                <Col xs={24} md={8}>
                  <Form.Item name="region" label="服务地域" rules={[{ required: true, whitespace: true, message: '请输入服务地域' }]}>
                    <Input placeholder="cn-shanghai" />
                  </Form.Item>
                </Col>
                <Col xs={24} md={8}>
                  <Form.Item name="endpoint" label="API 端点" rules={[{ required: true, whitespace: true, message: '请输入 API 端点' }]}>
                    <Input placeholder="green-cip.cn-shanghai.aliyuncs.com" />
                  </Form.Item>
                </Col>
                <Col xs={24} md={8}>
                  <Form.Item name="service" label="服务码" rules={[{ required: true, whitespace: true, message: '请输入服务码' }]}>
                    <Input placeholder="ugc_moderation_byllm" />
                  </Form.Item>
                </Col>
              </Row>
            </>}
          </div>

          <div className="xq-card" style={{ marginBottom: 16 }}>
            <p className="xq-card-title">最近测试与操作审计</p>
            <Space direction="vertical" size={8} style={{ display: 'flex', marginBottom: 20 }}>
              <Space wrap>
                {lastTestTag}
                <Typography.Text type="secondary">测试时间：{formatTime(settings?.last_tested_at)}</Typography.Text>
                {settings && <Typography.Text type="secondary">已保存服务商：{securityProviderLabels[settings.provider]}</Typography.Text>}
              </Space>
              {settings?.last_test_message && <Typography.Text type="secondary">{settings.last_test_message}</Typography.Text>}
              {settings?.provider === 'wechat' && settings.last_tested_at && <Typography.Text type="secondary">微信结果为 credential_only，不代表已完成真实用户文本检测。</Typography.Text>}
            </Space>
            <Form.Item name="reason" label="操作原因（保存与测试均必填）" rules={[{ required: true, whitespace: true, message: '请填写操作原因' }, { min: 4, message: '操作原因至少 4 个字' }, { max: 500, message: '操作原因不能超过 500 字' }]}>
              <Input.TextArea rows={3} maxLength={500} showCount placeholder="说明本次配置变更或测试目的，将写入操作审计" />
            </Form.Item>
            <Space wrap>
              <Button type="primary" htmlType="submit" icon={<SaveOutlined />} loading={saving} disabled={testing}>保存配置</Button>
              <Button icon={<ApiOutlined />} loading={testing} disabled={!settings || dirty || saving || loading} onClick={() => void testSavedConfig()}>测试已保存配置</Button>
              {dirty && <Typography.Text type="warning">配置已修改，请先保存再测试。</Typography.Text>}
            </Space>
          </div>
        </Form>
      </Spin>
    </div>
  )
}
