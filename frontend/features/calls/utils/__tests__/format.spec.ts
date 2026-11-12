import { describe, expect, it } from 'vitest'
import { formatCallTime, formatDay } from '../format'

describe('call time formatting', () => {
  it('keeps the Madrid wall-clock time as sent', () => {
    expect(formatCallTime('2026-11-09T09:41:33')).toBe('09.11.2026 09:41')
    expect(formatCallTime('2026-11-30T23:59:00')).toBe('30.11.2026 23:59')
  })

  it('leaves unexpected input untouched', () => {
    expect(formatCallTime('')).toBe('')
    expect(formatCallTime('yesterday')).toBe('yesterday')
  })

  it('formats days', () => {
    expect(formatDay('2026-11-01')).toBe('01.11.2026')
  })
})
