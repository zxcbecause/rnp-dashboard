import type { UserRef } from '~/features/org/types'

export type CallExclusionStatus = 'pending' | 'approved' | 'rejected'

export type CallExclusionCategory = 'technical' | 'spam' | 'test' | 'own' | 'other'

export const CALL_EXCLUSION_CATEGORIES: CallExclusionCategory[] = ['technical', 'spam', 'test', 'own', 'other']

export interface CallExclusion {
  id: number
  callId: number
  /** Madrid local time, Y-m-dTH:i:s */
  callStartedAt: string
  responsibleId: number | null
  reason: string
  status: CallExclusionStatus
  requestedBy: UserRef | null
  requestedAt: string
  decidedBy: UserRef | null
  decidedAt: string | null
  decisionNote: string | null
  canWithdraw: boolean
  category: CallExclusionCategory
  /** a screenshot is attached (GET /api/call-exclusions/{id}/image) */
  hasImage: boolean
}

/** GET /api/call-exclusions/{id}/image */
export interface CallExclusionImageData {
  contentType: string
  /** base64 */
  data: string
}

/** POST /api/org-units/{id}/call-exclusions */
export interface CallExclusionRequestResult {
  id: number
  /** approved: the author may decide, the exclusion is applied at once */
  status: CallExclusionStatus
  /** null while pending; false: the metrics are updated by the next sync */
  recalculated: boolean | null
}

export interface MissedCall {
  callId: number
  /** Madrid local time, Y-m-dTH:i:s */
  startedAt: string
  /** Bitrix user the call was routed to */
  responsibleId: number | null
  /** Bitrix CRM card the call is attached to */
  crmUrl: string | null
  canRequest: boolean
  exclusion: CallExclusion | null
  /** false: the call was answered (listed only when every incoming call is asked for) */
  missed: boolean
}

/** GET /api/org-units/{id}/missed-calls */
export interface MissedCalls {
  orgUnit: { id: number, name: string }
  from: string
  to: string
  /** requests are accepted for calls from this day on */
  editableFrom: string
  canRequest: boolean
  canDecide: boolean
  /** newest first */
  calls: MissedCall[]
  /** every pending request of the unit, oldest first */
  pending: CallExclusion[]
}
