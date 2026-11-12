import type { RnpRow } from '../types'

/** Tags under a metric name (source, direction, corrections, plan formula), as i18n keys. */
export function rowTagKeys(row: RnpRow): string[] {
  const result: string[] = []
  if (row.isFormula) result.push('rnp.tags.formula')
  else if (row.isRollup) result.push('rnp.tags.rollup')
  else if (row.isManual) result.push('rnp.tags.manual')
  else result.push(`rnp.tags.${row.sourceType}`)
  if (row.direction === 'lower_better') result.push('rnp.tags.lowerBetter')
  if (row.factOrigin === 'manual' && !row.isManual) result.push('rnp.tags.correctedManually')
  if (row.planFormula) result.push('rnp.tags.planFormula')
  return result
}
