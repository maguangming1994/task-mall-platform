import { Alert, Button, Card, Input, Modal, Popconfirm, Space, Table, Typography, message } from 'antd'
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
import { useAdminAuth } from '../auth/AdminAuthContext'
import { useRobotRecentVerification } from '../robot/useRobotRecentVerification'
import { DecorationWorkbench } from './DecorationWorkbench'
import { DECORATION_PAGE_TABS, createDecorationSection } from './sectionRegistry'

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
  const canPublish = Boolean(overview?.draft)

  const updatePage = (patch: Partial<DecorationPageConfig>) => {
    if (!pages) return
    setPages({ ...pages, [activePage]: { ...pages[activePage], ...patch } })
  }

  const updateSections = (sections: DecorationSection[]) => updatePage({ sections })

  const addSection = (type: string) => {
    if (!activeConfig) return
    const section = createDecorationSection(type)
    updateSections([...activeConfig.sections, section])
    setSelectedId(section.id)
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
          左侧拖拽组件进手机画布，画布内拖动可调整顺序；草稿与线上隔离，发布后用户端自动生效。使用「页面方案」时，启用公众号渠道的方案会覆盖这里的线上内容。
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

    {loading && !activeConfig
      ? <Card bordered={false} loading />
      : activeConfig ? <DecorationWorkbench
        page={activeConfig}
        pageLabel={activeTab.label}
        pageHint={activeTab.hint}
        selectedId={selectedId}
        onSelect={setSelectedId}
        onChangeSections={updateSections}
        onChangePage={updatePage}
        onAdd={addSection}
        coverHint="用于后台页面列表展示，可留空。"
        backgroundHint="设置后作为页面背景；背景色会铺在背景图下方。"
        headerLeft={<div style={{ display: 'flex', gap: 4 }}>
          {DECORATION_PAGE_TABS.map((tab) => {
            const active = tab.key === activePage
            return <button
              key={tab.key}
              type="button"
              onClick={() => { setActivePage(tab.key); setSelectedId(null) }}
              style={{
                height: 28,
                padding: '0 12px',
                borderRadius: 7,
                fontSize: 12,
                cursor: 'pointer',
                border: `1px solid ${active ? '#2F67DF' : '#E6EAF2'}`,
                background: active ? '#2F67DF' : '#fff',
                color: active ? '#fff' : '#4A5670',
                fontWeight: active ? 600 : 400,
              }}
            >{tab.label}</button>
          })}
        </div>}
      /> : null}

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
