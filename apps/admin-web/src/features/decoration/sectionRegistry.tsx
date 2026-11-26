import type { DecorationPageKey, DecorationSection } from '../../api/admin'

export const DECORATION_PAGE_TABS: Array<{ key: DecorationPageKey; label: string; hint: string }> = [
  { key: 'home', label: '首页', hint: '插入在积分卡之后、金刚区之前' },
  { key: 'community', label: '社区首页', hint: '插入在工具条之后、帖子流之前' },
  { key: 'mall', label: '商城首页', hint: '插入在品牌与搜索区之后' },
  { key: 'account', label: '我的页面', hint: '插入在用户信息卡之后' },
]

export const DECORATION_LINK_OPTIONS = [
  { value: 'none', label: '无跳转' },
  { value: 'tasks', label: '任务大厅' },
  { value: 'products', label: '积分商城' },
  { value: 'community', label: '社区' },
  { value: 'notifications', label: '消息通知' },
  { value: 'orders', label: '我的订单' },
  { value: 'assets', label: '我的资产' },
  { value: 'push', label: '地推中心' },
  { value: 'second_hand', label: '二手闲置' },
  { value: 'material', label: '资料共享' },
  { value: 'express_pickup', label: '快递代取' },
]

export interface DecorationSectionDef {
  type: string
  label: string
  description: string
  createProps: () => Record<string, unknown>
}

export const DECORATION_SECTION_DEFS: DecorationSectionDef[] = [
  {
    type: 'banner',
    label: '轮播图',
    description: '多张主视觉轮播，可配置标题、副标题与跳转。',
    createProps: () => ({
      items: [{ image_url: '', title: '新的轮播标题', subtitle: '', button_text: '立即查看', link_type: 'none', link_value: '' }],
      autoplay: true,
      interval_ms: 4500,
    }),
  },
  {
    type: 'announcement',
    label: '公告条',
    description: '单行公告，支持图标、标题与富文本内容。',
    createProps: () => ({ icon_text: '公', icon_url: '', title: '平台公告', content: '', link_type: 'notifications', link_value: '' }),
  },
  {
    type: 'quick_grid',
    label: '金刚区',
    description: '宫格入口，最多 10 个，可配置图标与跳转。',
    createProps: () => ({
      columns: 4,
      items: [{ id: 'entry-1', label: '入口一', mark: '一', icon_url: '', link_type: 'none', enabled: true }],
    }),
  },
  {
    type: 'image_banner',
    label: '单图横幅',
    description: '一张可点击的横幅图片。',
    createProps: () => ({ image_url: '', link_type: 'none', link_value: '', height_rpx: 200 }),
  },
  {
    type: 'image_text',
    label: '图文卡片',
    description: '左图右文或右图左文的卡片。',
    createProps: () => ({ layout: 'left', image_url: '', title: '图文标题', description: '', link_type: 'none', link_value: '' }),
  },
  {
    type: 'product_grid',
    label: '商品列表',
    description: '按数量拉取商城商品，支持指定分类。',
    createProps: () => ({ title: '精选商品', subtitle: '', limit: 6, category_id: '', price_mode: 'default' }),
  },
  {
    type: 'post_list',
    label: '帖子列表',
    description: '按数量拉取社区帖子，支持指定分类。',
    createProps: () => ({ title: '社区热帖', subtitle: '', limit: 6, category_code: '' }),
  },
  {
    type: 'task_list',
    label: '任务推荐',
    description: '从任务大厅拉取最新任务。',
    createProps: () => ({ title: '推荐任务', subtitle: '', limit: 4, category: '' }),
  },
  {
    type: 'rich_text',
    label: '富文本',
    description: '支持加粗、列表、链接等排版的说明区。',
    createProps: () => ({ html: '' }),
  },
  {
    type: 'spacer',
    label: '间距',
    description: '在区块之间留白。',
    createProps: () => ({ height_rpx: 24 }),
  },
]

export const DECORATION_SECTION_MAP: Record<string, DecorationSectionDef> = Object.fromEntries(
  DECORATION_SECTION_DEFS.map((definition) => [definition.type, definition]),
)

export function sectionTypeLabel(type: string): string {
  return DECORATION_SECTION_MAP[type]?.label ?? type
}

export function createDecorationSection(type: string): DecorationSection {
  const definition = DECORATION_SECTION_MAP[type]
  return {
    id: `sec-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 6)}`,
    type,
    enabled: true,
    props: definition ? definition.createProps() : {},
  }
}
