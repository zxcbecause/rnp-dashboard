<template>
  <svg
    :viewBox="`0 0 ${WIDTH} ${HEIGHT}`"
    class="block overflow-visible"
    role="img"
    :aria-label="label"
  >
    <defs>
      <filter
        :id="`${uid}-glow`"
        x="-20%"
        y="-50%"
        width="140%"
        height="200%"
      >
        <feGaussianBlur stdDeviation="5" />
      </filter>
      <radialGradient :id="`${uid}-head`">
        <stop
          offset="0%"
          stop-color="#fff8dc"
        />
        <stop
          offset="45%"
          :stop-color="color"
        />
        <stop
          offset="100%"
          :stop-color="color"
          stop-opacity="0"
        />
      </radialGradient>
    </defs>

    <!-- Monitor trace: the whole rhythm, faint -->
    <path
      :d="path"
      fill="none"
      :stroke="color"
      stroke-opacity="0.16"
      stroke-width="2"
      stroke-linejoin="round"
      vector-effect="non-scaling-stroke"
    />

    <template v-if="animated">
      <!-- Glow under the running signal -->
      <path
        v-if="glow"
        :d="path"
        fill="none"
        :stroke="color"
        stroke-width="7"
        stroke-linecap="round"
        stroke-linejoin="round"
        pathLength="1"
        :stroke-dasharray="`${TAIL} 2`"
        :filter="`url(#${uid}-glow)`"
        opacity="0.75"
      >
        <animate
          attributeName="stroke-dashoffset"
          :values="`${TAIL};-1`"
          :dur="`${DURATION}s`"
          repeatCount="indefinite"
        />
      </path>
      <!-- The running signal: a bright segment travelling along the rhythm -->
      <path
        :d="path"
        fill="none"
        :stroke="color"
        stroke-width="2.5"
        stroke-linecap="round"
        stroke-linejoin="round"
        pathLength="1"
        :stroke-dasharray="`${TAIL} 2`"
        vector-effect="non-scaling-stroke"
      >
        <animate
          attributeName="stroke-dashoffset"
          :values="`${TAIL};-1`"
          :dur="`${DURATION}s`"
          repeatCount="indefinite"
        />
      </path>
      <!-- Leading dot: reaches the end while the tail still fades out -->
      <circle
        r="9"
        :fill="`url(#${uid}-head)`"
      >
        <animateMotion
          :path="path"
          :dur="`${DURATION}s`"
          repeatCount="indefinite"
          calcMode="linear"
          :keyTimes="`0;${headEnd};1`"
          keyPoints="0;1;1"
        />
        <animate
          attributeName="opacity"
          :values="'1;1;0;0'"
          :keyTimes="`0;${headEnd};${Math.min(1, headEnd + 0.04)};1`"
          :dur="`${DURATION}s`"
          repeatCount="indefinite"
        />
      </circle>
    </template>
    <path
      v-else
      :d="path"
      fill="none"
      :stroke="color"
      stroke-width="2.5"
      stroke-linejoin="round"
      vector-effect="non-scaling-stroke"
    />
  </svg>
</template>

<script setup lang="ts">
import { onMounted, ref, useId } from 'vue'

/**
 * "Hand on the pulse": an ECG-like rhythm with a bright signal running along it, as on a heart monitor.
 * SMIL keeps the segment and its leading dot on one timeline; with reduced motion the trace is static.
 */
withDefaults(defineProps<{
  color?: string
  label?: string
  /** Blurred glow under the signal: for dark backgrounds */
  glow?: boolean
}>(), { color: '#eec52b', label: undefined, glow: true })

const WIDTH = 720
const HEIGHT = 160
const BASE = 96
/** Seconds per pass and the visible tail as a share of the path length */
const DURATION = 4.2
const TAIL = 0.28
/** The head reaches the end when the dash has moved 1 of its (1 + TAIL) travel. */
const headEnd = Number((1 / (1 + TAIL)).toFixed(4))

/** One PQRST complex at x with an R peak of the given height. */
function beat(x: number, peak: number): string {
  return [
    `L${x} ${BASE}`,
    `Q${x + 12} ${BASE - 12} ${x + 24} ${BASE}`,
    `L${x + 34} ${BASE}`,
    `L${x + 40} ${BASE + 9}`,
    `L${x + 48} ${BASE - peak}`,
    `L${x + 56} ${BASE + 22}`,
    `L${x + 62} ${BASE}`,
    `L${x + 80} ${BASE}`,
    `Q${x + 96} ${BASE - 18} ${x + 112} ${BASE}`,
  ].join(' ')
}

const path = [`M0 ${BASE}`, beat(40, 70), beat(220, 82), beat(400, 64), beat(580, 76), `L${WIDTH} ${BASE}`].join(' ')

const uid = `pulse-${useId()}`
const animated = ref(false)

onMounted(() => {
  animated.value = !window.matchMedia('(prefers-reduced-motion: reduce)').matches
})
</script>
