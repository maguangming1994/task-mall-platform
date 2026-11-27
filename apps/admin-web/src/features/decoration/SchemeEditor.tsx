import { ArrowLeftOutlined, CheckCircleOutlined, SaveOutlined } from '@ant-design/icons'
import { Alert, Button, Input, Space, Tag, Typography, message } from 'antd'
import { useState } from 'react'
import {
  setDecorationSchemeChannel,
  updateDecorationScheme,
  type DecorationPageConfig,
  type DecorationScheme,
  type DecorationSection,
} from '../../api/admin'
import { useAdminAuth } from '../auth/AdminAuthContext'
import { useRobotRecentVerification } from '../robot/useRobotRecentVerification'
import { DecorationWorkbench } from './DecorationWorkbench'
import { createDecorationSection } from './sectionRegistry'

interface Props {
  scheme: DecorationScheme
  pageLabel: string
  onBack: () => void
}

export function SchemeEditor({ scheme, pageLabel, onBack }: Props) {
  const { admin } = useAdminAuth()
  const [messageApi, contextHolder] = message.useMessage()
  const [name, setName] = useState(scheme.name)
  const [reason, setReason] = useState('')
  const [page, setPage] = useState<DecorationPageConfig>(scheme.page)
  const [selectedId, setSelectedId] = useState<string | null>(null)
  const [saving, setSaving] = useState(false)
  const [activating, setActivating] = useState(false)
  const { runWrite, verificationContext } = useRobotRecentVerification(
    admin?.requires_mfa ?? false,
    '该操作会保存或启用页面方案，请输入当前管理员密码以继续。',
  )

  const updatePage = (patch: Partial<DecorationPageConfig>) => setPage((current) => ({ ...current, ...patch }))
  const updateSections = (sections: DecorationSection[]) => updatePage({ sections })

  const addSection = (type: string) => {
    const section = createDecorationSection(type)
    updateSections([...page.sections, section])
    setSelectedId(section.id)
  }

  const save = async (): Promise<boolean> => {
    if (name.trim() === '') {
      messageApi.warning('请填写页面名称。')
      return false
    }
    setSaving(true)
    const result = await runWrite(() => updateDecorationScheme(scheme.id, {
      name: name.trim(),
      page,
      reason: reason.trim() || undefined,
    }))
    setSaving(false)
    if (!result) return false
    messageApi.success('方案已保存。')
    return true
  }

  const activate = async () => {
    if (!await save()) return
    setActivating(true)
    const result = await runWrite(() => setDecorationSchemeChannel(scheme.id, 'h5', true))
    setActivating(false)
    if (!result) return
    messageApi.success('已设为公众号生效，线上页面已更新。')
  }

  return <>
    {contextHolder}
    {verificationContext}
    <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: 12, flexWrap: 'wrap', marginBottom: 12 }}>
      <Space wrap>
        <Button icon={<ArrowLeftOutlined />} onClick={onBack}>返回列表</Button>
        <Tag color="blue">{pageLabel}</Tag>
        <Input value={name} onChange={(event) => setName(event.target.value)} maxLength={40} style={{ width: 240 }} placeholder="页面方案名称" />
      </Space>
      <Space wrap>
        <Input value={reason} onChange={(event) => setReason(event.target.value)} maxLength={200} placeholder="修改说明（可留空）" style={{ width: 200 }} />
        <Button icon={<SaveOutlined />} loading={saving} onClick={() => void save()}>保存方案</Button>
        <Button type="primary" icon={<CheckCircleOutlined />} loading={activating} onClick={() => void activate()}>设为公众号生效</Button>
      </Space>
    </div>

    <Alert
      showIcon
      type={scheme.derived ? 'warning' : 'info'}
      style={{ marginBottom: 12 }}
      message={scheme.derived
        ? '该方案由当前线上配置自动派生；保存后会成为独立方案，修改不会影响线上页面，直到点击「设为公众号生效」。'
        : '保存只更新方案，不会影响线上页面；点击「设为公众号生效」后才会替换该页面的线上装修。'}
    />

    <DecorationWorkbench
      page={page}
      pageLabel={pageLabel}
      selectedId={selectedId}
      onSelect={setSelectedId}
      onChangeSections={updateSections}
      onChangePage={updatePage}
      onAdd={addSection}
      coverHint="用于方案列表展示，可留空。"
      backgroundHint="设置后作为页面背景；背景色会铺在背景图下方。"
      headerLeft={<Typography.Text strong style={{ fontSize: 12 }}>{name || '未命名方案'}</Typography.Text>}
    />
  </>
}
