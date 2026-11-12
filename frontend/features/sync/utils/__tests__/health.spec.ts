import { describe, expect, it } from 'vitest'
import type { SourceStatus } from '../../types'
import { failedResources, overallHealth, sourceHealth } from '../health'

function source(patch: Partial<SourceStatus> = {}): SourceStatus {
  return {
    source: 'platform_api',
    configured: true,
    dataAsOf: '2026-11-05T11:45:00+01:00',
    stale: false,
    failing: false,
    resources: [],
    ...patch,
  }
}

describe('sourceHealth', () => {
  it('is off without credentials, whatever the runs say', () => {
    expect(sourceHealth(source({ configured: false, stale: true, failing: true }))).toBe('off')
  })

  it('puts stale data above a failed last run', () => {
    expect(sourceHealth(source({ stale: true, failing: true }))).toBe('stale')
    expect(sourceHealth(source({ failing: true }))).toBe('failing')
    expect(sourceHealth(source())).toBe('ok')
  })
})

describe('failedResources', () => {
  it('lists resources whose last run failed', () => {
    const s = source({
      resources: [
        { resource: 'bookings', lastRunAt: null, lastStatus: 'success', lastSuccessAt: null, error: null },
        { resource: 'tickets', lastRunAt: null, lastStatus: 'failed', lastSuccessAt: null, error: null },
      ],
    })
    expect(failedResources(s)).toEqual(['tickets'])
  })
})

describe('overallHealth', () => {
  it('takes the worst configured state', () => {
    expect(overallHealth([source(), source({ source: 'bitrix', failing: true })])).toBe('failing')
    expect(overallHealth([source({ failing: true }), source({ source: 'bitrix', stale: true })])).toBe('stale')
    expect(overallHealth([source(), source({ source: 'bitrix', configured: false })])).toBe('ok')
    expect(overallHealth([])).toBe('off')
  })
})
