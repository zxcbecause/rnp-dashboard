import { ref, watch } from 'vue'
import type { Ref } from 'vue'
import { useApi } from '~/composables/useApi'
import { createLatestRequestScope } from '~/composables/useLatestRequest'
import { fetchActionsWeek } from '~/features/actions/api/actions'
import type { ActionsWeek } from '~/features/actions/types'
import { fetchMetric } from '~/features/metrics/api/metrics'
import type { UserRef } from '~/features/org/types'
import { fetchRnpWeek } from '~/features/rnp/api/rnp'
import type { RnpWeek } from '~/features/rnp/types'
import { NotFoundError } from '~/types/api-errors'

/** Metric owners survive reloads of the overview: they rarely change. */
const ownerCache = new Map<number, UserRef | null>()

/**
 * Data of "Мой кабинет", composed from existing endpoints only: the RNP week of the unit, the previous
 * week (for trends), the actions week (execution rate, missed items) and, lazily, the metric cards for
 * owners (the RNP rows do not carry them).
 */
export function useCabinetOverview(unitId: Ref<number | null>, week: Ref<string | null>) {
  const current = ref<RnpWeek | null>(null)
  const previous = ref<RnpWeek | null>(null)
  const actions = ref<ActionsWeek | null>(null)
  const owners = ref<Record<number, UserRef | null>>({})
  const loading = ref(false)
  const error = ref<string | null>(null)
  const scope = createLatestRequestScope()

  async function loadOwners(rnp: RnpWeek, isLatest: () => boolean) {
    const api = useApi()
    owners.value = Object.fromEntries(rnp.rows.map(row => [row.metricId, ownerCache.get(row.metricId) ?? null]))
    const missing = rnp.rows.filter(row => !ownerCache.has(row.metricId))
    // Each owner shows up as soon as its card arrives (the dev backend answers slowly).
    await Promise.allSettled(missing.map(async (row) => {
      const metric = await fetchMetric(api, row.metricId)
      ownerCache.set(row.metricId, metric.owner)
      if (isLatest()) owners.value = { ...owners.value, [row.metricId]: metric.owner }
    }))
  }

  async function load() {
    const unit = unitId.value
    if (!unit) return
    const isLatest = scope.begin()
    loading.value = true
    error.value = null

    try {
      const api = useApi()
      const [rnp, acts] = await Promise.all([
        fetchRnpWeek(api, unit, week.value),
        fetchActionsWeek(api, unit, week.value).catch(() => null),
      ])
      const prev = rnp.prevWeekStart ? await fetchRnpWeek(api, unit, rnp.prevWeekStart).catch(() => null) : null
      if (!isLatest()) return

      current.value = rnp
      previous.value = prev
      actions.value = acts
      void loadOwners(rnp, isLatest)
    }
    catch (e) {
      if (!isLatest()) return
      current.value = null
      error.value = e instanceof NotFoundError ? 'not_found' : (e instanceof Error ? e.message : String(e))
    }
    finally {
      if (isLatest()) loading.value = false
    }
  }

  watch([unitId, week], load, { immediate: true })

  return { current, previous, actions, owners, loading, error, reload: load }
}
