<template>
  <span
    v-if="pct !== null"
    class="inline-flex h-6 min-w-16 items-center justify-center rounded-full px-2.5 text-xs font-semibold tabular-nums"
    :style="{ color: colors.fg, backgroundColor: colors.bg }"
    :title="title"
  >{{ formatPct(pct) }}</span>
  <span
    v-else
    class="text-rnp-muted/50"
  >—</span>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { STATUS_COLORS, statusOf } from '~/features/ui/status'
import { formatPct } from '~/utils/metricFormat'
import type { RnpColor } from '../types'

/** Completion % as a pastel status pill: green met, yellow between thresholds, red below. */
const props = defineProps<{
  pct: string | null
  color: RnpColor | null
  title?: string
}>()

const colors = computed(() => STATUS_COLORS[statusOf(props.color)])
</script>
