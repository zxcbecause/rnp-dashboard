import type { ApiRequest } from '~/composables/useApi'
import type { ActionsWeek, BaseFunctionInput, RoadmapEffect, RoadmapItemInput } from '../types'

export function fetchActionsWeek(api: ApiRequest, orgUnitId: number, week?: string | null): Promise<ActionsWeek> {
  return api<ActionsWeek>(`/api/org-units/${orgUnitId}/actions`, { query: week ? { week } : {} })
}

export function createBaseFunction(api: ApiRequest, orgUnitId: number, input: BaseFunctionInput): Promise<{ id: number }> {
  return api(`/api/org-units/${orgUnitId}/base-functions`, { method: 'POST', body: input })
}

export function updateBaseFunction(api: ApiRequest, id: number, input: BaseFunctionInput): Promise<void> {
  return api(`/api/base-functions/${id}`, { method: 'PUT', body: input })
}

/** done = null removes the mark */
export function saveCheck(api: ApiRequest, id: number, periodStart: string, done: boolean | null): Promise<void> {
  return api(`/api/base-functions/${id}/checks`, { method: 'PUT', body: { periodStart, done } })
}

export function createRoadmapItem(api: ApiRequest, metricId: number, input: RoadmapItemInput): Promise<{ id: number }> {
  return api(`/api/metrics/${metricId}/roadmap`, { method: 'POST', body: input })
}

export function updateRoadmapItem(api: ApiRequest, id: number, input: RoadmapItemInput): Promise<void> {
  return api(`/api/roadmap-items/${id}`, { method: 'PUT', body: input })
}

export function assessEffect(api: ApiRequest, id: number, effect: RoadmapEffect, note: string | null): Promise<void> {
  return api(`/api/roadmap-items/${id}/effect`, { method: 'POST', body: { effect, note } })
}
