export interface LatestRequestScope {
  /** Starts a request; the returned check is true only while no newer request has begun. */
  begin: () => () => boolean
}

/**
 * Race guard for loaders that can overlap (filters, pagination, week navigation).
 * Create one scope per loader at module level and skip stale results:
 *
 *   const isLatest = scope.begin()
 *   const data = await fetch()
 *   if (!isLatest()) return   // no store write, no toast, no loading=false
 */
export function createLatestRequestScope(): LatestRequestScope {
  let generation = 0

  return {
    begin() {
      const current = ++generation
      return () => current === generation
    },
  }
}
