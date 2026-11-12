import type { ApiRequest } from '~/composables/useApi'
import type { AiWeeklyReview, AnomalyExplanation, AnomalyKind, AssistantReply } from '../types'

export function fetchWeeklyReview(api: ApiRequest, orgUnitId: number, week: string): Promise<AiWeeklyReview> {
  return api<AiWeeklyReview>(`/api/org-units/${orgUnitId}/ai/weekly-review`, { query: { week } })
}

/** Asks the model for a fresh review of the week (replaces the stored one; takes 10–60 s). */
export function generateWeeklyReview(api: ApiRequest, orgUnitId: number, week: string): Promise<AiWeeklyReview> {
  return api<AiWeeklyReview>(`/api/org-units/${orgUnitId}/ai/weekly-review`, { method: 'POST', query: { week } })
}

/** Why the metric is red in the week of `date` / its month forecast is behind (reused while its figures stay the same). */
export function explainAnomaly(api: ApiRequest, metricId: number, kind: AnomalyKind, date: string): Promise<AnomalyExplanation> {
  return api<AnomalyExplanation>(`/api/metrics/${metricId}/ai/anomaly`, { method: 'POST', body: { kind, date } })
}

export function fetchAiStatus(api: ApiRequest): Promise<{ available: boolean }> {
  return api<{ available: boolean }>('/api/ai/status')
}

/** One question to the assistant with the visible conversation (text only); may take up to a minute. */
export function askAssistant(api: ApiRequest, messages: { role: 'user' | 'assistant', content: string }[], page: string): Promise<AssistantReply> {
  return api<AssistantReply>('/api/ai/assistant', { method: 'POST', body: { messages, page } })
}
