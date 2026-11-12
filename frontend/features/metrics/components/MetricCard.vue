<template>
  <UiCard :title="t('metrics.card.title')">
    <dl class="flex flex-col">
      <div
        v-for="item in items"
        :key="item.label"
        class="grid gap-1 border-b border-rnp-divider/70 py-3 text-sm first:pt-0 last:border-0 last:pb-0 sm:grid-cols-[11rem_1fr] sm:gap-6"
      >
        <dt class="text-rnp-muted">
          {{ item.label }}
        </dt>
        <dd class="text-rnp-text">
          <code
            v-if="item.mono && item.value"
            class="inline-block rounded-lg bg-rnp-bg px-2 py-0.5 font-mono text-xs"
          >{{ item.value }}</code>
          <template v-else>
            {{ item.value || '—' }}
          </template>
        </dd>
      </div>
    </dl>
  </UiCard>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import UiCard from '~/features/ui/components/UiCard.vue'
import type { Metric } from '../types'

const props = defineProps<{ metric: Metric }>()
const { t } = useI18n()

const items = computed(() => [
  { label: t('metrics.card.whatMeasured'), value: props.metric.card.whatMeasured },
  { label: t('metrics.card.formula'), value: props.metric.card.formulaText },
  { label: t('metrics.card.calculation'), value: props.metric.formula, mono: true },
  // Only metrics whose plan is computed from other metrics (e.g. 30 % of incoming bookings).
  ...(props.metric.planFormula ? [{ label: t('metrics.card.planCalculation'), value: props.metric.planFormula, mono: true }] : []),
  { label: t('metrics.card.source'), value: props.metric.card.sourceText },
  { label: t('metrics.card.period'), value: props.metric.card.periodText },
  { label: t('metrics.card.usedIn'), value: props.metric.card.usedIn },
  { label: t('metrics.card.direction'), value: t(`metrics.direction.${props.metric.direction}`) },
  { label: t('metrics.card.owner'), value: props.metric.owner?.name ?? null },
  { label: t('metrics.card.filler'), value: props.metric.isManual ? (props.metric.filler?.name ?? null) : t('metrics.card.automatic') },
])
</script>
