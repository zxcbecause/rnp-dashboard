import type { ApiRequest } from '~/composables/useApi'
import type { AdminOrgUnit } from '../types'

export function fetchAdminOrgUnits(api: ApiRequest): Promise<AdminOrgUnit[]> {
  return api<AdminOrgUnit[]>('/api/admin/org-units')
}

export function createAdminOrgUnit(api: ApiRequest, name: string, parentId: number): Promise<AdminOrgUnit> {
  return api<AdminOrgUnit>('/api/admin/org-units', { method: 'POST', body: { name, parentId } })
}

/** Renames; a new parentId moves the unit with its branch (the root keeps parentId null). */
export function updateAdminOrgUnit(api: ApiRequest, id: number, name: string, parentId: number | null): Promise<AdminOrgUnit> {
  return api<AdminOrgUnit>(`/api/admin/org-units/${id}`, { method: 'PUT', body: { name, parentId } })
}

export function setAdminOrgUnitHead(api: ApiRequest, id: number, userId: number | null): Promise<AdminOrgUnit> {
  return api<AdminOrgUnit>(`/api/admin/org-units/${id}/head`, { method: 'PUT', body: { userId } })
}

export function setAdminOrgUnitMember(api: ApiRequest, id: number, userId: number, role: 'member' | 'viewer'): Promise<AdminOrgUnit> {
  return api<AdminOrgUnit>(`/api/admin/org-units/${id}/members`, { method: 'PUT', body: { userId, role } })
}

export function removeAdminOrgUnitMember(api: ApiRequest, id: number, userId: number): Promise<AdminOrgUnit> {
  return api<AdminOrgUnit>(`/api/admin/org-units/${id}/members/${userId}`, { method: 'DELETE' })
}
