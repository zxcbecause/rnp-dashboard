import type { UserRef } from '~/features/org/types'

export type ImpactType = 'money' | 'blocked' | 'client' | 'other'
export type ErrorStatus = 'open' | 'in_progress' | 'resolved'

export interface ErrorEntry {
  id: number
  occurredOn: string
  reporter: UserRef | null
  description: string
  impactType: ImpactType
  impactAmount: string | null
  cause: string | null
  prevention: string | null
  owner: UserRef | null
  status: ErrorStatus
  createdAt: string
  canEdit: boolean
  /** Department of the entry: a unit's log includes its visible descendants */
  orgUnitId: number
  orgUnitName: string
}

/** GET /api/org-units/{id}/errors */
export interface ErrorLog {
  orgUnitId: number
  from: string
  to: string
  canReport: boolean
  total: number
  open: number
  byImpact: Record<ImpactType, number>
  moneyTotal: string
  entries: ErrorEntry[]
  people: UserRef[]
}

export interface ErrorEntryInput {
  occurredOn: string
  description: string
  impactType: ImpactType | ''
  impactAmount: string | null
  cause: string | null
  prevention: string | null
  ownerUserId: number | null
  status: ErrorStatus
}
