import type { UserRef } from '~/features/org/types'

/** GET|POST /api/org-units/{id}/ai/weekly-review */
export interface AiWeeklyReview {
  /** false: no API key on the server, AI features are off */
  available: boolean
  weekStart: string
  /** Markdown (bullets, bold); null until generated */
  text: string | null
  createdAt: string | null
  createdBy: UserRef | null
}

export interface AssistantMessage {
  role: 'user' | 'assistant'
  /** Markdown for the assistant */
  content: string
  /** what the assistant looked at ("РНП-неделя"), assistant messages only */
  used?: string[]
}

/** POST /api/metrics/{id}/ai/anomaly: why the week is red / the month forecast is behind */
export interface AnomalyExplanation {
  metricId: number
  metricName: string
  kind: AnomalyKind
  /** Y-m-d: week segment start or month start */
  periodStart: string
  /** Markdown bullet list */
  text: string
  createdAt: string
  createdBy: UserRef | null
  /** reused: the metric's figures have not changed since it was made */
  cached: boolean
}

export type AnomalyKind = 'week' | 'forecast'

/** What the «why?» dialog explains */
export interface AnomalyTarget {
  metricId: number
  metricName: string
  kind: AnomalyKind
  /** Y-m-d: the week (week) or a day of the month's current week (forecast) */
  date: string
}

/** POST /api/ai/assistant */
export interface AssistantReply {
  answer: string
  used: string[]
}
