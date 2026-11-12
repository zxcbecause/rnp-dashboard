import { defineStore } from 'pinia'
import { useApi } from '~/composables/useApi'
import { createLatestRequestScope } from '~/composables/useLatestRequest'
import { NotFoundError } from '~/types/api-errors'
import { fetchRnpWeek } from '../api/rnp'
import type { RnpWeek } from '../types'

const loadScope = createLatestRequestScope()

export const useRnpStore = defineStore('rnp', {
  state: () => ({
    week: null as RnpWeek | null,
    loading: false,
    /** "not_found" for a unit outside the user's branch, otherwise an error message */
    error: null as string | null,
  }),

  actions: {
    async load(orgUnitId: number, week?: string | null): Promise<void> {
      const isLatest = loadScope.begin()
      this.loading = true
      this.error = null

      try {
        const data = await fetchRnpWeek(useApi(), orgUnitId, week)
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
  },
})
