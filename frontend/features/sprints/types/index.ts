import type { MetricRef, Periodicity } from '~/features/actions/types'

export type SprintPriority = 'P1' | 'P2' | 'P3' | 'P4' | 'P5'

export interface SprintTask {
  id: number
  weekStart: string
  tag: string | null
  priority: SprintPriority
  title: string
  metric: MetricRef | null
  progressPct: number
  resultNote: string | null
  link: string | null
  nextStep: string | null
  carriedFromWeek: string | null
}

export interface RecurringTask {
  id: number
  title: string
  periodicity: Periodicity
  isActive: boolean
}

/** GET /api/org-units/{id}/sprint */
export interface SprintWeek {
  orgUnitId: number
  weekStart: string
  weekEnd: string
  prevWeekStart: string
  nextWeekStart: string
  canEdit: boolean
  canManageRecurring: boolean
  doneCount: number
  avgProgressPct: number | null
  carryableCount: number
  tasks: SprintTask[]
  recurring: RecurringTask[]
  metrics: MetricRef[]
}

export interface SprintTaskInput {
  title: string
  priority: SprintPriority
  tag: string | null
  metricId: number | null
  progressPct: number
  resultNote: string | null
  link: string | null
  nextStep: string | null
  weekStart?: string
}

export interface RecurringTaskInput {
  title: string
  periodicity: Periodicity
  isActive: boolean
}
