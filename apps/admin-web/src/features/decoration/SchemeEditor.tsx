import { ArrowLeftOutlined, CheckCircleOutlined, SaveOutlined } from '@ant-design/icons'
import { Alert, Button, Card, Empty, Input, Space, Tabs, Tag, Typography, message } from 'antd'
import { useState } from 'react'
import {
  setDecorationSchemeChannel,
  updateDecorationScheme,
  type DecorationPageConfig,
  type DecorationScheme,
  type DecorationSection,
} from '../../api/admin'
import { MediaGalleryInput } from '../../components/MediaGalleryInput'
import { useAdminAuth } from '../auth/AdminAuthContext'
import { useRobotRecentVerification } from '../robot/useRobotRecentVerification'
import { SectionCanvas } from './SectionCanvas'
import { SectionPropertyPanel } from './SectionPropertyPanel'
import { DECORATION_GROUPS, DECORATION_SECTION_DEFS, createDecorationSection } from './sectionRegistry'

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
  const [rightTab, setRightTab] = useState('page')
  const [saving, setSaving] = useState(false)
  const [activating, setActivating] = useState(false)
  const { runWrite, verificationContext } = useRobotRecentVerification(
    admin?.requires_mfa ?? false,
    '该操作会保存或启用页面方案，请输入当前管理员密码以继续。',
  )

  const selectedSection = page.sections.find((section) => section.id === selectedId) ?? null

  const updatePage = (patch: Partial<DecorationPageConfig>) => setPage((current) => ({ ...current, ...patch }))
  const updateSections = (sections: DecorationSection[]) => updatePage({ sections })
  const updateSection = (section: DecorationSection) => updateSections(page.sections.map((item) => item.id === section.id ? section : item))

  const addSection = (type: string) => {
    const section = createDecorationSection(type)
    updateSections([...page.sections, section])
    setSelectedId(section.id)
    setRightTab('props')
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

    <div style={{ display: 'grid', gridTemplateColumns: '220px minmax(300px, 1fr) 380px', gap: 16 }}>
      <Card size="small" title="组件库">
        <Space direction="vertical" style={{ width: '100%' }} size="small">
          {DECORATION_GROUPS.map((group) => <div key={group.key}>
            <Typography.Text type="secondary" style={{ display: 'block', fontSize: 11, fontWeight: 700, margin: '2px 0 6px' }}>{group.label}</Typography.Text>
            <Space direction="vertical" style={{ width: '100%' }} size={6}>
              {DECORATION_SECTION_DEFS.filter((definition) => definition.group === group.key).map((definition) => <Button
                key={definition.type}
                block
                style={{ textAlign: 'left', height: 'auto', padding: '8px 10px' }}
                onClick={() => addSection(definition.type)}
              >
                <div style={{ display: 'flex', flexDirection: 'column', alignItems: 'flex-start', gap: 2 }}>
                  <span style={{ fontWeight: 600 }}>{definition.label}</span>
                  <span style={{ fontSize: 12, color: '#7A859B', whiteSpace: 'normal' }}>{definition.description}</span>
                </div>
              </Button>)}
            </Space>
          </div>)}
        </Space>
      </Card>

      <Card size="small" title={`页面画布 · ${name || pageLabel}`} extra={<Tag>{page.sections.length} 个区块</Tag>}>
        <SectionCanvas
          sections={page.sections}
          selectedId={selectedId}
          onSelect={(id) => { setSelectedId(id); setRightTab('props') }}
          onChange={updateSections}
        />
      </Card>

      <Card size="small">
        <Tabs
          activeKey={rightTab}
          onChange={setRightTab}
          items={[
            {
              key: 'page',
              label: '页面设置',
              children: <Space direction="vertical" style={{ width: '100%' }}>
                <Typography.Text type="secondary">页面名称（内部识别）</Typography.Text>
                <Input value={page.name} maxLength={30} placeholder="如：国庆活动首页" onChange={(event) => updatePage({ name: event.target.value })} />
                <Typography.Text type="secondary">页面标题</Typography.Text>
                <Input value={page.title} maxLength={40} placeholder="展示在页面顶部的标题，可留空" onChange={(event) => updatePage({ title: event.target.value })} />
                <Typography.Text type="secondary">页面介绍</Typography.Text>
                <Input.TextArea rows={2} value={page.description} maxLength={200} placeholder="用于后台识别或活动说明，可留空" onChange={(event) => updatePage({ description: event.target.value })} />
                <Typography.Text type="secondary">页面封面</Typography.Text>
                <MediaGalleryInput
                  value={page.cover_url ? [{ url: page.cover_url, alt: '页面封面', sort_order: 0 }] : []}
                  onChange={(images) => updatePage({ cover_url: images[0]?.url ?? '' })}
                  max={1}
                  primaryLabel="页面封面"
                  helperText="用于方案列表展示，可留空。"
                />
                <Typography.Text type="secondary">页面背景色</Typography.Text>
                <Input value={page.background_color} maxLength={7} placeholder="#F5F7FB（留空为默认）" onChange={(event) => updatePage({ background_color: event.target.value })} />
                <Typography.Text type="secondary">页面背景图</Typography.Text>
                <MediaGalleryInput
                  value={page.background_url ? [{ url: page.background_url, alt: '页面背景', sort_order: 0 }] : []}
                  onChange={(images) => updatePage({ background_url: images[0]?.url ?? '' })}
                  max={1}
                  primaryLabel="页面背景"
                  helperText="设置后作为装修区域背景；页面背景色优先于默认底色。"
                />
              </Space>,
            },
            {
              key: 'props',
              label: '组件设置',
              children: selectedSection
                ? <SectionPropertyPanel section={selectedSection} onChange={updateSection} />
                : <Empty description="在画布中选择一个区块以编辑属性与样式" image={Empty.PRESENTED_IMAGE_SIMPLE} />,
            },
          ]}
        />
      </Card>
    </div>
  </>
}
