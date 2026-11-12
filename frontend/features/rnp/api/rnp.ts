import type { ApiRequest } from '~/composables/useApi'
import type { DeviationDraft, DeviationNote, MetricViewItem, PeriodType, PeriodValueInput, RnpWeek } from '../types'

export function fetchRnpWeek(api: ApiRequest, orgUnitId: number, week?: string | null, showHidden = false): Promise<RnpWeek> {
  return api<RnpWeek>(`/api/org-units/${orgUnitId}/rnp`, {
    query: { ...(week ? { week } : {}), ...(showHidden ? { showHidden: 'true' } : {}) },
  })
}

/** Personal order and visibility: every active metric of the unit, in display order. */
export function saveMetricView(api: ApiRequest, orgUnitId: number, items: MetricViewItem[]): Promise<void> {
  return api(`/api/org-units/${orgUnitId}/metric-view`, { method: 'PUT', body: { items } })
}

export function resetMetricView(api: ApiRequest, orgUnitId: number): Promise<void> {
  return api(`/api/org-units/${orgUnitId}/metric-view`, { method: 'DELETE' })
}

export function saveFact(api: ApiRequest, metricId: number, input: PeriodValueInput): Promise<{ value: string | null }> {
  return api(`/api/metrics/${metricId}/facts`, { method: 'PUT', body: input })
}

export function savePlan(api: ApiRequest, metricId: number, input: PeriodValueInput): Promise<{ value: string | null }> {
  return api(`/api/metrics/${metricId}/plans`, { method: 'PUT', body: input })
}

/** Reason + action of a deviation; an empty reason removes the note. */
export function saveDeviationNote(
  api: ApiRequest,
  metricId: number,
  input: { periodType: PeriodType, periodStart: string, reason: string, action: string },
): Promise<{ note: DeviationNote | null }> {
  return api(`/api/metrics/${metricId}/deviation-note`, { method: 'PUT', body: input })
}

/** AI proposal for the note (10–40 s); nothing is saved. */
export function draftDeviationNote(api: ApiRequest, metricId: number, input: { periodType: PeriodType, periodStart: string }): Promise<DeviationDraft> {
  return api(`/api/metrics/${metricId}/ai/deviation-draft`, { method: 'POST', body: input })
}
