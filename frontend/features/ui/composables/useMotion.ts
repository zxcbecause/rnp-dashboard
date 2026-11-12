import { onBeforeUnmount, ref, watch } from 'vue'
import type { Ref } from 'vue'

export const COUNT_UP_MS = 600

export function prefersReducedMotion(): boolean {
  return typeof window !== 'undefined' && typeof window.matchMedia === 'function'
    && window.matchMedia('(prefers-reduced-motion: reduce)').matches
}

/** easeOutCubic, close to the CSS cubic-bezier(0.22, 1, 0.36, 1) used everywhere else. */
export function easeOut(t: number): number {
  return 1 - (1 - t) ** 3
}

/** Decimal places of a decimal string ("12.50" → 2). */
export function decimalsOf(value: string): number {
  const dot = value.indexOf('.')
  return dot < 0 ? 0 : value.length - dot - 1
}

/**
 * Display-only count-up of a decimal string: on the first value it runs from 0 to the target (~600 ms),
 * later changes (another week, a filter) snap to the new value. The stored value is never touched;
 * the intermediate frames keep the target's number of decimals.
 */
export function useCountUp(target: Ref<string | null>): Ref<string | null> {
  const display = ref<string | null>(target.value)
  let played = false
  let frame = 0

  const run = (value: string | null) => {
    cancelAnimationFrame(frame)
    const end = value === null ? Number.NaN : Number(value)
    if (played || value === null || !Number.isFinite(end) || end === 0 || prefersReducedMotion()) {
      display.value = value
      played = played || value !== null
      return
    }

    played = true
    const decimals = decimalsOf(value)
    const started = performance.now()
    const step = (now: number) => {
      const t = Math.min(1, (now - started) / COUNT_UP_MS)
      display.value = t >= 1 ? value : (end * easeOut(t)).toFixed(decimals)
      if (t < 1) frame = requestAnimationFrame(step)
    }
    display.value = (0).toFixed(decimals)
    frame = requestAnimationFrame(step)
  }

  watch(target, run, { immediate: true })
  onBeforeUnmount(() => cancelAnimationFrame(frame))

  return display
}

/**
 * Animated progress for SVG strokes: 0 on mount, the value right after (CSS transitions the dash offset).
 * Later changes animate from the previous value.
 */
export function useAnimatedProgress(target: Ref<number>): Ref<number> {
  const shown = ref(prefersReducedMotion() ? target.value : 0)
  let frame = requestAnimationFrame(() => {
    frame = requestAnimationFrame(() => {
      shown.value = target.value
    })
  })
  watch(target, (value) => {
    shown.value = value
  })
  onBeforeUnmount(() => cancelAnimationFrame(frame))

  return shown
}
