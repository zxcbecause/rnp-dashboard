import { defineStore } from 'pinia'
import { useApi } from '~/composables/useApi'
import { createLatestRequestScope } from '~/composables/useLatestRequest'
import { buildTree, fetchOrgUnits } from '../api/org'
import type { OrgUnit, OrgUnitNode } from '../types'

const loadScope = createLatestRequestScope()

export const useOrgStore = defineStore('org', {
  state: () => ({
    units: [] as OrgUnit[],
    loading: false,
    error: null as string | null,
  }),

  getters: {
    tree: (state): OrgUnitNode[] => buildTree(state.units),
  },

  actions: {
    async load(): Promise<void> {
      const isLatest = loadScope.begin()
      this.loading = true
      this.error = null

      try {
        const units = await fetchOrgUnits(useApi())
        if (!isLatest()) return
        this.units = units
      }
      catch (error) {
        if (!isLatest()) return
        this.error = error instanceof Error ? error.message : String(error)
      }
      finally {
        if (isLatest()) this.loading = false
      }
    },
  },
})
