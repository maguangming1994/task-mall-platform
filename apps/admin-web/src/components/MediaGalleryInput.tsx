import { ArrowDownOutlined, ArrowUpOutlined, DeleteOutlined, PlusOutlined } from '@ant-design/icons'
import { Button, Image, Input, Space, Tag, Typography } from 'antd'
import { useState } from 'react'
import { ImageSourceModal, type ImageSourceValue } from './ImageSourceModal'

export interface GalleryImageValue {
  asset_id?: string
  url: string
  alt?: string
  sort_order?: number
}

interface Props {
  value?: GalleryImageValue[]
  onChange?: (value: GalleryImageValue[]) => void
  max?: number
  primaryLabel?: string
  itemLabel?: string
  helperText?: string
}

export function MediaGalleryInput({ value = [], onChange, max = 9, primaryLabel = '商品主图', itemLabel = '轮播图', helperText }: Props) {
  const [sourceOpen, setSourceOpen] = useState(false)

  const append = (image: ImageSourceValue) => {
    if (value.length >= max) return
    onChange?.([...value, { asset_id: image.asset_id, url: image.url, alt: '', sort_order: value.length }])
  }
  const update = (index: number, patch: Partial<GalleryImageValue>) => onChange?.(value.map((item, itemIndex) => itemIndex === index ? { ...item, ...patch } : item))
  const move = (index: number, offset: number) => {
    const target = index + offset
    if (target < 0 || target >= value.length) return
    const next = [...value]
    ;[next[index], next[target]] = [next[target], next[index]]
    onChange?.(next.map((item, sort_order) => ({ ...item, sort_order })))
  }

  return <>
    <Space direction="vertical" style={{ width: '100%' }}>
      {value.map((item, index) => <div key={`${item.asset_id ?? item.url}-${index}`} style={{ display: 'grid', gridTemplateColumns: '72px minmax(180px, 1fr) auto', alignItems: 'center', gap: 12, padding: 10, border: '1px solid #e8ecf2', borderRadius: 10 }}>
        <Image width={72} height={72} src={item.url} style={{ objectFit: 'cover', borderRadius: 8 }} />
        <Space direction="vertical" size={4} style={{ width: '100%' }}>
          <Space>{index === 0 ? <Tag color="blue">{primaryLabel}</Tag> : <Tag>{itemLabel} {index + 1}</Tag>}<Typography.Text type="secondary">{item.asset_id ? '已纳入素材存储' : '外部或历史链接'}</Typography.Text></Space>
          <Input value={item.alt} maxLength={120} placeholder="图片说明，例如：白色款正面" onChange={(event) => update(index, { alt: event.target.value })} />
        </Space>
        <Space>
          <Button type="text" icon={<ArrowUpOutlined />} disabled={index === 0} onClick={() => move(index, -1)} aria-label="上移" />
          <Button type="text" icon={<ArrowDownOutlined />} disabled={index === value.length - 1} onClick={() => move(index, 1)} aria-label="下移" />
          <Button danger type="text" icon={<DeleteOutlined />} onClick={() => onChange?.(value.filter((_, itemIndex) => itemIndex !== index).map((entry, sort_order) => ({ ...entry, sort_order })))} aria-label="删除" />
        </Space>
      </div>)}
      <Space wrap>
        <Button icon={<PlusOutlined />} disabled={value.length >= max} onClick={() => setSourceOpen(true)}>添加图片</Button>
        <Typography.Text type="secondary">{helperText ?? `${value.length}/${max} 张；支持本地上传、链接转存和直接外链，首张自动作为主图`}</Typography.Text>
      </Space>
    </Space>
    <ImageSourceModal open={sourceOpen} onCancel={() => setSourceOpen(false)} onSelect={append} />
  </>
}
