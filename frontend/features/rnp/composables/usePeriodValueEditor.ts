import { ref } from 'vue'
import { useToast } from 'primevue/usetoast'
import { useApi } from '~/composables/useApi'
import { useAsyncAction } from '~/composables/useAsyncAction'
import { ApiError } from '~/types/api-errors'
import { fromInputValue } from '~/utils/metricFormat'
import type { MetricUnit } from '~/utils/metricFormat'
import { saveFact, savePlan } from '../api/rnp'
import type { PeriodType } from '../types'

export interface EditTarget {
  metricId: number
  unit: MetricUnit
  kind: 'fact' | 'plan'
  periodType: PeriodType
  periodStart: string
}

/**
 * Saves one fact or plan cell. Single flight: a second Enter while saving is dropped.
 * Backend validation messages (unit typing) are shown inline next to the input.
 */
export function usePeriodValueEditor(onSaved: () => Promise<void> | void) {
  const toast = useToast()
  const error = ref<string | null>(null)

  const { pending, run } = useAsyncAction(
    async (target: EditTarget, input: string) => {
      error.value = null
      const body = { periodType: target.periodType, periodStart: target.periodStart, value: fromInputValue(target.unit, input) }
      const api = useApi()
      await (target.kind === 'fact' ? saveFact(api, target.metricId, body) : savePlan(api, target.metricId, body))
      await onSaved()
      return true
    },
    {
      rethrow: false,
      onError: (e) => {
        if (e instanceof ApiError && (e.statusCode === 422 || e.statusCode === 403)) {
          error.value = e.message
          return
        }
        toast.add({ severity: 'error', summary: e instanceof Error ? e.message : String(e), life: 5000 })
      },
    },
  )

  return { pending, error, save: run }
}
