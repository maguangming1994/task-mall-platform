import { apiRequest } from './http'

export interface LegalDocument {
  id: string
  document_type: string
  version: string
  title: string
  content_format: 'markdown' | 'rich_text_html'
  content: string
  content_hash: string
  effective_at: string
  current: boolean
  consent_required: boolean
  withdrawal_allowed: boolean
  change_summary: string | null
}

export function loadCurrentLegalDocuments(documentType?: string) {
  return apiRequest<LegalDocument[]>({
    path: `/legal-documents${documentType ? `?document_type=${encodeURIComponent(documentType)}` : ''}`,
  })
}

export function loadPendingLegalDocuments(accessToken: string, scope?: string) {
  return apiRequest<LegalDocument[]>({
    path: `/me/legal-consents/pending${scope ? `?scope=${encodeURIComponent(scope)}` : ''}`,
    accessToken,
  })
}

export function acceptLegalDocument(accessToken: string, documentId: string) {
  return apiRequest<void>({
    path: '/me/legal-consents',
    method: 'POST',
    accessToken,
    headers: { 'Idempotency-Key': `legal-${documentId}-${Date.now()}-${Math.random().toString(36).slice(2, 10)}` },
    data: {
      legal_document_version_id: documentId,
      action: 'accept',
    },
  })
}
