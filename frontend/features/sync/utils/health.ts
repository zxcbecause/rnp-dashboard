import type { SourceHealth, SourceStatus } from '../types'

/**
 * One state per source for the status bar. Stale data matters more than a failed last run:
 * a failed run with fresh data is a warning, data older than the threshold is the problem itself.
 */
export function sourceHealth(source: SourceStatus): SourceHealth {
  if (!source.configured) return 'off'
  if (source.stale) return 'stale'
  if (source.failing) return 'failing'
  return 'ok'
}

/** Resources whose last run failed, for the warning text. */
export function failedResources(source: SourceStatus): string[] {
  return source.resources.filter(r => r.lastStatus === 'failed').map(r => r.resource)
}

/** Worst state across sources: drives the color of the whole bar. */
export function overallHealth(sources: SourceStatus[]): SourceHealth {
  const order: SourceHealth[] = ['stale', 'failing', 'ok', 'off']
  const states = sources.map(sourceHealth)
  return order.find(state => states.includes(state)) ?? 'off'
}
