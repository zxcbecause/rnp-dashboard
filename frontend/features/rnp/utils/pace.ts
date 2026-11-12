import { formatMetricValue } from '~/utils/metricFormat'
import type { MetricUnit } from '~/utils/metricFormat'
import type { RnpRow } from '../types'

export interface PaceView {
  /**
   * need — still to bring per day (higher is better); allowed — may still spend per day (lower is better);
   * reached — month plan already met; exceeded — the month budget is already used up.
   */
  kind: 'need' | 'allowed' | 'reached' | 'exceeded'
  /** per day, formatted with the metric unit */
  required: string
  current: string
  /** good: the current pace is enough; bad: it is not */
  tone: 'good' | 'bad'
}

/** Per-day values: counts keep one decimal ("11,9 в день"), the rest is formatted as the metric. */
function perDay(unit: MetricUnit, value: string): string {
  if (unit === 'count') {
    return new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 1 }).format(Number(value))
  }
  return formatMetricValue(unit, value)
}

/** How the daily pace of a row reads: what is needed per day against the pace so far. */
export function paceView(row: RnpRow): PaceView | null {
  const pace = row.pace
  if (!pace) return null

  const lower = row.direction === 'lower_better'
  const required = Number(pace.requiredPerDay)
  const current = Number(pace.currentPerDay)
  const view = { required: perDay(row.unit, pace.requiredPerDay), current: perDay(row.unit, pace.currentPerDay) }

  if (pace.planReached) {
    return { ...view, kind: lower ? 'exceeded' : 'reached', tone: lower ? 'bad' : 'good' }
  }
  return lower
    ? { ...view, kind: 'allowed', tone: current <= required ? 'good' : 'bad' }
    : { ...view, kind: 'need', tone: current >= required ? 'good' : 'bad' }
}
