import type { ApiRequest } from '~/composables/useApi'
import type { RecurringTaskInput, SprintTask, SprintTaskInput, SprintWeek } from '../types'

export function fetchSprintWeek(api: ApiRequest, orgUnitId: number, week?: string | null): Promise<SprintWeek> {
  return api<SprintWeek>(`/api/org-units/${orgUnitId}/sprint`, { query: week ? { week } : {} })
}

export function createSprintTask(api: ApiRequest, orgUnitId: number, input: SprintTaskInput): Promise<{ id: number }> {
  return api(`/api/org-units/${orgUnitId}/sprint-tasks`, { method: 'POST', body: input })
}

export function updateSprintTask(api: ApiRequest, id: number, input: SprintTaskInput): Promise<void> {
  return api(`/api/sprint-tasks/${id}`, { method: 'PUT', body: input })
}

export function deleteSprintTask(api: ApiRequest, id: number): Promise<void> {
  return api(`/api/sprint-tasks/${id}`, { method: 'DELETE' })
}

export function carryOver(api: ApiRequest, orgUnitId: number, week: string): Promise<{ count: number }> {
  return api(`/api/org-units/${orgUnitId}/sprint/carry-over`, { method: 'POST', query: { week } })
}

export function pullRecurring(api: ApiRequest, orgUnitId: number, week: string): Promise<{ count: number }> {
  return api(`/api/org-units/${orgUnitId}/sprint/pull-recurring`, { method: 'POST', query: { week } })
}

export function createRecurringTask(api: ApiRequest, orgUnitId: number, input: RecurringTaskInput): Promise<{ id: number }> {
  return api(`/api/org-units/${orgUnitId}/recurring-tasks`, { method: 'POST', body: input })
}

export function updateRecurringTask(api: ApiRequest, id: number, input: RecurringTaskInput): Promise<void> {
  return api(`/api/recurring-tasks/${id}`, { method: 'PUT', body: input })
}

/** Full PUT body of a task with a new progress (quick progress buttons). */
export function withProgress(task: SprintTask, progressPct: number): SprintTaskInput {
  return {
    title: task.title,
    priority: task.priority,
    tag: task.tag,
    metricId: task.metric?.id ?? null,
    progressPct: Math.min(100, Math.max(0, Math.round(progressPct))),
    resultNote: task.resultNote,
    link: task.link,
    nextStep: task.nextStep,
  }
}
