export type OrgRole = 'head' | 'member' | 'viewer' | 'admin'

export interface Membership {
  orgUnitId: number
  orgUnitName: string
  role: OrgRole
}

/** GET /api/me */
export interface Me {
  id: number
  email: string
  name: string
  isAdmin: boolean
  memberships: Membership[]
  /** Where the user's cabinet starts: top-most visible org units. */
  rootUnitIds: number[]
  /** Units whose missed calls the user can see (screen «Пропущенные звонки»); empty hides the menu item. */
  callUnits?: { id: number, name: string }[]
}

export interface LoginCredentials {
  email: string
  password: string
}

export interface TokenResponse {
  token: string
}
