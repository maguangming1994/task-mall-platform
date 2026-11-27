import { Alert, Button, Card, Empty, Input, Modal, Popconfirm, Space, Table, Tabs, Tag, Typography, message } from 'antd'
import { useCallback, useEffect, useState } from 'react'
import {
  ApiError,
  getPageDecoration,
  listPageDecorationVersions,
  publishPageDecoration,
  rollbackPageDecoration,
  savePageDecorationDraft,
  type DecorationPageConfig,
  type DecorationPageKey,
  type DecorationSection,
  type PageDecorationConfig,
  type PageDecorationOverview,
  type SettingVersion,
} from '../../api/admin'
import { MediaGalleryInput } from '../../components/MediaGalleryInput'
import { useAdminAuth } from '../auth/AdminAuthContext'
import { useRobotRecentVerification } from '../robot/useRobotRecentVerification'
import { SectionCanvas } from './SectionCanvas'
import { SectionPropertyPanel } from './SectionPropertyPanel'
import { DECORATION_GROUPS, DECORATION_PAGE_TABS, DECORATION_SECTION_DEFS, createDecorationSection } from './sectionRegistry'

type PagesState = Record<DecorationPageKey, DecorationPageConfig>

const EMPTY_PAGE: Omit<DecorationPageConfig, 'sections'> = {
  name: '',
  title: '',
  description: '',
  cover_url: '',
  background_color: '',
  background_url: '',
}

function clonePages(pages: PagesState): PagesState {
  const cloned = JSON.parse(JSON.stringify(pages)) as Partial<PagesState>
  const normalized = {} as PagesState
  DECORATION_PAGE_TABS.forEach((tab) => {
    normalized[tab.key] = { ...EMPTY_PAGE, ...(cloned[tab.key] ?? {}), sections: cloned[tab.key]?.sections ?? [] }
  })
  return normalized
}

function errorMessage(error: unknown, fallback: string): string {
  return error instanceof ApiError ? error.message : fallback
}

export function DecorationEditorTab() {
  const { admin } = useAdminAuth()
  const [messageApi, contextHolder] = message.useMessage()
  const [overview, setOverview] = useState<PageDecorationOverview | null>(null)
  const [pages, setPages] = useState<PagesState | null>(null)
  const [activePage, setActivePage] = useState<DecorationPageKey>('home')
  const [selectedId, setSelectedId] = useState<string | null>(null)
  const [rightTab, setRightTab] = useState('page')
  const [reason, setReason] = useState('')
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [versionsOpen, setVersionsOpen] = useState(false)
  const [versions, setVersions] = useState<SettingVersion[]>([])
  const { runWrite, verificationContext } = useRobotRecentVerification(
    admin?.requires_mfa ?? false,
    '该操作会发布或回滚页面装修配置，请输入当前管理员密码以继续。',
  )

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const data = await getPageDecoration()
      setOverview(data)
      const draftValue = data.draft?.value as PageDecorationConfig | undefined
      const source = draftValue && draftValue.pages ? draftValue.pages : data.config.pages
      setPages(clonePages(source))
      setReason((current) => current || (data.draft?.reason ?? ''))
    } catch (error) {
      messageApi.error(errorMessage(error, '装修配置加载失败。'))
    } finally {
      setLoading(false)
    }
  }, [messageApi])

  useEffect(() => { void load() }, [load])

  const activeTab = DECORATION_PAGE_TABS.find((tab) => tab.key === activePage) ?? DECORATION_PAGE_TABS[0]
  const activeConfig = pages?.[activePage]
  const selectedSection = activeConfig?.sections.find((section) => section.id === selectedId) ?? null
  const canPublish = Boolean(overview?.draft)

  const updatePage = (patch: Partial<DecorationPageConfig>) => {
    if (!pages) return
    setPages({ ...pages, [activePage]: { ...pages[activePage], ...patch } })
  }

  const updateSections = (sections: DecorationSection[]) => updatePage({ sections })

  const updateSection = (section: DecorationSection) => {
    if (!activeConfig) return
    updateSections(activeConfig.sections.map((item) => item.id === section.id ? section : item))
  }

  const addSection = (type: string) => {
    if (!activeConfig) return
    const section = createDecorationSection(type)
    updateSections([...activeConfig.sections, section])
    setSelectedId(section.id)
    setRightTab('props')
  }

  const handleSave = async () => {
    if (!pages || !overview) return
    if (reason.trim().length < 4) {
      messageApi.warning('请填写至少 4 个字的修改说明。')
      return
    }
    setSaving(true)
    try {
      await savePageDecorationDraft(
        { schema_version: overview.config.schema_version || 1, pages, version: overview.config.version },
        reason.trim(),
        overview.draft?.lock_version,
      )
      messageApi.success('装修草稿已保存，线上页面未受影响。')
      await load()
    } catch (error) {
      messageApi.error(errorMessage(
        error,
        error instanceof ApiError && error.code === 'SETTING_DRAFT_CONFLICT' ? '草稿已被其他管理员更新，请刷新后重试。' : '保存装修草稿失败。',
      ))
    } finally {
      setSaving(false)
    }
  }

  const handlePublish = async () => {
    const result = await runWrite(() => publishPageDecoration())
    if (!result) return
    messageApi.success('装修配置已发布，用户端将自动刷新。')
    await load()
  }

  const openVersions = async () => {
    setVersionsOpen(true)
    try {
      setVersions((await listPageDecorationVersions()).items)
    } catch (error) {
      messageApi.error(errorMessage(error, '版本历史加载失败。'))
    }
  }

  const handleRollback = async (version: number) => {
    const result = await runWrite(() => rollbackPageDecoration(version, `回滚至已验证的 v${version} 版本。`))
    if (!result) return
    messageApi.success('回滚草稿已创建，请确认后发布。')
    setVersionsOpen(false)
    await load()
  }

  return <>
    {contextHolder}
    {verificationContext}
    <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: 12, flexWrap: 'wrap', marginBottom: 12 }}>
      <div>
        <Typography.Text strong>直接编辑线上页面</Typography.Text>
        <Typography.Paragraph type="secondary" style={{ margin: 0, maxWidth: 620 }}>
          从组件库添加区块、拖拽排序并实时预览；草稿与线上隔离，发布后用户端自动生效。使用「页面方案」时，启用公众号渠道的方案会覆盖这里的线上内容。
        </Typography.Paragraph>
      </div>
      <Space wrap>
        <Input
          value={reason}
          onChange={(event) => setReason(event.target.value)}
          placeholder="修改说明（至少 4 个字）"
          maxLength={500}
          style={{ width: 240 }}
        />
        <Button onClick={() => void openVersions()}>版本历史</Button>
        <Button loading={saving} onClick={() => void handleSave()}>保存草稿</Button>
        <Popconfirm title="发布后立即对用户端生效，确认继续？" onConfirm={() => void handlePublish()} disabled={!canPublish}>
          <Button type="primary" disabled={!canPublish}>发布</Button>
        </Popconfirm>
      </Space>
    </div>

    <Alert
      showIcon
      type="info"
      message={overview?.draft
        ? `当前有未发布草稿（锁版本 ${overview.draft.lock_version}），发布后才会替换线上页面。`
        : '当前没有草稿；保存草稿后再发布。无装修配置时用户端保持原有页面。'}
      style={{ marginBottom: 12 }}
    />

    <Card bordered={false} loading={loading}>
      <Tabs
        activeKey={activePage}
        onChange={(key) => { setActivePage(key as DecorationPageKey); setSelectedId(null); setRightTab('page') }}
        items={DECORATION_PAGE_TABS.map((tab) => ({ key: tab.key, label: tab.label }))}
      />
      <Typography.Text type="secondary">{activeTab.hint}</Typography.Text>
      <div style={{ display: 'grid', gridTemplateColumns: '220px minmax(300px, 1fr) 380px', gap: 16, marginTop: 12 }}>
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

        <Card size="small" title={`页面画布 · ${activeTab.label}`} extra={<Tag>{activeConfig?.sections.length ?? 0} 个区块</Tag>}>
          {activeConfig && <SectionCanvas
            sections={activeConfig.sections}
            selectedId={selectedId}
            onSelect={(id) => { setSelectedId(id); setRightTab('props') }}
            onChange={updateSections}
          />}
        </Card>

        <Card size="small">
          <Tabs
            activeKey={rightTab}
            onChange={setRightTab}
            items={[
              {
                key: 'page',
                label: '页面设置',
                children: activeConfig ? <Space direction="vertical" style={{ width: '100%' }}>
                  <Typography.Text type="secondary">页面名称（内部识别）</Typography.Text>
                  <Input value={activeConfig.name} maxLength={30} placeholder="如：首页装修" onChange={(event) => updatePage({ name: event.target.value })} />
                  <Typography.Text type="secondary">页面标题</Typography.Text>
                  <Input value={activeConfig.title} maxLength={40} placeholder="展示在页面顶部的标题，可留空" onChange={(event) => updatePage({ title: event.target.value })} />
                  <Typography.Text type="secondary">页面介绍</Typography.Text>
                  <Input.TextArea rows={2} value={activeConfig.description} maxLength={200} placeholder="用于后台识别或活动说明，可留空" onChange={(event) => updatePage({ description: event.target.value })} />
                  <Typography.Text type="secondary">页面封面</Typography.Text>
                  <MediaGalleryInput
                    value={activeConfig.cover_url ? [{ url: activeConfig.cover_url, alt: '页面封面', sort_order: 0 }] : []}
                    onChange={(images) => updatePage({ cover_url: images[0]?.url ?? '' })}
                    max={1}
                    primaryLabel="页面封面"
                    helperText="用于后台页面列表展示，可留空。"
                  />
                  <Typography.Text type="secondary">页面背景色</Typography.Text>
                  <Input value={activeConfig.background_color} maxLength={7} placeholder="#F5F7FB（留空为默认）" onChange={(event) => updatePage({ background_color: event.target.value })} />
                  <Typography.Text type="secondary">页面背景图</Typography.Text>
                  <MediaGalleryInput
                    value={activeConfig.background_url ? [{ url: activeConfig.background_url, alt: '页面背景', sort_order: 0 }] : []}
                    onChange={(images) => updatePage({ background_url: images[0]?.url ?? '' })}
                    max={1}
                    primaryLabel="页面背景"
                    helperText="设置后作为装修区域背景；页面背景色优先于默认底色。"
                  />
                </Space> : null,
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
    </Card>

    <Modal title="装修版本历史" open={versionsOpen} footer={null} width={760} onCancel={() => setVersionsOpen(false)}>
      <Table
        rowKey="version"
        dataSource={versions}
        pagination={false}
        columns={[
          { title: '版本', dataIndex: 'version', render: (value: number) => `v${value}` },
          { title: '来源', dataIndex: 'source', render: (value: string) => value === 'rollback' ? '回滚发布' : '正常发布' },
          { title: '原因', dataIndex: 'reason' },
          { title: '发布时间', dataIndex: 'published_at', render: (value: string) => value ? value.slice(0, 19).replace('T', ' ') : '—' },
          {
            title: '操作',
            render: (_: unknown, version: SettingVersion) => version.version !== overview?.current_version && <Popconfirm title={`创建回滚到 v${version.version} 的草稿？`} onConfirm={() => void handleRollback(version.version)}>
              <Button size="small">回滚至此</Button>
            </Popconfirm>,
          },
        ]}
      />
    </Modal>
  </>
}
