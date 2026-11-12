import { describe, expect, it } from 'vitest'
import type { RnpRow } from '~/features/rnp/types'
import { elapsedDays, lastFinishedWeekDate, pickKpis, summarize, trendOf } from '../overview'

function row(id: number, color: 'green' | 'red' | 'neutral' | null, hidden = false, weekly = true): RnpRow {
  const cell = { plan: '10', fact: '8', completionPct: '80', color }
  return {
    metricId: id, code: `m${id}`, name: `M${id}`, unit: 'count', direction: 'higher_better', timeAgg: 'sum',
    granularity: weekly ? 'week' : 'month', sourceType: 'manual', isManual: true, isFormula: false, isRollup: false,
    factOrigin: null, canEnterFact: false, canSetPlan: false,
    week: weekly ? cell : null, month: cell, runRate: null, runRateCompletionPct: null, runRateColor: null, hidden,
  }
}

describe('pickKpis', () => {
  it('takes the first visible metrics in the personal order', () => {
    const rows = [row(1, 'green'), row(2, 'red', true), row(3, null), row(4, 'green'), row(5, 'red'), row(6, 'green')]
    expect(pickKpis(rows).map(r => r.metricId)).toEqual([1, 3, 4, 5])
  })
})

describe('trendOf', () => {
  it('reports the change and whether it is good for the metric', () => {
    expect(trendOf('110', '100', 'higher_better')).toEqual({ pct: 10, up: true, good: true })
    expect(trendOf('90', '100', 'higher_better')).toEqual({ pct: 10, up: false, good: false })
    expect(trendOf('90', '100', 'lower_better')).toEqual({ pct: 10, up: false, good: true })
  })

  it('compares sums per day when segments differ in length', () => {
    // 1 day with 10 before, 4 days with 30 now: 10/day → 7.5/day = −25 %.
    expect(trendOf('30', '10', 'higher_better', { current: 4, previous: 1 })).toEqual({ pct: 25, up: false, good: false })
  })

  it('has no trend without both facts or from zero', () => {
    expect(trendOf(null, '100', 'higher_better')).toBeNull()
    expect(trendOf('5', '0', 'higher_better')).toBeNull()
  })
})

describe('summarize', () => {
  it('counts statuses and the done share among planned metrics', () => {
    const summary = summarize([row(1, 'green'), row(2, 'green'), row(3, 'neutral'), row(4, 'red'), row(5, null, false, false)])
    expect(summary).toMatchObject({ total: 5, done: 2, risk: 1, missed: 1, none: 1 })
    expect(summary.donePct).toBe(50)
  })

  it('has no share without planned metrics', () => {
    expect(summarize([row(1, null)]).donePct).toBeNull()
  })
})

describe('elapsedDays', () => {
  it('counts a finished segment in full and a running one up to today', () => {
    expect(elapsedDays('2026-11-02', '2026-11-08', new Date(2026, 11, 20))).toBe(7)
    expect(elapsedDays('2026-11-02', '2026-11-08', new Date(2026, 10, 5))).toBe(4)
    expect(elapsedDays('2026-11-01', '2026-11-01', new Date(2026, 10, 5))).toBe(1)
  })
})

describe('lastFinishedWeekDate', () => {
  it('returns Sunday of the previous week on a Monday', () => {
    expect(lastFinishedWeekDate(new Date(2026, 10, 9))).toBe('2026-11-08')
  })

  it('returns the previous Sunday in the middle of the week', () => {
    expect(lastFinishedWeekDate(new Date(2026, 10, 12))).toBe('2026-11-08')
  })

  it('treats the week cut by the 1st of the month as its own segment', () => {
    // 01.12.2026 is a Tuesday: the running segment starts on the 1st, the finished one ends on 30.11.
    expect(lastFinishedWeekDate(new Date(2026, 11, 3))).toBe('2026-11-30')
    // 01.11.2026 is a Sunday (a one-day segment); on 02.11 the finished segment is that Sunday.
    expect(lastFinishedWeekDate(new Date(2026, 10, 2))).toBe('2026-11-01')
  })

  it('returns the previous day on the 1st of the month', () => {
    expect(lastFinishedWeekDate(new Date(2026, 9, 1))).toBe('2026-09-30')
  })
})
