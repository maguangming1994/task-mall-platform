import { Empty, Tabs, Tag, Typography } from 'antd'
import { useState } from 'react'
import type { ReactNode } from 'react'
import type { DecorationPageConfig, DecorationSection } from '../../api/admin'
import { ComponentLibraryPanel } from './ComponentLibraryPanel'
import { PageSettingsPanel } from './PageSettingsPanel'
import { SectionCanvas } from './SectionCanvas'
import { SectionPropertyPanel } from './SectionPropertyPanel'

interface Props {
  page: DecorationPageConfig
  pageLabel: string
  pageHint?: string
  selectedId: string | null
  onSelect: (id: string) => void
  onChangeSections: (sections: DecorationSection[]) => void
  onChangePage: (patch: Partial<DecorationPageConfig>) => void
  onAdd: (type: string) => void
  /** 画布顶部左侧的页面切换等控件。 */
  headerLeft?: ReactNode
  coverHint?: string
  backgroundHint?: string
}

export function DecorationWorkbench({
  page,
  pageLabel,
  pageHint,
  selectedId,
  onSelect,
  onChangeSections,
  onChangePage,
  onAdd,
  headerLeft,
  coverHint,
  backgroundHint,
}: Props) {
  const [draggingType, setDraggingType] = useState<string | null>(null)
  const [rightTab, setRightTab] = useState('page')

  const selectedSection = page.sections.find((section) => section.id === selectedId) ?? null

  const select = (id: string) => {
    onSelect(id)
    setRightTab('props')
  }

  const add = (type: string) => {
    onAdd(type)
    setRightTab('props')
  }

  return <div style={{
    display: 'grid',
    gridTemplateColumns: '252px minmax(440px, 1fr) 380px',
    height: 'max(620px, calc(100vh - 268px))',
    background: '#fff',
    border: '1px solid #E6EAF2',
    borderRadius: 14,
    overflowX: 'auto',
    overflowY: 'hidden',
  }}>
    <aside style={{ borderRight: '1px solid #EEF1F6', minHeight: 0 }}>
      <ComponentLibraryPanel onAdd={add} onDragTypeChange={setDraggingType} />
    </aside>

    <main style={{ display: 'flex', flexDirection: 'column', minWidth: 0, minHeight: 0, background: '#F3F5F9' }}>
      <div style={{
        flex: '0 0 auto',
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'space-between',
        gap: 12,
        padding: '8px 14px',
        borderBottom: '1px solid #E6EAF2',
        background: '#fff',
      }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 8, minWidth: 0 }}>
          {headerLeft}
          {pageHint ? <Typography.Text type="secondary" style={{ fontSize: 11, whiteSpace: 'nowrap' }}>{pageHint}</Typography.Text> : null}
        </div>
        <div style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
          <Tag style={{ marginInlineEnd: 0 }}>{page.sections.length} 个区块</Tag>
          <Tag color="blue" style={{ marginInlineEnd: 0 }}>{pageLabel}</Tag>
        </div>
      </div>
      <div style={{ flex: 1, minHeight: 0, overflow: 'auto', padding: '16px 12px 20px' }}>
        <SectionCanvas
          sections={page.sections}
          selectedId={selectedId}
          onSelect={select}
          onChange={onChangeSections}
          background={{ color: page.background_color, url: page.background_url }}
          draggingType={draggingType}
          onDragEnd={() => setDraggingType(null)}
          onOpenSettings={() => setRightTab('props')}
        />
      </div>
    </main>

    <aside style={{ borderLeft: '1px solid #EEF1F6', minHeight: 0, display: 'flex', flexDirection: 'column' }}>
      <Tabs
        activeKey={rightTab}
        onChange={setRightTab}
        size="small"
        tabBarStyle={{ margin: 0, padding: '4px 12px 0' }}
        style={{ flex: 1, minHeight: 0 }}
        items={[
          {
            key: 'page',
            label: '页面设置',
            children: <div style={{ padding: '10px 14px 20px', overflow: 'auto', maxHeight: 'calc(max(620px, 100vh - 268px) - 92px)' }}>
              <PageSettingsPanel page={page} onChange={onChangePage} coverHint={coverHint} backgroundHint={backgroundHint} />
            </div>,
          },
          {
            key: 'props',
            label: '组件设置',
            children: <div style={{ padding: '10px 14px 20px', overflow: 'auto', maxHeight: 'calc(max(620px, 100vh - 268px) - 92px)' }}>
              {selectedSection
                ? <SectionPropertyPanel section={selectedSection} onChange={(section) => onChangeSections(page.sections.map((item) => item.id === section.id ? section : item))} />
                : <Empty style={{ marginTop: 40 }} description="在中间画布点击区块后编辑属性" image={Empty.PRESENTED_IMAGE_SIMPLE} />}
            </div>,
          },
        ]}
      />
    </aside>
  </div>
}
