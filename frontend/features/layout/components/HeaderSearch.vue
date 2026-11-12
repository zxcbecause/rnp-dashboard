<template>
  <div
    ref="root"
    class="relative w-full max-w-sm"
  >
    <label class="flex h-11 items-center gap-2.5 rounded-full bg-rnp-pill px-4 text-sm ring-1 ring-transparent transition focus-within:ring-rnp-accent">
      <i
        class="pi pi-search text-rnp-muted"
        aria-hidden="true"
      />
      <input
        v-model="query"
        type="search"
        class="w-full bg-transparent text-rnp-text outline-none placeholder:text-rnp-muted"
        :placeholder="t('layout.search')"
        :aria-label="t('layout.search')"
        @focus="open"
        @keydown.down.prevent="move(1)"
        @keydown.up.prevent="move(-1)"
        @keydown.enter.prevent="go(results[active])"
        @keydown.esc="close"
      >
      <i
        v-if="loading"
        class="pi pi-spin pi-spinner text-xs text-rnp-muted"
        aria-hidden="true"
      />
    </label>

    <Transition name="rnp-fade">
      <ul
        v-if="opened && query.trim().length > 0"
        class="absolute left-0 right-0 top-12 z-30 max-h-80 overflow-y-auto rounded-2xl bg-rnp-pill p-1.5 shadow-lg ring-1 ring-rnp-divider"
        role="listbox"
      >
        <li
          v-for="(item, i) in results"
          :key="item.metricId"
          role="option"
          :aria-selected="i === active"
        >
          <button
            type="button"
            class="flex w-full flex-col items-start rounded-xl px-3 py-2 text-left transition-colors"
            :class="i === active ? 'bg-rnp-accent-soft' : 'hover:bg-rnp-bg'"
            @mouseenter="active = i"
            @click="go(item)"
          >
            <span class="text-sm font-medium text-rnp-text">{{ item.name }}</span>
            <span class="text-xs text-rnp-muted">{{ item.unitName }}</span>
          </button>
        </li>
        <li
          v-if="!loading && results.length === 0"
          class="px-3 py-2 text-sm text-rnp-muted"
        >
          {{ t('layout.searchEmpty') }}
        </li>
      </ul>
    </Transition>
  </div>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { navigateTo } from '#app'
import { useApi } from '~/composables/useApi'
import { useOrgStore } from '~/features/org/stores/orgStore'
import { fetchRnpWeek } from '~/features/rnp/api/rnp'

interface SearchItem {
  metricId: number
  name: string
  code: string
  unitName: string
}

const { t } = useI18n()
const orgStore = useOrgStore()

const root = ref<HTMLElement | null>(null)
const query = ref('')
const opened = ref(false)
const active = ref(0)
const loading = ref(false)
const index = ref<SearchItem[] | null>(null)

/**
 * There is no "all metrics" endpoint: on first focus the RNP rows of every visible unit are loaded
 * (the same calls the RNP screen makes) and searched locally afterwards.
 */
async function loadIndex() {
  if (index.value !== null || loading.value) return
  loading.value = true
  try {
    if (!orgStore.units.length) await orgStore.load()
    const api = useApi()
    const weeks = await Promise.allSettled(orgStore.units.map(unit => fetchRnpWeek(api, unit.id, null, true)))
    const seen = new Set<number>()
    const items: SearchItem[] = []
    for (const week of weeks) {
      if (week.status !== 'fulfilled') continue
      for (const row of week.value.rows) {
        if (seen.has(row.metricId)) continue
        seen.add(row.metricId)
        items.push({ metricId: row.metricId, name: row.name, code: row.code, unitName: week.value.orgUnit.name })
      }
    }
    index.value = items
  }
  finally {
    loading.value = false
  }
}

const results = computed(() => {
  const q = query.value.trim().toLowerCase()
  if (!q || !index.value) return []
  return index.value.filter(item => item.name.toLowerCase().includes(q) || item.code.includes(q)).slice(0, 8)
})

function open() {
  opened.value = true
  active.value = 0
  void loadIndex()
}

function close() {
  opened.value = false
}

function move(step: number) {
  if (!results.value.length) return
  active.value = (active.value + step + results.value.length) % results.value.length
}

function go(item: SearchItem | undefined) {
  if (!item) return
  close()
  query.value = ''
  void navigateTo(`/metrics/${item.metricId}`)
}

function onDocumentClick(e: MouseEvent) {
  if (root.value && !root.value.contains(e.target as Node)) close()
}
onMounted(() => document.addEventListener('mousedown', onDocumentClick))
onBeforeUnmount(() => document.removeEventListener('mousedown', onDocumentClick))
</script>
