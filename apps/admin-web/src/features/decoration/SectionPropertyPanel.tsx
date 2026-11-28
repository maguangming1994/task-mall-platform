import { Button, Card, Checkbox, Divider, Empty, Input, InputNumber, Select, Slider, Space, Switch, Tag, Typography } from 'antd'
import type { DecorationSection, DecorationStyle } from '../../api/admin'
import { MediaGalleryInput } from '../../components/MediaGalleryInput'
import { RichTextEditor } from '../../components/RichTextEditor'
import { DECORATION_LINK_OPTIONS, createDecorationStyle, sectionTypeLabel } from './sectionRegistry'

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

function ColorField({ label, value, onChange }: { label: string; value: string; onChange: (value: string) => void }) {
  return <div style={{ marginBottom: 12 }}>
    <FieldLabel>{label}</FieldLabel>
    <Space.Compact style={{ width: '100%' }}>
      <input type="color" value={value || '#FFFFFF'} onChange={(event) => onChange(event.target.value.toUpperCase())} style={{ width: 38, height: 32, border: '1px solid #DDE2DF', borderRadius: '6px 0 0 6px', background: '#fff', padding: 2 }} aria-label={label} />
      <Input value={value} placeholder="#RRGGBB（留空为透明）" onChange={(event) => onChange(event.target.value)} style={{ flex: 1 }} />
    </Space.Compact>
  </div>
}

function SpacingField({ label, value, onChange }: { label: string; value: DecorationStyle['margin']; onChange: (value: DecorationStyle['margin']) => void }) {
  const sides: Array<{ key: keyof DecorationStyle['margin']; label: string }> = [
    { key: 'top', label: '上' }, { key: 'right', label: '右' }, { key: 'bottom', label: '下' }, { key: 'left', label: '左' },
  ]
  return <div style={{ marginBottom: 12 }}>
    <FieldLabel>{label}</FieldLabel>
    <Space.Compact style={{ width: '100%' }}>
      {sides.map((side) => <InputNumber
        key={side.key}
        style={{ width: '25%' }}
        min={0}
        max={100}
        value={value[side.key]}
        addonBefore={side.label}
        onChange={(next) => onChange({ ...value, [side.key]: next ?? 0 })}
      />)}
    </Space.Compact>
  </div>
}

function StyleEditor({ style, onChange }: { style: DecorationStyle; onChange: (style: DecorationStyle) => void }) {
  const patch = (partial: Partial<DecorationStyle>) => onChange({ ...style, ...partial })
  const corners = style.radius_corners ?? { top_left: 0, top_right: 0, bottom_left: 0, bottom_right: 0 }
  const cornerItems: Array<{ key: keyof typeof corners; label: string }> = [
    { key: 'top_left', label: '左上' }, { key: 'top_right', label: '右上' }, { key: 'bottom_left', label: '左下' }, { key: 'bottom_right', label: '右下' },
  ]
  return <Card size="small" title="组件布局与样式" style={{ marginTop: 8 }}>
    <SpacingField label="外边距（0–100）" value={style.margin} onChange={(value) => patch({ margin: value })} />
    <SpacingField label="内边距（0–100）" value={style.padding} onChange={(value) => patch({ padding: value })} />
    <NumberField label="统一圆角（0–100）" min={0} max={100} value={style.radius} onChange={(value) => patch({ radius: value })} />
    <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 10 }}>
      <span>四角独立圆角</span>
      <Switch checked={style.corner_radius === true} onChange={(checked) => patch({ corner_radius: checked })} />
    </div>
    {style.corner_radius === true && <Space.Compact style={{ width: '100%', marginBottom: 12 }}>
      {cornerItems.map((corner) => <InputNumber
        key={corner.key}
        style={{ width: '25%' }}
        min={0}
        max={100}
        value={corners[corner.key]}
        addonBefore={corner.label}
        onChange={(next) => patch({ radius_corners: { ...corners, [corner.key]: next ?? 0 } })}
      />)}
    </Space.Compact>}
    <div style={{ marginBottom: 12 }}>
      <FieldLabel>整体透明度（20–100）</FieldLabel>
      <Slider min={20} max={100} value={style.opacity ?? 100} onChange={(value) => patch({ opacity: value })} />
    </div>
    <div style={{ marginBottom: 12 }}>
      <FieldLabel>背景</FieldLabel>
      <Select
        style={{ width: '100%' }}
        value={style.background_type}
        options={[{ value: 'none', label: '无背景' }, { value: 'color', label: '纯色' }, { value: 'image', label: '图片' }, { value: 'gradient', label: '渐变' }]}
        onChange={(value) => patch({ background_type: value as DecorationStyle['background_type'] })}
      />
    </div>
    {style.background_type === 'color' && <ColorField label="背景颜色" value={style.background_color} onChange={(value) => patch({ background_color: value })} />}
    {style.background_type === 'image' && <ImageField label="背景图片" value={style.background_image} onChange={(value) => patch({ background_image: value })} />}
    {style.background_type === 'gradient' && <TextField label="CSS 渐变" value={style.background_gradient} maxLength={120} placeholder="linear-gradient(135deg,#12D978,#08A152)" onChange={(value) => patch({ background_gradient: value })} />}
    <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 10 }}>
      <span>显示边框</span>
      <Switch checked={style.border} onChange={(checked) => patch({ border: checked })} />
    </div>
    {style.border && <ColorField label="边框颜色" value={style.border_color} onChange={(value) => patch({ border_color: value })} />}
    <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 10 }}>
      <span>卡片阴影</span>
      <Switch checked={style.shadow} onChange={(checked) => patch({ shadow: checked })} />
    </div>
    <Divider style={{ margin: '10px 0' }} />
    <div style={{ marginBottom: 12 }}>
      <FieldLabel>显示渠道</FieldLabel>
      <Checkbox.Group
        value={style.channels}
        options={[{ value: 'h5', label: 'H5' }, { value: 'miniapp', label: '小程序' }]}
        onChange={(value) => patch({ channels: (value.length ? value : ['h5', 'miniapp']) as DecorationStyle['channels'] })}
      />
    </div>
    <TextField label="开始显示（YYYY-MM-DDTHH:mm）" value={style.display_start} maxLength={16} placeholder="留空表示立即显示" onChange={(value) => patch({ display_start: value })} />
    <TextField label="结束显示（YYYY-MM-DDTHH:mm）" value={style.display_end} maxLength={16} placeholder="留空表示长期显示" onChange={(value) => patch({ display_end: value })} />
    <Divider style={{ margin: '10px 0' }} />
    <TextField label="每日开始显示（HH:mm）" value={style.display_daily_start ?? ''} maxLength={5} placeholder="如 09:00，留空表示全天" onChange={(value) => patch({ display_daily_start: value })} />
    <TextField label="每日结束显示（HH:mm）" value={style.display_daily_end ?? ''} maxLength={5} placeholder="如 22:00，小于开始时间视为跨零点" onChange={(value) => patch({ display_daily_end: value })} />
  </Card>
}

export function SectionPropertyPanel({ section, onChange }: Props) {
  if (!section) {
    return <Empty description="选择一个区块以编辑属性" image={Empty.PRESENTED_IMAGE_SIMPLE} />
  }

  const props = section.props
  const style = section.style ?? createDecorationStyle()
  const updateStyle = (next: DecorationStyle) => onChange({ ...section, style: next })
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
        <FieldLabel>数据来源</FieldLabel>
        <Select style={{ width: '100%' }} value={stringProp('data_source', 'manual')} options={[{ value: 'manual', label: '手动上传' }, { value: 'platform', label: '读取平台幻灯片' }]} onChange={(value) => setProp('data_source', value)} />
      </div>
      {stringProp('data_source', 'manual') === 'platform'
        ? <>
          <NumberField label="读取数量（1–10）" min={1} max={10} value={numberProp('limit', 5)} onChange={(value) => setProp('limit', value)} />
          <Typography.Text type="secondary" style={{ display: 'block', marginBottom: 12 }}>读取「设置 → 首页内容 → 轮播图」的图片、标题、描述、按钮与跳转；为空时回退到下方手动配置。</Typography.Text>
        </>
        : null}
      <div style={{ marginBottom: 12 }}>
        <FieldLabel>自动轮播</FieldLabel>
        <Switch checked={props.autoplay !== false} onChange={(checked) => setProp('autoplay', checked)} />
      </div>
      <NumberField label="轮播间隔（毫秒）" min={1000} max={15000} value={numberProp('interval_ms', 4500)} onChange={(value) => setProp('interval_ms', value)} />
      <NumberField label="切换动画时长（毫秒）" min={200} max={1000} value={numberProp('duration_ms', 300)} onChange={(value) => setProp('duration_ms', value)} />
      <NumberField label="轮播高度（rpx）" min={200} max={600} value={numberProp('height_rpx', 280)} onChange={(value) => setProp('height_rpx', value)} />
      <NumberField label="图片圆角（rpx）" min={0} max={48} value={numberProp('radius_rpx', 24)} onChange={(value) => setProp('radius_rpx', value)} />
      <div style={{ marginBottom: 12 }}>
        <FieldLabel>指示器样式</FieldLabel>
        <Select style={{ width: '100%' }} value={stringProp('indicator', 'dots')} options={[{ value: 'dots', label: '圆点' }, { value: 'bars', label: '短条' }, { value: 'numbers', label: '数字' }, { value: 'none', label: '不显示' }]} onChange={(value) => setProp('indicator', value)} />
      </div>
      <div style={{ marginBottom: 12 }}>
        <FieldLabel>指示器位置</FieldLabel>
        <Select style={{ width: '100%' }} value={stringProp('indicator_position', 'bottom-right')} options={[{ value: 'bottom-left', label: '下左' }, { value: 'bottom-center', label: '下中' }, { value: 'bottom-right', label: '下右' }, { value: 'top-left', label: '上左' }, { value: 'top-center', label: '上中' }, { value: 'top-right', label: '上右' }]} onChange={(value) => setProp('indicator_position', value)} />
      </div>
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 10 }}>
        <span>显示标题层</span>
        <Switch checked={props.show_title !== false} onChange={(checked) => setProp('show_title', checked)} />
      </div>
      {stringProp('data_source', 'manual') === 'manual' && <>
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
    </>}

    {section.type === 'announcement' && <>
      <div style={{ marginBottom: 12 }}>
        <FieldLabel>数据来源</FieldLabel>
        <Select style={{ width: '100%' }} value={stringProp('data_source', 'manual')} options={[{ value: 'manual', label: '手动填写' }, { value: 'system', label: '读取系统公告' }]} onChange={(value) => setProp('data_source', value)} />
      </div>
      {stringProp('data_source', 'manual') === 'system'
        ? <>
          <NumberField label="读取数量（1–10）" min={1} max={10} value={numberProp('limit', 3)} onChange={(value) => setProp('limit', value)} />
          <Typography.Text type="secondary" style={{ display: 'block', marginBottom: 12 }}>读取当前用户的系统公告，按时间倒序拼接播放；未登录或暂无公告时回退到下方手动内容。</Typography.Text>
        </>
        : null}
      <TextField label="图标文字" value={stringProp('icon_text')} maxLength={2} onChange={(value) => setProp('icon_text', value)} />
      <ImageField label="图标图片" value={stringProp('icon_url')} onChange={(value) => setProp('icon_url', value)} />
      <TextField label="标题" value={stringProp('title')} maxLength={30} onChange={(value) => setProp('title', value)} />
      <div style={{ marginBottom: 12 }}>
        <FieldLabel>公告内容</FieldLabel>
        <RichTextEditor compact value={stringProp('content')} minHeight={160} placeholder="请输入公告内容，可设置加粗、列表、对齐和链接…" onChange={(html) => setProp('content', html)} />
      </div>
      <LinkField value={stringProp('link_type', 'none')} onChange={(value) => setProp('link_type', value)} />
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 10 }}>
        <span>内容滚动播放</span>
        <Switch checked={props.scroll !== false} onChange={(checked) => setProp('scroll', checked)} />
      </div>
      <NumberField label="滚动速度（1 慢 – 5 快）" min={1} max={5} value={numberProp('speed', 3)} onChange={(value) => setProp('speed', value)} />
      <NumberField label="标题字号（rpx）" min={22} max={36} value={numberProp('title_size_rpx', 28)} onChange={(value) => setProp('title_size_rpx', value)} />
      <ColorField label="公告文字颜色" value={stringProp('text_color', '#8A6A1F')} onChange={(value) => setProp('text_color', value)} />
      <NumberField label="图标尺寸（rpx）" min={32} max={72} value={numberProp('icon_size_rpx', 48)} onChange={(value) => setProp('icon_size_rpx', value)} />
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 10 }}>
        <span>显示右侧箭头</span>
        <Switch checked={props.show_arrow !== false} onChange={(checked) => setProp('show_arrow', checked)} />
      </div>
    </>}

    {section.type === 'quick_grid' && <>
      <div style={{ marginBottom: 12 }}>
        <FieldLabel>数据来源</FieldLabel>
        <Select style={{ width: '100%' }} value={stringProp('data_source', 'manual')} options={[{ value: 'manual', label: '手动填写' }, { value: 'product_category', label: '调用商品分类' }]} onChange={(value) => setProp('data_source', value)} />
      </div>
      {stringProp('data_source', 'manual') === 'product_category' && <>
        <NumberField label="读取数量（1–10）" min={1} max={10} value={numberProp('limit', 8)} onChange={(value) => setProp('limit', value)} />
        <Typography.Text type="secondary" style={{ display: 'block', marginBottom: 12 }}>按商城分类顺序生成入口，点击直达对应分类；暂无分类时回退到手动入口。</Typography.Text>
      </>}
      <div style={{ marginBottom: 12 }}>
        <FieldLabel>图标形状</FieldLabel>
        <Select style={{ width: '100%' }} value={stringProp('icon_shape', 'rounded')} options={[{ value: 'rounded', label: '圆角方形' }, { value: 'circle', label: '圆形' }]} onChange={(value) => setProp('icon_shape', value)} />
      </div>
      <NumberField label="图标尺寸（rpx）" min={56} max={120} value={numberProp('icon_size_rpx', 84)} onChange={(value) => setProp('icon_size_rpx', value)} />
      <NumberField label="文字字号（rpx）" min={20} max={32} value={numberProp('label_size_rpx', 24)} onChange={(value) => setProp('label_size_rpx', value)} />
      <ColorField label="文字颜色" value={stringProp('label_color', '#3C4961')} onChange={(value) => setProp('label_color', value)} />
      <NumberField label="行间距（rpx）" min={0} max={48} value={numberProp('row_gap_rpx', 20)} onChange={(value) => setProp('row_gap_rpx', value)} />
      <NumberField label="每行列数（3–5）" min={3} max={5} value={numberProp('columns', 4)} onChange={(value) => setProp('columns', value)} />
      {stringProp('data_source', 'manual') === 'manual' && <>
        {listProp('items').map((item, index) => {
          const update = (key: string, value: unknown) => setProp('items', listProp('items').map((entry, entryIndex) => entryIndex === index ? { ...entry, [key]: value } : entry))
          const text = (key: string, fallback = '') => typeof item[key] === 'string' ? item[key] as string : fallback
          return <Card key={index} size="small" title={`入口 ${index + 1}`} extra={<Button danger size="small" onClick={() => setProp('items', listProp('items').filter((_, entryIndex) => entryIndex !== index))}>删除</Button>}>
            <TextField label="入口名称" value={text('label')} maxLength={8} onChange={(value) => update('label', value)} />
            <TextField label="图片为空时显示的文字" value={text('mark')} maxLength={2} onChange={(value) => update('mark', value)} />
            <div style={{ marginBottom: 12 }}>
              <FieldLabel>外部跳转类型</FieldLabel>
              <Select style={{ width: '100%' }} value={text('link_ext_type', 'none')} options={[{ value: 'none', label: '内部页面（下方选择）' }, { value: 'miniprogram', label: '跳转其他小程序' }, { value: 'h5', label: '打开 H5 网页' }]} onChange={(value) => update('link_ext_type', value)} />
            </div>
            {text('link_ext_type', 'none') === 'miniprogram' && <>
              <TextField label="目标小程序 AppID" value={text('mini_app_id')} maxLength={32} placeholder="如 wx1234567890abcdef" onChange={(value) => update('mini_app_id', value)} />
              <TextField label="目标页面路径（可留空）" value={text('mini_path')} maxLength={200} placeholder="如 pages/index/index?id=1" onChange={(value) => update('mini_path', value)} />
            </>}
            {text('link_ext_type', 'none') === 'h5' && <TextField label="H5 链接地址" value={text('h5_url')} maxLength={500} placeholder="https://…（需为已配置的业务域名）" onChange={(value) => update('h5_url', value)} />}
            {text('link_ext_type', 'none') === 'none' && <LinkField value={text('link_type', 'none')} onChange={(value) => update('link_type', value)} />}
            <div style={{ marginBottom: 12 }}>
              <FieldLabel>角标样式</FieldLabel>
              <Select style={{ width: '100%' }} value={text('badge_type', 'none')} options={[{ value: 'none', label: '不显示' }, { value: 'text', label: '数字文字' }, { value: 'dot', label: '圆点' }, { value: 'image', label: '图片' }]} onChange={(value) => update('badge_type', value)} />
            </div>
            {text('badge_type', 'none') === 'text' && <TextField label="角标文字" value={text('badge_text')} maxLength={4} placeholder="如 新 / 热" onChange={(value) => update('badge_text', value)} />}
            {text('badge_type', 'none') === 'image' && <ImageField label="角标图片" value={text('badge_image')} onChange={(value) => update('badge_image', value)} />}
            <Space><Switch checked={item.enabled !== false} onChange={(checked) => update('enabled', checked)} /><span>显示该入口</span></Space>
          </Card>
        })}
        <Button type="dashed" block onClick={() => setProp('items', [...listProp('items'), { id: `entry-${Date.now().toString(36)}`, label: '新入口', mark: '新', icon_url: '', link_type: 'none', link_ext_type: 'none', mini_app_id: '', mini_path: '', h5_url: '', enabled: true, badge_type: 'none', badge_text: '', badge_image: '' }])}>新增入口</Button>
      </>}
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
      <div style={{ marginBottom: 12 }}>
        <FieldLabel>每行列数</FieldLabel>
        <Select style={{ width: '100%' }} value={numberProp('columns', 2)} options={[{ value: 1, label: '单列大卡' }, { value: 2, label: '两列' }, { value: 3, label: '三列' }]} onChange={(value) => setProp('columns', value)} />
      </div>
      <Space style={{ marginBottom: 10 }}><Switch size="small" checked={props.show_price !== false} onChange={(checked) => setProp('show_price', checked)} /><span>显示价格</span></Space>
      <Space style={{ marginBottom: 10 }}><Switch size="small" checked={props.show_subtitle !== false} onChange={(checked) => setProp('show_subtitle', checked)} /><span>显示副标题</span></Space>
      <Space style={{ marginBottom: 10 }}><Switch size="small" checked={props.show_category === true} onChange={(checked) => setProp('show_category', checked)} /><span>显示分类标签</span></Space>
    </>}

    {section.type === 'post_list' && <>
      <TextField label="区块标题" value={stringProp('title')} maxLength={20} onChange={(value) => setProp('title', value)} />
      <TextField label="副标题" value={stringProp('subtitle')} maxLength={40} onChange={(value) => setProp('subtitle', value)} />
      <NumberField label="展示数量" min={1} max={20} value={numberProp('limit', 6)} onChange={(value) => setProp('limit', value)} />
      <TextField label="帖子分类标识（可留空）" value={stringProp('category_code')} maxLength={32} onChange={(value) => setProp('category_code', value)} />
      <div style={{ marginBottom: 12 }}>
        <FieldLabel>展示形态</FieldLabel>
        <Select style={{ width: '100%' }} value={stringProp('layout', 'card')} options={[{ value: 'card', label: '卡片' }, { value: 'list', label: '列表' }]} onChange={(value) => setProp('layout', value)} />
      </div>
      <Space style={{ marginBottom: 10 }}><Switch size="small" checked={props.show_avatar !== false} onChange={(checked) => setProp('show_avatar', checked)} /><span>显示头像</span></Space>
      <Space style={{ marginBottom: 10 }}><Switch size="small" checked={props.show_excerpt === true} onChange={(checked) => setProp('show_excerpt', checked)} /><span>显示摘要</span></Space>
      <Space style={{ marginBottom: 10 }}><Switch size="small" checked={props.show_stats !== false} onChange={(checked) => setProp('show_stats', checked)} /><span>显示评论数</span></Space>
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

    {section.type === 'nav_bar' && <>
      <TextField label="标题" value={stringProp('title')} maxLength={20} onChange={(value) => setProp('title', value)} />
      <TextField label="副标题" value={stringProp('subtitle')} maxLength={40} onChange={(value) => setProp('subtitle', value)} />
      <LinkField value={stringProp('link_type', 'none')} onChange={(value) => setProp('link_type', value)} />
      <Space><Switch checked={props.show_border === true} onChange={(checked) => setProp('show_border', checked)} /><span>显示底部分割线</span></Space>
    </>}

    {section.type === 'search_box' && <>
      <TextField label="占位文案" value={stringProp('placeholder')} maxLength={20} onChange={(value) => setProp('placeholder', value)} />
      <LinkField value={stringProp('link_type', 'products')} onChange={(value) => setProp('link_type', value)} />
    </>}

    {section.type === 'category_nav' && <>
      {listProp('items').map((item, index) => {
        const update = (key: string, value: unknown) => setProp('items', listProp('items').map((entry, entryIndex) => entryIndex === index ? { ...entry, [key]: value } : entry))
        return <Card key={index} size="small" title={`分类 ${index + 1}`} extra={<Button danger size="small" onClick={() => setProp('items', listProp('items').filter((_, entryIndex) => entryIndex !== index))}>删除</Button>}>
          <TextField label="分类名称" value={typeof item.label === 'string' ? item.label : ''} maxLength={8} onChange={(value) => update('label', value)} />
          <TextField label="商城分类 ID（可选）" value={typeof item.category_id === 'string' ? item.category_id : ''} maxLength={64} placeholder="填写后点击直达该分类" onChange={(value) => update('category_id', value)} />
          <LinkField value={typeof item.link_type === 'string' ? item.link_type : 'none'} onChange={(value) => update('link_type', value)} />
        </Card>
      })}
      <Button type="dashed" block disabled={listProp('items').length >= 8} onClick={() => setProp('items', [...listProp('items'), { label: '新分类', link_type: 'products' }])}>新增分类</Button>
    </>}

    {section.type === 'coupon' && <>
      <TextField label="区块标题" value={stringProp('title')} maxLength={20} onChange={(value) => setProp('title', value)} />
      <div style={{ marginBottom: 12 }}>
        <FieldLabel>数据来源</FieldLabel>
        <Select
          style={{ width: '100%' }}
          value={stringProp('source', 'manual')}
          options={[{ value: 'manual', label: '手动配置（展示 + 跳转）' }, { value: 'center', label: '优惠券中心（展示 + 直接领取）' }]}
          onChange={(value) => setProp('source', value)}
        />
      </div>
      {stringProp('source', 'manual') === 'center'
        ? <><NumberField label="展示数量" min={1} max={6} value={numberProp('limit', 3)} onChange={(value) => setProp('limit', value)} />
          <Typography.Text type="secondary">读取优惠券中心的可领取模板，按钮会直接调用领取接口；用户未登录时跳转登录。</Typography.Text></>
        : <>
          {listProp('items').map((item, index) => {
            const update = (key: string, value: unknown) => setProp('items', listProp('items').map((entry, entryIndex) => entryIndex === index ? { ...entry, [key]: value } : entry))
            const text = (key: string, fallback = '') => typeof item[key] === 'string' ? item[key] as string : fallback
            return <Card key={index} size="small" title={`优惠券 ${index + 1}`} extra={<Button danger size="small" onClick={() => setProp('items', listProp('items').filter((_, entryIndex) => entryIndex !== index))}>删除</Button>}>
              <TextField label="优惠券名称" value={text('title')} maxLength={16} onChange={(value) => update('title', value)} />
              <TextField label="优惠额度" value={text('value_text')} maxLength={8} placeholder="如 ¥10 / 8 折" onChange={(value) => update('value_text', value)} />
              <TextField label="使用条件" value={text('condition')} maxLength={20} placeholder="如 满 50 元可用" onChange={(value) => update('condition', value)} />
              <TextField label="按钮文字" value={text('button_text')} maxLength={10} onChange={(value) => update('button_text', value)} />
              <LinkField value={text('link_type', 'none')} onChange={(value) => update('link_type', value)} />
            </Card>
          })}
          <Button type="dashed" block disabled={listProp('items').length >= 6} onClick={() => setProp('items', [...listProp('items'), { title: '新优惠券', value_text: '¥10', condition: '满 50 元可用', button_text: '立即领取', link_type: 'products' }])}>新增优惠券</Button>
        </>}
    </>}

    {section.type === 'floating_box' && <>
      <ImageField label="悬浮图片" value={stringProp('image_url')} onChange={(value) => setProp('image_url', value)} />
      <TextField label="图片为空时的文字" value={stringProp('icon_text')} maxLength={2} onChange={(value) => setProp('icon_text', value)} />
      <NumberField label="距底部距离（rpx）" min={80} max={400} value={numberProp('bottom_rpx', 180)} onChange={(value) => setProp('bottom_rpx', value)} />
      <LinkField value={stringProp('link_type', 'none')} onChange={(value) => setProp('link_type', value)} />
    </>}

    {section.type === 'page_title' && <>
      <TextField label="标题" value={stringProp('title')} maxLength={30} onChange={(value) => setProp('title', value)} />
      <TextField label="副标题" value={stringProp('subtitle')} maxLength={60} onChange={(value) => setProp('subtitle', value)} />
      <div style={{ marginBottom: 12 }}>
        <FieldLabel>对齐方式</FieldLabel>
        <Select style={{ width: '100%' }} value={stringProp('align', 'left')} options={[{ value: 'left', label: '左对齐' }, { value: 'center', label: '居中' }]} onChange={(value) => setProp('align', value)} />
      </div>
    </>}

    {section.type === 'list_nav' && <>
      {listProp('items').map((item, index) => {
        const update = (key: string, value: unknown) => setProp('items', listProp('items').map((entry, entryIndex) => entryIndex === index ? { ...entry, [key]: value } : entry))
        const text = (key: string, fallback = '') => typeof item[key] === 'string' ? item[key] as string : fallback
        return <Card key={index} size="small" title={`入口 ${index + 1}`} extra={<Button danger size="small" onClick={() => setProp('items', listProp('items').filter((_, entryIndex) => entryIndex !== index))}>删除</Button>}>
          <ImageField label="图标" value={text('icon_url')} onChange={(value) => update('icon_url', value)} />
          <TextField label="标题" value={text('title')} maxLength={20} onChange={(value) => update('title', value)} />
          <TextField label="描述" value={text('subtitle')} maxLength={40} onChange={(value) => update('subtitle', value)} />
          <LinkField value={text('link_type', 'none')} onChange={(value) => update('link_type', value)} />
        </Card>
      })}
      <Button type="dashed" block disabled={listProp('items').length >= 6} onClick={() => setProp('items', [...listProp('items'), { icon_url: '', title: '新入口', subtitle: '', link_type: 'none' }])}>新增入口</Button>
    </>}

    {section.type === 'tag_group' && <>
      <TextField label="区块标题" value={stringProp('title')} maxLength={20} onChange={(value) => setProp('title', value)} />
      {listProp('items').map((item, index) => {
        const update = (key: string, value: unknown) => setProp('items', listProp('items').map((entry, entryIndex) => entryIndex === index ? { ...entry, [key]: value } : entry))
        return <Card key={index} size="small" title={`标签 ${index + 1}`} extra={<Button danger size="small" onClick={() => setProp('items', listProp('items').filter((_, entryIndex) => entryIndex !== index))}>删除</Button>}>
          <TextField label="标签文字" value={typeof item.label === 'string' ? item.label : ''} maxLength={12} onChange={(value) => update('label', value)} />
          <LinkField value={typeof item.link_type === 'string' ? item.link_type : 'none'} onChange={(value) => update('link_type', value)} />
        </Card>
      })}
      <Button type="dashed" block disabled={listProp('items').length >= 10} onClick={() => setProp('items', [...listProp('items'), { label: '新标签', link_type: 'none' }])}>新增标签</Button>
    </>}

    {section.type === 'image_group' && <>
      <div style={{ marginBottom: 12 }}>
        <FieldLabel>每行列数</FieldLabel>
        <Select style={{ width: '100%' }} value={numberProp('columns', 2)} options={[{ value: 2, label: '两列' }, { value: 3, label: '三列' }]} onChange={(value) => setProp('columns', value)} />
      </div>
      {listProp('items').map((item, index) => {
        const update = (key: string, value: unknown) => setProp('items', listProp('items').map((entry, entryIndex) => entryIndex === index ? { ...entry, [key]: value } : entry))
        return <Card key={index} size="small" title={`图片 ${index + 1}`} extra={<Button danger size="small" onClick={() => setProp('items', listProp('items').filter((_, entryIndex) => entryIndex !== index))}>删除</Button>}>
          <ImageField label="图片" value={typeof item.image_url === 'string' ? item.image_url : ''} onChange={(value) => update('image_url', value)} />
          <LinkField value={typeof item.link_type === 'string' ? item.link_type : 'none'} onChange={(value) => update('link_type', value)} />
        </Card>
      })}
      <Button type="dashed" block disabled={listProp('items').length >= 9} onClick={() => setProp('items', [...listProp('items'), { image_url: '', link_type: 'none' }])}>新增图片</Button>
    </>}

    {section.type === 'button_group' && <>
      {listProp('items').map((item, index) => {
        const update = (key: string, value: unknown) => setProp('items', listProp('items').map((entry, entryIndex) => entryIndex === index ? { ...entry, [key]: value } : entry))
        return <Card key={index} size="small" title={`按钮 ${index + 1}`} extra={<Button danger size="small" onClick={() => setProp('items', listProp('items').filter((_, entryIndex) => entryIndex !== index))}>删除</Button>}>
          <TextField label="按钮文字" value={typeof item.label === 'string' ? item.label : ''} maxLength={10} onChange={(value) => update('label', value)} />
          <LinkField value={typeof item.link_type === 'string' ? item.link_type : 'none'} onChange={(value) => update('link_type', value)} />
        </Card>
      })}
      <Button type="dashed" block disabled={listProp('items').length >= 3} onClick={() => setProp('items', [...listProp('items'), { label: '新按钮', link_type: 'none' }])}>新增按钮</Button>
    </>}

    {section.type === 'divider' && <>
      <div style={{ marginBottom: 12 }}>
        <FieldLabel>线条样式</FieldLabel>
        <Select style={{ width: '100%' }} value={stringProp('line_style', 'solid')} options={[{ value: 'solid', label: '实线' }, { value: 'dashed', label: '虚线' }]} onChange={(value) => setProp('line_style', value)} />
      </div>
      <ColorField label="线条颜色" value={stringProp('color')} onChange={(value) => setProp('color', value)} />
    </>}

    {section.type === 'activity_group' && <>
      <TextField label="区块标题" value={stringProp('title')} maxLength={20} onChange={(value) => setProp('title', value)} />
      {listProp('items').map((item, index) => {
        const update = (key: string, value: unknown) => setProp('items', listProp('items').map((entry, entryIndex) => entryIndex === index ? { ...entry, [key]: value } : entry))
        const text = (key: string, fallback = '') => typeof item[key] === 'string' ? item[key] as string : fallback
        return <Card key={index} size="small" title={`活动 ${index + 1}`} extra={<Button danger size="small" onClick={() => setProp('items', listProp('items').filter((_, entryIndex) => entryIndex !== index))}>删除</Button>}>
          <ImageField label="活动图片" value={text('image_url')} onChange={(value) => update('image_url', value)} />
          <TextField label="活动标题" value={text('title')} maxLength={20} onChange={(value) => update('title', value)} />
          <TextField label="活动说明" value={text('subtitle')} maxLength={40} onChange={(value) => update('subtitle', value)} />
          <LinkField value={text('link_type', 'none')} onChange={(value) => update('link_type', value)} />
        </Card>
      })}
      <Button type="dashed" block disabled={listProp('items').length >= 4} onClick={() => setProp('items', [...listProp('items'), { image_url: '', title: '新活动', subtitle: '', link_type: 'none' }])}>新增活动</Button>
    </>}

    {section.type === 'member_card' && <>
      <TextField label="标题" value={stringProp('title')} maxLength={20} onChange={(value) => setProp('title', value)} />
      <TextField label="描述" value={stringProp('description')} maxLength={60} onChange={(value) => setProp('description', value)} />
      <TextField label="按钮文字" value={stringProp('button_text')} maxLength={10} onChange={(value) => setProp('button_text', value)} />
      <LinkField value={stringProp('link_type', 'none')} onChange={(value) => setProp('link_type', value)} />
    </>}

    {section.type === 'cart_entry' && <>
      <TextField label="标题" value={stringProp('title')} maxLength={20} onChange={(value) => setProp('title', value)} />
      <TextField label="描述" value={stringProp('description')} maxLength={60} onChange={(value) => setProp('description', value)} />
      <TextField label="按钮文字" value={stringProp('button_text')} maxLength={10} onChange={(value) => setProp('button_text', value)} />
    </>}

    {section.type === 'qr_code' && <>
      <ImageField label="二维码图片" value={stringProp('image_url')} onChange={(value) => setProp('image_url', value)} />
      <TextField label="标题" value={stringProp('title')} maxLength={20} onChange={(value) => setProp('title', value)} />
      <TextField label="描述" value={stringProp('description')} maxLength={60} onChange={(value) => setProp('description', value)} />
    </>}

    {section.type === 'location_search' && <>
      <TextField label="校区文字" value={stringProp('campus_text')} maxLength={12} onChange={(value) => setProp('campus_text', value)} />
      <TextField label="搜索占位文案" value={stringProp('placeholder')} maxLength={20} onChange={(value) => setProp('placeholder', value)} />
      <LinkField value={stringProp('link_type', 'products')} onChange={(value) => setProp('link_type', value)} />
    </>}

    {section.type === 'order_marquee' && <>
      <TextField label="图标文字" value={stringProp('icon_text')} maxLength={2} onChange={(value) => setProp('icon_text', value)} />
      {listProp('items').map((item, index) => <Card key={index} size="small" title={`动态 ${index + 1}`} extra={<Button danger size="small" onClick={() => setProp('items', listProp('items').filter((_, entryIndex) => entryIndex !== index))}>删除</Button>}>
        <TextField label="动态文字" value={typeof item.text === 'string' ? item.text : ''} maxLength={40} onChange={(value) => setProp('items', listProp('items').map((entry, entryIndex) => entryIndex === index ? { ...entry, text: value } : entry))} />
      </Card>)}
      <Button type="dashed" block disabled={listProp('items').length >= 8} onClick={() => setProp('items', [...listProp('items'), { text: '新的动态播报' }])}>新增动态</Button>
      <LinkField value={stringProp('link_type', 'none')} onChange={(value) => setProp('link_type', value)} />
      <NumberField label="滚动速度（1 慢 – 5 快）" min={1} max={5} value={numberProp('speed', 3)} onChange={(value) => setProp('speed', value)} />
      <ColorField label="弹幕背景色" value={stringProp('bg_color', '#FFF8E8')} onChange={(value) => setProp('bg_color', value)} />
      <ColorField label="弹幕文字颜色" value={stringProp('text_color', '#8A6A1F')} onChange={(value) => setProp('text_color', value)} />
      <NumberField label="整体透明度（20–100）" min={20} max={100} value={numberProp('opacity', 100)} onChange={(value) => setProp('opacity', value)} />
    </>}

    {section.type === 'video' && <>
      <TextField label="视频地址" value={stringProp('video_url')} maxLength={500} placeholder="https://…（mp4）" onChange={(value) => setProp('video_url', value)} />
      <ImageField label="视频封面" value={stringProp('poster_url')} onChange={(value) => setProp('poster_url', value)} />
      <div style={{ marginBottom: 12 }}>
        <FieldLabel>视频比例</FieldLabel>
        <Select style={{ width: '100%' }} value={stringProp('ratio', 'auto')} options={[{ value: 'auto', label: '按高度设置' }, { value: '16:9', label: '16 : 9' }, { value: '4:3', label: '4 : 3' }, { value: '1:1', label: '1 : 1' }]} onChange={(value) => setProp('ratio', value)} />
      </div>
      <NumberField label="播放器高度（rpx）" min={180} max={600} value={numberProp('height_rpx', 220)} onChange={(value) => setProp('height_rpx', value)} />
    </>}

    {section.type === 'tab_nav' && <>
      <ColorField label="激活项颜色" value={stringProp('active_color', '#0BBF63')} onChange={(value) => setProp('active_color', value)} />
      <ColorField label="未激活项底色" value={stringProp('background_color', '#F2F4F8')} onChange={(value) => setProp('background_color', value)} />
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 10 }}>
        <span>滚动时吸顶</span>
        <Switch checked={props.sticky === true} onChange={(checked) => setProp('sticky', checked)} />
      </div>
      {listProp('items').map((item, index) => {
        const update = (key: string, value: unknown) => setProp('items', listProp('items').map((entry, entryIndex) => entryIndex === index ? { ...entry, [key]: value } : entry))
        return <Card key={index} size="small" title={`选项卡 ${index + 1}`} extra={<Button danger size="small" onClick={() => setProp('items', listProp('items').filter((_, entryIndex) => entryIndex !== index))}>删除</Button>}>
          <TextField label="文字" value={typeof item.label === 'string' ? item.label : ''} maxLength={8} onChange={(value) => update('label', value)} />
          <LinkField value={typeof item.link_type === 'string' ? item.link_type : 'none'} onChange={(value) => update('link_type', value)} />
        </Card>
      })}
      <Button type="dashed" block disabled={listProp('items').length >= 6} onClick={() => setProp('items', [...listProp('items'), { label: '新选项', link_type: 'none' }])}>新增选项卡</Button>
    </>}

    {section.type === 'ad_banner' && <>
      <ImageField label="广告图片" value={stringProp('image_url')} onChange={(value) => setProp('image_url', value)} />
      <LinkField value={stringProp('link_type', 'none')} onChange={(value) => setProp('link_type', value)} />
      <TextField label="自定义跳转地址" value={stringProp('link_value')} maxLength={255} placeholder="可留空" onChange={(value) => setProp('link_value', value)} />
      <NumberField label="图片高度（rpx）" min={80} max={600} value={numberProp('height_rpx', 160)} onChange={(value) => setProp('height_rpx', value)} />
      <Space><Switch checked={props.show_badge !== false} onChange={(checked) => setProp('show_badge', checked)} /><span>显示“广告”角标</span></Space>
    </>}

    {section.type === 'image_showcase' && <>
      <div style={{ marginBottom: 12 }}>
        <FieldLabel>大图位置</FieldLabel>
        <Select style={{ width: '100%' }} value={stringProp('layout', 'left')} options={[{ value: 'left', label: '左侧大图' }, { value: 'right', label: '右侧大图' }]} onChange={(value) => setProp('layout', value)} />
      </div>
      {listProp('items').slice(0, 3).map((item, index) => {
        const update = (key: string, value: unknown) => setProp('items', listProp('items').map((entry, entryIndex) => entryIndex === index ? { ...entry, [key]: value } : entry))
        return <Card key={index} size="small" title={`${index === 0 ? '大图' : `小图 ${index}`}`}>
          <ImageField label="图片" value={typeof item.image_url === 'string' ? item.image_url : ''} onChange={(value) => update('image_url', value)} />
          <TextField label="角标文字" value={typeof item.title === 'string' ? item.title : ''} maxLength={16} onChange={(value) => update('title', value)} />
          <LinkField value={typeof item.link_type === 'string' ? item.link_type : 'none'} onChange={(value) => update('link_type', value)} />
        </Card>
      })}
      <Typography.Text type="secondary">固定 3 张：第 1 张为大图，第 2、3 张为小图。</Typography.Text>
    </>}

    {section.type === 'notice_bar' && <>
      <div style={{ marginBottom: 12 }}>
        <FieldLabel>提示类型</FieldLabel>
        <Select style={{ width: '100%' }} value={stringProp('tone', 'warning')} options={[{ value: 'info', label: '信息' }, { value: 'warning', label: '警告' }, { value: 'success', label: '成功' }, { value: 'normal', label: '正常' }]} onChange={(value) => setProp('tone', value)} />
      </div>
      <TextField label="标题" value={stringProp('title')} maxLength={20} onChange={(value) => setProp('title', value)} />
      <TextField label="内容" value={stringProp('content')} maxLength={80} onChange={(value) => setProp('content', value)} />
      <LinkField value={stringProp('link_type', 'none')} onChange={(value) => setProp('link_type', value)} />
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 10 }}>
        <span>显示左侧图标</span>
        <Switch checked={props.show_icon !== false} onChange={(checked) => setProp('show_icon', checked)} />
      </div>
      <NumberField label="标题字号（rpx）" min={22} max={36} value={numberProp('title_size_rpx', 26)} onChange={(value) => setProp('title_size_rpx', value)} />
      <NumberField label="内容字号（rpx）" min={20} max={32} value={numberProp('content_size_rpx', 22)} onChange={(value) => setProp('content_size_rpx', value)} />
    </>}

    {section.type === 'hot_tags' && <>
      <TextField label="区块标题" value={stringProp('title')} maxLength={20} onChange={(value) => setProp('title', value)} />
      {listProp('items').map((item, index) => {
        const update = (key: string, value: unknown) => setProp('items', listProp('items').map((entry, entryIndex) => entryIndex === index ? { ...entry, [key]: value } : entry))
        return <Card key={index} size="small" title={`标签 ${index + 1}`} extra={<Button danger size="small" onClick={() => setProp('items', listProp('items').filter((_, entryIndex) => entryIndex !== index))}>删除</Button>}>
          <TextField label="标签文字" value={typeof item.label === 'string' ? item.label : ''} maxLength={12} onChange={(value) => update('label', value)} />
          <LinkField value={typeof item.link_type === 'string' ? item.link_type : 'none'} onChange={(value) => update('link_type', value)} />
          <Space><Switch checked={item.hot === true} onChange={(checked) => update('hot', checked)} /><span>显示 HOT 标记</span></Space>
        </Card>
      })}
      <Button type="dashed" block disabled={listProp('items').length >= 10} onClick={() => setProp('items', [...listProp('items'), { label: '新标签', link_type: 'none', hot: false }])}>新增标签</Button>
    </>}

    {section.type === 'title_bar' && <>
      <TextField label="标题" value={stringProp('title')} maxLength={20} onChange={(value) => setProp('title', value)} />
      <TextField label="副标题" value={stringProp('subtitle')} maxLength={40} onChange={(value) => setProp('subtitle', value)} />
      <TextField label="右侧文字" value={stringProp('more_text')} maxLength={8} onChange={(value) => setProp('more_text', value)} />
      <LinkField value={stringProp('link_type', 'none')} onChange={(value) => setProp('link_type', value)} />
      <NumberField label="标题字号（rpx）" min={26} max={40} value={numberProp('title_size_rpx', 32)} onChange={(value) => setProp('title_size_rpx', value)} />
      <ColorField label="标题颜色" value={stringProp('title_color', '#1F2937')} onChange={(value) => setProp('title_color', value)} />
      <ColorField label="右侧文字颜色" value={stringProp('more_color', '#0BBF63')} onChange={(value) => setProp('more_color', value)} />
    </>}

    {section.type === 'tile_ad' && <>
      <div style={{ marginBottom: 12 }}>
        <FieldLabel>拼贴布局</FieldLabel>
        <Select style={{ width: '100%' }} value={stringProp('layout', 'big-left')} options={[{ value: 'big-left', label: '左大右二' }, { value: 'big-right', label: '右大左二' }, { value: 'three', label: '三列等宽' }]} onChange={(value) => setProp('layout', value)} />
      </div>
      <NumberField label="圆角（rpx）" min={0} max={32} value={numberProp('radius_rpx', 12)} onChange={(value) => setProp('radius_rpx', value)} />
      <NumberField label="瓷片间距（rpx）" min={0} max={24} value={numberProp('gap_rpx', 8)} onChange={(value) => setProp('gap_rpx', value)} />
      <NumberField label="主标题字号（rpx）" min={24} max={40} value={numberProp('title_size_rpx', 30)} onChange={(value) => setProp('title_size_rpx', value)} />
      <ColorField label="主标题颜色" value={stringProp('title_color', '#FFFFFF')} onChange={(value) => setProp('title_color', value)} />
      <NumberField label="副标题字号（rpx）" min={20} max={30} value={numberProp('subtitle_size_rpx', 22)} onChange={(value) => setProp('subtitle_size_rpx', value)} />
      <ColorField label="副标题颜色" value={stringProp('subtitle_color', '#F2F4F8')} onChange={(value) => setProp('subtitle_color', value)} />
      <ColorField label="标签底色" value={stringProp('tag_bg', '#0BBF63')} onChange={(value) => setProp('tag_bg', value)} />
      <ColorField label="标签文字颜色" value={stringProp('tag_color', '#FFFFFF')} onChange={(value) => setProp('tag_color', value)} />
      {listProp('items').slice(0, 3).map((item, index) => {
        const update = (key: string, value: unknown) => setProp('items', listProp('items').map((entry, entryIndex) => entryIndex === index ? { ...entry, [key]: value } : entry))
        const text = (key: string, fallback = '') => typeof item[key] === 'string' ? item[key] as string : fallback
        return <Card key={index} size="small" title={index === 0 ? '瓷片 1（大图）' : `瓷片 ${index + 1}`}>
          <ImageField label="瓷片图片" value={text('image_url')} onChange={(value) => update('image_url', value)} />
          <TextField label="主标题" value={text('title')} maxLength={16} onChange={(value) => update('title', value)} />
          <TextField label="副标题" value={text('subtitle')} maxLength={24} onChange={(value) => update('subtitle', value)} />
          <TextField label="标签（可留空）" value={text('tag')} maxLength={6} onChange={(value) => update('tag', value)} />
          <LinkField value={text('link_type', 'none')} onChange={(value) => update('link_type', value)} />
        </Card>
      })}
      <Typography.Text type="secondary">固定 3 块瓷片：第 1 块为大图，第 2、3 块为小图；三列等宽时三块同尺寸。</Typography.Text>
    </>}

    {section.type === 'popup' && <>
      <ImageField label="弹窗图片" value={stringProp('image_url')} onChange={(value) => setProp('image_url', value)} />
      <TextField label="标题" value={stringProp('title')} maxLength={20} onChange={(value) => setProp('title', value)} />
      <TextField label="内容" value={stringProp('content')} maxLength={60} textarea onChange={(value) => setProp('content', value)} />
      <TextField label="按钮文字" value={stringProp('button_text')} maxLength={10} onChange={(value) => setProp('button_text', value)} />
      <LinkField value={stringProp('link_type', 'none')} onChange={(value) => setProp('link_type', value)} />
      <div style={{ marginBottom: 12 }}>
        <FieldLabel>展示频次</FieldLabel>
        <Select style={{ width: '100%' }} value={stringProp('frequency', 'daily')} options={[{ value: 'once', label: '仅展示一次' }, { value: 'daily', label: '每天一次' }, { value: 'every', label: '每次进入' }]} onChange={(value) => setProp('frequency', value)} />
      </div>
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 10 }}>
        <span>允许关闭</span>
        <Switch checked={props.closable !== false} onChange={(checked) => setProp('closable', checked)} />
      </div>
      <NumberField label="弹窗宽度（rpx）" min={480} max={660} value={numberProp('width_rpx', 560)} onChange={(value) => setProp('width_rpx', value)} />
      <NumberField label="弹窗圆角（rpx）" min={0} max={32} value={numberProp('radius_rpx', 20)} onChange={(value) => setProp('radius_rpx', value)} />
    </>}

    {section.type === 'brand_card' && <>
      <TextField label="Logo 文字（图片为空时显示）" value={stringProp('logo_text')} maxLength={4} onChange={(value) => setProp('logo_text', value)} />
      <ImageField label="Logo 图片" value={stringProp('logo_url')} onChange={(value) => setProp('logo_url', value)} />
      <TextField label="品牌名称" value={stringProp('name')} maxLength={20} onChange={(value) => setProp('name', value)} />
      <TextField label="品牌标语" value={stringProp('caption')} maxLength={40} onChange={(value) => setProp('caption', value)} />
      <LinkField value={stringProp('link_type', 'none')} onChange={(value) => setProp('link_type', value)} />
    </>}

    {section.type === 'point_card' && <>
      <TextField label="积分标签" value={stringProp('points_label')} maxLength={12} onChange={(value) => setProp('points_label', value)} />
      <TextField label="签到按钮文字" value={stringProp('sign_button_text')} maxLength={10} onChange={(value) => setProp('sign_button_text', value)} />
      <LinkField value={stringProp('link_type', 'assets')} onChange={(value) => setProp('link_type', value)} />
    </>}

    {section.type === 'recommendation_card' && <>
      <TextField label="区块标题" value={stringProp('section_title')} maxLength={20} onChange={(value) => setProp('section_title', value)} />
      <TextField label="区块副标题" value={stringProp('section_subtitle')} maxLength={40} onChange={(value) => setProp('section_subtitle', value)} />
      <TextField label="推荐卡标题" value={stringProp('title')} maxLength={30} onChange={(value) => setProp('title', value)} />
      <TextField label="推荐卡描述" value={stringProp('description')} maxLength={120} textarea onChange={(value) => setProp('description', value)} />
      <TextField label="标签文字" value={stringProp('tag')} maxLength={6} onChange={(value) => setProp('tag', value)} />
      <TextField label="奖励文案" value={stringProp('reward_text')} maxLength={20} onChange={(value) => setProp('reward_text', value)} />
      <TextField label="时长文案" value={stringProp('time_text')} maxLength={12} onChange={(value) => setProp('time_text', value)} />
      <TextField label="图标文字" value={stringProp('icon_text')} maxLength={2} onChange={(value) => setProp('icon_text', value)} />
      <ImageField label="图标图片" value={stringProp('icon_url')} onChange={(value) => setProp('icon_url', value)} />
      <ImageField label="背景图片" value={stringProp('background_url')} onChange={(value) => setProp('background_url', value)} />
      <LinkField value={stringProp('link_type', 'tasks')} onChange={(value) => setProp('link_type', value)} />
    </>}

    {section.type === 'product_section' && <>
      <TextField label="区块标题" value={stringProp('section_title')} maxLength={20} onChange={(value) => setProp('section_title', value)} />
      <TextField label="区块副标题" value={stringProp('section_subtitle')} maxLength={40} onChange={(value) => setProp('section_subtitle', value)} />
      {listProp('cards').map((item, index) => {
        const update = (key: string, value: unknown) => setProp('cards', listProp('cards').map((entry, entryIndex) => entryIndex === index ? { ...entry, [key]: value } : entry))
        const text = (key: string, fallback = '') => typeof item[key] === 'string' ? item[key] as string : fallback
        return <Card key={index} size="small" title={`兑换卡 ${index + 1}`}>
          <TextField label="眉标" value={text('eyebrow')} maxLength={8} onChange={(value) => update('eyebrow', value)} />
          <TextField label="标题" value={text('title')} maxLength={12} onChange={(value) => update('title', value)} />
          <TextField label="价格文案" value={text('price_text')} maxLength={12} onChange={(value) => update('price_text', value)} />
          <div style={{ marginBottom: 12 }}>
            <FieldLabel>配色</FieldLabel>
            <Select style={{ width: '100%' }} value={text('tone', 'blue')} options={[{ value: 'blue', label: '蓝' }, { value: 'warm', label: '暖黄' }, { value: 'green', label: '绿' }, { value: 'orange', label: '橙' }]} onChange={(value) => update('tone', value)} />
          </div>
          <ImageField label="卡片图片" value={text('image_url')} onChange={(value) => update('image_url', value)} />
          <ImageField label="背景图片" value={text('background_url')} onChange={(value) => update('background_url', value)} />
          <LinkField value={text('link_type', 'products')} onChange={(value) => update('link_type', value)} />
        </Card>
      })}
    </>}

    {section.type === 'trust_card' && <>
      <TextField label="标题" value={stringProp('title')} maxLength={30} onChange={(value) => setProp('title', value)} />
      <TextField label="描述" value={stringProp('description')} maxLength={120} textarea onChange={(value) => setProp('description', value)} />
      <ImageField label="图标图片" value={stringProp('icon_url')} onChange={(value) => setProp('icon_url', value)} />
      <ImageField label="背景图片" value={stringProp('background_url')} onChange={(value) => setProp('background_url', value)} />
      <LinkField value={stringProp('link_type', 'assets')} onChange={(value) => setProp('link_type', value)} />
    </>}

    <StyleEditor style={style} onChange={updateStyle} />
  </Space>
}
