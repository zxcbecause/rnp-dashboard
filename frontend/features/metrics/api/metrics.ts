import type { ApiRequest } from '~/composables/useApi'
import type { Metric, SeriesPoint } from '../types'

export function fetchMetric(api: ApiRequest, id: number): Promise<Metric> {
  return api<Metric>(`/api/metrics/${id}`)
}

export function fetchSeries(api: ApiRequest, id: number, period: 'week' | 'month', from: string, to: string, compareYear = false): Promise<SeriesPoint[]> {
  return api<SeriesPoint[]>(`/api/metrics/${id}/series`, {
    query: { period, from, to, ...(compareYear ? { compareYear: 'true' } : {}) },
  })
}

/** [from, to] covering the last `months` calendar months including the current one. */
export function lastMonthsRange(months: number, today: Date = new Date()): { from: string, to: string } {
  const pad = (n: number) => String(n).padStart(2, '0')
  const end = new Date(today.getFullYear(), today.getMonth() + 1, 0)
  const start = new Date(today.getFullYear(), today.getMonth() - (months - 1), 1)
  const fmt = (d: Date) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`
  return { from: fmt(start), to: fmt(end) }
}
