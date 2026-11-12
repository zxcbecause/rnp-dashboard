import type { MetricUnit } from '~/utils/metricFormat'
import type { OrgUnit, UserRef } from '~/features/org/types'

export type RnpColor = 'green' | 'red' | 'neutral'
export type PeriodType = 'week' | 'month'

/** Decimal strings; percent metrics are fractions ("0.45"), completionPct is in percent points. */
export interface RnpCell {
  plan: string | null
  fact: string | null
  completionPct: string | null
  color: RnpColor | null
}

export interface RnpRow {
  metricId: number
  code: string
  name: string
  unit: MetricUnit
  direction: 'higher_better' | 'lower_better'
  timeAgg: string
  granularity: PeriodType
  sourceType: 'manual' | 'platform_api' | 'bitrix' | 'push_api'
  isManual: boolean
  isFormula: boolean
  isRollup: boolean
  factOrigin: 'manual' | 'auto' | 'import' | null
  canEnterFact: boolean
  canSetPlan: boolean
  /** null for monthly metrics */
  week: RnpCell | null
  /** fact = month to date */
  month: RnpCell
  runRate: string | null
  runRateCompletionPct: string | null
  runRateColor: RnpColor | null
  /** hidden in the user's personal view (present only with showHidden) */
  hidden: boolean
  /** why the shown period deviated and what is done (week segment; month for monthly metrics) */
  note?: DeviationNote | null
  /** whether the user may write that explanation */
  canExplain?: boolean
  /** daily pace to the month plan (additive weekly metrics with a month plan, while the month runs) */
  pace?: Pace | null
  /** plan computed from other metrics' facts (e.g. 30 % of incoming bookings): not editable, no run rate */
  planFormula?: string | null
  /** average per day of the week and of the month to date, for metrics that ask for it (calls) */
  dailyAverage?: DailyAverage | null
}

export interface DailyAverage {
  week: string | null
  month: string | null
}

export interface Pace {
  /** month fact to date / elapsed days */
  currentPerDay: string
  /** (month plan − fact) / remaining days, never below 0 */
  requiredPerDay: string
  remainingDays: number
  /** plan met (higher is better) or budget already exceeded (lower is better) */
  planReached: boolean
}

export interface DeviationNote {
  periodType: PeriodType
  periodStart: string
  reason: string
  action: string | null
  author: UserRef | null
  updatedAt: string
}

/** POST /api/metrics/{id}/ai/deviation-draft — a proposal, not saved */
export interface DeviationDraft {
  reason: string
  action: string
}

/** GET /api/org-units/{id}/rnp */
export interface RnpWeek {
  orgUnit: OrgUnit
  weekIndex: number
  weekStart: string
  weekEnd: string
  monthStart: string
  prevWeekStart: string | null
  nextWeekStart: string
  isCurrentWeek: boolean
  rows: RnpRow[]
  /** metrics of the unit hidden in the user's view */
  hiddenCount: number
}

export interface MetricViewItem {
  metricId: number
  visible: boolean
}

export interface PeriodValueInput {
  periodType: PeriodType
  periodStart: string
  value: string | null
}
