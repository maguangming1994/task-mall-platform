import { ApiOutlined, ReloadOutlined, SafetyCertificateOutlined } from '@ant-design/icons'
import { Alert, Button, Descriptions, Modal, Space, Typography, message } from 'antd'
import { useCallback, useEffect, useState } from 'react'
import { getXpRobotAssistantWebhook, testXpRobotAssistantWebhook, type XpRobotAssistant, type XpRobotAssistantWebhook } from '../../api/admin'
import { Pill } from '../../components/xq'
import { formatRobotTime } from './robotUtils'
import { useRobotRecentVerification } from './useRobotRecentVerification'

interface Props {
  assistant: XpRobotAssistant | null
  canManage: boolean
  requiresMfa: boolean
  onClose: () => void
}

export function AssistantWebhookModal({ assistant, canManage, requiresMfa, onClose }: Props) {
  const [messageApi, messageContext] = message.useMessage()
  const { runWrite, verificationContext } = useRobotRecentVerification(requiresMfa)
  const [webhook, setWebhook] = useState<XpRobotAssistantWebhook | null>(null)
  const [loading, setLoading] = useState(false)
  const [testing, setTesting] = useState(false)

  const load = useCallback(async () => {
    if (!assistant) return
    setLoading(true)
    try {
      setWebhook(await getXpRobotAssistantWebhook(assistant.id))
    } catch (error) {
      messageApi.error(error instanceof Error ? error.message : 'Webhook 配置加载失败。')
    } finally {
      setLoading(false)
    }
  }, [assistant, messageApi])

  useEffect(() => {
    setWebhook(null)
    if (assistant) void load()
  }, [assistant, load])

  const selfTest = async () => {
    if (!assistant) return
    setTesting(true)
    try {
      const result = await runWrite(() => testXpRobotAssistantWebhook(assistant.id), 'Webhook 本地自检失败。')
      if (!result) return
      setWebhook(result)
      if (result.last_test_status === 'LOCAL_READY') messageApi.success('本地配置、凭证和机器人绑定检查通过；未请求外部平台。')
      else messageApi.warning(result.last_test_message || '本地自检未通过。')
    } finally {
      setTesting(false)
    }
  }

  return <>
    {messageContext}
    {verificationContext}
    <Modal
      title={assistant ? <Space><ApiOutlined />Webhook 配置：{assistant.name}</Space> : 'Webhook 配置'}
      open={!!assistant}
      onCancel={onClose}
      width={760}
      footer={<Space>
        <Button icon={<ReloadOutlined />} loading={loading} onClick={() => void load()}>刷新</Button>
        <Button type="primary" icon={<SafetyCertificateOutlined />} loading={testing} disabled={!canManage || loading} onClick={() => void selfTest()}>执行本地自检</Button>
        <Button onClick={onClose}>关闭</Button>
      </Space>}
    >
      <Alert showIcon type="warning" message="预留回调地址" description={webhook?.notice ?? '本地自检不请求外部平台，也不代表公网回调可达。'} style={{ marginBottom: 18 }} />
      <Descriptions
        bordered
        column={1}
        size="small"
        items={[
          { key: 'url', label: '预留 Webhook URL', children: webhook?.url ? <Typography.Text copyable={{ text: webhook.url }} style={{ wordBreak: 'break-all' }}>{webhook.url}</Typography.Text> : '加载中' },
          { key: 'app', label: '应用 ID', children: webhook?.app_id ? <Typography.Text copyable={{ text: webhook.app_id }}>{webhook.app_id}</Typography.Text> : '—' },
          { key: 'bindings', label: '已绑定机器人', children: `${webhook?.binding_count ?? 0} 个` },
          {
            key: 'status', label: '最近本地自检', children: webhook?.last_test_status
              ? <Pill tone={webhook.last_test_status === 'LOCAL_READY' ? 'green' : 'red'}>{webhook.last_test_status === 'LOCAL_READY' ? '本地配置就绪' : '本地检查未通过'}</Pill>
              : '尚未自检',
          },
          { key: 'time', label: '自检时间', children: formatRobotTime(webhook?.last_tested_at) },
          { key: 'message', label: '自检说明', children: webhook?.last_test_message || '—' },
        ]}
      />
      <Typography.Paragraph type="secondary" style={{ marginTop: 16, marginBottom: 0 }}>
        自检仅检查助手启用状态、本地加密凭证是否可解密及机器人绑定数量，不发送任何外部 HTTP 请求。
      </Typography.Paragraph>
    </Modal>
  </>
}
