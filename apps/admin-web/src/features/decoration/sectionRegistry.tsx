import type { DecorationPageKey, DecorationSection, DecorationStyle } from '../../api/admin'

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

export const DECORATION_GROUPS: Array<{ key: 'basic' | 'shop' | 'marketing' | 'feature' | 'member'; label: string }> = [
  { key: 'basic', label: '基础组件' },
  { key: 'shop', label: '店铺组件' },
  { key: 'marketing', label: '营销组件' },
  { key: 'feature', label: '功能组件' },
  { key: 'member', label: '会员组件' },
]

export interface DecorationSectionDef {
  type: string
  label: string
  description: string
  group: 'basic' | 'shop' | 'marketing' | 'feature' | 'member'
  createProps: () => Record<string, unknown>
}

export const DECORATION_SECTION_DEFS: DecorationSectionDef[] = [
  {
    type: 'banner',
    label: '轮播图',
    description: '多张主视觉轮播，可配标题与跳转。',
    group: 'basic',
    createProps: () => ({
      items: [{ image_url: '', title: '新的轮播标题', subtitle: '', button_text: '立即查看', link_type: 'none', link_value: '' }],
      autoplay: true,
      interval_ms: 4500,
      duration_ms: 300,
      height_rpx: 280,
      radius_rpx: 24,
      indicator: 'dots',
      indicator_position: 'bottom-right',
      show_title: true,
      data_source: 'manual',
      limit: 5,
    }),
  },
  {
    type: 'nav_bar',
    label: '导航栏',
    description: '页面内的标题栏，可加副标题与跳转。',
    group: 'basic',
    createProps: () => ({ title: '页面标题', subtitle: '', link_type: 'none', show_border: false }),
  },
  {
    type: 'search_box',
    label: '搜索框',
    description: '搜索入口，点击跳转搜索或指定页面。',
    group: 'basic',
    createProps: () => ({ placeholder: '搜索任务、商品或帖子', link_type: 'products' }),
  },
  {
    type: 'announcement',
    label: '公告条',
    description: '单行公告，支持图标、标题与富文本内容。',
    group: 'basic',
    createProps: () => ({
      icon_text: '公', icon_url: '', title: '平台公告', content: '', link_type: 'notifications', link_value: '',
      scroll: true, speed: 3, title_size_rpx: 28, text_color: '#8A6A1F', icon_size_rpx: 48, show_arrow: true,
      data_source: 'manual', limit: 3,
    }),
  },
  {
    type: 'quick_grid',
    label: '金刚区',
    description: '宫格入口，最多 10 个，可配置图标与跳转。',
    group: 'basic',
    createProps: () => ({
      columns: 4,
      icon_shape: 'rounded',
      icon_size_rpx: 84,
      label_size_rpx: 24,
      label_color: '#3C4961',
      row_gap_rpx: 20,
      data_source: 'manual',
      limit: 8,
      items: [{ id: 'entry-1', label: '入口一', mark: '一', icon_url: '', link_type: 'none', enabled: true, badge_type: 'none', badge_text: '', badge_image: '' }],
    }),
  },
  {
    type: 'image_banner',
    label: '单图横幅',
    description: '一张可点击的横幅图片。',
    group: 'basic',
    createProps: () => ({ image_url: '', link_type: 'none', link_value: '', height_rpx: 200 }),
  },
  {
    type: 'image_text',
    label: '图文卡片',
    description: '左图右文或右图左文的卡片。',
    group: 'basic',
    createProps: () => ({ layout: 'left', image_url: '', title: '图文标题', description: '', link_type: 'none', link_value: '' }),
  },
  {
    type: 'rich_text',
    label: '富文本',
    description: '支持加粗、列表、链接等排版的说明区。',
    group: 'basic',
    createProps: () => ({ html: '' }),
  },
  {
    type: 'spacer',
    label: '辅助空白',
    description: '在区块之间留白。',
    group: 'basic',
    createProps: () => ({ height_rpx: 24 }),
  },
  {
    type: 'floating_box',
    label: '悬浮框',
    description: '固定悬浮在页面右下角的图标或客服入口。',
    group: 'basic',
    createProps: () => ({ image_url: '', icon_text: '客', link_type: 'none', bottom_rpx: 180 }),
  },
  {
    type: 'product_grid',
    label: '商品列表',
    description: '按数量拉取商城商品，支持指定分类。',
    group: 'shop',
    createProps: () => ({ title: '精选商品', subtitle: '', limit: 6, category_id: '', price_mode: 'default', columns: 2, show_price: true, show_subtitle: true, show_category: false }),
  },
  {
    type: 'category_nav',
    label: '分类导航',
    description: '横向分类入口，最多 8 项。',
    group: 'shop',
    createProps: () => ({ items: [{ label: '全部分类', category_id: '', link_type: 'products' }, { label: '热销', category_id: '', link_type: 'products' }] }),
  },
  {
    type: 'coupon',
    label: '优惠券',
    description: '手动配置的优惠券卡片，最多 6 张。',
    group: 'shop',
    createProps: () => ({
      title: '优惠券',
      source: 'manual',
      limit: 3,
      items: [{ title: '新人券', value_text: '¥10', condition: '满 50 元可用', button_text: '立即领取', link_type: 'products' }],
    }),
  },
  {
    type: 'post_list',
    label: '帖子列表',
    description: '按数量拉取社区帖子，支持指定分类。',
    group: 'shop',
    createProps: () => ({ title: '社区热帖', subtitle: '', limit: 6, category_code: '', layout: 'card', show_avatar: true, show_excerpt: false, show_stats: true }),
  },
  {
    type: 'task_list',
    label: '任务推荐',
    description: '从任务大厅拉取最新任务。',
    group: 'shop',
    createProps: () => ({ title: '推荐任务', subtitle: '', limit: 4, category: '' }),
  },
  {
    type: 'page_title',
    label: '页面标题',
    description: '大标题与副标题，用于分区说明。',
    group: 'basic',
    createProps: () => ({ title: '页面标题', subtitle: '', align: 'left' }),
  },
  {
    type: 'list_nav',
    label: '列表导航',
    description: '带图标与描述的入口列表，最多 6 项。',
    group: 'basic',
    createProps: () => ({ items: [{ icon_url: '', title: '任务大厅', subtitle: '发布与接取任务', link_type: 'tasks' }] }),
  },
  {
    type: 'tag_group',
    label: '标签组',
    description: '热门标签或快捷话题，最多 10 个。',
    group: 'basic',
    createProps: () => ({ title: '热门标签', items: [{ label: '#校园互助', link_type: 'community' }, { label: '#自习搭子', link_type: 'community' }] }),
  },
  {
    type: 'image_group',
    label: '单图组',
    description: '多张图片网格，可两列或三列。',
    group: 'basic',
    createProps: () => ({ columns: 2, items: [{ image_url: '', link_type: 'none' }] }),
  },
  {
    type: 'button_group',
    label: '按钮组',
    description: '一行最多 3 个快捷按钮。',
    group: 'basic',
    createProps: () => ({ items: [{ label: '立即参与', link_type: 'tasks' }] }),
  },
  {
    type: 'divider',
    label: '辅助线',
    description: '实线或虚线分隔。',
    group: 'basic',
    createProps: () => ({ line_style: 'solid', color: '#E7EBF2' }),
  },
  {
    type: 'activity_group',
    label: '活动组',
    description: '活动卡片，最多 4 个。',
    group: 'marketing',
    createProps: () => ({ title: '校园活动', items: [{ image_url: '', title: '新学期任务季', subtitle: '完成任务领额外积分', link_type: 'tasks' }] }),
  },
  {
    type: 'member_card',
    label: '会员卡',
    description: '会员中心入口卡片。',
    group: 'member',
    createProps: () => ({ title: '会员中心', description: '开通会员享专属权益', button_text: '去开通', link_type: 'none' }),
  },
  {
    type: 'cart_entry',
    label: '购物车入口',
    description: '展示购物车入口与结算按钮。',
    group: 'feature',
    createProps: () => ({ title: '购物车', description: '查看已选商品', button_text: '去结算' }),
  },
  {
    type: 'qr_code',
    label: '引流二维码',
    description: '展示二维码与说明文字。',
    group: 'feature',
    createProps: () => ({ image_url: '', title: '扫码加入', description: '扫码进入小程序，参与校园任务' }),
  },
  {
    type: 'location_search',
    label: '定位搜索框',
    description: '校区文字 + 搜索入口。',
    group: 'basic',
    createProps: () => ({ campus_text: '当前校区', placeholder: '搜索任务、商品或帖子', link_type: 'products' }),
  },
  {
    type: 'order_marquee',
    label: '订单弹幕',
    description: '横向滚动的动态播报，最多 8 条。',
    group: 'basic',
    createProps: () => ({ icon_text: '动', items: [{ text: '同学刚刚完成任务，获得 50 积分' }], link_type: 'none', speed: 3, bg_color: '#FFF8E8', text_color: '#8A6A1F', opacity: 100 }),
  },
  {
    type: 'video',
    label: '视频播放',
    description: '视频播放器，支持封面与高度设置。',
    group: 'basic',
    createProps: () => ({ video_url: '', poster_url: '', height_rpx: 220, ratio: 'auto' }),
  },
  {
    type: 'tab_nav',
    label: '选项卡导航',
    description: '一行选项卡入口，最多 6 个。',
    group: 'basic',
    createProps: () => ({ items: [{ label: '推荐', link_type: 'tasks' }, { label: '商城', link_type: 'products' }], active_color: '#0BBF63', background_color: '#F2F4F8', sticky: false }),
  },
  {
    type: 'ad_banner',
    label: '广告',
    description: '带“广告”角标的可点击横幅。',
    group: 'basic',
    createProps: () => ({ image_url: '', link_type: 'none', link_value: '', height_rpx: 160, show_badge: true }),
  },
  {
    type: 'image_showcase',
    label: '图片橱窗',
    description: '左大右小（或相反）的三图橱窗。',
    group: 'basic',
    createProps: () => ({ layout: 'left', items: [{ image_url: '', title: '主推内容', link_type: 'none' }, { image_url: '', title: '内容二', link_type: 'none' }, { image_url: '', title: '内容三', link_type: 'none' }] }),
  },
  {
    type: 'notice_bar',
    label: '警告提示',
    description: '信息 / 警告 / 成功三色提示条。',
    group: 'basic',
    createProps: () => ({ tone: 'warning', title: '温馨提示', content: '活动期间任务奖励以页面展示为准。', link_type: 'none', show_icon: true, title_size_rpx: 26, content_size_rpx: 22 }),
  },
  {
    type: 'hot_tags',
    label: '热门标签',
    description: '带 HOT 标记的热门标签。',
    group: 'basic',
    createProps: () => ({ title: '热门标签', items: [{ label: '#校园互助', link_type: 'community', hot: true }] }),
  },
  {
    type: 'title_bar',
    label: '标题栏',
    description: '左侧标题、右侧“更多”入口。',
    group: 'basic',
    createProps: () => ({ title: '精选推荐', subtitle: '', more_text: '更多', link_type: 'none', title_size_rpx: 32, title_color: '#1F2937', more_color: '#0BBF63' }),
  },
  {
    type: 'tile_ad',
    label: '瓷片广告位',
    description: '左大右二或三列拼贴，图片上叠加标题、副标题与标签。',
    group: 'marketing',
    createProps: () => ({
      layout: 'big-left',
      radius_rpx: 12,
      gap_rpx: 8,
      title_size_rpx: 30,
      title_color: '#FFFFFF',
      subtitle_size_rpx: 22,
      subtitle_color: '#F2F4F8',
      tag_bg: '#0BBF63',
      tag_color: '#FFFFFF',
      items: [
        { image_url: '', title: '主推活动', subtitle: '完成任务领积分', tag: '推荐', link_type: 'tasks', link_value: '' },
        { image_url: '', title: '新品上架', subtitle: '限量兑换', tag: '', link_type: 'products', link_value: '' },
        { image_url: '', title: '社区热帖', subtitle: '看看同学都在聊', tag: '', link_type: 'community', link_value: '' },
      ],
    }),
  },
  {
    type: 'popup',
    label: '启动弹窗',
    description: '进入页面后浮层展示，可设置展示频次与关闭按钮。',
    group: 'marketing',
    createProps: () => ({
      image_url: '',
      title: '新人礼包',
      content: '完成任务即可领取专属积分奖励。',
      button_text: '立即参与',
      link_type: 'tasks',
      frequency: 'daily',
      closable: true,
      width_rpx: 560,
      radius_rpx: 20,
    }),
  },
]

export const DECORATION_SECTION_MAP: Record<string, DecorationSectionDef> = Object.fromEntries(
  DECORATION_SECTION_DEFS.map((definition) => [definition.type, definition]),
)

export function sectionTypeLabel(type: string): string {
  return DECORATION_SECTION_MAP[type]?.label ?? type
}

export function createDecorationStyle(): DecorationStyle {
  return {
    margin: { top: 0, right: 0, bottom: 0, left: 0 },
    padding: { top: 0, right: 0, bottom: 0, left: 0 },
    radius: 0,
    corner_radius: false,
    radius_corners: { top_left: 0, top_right: 0, bottom_left: 0, bottom_right: 0 },
    opacity: 100,
    display_daily_start: '',
    display_daily_end: '',
    background_type: 'none',
    background_color: '',
    background_image: '',
    background_gradient: '',
    border: false,
    border_color: '#E7EBF2',
    shadow: false,
    channels: ['h5', 'miniapp'],
    display_start: '',
    display_end: '',
  }
}

export function createDecorationSection(type: string): DecorationSection {
  const definition = DECORATION_SECTION_MAP[type]
  return {
    id: `sec-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 6)}`,
    type,
    enabled: true,
    style: createDecorationStyle(),
    props: definition ? definition.createProps() : {},
  }
}
