import type { ApiRequest } from '~/composables/useApi'
import type { CallExclusionCategory, CallExclusionImageData, CallExclusionRequestResult, MissedCalls } from '../types'

/** all = true: every incoming call, not only the missed ones */
export function fetchMissedCalls(api: ApiRequest, orgUnitId: number, from?: string | null, to?: string | null, all = false): Promise<MissedCalls> {
  return api<MissedCalls>(`/api/org-units/${orgUnitId}/missed-calls`, { query: { ...(from ? { from } : {}), ...(to ? { to } : {}), ...(all ? { all: 'true' } : {}) } })
}

/** image: optional screenshot as base64 (PNG, JPG or WebP up to 4 MB) */
export function requestCallExclusion(api: ApiRequest, orgUnitId: number, callId: number, reason: string, category: CallExclusionCategory, image: string | null = null): Promise<CallExclusionRequestResult> {
  return api(`/api/org-units/${orgUnitId}/call-exclusions`, { method: 'POST', body: { callId, reason, category, ...(image ? { image } : {}) } })
}

/** recalculated = false: the metrics are updated by the next sync (within 30 minutes) */
export function decideCallExclusion(api: ApiRequest, id: number, approve: boolean, note: string | null): Promise<{ recalculated: boolean }> {
  return api(`/api/call-exclusions/${id}/decision`, { method: 'POST', body: { approve, note } })
}

export function fetchCallExclusionImage(api: ApiRequest, id: number): Promise<CallExclusionImageData> {
  return api(`/api/call-exclusions/${id}/image`)
}

export function withdrawCallExclusion(api: ApiRequest, id: number): Promise<void> {
  return api(`/api/call-exclusions/${id}`, { method: 'DELETE' })
}
