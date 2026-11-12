import type { MetricUnit } from '~/utils/metricFormat'
import type { RnpColor } from '~/features/rnp/types'
import type { UserRef } from '~/features/org/types'

export interface MetricCard {
  whatMeasured: string | null
  formulaText: string | null
  sourceText: string | null
  periodText: string | null
  usedIn: string | null
}

/** GET /api/metrics/{id} */
export interface Metric {
  id: number
  orgUnitId: number
  code: string
  name: string
  unit: MetricUnit
  direction: 'higher_better' | 'lower_better'
  timeAgg: string
  treeAgg: string
  formula: string | null
  sourceType: string
  isManual: boolean
  granularity: 'week' | 'month'
  owner: UserRef | null
  filler: UserRef | null
  sort: number
  card: MetricCard
  /** plan computed per period from other metrics: a bare code is a fact, "code.plan" its set plan */
  planFormula?: string | null
}

/** GET /api/metrics/{id}/series item */
export interface SeriesPoint {
  periodStart: string
  periodEnd: string
  plan: string | null
  fact: string | null
  completionPct: string | null
  color: RnpColor | null
  /** With compareYear: fact of the same month (or the week segment holding the same date) a year earlier */
  factPrevYear: string | null
}
