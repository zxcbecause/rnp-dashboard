import type { ApiRequest } from '~/composables/useApi'
import type { CreatedIngestToken, IngestToken } from '../types'

export function fetchIngestTokens(api: ApiRequest): Promise<IngestToken[]> {
  return api<IngestToken[]>('/api/admin/ingest-tokens')
}

export function createIngestToken(api: ApiRequest, name: string, orgUnitId: number): Promise<CreatedIngestToken> {
  return api<CreatedIngestToken>('/api/admin/ingest-tokens', { method: 'POST', body: { name, orgUnitId } })
}

export function revokeIngestToken(api: ApiRequest, id: number): Promise<IngestToken> {
  return api<IngestToken>(`/api/admin/ingest-tokens/${id}/revoke`, { method: 'POST' })
}
