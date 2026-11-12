import type { RnpColor } from '~/features/rnp/types'

/**
 * RNP completion colours shown as statuses: green = plan met (≥ green threshold), neutral = between the
 * thresholds (at risk), red = below the red threshold, null = no plan to compare with.
 */
export type UiStatus = 'done' | 'risk' | 'missed' | 'none'

export function statusOf(color: RnpColor | null | undefined): UiStatus {
  if (color === 'green') return 'done'
  if (color === 'neutral') return 'risk'
  if (color === 'red') return 'missed'
  return 'none'
}

/** CSS variables of a status: strong colour and its pastel background. */
export const STATUS_COLORS: Record<UiStatus, { fg: string, bg: string }> = {
  done: { fg: 'var(--rnp-green)', bg: 'var(--rnp-green-soft)' },
  risk: { fg: 'var(--rnp-yellow)', bg: 'var(--rnp-yellow-soft)' },
  missed: { fg: 'var(--rnp-red)', bg: 'var(--rnp-red-soft)' },
  none: { fg: 'var(--rnp-text-muted)', bg: 'var(--rnp-divider)' },
}
