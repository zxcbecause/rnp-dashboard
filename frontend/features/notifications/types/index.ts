export type AppNotificationType
  = | 'call_exclusions_pending'
    | 'call_exclusion_decided'
    | 'metric_red'
    | 'run_rate_behind'
    | 'facts_missing'
    | 'actions_missed'
    | 'errors_new'
    | 'data_stale'

/** GET /api/notifications item: computed from the current state, texts are built here. */
export interface AppNotification {
  type: AppNotificationType
  /** null for data_stale (not about a unit) */
  orgUnitId: number | null
  orgUnitName: string | null
  /** ISO 8601: when the item appeared (the oldest pending request, the decision, the end of the week, ...) */
  at: string
  /** pending requests; metrics / actions / errors concerned */
  count: number | null
  /** call_exclusion_decided: approved | rejected; data_stale: stale | failing */
  status: string | null
  callStartedAt: string | null
  decidedBy: string | null
  /** metric alerts: names of the first metrics */
  metrics?: string[]
  /** Y-m-d start of the week segment (metric_red, facts_missing) */
  week?: string | null
  /** data_stale: platform_api | bitrix */
  source?: string | null
  /** metric alerts: ids of `metrics`, same order */
  metricIds?: number[]
}
