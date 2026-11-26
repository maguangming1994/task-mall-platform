import { apiRequest } from '@/api/http'

export type PageDecorationKey = 'home' | 'community' | 'mall' | 'account'

export interface DecorationSection {
  id: string
  type: string
  enabled: boolean
  props: Record<string, unknown>
}

export interface DecorationPageConfig {
  background_url: string
  sections: DecorationSection[]
}

export interface PageDecoration {
  schema_version: number
  pages: Record<PageDecorationKey, DecorationPageConfig>
  version: number | null
}

export function emptyPageDecoration(): PageDecoration {
  return {
    schema_version: 1,
    version: null,
    pages: {
      home: { background_url: '', sections: [] },
      community: { background_url: '', sections: [] },
      mall: { background_url: '', sections: [] },
      account: { background_url: '', sections: [] },
    },
  }
}

export async function loadPageDecoration(): Promise<PageDecoration> {
  try {
    return await apiRequest<PageDecoration>({ path: '/page-decoration' })
  } catch {
    return emptyPageDecoration()
  }
}
