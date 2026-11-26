import { Button, Card, Empty, Input, InputNumber, Select, Space, Switch, Tag, Typography } from 'antd'
import type { DecorationSection } from '../../api/admin'
import { MediaGalleryInput } from '../../components/MediaGalleryInput'
import { RichTextEditor } from '../../components/RichTextEditor'
import { DECORATION_LINK_OPTIONS, sectionTypeLabel } from './sectionRegistry'

interface Props {
  section: DecorationSection | null
  onChange: (section: DecorationSection) => void
}

function FieldLabel({ children }: { children: string }) {
  return <Typography.Text type="secondary" style={{ display: 'block', marginBottom: 4 }}>{children}</Typography.Text>
}

function TextField({ label, value, onChange, maxLength, placeholder, textarea }: {
  label: string
  value: string
  onChange: (value: string) => void
  maxLength?: number
  placeholder?: string
  textarea?: boolean
}) {
  return <div style={{ marginBottom: 12 }}>
    <FieldLabel>{label}</FieldLabel>
    {textarea
      ? <Input.TextArea rows={3} value={value} maxLength={maxLength} placeholder={placeholder} onChange={(event) => onChange(event.target.value)} />
      : <Input value={value} maxLength={maxLength} placeholder={placeholder} onChange={(event) => onChange(event.target.value)} />}
  </div>
}

function NumberField({ label, value, min, max, onChange }: { label: string; value: number; min: number; max: number; onChange: (value: number) => void }) {
  return <div style={{ marginBottom: 12 }}>
    <FieldLabel>{label}</FieldLabel>
    <InputNumber style={{ width: '100%' }} min={min} max={max} value={value} onChange={(next) => onChange(next ?? min)} />
  </div>
}

function LinkField({ value, onChange }: { value: string; onChange: (value: string) => void }) {
  return <div style={{ marginBottom: 12 }}>
    <FieldLabel>点击跳转</FieldLabel>
    <Select style={{ width: '100%' }} value={value} options={DECORATION_LINK_OPTIONS} onChange={onChange} />
  </div>
}

function ImageField({ label, value, onChange }: { label: string; value: string; onChange: (value: string) => void }) {
  return <div style={{ marginBottom: 12 }}>
    <FieldLabel>{label}</FieldLabel>
    <MediaGalleryInput
      value={value ? [{ url: value, alt: label, sort_order: 0 }] : []}
      onChange={(images) => onChange(images[0]?.url ?? '')}
      max={1}
      primaryLabel={label}
      helperText="支持本地上传、HTTPS 链接转存或直接外链；留空则不显示图片。"
    />
  </div>
}

export function SectionPropertyPanel({ section, onChange }: Props) {
  if (!section) {
    return <Empty description="选择一个区块以编辑属性" image={Empty.PRESENTED_IMAGE_SIMPLE} />
  }

  const props = section.props
  const setProp = (key: string, value: unknown) => onChange({ ...section, props: { ...props, [key]: value } })
  const stringProp = (key: string, fallback = '') => typeof props[key] === 'string' ? props[key] as string : fallback
  const numberProp = (key: string, fallback: number) => typeof props[key] === 'number' ? props[key] as number : fallback
  const listProp = (key: string) => Array.isArray(props[key]) ? props[key] as Record<string, unknown>[] : []

  return <Space direction="vertical" style={{ width: '100%' }} size="small">
    <Space style={{ justifyContent: 'space-between', width: '100%' }}>
      <Space><Tag color="blue">{sectionTypeLabel(section.type)}</Tag><Typography.Text type="secondary">{section.id}</Typography.Text></Space>
      <Space><Switch checked={section.enabled} onChange={(checked) => onChange({ ...section, enabled: checked })} /><span>在页面显示</span></Space>
    </Space>

    {section.type === 'banner' && <>
      <div style={{ marginBottom: 12 }}>
        <FieldLabel>自动轮播</FieldLabel>
        <Switch checked={props.autoplay !== false} onChange={(checked) => setProp('autoplay', checked)} />
      </div>
      <NumberField label="轮播间隔（毫秒）" min={1000} max={15000} value={numberProp('interval_ms', 4500)} onChange={(value) => setProp('interval_ms', value)} />
      {listProp('items').map((item, index) => {
        const update = (key: string, value: unknown) => setProp('items', listProp('items').map((entry, entryIndex) => entryIndex === index ? { ...entry, [key]: value } : entry))
        const text = (key: string, fallback = '') => typeof item[key] === 'string' ? item[key] as string : fallback
        return <Card key={index} size="small" title={`轮播图 ${index + 1}`} extra={<Button danger size="small" onClick={() => setProp('items', listProp('items').filter((_, entryIndex) => entryIndex !== index))}>删除</Button>}>
          <ImageField label="轮播图片" value={text('image_url')} onChange={(value) => update('image_url', value)} />
          <TextField label="主标题" value={text('title')} maxLength={30} onChange={(value) => update('title', value)} />
          <TextField label="副标题" value={text('subtitle')} maxLength={60} onChange={(value) => update('subtitle', value)} />
          <TextField label="按钮文字" value={text('button_text')} maxLength={12} onChange={(value) => update('button_text', value)} />
          <LinkField value={text('link_type', 'none')} onChange={(value) => update('link_type', value)} />
        </Card>
      })}
      <Button type="dashed" block onClick={() => setProp('items', [...listProp('items'), { image_url: '', title: '新的轮播标题', subtitle: '', button_text: '立即查看', link_type: 'none', link_value: '' }])}>新增轮播图</Button>
    </>}

    {section.type === 'announcement' && <>
      <TextField label="图标文字" value={stringProp('icon_text')} maxLength={2} onChange={(value) => setProp('icon_text', value)} />
      <ImageField label="图标图片" value={stringProp('icon_url')} onChange={(value) => setProp('icon_url', value)} />
      <TextField label="标题" value={stringProp('title')} maxLength={30} onChange={(value) => setProp('title', value)} />
      <div style={{ marginBottom: 12 }}>
        <FieldLabel>公告内容</FieldLabel>
        <RichTextEditor compact value={stringProp('content')} minHeight={160} placeholder="请输入公告内容，可设置加粗、列表、对齐和链接…" onChange={(html) => setProp('content', html)} />
      </div>
      <LinkField value={stringProp('link_type', 'none')} onChange={(value) => setProp('link_type', value)} />
    </>}

    {section.type === 'quick_grid' && <>
      <NumberField label="每行列数（3–5）" min={3} max={5} value={numberProp('columns', 4)} onChange={(value) => setProp('columns', value)} />
      {listProp('items').map((item, index) => {
        const update = (key: string, value: unknown) => setProp('items', listProp('items').map((entry, entryIndex) => entryIndex === index ? { ...entry, [key]: value } : entry))
        const text = (key: string, fallback = '') => typeof item[key] === 'string' ? item[key] as string : fallback
        return <Card key={index} size="small" title={`入口 ${index + 1}`} extra={<Button danger size="small" onClick={() => setProp('items', listProp('items').filter((_, entryIndex) => entryIndex !== index))}>删除</Button>}>
          <TextField label="入口名称" value={text('label')} maxLength={8} onChange={(value) => update('label', value)} />
          <TextField label="图片为空时显示的文字" value={text('mark')} maxLength={2} onChange={(value) => update('mark', value)} />
          <ImageField label="入口图标" value={text('icon_url')} onChange={(value) => update('icon_url', value)} />
          <LinkField value={text('link_type', 'none')} onChange={(value) => update('link_type', value)} />
          <Space><Switch checked={item.enabled !== false} onChange={(checked) => update('enabled', checked)} /><span>显示该入口</span></Space>
        </Card>
      })}
      <Button type="dashed" block onClick={() => setProp('items', [...listProp('items'), { id: `entry-${Date.now().toString(36)}`, label: '新入口', mark: '新', icon_url: '', link_type: 'none', enabled: true }])}>新增入口</Button>
    </>}

    {section.type === 'image_banner' && <>
      <ImageField label="横幅图片" value={stringProp('image_url')} onChange={(value) => setProp('image_url', value)} />
      <NumberField label="图片高度（rpx）" min={80} max={600} value={numberProp('height_rpx', 200)} onChange={(value) => setProp('height_rpx', value)} />
      <LinkField value={stringProp('link_type', 'none')} onChange={(value) => setProp('link_type', value)} />
    </>}

    {section.type === 'image_text' && <>
      <div style={{ marginBottom: 12 }}>
        <FieldLabel>图文布局</FieldLabel>
        <Select style={{ width: '100%' }} value={stringProp('layout', 'left')} options={[{ value: 'left', label: '左图右文' }, { value: 'right', label: '右图左文' }]} onChange={(value) => setProp('layout', value)} />
      </div>
      <ImageField label="图片" value={stringProp('image_url')} onChange={(value) => setProp('image_url', value)} />
      <TextField label="标题" value={stringProp('title')} maxLength={30} onChange={(value) => setProp('title', value)} />
      <TextField label="描述" value={stringProp('description')} maxLength={120} textarea onChange={(value) => setProp('description', value)} />
      <LinkField value={stringProp('link_type', 'none')} onChange={(value) => setProp('link_type', value)} />
    </>}

    {section.type === 'product_grid' && <>
      <TextField label="区块标题" value={stringProp('title')} maxLength={20} onChange={(value) => setProp('title', value)} />
      <TextField label="副标题" value={stringProp('subtitle')} maxLength={40} onChange={(value) => setProp('subtitle', value)} />
      <NumberField label="展示数量" min={1} max={20} value={numberProp('limit', 6)} onChange={(value) => setProp('limit', value)} />
      <TextField label="商品分类 ID（可留空）" value={stringProp('category_id')} maxLength={64} onChange={(value) => setProp('category_id', value)} />
      <div style={{ marginBottom: 12 }}>
        <FieldLabel>价格展示</FieldLabel>
        <Select style={{ width: '100%' }} value={stringProp('price_mode', 'default')} options={[{ value: 'default', label: '按商品默认价格' }, { value: 'points', label: '仅积分' }, { value: 'cash', label: '仅现金' }, { value: 'cash_points', label: '现金 + 积分' }]} onChange={(value) => setProp('price_mode', value)} />
      </div>
    </>}

    {section.type === 'post_list' && <>
      <TextField label="区块标题" value={stringProp('title')} maxLength={20} onChange={(value) => setProp('title', value)} />
      <TextField label="副标题" value={stringProp('subtitle')} maxLength={40} onChange={(value) => setProp('subtitle', value)} />
      <NumberField label="展示数量" min={1} max={20} value={numberProp('limit', 6)} onChange={(value) => setProp('limit', value)} />
      <TextField label="帖子分类标识（可留空）" value={stringProp('category_code')} maxLength={32} onChange={(value) => setProp('category_code', value)} />
    </>}

    {section.type === 'task_list' && <>
      <TextField label="区块标题" value={stringProp('title')} maxLength={20} onChange={(value) => setProp('title', value)} />
      <TextField label="副标题" value={stringProp('subtitle')} maxLength={40} onChange={(value) => setProp('subtitle', value)} />
      <NumberField label="展示数量" min={1} max={10} value={numberProp('limit', 4)} onChange={(value) => setProp('limit', value)} />
      <TextField label="任务分类（可留空）" value={stringProp('category')} maxLength={32} onChange={(value) => setProp('category', value)} />
    </>}

    {section.type === 'rich_text' && <div>
      <FieldLabel>富文本内容</FieldLabel>
      <RichTextEditor compact value={stringProp('html')} minHeight={220} placeholder="请输入富文本内容，可设置加粗、列表、对齐和链接…" onChange={(html) => setProp('html', html)} />
    </div>}

    {section.type === 'spacer' && <NumberField label="间距高度（rpx）" min={0} max={200} value={numberProp('height_rpx', 24)} onChange={(value) => setProp('height_rpx', value)} />}
  </Space>
}
