import { describe, expect, it } from 'vitest'
import { decimalsOf, easeOut } from '../useMotion'

describe('motion helpers', () => {
  it('keeps the decimals of the target value', () => {
    expect(decimalsOf('12')).toBe(0)
    expect(decimalsOf('12.5')).toBe(1)
    expect(decimalsOf('0.4500')).toBe(4)
  })

  it('eases from 0 to 1 and decelerates', () => {
    expect(easeOut(0)).toBe(0)
    expect(easeOut(1)).toBe(1)
    expect(easeOut(0.5)).toBeGreaterThan(0.5)
  })
})
