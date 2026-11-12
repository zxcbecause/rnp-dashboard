import type { ApiRequest } from '~/composables/useApi'
import type { ErrorEntryInput, ErrorLog } from '../types'

export function fetchErrorLog(api: ApiRequest, orgUnitId: number, from?: string | null, to?: string | null): Promise<ErrorLog> {
  return api<ErrorLog>(`/api/org-units/${orgUnitId}/errors`, { query: { ...(from ? { from } : {}), ...(to ? { to } : {}) } })
}

export function createErrorEntry(api: ApiRequest, orgUnitId: number, input: ErrorEntryInput): Promise<{ id: number }> {
  return api(`/api/org-units/${orgUnitId}/errors`, { method: 'POST', body: input })
}

export function updateErrorEntry(api: ApiRequest, id: number, input: ErrorEntryInput): Promise<void> {
  return api(`/api/errors/${id}`, { method: 'PUT', body: input })
}
