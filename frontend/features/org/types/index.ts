export interface UserRef {
  id: number
  name: string
}

/** GET /api/org-units item */
export interface OrgUnit {
  id: number
  parentId: number | null
  name: string
  headUser: UserRef | null
  sort: number
}

export interface OrgUnitNode extends OrgUnit {
  children: OrgUnitNode[]
}
