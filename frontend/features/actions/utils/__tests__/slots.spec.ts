import { describe, expect, it } from 'vitest'
import { nextCheckValue, slotLabel, withStatus } from '../slots'
import type { RoadmapItem } from '../../types'

describe('slotLabel', () => {
  it('labels slots by periodicity', () => {
    expect(slotLabel('daily', { periodStart: '2026-10-26' })).toBe('Пн 26.10')
    expect(slotLabel('daily', { periodStart: '2026-10-30' })).toBe('Пт 30.10')
    expect(slotLabel('weekly', { periodStart: '2026-11-01' })).toBe('нед. 01.11')
    expect(slotLabel('monthly', { periodStart: '2026-10-01' })).toBe('октябрь')
    expect(slotLabel('quarterly', { periodStart: '2026-10-01' })).toBe('IV кв.')
  })
})

describe('nextCheckValue', () => {
  it('cycles none → done → not done → none', () => {
    expect(nextCheckValue(null)).toBe(true)
    expect(nextCheckValue(true)).toBe(false)
    expect(nextCheckValue(false)).toBeNull()
  })
})

describe('withStatus', () => {
  it('keeps every field but the status', () => {
    const item = {
      id: 1, metric: { id: 2, name: 'CPL' }, title: 'T', owner: { id: 7, name: 'A' }, dueDate: '2026-11-20',
      status: 'planned', expectedEffect: 'x', effect: null, isOverdue: false, canEdit: false, canChangeStatus: true, events: [],
    } as RoadmapItem

    expect(withStatus(item, 'done')).toEqual({ title: 'T', ownerUserId: 7, dueDate: '2026-11-20', status: 'done', expectedEffect: 'x' })
  })
})
