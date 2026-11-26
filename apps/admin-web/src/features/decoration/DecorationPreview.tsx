import { Empty, Typography } from 'antd'
import type { DecorationPageConfig } from '../../api/admin'

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

function MediaPlaceholder({ url, height = 96, radius = 10 }: { url: string; height?: number; radius?: number }) {
  if (url) return <img src={url} alt="" style={{ width: '100%', height, objectFit: 'cover', borderRadius: radius, display: 'block' }} />
  return <div style={{ width: '100%', height, borderRadius: radius, background: '#EEF2F9', display: 'flex', alignItems: 'center', justifyContent: 'center', color: '#9AA6BC', fontSize: 12 }}>未设置图片</div>
}

function SectionPreview({ section }: { section: { type: string; props: Record<string, unknown> } }) {
  const props = section.props
  switch (section.type) {
    case 'banner': {
      const first = listValue(props, 'items')[0] ?? {}
      return <div style={{ position: 'relative', borderRadius: 12, overflow: 'hidden' }}>
        <MediaPlaceholder url={stringValue(first, 'image_url')} height={140} radius={12} />
        <div style={{ position: 'absolute', inset: 0, padding: 14, display: 'flex', flexDirection: 'column', justifyContent: 'flex-end', background: 'linear-gradient(180deg, rgba(0,0,0,0) 40%, rgba(0,0,0,.45))', color: '#fff' }}>
          <div style={{ fontSize: 15, fontWeight: 700 }}>{stringValue(first, 'title') || '轮播标题'}</div>
          <div style={{ fontSize: 11, opacity: .9 }}>{stringValue(first, 'subtitle')}</div>
          {stringValue(first, 'button_text') ? <span style={{ alignSelf: 'flex-start', marginTop: 8, padding: '4px 12px', borderRadius: 999, background: '#fff', color: '#2F67DF', fontSize: 11, fontWeight: 600 }}>{stringValue(first, 'button_text')}</span> : null}
        </div>
        <div style={{ position: 'absolute', bottom: 8, right: 12, display: 'flex', gap: 4 }}>
          {listValue(props, 'items').map((_, index) => <span key={index} style={{ width: 6, height: 6, borderRadius: 999, background: index === 0 ? '#fff' : 'rgba(255,255,255,.5)' }} />)}
        </div>
      </div>
    }
    case 'announcement':
      return <div style={{ display: 'flex', alignItems: 'flex-start', gap: 10, padding: '10px 12px', borderRadius: 10, background: '#FFF8E8' }}>
        <span style={{ width: 22, height: 22, borderRadius: 6, background: '#FFD43B', color: '#8A5C00', fontSize: 12, fontWeight: 700, display: 'flex', alignItems: 'center', justifyContent: 'center' }}>{stringValue(props, 'icon_text') || '公'}</span>
        <div style={{ minWidth: 0 }}>
          <div style={{ fontSize: 13, fontWeight: 600, color: '#5C4300' }}>{stringValue(props, 'title') || '平台公告'}</div>
          <Typography.Paragraph ellipsis={{ rows: 2 }} style={{ margin: 0, fontSize: 11, color: '#8A6A1F' }}>{plainText(stringValue(props, 'content')) || '暂无公告内容'}</Typography.Paragraph>
        </div>
      </div>
    case 'quick_grid': {
      const columns = numberValue(props, 'columns', 4)
      return <div style={{ display: 'grid', gridTemplateColumns: `repeat(${columns}, minmax(0,1fr))`, gap: 10, padding: 12, background: '#fff', borderRadius: 12 }}>
        {listValue(props, 'items').filter((item) => item.enabled !== false).map((item, index) => <div key={index} style={{ display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 4 }}>
          {stringValue(item, 'icon_url')
            ? <img src={stringValue(item, 'icon_url')} alt="" style={{ width: 32, height: 32, objectFit: 'contain' }} />
            : <span style={{ width: 32, height: 32, borderRadius: 10, background: '#EAF1FF', color: '#2F67DF', fontSize: 13, fontWeight: 700, display: 'flex', alignItems: 'center', justifyContent: 'center' }}>{stringValue(item, 'mark') || stringValue(item, 'label').slice(0, 1)}</span>}
          <span style={{ fontSize: 11, color: '#3C4961', textAlign: 'center' }}>{stringValue(item, 'label') || '入口'}</span>
        </div>)}
      </div>
    }
    case 'image_banner':
      return <MediaPlaceholder url={stringValue(props, 'image_url')} height={numberValue(props, 'height_rpx', 200) / 2.4} radius={12} />
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
    case 'product_grid':
    case 'post_list':
    case 'task_list': {
      const label = section.type === 'product_grid' ? '商品' : section.type === 'post_list' ? '帖子' : '任务'
      const limit = numberValue(props, 'limit', 4)
      return <div style={{ background: '#fff', borderRadius: 12, padding: 12 }}>
        <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: 8 }}>
          <span style={{ fontSize: 13, fontWeight: 700 }}>{stringValue(props, 'title') || `${label}列表`}</span>
          <span style={{ fontSize: 11, color: '#7A859B' }}>查看全部 ›</span>
        </div>
        <div style={{ display: 'flex', flexDirection: 'column', gap: 6 }}>
          {Array.from({ length: Math.min(limit, 4) }).map((_, index) => <div key={index} style={{ height: 30, borderRadius: 8, background: '#F4F6FB', display: 'flex', alignItems: 'center', padding: '0 10px', fontSize: 11, color: '#9AA6BC' }}>{label}占位 {index + 1}</div>)}
        </div>
      </div>
    }
    case 'rich_text':
      return <div style={{ background: '#fff', borderRadius: 12, padding: 12, fontSize: 12, color: '#3C4961', lineHeight: 1.6 }}>{plainText(stringValue(props, 'html')) || '富文本内容'}</div>
    case 'spacer':
      return <div style={{ height: Math.max(4, numberValue(props, 'height_rpx', 24) / 2) }} />
    default:
      return null
  }
}

export function DecorationPreview({ page }: { page: DecorationPageConfig }) {
  const sections = page.sections.filter((section) => section.enabled)
  return <div style={{ width: 320, margin: '0 auto', border: '10px solid #1F2430', borderRadius: 30, background: page.background_url ? `url(${page.background_url}) center/cover` : '#F5F7FB', minHeight: 520, padding: 10, display: 'flex', flexDirection: 'column', gap: 10 }}>
    <div style={{ height: 18, borderRadius: 999, background: 'rgba(0,0,0,.12)', width: 90, margin: '0 auto' }} />
    {sections.length === 0
      ? <div style={{ marginTop: 60 }}><Empty description="暂无可见区块" image={Empty.PRESENTED_IMAGE_SIMPLE} /></div>
      : sections.map((section) => <SectionPreview key={section.id} section={section} />)}
  </div>
}
