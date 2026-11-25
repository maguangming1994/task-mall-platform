import {
  Alert,
  Button,
  Card,
  Form,
  Input,
  InputNumber,
  Image,
  Modal,
  Popconfirm,
  Select,
  Space,
  Switch,
  Table,
  Tag,
  Typography,
  message,
} from 'antd'
import { useCallback, useEffect, useState } from 'react'
import {
  ApiError,
  createSettingRollback,
  listSettings,
  listSettingVersions,
  publishSetting,
  reviewSetting,
  saveSettingDraft,
  type SettingValue,
  type SettingVersion,
  type SystemSetting,
} from '../../api/admin'
import { MediaGalleryInput } from '../../components/MediaGalleryInput'
import { RichTextEditor } from '../../components/RichTextEditor'
import { useAdminAuth } from '../auth/AdminAuthContext'
import { IconConfigPanel } from './IconConfigPanel'
import { StorageManagementPanel } from './StorageManagementPanel'
import { ThirdPartyServicesPanel } from './ThirdPartyServicesPanel'
import navAccountActive from '../../../../user-app/src/static/tabbar/account-active.png'
import navAccount from '../../../../user-app/src/static/tabbar/account.png'
import navCommunityActive from '../../../../user-app/src/static/tabbar/community-active.png'
import navCommunity from '../../../../user-app/src/static/tabbar/community.png'
import navHomeActive from '../../../../user-app/src/static/tabbar/home-active.png'
import navHome from '../../../../user-app/src/static/tabbar/home.png'
import navMallActive from '../../../../user-app/src/static/tabbar/mall-active.png'
import navMall from '../../../../user-app/src/static/tabbar/mall.png'
import navTasksActive from '../../../../user-app/src/static/tabbar/tasks-active.png'
import navTasks from '../../../../user-app/src/static/tabbar/tasks.png'

const groupNames: Record<string, string> = {
  accounts: '账号', after_sales: '售后', community: '社区', coupons: '优惠券', notifications: '通知', orders: '订单',
  payment: '支付', points: '积分', risk: '风控', tasks: '任务', wallet: '钱包', mall: '商城装修', homepage: '首页装修', navigation: '导航装修',
}

function displayValue(setting: SystemSetting, value: SettingValue) {
  if (setting.value_type === 'boolean') return value ? '启用' : '停用'
  if (setting.value_type === 'money' && typeof value === 'number') return `¥${(value / 100).toFixed(2)}`
  if (Array.isArray(value)) return value.join('、') || '—'
  if (typeof value === 'object') {
    if (setting.key === 'homepage.brand') return `${String(value.name || '未命名')} · ${value.logo_url ? '图片 Logo' : '文字 Logo'}`
    if (setting.key === 'homepage.carousel') return `${Array.isArray(value.items) ? value.items.length : 0} 张轮播图`
    if (setting.key === 'homepage.announcement') return value.enabled ? String(value.title || '公告已启用') : '已停用'
    if (setting.key === 'homepage.popup') return value.enabled ? `${value.mode === 'poster' ? '海报' : '公告'}弹窗` : '已停用'
    if (setting.key === 'homepage.floating_support') return value.enabled ? `${String(value.label || '客服二维码')} · ${value.qr_code_url ? '已上传二维码' : '待上传二维码'}` : '已停用'
    if (setting.key === 'homepage.modules') return `${Array.isArray(value.quick_entries) ? value.quick_entries.length : 0} 个快捷入口 · ${Array.isArray((value.product_section as Record<string, unknown> | undefined)?.cards) ? ((value.product_section as Record<string, unknown>).cards as unknown[]).length : 0} 个商城推荐位`
    if (setting.key === 'community.homepage') return `${Array.isArray(value.quick_entries) ? value.quick_entries.length : 0} 个社区入口 · 品牌图标可更换`
    if (setting.key === 'points.source_reward_amounts') return `签到 ${Number(value.sign_in ?? 0)} · 发帖 ${Number(value.post ?? 0)} · 评论 ${Number(value.comment ?? 0)} · 完善资料 ${Number(value.profile ?? 0)} · 推荐 ${Number(value.referral ?? 0)} 积分`
    if (setting.key === 'mall.appearance') return `${Array.isArray(value.assurances) ? value.assurances.length : 0} 个服务图标 · 商城 Logo 可更换`
    if (setting.key === 'accounts.appearance') return `${Array.isArray(value.service_icons) ? value.service_icons.length : 0} 个账户服务图标`
    if (setting.key === 'navigation.bottom_tabs') return `${Array.isArray(value.items) ? (value.items as Array<Record<string, unknown>>).filter((item) => item.enabled).length : 0} 个显示入口 · 支持排序与跳转配置`
    return JSON.stringify(value)
  }
  return `${String(value)}${setting.unit ? ` ${setting.unit}` : ''}`
}

function editorInitialValue(setting: SystemSetting) {
  const value = setting.draft?.value ?? setting.current_value
  if (setting.value_type === 'money' && typeof value === 'number') return value / 100
  if (setting.value_type === 'object' && !setting.key.startsWith('homepage.') && !setting.key.startsWith('navigation.') && !['community.homepage', 'mall.appearance', 'accounts.appearance', 'points.source_reward_amounts'].includes(setting.key)) return JSON.stringify(value, null, 2)
  return value
}

function normalizeEditorValue(setting: SystemSetting, value: unknown): SettingValue {
  if (setting.value_type === 'money') return Math.round(Number(value) * 100)
  if (setting.value_type === 'object') return typeof value === 'object' && value !== null && !Array.isArray(value)
    ? value as Record<string, unknown>
    : JSON.parse(String(value)) as Record<string, unknown>
  return value as SettingValue
}

const linkOptions = [
  { value: 'none', label: '无跳转' },
  { value: 'tasks', label: '任务大厅' },
  { value: 'products', label: '积分商城' },
  { value: 'community', label: '社区' },
  { value: 'notifications', label: '消息通知' },
  { value: 'orders', label: '我的订单' },
  { value: 'assets', label: '我的资产' },
  { value: 'push', label: '地推中心' },
]

const navigationTargetOptions = [
  { value: 'home', label: '首页' },
  { value: 'tasks', label: '任务大厅' },
  { value: 'products', label: '商城' },
  { value: 'community', label: '社区' },
  { value: 'account', label: '我的账户' },
]

interface ValueEditorProps {
  setting: SystemSetting
  value?: SettingValue
  onChange?: (value: SettingValue) => void
}

function HomeImageInput({ url, label, defaultUrl, onChange }: { url: string; label: string; defaultUrl?: string; onChange: (url: string) => void }) {
  return <Space direction="vertical" style={{ width: '100%' }}>
    {!url && defaultUrl && <div style={{ display: 'flex', alignItems: 'center', gap: 12, padding: 10, border: '1px dashed #b7c8ec', borderRadius: 10, background: '#f7f9ff' }}>
      <Image width={72} height={72} src={defaultUrl} preview={{ mask: '放大预览' }} style={{ objectFit: 'contain', padding: 12, borderRadius: 8, background: '#fff' }} />
      <Space direction="vertical" size={2}>
        <Tag color="blue">系统默认</Tag>
        <Typography.Text>{label}</Typography.Text>
        <Typography.Text type="secondary">上传新图后会立即替换此预览；删除新图即可恢复默认。</Typography.Text>
      </Space>
    </div>}
    <MediaGalleryInput
      value={url ? [{ url, alt: label, sort_order: 0 }] : []}
      onChange={(images) => onChange(images[0]?.url ?? '')}
      max={1}
      primaryLabel={label}
      helperText={url ? '当前显示的是新图片；点击缩略图可放大预览，删除后恢复系统默认。' : '支持本地上传、HTTPS 链接转存或直接外链；添加后会立即显示预览。'}
    />
  </Space>
}

const defaultNavigationIcons: Record<string, { normal: string; active: string }> = {
  home: { normal: navHome, active: navHomeActive },
  tasks: { normal: navTasks, active: navTasksActive },
  products: { normal: navMall, active: navMallActive },
  community: { normal: navCommunity, active: navCommunityActive },
  account: { normal: navAccount, active: navAccountActive },
}

function defaultHomePreview(symbol: string, label: string, background = '#EEF4FF', foreground = '#2F67DF') {
  const escape = (text: string) => text.replace(/[&<>"']/g, (character) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&apos;' })[character] ?? character)
  const safeSymbol = escape(symbol.slice(0, 4))
  const safeLabel = escape(label.slice(0, 12))
  const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="320" height="200" viewBox="0 0 320 200"><rect width="320" height="200" rx="28" fill="${background}"/><circle cx="160" cy="80" r="48" fill="#fff" fill-opacity=".88"/><text x="160" y="93" text-anchor="middle" font-family="Arial,'Microsoft YaHei',sans-serif" font-size="34" font-weight="700" fill="${foreground}">${safeSymbol}</text><text x="160" y="158" text-anchor="middle" font-family="Arial,'Microsoft YaHei',sans-serif" font-size="22" font-weight="600" fill="${foreground}">${safeLabel}</text></svg>`
  return `data:image/svg+xml;charset=UTF-8,${encodeURIComponent(svg)}`
}

function BottomNavigationSettingEditor({ value, onChange }: ValueEditorProps) {
  const current = typeof value === 'object' && value !== null && !Array.isArray(value) ? value : {}
  const items = Array.isArray(current.items) ? current.items as Record<string, unknown>[] : []
  const style = typeof current.style === 'object' && current.style !== null && !Array.isArray(current.style) ? current.style as Record<string, unknown> : {}
  const update = (key: string, next: unknown) => onChange?.({ ...current, [key]: next })
  const updateItem = (index: number, key: string, next: unknown) => update('items', items.map((item, itemIndex) => itemIndex === index ? { ...item, [key]: next } : item))
  const updateStyle = (key: string, next: string) => update('style', { ...style, [key]: next })
  const enabledItems = items.filter((item) => item.enabled)
  const [previewActive, setPreviewActive] = useState(String(enabledItems[0]?.target ?? 'home'))
  const move = (index: number, offset: number) => {
    const target = index + offset
    if (target < 0 || target >= items.length) return
    const next = [...items]
    ;[next[index], next[target]] = [next[target], next[index]]
    update('items', next)
  }

  return <Space direction="vertical" style={{ width: '100%' }} size="middle">
    <Alert showIcon type="info" message="位置由列表顺序决定；至少保留 3 个入口。H5 与微信小程序共用此配置，图标建议使用透明背景的正方形 PNG。" />
    <Card size="small" title="底部导航实时预览" extra={<Typography.Text type="secondary">点击图标可切换选中状态</Typography.Text>}>
      <div style={{ display: 'flex', maxWidth: 620, minHeight: 78, margin: '0 auto', border: `1px solid ${String(style.border_color || '#E7EBF2')}`, borderRadius: 14, overflow: 'hidden', background: String(style.background_color || '#FFFFFF'), boxShadow: '0 8px 24px rgba(29,43,73,.08)' }}>
        {enabledItems.map((item) => {
          const target = String(item.target ?? 'home')
          const active = previewActive === target
          const defaults = defaultNavigationIcons[target] ?? defaultNavigationIcons.home
          const source = active ? String(item.active_icon_url || defaults.active) : String(item.icon_url || defaults.normal)
          return <button key={String(item.id)} type="button" onClick={() => setPreviewActive(target)} style={{ position: 'relative', display: 'flex', minWidth: 0, flex: 1, alignItems: 'center', justifyContent: 'center', flexDirection: 'column', gap: 4, padding: '10px 4px 8px', border: 0, background: 'transparent', color: active ? String(style.active_color || '#2F67DF') : String(style.text_color || '#7A859B'), cursor: 'pointer' }}>
            {active && <span style={{ position: 'absolute', top: 0, width: 28, height: 3, borderRadius: '0 0 4px 4px', background: String(style.active_color || '#2F67DF') }} />}
            <img src={source} alt={`${String(item.label)}${active ? '选中' : '默认'}图标`} style={{ width: 30, height: 30, objectFit: 'contain' }} />
            <span style={{ maxWidth: '100%', overflow: 'hidden', fontSize: 12, fontWeight: 600, textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{String(item.label ?? '')}</span>
          </button>
        })}
      </div>
    </Card>
    <Card size="small" title="导航颜色">
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(210px,1fr))', gap: 12 }}>
        {([
          ['background_color', '背景颜色'],
          ['text_color', '默认文字与图标颜色'],
          ['active_color', '选中文字与图标颜色'],
          ['border_color', '顶部边框颜色'],
        ] as const).map(([key, label]) => <Input key={key} addonBefore={label} value={String(style[key] ?? '')} maxLength={7} placeholder="#FFFFFF" onChange={(event) => updateStyle(key, event.target.value.trim())} />)}
      </div>
    </Card>
    {items.map((item, index) => <Card
      key={String(item.id ?? index)}
      size="small"
      title={`位置 ${index + 1} · ${String(item.label || '未命名')}`}
      extra={<Space><Button size="small" disabled={index === 0} onClick={() => move(index, -1)}>上移</Button><Button size="small" disabled={index === items.length - 1} onClick={() => move(index, 1)}>下移</Button></Space>}
    >
      <Space direction="vertical" style={{ width: '100%' }}>
        <Space><Switch checked={Boolean(item.enabled)} onChange={(checked) => updateItem(index, 'enabled', checked)} /><span>在底部导航显示</span></Space>
        <Input value={String(item.label ?? '')} maxLength={8} showCount placeholder="导航文字" onChange={(event) => updateItem(index, 'label', event.target.value)} />
        <Select style={{ width: '100%' }} value={String(item.target ?? '')} options={navigationTargetOptions} placeholder="点击后的跳转页面" onChange={(next) => updateItem(index, 'target', next)} />
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(300px,1fr))', gap: 12 }}>
          <div><Typography.Text strong>默认图标</Typography.Text><HomeImageInput url={String(item.icon_url ?? '')} defaultUrl={(defaultNavigationIcons[String(item.target)] ?? defaultNavigationIcons.home).normal} label={`${String(item.label ?? '')}默认图标`} onChange={(url) => updateItem(index, 'icon_url', url)} /></div>
          <div><Typography.Text strong>选中图标</Typography.Text><HomeImageInput url={String(item.active_icon_url ?? '')} defaultUrl={(defaultNavigationIcons[String(item.target)] ?? defaultNavigationIcons.home).active} label={`${String(item.label ?? '')}选中图标`} onChange={(url) => updateItem(index, 'active_icon_url', url)} /></div>
        </div>
      </Space>
    </Card>)}
  </Space>
}

function HomeSettingEditor({ setting, value, onChange }: ValueEditorProps) {
  const current = typeof value === 'object' && value !== null && !Array.isArray(value) ? value : {}
  const update = (key: string, next: unknown) => onChange?.({ ...current, [key]: next })
  const textField = (key: string, placeholder: string) => <Input value={String(current[key] ?? '')} placeholder={placeholder} onChange={(event) => update(key, event.target.value)} />

  if (setting.key === 'homepage.brand') return <Space direction="vertical" style={{ width: '100%' }}>
    <Typography.Text strong>品牌 Logo</Typography.Text>
    <HomeImageInput url={String(current.logo_url ?? '')} defaultUrl={defaultHomePreview(String(current.logo_text || 'TM'), '品牌 Logo')} label="品牌 Logo" onChange={(url) => update('logo_url', url)} />
    {textField('logo_text', '图片为空时显示的 Logo 文字，例如 TM')}
    {textField('name', '品牌名称')}
    {textField('caption', '品牌英文或副标题')}
  </Space>

  if (setting.key === 'homepage.announcement') return <Space direction="vertical" style={{ width: '100%' }}>
    <Space><Switch checked={Boolean(current.enabled)} onChange={(checked) => update('enabled', checked)} /><span>显示首页公告栏</span></Space>
    <Typography.Text strong>公告图标</Typography.Text>
    <HomeImageInput url={String(current.icon_url ?? '')} defaultUrl={defaultHomePreview(String(current.icon_text || '公'), '公告图标', '#FFF2B8', '#8A5C00')} label="公告图标" onChange={(url) => update('icon_url', url)} />
    {textField('icon_text', '公告图标文字，例如 公')}
    {textField('title', '公告标题')}
    <Typography.Text strong>公告内容</Typography.Text>
    <RichTextEditor compact value={String(current.content ?? '')} minHeight={180} placeholder="请输入公告内容，可设置加粗、列表、对齐和链接…" onChange={(html) => update('content', html)} />
    <Typography.Text type="secondary">内容保存时会进行安全过滤；用户端公告栏会按富文本格式展示并限制在公告区域内。</Typography.Text>
    <Select value={String(current.link_type ?? 'none')} options={linkOptions} onChange={(next) => update('link_type', next)} />
  </Space>

  if (setting.key === 'homepage.popup') return <Space direction="vertical" style={{ width: '100%' }}>
    <Space><Switch checked={Boolean(current.enabled)} onChange={(checked) => update('enabled', checked)} /><span>启用首页弹窗</span></Space>
    <Space><Switch checked={Boolean(current.display_once)} onChange={(checked) => update('display_once', checked)} /><span>同一内容每台设备只展示一次</span></Space>
    {textField('content_id', '内容版本标识；修改后会重新展示，例如 summer-2026')}
    <Select value={String(current.mode ?? 'announcement')} options={[{ value: 'announcement', label: '公告弹窗' }, { value: 'poster', label: '海报弹窗' }]} onChange={(next) => update('mode', next)} />
    {textField('title', '弹窗标题')}
    <Input.TextArea value={String(current.content ?? '')} rows={3} placeholder="弹窗正文" onChange={(event) => update('content', event.target.value)} />
    <Typography.Text strong>弹窗海报图片</Typography.Text>
    <HomeImageInput url={String(current.image_url ?? '')} defaultUrl={defaultHomePreview(current.mode === 'poster' ? '报' : '公', current.mode === 'poster' ? '默认海报' : '公告弹窗', '#FFF1E4', '#E56A16')} label="弹窗海报" onChange={(url) => update('image_url', url)} />
    {textField('button_text', '按钮文字')}
    <Select value={String(current.link_type ?? 'none')} options={linkOptions} onChange={(next) => update('link_type', next)} />
  </Space>

  if (setting.key === 'homepage.floating_support') return <Space direction="vertical" style={{ width: '100%' }} size="middle">
    <Alert showIcon type="info" message="此入口仅用于展示客服小程序二维码。移动端首页悬浮按钮和“我的—联系客服”都会进入同一个二维码页面。" />
    <Space><Switch checked={Boolean(current.enabled)} onChange={(checked) => update('enabled', checked)} /><span>显示首页悬浮客服二维码</span></Space>
    <Space><Switch checked={Boolean(current.show_label)} onChange={(checked) => update('show_label', checked)} /><span>显示客服名称与副标题</span></Space>
    <Typography.Text strong>客服悬浮图标</Typography.Text>
    <HomeImageInput url={String(current.icon_url ?? '')} defaultUrl={defaultHomePreview(String(current.icon_text || '服'), '悬浮客服图标', '#DFF8EF', '#087A53')} label="悬浮客服图标" onChange={(url) => update('icon_url', url)} />
    {textField('icon_text', '未上传图片时显示的图标文字，例如 服')}
    {textField('label', '按钮与二维码页标题，例如 客服二维码')}
    {textField('subtitle', '按钮副标题，例如 扫码联系校园客服')}
    <Typography.Text strong>客服小程序二维码</Typography.Text>
    <HomeImageInput url={String(current.qr_code_url ?? '')} defaultUrl={defaultHomePreview('码', '客服小程序二维码', '#F3FBFF', '#147CB1')} label="客服小程序二维码" onChange={(url) => update('qr_code_url', url)} />
    {textField('qr_code_tip', '扫码说明，例如 长按二维码识别，或使用微信扫一扫')}
    <Space wrap>
      <Select style={{ width: 180 }} value={String(current.position ?? 'right')} options={[{ value: 'right', label: '悬浮在右侧' }, { value: 'left', label: '悬浮在左侧' }]} onChange={(next) => update('position', next)} />
      <InputNumber min={130} max={400} step={10} addonBefore="距底部" addonAfter="rpx" value={Number(current.bottom_offset_rpx ?? 170)} onChange={(next) => update('bottom_offset_rpx', next ?? 170)} />
    </Space>
  </Space>

  if (setting.key === 'homepage.carousel') {
    const items = Array.isArray(current.items) ? current.items as Record<string, unknown>[] : []
    const updateItem = (index: number, key: string, next: unknown) => update('items', items.map((item, itemIndex) => itemIndex === index ? { ...item, [key]: next } : item))
    const removeItem = (index: number) => update('items', items.filter((_, itemIndex) => itemIndex !== index))
    return <Space direction="vertical" style={{ width: '100%' }} size="middle">
      <Space><Switch checked={Boolean(current.autoplay)} onChange={(checked) => update('autoplay', checked)} /><span>自动轮播</span></Space>
      <InputNumber min={1500} max={15000} step={500} addonAfter="毫秒" value={Number(current.interval_ms || 4500)} onChange={(next) => update('interval_ms', next ?? 4500)} />
      {items.map((item, index) => <Card key={String(item.id || index)} size="small" title={`轮播图 ${index + 1}`} extra={<Button danger size="small" onClick={() => removeItem(index)}>删除</Button>}>
        <Space direction="vertical" style={{ width: '100%' }}>
          <Input value={String(item.id ?? '')} placeholder="唯一标识" onChange={(event) => updateItem(index, 'id', event.target.value)} />
          <Typography.Text strong>轮播背景图</Typography.Text>
          <HomeImageInput url={String(item.image_url ?? '')} defaultUrl={defaultHomePreview(String(index + 1), String(item.title || `轮播图 ${index + 1}`), index % 2 === 0 ? '#E8F0FF' : '#EEF7FF', '#2455C3')} label={`轮播背景 ${index + 1}`} onChange={(url) => updateItem(index, 'image_url', url)} />
          <Input value={String(item.kicker ?? '')} placeholder="眉标题" onChange={(event) => updateItem(index, 'kicker', event.target.value)} />
          <Input value={String(item.title ?? '')} placeholder="主标题" onChange={(event) => updateItem(index, 'title', event.target.value)} />
          <Input.TextArea value={String(item.description ?? '')} rows={2} placeholder="说明" onChange={(event) => updateItem(index, 'description', event.target.value)} />
          <Input value={String(item.button_text ?? '')} placeholder="按钮文字" onChange={(event) => updateItem(index, 'button_text', event.target.value)} />
          <Select value={String(item.link_type ?? 'none')} options={linkOptions} onChange={(next) => updateItem(index, 'link_type', next)} />
        </Space>
      </Card>)}
      <Button type="dashed" block onClick={() => update('items', [...items, { id: `slide-${Date.now()}`, image_url: '', kicker: '', title: '新轮播图', description: '', button_text: '查看详情', link_type: 'none' }])}>新增轮播图</Button>
    </Space>
  }

  if (setting.key === 'homepage.modules') {
    const quickEntries = Array.isArray(current.quick_entries) ? current.quick_entries as Record<string, unknown>[] : []
    const priorityEntries = Array.isArray(current.priority_entries) && current.priority_entries.length > 0
      ? current.priority_entries as Record<string, unknown>[]
      : [{ id: 'assets', mark: '资', icon_url: '' }, { id: 'referrals', mark: '邀', icon_url: '' }]
    const recommendation = typeof current.recommendation === 'object' && current.recommendation !== null ? current.recommendation as Record<string, unknown> : {}
    const productSection = typeof current.product_section === 'object' && current.product_section !== null ? current.product_section as Record<string, unknown> : {}
    const productCards = Array.isArray(productSection.cards) ? productSection.cards as Record<string, unknown>[] : []
    const trust = typeof current.trust === 'object' && current.trust !== null ? current.trust as Record<string, unknown> : {}
    const updateQuick = (index: number, key: string, next: unknown) => update('quick_entries', quickEntries.map((item, itemIndex) => itemIndex === index ? { ...item, [key]: next } : item))
    const updatePriority = (index: number, key: string, next: unknown) => update('priority_entries', priorityEntries.map((item, itemIndex) => itemIndex === index ? { ...item, [key]: next } : item))
    const updateRecommendation = (key: string, next: unknown) => update('recommendation', { ...recommendation, [key]: next })
    const updateProductSection = (key: string, next: unknown) => update('product_section', { ...productSection, [key]: next })
    const updateProductCard = (index: number, key: string, next: unknown) => updateProductSection('cards', productCards.map((item, itemIndex) => itemIndex === index ? { ...item, [key]: next } : item))
    const updateTrust = (key: string, next: unknown) => update('trust', { ...trust, [key]: next })
    return <Space direction="vertical" style={{ width: '100%' }} size="middle">
      <Card size="small" title="首页整体背景与顶部图标">
        <Space direction="vertical" style={{ width: '100%' }}>
          <Typography.Text strong>首页背景图</Typography.Text>
          <HomeImageInput url={String(current.page_background_url ?? '')} defaultUrl={defaultHomePreview('底', '首页默认背景', '#F6F8FC', '#748099')} label="首页背景" onChange={(url) => update('page_background_url', url)} />
          <Typography.Text strong>右上角消息通知图标</Typography.Text>
          <HomeImageInput url={String(current.notification_icon_url ?? '')} defaultUrl={defaultHomePreview('铃', '消息通知图标')} label="通知图标" onChange={(url) => update('notification_icon_url', url)} />
          <Typography.Text strong>快捷入口区域背景图</Typography.Text>
          <HomeImageInput url={String(current.quick_grid_background_url ?? '')} defaultUrl={defaultHomePreview('格', '快捷入口背景', '#FFFFFF', '#3C4961')} label="快捷入口背景" onChange={(url) => update('quick_grid_background_url', url)} />
        </Space>
      </Card>
      <Card size="small" title="四个快捷入口图标">
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(320px,1fr))', gap: 12 }}>
          {quickEntries.map((item, index) => <Card key={String(item.id ?? index)} size="small" title={String(item.label || `入口 ${index + 1}`)}>
            <Space direction="vertical" style={{ width: '100%' }}>
              <HomeImageInput url={String(item.icon_url ?? '')} defaultUrl={defaultHomePreview(String(item.mark || index + 1), String(item.label || `快捷入口 ${index + 1}`), ['#EEF4FF', '#FFF1E4', '#E9F8F3', '#F1EDFF'][index] || '#EEF4FF', ['#2F67DF', '#E56A16', '#15966A', '#7257C8'][index] || '#2F67DF')} label={`${String(item.label ?? '')}图标`} onChange={(url) => updateQuick(index, 'icon_url', url)} />
              <Input value={String(item.label ?? '')} placeholder="入口名称" onChange={(event) => updateQuick(index, 'label', event.target.value)} />
              <Input value={String(item.note ?? '')} placeholder="入口说明" onChange={(event) => updateQuick(index, 'note', event.target.value)} />
              <Input value={String(item.mark ?? '')} placeholder="未上传图标时显示的文字" onChange={(event) => updateQuick(index, 'mark', event.target.value)} />
              <Select value={String(item.link_type ?? 'none')} options={linkOptions} onChange={(next) => updateQuick(index, 'link_type', next)} />
            </Space>
          </Card>)}
        </div>
      </Card>
      <Card size="small" title="公告下方资产与邀请图标">
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(320px,1fr))', gap: 12 }}>
          {priorityEntries.map((item, index) => <Card key={String(item.id ?? index)} size="small" title={String(item.id) === 'assets' ? '资产中心' : '邀请成长'}>
            <Space direction="vertical" style={{ width: '100%' }}>
              <HomeImageInput url={String(item.icon_url ?? '')} defaultUrl={defaultHomePreview(String(item.mark || (index ? '邀' : '资')), index ? '邀请成长图标' : '资产中心图标', index ? '#FFF2B8' : '#DFF8EC', index ? '#8A5C00' : '#0C7B4B')} label={index ? '邀请成长图标' : '资产中心图标'} onChange={(url) => updatePriority(index, 'icon_url', url)} />
              <Input value={String(item.mark ?? '')} placeholder="未上传图片时显示的文字" onChange={(event) => updatePriority(index, 'mark', event.target.value)} />
            </Space>
          </Card>)}
        </div>
      </Card>
      <Card size="small" title="今日推荐区块">
        <Space direction="vertical" style={{ width: '100%' }}>
          <HomeImageInput url={String(recommendation.icon_url ?? '')} defaultUrl={defaultHomePreview(String(recommendation.icon_text || '新'), '推荐任务图标')} label="推荐任务图标" onChange={(url) => updateRecommendation('icon_url', url)} />
          <HomeImageInput url={String(recommendation.background_url ?? '')} defaultUrl={defaultHomePreview('荐', '推荐卡片背景', '#FFFFFF', '#2455C3')} label="推荐卡片背景" onChange={(url) => updateRecommendation('background_url', url)} />
          {(['section_title', 'section_subtitle', 'title', 'description', 'tag', 'reward_text', 'time_text', 'icon_text'] as const).map((key) => <Input key={key} value={String(recommendation[key] ?? '')} placeholder={{ section_title: '区块标题', section_subtitle: '区块副标题', title: '推荐标题', description: '推荐说明', tag: '标签', reward_text: '奖励文字', time_text: '耗时文字', icon_text: '无图片时的图标文字' }[key]} onChange={(event) => updateRecommendation(key, event.target.value)} />)}
          <Select value={String(recommendation.link_type ?? 'tasks')} options={linkOptions} onChange={(next) => updateRecommendation('link_type', next)} />
        </Space>
      </Card>
      <Card size="small" title="精选兑换区块">
        <Space direction="vertical" style={{ width: '100%' }}>
          <Input value={String(productSection.section_title ?? '')} placeholder="区块标题" onChange={(event) => updateProductSection('section_title', event.target.value)} />
          <Input value={String(productSection.section_subtitle ?? '')} placeholder="区块副标题" onChange={(event) => updateProductSection('section_subtitle', event.target.value)} />
          {productCards.map((item, index) => <Card key={String(item.id ?? index)} size="small" title={`推荐商品卡片 ${index + 1}`}>
            <Space direction="vertical" style={{ width: '100%' }}>
              <HomeImageInput url={String(item.image_url ?? '')} defaultUrl={defaultHomePreview(index === 0 ? '杯' : '礼', String(item.title || `商品装饰 ${index + 1}`), index === 0 ? '#E8F0FF' : '#FFF0DF', index === 0 ? '#2F67DF' : '#E56A16')} label={`商品装饰图 ${index + 1}`} onChange={(url) => updateProductCard(index, 'image_url', url)} />
              <HomeImageInput url={String(item.background_url ?? '')} defaultUrl={defaultHomePreview('底', `商品卡片背景 ${index + 1}`, index === 0 ? '#E8F0FF' : '#FFF0DF', index === 0 ? '#2455C3' : '#E56A16')} label={`商品卡片背景 ${index + 1}`} onChange={(url) => updateProductCard(index, 'background_url', url)} />
              <Input value={String(item.eyebrow ?? '')} placeholder="眉标题" onChange={(event) => updateProductCard(index, 'eyebrow', event.target.value)} />
              <Input value={String(item.title ?? '')} placeholder="卡片标题" onChange={(event) => updateProductCard(index, 'title', event.target.value)} />
              <Input value={String(item.price_text ?? '')} placeholder="价格或购买方式文案" onChange={(event) => updateProductCard(index, 'price_text', event.target.value)} />
              <Select value={String(item.link_type ?? 'products')} options={linkOptions} onChange={(next) => updateProductCard(index, 'link_type', next)} />
            </Space>
          </Card>)}
        </Space>
      </Card>
      <Card size="small" title="资产记录提示区块">
        <Space direction="vertical" style={{ width: '100%' }}>
          <HomeImageInput url={String(trust.icon_url ?? '')} defaultUrl={defaultHomePreview('盾', '资产提示图标')} label="资产提示图标" onChange={(url) => updateTrust('icon_url', url)} />
          <HomeImageInput url={String(trust.background_url ?? '')} defaultUrl={defaultHomePreview('账', '资产提示背景', '#EEF4FF', '#2455C3')} label="资产提示背景" onChange={(url) => updateTrust('background_url', url)} />
          <Input value={String(trust.title ?? '')} placeholder="标题" onChange={(event) => updateTrust('title', event.target.value)} />
          <Input.TextArea value={String(trust.description ?? '')} rows={2} placeholder="说明" onChange={(event) => updateTrust('description', event.target.value)} />
          <Select value={String(trust.link_type ?? 'assets')} options={linkOptions} onChange={(next) => updateTrust('link_type', next)} />
        </Space>
      </Card>
    </Space>
  }

  return <Input.TextArea value={JSON.stringify(current, null, 2)} autoSize={{ minRows: 5, maxRows: 12 }} onChange={(event) => onChange?.(event.target.value)} />
}

function AppearanceSettingEditor({ setting, value, onChange }: ValueEditorProps) {
  const current = typeof value === 'object' && value !== null && !Array.isArray(value) ? value : {}
  const update = (key: string, next: unknown) => onChange?.({ ...current, [key]: next })
  const renderBrand = (brand: Record<string, unknown>, title: string, defaultMark: string, onBrandChange: (key: string, value: unknown) => void) => <Card size="small" title={title}>
    <Space direction="vertical" style={{ width: '100%' }}>
      <HomeImageInput url={String(brand.logo_url ?? '')} defaultUrl={defaultHomePreview(String(brand.logo_text || defaultMark), title)} label={title} onChange={(url) => onBrandChange('logo_url', url)} />
      <Input value={String(brand.logo_text ?? '')} placeholder="未上传图片时显示的文字" onChange={(event) => onBrandChange('logo_text', event.target.value)} />
      <Input value={String(brand.name ?? '')} placeholder="品牌名称" onChange={(event) => onBrandChange('name', event.target.value)} />
      <Input value={String(brand.caption ?? '')} placeholder="品牌副标题" onChange={(event) => onBrandChange('caption', event.target.value)} />
    </Space>
  </Card>

  if (setting.key === 'community.homepage') {
    const brand = typeof current.brand === 'object' && current.brand !== null ? current.brand as Record<string, unknown> : {}
    const banner = typeof current.banner === 'object' && current.banner !== null ? current.banner as Record<string, unknown> : {}
    const entries = Array.isArray(current.quick_entries) ? current.quick_entries as Record<string, unknown>[] : []
    const updateBrand = (key: string, next: unknown) => update('brand', { ...brand, [key]: next })
    const updateBanner = (key: string, next: unknown) => update('banner', { ...banner, [key]: next })
    const updateEntry = (index: number, key: string, next: unknown) => update('quick_entries', entries.map((item, itemIndex) => itemIndex === index ? { ...item, [key]: next } : item))
    return <Space direction="vertical" style={{ width: '100%' }} size="middle">
      <Alert showIcon type="info" message="社区 Logo、头图和快捷入口图标均支持本地上传、HTTPS 链接转存、直接外链及恢复系统默认。" />
      {renderBrand(brand, '社区顶部 Logo', '校', updateBrand)}
      <Card size="small" title="社区主视觉"><HomeImageInput url={String(banner.image_url ?? '')} defaultUrl={defaultHomePreview('社', String(banner.title || '校园互助社区'), '#DFF8FF', '#0C7A91')} label="社区主视觉背景" onChange={(url) => updateBanner('image_url', url)} /></Card>
      <Card size="small" title="社区快捷入口图标"><div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(300px,1fr))', gap: 12 }}>{entries.map((item, index) => <Card key={String(item.id ?? index)} size="small" title={String(item.label || `入口 ${index + 1}`)}><Space direction="vertical" style={{ width: '100%' }}><HomeImageInput url={String(item.icon_url ?? '')} defaultUrl={defaultHomePreview(['助', '团', '任', '帖'][index] || '社', String(item.label || '社区入口'))} label={`${String(item.label || '社区入口')}图标`} onChange={(url) => updateEntry(index, 'icon_url', url)} /><Input value={String(item.label ?? '')} onChange={(event) => updateEntry(index, 'label', event.target.value)} /><Input value={String(item.description ?? '')} onChange={(event) => updateEntry(index, 'description', event.target.value)} /></Space></Card>)}</div></Card>
    </Space>
  }

  if (setting.key === 'mall.appearance') {
    const brand = typeof current.brand === 'object' && current.brand !== null ? current.brand as Record<string, unknown> : {}
    const assurances = Array.isArray(current.assurances) ? current.assurances as Record<string, unknown>[] : []
    const updateBrand = (key: string, next: unknown) => update('brand', { ...brand, [key]: next })
    const updateAssurance = (index: number, key: string, next: unknown) => update('assurances', assurances.map((item, itemIndex) => itemIndex === index ? { ...item, [key]: next } : item))
    return <Space direction="vertical" style={{ width: '100%' }} size="middle">
      {renderBrand(brand, '商城顶部 Logo', 'L', updateBrand)}
      <Card size="small" title="商城服务保障图标"><div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(300px,1fr))', gap: 12 }}>{assurances.map((item, index) => <Card key={String(item.id ?? index)} size="small" title={String(item.label || `服务 ${index + 1}`)}><Space direction="vertical" style={{ width: '100%' }}><HomeImageInput url={String(item.icon_url ?? '')} defaultUrl={defaultHomePreview(String(item.mark || index + 1), String(item.label || '商城服务'))} label={`${String(item.label || '商城服务')}图标`} onChange={(url) => updateAssurance(index, 'icon_url', url)} /><Input value={String(item.mark ?? '')} placeholder="默认文字图标" onChange={(event) => updateAssurance(index, 'mark', event.target.value)} /><Input value={String(item.label ?? '')} placeholder="标题" onChange={(event) => updateAssurance(index, 'label', event.target.value)} /><Input value={String(item.description ?? '')} placeholder="说明" onChange={(event) => updateAssurance(index, 'description', event.target.value)} /></Space></Card>)}</div></Card>
    </Space>
  }

  const serviceIcons = Array.isArray(current.service_icons) ? current.service_icons as Record<string, unknown>[] : []
  const labels: Record<string, string> = { tasks: '我的任务', orders: '我的订单', after_sales: '售后服务', referrals: '邀请好友', check_in: '每日签到', notifications: '消息中心', addresses: '收货地址', legal: '协议隐私', security: '账号安全', support: '联系客服' }
  const updateService = (index: number, key: string, next: unknown) => update('service_icons', serviceIcons.map((item, itemIndex) => itemIndex === index ? { ...item, [key]: next } : item))
  return <Space direction="vertical" style={{ width: '100%' }} size="middle">
    <Alert showIcon type="info" message="账户中心头像占位图和全部服务入口图标均可独立替换；业务名称与跳转仍由系统固定，避免误配。" />
    <Card size="small" title="账户头像占位图"><HomeImageInput url={String(current.profile_icon_url ?? '')} defaultUrl={defaultHomePreview('用', '账户头像占位图')} label="账户头像占位图" onChange={(url) => update('profile_icon_url', url)} /></Card>
    <Card size="small" title="账户服务图标"><div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(280px,1fr))', gap: 12 }}>{serviceIcons.map((item, index) => <Card key={String(item.id ?? index)} size="small" title={labels[String(item.id)] || String(item.id)}><Space direction="vertical" style={{ width: '100%' }}><HomeImageInput url={String(item.icon_url ?? '')} defaultUrl={defaultHomePreview(String(item.mark || index + 1), labels[String(item.id)] || '账户服务')} label={`${labels[String(item.id)] || '账户服务'}图标`} onChange={(url) => updateService(index, 'icon_url', url)} /><Input value={String(item.mark ?? '')} placeholder="未上传图片时显示的文字" onChange={(event) => updateService(index, 'mark', event.target.value)} /></Space></Card>)}</div></Card>
  </Space>
}

const rewardAmountFields = [
  { key: 'sign_in', label: '每日签到' },
  { key: 'post', label: '发布帖子' },
  { key: 'comment', label: '有效评论' },
  { key: 'profile', label: '完善资料' },
  { key: 'referral', label: '推荐新人' },
] as const

function RewardAmountsSettingEditor({ value, onChange }: ValueEditorProps) {
  const current = typeof value === 'object' && value !== null && !Array.isArray(value) ? value : {}
  return <Space direction="vertical" style={{ width: '100%' }} size="middle">
    <Alert showIcon type="info" message="发帖奖励在帖子审核通过或自动发布时发放，每篇仅发放一次；对应来源设为 0 即可关闭奖励。" />
    <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(200px,1fr))', gap: 12 }}>
      {rewardAmountFields.map((field) => <label key={field.key}>
        <Typography.Text type="secondary">{field.label}</Typography.Text>
        <InputNumber
          value={Number(current[field.key] ?? 0)}
          onChange={(next) => onChange?.({ ...current, [field.key]: next ?? 0 })}
          min={0}
          max={100000}
          precision={0}
          addonAfter="积分"
          style={{ width: '100%', marginTop: 6 }}
        />
      </label>)}
    </div>
  </Space>
}

function ValueEditor({ setting, value, onChange }: ValueEditorProps) {
  if (setting.key === 'points.source_reward_amounts') return <RewardAmountsSettingEditor setting={setting} value={value} onChange={onChange} />
  if (setting.key === 'navigation.bottom_tabs') return <BottomNavigationSettingEditor setting={setting} value={value} onChange={onChange} />
  if (setting.key.startsWith('homepage.')) return <HomeSettingEditor setting={setting} value={value} onChange={onChange} />
  if (['community.homepage', 'mall.appearance', 'accounts.appearance'].includes(setting.key)) return <AppearanceSettingEditor setting={setting} value={value} onChange={onChange} />
  if (setting.value_type === 'boolean') return <Switch checked={Boolean(value)} onChange={onChange} checkedChildren="启用" unCheckedChildren="停用" />
  if (['integer', 'money', 'decimal'].includes(setting.value_type)) {
    const rules = setting.validation_rules ?? {}
    const factor = setting.value_type === 'money' ? 100 : 1
    return (
      <InputNumber
        value={typeof value === 'number' ? value : undefined}
        onChange={(next) => onChange?.(next ?? 0)}
        style={{ width: '100%' }}
        min={typeof rules.min === 'number' ? rules.min / factor : undefined}
        max={typeof rules.max === 'number' ? rules.max / factor : undefined}
        step={setting.value_type === 'decimal' ? 0.001 : setting.value_type === 'money' ? 0.01 : 1}
        addonAfter={setting.value_type === 'money' ? '元' : setting.unit}
      />
    )
  }
  if (setting.value_type === 'enum') return <Select value={typeof value === 'string' ? value : undefined} onChange={onChange} options={(setting.options ?? []).map((option) => ({ value: option, label: option }))} />
  if (setting.value_type === 'multi_enum') return <Select value={Array.isArray(value) ? value : []} onChange={onChange} mode="multiple" options={(setting.options ?? []).map((option) => ({ value: option, label: option }))} />
  if (setting.value_type === 'object') return <Input.TextArea value={typeof value === 'string' ? value : JSON.stringify(value, null, 2)} onChange={(event) => onChange?.(event.target.value)} autoSize={{ minRows: 5, maxRows: 12 }} />
  return <Input value={typeof value === 'string' ? value : ''} onChange={(event) => onChange?.(event.target.value)} type={setting.value_type === 'time' ? 'time' : 'text'} />
}

export function SettingsPage() {
  const { admin } = useAdminAuth()
  const [items, setItems] = useState<SystemSetting[]>([])
  const [groups, setGroups] = useState<string[]>([])
  const [group, setGroup] = useState<string>()
  const [loading, setLoading] = useState(true)
  const [editing, setEditing] = useState<SystemSetting | null>(null)
  const [versionsFor, setVersionsFor] = useState<SystemSetting | null>(null)
  const [versions, setVersions] = useState<SettingVersion[]>([])
  const [submitting, setSubmitting] = useState(false)
  const [form] = Form.useForm()
  const [messageApi, contextHolder] = message.useMessage()
  const canApprove = admin?.permissions.includes('settings.approve') ?? false
  const isSuperAdmin = admin?.roles.includes('super_admin') ?? false

  const load = useCallback(async (nextGroup?: string) => {
    setLoading(true)
    try {
      const data = await listSettings(nextGroup)
      setItems(data.items)
      if (!nextGroup) setGroups(data.groups)
    } catch (error) {
      messageApi.error(error instanceof ApiError ? error.message : '配置列表加载失败。')
    } finally {
      setLoading(false)
    }
  }, [messageApi])

  useEffect(() => { void load(group) }, [group, load])

  const openEditor = (setting: SystemSetting) => {
    setEditing(setting)
    form.setFieldsValue({ value: editorInitialValue(setting), reason: setting.draft?.reason })
  }

  const saveDraft = async () => {
    if (!editing) return
    try {
      const values = await form.validateFields()
      setSubmitting(true)
      await saveSettingDraft(
        editing.key,
        normalizeEditorValue(editing, values.value),
        values.reason,
        editing.draft?.lock_version,
      )
      messageApi.success('草稿已保存，线上值未受影响。')
      setEditing(null)
      await load(group)
    } catch (error) {
      if (error instanceof ApiError) messageApi.error(error.message)
      else if (error instanceof SyntaxError) messageApi.error('JSON 格式不正确。')
    } finally {
      setSubmitting(false)
    }
  }

  const runAction = async (action: () => Promise<unknown>, success: string) => {
    try {
      setSubmitting(true)
      await action()
      messageApi.success(success)
      await load(group)
    } catch (error) {
      messageApi.error(error instanceof ApiError ? error.message : '操作失败，请稍后重试。')
    } finally {
      setSubmitting(false)
    }
  }

  const openVersions = async (setting: SystemSetting) => {
    setVersionsFor(setting)
    try {
      setVersions((await listSettingVersions(setting.key)).items)
    } catch (error) {
      messageApi.error(error instanceof ApiError ? error.message : '版本历史加载失败。')
    }
  }

  const columns = [
    { title: '配置项', dataIndex: 'name', key: 'name', render: (_: string, record: SystemSetting) => <><strong>{record.name}</strong><Typography.Text type="secondary" className="setting-key">{record.key}</Typography.Text></> },
    { title: '分类', dataIndex: 'group', width: 100, render: (value: string) => groupNames[value] ?? value },
    { title: '线上值', key: 'current', render: (_: unknown, record: SystemSetting) => <Space direction="vertical" size={2}><span>{displayValue(record, record.current_value)}</span><Typography.Text type="secondary">{record.current_version ? `v${record.current_version}` : '系统默认值'}</Typography.Text></Space> },
    { title: '风险', dataIndex: 'risk_level', width: 100, render: (value: string) => value === 'high' ? <Tag color="red">高风险</Tag> : <Tag>普通</Tag> },
    { title: '草稿', key: 'draft', width: 130, render: (_: unknown, record: SystemSetting) => record.draft ? <Tag color={record.draft.review_status === 'approved' ? 'green' : record.draft.review_status === 'pending' ? 'orange' : 'blue'}>{record.draft.review_status === 'not_required' ? '待发布' : record.draft.review_status === 'pending' ? '待审核' : record.draft.review_status === 'approved' ? '已审核' : '已驳回'}</Tag> : '—' },
    { title: '操作', key: 'actions', width: 300, render: (_: unknown, record: SystemSetting) => <Space wrap>
      <Button size="small" onClick={() => openEditor(record)}>编辑</Button>
      <Button size="small" onClick={() => void openVersions(record)}>版本</Button>
      {record.draft?.review_status === 'pending' && canApprove && <>
        <Popconfirm title="确认已复核该高风险配置？" onConfirm={() => void runAction(() => reviewSetting(record.key, 'approve', '已复核配置值、影响范围和修改原因。'), '审核已通过。')}><Button size="small">通过</Button></Popconfirm>
        <Popconfirm title="确认驳回该配置草稿？" onConfirm={() => void runAction(() => reviewSetting(record.key, 'reject', '复核未通过，请修改配置值或补充调整依据。'), '草稿已驳回。')}><Button size="small" danger>驳回</Button></Popconfirm>
      </>}
      {record.draft && ['not_required', 'approved'].includes(record.draft.review_status) && <Popconfirm title="发布后立即对新业务生效，确认继续？" onConfirm={() => void runAction(() => publishSetting(record.key), '新配置版本已发布。')}><Button size="small" type="primary">发布</Button></Popconfirm>}
    </Space> },
  ]

  return (
    <>
      {contextHolder}
      <div className="page-heading">
        <div><Typography.Text className="eyebrow">平台配置治理</Typography.Text><Typography.Title level={2}>配置中心</Typography.Title><Typography.Paragraph type="secondary">草稿与线上版本隔离；高风险配置必须由另一位管理员复核。</Typography.Paragraph></div>
        <Select allowClear placeholder="全部分类" value={group} onChange={setGroup} style={{ width: 180 }} options={groups.map((value) => ({ value, label: groupNames[value] ?? value }))} />
      </div>
      <Alert showIcon type="info" message="金额编辑时使用元，服务端和数据库统一保存为分；发布只影响后续新业务，历史订单与资产事实不重算。" />
      <IconConfigPanel />
      <StorageManagementPanel />
      {isSuperAdmin && <ThirdPartyServicesPanel />}
      <Card bordered={false} className="settings-table-card">
        <Table rowKey="key" loading={loading} dataSource={items} columns={columns} pagination={{ pageSize: 10, showSizeChanger: true }} scroll={{ x: 1000 }} />
      </Card>
      <Modal title={editing ? `编辑：${editing.name}` : '编辑配置'} open={Boolean(editing)} onCancel={() => setEditing(null)} onOk={() => void saveDraft()} confirmLoading={submitting} okText="保存草稿" width={editing?.key.startsWith('homepage.') || editing?.key.startsWith('navigation.') ? 1000 : 560} styles={{ body: { maxHeight: '76vh', overflowY: 'auto', paddingRight: 8 } }} destroyOnHidden>
        {editing && <Form form={form} layout="vertical" requiredMark={false}>
          {editing.risk_level === 'high' && <Alert type="warning" showIcon message="高风险配置保存后需要另一位有审核权限的管理员复核。" />}
          <Form.Item label="配置值" name="value" valuePropName={editing.value_type === 'boolean' ? 'checked' : 'value'} rules={[{ required: true, message: '请填写配置值' }]}><ValueEditor setting={editing} /></Form.Item>
          <Form.Item label="修改原因" name="reason" rules={[{ required: true, min: 4, max: 500, message: '请填写至少 4 个字的修改原因' }]}><Input.TextArea rows={3} showCount maxLength={500} /></Form.Item>
          {editing.diff && <Typography.Text type="secondary">当前线上值：{displayValue(editing, editing.diff.before)}</Typography.Text>}
        </Form>}
      </Modal>
      <Modal title={versionsFor ? `${versionsFor.name} · 版本历史` : '版本历史'} open={Boolean(versionsFor)} footer={null} width={760} onCancel={() => setVersionsFor(null)}>
        <Table rowKey="version" dataSource={versions} pagination={false} columns={[
          { title: '版本', dataIndex: 'version', render: (value: number) => `v${value}` },
          { title: '值', dataIndex: 'value', render: (value: SettingValue) => versionsFor ? displayValue(versionsFor, value) : '' },
          { title: '来源', dataIndex: 'source', render: (value: string) => value === 'rollback' ? '回滚发布' : '正常发布' },
          { title: '原因', dataIndex: 'reason' },
          { title: '操作', render: (_: unknown, version: SettingVersion) => versionsFor?.current_version !== version.version && <Popconfirm title={`创建回滚到 v${version.version} 的草稿？`} onConfirm={() => void runAction(() => createSettingRollback(versionsFor!.key, version.version, `回滚至已验证的 v${version.version} 版本。`), '回滚草稿已创建，请按流程审核并发布。')}><Button size="small">回滚至此</Button></Popconfirm> },
        ]} />
      </Modal>
    </>
  )
}
