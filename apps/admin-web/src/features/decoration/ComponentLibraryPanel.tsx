import { DragOutlined, SearchOutlined } from '@ant-design/icons'
import { Empty, Input, Typography } from 'antd'
import { useMemo, useState } from 'react'
import { DECORATION_GROUPS, DECORATION_SECTION_DEFS } from './sectionRegistry'
import { sectionIcon } from './sectionIcons'

interface Props {
  onAdd: (type: string) => void
  /** 通知画布当前正在从组件库拖拽的组件类型，用于显示插入位置。 */
  onDragTypeChange: (type: string | null) => void
}

export function ComponentLibraryPanel({ onAdd, onDragTypeChange }: Props) {
  const [keyword, setKeyword] = useState('')
  const [hovered, setHovered] = useState<string | null>(null)

  const groups = useMemo(() => {
    const text = keyword.trim()
    return DECORATION_GROUPS.map((group) => ({
      ...group,
      items: DECORATION_SECTION_DEFS.filter((definition) => definition.group === group.key
        && (text === '' || `${definition.label}${definition.description}`.includes(text))),
    })).filter((group) => group.items.length > 0)
  }, [keyword])

  return <div style={{ display: 'flex', flexDirection: 'column', height: '100%', minHeight: 0 }}>
    <div style={{ padding: '12px 12px 8px', borderBottom: '1px solid #EEF1F6' }}>
      <Typography.Text strong style={{ fontSize: 13 }}>组件库</Typography.Text>
      <Typography.Text type="secondary" style={{ display: 'block', fontSize: 11, marginBottom: 8 }}>点击添加，或拖拽到中间画布</Typography.Text>
      <Input
        size="small"
        allowClear
        prefix={<SearchOutlined style={{ color: '#A6B0C3' }} />}
        placeholder="搜索组件"
        value={keyword}
        onChange={(event) => setKeyword(event.target.value)}
      />
    </div>
    <div style={{ flex: 1, overflow: 'auto', padding: '10px 12px 20px' }}>
      {groups.length === 0
        ? <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="没有匹配的组件" style={{ marginTop: 32 }} />
        : groups.map((group) => <div key={group.key} style={{ marginBottom: 14 }}>
          <Typography.Text style={{ display: 'block', fontSize: 11, fontWeight: 700, color: '#8B95A6', margin: '0 0 8px' }}>{group.label}</Typography.Text>
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(3, minmax(0, 1fr))', gap: 8 }}>
            {group.items.map((definition) => <button
              key={definition.type}
              type="button"
              draggable
              title={definition.description}
              onMouseEnter={() => setHovered(definition.type)}
              onMouseLeave={() => setHovered((current) => current === definition.type ? null : current)}
              onDragStart={(event) => {
                event.dataTransfer.setData('text/plain', definition.type)
                event.dataTransfer.effectAllowed = 'copy'
                onDragTypeChange(definition.type)
              }}
              onDragEnd={() => onDragTypeChange(null)}
              onClick={() => onAdd(definition.type)}
              style={{
                display: 'flex',
                flexDirection: 'column',
                alignItems: 'center',
                justifyContent: 'center',
                gap: 4,
                height: 58,
                padding: '4px 2px',
                borderRadius: 8,
                cursor: 'grab',
                background: hovered === definition.type ? '#EFF4FF' : '#fff',
                border: `1px solid ${hovered === definition.type ? '#9DB6F0' : '#E6EAF2'}`,
                boxShadow: hovered === definition.type ? '0 4px 10px rgba(47,103,223,.12)' : 'none',
                transition: 'all .15s ease',
              }}
            >
              <span style={{ fontSize: 16, color: hovered === definition.type ? '#2F67DF' : '#4A5670', lineHeight: 1 }}>{sectionIcon(definition.type)}</span>
              <span style={{
                fontSize: 10.5,
                color: hovered === definition.type ? '#2F67DF' : '#606C82',
                maxWidth: '100%',
                overflow: 'hidden',
                textOverflow: 'ellipsis',
                whiteSpace: 'nowrap',
              }}>{definition.label}</span>
            </button>)}
          </div>
        </div>)}
    </div>
    <div style={{ padding: '8px 12px', borderTop: '1px solid #EEF1F6', display: 'flex', alignItems: 'center', gap: 6 }}>
      <DragOutlined style={{ color: '#A6B0C3', fontSize: 12 }} />
      <Typography.Text type="secondary" style={{ fontSize: 11 }}>拖动组件可精确插入到指定位置</Typography.Text>
    </div>
  </div>
}
