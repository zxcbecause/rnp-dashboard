import type { RouteLocationRaw } from 'vue-router'
import type { AnomalyTarget } from '~/features/ai/types'
import type { AppNotification } from '../types'

/**
 * Badge number: items that wait for the user's action always count (pending call exclusions by their number,
 * missing facts once per unit); everything else counts until the bell is opened after it appeared.
 */
export function unreadCount(items: AppNotification[], lastSeen: string | null): number {
  const seen = lastSeen ? Date.parse(lastSeen) : Number.NaN
  return items.reduce((total, item) => {
    if (item.type === 'call_exclusions_pending') return total + (item.count ?? 0)
    if (item.type === 'facts_missing') return total + 1
    return total + (Number.isNaN(seen) || Date.parse(item.at) > seen ? 1 : 0)
  }, 0)
}

/** Screen that resolves the item. */
export function notificationLink(item: AppNotification): RouteLocationRaw {
  const unit = item.orgUnitId ? { unit: String(item.orgUnitId) } : {}
  switch (item.type) {
    case 'metric_red':
    case 'facts_missing':
      return { path: '/rnp-week', query: { ...unit, ...(item.week ? { week: item.week } : {}) } }
    case 'run_rate_behind':
      return { path: '/rnp-week', query: unit }
    case 'actions_missed':
      return { path: '/actions', query: unit }
    case 'errors_new':
      return { path: '/errors', query: unit }
    case 'data_stale':
      return { path: '/admin/sync' }
    default:
      return { path: '/calls', query: unit }
  }
}

/** "2026-11-02" → "02.11" */
export function shortDate(ymd: string | null | undefined): string {
  const match = /^\d{4}-(\d{2})-(\d{2})$/.exec(ymd ?? '')
  return match ? `${match[2]}.${match[1]}` : ''
}

/** «Why?» buttons of a metric alert: the red week of `item.week`, or this month's forecast as of `today` (Y-m-d). */
export function anomalyTargets(item: AppNotification, today: string): AnomalyTarget[] {
  if (item.type !== 'metric_red' && item.type !== 'run_rate_behind') return []
  if (item.type === 'metric_red' && !item.week) return []
  const names = item.metrics ?? []
  return (item.metricIds ?? []).slice(0, names.length).map((metricId, index) => ({
    metricId,
    metricName: names[index] ?? '',
    kind: item.type === 'metric_red' ? 'week' : 'forecast',
    date: item.type === 'metric_red' ? (item.week as string) : today,
  }))
}
