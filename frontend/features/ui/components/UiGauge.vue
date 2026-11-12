<template>
  <div class="flex flex-col items-center">
    <svg
      viewBox="-14 0 328 170"
      class="w-full max-w-[320px]"
      role="img"
      :aria-label="label"
    >
      <!-- Ticks every 2 %, longer every 10 %. -->
      <line
        v-for="tick in ticks"
        :key="tick.p"
        :x1="tick.x1"
        :y1="tick.y1"
        :x2="tick.x2"
        :y2="tick.y2"
        :stroke="tick.major ? '#b9b1a3' : '#ddd5c6'"
        :stroke-width="tick.major ? 1.6 : 1"
        stroke-linecap="round"
      />
      <text
        v-for="mark in marks"
        :key="mark.p"
        :x="mark.x"
        :y="mark.y"
        text-anchor="middle"
        dominant-baseline="middle"
        class="fill-rnp-muted text-[10px]"
      >{{ mark.p }}</text>

      <!-- Track and coloured segments on one arc (pathLength = 100). -->
      <path
        :d="arc"
        fill="none"
        stroke="var(--rnp-divider)"
        :stroke-width="STROKE"
        stroke-linecap="round"
        pathLength="100"
      />
      <path
        v-for="segment in drawn"
        :key="segment.key"
        :d="arc"
        fill="none"
        :stroke="segment.color"
        :stroke-width="STROKE"
        stroke-linecap="butt"
        pathLength="100"
        :stroke-dasharray="`${segment.length} 100`"
        :stroke-dashoffset="-segment.start"
        class="transition-[stroke-dasharray,stroke-dashoffset] duration-1000"
        :style="{ transitionTimingFunction: 'var(--rnp-ease-out)' }"
      />

      <text
        :x="CX"
        :y="CY - 24"
        text-anchor="middle"
        class="fill-rnp-text text-[34px] font-bold"
      >{{ centerText }}</text>
      <text
        :x="CX"
        :y="CY + 2"
        text-anchor="middle"
        class="fill-rnp-muted text-[12px]"
      >{{ caption }}</text>
    </svg>
    <slot />
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useAnimatedProgress } from '../composables/useMotion'

export interface GaugeSegment {
  key: string
  /** Share in percent points; segments are laid out one after another from the left. */
  value: number
  color: string
}

/** Half-circle gauge of the reference: ticks, coloured segments, big percentage in the middle. */
const props = defineProps<{
  segments: GaugeSegment[]
  /** Big number in the middle (percent points); null shows a dash. */
  center: number | null
  caption: string
  /** Accessible name of the chart */
  label: string
}>()

const CX = 150
const CY = 150
const R = 110
const STROKE = 16

const point = (percent: number, radius: number) => {
  const angle = Math.PI * (1 - percent / 100)
  return { x: CX + radius * Math.cos(angle), y: CY - radius * Math.sin(angle) }
}

const arc = (() => {
  const from = point(0, R)
  const to = point(100, R)
  return `M ${from.x} ${from.y} A ${R} ${R} 0 0 1 ${to.x} ${to.y}`
})()

const ticks = Array.from({ length: 51 }, (_, i) => {
  const p = i * 2
  const major = p % 10 === 0
  const inner = point(p, R + STROKE / 2 + 6)
  const outer = point(p, R + STROKE / 2 + (major ? 15 : 11))
  return { p, major, x1: inner.x, y1: inner.y, x2: outer.x, y2: outer.y }
})

const marks = [0, 25, 50, 75, 100].map((p) => {
  const at = point(p, R + STROKE / 2 + 25)
  return { p, x: at.x, y: at.y }
})

// Segments grow from 0 to their size on first show.
const progress = useAnimatedProgress(computed(() => 1))

const drawn = computed(() => {
  let start = 0
  return props.segments
    .filter(s => s.value > 0)
    .map((segment) => {
      const length = Math.min(segment.value, 100 - start) * progress.value
      const out = { key: segment.key, color: segment.color, start: start * progress.value, length }
      start += segment.value
      return out
    })
})

const centerText = computed(() => (props.center === null ? '—' : `${Math.round(props.center)}%`))
</script>
