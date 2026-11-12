import { describe, expect, it } from 'vitest'
import type { AdminOrgUnit } from '../../types'
import { branchIds, orgRows, parentOptions } from '../orgTree'

const unit = (id: number, parentId: number | null, name: string, sort = 0): AdminOrgUnit =>
  ({ id, parentId, name, sort, headUserId: null, activeMetrics: 0, members: [] })

const units = [
  unit(1, null, 'Acme'),
  unit(3, 1, 'Marketing', 20),
  unit(2, 1, 'Revenue', 10),
  unit(4, 2, 'OTA'),
  unit(5, 4, 'Content'),
]

describe('orgRows', () => {
  it('walks the tree depth-first, siblings by sort', () => {
    expect(orgRows(units).map(row => [row.unit.name, row.depth])).toEqual([
      ['Acme', 0], ['Revenue', 1], ['OTA', 2], ['Content', 3], ['Marketing', 1],
    ])
    expect(orgRows(units).find(row => row.unit.id === 5)?.path).toBe('Acme / Revenue / OTA / Content')
  })

  it('keeps a unit whose parent is unknown as a root', () => {
    expect(orgRows([unit(7, 99, 'Lost')]).map(row => row.depth)).toEqual([0])
  })
})

describe('moving', () => {
  it('excludes the unit and its whole branch from the targets', () => {
    expect([...branchIds(units, 2)].sort()).toEqual([2, 4, 5])
    expect(parentOptions(units, 2).map(row => row.unit.name)).toEqual(['Acme', 'Marketing'])
    expect(parentOptions(units, 5).map(row => row.unit.name)).toEqual(['Acme', 'Revenue', 'OTA', 'Marketing'])
  })
})
