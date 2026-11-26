import { ArrowDownOutlined, ArrowUpOutlined, CopyOutlined, DeleteOutlined, HolderOutlined } from '@ant-design/icons'
import { Button, Empty, Popconfirm, Space, Switch, Tag, Typography } from 'antd'
import { useState } from 'react'
import type { DecorationSection } from '../../api/admin'
import { sectionTypeLabel } from './sectionRegistry'

interface Props {
  sections: DecorationSection[]
  selectedId: string | null
  onSelect: (id: string) => void
  onChange: (sections: DecorationSection[]) => void
}

export function SectionCanvas({ sections, selectedId, onSelect, onChange }: Props) {
  const [dragIndex, setDragIndex] = useState<number | null>(null)

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
      props: JSON.parse(JSON.stringify(source.props)) as Record<string, unknown>,
    }
    const next = [...sections]
    next.splice(index + 1, 0, copy)
    onChange(next)
    onSelect(copy.id)
  }

  const dropAt = (targetIndex: number) => {
    if (dragIndex === null || dragIndex === targetIndex) return
    const next = [...sections]
    const [moved] = next.splice(dragIndex, 1)
    next.splice(targetIndex, 0, moved)
    setDragIndex(null)
    onChange(next)
  }

  if (sections.length === 0) {
    return <Empty description="还没有区块，从左侧组件库点击添加" image={Empty.PRESENTED_IMAGE_SIMPLE} />
  }

  return (
    <Space direction="vertical" style={{ width: '100%' }} size="small">
      {sections.map((section, index) => <div
        key={section.id}
        draggable
        onDragStart={() => setDragIndex(index)}
        onDragOver={(event) => event.preventDefault()}
        onDrop={() => dropAt(index)}
        onClick={() => onSelect(section.id)}
        style={{
          border: `1px solid ${selectedId === section.id ? '#2F67DF' : '#E7EBF2'}`,
          boxShadow: selectedId === section.id ? '0 0 0 2px rgba(47,103,223,.12)' : 'none',
          borderRadius: 10,
          padding: '10px 12px',
          background: '#fff',
          cursor: 'pointer',
        }}
      >
        <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
          <HolderOutlined style={{ color: '#A0AABF', cursor: 'grab' }} />
          <Tag color="blue">{sectionTypeLabel(section.type)}</Tag>
          <Typography.Text type="secondary" style={{ flex: 1 }} ellipsis>{section.id}</Typography.Text>
          <Switch size="small" checked={section.enabled} onClick={(_, event) => event.stopPropagation()} onChange={(checked) => onChange(sections.map((item) => item.id === section.id ? { ...item, enabled: checked } : item))} />
          <Button type="text" size="small" icon={<ArrowUpOutlined />} disabled={index === 0} onClick={(event) => { event.stopPropagation(); move(index, -1) }} aria-label="上移" />
          <Button type="text" size="small" icon={<ArrowDownOutlined />} disabled={index === sections.length - 1} onClick={(event) => { event.stopPropagation(); move(index, 1) }} aria-label="下移" />
          <Button type="text" size="small" icon={<CopyOutlined />} onClick={(event) => { event.stopPropagation(); duplicate(index) }} aria-label="复制" />
          <Popconfirm title="删除该区块？" onConfirm={() => remove(index)}>
            <Button danger type="text" size="small" icon={<DeleteOutlined />} onClick={(event) => event.stopPropagation()} aria-label="删除" />
          </Popconfirm>
        </div>
      </div>)}
      <Typography.Text type="secondary">支持拖拽排序；停用的区块不会在用户端显示。</Typography.Text>
    </Space>
  )
}
