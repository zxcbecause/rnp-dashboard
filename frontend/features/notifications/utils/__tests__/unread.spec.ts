import { describe, expect, it } from 'vitest'
import type { AppNotification } from '../../types'
import { anomalyTargets, notificationLink, shortDate, unreadCount } from '../unread'

const base = { orgUnitId: 1, orgUnitName: 'CS', count: null, status: null, callStartedAt: null, decidedBy: null }
const pending: AppNotification = { ...base, type: 'call_exclusions_pending', at: '2026-11-01T10:00:00+01:00', count: 3 }
const decided = (at: string): AppNotification => ({ ...base, type: 'call_exclusion_decided', at, status: 'approved' })

describe('notification badge', () => {
  it('always counts pending requests', () => {
    expect(unreadCount([pending], '2026-12-01T00:00:00+01:00')).toBe(3)
  })

  it('counts decisions until the bell is opened after them', () => {
    const items = [decided('2026-11-09T10:00:00+01:00'), decided('2026-11-10T10:00:00+01:00')]
    expect(unreadCount(items, null)).toBe(2)
    expect(unreadCount(items, '2026-11-09T12:00:00+01:00')).toBe(1)
    expect(unreadCount(items, '2026-11-10T12:00:00+01:00')).toBe(0)
  })
})

describe('alerts', () => {
  const alert = (type: AppNotification['type'], extra: Partial<AppNotification> = {}): AppNotification =>
    ({ ...base, type, at: '2026-11-09T00:00:00+01:00', count: 2, ...extra })

  it('counts missing facts until entered, other alerts until seen', () => {
    const items = [alert('facts_missing'), alert('metric_red'), alert('errors_new')]
    expect(unreadCount(items, null)).toBe(3)
    expect(unreadCount(items, '2026-11-10T08:00:00+01:00')).toBe(1)
  })

  it('links every alert to the screen that resolves it', () => {
    expect(notificationLink(alert('metric_red', { week: '2026-11-02' }))).toEqual({ path: '/rnp-week', query: { unit: '1', week: '2026-11-02' } })
    expect(notificationLink(alert('run_rate_behind'))).toEqual({ path: '/rnp-week', query: { unit: '1' } })
    expect(notificationLink(alert('actions_missed'))).toEqual({ path: '/actions', query: { unit: '1' } })
    expect(notificationLink(alert('errors_new'))).toEqual({ path: '/errors', query: { unit: '1' } })
    expect(notificationLink(alert('data_stale', { orgUnitId: null, orgUnitName: null }))).toEqual({ path: '/admin/sync' })
    expect(notificationLink(pending)).toEqual({ path: '/calls', query: { unit: '1' } })
  })

  it('formats the week start', () => {
    expect(shortDate('2026-11-02')).toBe('02.11')
    expect(shortDate(null)).toBe('')
  })
})

describe('why buttons', () => {
  const base2 = { ...base, at: '2026-11-09T00:00:00+01:00', count: 4, metrics: ['A', 'B'], metricIds: [7, 8] }

  it('explains a red week by its week and a forecast as of today', () => {
    expect(anomalyTargets({ ...base2, type: 'metric_red', week: '2026-11-02' }, '2026-11-10')).toEqual([
      { metricId: 7, metricName: 'A', kind: 'week', date: '2026-11-02' },
      { metricId: 8, metricName: 'B', kind: 'week', date: '2026-11-02' },
    ])
    expect(anomalyTargets({ ...base2, type: 'run_rate_behind' }, '2026-11-10').map(t => [t.kind, t.date])).toEqual([['forecast', '2026-11-10'], ['forecast', '2026-11-10']])
  })

  it('offers nothing for other alerts', () => {
    expect(anomalyTargets({ ...base2, type: 'facts_missing', week: '2026-11-02' }, '2026-11-10')).toEqual([])
  })
})
