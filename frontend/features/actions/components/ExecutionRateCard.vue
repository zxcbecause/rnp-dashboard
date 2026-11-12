<template>
  <UiCard>
    <div class="flex items-center gap-5">
      <UiProgressRing
        :value="rate.pct === null ? null : Number(rate.pct)"
        :status="status"
        :size="72"
      />
      <div class="min-w-0">
        <div class="text-sm text-rnp-muted">
          {{ label }}
        </div>
        <div class="mt-0.5 flex flex-wrap items-baseline gap-x-2">
          <span class="text-3xl font-bold tabular-nums text-rnp-text">{{ rate.pct === null ? '—' : formatPct(rate.pct) }}</span>
          <span class="text-sm text-rnp-muted">{{ t('actions.rate.doneOf', { done: rate.done, planned: rate.planned }) }}</span>
        </div>
        <div class="mt-1.5 text-xs text-rnp-muted">
          {{ t('actions.rate.split', { bfDone: rate.baseFunctionsDone, bfPlanned: rate.baseFunctionsPlanned, rmDone: rate.roadmapDone, rmPlanned: rate.roadmapPlanned, spDone: rate.sprintDone, spPlanned: rate.sprintPlanned }) }}
        </div>
      </div>
    </div>
  </UiCard>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import UiCard from '~/features/ui/components/UiCard.vue'
import UiProgressRing from '~/features/ui/components/UiProgressRing.vue'
import type { UiStatus } from '~/features/ui/status'
import { formatPct } from '~/utils/metricFormat'
import type { ExecutionRate } from '../types'

const props = defineProps<{ label: string, rate: ExecutionRate }>()
const { t } = useI18n()

// Same default thresholds as the RNP screen: ≥ 100 % done, < 90 % missed, in between at risk.
const status = computed<UiStatus>(() => {
  if (props.rate.pct === null) return 'none'
  const pct = Number(props.rate.pct)
  return pct >= 100 ? 'done' : pct < 90 ? 'missed' : 'risk'
})
</script>
