/** GET /api/admin/ingest-tokens item: the token itself is never returned after creation */
export interface IngestToken {
  id: number
  name: string
  orgUnitId: number
  orgUnitName: string
  /** First characters of the token + "…" */
  hint: string
  createdAt: string
  lastUsedAt: string | null
  revokedAt: string | null
}

export interface CreatedIngestToken {
  /** Shown once: only its hash is stored */
  token: string
  item: IngestToken
}

export type MemberRole = 'head' | 'member' | 'viewer' | 'admin'

/** GET /api/admin/users item */
export interface AdminUser {
  id: number
  email: string
  name: string
  isAdmin: boolean
  isActive: boolean
  createdAt: string
  memberships: { orgUnitId: number, orgUnitName: string, role: MemberRole }[]
}

/** A generated password is returned once (creation or reset) and never again */
export interface CreatedAdminUser {
  password: string
  item: AdminUser
}

export interface AdminMember {
  userId: number
  name: string
  email: string
  role: MemberRole
  isActive: boolean
}

/** GET /api/admin/org-units item (flat; parentId builds the tree) */
export interface AdminOrgUnit {
  id: number
  parentId: number | null
  name: string
  sort: number
  headUserId: number | null
  activeMetrics: number
  members: AdminMember[]
}
