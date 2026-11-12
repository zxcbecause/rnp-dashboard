import type { AdminOrgUnit } from '../types'

export interface OrgRow {
  unit: AdminOrgUnit
  depth: number
  /** "Acme / Revenue / OTA" */
  path: string
}

/** Depth-first rows of the tree (siblings by sort, then id); units with an unknown parent become roots. */
export function orgRows(units: AdminOrgUnit[]): OrgRow[] {
  const ids = new Set(units.map(unit => unit.id))
  const children = new Map<number | null, AdminOrgUnit[]>()
  for (const unit of units) {
    const parent = unit.parentId !== null && ids.has(unit.parentId) ? unit.parentId : null
    children.set(parent, [...(children.get(parent) ?? []), unit])
  }

  const rows: OrgRow[] = []
  const walk = (parent: number | null, depth: number, path: string[]) => {
    const list = [...(children.get(parent) ?? [])].sort((a, b) => a.sort - b.sort || a.id - b.id)
    for (const unit of list) {
      const trail = [...path, unit.name]
      rows.push({ unit, depth, path: trail.join(' / ') })
      walk(unit.id, depth + 1, trail)
    }
  }
  walk(null, 0, [])

  return rows
}

/** Ids of the unit and everything under it: a unit cannot move into its own branch. */
export function branchIds(units: AdminOrgUnit[], id: number): Set<number> {
  const branch = new Set([id])
  let grew = true
  while (grew) {
    grew = false
    for (const unit of units) {
      if (unit.parentId !== null && branch.has(unit.parentId) && !branch.has(unit.id)) {
        branch.add(unit.id)
        grew = true
      }
    }
  }
  return branch
}

/** Where a unit may be moved: every unit outside its branch. */
export function parentOptions(units: AdminOrgUnit[], id: number): OrgRow[] {
  const branch = branchIds(units, id)
  return orgRows(units).filter(row => !branch.has(row.unit.id))
}
