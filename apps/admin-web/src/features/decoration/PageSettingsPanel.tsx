import { Input, Space, Typography } from 'antd'
import type { DecorationPageConfig } from '../../api/admin'
import { MediaGalleryInput } from '../../components/MediaGalleryInput'

interface Props {
  page: DecorationPageConfig
  onChange: (patch: Partial<DecorationPageConfig>) => void
  coverHint?: string
  backgroundHint?: string
}

export function PageSettingsPanel({ page, onChange, coverHint, backgroundHint }: Props) {
  return <Space direction="vertical" style={{ width: '100%' }} size={10}>
    <div>
      <Typography.Text type="secondary" style={{ fontSize: 12 }}>页面名称（内部识别）</Typography.Text>
      <Input value={page.name} maxLength={30} placeholder="如：首页装修" onChange={(event) => onChange({ name: event.target.value })} />
    </div>
    <div>
      <Typography.Text type="secondary" style={{ fontSize: 12 }}>页面标题</Typography.Text>
      <Input value={page.title} maxLength={40} placeholder="展示在页面顶部的标题，可留空" onChange={(event) => onChange({ title: event.target.value })} />
    </div>
    <div>
      <Typography.Text type="secondary" style={{ fontSize: 12 }}>页面介绍</Typography.Text>
      <Input.TextArea rows={2} value={page.description} maxLength={200} placeholder="用于后台识别或活动说明，可留空" onChange={(event) => onChange({ description: event.target.value })} />
    </div>
    <div>
      <Typography.Text type="secondary" style={{ fontSize: 12 }}>页面封面</Typography.Text>
      <MediaGalleryInput
        value={page.cover_url ? [{ url: page.cover_url, alt: '页面封面', sort_order: 0 }] : []}
        onChange={(images) => onChange({ cover_url: images[0]?.url ?? '' })}
        max={1}
        primaryLabel="页面封面"
        helperText={coverHint ?? '用于后台页面列表展示，可留空。'}
      />
    </div>
    <div>
      <Typography.Text type="secondary" style={{ fontSize: 12 }}>页面背景色</Typography.Text>
      <Space.Compact style={{ width: '100%' }}>
        <Input value={page.background_color} maxLength={7} placeholder="#F5F7FB" onChange={(event) => onChange({ background_color: event.target.value })} />
        <Input
          type="color"
          value={/^#[0-9A-Fa-f]{6}$/.test(page.background_color) ? page.background_color : '#F5F7FB'}
          onChange={(event) => onChange({ background_color: event.target.value.toUpperCase() })}
          style={{ width: 46, padding: 2 }}
        />
      </Space.Compact>
    </div>
    <div>
      <Typography.Text type="secondary" style={{ fontSize: 12 }}>页面背景图</Typography.Text>
      <MediaGalleryInput
        value={page.background_url ? [{ url: page.background_url, alt: '页面背景', sort_order: 0 }] : []}
        onChange={(images) => onChange({ background_url: images[0]?.url ?? '' })}
        max={1}
        primaryLabel="页面背景"
        helperText={backgroundHint ?? '设置后作为页面背景；背景色会铺在背景图下方。'}
      />
    </div>
  </Space>
}
