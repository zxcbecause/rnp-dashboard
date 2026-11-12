import { describe, expect, it } from 'vitest'
import { buildTree } from '../org'
import type { OrgUnit } from '../../types'

const unit = (id: number, parentId: number | null, sort = 0): OrgUnit => ({
  id, parentId, name: `Unit ${id}`, headUser: null, sort,
})

describe('buildTree', () => {
  it('nests children under visible parents and orders by sort', () => {
    const tree = buildTree([unit(1, null), unit(3, 1, 20), unit(2, 1, 10), unit(4, 2)])

    expect(tree.map(n => n.id)).toEqual([1])
    expect(tree[0]!.children.map(n => n.id)).toEqual([2, 3])
    expect(tree[0]!.children[0]!.children.map(n => n.id)).toEqual([4])
  })

  it('turns a unit with an invisible parent into a root (department head cabinet)', () => {
    const tree = buildTree([unit(2, 1), unit(5, 2)])

    expect(tree.map(n => n.id)).toEqual([2])
    expect(tree[0]!.children.map(n => n.id)).toEqual([5])
  })
})
