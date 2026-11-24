import { LinkOutlined, PlusOutlined, ReloadOutlined } from '@ant-design/icons'
import { Alert, Button, Form, Modal, Popconfirm, Select, Space, Table, Typography, message } from 'antd'
import type { ColumnsType } from 'antd/es/table'
import { useCallback, useEffect, useRef, useState } from 'react'
import {
  bindXpRobotAssistant,
  listXpRobotAccounts,
  listXpRobotDevices,
  unbindXpRobotAssistant,
  type XpRobotAccount,
  type XpRobotAssistant,
  type XpRobotAssistantBinding,
  type XpRobotDevice,
} from '../../api/admin'
import { formatRobotTime, robotAccountName, robotAccountStatusLabel, robotAccountTypeLabel, robotDeviceName } from './robotUtils'
import { useRobotRecentVerification } from './useRobotRecentVerification'

interface Props {
  assistant: XpRobotAssistant | null
  configured: boolean
  canManage: boolean
  requiresMfa: boolean
  onClose: () => void
  onUpdated: (assistant: XpRobotAssistant) => void
}

export function AssistantBindingModal({ assistant, configured, canManage, requiresMfa, onClose, onUpdated }: Props) {
  const [messageApi, messageContext] = message.useMessage()
  const { runWrite, verificationContext } = useRobotRecentVerification(requiresMfa)
  const [devices, setDevices] = useState<XpRobotDevice[]>([])
  const [devicesLoading, setDevicesLoading] = useState(false)
  const [deviceId, setDeviceId] = useState('')
  const [accounts, setAccounts] = useState<XpRobotAccount[]>([])
  const [accountsLoading, setAccountsLoading] = useState(false)
  const [wxid, setWxid] = useState('')
  const [binding, setBinding] = useState(false)
  const [unbindingId, setUnbindingId] = useState<string | null>(null)
  const accountRequest = useRef(0)

  const loadDevices = useCallback(async () => {
    if (!configured) {
      setDevices([])
      return
    }
    setDevicesLoading(true)
    try {
      const result = await listXpRobotDevices()
      setDevices(result.items)
    } catch (error) {
      messageApi.error(error instanceof Error ? error.message : 'XP 设备列表加载失败。')
    } finally {
      setDevicesLoading(false)
    }
  }, [configured, messageApi])

  const assistantId = assistant?.id
  useEffect(() => {
    if (!assistantId) return
    setDeviceId('')
    setWxid('')
    setAccounts([])
    void loadDevices()
  }, [assistantId, loadDevices])

  const selectDevice = async (value: string) => {
    const requestId = ++accountRequest.current
    setDeviceId(value)
    setWxid('')
    setAccounts([])
    if (!value) return
    setAccountsLoading(true)
    try {
      const result = await listXpRobotAccounts(value)
      if (accountRequest.current === requestId) setAccounts(result.items)
    } catch (error) {
      if (accountRequest.current === requestId) messageApi.error(error instanceof Error ? error.message : '微信账号列表加载失败。')
    } finally {
      if (accountRequest.current === requestId) setAccountsLoading(false)
    }
  }

  const bind = async () => {
    if (!assistant || !deviceId || !wxid) {
      messageApi.warning('请选择设备和微信账号。')
      return
    }
    const device = devices.find((item) => item.device_id === deviceId)
    const account = accounts.find((item) => item.wxid === wxid)
    setBinding(true)
    try {
      const updated = await runWrite(() => bindXpRobotAssistant(assistant.id, {
        device_id: deviceId,
        device_name: device ? robotDeviceName(device) : deviceId,
        wxid,
        account_name: account ? robotAccountName(account) : wxid,
      }), '机器人绑定失败。')
      if (!updated) return
      onUpdated(updated)
      setDeviceId('')
      setWxid('')
      setAccounts([])
      messageApi.success('机器人绑定成功。')
    } finally {
      setBinding(false)
    }
  }

  const unbind = async (record: XpRobotAssistantBinding) => {
    if (!assistant) return
    setUnbindingId(record.binding_id)
    try {
      const updated = await runWrite(() => unbindXpRobotAssistant(assistant.id, record.binding_id), '机器人解绑失败。')
      if (!updated) return
      onUpdated(updated)
      messageApi.success('机器人已解绑。')
    } finally {
      setUnbindingId(null)
    }
  }

  const columns: ColumnsType<XpRobotAssistantBinding> = [
    {
      title: '设备', width: 260,
      render: (_, record) => <div>
        <Typography.Text strong>{record.device_name || record.device_id}</Typography.Text>
        <Typography.Text type="secondary" copyable={{ text: record.device_id }} style={{ display: 'block', marginTop: 4, fontSize: 12 }}>{record.device_id}</Typography.Text>
      </div>,
    },
    {
      title: '微信账号', width: 260,
      render: (_, record) => <div>
        <Typography.Text>{record.account_name || record.wxid}</Typography.Text>
        <Typography.Text type="secondary" copyable={{ text: record.wxid }} style={{ display: 'block', marginTop: 4, fontSize: 12 }}>{record.wxid}</Typography.Text>
      </div>,
    },
    { title: '绑定时间', width: 180, render: (_, record) => formatRobotTime(record.bound_at) },
    {
      title: '操作', width: 100, fixed: 'right',
      render: (_, record) => <Popconfirm title="解绑该机器人？" description="只解除本地助手绑定，不修改 XP 设备。" okText="解绑" cancelText="取消" onConfirm={() => void unbind(record)}>
        <Button type="link" danger size="small" disabled={!canManage} loading={unbindingId === record.binding_id}>解绑</Button>
      </Popconfirm>,
    },
  ]

  return <>
    {messageContext}
    {verificationContext}
    <Modal
      title={assistant ? <Space><LinkOutlined />机器人绑定：{assistant.name}</Space> : '机器人绑定'}
      open={!!assistant}
      onCancel={onClose}
      footer={<Button onClick={onClose}>关闭</Button>}
      width={940}
    >
      <Alert showIcon type="info" message={`按“设备 + 微信账号”去重绑定。当前已绑定 ${assistant?.robot_count ?? 0} 个机器人。`} style={{ marginBottom: 16 }} />
      {!configured ? <Alert showIcon type="warning" message="XP API Key 尚未配置，暂时不能选择新设备；已有绑定仍可查看或解绑。" style={{ marginBottom: 16 }} /> : null}
      <Form layout="vertical" style={{ padding: 16, border: '1px solid #f0f0f0', borderRadius: 8, marginBottom: 18 }}>
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(240px, 1fr))', gap: 14 }}>
          <Form.Item label="XP 设备" required style={{ marginBottom: 0 }}>
            <Select
              showSearch
              optionFilterProp="label"
              value={deviceId || undefined}
              loading={devicesLoading}
              disabled={!configured || !canManage}
              placeholder="选择设备"
              options={devices.map((item) => ({ value: item.device_id, label: `${robotDeviceName(item)} · ${item.online ? '在线' : '离线'}` }))}
              onChange={(value) => void selectDevice(value)}
            />
          </Form.Item>
          <Form.Item label="微信账号" required style={{ marginBottom: 0 }}>
            <Select
              showSearch
              optionFilterProp="label"
              value={wxid || undefined}
              loading={accountsLoading}
              disabled={!deviceId || !canManage}
              placeholder="选择账号"
              options={accounts.map((item) => ({ value: item.wxid, label: `${robotAccountName(item)} · ${robotAccountTypeLabel(item.account_type)}${item.status ? ` · ${robotAccountStatusLabel(item.status)}` : ''}` }))}
              onChange={setWxid}
            />
          </Form.Item>
        </div>
        <Space style={{ marginTop: 16 }}>
          <Button type="primary" icon={<PlusOutlined />} loading={binding} disabled={!canManage || !deviceId || !wxid} onClick={() => void bind()}>绑定机器人</Button>
          <Button icon={<ReloadOutlined />} loading={devicesLoading} disabled={!configured} onClick={() => void loadDevices()}>刷新设备</Button>
        </Space>
      </Form>
      <Table<XpRobotAssistantBinding>
        rowKey="binding_id"
        size="small"
        columns={columns}
        dataSource={assistant?.robot_bindings ?? []}
        scroll={{ x: 800 }}
        pagination={false}
        locale={{ emptyText: '尚未绑定机器人' }}
      />
    </Modal>
  </>
}
