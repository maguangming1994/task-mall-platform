const entities: Record<string, string> = {
  '&nbsp;': ' ', '&amp;': '&', '&lt;': '<', '&gt;': '>', '&quot;': '"', '&#39;': "'",
}

export function plainTextFromRich(value?: string | null): string {
  if (!value) return ''
  return value
    .replace(/<(br|\/p|\/li|\/h2|\/h3|\/blockquote|\/tr)>/gi, '\n')
    .replace(/<[^>]*>/g, '')
    .replace(/&(nbsp|amp|lt|gt|quot|#39);/g, (entity) => entities[entity] ?? entity)
    .replace(/[\t ]+/g, ' ')
    .replace(/\n{3,}/g, '\n\n')
    .trim()
}

export function richTextContent(value?: string | null): string {
  if (!value) return ''
  if (/<[a-z][\s\S]*>/i.test(value)) return value
  return `<p>${value.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/\n/g, '<br>')}</p>`
}

export function compactRichTextContent(value?: string | null): string {
  return richTextContent(value)
    .replace(/<img\b[^>]*>/gi, '')
    .replace(/<table\b[^>]*>[\s\S]*?<\/table>/gi, '')
    .replace(/<(p|h2|h3|ul|ol|blockquote)(\s[^>]*)?>/gi, (_match, tag: string, attributes = '') => {
      const existingStyle = attributes.match(/\sstyle="([^"]*)"/i)?.[1] ?? ''
      const remainingAttributes = attributes.replace(/\sstyle="[^"]*"/i, '')
      return `<${tag}${remainingAttributes} style="${existingStyle};margin:0;padding:0;font-size:inherit;line-height:inherit">`
    })
}

export type MarkdownLineKind = 'h1' | 'h2' | 'h3' | 'quote' | 'list' | 'plain'
export interface MarkdownLine { text: string; kind: MarkdownLineKind }

/* 轻量 Markdown 按行解析（无第三方依赖）：识别标题、引用、列表、正文，并去除简单加粗标记 */
export function markdownLines(content?: string | null): MarkdownLine[] {
  if (!content) return []
  return content.split(/\r?\n/).reduce<MarkdownLine[]>((lines, raw) => {
    const text = raw.trim()
    if (!text) return lines
    let kind: MarkdownLineKind = 'plain'
    let body = text
    if (body.startsWith('### ')) { kind = 'h3'; body = body.slice(4) }
    else if (body.startsWith('## ')) { kind = 'h2'; body = body.slice(3) }
    else if (body.startsWith('# ')) { kind = 'h1'; body = body.slice(2) }
    else if (body.startsWith('> ')) { kind = 'quote'; body = body.slice(2) }
    else if (/^[-*]\s+/.test(body)) { kind = 'list'; body = body.replace(/^[-*]\s+/, '· ') }
    else if (/^\d+\.\s+/.test(body)) { kind = 'list' }
    lines.push({ text: body.replace(/\*\*(.+?)\*\*/g, '$1'), kind })
    return lines
  }, [])
}
