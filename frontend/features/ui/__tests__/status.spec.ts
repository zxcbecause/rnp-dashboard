import { describe, expect, it } from 'vitest'
import { statusOf } from '../status'

describe('statusOf', () => {
  it('maps RNP colours to statuses', () => {
    expect(statusOf('green')).toBe('done')
    expect(statusOf('neutral')).toBe('risk')
    expect(statusOf('red')).toBe('missed')
    expect(statusOf(null)).toBe('none')
  })
})
