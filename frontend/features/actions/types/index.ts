import type { UserRef } from '~/features/org/types'

export type Periodicity = 'daily' | 'weekly' | 'monthly' | 'quarterly'
export type SlotState = 'done' | 'not_done' | 'missed' | 'pending' | 'future'
export type RoadmapStatus = 'planned' | 'in_progress' | 'done' | 'cancelled'
export type RoadmapEffect = 'affected' | 'not_affected'

export interface MetricRef {
  id: number
  name: string
}

export interface ActionSlot {
  periodStart: string
  due: string
  state: SlotState
  done: boolean | null
  note: string | null
  checkedBy: UserRef | null
  checkedAt: string | null
}

export interface BaseFunction {
  id: number
  title: string
  periodicity: Periodicity
  dueText: string | null
  metric: MetricRef | null
  owner: UserRef | null
  isActive: boolean
  canCheck: boolean
  slots: ActionSlot[]
}

export interface RoadmapEvent {
  id: number
  type: 'created' | 'status_changed' | 'field_changed' | 'effect_assessed'
  field: string | null
  oldValue: string | null
  newValue: string | null
  effect: RoadmapEffect | null
  note: string | null
  actor: UserRef | null
  createdAt: string
}

export interface RoadmapItem {
  id: number
  metric: MetricRef
  title: string
  owner: UserRef | null
  dueDate: string | null
  status: RoadmapStatus
  expectedEffect: string | null
  effect: RoadmapEffect | null
  isOverdue: boolean
  canEdit: boolean
  canChangeStatus: boolean
  events: RoadmapEvent[]
}

export interface ExecutionRate {
  planned: number
  done: number
  pct: string | null
  baseFunctionsPlanned: number
  baseFunctionsDone: number
  roadmapPlanned: number
  roadmapDone: number
  sprintPlanned: number
  sprintDone: number
}

export interface MissedItem {
  kind: 'base_function' | 'roadmap'
  id: number
  title: string
  periodStart: string
  due: string
  owner: UserRef | null
}

/** GET /api/org-units/{id}/actions */
export interface ActionsWeek {
  orgUnitId: number
  weekStart: string
  weekEnd: string
  monthStart: string
  prevWeekStart: string | null
  nextWeekStart: string
  canManage: boolean
  weekExecution: ExecutionRate
  monthExecution: ExecutionRate
  baseFunctions: BaseFunction[]
  roadmap: RoadmapItem[]
  missed: MissedItem[]
  metrics: MetricRef[]
  people: UserRef[]
}

export interface BaseFunctionInput {
  title: string
  periodicity: Periodicity
  metricId: number | null
  ownerUserId: number | null
  dueText: string | null
  isActive: boolean
}

export interface RoadmapItemInput {
  title: string
  ownerUserId: number | null
  dueDate: string | null
  status: RoadmapStatus
  expectedEffect: string | null
}
