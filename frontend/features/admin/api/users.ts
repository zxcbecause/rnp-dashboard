import type { ApiRequest } from '~/composables/useApi'
import type { AdminUser, CreatedAdminUser } from '../types'

export function fetchAdminUsers(api: ApiRequest): Promise<AdminUser[]> {
  return api<AdminUser[]>('/api/admin/users')
}

export function createAdminUser(api: ApiRequest, body: { email: string, name: string, isAdmin: boolean }): Promise<CreatedAdminUser> {
  return api<CreatedAdminUser>('/api/admin/users', { method: 'POST', body })
}

export function updateAdminUser(api: ApiRequest, id: number, body: { name: string, isAdmin: boolean, isActive: boolean }): Promise<AdminUser> {
  return api<AdminUser>(`/api/admin/users/${id}`, { method: 'PUT', body })
}

/** Ends every session of the account; the new password is returned once. */
export function resetAdminUserPassword(api: ApiRequest, id: number): Promise<{ password: string }> {
  return api<{ password: string }>(`/api/admin/users/${id}/reset-password`, { method: 'POST' })
}

/** Only accounts without history (422 with the reason otherwise). */
export function deleteAdminUser(api: ApiRequest, id: number): Promise<void> {
  return api(`/api/admin/users/${id}`, { method: 'DELETE' })
}
