import { defineStore } from 'pinia'
import { useApi } from '~/composables/useApi'
import { createLatestRequestScope } from '~/composables/useLatestRequest'
import { NotFoundError } from '~/types/api-errors'
import { fetchActionsWeek } from '../api/actions'
import type { ActionsWeek } from '../types'

const loadScope = createLatestRequestScope()

export const useActionsStore = defineStore('actions', {
  state: () => ({
    week: null as ActionsWeek | null,
    unitId: null as number | null,
    loading: false,
    error: null as string | null,
  }),

  actions: {
    async load(orgUnitId: number, week?: string | null): Promise<void> {
      const isLatest = loadScope.begin()
      this.loading = true
      this.error = null
      this.unitId = orgUnitId

      try {
        const data = await fetchActionsWeek(useApi(), orgUnitId, week)
        if (!isLatest()) return
        this.week = data
      }
      catch (error) {
        if (!isLatest()) return
        this.week = null
        this.error = error instanceof NotFoundError ? 'not_found' : (error instanceof Error ? error.message : String(error))
      }
      finally {
        if (isLatest()) this.loading = false
      }
    },

    /** Re-reads the shown week after a change. */
    async reload(): Promise<void> {
      if (this.unitId) await this.load(this.unitId, this.week?.weekStart)
    },
  },
})
