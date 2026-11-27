import { ArrowDownOutlined, ArrowUpOutlined, CopyOutlined, DeleteOutlined, HolderOutlined } from '@ant-design/icons'
import { Button, Empty, Popconfirm, Switch, Typography } from 'antd'
import { useState } from 'react'
import type { DecorationSection } from '../../api/admin'
import { SectionPreview, sectionOuterStyle } from './DecorationPreview'
import { sectionTypeLabel } from './sectionRegistry'

interface Props {
  sections: DecorationSection[]
  selectedId: string | null
  onSelect: (id: string) => void
  onChange: (sections: DecorationSection[]) => void
}

export function SectionCanvas({ sections, selectedId, onSelect, onChange }: Props) {
  const [dragIndex, setDragIndex] = useState<number | null>(null)
  const [dropIndex, setDropIndex] = useState<number | null>(null)

  const move = (index: number, offset: number) => {
    const target = index + offset
    if (target < 0 || target >= sections.length) return
    const next = [...sections]
    ;[next[index], next[target]] = [next[target], next[index]]
    onChange(next)
  }

  const remove = (index: number) => onChange(sections.filter((_, itemIndex) => itemIndex !== index))

  const duplicate = (index: number) => {
    const source = sections[index]
    const copy: DecorationSection = {
      ...source,
      id: `sec-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 6)}`,
      style: JSON.parse(JSON.stringify(source.style)) as DecorationSection['style'],
      props: JSON.parse(JSON.stringify(source.props)) as Record<string, unknown>,
    }
    const next = [...sections]
    next.splice(index + 1, 0, copy)
    onChange(next)
    onSelect(copy.id)
  }

  const toggle = (index: number) => onChange(sections.map((item, itemIndex) => itemIndex === index ? { ...item, enabled: !item.enabled } : item))

  const dropAt = (targetIndex: number) => {
    if (dragIndex !== null && dragIndex !== targetIndex) {
      const next = [...sections]
      const [moved] = next.splice(dragIndex, 1)
      next.splice(targetIndex > dragIndex ? targetIndex - 1 : targetIndex, 0, moved)
      onChange(next)
    }
    setDragIndex(null)
    setDropIndex(null)
  }

  return <div style={{ background: '#F4F6F4', borderRadius: 12, padding: '18px 14px', maxHeight: 640, overflow: 'auto' }}>
    <div style={{ width: 336, margin: '0 auto', border: '10px solid #1F2430', borderRadius: 32, background: '#F5F7FB', padding: 10, minHeight: 520 }}>
      <div style={{ height: 18, borderRadius: 999, background: 'rgba(0,0,0,.12)', width: 90, margin: '0 auto 10px' }} />
      {sections.length === 0
        ? <div style={{ marginTop: 80 }}><Empty description="从左侧组件库点击添加区块" image={Empty.PRESENTED_IMAGE_SIMPLE} /></div>
        : <div style={{ display: 'flex', flexDirection: 'column', gap: 6 }}>
          {sections.map((section, index) => {
            const selected = selectedId === section.id
            return <div key={section.id}>
              {dropIndex === index && dragIndex !== null && dragIndex !== index ? <div style={{ height: 3, borderRadius: 2, background: '#FF3B30', margin: '2px 0' }} /> : null}
              <div
                draggable
                onDragStart={() => setDragIndex(index)}
                onDragEnd={() => { setDragIndex(null); setDropIndex(null) }}
                onDragOver={(event) => { event.preventDefault(); setDropIndex(index) }}
                onDrop={(event) => { event.preventDefault(); dropAt(index) }}
                onClick={() => onSelect(section.id)}
                style={{
                  position: 'relative',
                  border: `1px solid ${selected ? '#2F67DF' : 'transparent'}`,
                  outline: selected ? '2px solid rgba(47,103,223,.16)' : 'none',
                  borderRadius: 10,
                  opacity: section.enabled ? 1 : .45,
                  cursor: 'pointer',
                  padding: 2,
                  ...sectionOuterStyle(section),
                }}
              >
                <div style={{ position: 'absolute', top: -9, left: 6, zIndex: 2, display: 'flex', alignItems: 'center', gap: 4 }}>
                  <span style={{ fontSize: 10, fontWeight: 700, color: '#fff', background: selected ? '#2F67DF' : '#8B95A6', borderRadius: 5, padding: '1px 6px' }}>{sectionTypeLabel(section.type)}</span>
                  {!section.enabled ? <span style={{ fontSize: 9, color: '#fff', background: '#8B95A6', borderRadius: 5, padding: '1px 5px' }}>已停用</span> : null}
                </div>
                <SectionPreview section={section} />
                {selected ? <div
                  onClick={(event) => event.stopPropagation()}
                  style={{ position: 'absolute', right: -2, top: -14, zIndex: 3, display: 'flex', alignItems: 'center', gap: 2, background: '#fff', border: '1px solid #E3E8F0', borderRadius: 8, padding: 3, boxShadow: '0 4px 12px rgba(15,25,20,.12)' }}
                >
                  <HolderOutlined style={{ color: '#A0AABF', cursor: 'grab', padding: '0 2px' }} />
                  <Switch size="small" checked={section.enabled} onChange={() => toggle(index)} />
                  <Button type="text" size="small" icon={<ArrowUpOutlined />} disabled={index === 0} onClick={() => move(index, -1)} aria-label="上移" />
                  <Button type="text" size="small" icon={<ArrowDownOutlined />} disabled={index === sections.length - 1} onClick={() => move(index, 1)} aria-label="下移" />
                  <Button type="text" size="small" icon={<CopyOutlined />} onClick={() => duplicate(index)} aria-label="复制" />
                  <Popconfirm title="删除该区块？" onConfirm={() => remove(index)}>
                    <Button danger type="text" size="small" icon={<DeleteOutlined />} aria-label="删除" />
                  </Popconfirm>
                </div> : null}
              </div>
            </div>
          })}
        </div>}
    </div>
    <Typography.Paragraph type="secondary" style={{ textAlign: 'center', marginTop: 12, fontSize: 12 }}>
      点击区块选中；拖动排序；右上角工具可启停、上下移、复制与删除。
    </Typography.Paragraph>
  </div>
}
