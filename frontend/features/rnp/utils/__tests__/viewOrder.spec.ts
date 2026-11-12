import { describe, expect, it } from 'vitest'
import { canSaveView, moveEntry } from '../viewOrder'

describe('moveEntry', () => {
  it('moves up and down without mutating the input', () => {
    const list = ['a', 'b', 'c']
    expect(moveEntry(list, 2, -1)).toEqual(['a', 'c', 'b'])
    expect(moveEntry(list, 0, 1)).toEqual(['b', 'a', 'c'])
    expect(list).toEqual(['a', 'b', 'c'])
  })

  it('ignores moves past the edges', () => {
    const list = ['a', 'b']
    expect(moveEntry(list, 0, -1)).toBe(list)
    expect(moveEntry(list, 1, 1)).toBe(list)
    expect(moveEntry(list, 5, -1)).toBe(list)
  })
})

describe('canSaveView', () => {
  it('requires at least one visible metric', () => {
    expect(canSaveView([{ metricId: 1, name: 'a', visible: false }, { metricId: 2, name: 'b', visible: true }])).toBe(true)
    expect(canSaveView([{ metricId: 1, name: 'a', visible: false }])).toBe(false)
    expect(canSaveView([])).toBe(false)
  })
})
