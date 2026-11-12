/**
 * "2026-11-09T09:41:33" (Madrid local time from the API) → "09.11.2026 09:41". Parsed as text, never through
 * Date, so the viewer's time zone cannot shift it.
 */
export function formatCallTime(value: string): string {
  const match = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})/.exec(value)
  if (!match) return value
  const [, y, m, d, hh, mm] = match
  return `${d}.${m}.${y} ${hh}:${mm}`
}

/** "2026-11-09" → "09.11.2026" */
export function formatDay(value: string): string {
  const [y, m, d] = value.split('-')
  return y && m && d ? `${d}.${m}.${y}` : value
}
