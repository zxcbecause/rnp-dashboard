<template>
  <div
    v-if="status"
    class="inline-flex flex-wrap items-center gap-x-5 gap-y-1 rounded-full px-4 py-2 text-xs transition-colors"
    :class="barClass"
    role="status"
  >
    <span
      v-for="item in items"
      :key="item.source.source"
      class="inline-flex items-center gap-1.5"
      :title="item.title"
    >
      <span
        class="inline-block h-2 w-2 shrink-0 rounded-full"
        :class="dotClass[item.health]"
        aria-hidden="true"
      />
      <span class="font-medium">{{ t(`dataStatus.source.${item.source.source}`) }}:</span>
      <span>{{ item.text }}</span>
    </span>
  </div>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useApi } from '~/composables/useApi'
import { createLatestRequestScope } from '~/composables/useLatestRequest'
import { fetchDataStatus } from '../api/dataStatus'
import type { DataStatus, SourceHealth, SourceStatus } from '../types'
import { failedResources, overallHealth, sourceHealth } from '../utils/health'

/** Refresh while the page stays open: the schedule syncs every 30 minutes. */
const REFRESH_MS = 5 * 60 * 1000

const { t, locale } = useI18n()
const status = ref<DataStatus | null>(null)
const scope = createLatestRequestScope()
let timer: ReturnType<typeof setInterval> | null = null

const dotClass: Record<SourceHealth, string> = {
  ok: 'bg-rnp-green',
  failing: 'bg-rnp-yellow',
  stale: 'bg-rnp-red',
  off: 'bg-rnp-divider',
}

const barClass = computed(() => ({
  ok: 'bg-rnp-pill text-rnp-muted',
  off: 'bg-rnp-pill text-rnp-muted',
  failing: 'bg-rnp-yellow-soft text-rnp-text',
  stale: 'bg-rnp-red-soft text-rnp-text',
}[overallHealth(status.value?.sources ?? [])]))

const items = computed(() => (status.value?.sources ?? []).map((source) => {
  const health = sourceHealth(source)
  return { source, health, text: describe(source, health), title: errorTitle(source) }
}))

function describe(source: SourceStatus, health: SourceHealth): string {
  if (health === 'off') return t('dataStatus.off')
  if (!source.dataAsOf) return t('dataStatus.never')

  const asOf = formatTime(source.dataAsOf)
  if (health === 'stale') return t('dataStatus.stale', { time: asOf })
  if (health === 'failing') return t('dataStatus.failing', { time: asOf, resources: failedResources(source).join(', ') })
  return t('dataStatus.ok', { time: asOf })
}

/** Error texts come for admins only; others get no tooltip. */
function errorTitle(source: SourceStatus): string | undefined {
  const errors = source.resources.filter(r => r.error).map(r => `${r.resource}: ${r.error}`)
  return errors.length ? errors.join('\n') : undefined
}

/** Business time is Madrid, whatever the viewer's browser zone. */
function formatTime(iso: string): string {
  return new Intl.DateTimeFormat(locale.value, {
    day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit', timeZone: 'Europe/Madrid',
  }).format(new Date(iso))
}

async function load() {
  const isLatest = scope.begin()
  try {
    const data = await fetchDataStatus(useApi())
    if (isLatest()) status.value = data
  }
  catch {
    // The bar is informational: a failed status request must not disturb the page.
    if (isLatest()) status.value = null
  }
}

onMounted(() => {
  void load()
  timer = setInterval(() => void load(), REFRESH_MS)
})

onBeforeUnmount(() => {
  if (timer) clearInterval(timer)
})
</script>
