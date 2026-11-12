<template>
  <span
    class="relative inline-flex shrink-0 items-center justify-center"
    :style="{ width: `${size}px`, height: `${size}px` }"
    role="img"
    :aria-label="label"
  >
    <svg
      :width="size"
      :height="size"
      :viewBox="`0 0 ${size} ${size}`"
      class="-rotate-90"
    >
      <circle
        :cx="size / 2"
        :cy="size / 2"
        :r="radius"
        fill="none"
        stroke="var(--rnp-divider)"
        :stroke-width="stroke"
      />
      <circle
        :cx="size / 2"
        :cy="size / 2"
        :r="radius"
        fill="none"
        :stroke="color"
        :stroke-width="stroke"
        stroke-linecap="round"
        :stroke-dasharray="circumference"
        :stroke-dashoffset="offset"
        class="transition-[stroke-dashoffset] duration-700"
        :style="{ transitionTimingFunction: 'var(--rnp-ease-out)' }"
      />
    </svg>
    <span
      class="absolute font-bold text-rnp-text"
      :style="{ fontSize: `${Math.max(8, size * 0.24)}px` }"
    >{{ text }}</span>
  </span>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useAnimatedProgress } from '../composables/useMotion'
import { STATUS_COLORS } from '../status'
import type { UiStatus } from '../status'

/** Small completion ring with the percentage inside; the arc grows from 0 on first show. */
const props = withDefaults(defineProps<{
  /** Percent points; above 100 the ring is full. null = no value. */
  value: number | null
  status: UiStatus
  size?: number
}>(), { size: 40 })

const stroke = computed(() => Math.max(3, Math.round(props.size / 10)))
const radius = computed(() => (props.size - stroke.value) / 2)
const circumference = computed(() => 2 * Math.PI * radius.value)
const shown = useAnimatedProgress(computed(() => Math.min(100, Math.max(0, props.value ?? 0))))
const offset = computed(() => circumference.value * (1 - shown.value / 100))
const color = computed(() => STATUS_COLORS[props.status].fg)
const text = computed(() => (props.value === null ? '—' : `${Math.round(props.value)}%`))
const label = computed(() => (props.value === null ? '—' : `${props.value}%`))
</script>
