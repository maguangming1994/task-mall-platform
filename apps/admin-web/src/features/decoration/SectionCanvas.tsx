import {
  ArrowDownOutlined,
  ArrowUpOutlined,
  CopyOutlined,
  DeleteOutlined,
  HolderOutlined,
  PlusOutlined,
  SettingOutlined,
} from '@ant-design/icons'
import { Button, Popconfirm, Switch, Tooltip, Typography } from 'antd'
import { useRef, useState } from 'react'
import type { DragEvent, ReactNode } from 'react'
import type { DecorationSection } from '../../api/admin'
import { SectionPreview, sectionOuterStyle } from './DecorationPreview'
import { createDecorationSection, sectionTypeLabel } from './sectionRegistry'

interface Props {
  sections: DecorationSection[]
  selectedId: string | null
  onSelect: (id: string) => void
  onChange: (sections: DecorationSection[]) => void
  /** 页面级背景，让手机预览更接近用户端真实效果。 */
  background?: { color?: string; url?: string }
  /** 组件库正在拖拽的组件类型；有值时在画布上显示插入位置。 */
  draggingType?: string | null
  onDragEnd?: () => void
  /** 点击右侧悬浮条「设置」时打开属性面板。 */
  onOpenSettings?: () => void
  hint?: string
}

const DEVICE_WIDTH = 360

export function SectionCanvas({ sections, selectedId, onSelect, onChange, background, draggingType, onDragEnd, onOpenSettings, hint }: Props) {
  const [moveIndex, setMoveIndex] = useState<number | null>(null)
  const [insertAt, setInsertAt] = useState<number | null>(null)
  const [hoveredId, setHoveredId] = useState<string | null>(null)
  const itemRefs = useRef<Array<HTMLDivElement | null>>([])

  const isDragging = Boolean(draggingType) || moveIndex !== null

  const resetDrag = () => {
    setMoveIndex(null)
    setInsertAt(null)
    onDragEnd?.()
  }

  const computeInsertIndex = (clientY: number): number => {
    for (let index = 0; index < sections.length; index += 1) {
      const node = itemRefs.current[index]
      if (!node) continue
      const rect = node.getBoundingClientRect()
      if (clientY < rect.top + rect.height / 2) return index
    }
    return sections.length
  }

  const handleDragOver = (event: DragEvent<HTMLDivElement>) => {
    if (!isDragging) return
    event.preventDefault()
    event.dataTransfer.dropEffect = draggingType ? 'copy' : 'move'
    setInsertAt(computeInsertIndex(event.clientY))
  }

  const handleDrop = (event: DragEvent<HTMLDivElement>) => {
    if (!isDragging) return
    event.preventDefault()
    const target = insertAt ?? sections.length

    if (draggingType) {
      const section = createDecorationSection(draggingType)
      const next = [...sections]
      next.splice(target, 0, section)
      onChange(next)
      onSelect(section.id)
    } else if (moveIndex !== null) {
      const next = [...sections]
      const [moved] = next.splice(moveIndex, 1)
      next.splice(target > moveIndex ? target - 1 : target, 0, moved)
      onChange(next)
    }
    resetDrag()
  }

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

  const pageStyle = {
    backgroundColor: background?.color || '#F5F7FB',
    ...(background?.url ? { backgroundImage: `url(${background.url})`, backgroundSize: 'cover', backgroundPosition: 'center' } : {}),
  }

  const insertionLine = (index: number) => insertAt === index && isDragging
    ? <div style={{ position: 'relative', height: 3, borderRadius: 2, background: '#2F67DF', margin: '4px 0' }}>
      <span style={{
        position: 'absolute',
        right: 4,
        top: -9,
        fontSize: 9,
        lineHeight: '16px',
        color: '#fff',
        background: '#2F67DF',
        borderRadius: 4,
        padding: '0 5px',
      }}>{draggingType ? '松手添加' : '松手移动'}</span>
    </div>
    : null

  const selectedIndex = sections.findIndex((section) => section.id === selectedId)
  const hasSelection = selectedIndex >= 0

  const railButton = (options: { key: string; icon: ReactNode; title: string; disabled?: boolean; onClick: () => void; danger?: boolean }) =>
    <Tooltip key={options.key} title={options.title} placement="left">
      <button
        type="button"
        disabled={options.disabled}
        onClick={options.onClick}
        style={{
          width: 34,
          height: 34,
          display: 'flex',
          alignItems: 'center',
          justifyContent: 'center',
          border: 'none',
          background: 'transparent',
          borderRadius: 9,
          cursor: options.disabled ? 'not-allowed' : 'pointer',
          color: options.disabled ? '#CBD3E1' : options.danger ? '#E5484D' : '#4A5670',
        }}
      >{options.icon}</button>
    </Tooltip>

  return <div style={{
    position: 'relative',
    display: 'flex',
    flexDirection: 'column',
    alignItems: 'center',
    gap: 10,
    padding: '10px 0 14px',
    borderRadius: 16,
    backgroundColor: '#FBFCFE',
    backgroundImage: 'linear-gradient(45deg, #EEF1F7 25%, transparent 25%), linear-gradient(-45deg, #EEF1F7 25%, transparent 25%), linear-gradient(45deg, transparent 75%, #EEF1F7 75%), linear-gradient(-45deg, transparent 75%, #EEF1F7 75%)',
    backgroundSize: '18px 18px',
    backgroundPosition: '0 0, 0 9px, 9px -9px, -9px 0',
  }}>
    <div style={{
      width: DEVICE_WIDTH + 20,
      borderRadius: 46,
      background: '#141821',
      padding: 10,
      boxShadow: '0 26px 60px rgba(15,23,42,.30), 0 0 0 1px rgba(255,255,255,.06) inset',
    }}>
      <div
        style={{
          position: 'relative',
          width: DEVICE_WIDTH,
          height: 640,
          borderRadius: 36,
          overflow: 'hidden',
          display: 'flex',
          flexDirection: 'column',
          ...pageStyle,
        }}
      >
        {/* 状态栏 + 灵动岛 */}
        <div style={{ position: 'relative', height: 38, flex: '0 0 auto', display: 'flex', alignItems: 'center', justifyContent: 'space-between', padding: '0 18px', color: '#101623', fontSize: 11, fontWeight: 600 }}>
          <span>9:41</span>
          <span style={{ position: 'absolute', left: '50%', transform: 'translateX(-50%)', top: 8, width: 88, height: 22, borderRadius: 12, background: '#0B0E14' }} />
          <span style={{ display: 'flex', alignItems: 'center', gap: 4 }}>
            <span style={{ display: 'flex', alignItems: 'flex-end', gap: 1.5 }}>
              {[4, 7, 10, 13].map((height) => <span key={height} style={{ width: 2.5, height, borderRadius: 1, background: '#101623' }} />)}
            </span>
            <span style={{ width: 12, height: 9, borderTop: '2px solid #101623', borderRadius: '50% 50% 0 0' }} />
            <span style={{ display: 'flex', alignItems: 'center', gap: 1 }}>
              <span style={{ width: 16, height: 8, border: '1px solid #101623', borderRadius: 2, padding: 1, display: 'block' }}>
                <span style={{ display: 'block', width: '72%', height: '100%', background: '#101623', borderRadius: 1 }} />
              </span>
            </span>
          </span>
        </div>

        <div
          onDragOver={handleDragOver}
          onDrop={handleDrop}
          onDragLeave={(event) => {
            if (!event.currentTarget.contains(event.relatedTarget as Node | null)) setInsertAt(null)
          }}
          style={{ flex: 1, minHeight: 0, overflowY: 'auto', overflowX: 'hidden', padding: '4px 12px 28px' }}
        >
          {sections.length === 0
            ? <div
              onDragOver={handleDragOver}
              onDrop={handleDrop}
              style={{
                marginTop: 40,
                height: 220,
                border: `1.5px dashed ${isDragging ? '#2F67DF' : '#C9D2E3'}`,
                borderRadius: 14,
                display: 'flex',
                flexDirection: 'column',
                alignItems: 'center',
                justifyContent: 'center',
                gap: 8,
                color: isDragging ? '#2F67DF' : '#9AA6BC',
                background: isDragging ? 'rgba(47,103,223,.06)' : 'transparent',
              }}
            >
              <PlusOutlined style={{ fontSize: 22 }} />
              <span style={{ fontSize: 12 }}>{isDragging ? '松手即可添加该组件' : '从左侧拖拽组件到这里'}</span>
              <span style={{ fontSize: 11, opacity: .8 }}>也可以直接点击左侧组件</span>
            </div>
            : <div style={{ display: 'flex', flexDirection: 'column' }}>
              {sections.map((section, index) => {
                const selected = selectedId === section.id
                const hovering = hoveredId === section.id
                const active = selected || hovering
                return <div key={section.id}>
                  {insertionLine(index)}
                  <div
                    ref={(node) => { itemRefs.current[index] = node }}
                    draggable
                    onDragStart={(event) => {
                      event.dataTransfer.setData('text/plain', section.id)
                      event.dataTransfer.effectAllowed = 'move'
                      setMoveIndex(index)
                    }}
                    onDragEnd={resetDrag}
                    onMouseEnter={() => setHoveredId(section.id)}
                    onMouseLeave={() => setHoveredId((current) => current === section.id ? null : current)}
                    onClick={() => onSelect(section.id)}
                    style={{
                      position: 'relative',
                      borderRadius: 8,
                      padding: 2,
                      marginBottom: 4,
                      cursor: 'pointer',
                      opacity: section.enabled ? 1 : .4,
                      outline: selected ? '2px solid #2F67DF' : active ? '1px dashed #9DB6F0' : 'none',
                      outlineOffset: 1,
                      transition: 'outline-color .15s ease',
                      ...sectionOuterStyle(section),
                    }}
                  >
                    <div style={{
                      position: 'absolute',
                      top: -9,
                      left: 4,
                      zIndex: 3,
                      display: 'flex',
                      alignItems: 'center',
                      gap: 4,
                      pointerEvents: 'none',
                    }}>
                      <span style={{
                        fontSize: 10,
                        lineHeight: '16px',
                        fontWeight: 600,
                        color: '#fff',
                        background: selected ? '#2F67DF' : 'rgba(30,41,59,.72)',
                        borderRadius: 4,
                        padding: '0 6px',
                      }}>{sectionTypeLabel(section.type)}</span>
                      {!section.enabled ? <span style={{ fontSize: 9, color: '#fff', background: '#8B95A6', borderRadius: 4, padding: '0 5px', lineHeight: '16px' }}>已停用</span> : null}
                    </div>

                    <SectionPreview section={section} />

                    {active ? <div
                      onClick={(event) => event.stopPropagation()}
                      onMouseDown={(event) => event.stopPropagation()}
                      style={{
                        position: 'absolute',
                        right: 2,
                        top: -16,
                        zIndex: 4,
                        display: 'flex',
                        alignItems: 'center',
                        gap: 1,
                        background: '#fff',
                        border: '1px solid #E3E8F0',
                        borderRadius: 8,
                        padding: '2px 3px',
                        boxShadow: '0 6px 16px rgba(15,25,20,.16)',
                      }}
                    >
                      <span style={{ cursor: 'grab', padding: '0 2px', color: '#A0AABF', display: 'flex' }}>
                        <HolderOutlined />
                      </span>
                      <Tooltip title={section.enabled ? '停用该区块' : '启用该区块'}>
                        <Switch size="small" checked={section.enabled} onChange={() => toggle(index)} style={{ margin: '0 3px' }} />
                      </Tooltip>
                      <Tooltip title="上移">
                        <Button type="text" size="small" icon={<ArrowUpOutlined />} disabled={index === 0} onClick={() => move(index, -1)} />
                      </Tooltip>
                      <Tooltip title="下移">
                        <Button type="text" size="small" icon={<ArrowDownOutlined />} disabled={index === sections.length - 1} onClick={() => move(index, 1)} />
                      </Tooltip>
                      <Tooltip title="复制">
                        <Button type="text" size="small" icon={<CopyOutlined />} onClick={() => duplicate(index)} />
                      </Tooltip>
                      <Popconfirm title="删除该区块？" onConfirm={() => remove(index)}>
                        <Button danger type="text" size="small" icon={<DeleteOutlined />} />
                      </Popconfirm>
                    </div> : null}
                  </div>
                  {index === sections.length - 1 ? insertionLine(sections.length) : null}
                </div>
              })}
              <div
                style={{
                  marginTop: 6,
                  height: 34,
                  borderRadius: 10,
                  border: `1px dashed ${isDragging ? '#2F67DF' : 'rgba(148,163,184,.6)'}`,
                  color: isDragging ? '#2F67DF' : '#9AA6BC',
                  fontSize: 11,
                  display: 'flex',
                  alignItems: 'center',
                  justifyContent: 'center',
                  background: isDragging ? 'rgba(47,103,223,.06)' : 'rgba(255,255,255,.5)',
                }}
              >松手插入到页面末尾</div>
            </div>}
        </div>

        <div style={{ flex: '0 0 auto', height: 22, display: 'flex', alignItems: 'center', justifyContent: 'center', background: 'inherit' }}>
          <span style={{ width: 110, height: 4, borderRadius: 999, background: 'rgba(15,23,42,.28)' }} />
        </div>
      </div>
    </div>

    {/* 手机右侧悬浮操作条，与设计工具一致 */}
    <div style={{
      position: 'absolute',
      left: '50%',
      marginLeft: DEVICE_WIDTH / 2 + 20,
      top: 96,
      display: 'flex',
      flexDirection: 'column',
      padding: 3,
      gap: 2,
      background: '#fff',
      border: '1px solid #E6EAF2',
      borderRadius: 12,
      boxShadow: '0 10px 26px rgba(15,23,42,.12)',
    }}>
      {railButton({ key: 'up', icon: <ArrowUpOutlined />, title: '上移选中区块', disabled: !hasSelection || selectedIndex === 0, onClick: () => move(selectedIndex, -1) })}
      {railButton({ key: 'down', icon: <ArrowDownOutlined />, title: '下移选中区块', disabled: !hasSelection || selectedIndex === sections.length - 1, onClick: () => move(selectedIndex, 1) })}
      {railButton({ key: 'copy', icon: <CopyOutlined />, title: '复制选中区块', disabled: !hasSelection, onClick: () => duplicate(selectedIndex) })}
      <span style={{ height: 1, background: '#EEF1F6', margin: '2px 4px' }} />
      {railButton({ key: 'settings', icon: <SettingOutlined />, title: '编辑选中区块', disabled: !hasSelection, onClick: () => onOpenSettings?.() })}
    </div>

    <Typography.Text type="secondary" style={{ fontSize: 11, textAlign: 'center', maxWidth: 380 }}>
      {hint ?? '拖动区块可调整顺序；悬停出现的工具条可启停、复制与删除。'}
    </Typography.Text>
  </div>
}
