import type { ApiRequest } from '~/composables/useApi'
import type { OrgUnit, OrgUnitNode } from '../types'

export function fetchOrgUnits(api: ApiRequest): Promise<OrgUnit[]> {
  return api<OrgUnit[]>('/api/org-units')
}

/**
 * Builds the visible forest. A unit whose parent is not visible becomes a root,
 * which is exactly the entry point of a department head's cabinet.
 */
export function buildTree(units: OrgUnit[]): OrgUnitNode[] {
  const nodes = new Map<number, OrgUnitNode>()
  for (const unit of units) {
    nodes.set(unit.id, { ...unit, children: [] })
  }

  const roots: OrgUnitNode[] = []
  for (const node of nodes.values()) {
    const parent = node.parentId === null ? undefined : nodes.get(node.parentId)
    if (parent) parent.children.push(node)
    else roots.push(node)
  }

  const order = (list: OrgUnitNode[]) => {
    list.sort((a, b) => a.sort - b.sort || a.id - b.id)
    list.forEach(node => order(node.children))
  }
  order(roots)

  return roots
}
