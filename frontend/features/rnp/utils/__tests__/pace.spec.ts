import { describe, expect, it } from 'vitest'
import type { RnpRow } from '../../types'
import { paceView } from '../pace'

function row(direction: RnpRow['direction'], pace: RnpRow['pace'], unit: RnpRow['unit'] = 'count'): RnpRow {
  const cell = { plan: '310', fact: '73', completionPct: '23.5', color: 'red' as const }
  return {
    metricId: 1, code: 'm', name: 'M', unit, direction, timeAgg: 'sum', granularity: 'week', sourceType: 'manual',
    isManual: true, isFormula: false, isRollup: false, factOrigin: null, canEnterFact: false, canSetPlan: false,
    week: cell, month: cell, runRate: '205', runRateCompletionPct: '66.4', runRateColor: 'red', hidden: false, pace,
  }
}

describe('paceView', () => {
  it('shows what is needed per day and whether the current pace is enough', () => {
    const view = paceView(row('higher_better', { currentPerDay: '6.6364', requiredPerDay: '11.85', remainingDays: 20, planReached: false }))

    expect(view).toEqual({ kind: 'need', required: '11,9', current: '6,6', tone: 'bad' })
  })

  it('reads a cost metric as an allowance', () => {
    const view = paceView(row('lower_better', { currentPerDay: '40', requiredPerDay: '55.5', remainingDays: 10, planReached: false }, 'eur'))

    expect(view?.kind).toBe('allowed')
    expect(view?.tone).toBe('good')
    expect(view?.required).toContain('56')
  })

  it('marks a met plan as reached and a spent budget as exceeded', () => {
    const met = { currentPerDay: '20', requiredPerDay: '0', remainingDays: 5, planReached: true }

    expect(paceView(row('higher_better', met))).toMatchObject({ kind: 'reached', tone: 'good' })
    expect(paceView(row('lower_better', met))).toMatchObject({ kind: 'exceeded', tone: 'bad' })
    expect(paceView(row('higher_better', null))).toBeNull()
  })
})
