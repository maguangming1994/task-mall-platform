import { ArrowDownOutlined, ArrowUpOutlined, DeleteOutlined, LinkOutlined, UploadOutlined } from '@ant-design/icons'
import { Button, Image, Input, Modal, Space, Tag, Typography, Upload, message } from 'antd'
import { useState } from 'react'
import { ApiError, importMediaUrl, uploadMediaFile } from '../api/admin'

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
  const [urlOpen, setUrlOpen] = useState(false)
  const [url, setUrl] = useState('')
  const [uploading, setUploading] = useState(false)
  const [messageApi, contextHolder] = message.useMessage()

  const append = (asset: { id: string; url: string }) => {
    onChange?.([...value, { asset_id: asset.id, url: asset.url, alt: '', sort_order: value.length }])
  }
  const addFile = async (file: File) => {
    if (value.length >= max) return false
    if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type)) {
      messageApi.error('仅支持 JPG、PNG 和 WebP 图片。')
      return false
    }
    if (file.size > 10 * 1024 * 1024) {
      messageApi.error('单张图片不能超过 10MB。')
      return false
    }
    try {
      setUploading(true)
      append(await uploadMediaFile(file))
      messageApi.success('图片已上传并保存到当前存储。')
    } catch (error) {
      messageApi.error(error instanceof ApiError ? error.message : '图片上传失败。')
    } finally {
      setUploading(false)
    }
    return false
  }
  const importUrl = async () => {
    try {
      setUploading(true)
      append(await importMediaUrl(url))
      setUrl('')
      setUrlOpen(false)
      messageApi.success('网址图片已下载并转存，不会依赖外部图片地址。')
    } catch (error) {
      messageApi.error(error instanceof ApiError ? error.message : '图片网址导入失败。')
    } finally {
      setUploading(false)
    }
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
    {contextHolder}
    <Space direction="vertical" style={{ width: '100%' }}>
      {value.map((item, index) => <div key={item.asset_id ?? item.url} style={{ display: 'grid', gridTemplateColumns: '72px minmax(180px, 1fr) auto', alignItems: 'center', gap: 12, padding: 10, border: '1px solid #e8ecf2', borderRadius: 10 }}>
        <Image width={72} height={72} src={item.url} style={{ objectFit: 'cover', borderRadius: 8 }} />
        <Space direction="vertical" size={4} style={{ width: '100%' }}>
          <Space>{index === 0 ? <Tag color="blue">{primaryLabel}</Tag> : <Tag>{itemLabel} {index + 1}</Tag>}<Typography.Text type="secondary">{item.asset_id ? '已纳入素材存储' : '历史图片地址'}</Typography.Text></Space>
          <Input value={item.alt} maxLength={120} placeholder="图片说明，例如：白色款正面" onChange={(event) => update(index, { alt: event.target.value })} />
        </Space>
        <Space>
          <Button type="text" icon={<ArrowUpOutlined />} disabled={index === 0} onClick={() => move(index, -1)} aria-label="上移" />
          <Button type="text" icon={<ArrowDownOutlined />} disabled={index === value.length - 1} onClick={() => move(index, 1)} aria-label="下移" />
          <Button danger type="text" icon={<DeleteOutlined />} onClick={() => onChange?.(value.filter((_, itemIndex) => itemIndex !== index).map((entry, sort_order) => ({ ...entry, sort_order })))} aria-label="删除" />
        </Space>
      </div>)}
      <Space wrap>
        <Upload accept="image/jpeg,image/png,image/webp" multiple showUploadList={false} beforeUpload={(file) => { void addFile(file); return Upload.LIST_IGNORE }} disabled={uploading || value.length >= max}>
          <Button icon={<UploadOutlined />} loading={uploading}>从电脑上传</Button>
        </Upload>
        <Button icon={<LinkOutlined />} disabled={uploading || value.length >= max} onClick={() => setUrlOpen(true)}>通过图片网址导入</Button>
        <Typography.Text type="secondary">{helperText ?? `${value.length}/${max} 张，首张自动作为主图`}</Typography.Text>
      </Space>
    </Space>
    <Modal title="通过图片网址导入" open={urlOpen} onCancel={() => setUrlOpen(false)} onOk={() => void importUrl()} okButtonProps={{ loading: uploading, disabled: !url.startsWith('https://') }} okText="下载并转存" cancelText="取消">
      <Typography.Paragraph type="secondary">请输入可直接访问的 HTTPS 图片地址。系统会校验图片内容，并保存到当前启用的本地或云存储中。</Typography.Paragraph>
      <Input value={url} onChange={(event) => setUrl(event.target.value.trim())} placeholder="https://example.com/product.jpg" />
    </Modal>
  </>
}
