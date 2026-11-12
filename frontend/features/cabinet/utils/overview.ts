import type { RnpCell, RnpRow } from '~/features/rnp/types'
import { statusOf } from '~/features/ui/status'
import type { UiStatus } from '~/features/ui/status'

export const KPI_COUNT = 4

/** The cell a metric is judged by on the overview: the week, or the month for monthly metrics. */
export function mainCell(row: RnpRow): RnpCell {
  return row.week ?? row.month
}

/**
 * KPI cards = the head's main metrics: the first ones in the user's personal order
 * ("Настроить вид" on the RNP screen), so every head chooses their own.
 */
export function pickKpis(rows: RnpRow[]): RnpRow[] {
  return rows.filter(row => !row.hidden).slice(0, KPI_COUNT)
}

export interface Trend {
  /** Percent change, rounded, without sign */
  pct: number
  up: boolean
  /** Good for the metric: up for higher-better, down for lower-better */
  good: boolean
}

/**
 * Change of the fact against the previous period. Display only (plain numbers are fine here);
 * none when either fact is missing or the previous one is zero. With day counts the facts are compared
 * per day: week segments differ in length (a month cut "Неделя 1: 01.11" has one day) and the current
 * week is counted up to today.
 */
export function trendOf(
  current: string | null,
  previous: string | null,
  direction: RnpRow['direction'],
  days?: { current: number, previous: number },
): Trend | null {
  if (current === null || previous === null) return null
  const now = Number(current) / (days ? Math.max(1, days.current) : 1)
  const before = Number(previous) / (days ? Math.max(1, days.previous) : 1)
  if (!Number.isFinite(now) || !Number.isFinite(before) || before === 0) return null

  const change = ((now - before) / Math.abs(before)) * 100
  const up = change >= 0
  return { pct: Math.round(Math.abs(change)), up, good: direction === 'lower_better' ? !up : up }
}

export interface StatusSummary {
  total: number
  done: number
  risk: number
  missed: number
  none: number
  /** Share of metrics with a plan that are done, in percent points; null without any plan */
  donePct: number | null
}

export function summarize(rows: RnpRow[]): StatusSummary {
  const summary: StatusSummary = { total: rows.length, done: 0, risk: 0, missed: 0, none: 0, donePct: null }
  for (const row of rows) {
    summary[statusOf(mainCell(row).color)]++
  }
  const planned = summary.done + summary.risk + summary.missed
  summary.donePct = planned > 0 ? (summary.done / planned) * 100 : null
  return summary
}

export function rowStatus(row: RnpRow): UiStatus {
  return statusOf(mainCell(row).color)
}

/** Days of a segment [start, end], or up to today when the segment is still running. */
/**
 * A date inside the last finished RNP week segment before `today`: segments run Mon–Sun and are cut by the
 * month boundary, so the running one starts at max(Monday, 1st of the month) and the day before it closes
 * the previous segment.
 */
export function lastFinishedWeekDate(today: Date = new Date()): string {
  const monday = new Date(today.getFullYear(), today.getMonth(), today.getDate() - ((today.getDay() + 6) % 7))
  const firstOfMonth = new Date(today.getFullYear(), today.getMonth(), 1)
  const start = monday > firstOfMonth ? monday : firstOfMonth
  const last = new Date(start.getFullYear(), start.getMonth(), start.getDate() - 1)
  return `${last.getFullYear()}-${String(last.getMonth() + 1).padStart(2, '0')}-${String(last.getDate()).padStart(2, '0')}`
}

/** Today as Y-m-d in the browser's calendar. */
export function isoDate(date: Date = new Date()): string {
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`
}

export function elapsedDays(start: string, end: string, today: Date = new Date()): number {
  const day = (iso: string) => Date.UTC(Number(iso.slice(0, 4)), Number(iso.slice(5, 7)) - 1, Number(iso.slice(8, 10)))
  const todayUtc = Date.UTC(today.getFullYear(), today.getMonth(), today.getDate())
  const last = Math.min(day(end), todayUtc)
  return Math.max(1, Math.round((last - day(start)) / 86_400_000) + 1)
}

/** Sums are compared per day; averages, states and ratios as they are. */
export function comparesPerDay(row: RnpRow): boolean {
  return row.timeAgg === 'sum' && !row.isFormula
}
