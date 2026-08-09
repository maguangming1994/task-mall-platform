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
