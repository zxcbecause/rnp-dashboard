<template>
  <UiCard
    hover
    class="flex flex-col"
  >
    <span
      class="mb-5 flex h-12 w-12 items-center justify-center rounded-full text-white"
      :style="{ backgroundColor: color }"
      aria-hidden="true"
    >
      <i
        :class="icon"
        class="text-lg"
      />
    </span>
    <p class="line-clamp-2 text-sm text-rnp-muted">
      {{ label }}
    </p>
    <p class="mt-1 flex flex-wrap items-baseline gap-x-1.5 tabular-nums">
      <span class="text-2xl font-bold leading-tight tracking-tight text-rnp-text md:text-[28px]">{{ shown }}</span>
      <span
        v-if="suffix"
        class="text-sm font-semibold text-rnp-text/70"
      >/{{ suffix }}</span>
    </p>
    <p
      v-if="trend"
      class="mt-3 flex items-center gap-1.5 text-xs"
      :class="trend.good ? 'text-rnp-text' : 'text-rnp-text'"
    >
      <i
        class="pi text-[11px]"
        :class="[trend.up ? 'pi-arrow-up-right' : 'pi-arrow-down-right', trend.good ? 'text-rnp-green' : 'text-rnp-red']"
        aria-hidden="true"
      />
      <span class="text-rnp-text/80">{{ trend.text }}</span>
    </p>
    <p
      v-else
      class="mt-3 text-xs text-rnp-muted"
    >
      {{ noTrendText }}
    </p>
  </UiCard>
</template>

<script setup lang="ts">
import { computed, toRef } from 'vue'
import { useCountUp } from '../composables/useMotion'
import UiCard from './UiCard.vue'

export interface KpiTrend {
  up: boolean
  /** Whether the change is good for this metric (lower-better metrics invert it). */
  good: boolean
  text: string
}

/** KPI card of the reference: coloured icon circle, grey label, big number "/plan", trend line. */
const props = withDefaults(defineProps<{
  label: string
  icon: string
  /** Icon circle colour (a --rnp-kpi-* variable) */
  color: string
  /** Raw decimal string, counted up on first show */
  value: string | null
  /** Turns the (intermediate) raw value into display text */
  format: (raw: string | null) => string
  suffix?: string | null
  trend?: KpiTrend | null
  noTrendText?: string
}>(), { suffix: null, trend: null, noTrendText: '' })

const counted = useCountUp(toRef(props, 'value'))
const shown = computed(() => props.format(counted.value) || '—')
</script>
