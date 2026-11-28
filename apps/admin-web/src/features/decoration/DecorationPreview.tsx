import type { CSSProperties } from 'react'
import { PictureOutlined } from '@ant-design/icons'
import { Empty } from 'antd'
import type { DecorationPageConfig, DecorationSection } from '../../api/admin'
import { createDecorationStyle } from './sectionRegistry'

function plainText(html: string): string {
  return html.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim()
}

function stringValue(source: Record<string, unknown>, key: string): string {
  return typeof source[key] === 'string' ? source[key] as string : ''
}

function numberValue(source: Record<string, unknown>, key: string, fallback: number): number {
  return typeof source[key] === 'number' ? source[key] as number : fallback
}

function listValue(source: Record<string, unknown>, key: string): Record<string, unknown>[] {
  return Array.isArray(source[key]) ? source[key] as Record<string, unknown>[] : []
}

/** 后台数值以 1 单位 ≈ 0.5px 缩放，让手机画布更接近用户端 rpx 视觉。 */
export function sectionOuterStyle(section: DecorationSection): CSSProperties {
  const style = section.style ?? createDecorationStyle()
  const unit = (value: number) => `${value / 2}px`
  const background: CSSProperties = style.background_type === 'color' && style.background_color
    ? { backgroundColor: style.background_color }
    : style.background_type === 'image' && style.background_image
      ? { backgroundImage: `url(${style.background_image})`, backgroundSize: 'cover', backgroundPosition: 'center' }
      : style.background_type === 'gradient' && style.background_gradient
        ? { backgroundImage: style.background_gradient }
        : {}

  return {
    marginTop: unit(style.margin.top),
    marginRight: unit(style.margin.right),
    marginBottom: unit(style.margin.bottom),
    marginLeft: unit(style.margin.left),
    paddingTop: unit(style.padding.top),
    paddingRight: unit(style.padding.right),
    paddingBottom: unit(style.padding.bottom),
    paddingLeft: unit(style.padding.left),
    ...(style.corner_radius && style.radius_corners
      ? {
        borderTopLeftRadius: unit(style.radius_corners.top_left),
        borderTopRightRadius: unit(style.radius_corners.top_right),
        borderBottomLeftRadius: unit(style.radius_corners.bottom_left),
        borderBottomRightRadius: unit(style.radius_corners.bottom_right),
      }
      : { borderRadius: unit(style.radius) }),
    opacity: (style.opacity ?? 100) / 100,
    border: style.border ? `1px solid ${style.border_color || '#E7EBF2'}` : undefined,
    boxShadow: style.shadow ? '0 6px 18px rgba(15,25,20,.12)' : undefined,
    ...background,
  }
}

function MediaPlaceholder({ url, height = 96, radius = 10, label = '未设置图片' }: { url: string; height?: number; radius?: number; label?: string }) {
  if (url) return <img src={url} alt="" style={{ width: '100%', height, objectFit: 'cover', borderRadius: radius, display: 'block' }} />
  return <div style={{
    width: '100%',
    height,
    borderRadius: radius,
    background: 'linear-gradient(135deg, #E9F0FD 0%, #DEE8F9 48%, #EDF3FF 100%)',
    display: 'flex',
    flexDirection: 'column',
    alignItems: 'center',
    justifyContent: 'center',
    gap: 4,
    color: '#93A6C6',
  }}>
    <PictureOutlined style={{ fontSize: Math.min(20, height / 4) }} />
    <span style={{ fontSize: 10.5 }}>{label}</span>
  </div>
}

export function SectionPreview({ section }: { section: DecorationSection }) {
  const props = section.props
  switch (section.type) {
    case 'banner': {
      const fromPlatform = stringValue(props, 'data_source') === 'platform'
      const items = fromPlatform
        ? Array.from({ length: Math.min(numberValue(props, 'limit', 5), 3) }).map((_, index) => ({
          title: `平台幻灯片 ${index + 1}`,
          subtitle: '来自设置 → 首页内容 → 轮播图',
          button_text: '查看',
          image_url: '',
        }))
        : listValue(props, 'items')
      const first = items[0] ?? {}
      const height = numberValue(props, 'height_rpx', 280) / 2.4
      const radius = numberValue(props, 'radius_rpx', 24) / 2
      const indicator = stringValue(props, 'indicator') || 'dots'
      const [vertical, horizontal] = (stringValue(props, 'indicator_position') || 'bottom-right').split('-')
      const anchor = vertical === 'top' ? { top: 8 } : { bottom: 8 }
      const align = horizontal === 'left' ? { left: 12 } : horizontal === 'center' ? { left: '50%', transform: 'translateX(-50%)' } : { right: 12 }
      return <div style={{ position: 'relative', borderRadius: radius, overflow: 'hidden' }}>
        <MediaPlaceholder url={stringValue(first, 'image_url')} height={height} radius={radius} label="主视觉图 · 可在右侧换图" />
        {props.show_title !== false ? <div style={{ position: 'absolute', inset: 0, padding: 14, display: 'flex', flexDirection: 'column', justifyContent: 'flex-end', background: 'linear-gradient(180deg, rgba(0,0,0,0) 40%, rgba(0,0,0,.45))', color: '#fff' }}>
          <div style={{ fontSize: 15, fontWeight: 700 }}>{stringValue(first, 'title') || '轮播标题'}</div>
          <div style={{ fontSize: 11, opacity: .9 }}>{stringValue(first, 'subtitle')}</div>
          {stringValue(first, 'button_text') ? <span style={{ alignSelf: 'flex-start', marginTop: 8, padding: '4px 12px', borderRadius: 999, background: '#fff', color: '#2F67DF', fontSize: 11, fontWeight: 600 }}>{stringValue(first, 'button_text')}</span> : null}
        </div> : null}
        {indicator === 'none' ? null : <div style={{ position: 'absolute', display: 'flex', gap: 4, alignItems: 'center', ...anchor, ...align }}>
          {indicator === 'numbers'
            ? <span style={{ fontSize: 9, color: '#fff', background: 'rgba(0,0,0,.4)', borderRadius: 999, padding: '1px 8px' }}>1/{Math.max(1, items.length)}</span>
            : items.map((_, index) => <span key={index} style={{ width: indicator === 'bars' ? 10 : 6, height: indicator === 'bars' ? 3 : 6, borderRadius: 999, background: index === 0 ? '#fff' : 'rgba(255,255,255,.5)' }} />)}
        </div>}
      </div>
    }
    case 'nav_bar':
      return <div style={{ padding: '10px 2px', borderBottom: props.show_border ? '1px solid #EDEFF3' : 'none' }}>
        <div style={{ fontSize: 15, fontWeight: 700 }}>{stringValue(props, 'title') || '页面标题'}</div>
        <div style={{ fontSize: 11, color: '#7A859B', marginTop: 2 }}>{stringValue(props, 'subtitle')}</div>
      </div>
    case 'search_box':
      return <div style={{ height: 34, borderRadius: 999, background: '#F2F4F8', display: 'flex', alignItems: 'center', padding: '0 12px', gap: 6, color: '#9AA6BC', fontSize: 12 }}>
        <span style={{ fontSize: 13 }}>⌕</span>{stringValue(props, 'placeholder') || '搜索任务、商品或帖子'}
      </div>
    case 'announcement': {
      const iconSize = numberValue(props, 'icon_size_rpx', 48) / 2
      const textColor = stringValue(props, 'text_color') || '#8A6A1F'
      const fromSystem = stringValue(props, 'data_source') === 'system'
      return <div style={{ display: 'flex', alignItems: 'center', gap: 10, padding: '10px 12px', borderRadius: 10, background: '#FFF8E8' }}>
        <span style={{ width: iconSize, height: iconSize, borderRadius: 6, background: '#FFD43B', color: '#8A5C00', fontSize: Math.round(iconSize * .45), fontWeight: 700, display: 'flex', alignItems: 'center', justifyContent: 'center', flexShrink: 0 }}>{stringValue(props, 'icon_text') || '公'}</span>
        <div style={{ minWidth: 0, flex: 1 }}>
          <div style={{ fontSize: numberValue(props, 'title_size_rpx', 28) / 2, fontWeight: 600, color: '#5C4300' }}>{stringValue(props, 'title') || '平台公告'}</div>
          <div style={{ margin: 0, fontSize: 11, color: textColor, whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>{fromSystem ? `系统公告 · 读取 ${numberValue(props, 'limit', 3)} 条` : (plainText(stringValue(props, 'content')) || '暂无公告内容')}</div>
        </div>
        {props.show_arrow !== false ? <span style={{ color: '#B79A52' }}>›</span> : null}
      </div>
    }
    case 'quick_grid': {
      const columns = numberValue(props, 'columns', 4)
      const iconSize = numberValue(props, 'icon_size_rpx', 84) / 2
      const shape = stringValue(props, 'icon_shape') === 'circle' ? '50%' : 10
      const labelSize = numberValue(props, 'label_size_rpx', 24) / 2
      const labelColor = stringValue(props, 'label_color') || '#3C4961'
      const fromCategories = stringValue(props, 'data_source') === 'product_category'
      const entries = fromCategories
        ? Array.from({ length: Math.min(numberValue(props, 'limit', 8), columns * 2) }).map((_, index) => ({ label: `分类 ${index + 1}`, mark: '分', icon_url: '', enabled: true, badge_type: 'none' }))
        : listValue(props, 'items').filter((item) => item.enabled !== false)
      return <div style={{ display: 'grid', gridTemplateColumns: `repeat(${columns}, minmax(0,1fr))`, gap: numberValue(props, 'row_gap_rpx', 20) / 2, padding: 12, background: '#fff', borderRadius: 12 }}>
        {entries.map((item, index) => {
          const badgeType = stringValue(item, 'badge_type') || 'none'
          return <div key={index} style={{ display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 4 }}>
            <div style={{ position: 'relative', width: iconSize, height: iconSize }}>
              {stringValue(item, 'icon_url')
                ? <img src={stringValue(item, 'icon_url')} alt="" style={{ width: '100%', height: '100%', borderRadius: shape, objectFit: 'contain' }} />
                : <span style={{ width: '100%', height: '100%', borderRadius: shape, background: '#EAF1FF', color: '#2F67DF', fontSize: 13, fontWeight: 700, display: 'flex', alignItems: 'center', justifyContent: 'center' }}>{stringValue(item, 'mark') || stringValue(item, 'label').slice(0, 1)}</span>}
              {badgeType === 'dot' ? <span style={{ position: 'absolute', top: -2, right: -2, width: 8, height: 8, borderRadius: 999, background: '#FF5964' }} /> : null}
              {badgeType === 'text' ? <span style={{ position: 'absolute', top: -6, right: -8, padding: '0 5px', borderRadius: 999, background: '#FF5964', color: '#fff', fontSize: 9, fontWeight: 700 }}>{stringValue(item, 'badge_text') || '新'}</span> : null}
              {badgeType === 'image' && stringValue(item, 'badge_image') ? <img src={stringValue(item, 'badge_image')} alt="" style={{ position: 'absolute', top: -8, right: -8, width: 22, height: 22, objectFit: 'contain' }} /> : null}
            </div>
            <span style={{ fontSize: labelSize, color: labelColor, textAlign: 'center' }}>{stringValue(item, 'label') || '入口'}</span>
          </div>
        })}
      </div>
    }
    case 'image_banner':
      return <MediaPlaceholder url={stringValue(props, 'image_url')} height={numberValue(props, 'height_rpx', 200) / 2.4} radius={12} label="横幅图片 · 可在右侧上传" />
    case 'image_text': {
      const rightImage = stringValue(props, 'layout') === 'right'
      return <div style={{ display: 'flex', gap: 10, padding: 10, background: '#fff', borderRadius: 12, flexDirection: rightImage ? 'row-reverse' : 'row' }}>
        <div style={{ width: 84, flexShrink: 0 }}><MediaPlaceholder url={stringValue(props, 'image_url')} height={84} radius={8} /></div>
        <div style={{ minWidth: 0, flex: 1 }}>
          <div style={{ fontSize: 13, fontWeight: 600 }}>{stringValue(props, 'title') || '图文标题'}</div>
          <div style={{ fontSize: 11, color: '#7A859B', marginTop: 4 }}>{stringValue(props, 'description')}</div>
        </div>
      </div>
    }
    case 'category_nav':
      return <div style={{ display: 'flex', gap: 8, overflowX: 'auto', padding: '2px 0' }}>
        {listValue(props, 'items').map((item, index) => <span key={index} style={{ flexShrink: 0, padding: '6px 14px', borderRadius: 999, background: index === 0 ? '#0BBF63' : '#F2F4F8', color: index === 0 ? '#fff' : '#3C4961', fontSize: 12, fontWeight: 600 }}>{stringValue(item, 'label') || '分类'}</span>)}
      </div>
    case 'coupon': {
      const fromCenter = stringValue(props, 'source') === 'center'
      const count = fromCenter ? numberValue(props, 'limit', 3) : listValue(props, 'items').length
      return <div style={{ padding: 10, background: '#fff', borderRadius: 12 }}>
        <div style={{ fontSize: 13, fontWeight: 700, marginBottom: 8 }}>{stringValue(props, 'title') || '优惠券'}</div>
        <div style={{ display: 'flex', gap: 8, overflowX: 'auto' }}>
          {Array.from({ length: Math.max(1, count) }).map((_, index) => {
            const item = fromCenter ? {} : (listValue(props, 'items')[index] ?? {})
            return <div key={index} style={{ flexShrink: 0, width: 150, borderRadius: 10, background: '#FFF4EC', padding: 10, display: 'flex', flexDirection: 'column', gap: 2 }}>
              <span style={{ fontSize: 16, fontWeight: 800, color: '#F2620A' }}>{fromCenter ? '领取' : (stringValue(item, 'value_text') || '¥10')}</span>
              <span style={{ fontSize: 12, fontWeight: 600 }}>{fromCenter ? '优惠券中心模板' : (stringValue(item, 'title') || '优惠券')}</span>
              <span style={{ fontSize: 10, color: '#9A7B67' }}>{fromCenter ? '按后台模板实时展示' : stringValue(item, 'condition')}</span>
              <span style={{ alignSelf: 'flex-start', marginTop: 4, fontSize: 10, fontWeight: 700, color: '#F2620A', border: '1px solid #F2620A', borderRadius: 999, padding: '1px 8px' }}>{fromCenter ? '立即领取' : (stringValue(item, 'button_text') || '立即领取')}</span>
            </div>
          })}
        </div>
      </div>
    }
    case 'product_grid': {
      const columns = numberValue(props, 'columns', 2)
      const limit = Math.min(numberValue(props, 'limit', 6), columns === 1 ? 3 : 6)
      return <div style={{ background: '#fff', borderRadius: 12, padding: 12 }}>
        <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: 8 }}>
          <span style={{ fontSize: 13, fontWeight: 700 }}>{stringValue(props, 'title') || '商品列表'}</span>
          <span style={{ fontSize: 11, color: '#7A859B' }}>查看全部 ›</span>
        </div>
        <div style={{ display: 'grid', gridTemplateColumns: `repeat(${columns}, minmax(0,1fr))`, gap: 8 }}>
          {Array.from({ length: limit }).map((_, index) => <div key={index} style={{ display: 'flex', flexDirection: 'column', gap: 4 }}>
            <MediaPlaceholder url="" height={columns === 1 ? 62 : 70} radius={8} label="商品图" />
            <span style={{ fontSize: 10.5, color: '#3C4961', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>精选商品 {index + 1}</span>
            {props.show_price !== false ? <span style={{ fontSize: 11, fontWeight: 700, color: '#F2620A' }}>¥ 9.9<span style={{ fontSize: 9, color: '#B4A196', fontWeight: 400 }}> 起</span></span> : null}
            {props.show_subtitle !== false ? <span style={{ fontSize: 9.5, color: '#9AA6BC', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>校区自提 · 当日送达</span> : null}
            {props.show_category === true ? <span style={{ alignSelf: 'flex-start', fontSize: 9, color: '#2F67DF', background: '#EAF1FF', borderRadius: 4, padding: '0 4px' }}>分类</span> : null}
          </div>)}
        </div>
      </div>
    }
    case 'post_list': {
      const listMode = stringValue(props, 'layout') === 'list'
      return <div style={{ background: '#fff', borderRadius: 12, padding: 12 }}>
        <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: 8 }}>
          <span style={{ fontSize: 13, fontWeight: 700 }}>{stringValue(props, 'title') || '社区热帖'}</span>
          <span style={{ fontSize: 11, color: '#7A859B' }}>进入社区 ›</span>
        </div>
        <div style={{ display: 'flex', flexDirection: 'column', gap: listMode ? 6 : 10 }}>
          {Array.from({ length: Math.min(numberValue(props, 'limit', 6), 4) }).map((_, index) => <div key={index} style={{ display: 'flex', gap: 8, alignItems: 'center', padding: listMode ? '4px 8px' : 0 }}>
            {props.show_avatar !== false ? <span style={{ width: 26, height: 26, borderRadius: 999, flexShrink: 0, background: 'linear-gradient(135deg, #DDE8FA, #C9DAF6)', color: '#5B719A', fontSize: 10, fontWeight: 700, display: 'flex', alignItems: 'center', justifyContent: 'center' }}>同</span> : null}
            <div style={{ minWidth: 0, flex: 1, background: listMode ? 'transparent' : '#F8FAFD', borderRadius: 8, padding: listMode ? 0 : '6px 8px' }}>
              <div style={{ fontSize: 11.5, fontWeight: 600 }}>校园二手交易经验分享 {index + 1}</div>
              {props.show_excerpt === true ? <div style={{ fontSize: 10, color: '#8B95A6', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>整理了一些实操心得，欢迎一起交流～</div> : null}
              {props.show_stats !== false ? <div style={{ fontSize: 9.5, color: '#9AA6BC' }}>同学 · {index + 2} 条评论 · {index + 3} 赞</div> : null}
            </div>
          </div>)}
        </div>
      </div>
    }
    case 'task_list': {
      const limit = numberValue(props, 'limit', 4)
      return <div style={{ background: '#fff', borderRadius: 12, padding: 12 }}>
        <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: 8 }}>
          <span style={{ fontSize: 13, fontWeight: 700 }}>{stringValue(props, 'title') || '任务列表'}</span>
          <span style={{ fontSize: 11, color: '#7A859B' }}>查看全部 ›</span>
        </div>
        <div style={{ display: 'flex', flexDirection: 'column', gap: 6 }}>
          {Array.from({ length: Math.min(limit, 4) }).map((_, index) => <div key={index} style={{ display: 'flex', alignItems: 'center', gap: 8, borderRadius: 8, background: '#F8FAFD', padding: '7px 10px' }}>
            <span style={{ width: 3, height: 18, borderRadius: 2, background: '#0BBF63' }} />
            <div style={{ flex: 1, minWidth: 0 }}>
              <div style={{ fontSize: 11, fontWeight: 600, color: '#3C4961' }}>代取快递 · 3 号楼 {index + 1}</div>
              <div style={{ fontSize: 9.5, color: '#9AA6BC' }}>积分 +{5 + index * 5} · 预计 20 分钟</div>
            </div>
            <span style={{ fontSize: 10, fontWeight: 700, color: '#0BBF63' }}>去接单</span>
          </div>)}
        </div>
      </div>
    }
    case 'rich_text':
      return <div style={{ background: '#fff', borderRadius: 12, padding: 12, fontSize: 12, color: '#3C4961', lineHeight: 1.6 }}>{plainText(stringValue(props, 'html')) || '富文本内容'}</div>
    case 'spacer':
      return <div style={{ height: Math.max(4, numberValue(props, 'height_rpx', 24) / 2) }} />
    case 'floating_box':
      return <div style={{ display: 'flex', justifyContent: 'flex-end', paddingRight: 4 }}>
        <div style={{ width: 46, height: 46, borderRadius: '50%', background: '#0BBF63', color: '#fff', display: 'flex', alignItems: 'center', justifyContent: 'center', fontSize: 13, fontWeight: 700, boxShadow: '0 6px 16px rgba(11,191,99,.35)' }}>
          {stringValue(props, 'image_url') ? <img src={stringValue(props, 'image_url')} alt="" style={{ width: 46, height: 46, borderRadius: '50%', objectFit: 'cover' }} /> : (stringValue(props, 'icon_text') || '客')}
        </div>
      </div>
    case 'page_title':
      return <div style={{ textAlign: stringValue(props, 'align') === 'center' ? 'center' : 'left', padding: '4px 2px' }}>
        <div style={{ fontSize: 16, fontWeight: 800 }}>{stringValue(props, 'title') || '页面标题'}</div>
        {stringValue(props, 'subtitle') ? <div style={{ fontSize: 11, color: '#7A859B', marginTop: 3 }}>{stringValue(props, 'subtitle')}</div> : null}
      </div>
    case 'list_nav':
      return <div style={{ background: '#fff', borderRadius: 12, padding: '4px 12px' }}>
        {listValue(props, 'items').map((item, index) => <div key={index} style={{ display: 'flex', alignItems: 'center', gap: 10, padding: '10px 0', borderTop: index === 0 ? 'none' : '1px solid #F1F3F7' }}>
          {stringValue(item, 'icon_url') ? <img src={stringValue(item, 'icon_url')} alt="" style={{ width: 28, height: 28, borderRadius: 8, objectFit: 'cover' }} /> : <span style={{ width: 28, height: 28, borderRadius: 8, background: '#EAF1FF', color: '#2F67DF', fontSize: 12, fontWeight: 700, display: 'flex', alignItems: 'center', justifyContent: 'center' }}>{stringValue(item, 'title').slice(0, 1)}</span>}
          <div style={{ flex: 1, minWidth: 0 }}>
            <div style={{ fontSize: 12.5, fontWeight: 600 }}>{stringValue(item, 'title') || '入口'}</div>
            <div style={{ fontSize: 10.5, color: '#8B95A6' }}>{stringValue(item, 'subtitle')}</div>
          </div>
          <span style={{ color: '#B7C0CC' }}>›</span>
        </div>)}
      </div>
    case 'tag_group':
      return <div style={{ background: '#fff', borderRadius: 12, padding: 12 }}>
        {stringValue(props, 'title') ? <div style={{ fontSize: 12.5, fontWeight: 700, marginBottom: 8 }}>{stringValue(props, 'title')}</div> : null}
        <div style={{ display: 'flex', flexWrap: 'wrap', gap: 6 }}>
          {listValue(props, 'items').map((item, index) => <span key={index} style={{ padding: '4px 10px', borderRadius: 999, background: '#F2F4F8', color: '#3C4961', fontSize: 11 }}>{stringValue(item, 'label') || '标签'}</span>)}
        </div>
      </div>
    case 'image_group': {
      const columns = numberValue(props, 'columns', 2)
      return <div style={{ display: 'grid', gridTemplateColumns: `repeat(${columns}, minmax(0,1fr))`, gap: 6 }}>
        {listValue(props, 'items').map((item, index) => <MediaPlaceholder key={index} url={stringValue(item, 'image_url')} height={columns === 2 ? 78 : 64} radius={8} />)}
      </div>
    }
    case 'button_group':
      return <div style={{ display: 'flex', gap: 8 }}>
        {listValue(props, 'items').map((item, index) => <span key={index} style={{ flex: 1, textAlign: 'center', padding: '7px 0', borderRadius: 999, background: index === 0 ? '#0BBF63' : '#F2F4F8', color: index === 0 ? '#fff' : '#3C4961', fontSize: 11.5, fontWeight: 700 }}>{stringValue(item, 'label') || '按钮'}</span>)}
      </div>
    case 'divider':
      return <div style={{ height: 1, borderTop: `1px ${stringValue(props, 'line_style') === 'dashed' ? 'dashed' : 'solid'} ${stringValue(props, 'color') || '#E7EBF2'}` }} />
    case 'activity_group':
      return <div style={{ background: '#fff', borderRadius: 12, padding: 10 }}>
        <div style={{ fontSize: 12.5, fontWeight: 700, marginBottom: 8 }}>{stringValue(props, 'title') || '校园活动'}</div>
        <div style={{ display: 'flex', gap: 8, overflowX: 'auto' }}>
          {listValue(props, 'items').map((item, index) => <div key={index} style={{ flexShrink: 0, width: 130 }}>
            <MediaPlaceholder url={stringValue(item, 'image_url')} height={70} radius={8} />
            <div style={{ fontSize: 11, fontWeight: 600, marginTop: 4 }}>{stringValue(item, 'title') || '活动'}</div>
            <div style={{ fontSize: 10, color: '#8B95A6' }}>{stringValue(item, 'subtitle')}</div>
          </div>)}
        </div>
      </div>
    case 'member_card':
      return <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 10, padding: '12px 14px', borderRadius: 12, background: 'linear-gradient(135deg,#2A3142,#4B5563)', color: '#fff' }}>
        <div>
          <div style={{ fontSize: 13, fontWeight: 700 }}>{stringValue(props, 'title') || '会员中心'}</div>
          <div style={{ fontSize: 10.5, opacity: .8, marginTop: 2 }}>{stringValue(props, 'description')}</div>
        </div>
        <span style={{ flexShrink: 0, padding: '4px 12px', borderRadius: 999, background: '#FFD86B', color: '#6B4A00', fontSize: 10.5, fontWeight: 700 }}>{stringValue(props, 'button_text') || '去开通'}</span>
      </div>
    case 'cart_entry':
      return <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 10, padding: '11px 14px', borderRadius: 12, background: '#EAF7EF' }}>
        <div>
          <div style={{ fontSize: 13, fontWeight: 700, color: '#0A3D23' }}>{stringValue(props, 'title') || '购物车'}</div>
          <div style={{ fontSize: 10.5, color: '#4F7A63', marginTop: 2 }}>{stringValue(props, 'description')}</div>
        </div>
        <span style={{ flexShrink: 0, padding: '4px 12px', borderRadius: 999, background: '#0BBF63', color: '#fff', fontSize: 10.5, fontWeight: 700 }}>{stringValue(props, 'button_text') || '去结算'}</span>
      </div>
    case 'qr_code':
      return <div style={{ display: 'flex', alignItems: 'center', gap: 12, padding: 12, background: '#fff', borderRadius: 12 }}>
        <div style={{ width: 84, flexShrink: 0 }}><MediaPlaceholder url={stringValue(props, 'image_url')} height={84} radius={8} /></div>
        <div>
          <div style={{ fontSize: 13, fontWeight: 700 }}>{stringValue(props, 'title') || '扫码加入'}</div>
          <div style={{ fontSize: 10.5, color: '#8B95A6', marginTop: 3 }}>{stringValue(props, 'description')}</div>
        </div>
      </div>
    case 'location_search':
      return <div style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
        <span style={{ flexShrink: 0, padding: '6px 10px', borderRadius: 999, background: '#EAF7EF', color: '#0A3D23', fontSize: 11, fontWeight: 700 }}>⌖ {stringValue(props, 'campus_text') || '当前校区'}</span>
        <span style={{ flex: 1, height: 32, borderRadius: 999, background: '#F2F4F8', color: '#9AA6BC', fontSize: 11.5, display: 'flex', alignItems: 'center', padding: '0 12px' }}>{stringValue(props, 'placeholder') || '搜索'}</span>
      </div>
    case 'order_marquee':
      return <div style={{ display: 'flex', alignItems: 'center', gap: 8, padding: '9px 12px', borderRadius: 10, background: stringValue(props, 'bg_color') || '#FFF8E8', overflow: 'hidden', opacity: numberValue(props, 'opacity', 100) / 100 }}>
        <span style={{ width: 20, height: 20, borderRadius: 6, background: '#FFD43B', color: '#8A5C00', fontSize: 10, fontWeight: 800, display: 'flex', alignItems: 'center', justifyContent: 'center', flexShrink: 0 }}>{stringValue(props, 'icon_text') || '动'}</span>
        <span style={{ fontSize: 11.5, color: stringValue(props, 'text_color') || '#8A6A1F', whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>{listValue(props, 'items').map((item) => stringValue(item, 'text')).filter(Boolean).join('　·　') || '暂无动态'}</span>
      </div>
    case 'video': {
      const ratio = stringValue(props, 'ratio') || 'auto'
      const height = ratio === '16:9' ? 168 : ratio === '4:3' ? 225 : ratio === '1:1' ? 300 : numberValue(props, 'height_rpx', 220) / 2.4
      return <div style={{ position: 'relative', height, borderRadius: 12, background: '#1F2430', overflow: 'hidden', display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
        {stringValue(props, 'poster_url') ? <img src={stringValue(props, 'poster_url')} alt="" style={{ width: '100%', height: '100%', objectFit: 'cover', opacity: .8 }} /> : null}
        <span style={{ position: 'absolute', width: 40, height: 40, borderRadius: '50%', background: 'rgba(255,255,255,.9)', color: '#0A3D23', display: 'flex', alignItems: 'center', justifyContent: 'center', fontSize: 15 }}>▶</span>
      </div>
    }
    case 'tab_nav':
      return <div style={{ display: 'flex', gap: 8 }}>
        {listValue(props, 'items').map((item, index) => <span key={index} style={{ flex: 1, textAlign: 'center', padding: '6px 0', borderRadius: 8, background: index === 0 ? (stringValue(props, 'active_color') || '#0BBF63') : (stringValue(props, 'background_color') || '#F2F4F8'), color: index === 0 ? '#fff' : '#3C4961', fontSize: 11.5, fontWeight: 600 }}>{stringValue(item, 'label') || '选项'}</span>)}
      </div>
    case 'ad_banner':
      return <div style={{ position: 'relative', borderRadius: 12, overflow: 'hidden' }}>
        <MediaPlaceholder url={stringValue(props, 'image_url')} height={numberValue(props, 'height_rpx', 160) / 2.4} radius={12} />
        {props.show_badge !== false ? <span style={{ position: 'absolute', right: 6, bottom: 6, padding: '1px 6px', borderRadius: 4, background: 'rgba(0,0,0,.45)', color: '#fff', fontSize: 9 }}>广告</span> : null}
      </div>
    case 'image_showcase': {
      const items = listValue(props, 'items')
      const isRight = stringValue(props, 'layout') === 'right'
      return <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gridTemplateRows: '70px 70px', gap: 6 }}>
        {items.slice(0, 3).map((item, index) => <div key={index} style={{ position: 'relative', gridRow: index === 0 ? '1 / span 2' : 'auto', gridColumn: index === 0 && isRight ? 2 : 'auto', borderRadius: 8, overflow: 'hidden' }}>
          <MediaPlaceholder url={stringValue(item, 'image_url')} height={index === 0 ? 146 : 70} radius={8} />
          {stringValue(item, 'title') ? <span style={{ position: 'absolute', left: 6, bottom: 6, color: '#fff', fontSize: 10, background: 'rgba(0,0,0,.35)', padding: '1px 6px', borderRadius: 4 }}>{stringValue(item, 'title')}</span> : null}
        </div>)}
      </div>
    }
    case 'notice_bar': {
      const tone = stringValue(props, 'tone') || 'warning'
      const palette = tone === 'success' ? { bg: '#EAF7EF', color: '#0A3D23' } : tone === 'info' ? { bg: '#EAF2FF', color: '#1E3A8A' } : tone === 'normal' ? { bg: '#F4F6FA', color: '#3C4961' } : { bg: '#FFF2E5', color: '#8A4B00' }
      return <div style={{ display: 'flex', gap: 8, alignItems: 'flex-start', padding: '10px 12px', borderRadius: 10, background: palette.bg, color: palette.color }}>
        {props.show_icon !== false ? <span style={{ fontSize: 12, fontWeight: 800 }}>!</span> : null}
        <div>
          <div style={{ fontSize: numberValue(props, 'title_size_rpx', 26) / 2, fontWeight: 700 }}>{stringValue(props, 'title') || '温馨提示'}</div>
          <div style={{ fontSize: numberValue(props, 'content_size_rpx', 22) / 2, opacity: .85 }}>{stringValue(props, 'content')}</div>
        </div>
      </div>
    }
    case 'hot_tags':
      return <div style={{ background: '#fff', borderRadius: 12, padding: 12 }}>
        <div style={{ fontSize: 12.5, fontWeight: 700, marginBottom: 8 }}>{stringValue(props, 'title') || '热门标签'}</div>
        <div style={{ display: 'flex', flexWrap: 'wrap', gap: 6 }}>
          {listValue(props, 'items').map((item, index) => <span key={index} style={{ display: 'inline-flex', alignItems: 'center', gap: 4, padding: '4px 10px', borderRadius: 999, background: '#F2F4F8', color: '#3C4961', fontSize: 11 }}>
            {stringValue(item, 'label') || '标签'}{item.hot === true ? <span style={{ fontSize: 8, fontWeight: 800, color: '#fff', background: '#FF5964', borderRadius: 3, padding: '0 3px' }}>HOT</span> : null}
          </span>)}
        </div>
      </div>
    case 'tile_ad': {
      const items = listValue(props, 'items')
      const layout = stringValue(props, 'layout') || 'big-left'
      const radius = numberValue(props, 'radius_rpx', 12) / 2
      const gap = numberValue(props, 'gap_rpx', 8) / 2
      const bigRight = layout === 'big-right'
      const titleSize = numberValue(props, 'title_size_rpx', 30) / 2
      const subtitleSize = numberValue(props, 'subtitle_size_rpx', 22) / 2
      const tile = (item: Record<string, unknown>, big: boolean) => <div style={{ position: 'relative', height: big ? 148 : 70, borderRadius: radius, overflow: 'hidden', background: '#EEF2F9', display: 'flex', alignItems: 'flex-end' }}>
        {stringValue(item, 'image_url') ? <img src={stringValue(item, 'image_url')} alt="" style={{ position: 'absolute', inset: 0, width: '100%', height: '100%', objectFit: 'cover' }} /> : null}
        <div style={{ position: 'relative', width: '100%', padding: 8, background: stringValue(item, 'image_url') ? 'linear-gradient(180deg, rgba(0,0,0,0) 20%, rgba(0,0,0,.55))' : 'transparent' }}>
          {stringValue(item, 'tag') ? <span style={{ display: 'inline-block', marginBottom: 3, padding: '0 6px', borderRadius: 4, background: stringValue(props, 'tag_bg') || '#0BBF63', color: stringValue(props, 'tag_color') || '#FFFFFF', fontSize: 9, fontWeight: 700 }}>{stringValue(item, 'tag')}</span> : null}
          <div style={{ fontSize: titleSize, fontWeight: 700, color: stringValue(props, 'title_color') || '#FFFFFF' }}>{stringValue(item, 'title') || '瓷片标题'}</div>
          <div style={{ fontSize: subtitleSize, color: stringValue(props, 'subtitle_color') || '#F2F4F8' }}>{stringValue(item, 'subtitle')}</div>
        </div>
      </div>
      if (layout === 'three') {
        return <div style={{ display: 'grid', gridTemplateColumns: 'repeat(3, minmax(0,1fr))', gap }}>{items.slice(0, 3).map((item, index) => <div key={index}>{tile(item, false)}</div>)}</div>
      }
      return <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap }}>
        <div style={{ gridRow: '1 / span 2', gridColumn: bigRight ? 2 : 1 }}>{tile(items[0] ?? {}, true)}</div>
        <div style={{ gridColumn: bigRight ? 1 : 2 }}>{tile(items[1] ?? {}, false)}</div>
        <div style={{ gridColumn: bigRight ? 1 : 2 }}>{tile(items[2] ?? {}, false)}</div>
      </div>
    }
    case 'popup': {
      const width = Math.min(286, numberValue(props, 'width_rpx', 560) / 2)
      return <div style={{ display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 6, padding: '10px 0' }}>
        <div style={{ width, background: '#fff', borderRadius: numberValue(props, 'radius_rpx', 20) / 2, overflow: 'hidden', boxShadow: '0 8px 24px rgba(15,25,20,.18)' }}>
          <MediaPlaceholder url={stringValue(props, 'image_url')} height={104} radius={0} />
          <div style={{ padding: 10, textAlign: 'center' }}>
            <div style={{ fontSize: 13, fontWeight: 700 }}>{stringValue(props, 'title') || '弹窗标题'}</div>
            <div style={{ marginTop: 4, fontSize: 10.5, color: '#7A859B', lineHeight: 1.5 }}>{stringValue(props, 'content') || '弹窗内容'}</div>
            {props.closable !== false ? <div style={{ marginTop: 6, fontSize: 9, color: '#AEB3BB' }}>允许关闭 · ×</div> : null}
            <span style={{ display: 'inline-block', marginTop: 8, padding: '4px 16px', borderRadius: 999, background: '#0BBF63', color: '#fff', fontSize: 10.5, fontWeight: 700 }}>{stringValue(props, 'button_text') || '立即查看'}</span>
          </div>
        </div>
        <span style={{ fontSize: 9.5, color: '#AEB3BB' }}>用户端为浮层展示</span>
      </div>
    }
    case 'brand_card':
      return <div style={{ display: 'flex', alignItems: 'center', gap: 9 }}>
        <div style={{ width: 32, height: 32, borderRadius: 9, background: 'linear-gradient(135deg,#0BBF63,#0A9D54)', color: '#fff', fontSize: 14, fontWeight: 800, display: 'flex', alignItems: 'center', justifyContent: 'center' }}>{stringValue(props, 'logo_text') || 'TM'}</div>
        <div><div style={{ fontSize: 16, fontWeight: 800, color: '#1F2937' }}>{stringValue(props, 'name') || '任务商城'}</div><div style={{ fontSize: 8.5, letterSpacing: 1, color: '#9AA5B8' }}>{stringValue(props, 'caption')}</div></div>
      </div>
    case 'point_card':
      return <div style={{ background: 'linear-gradient(135deg,#0BBF63,#0A9D54)', borderRadius: 12, padding: 14, color: '#fff', display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
        <div><div style={{ fontSize: 12, fontWeight: 700 }}>{stringValue(props, 'points_label') || '我的积分'} ›</div><div style={{ fontSize: 9, opacity: .85 }}>积分越多，兑换越多</div></div>
        <span style={{ padding: '5px 13px', borderRadius: 999, background: 'rgba(255,255,255,.22)', fontSize: 10, fontWeight: 600 }}>{stringValue(props, 'sign_button_text') || '每日签到'}</span>
      </div>
    case 'recommendation_card':
      return <div style={{ display: 'flex', flexDirection: 'column', gap: 5 }}>
        <div style={{ fontSize: 16, fontWeight: 800, color: '#1F2937' }}>{stringValue(props, 'section_title') || '今日推荐'}</div>
        <div style={{ fontSize: 10, color: '#8B95A6' }}>{stringValue(props, 'section_subtitle')}</div>
        <div style={{ display: 'flex', gap: 9, background: '#fff', borderRadius: 11, padding: 12, boxShadow: '0 3px 10px rgba(15,30,20,.06)' }}>
          <div style={{ width: 30, height: 30, borderRadius: 9, background: '#EAF7EF', color: '#0A9D54', fontSize: 14, fontWeight: 800, display: 'flex', alignItems: 'center', justifyContent: 'center' }}>{stringValue(props, 'icon_text') || '新'}</div>
          <div style={{ flex: 1 }}>
            <div style={{ display: 'flex', alignItems: 'center', gap: 5 }}><span style={{ fontSize: 14, fontWeight: 700, color: '#1F2937' }}>{stringValue(props, 'title')}</span>{stringValue(props, 'tag') ? <span style={{ padding: '1px 6px', borderRadius: 4, background: '#FFF2E5', color: '#D96B1A', fontSize: 9 }}>{stringValue(props, 'tag')}</span> : null}</div>
            <div style={{ fontSize: 10, color: '#7A859B', marginTop: 2 }}>{stringValue(props, 'description')}</div>
            <div style={{ marginTop: 4 }}><span style={{ fontSize: 11, fontWeight: 700, color: '#FF5964' }}>{stringValue(props, 'reward_text')}</span>{stringValue(props, 'time_text') ? <span style={{ fontSize: 9, color: '#9AA5B8', marginLeft: 8 }}>{stringValue(props, 'time_text')}</span> : null}</div>
          </div>
        </div>
      </div>
    case 'product_section':
      return <div style={{ display: 'flex', flexDirection: 'column', gap: 7 }}>
        <div style={{ fontSize: 16, fontWeight: 800, color: '#1F2937' }}>{stringValue(props, 'section_title') || '精选兑换'}</div>
        <div style={{ fontSize: 10, color: '#8B95A6' }}>{stringValue(props, 'section_subtitle')}</div>
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 8 }}>
          {(Array.isArray(props.cards) ? props.cards : []).map((card: Record<string, unknown>, i: number) => (
            <div key={i} style={{ borderRadius: 10, padding: 12, minHeight: 80, display: 'flex', flexDirection: 'column', justifyContent: 'flex-end', gap: 3, background: (card.tone === 'warm' ? 'linear-gradient(135deg,#FFF3E6,#FFE4CC)' : 'linear-gradient(135deg,#E8F1FF,#D4E4FF)') }}>
              <div style={{ fontSize: 9, color: '#6B7893' }}>{stringValue(card, 'eyebrow')}</div>
              <div style={{ fontSize: 13, fontWeight: 700, color: '#1F2937' }}>{stringValue(card, 'title')}</div>
              <div style={{ fontSize: 11, fontWeight: 700, color: '#FF5964' }}>{stringValue(card, 'price_text')}</div>
            </div>
          ))}
        </div>
      </div>
    case 'trust_card':
      return <div style={{ display: 'flex', alignItems: 'center', gap: 9, background: '#F4F6FA', borderRadius: 10, padding: 12 }}>
        <div style={{ flex: 1 }}><div style={{ fontSize: 13, fontWeight: 700, color: '#1F2937' }}>{stringValue(props, 'title') || '每一笔资产都有记录'}</div><div style={{ fontSize: 10, color: '#7A859B', marginTop: 2 }}>{stringValue(props, 'description')}</div></div>
        <span style={{ fontSize: 16, color: '#9AA5B8' }}>›</span>
      </div>
    case 'title_bar':
      return <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 10 }}>
        <div><div style={{ fontSize: numberValue(props, 'title_size_rpx', 32) / 2, fontWeight: 800, color: stringValue(props, 'title_color') || '#1F2937' }}>{stringValue(props, 'title') || '标题'}</div><div style={{ fontSize: 10.5, color: '#8B95A6' }}>{stringValue(props, 'subtitle')}</div></div>
        <span style={{ fontSize: 11, color: stringValue(props, 'more_color') || '#0BBF63', fontWeight: 600 }}>{stringValue(props, 'more_text') || '更多'} ›</span>
      </div>
    default:
      return null
  }
}

export function DecorationPreview({ page }: { page: DecorationPageConfig }) {
  const sections = page.sections.filter((section) => section.enabled)
  return <div style={{ width: 320, margin: '0 auto', border: '10px solid #1F2430', borderRadius: 30, background: page.background_url ? `url(${page.background_url}) center/cover` : (page.background_color || '#F5F7FB'), minHeight: 520, padding: 10, display: 'flex', flexDirection: 'column', gap: 10 }}>
    <div style={{ height: 18, borderRadius: 999, background: 'rgba(0,0,0,.12)', width: 90, margin: '0 auto', flexShrink: 0 }} />
    {page.title ? <div style={{ textAlign: 'center', fontSize: 12, fontWeight: 700, color: '#3C4961' }}>{page.title}</div> : null}
    {sections.length === 0
      ? <div style={{ marginTop: 60 }}><Empty description="暂无可见区块" image={Empty.PRESENTED_IMAGE_SIMPLE} /></div>
      : sections.map((section) => <div key={section.id} style={sectionOuterStyle(section)}><SectionPreview section={section} /></div>)}
  </div>
}
