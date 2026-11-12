import { $fetch } from 'ofetch'
import type { FetchError } from 'ofetch'
import type { ApiRequest } from '~/composables/useApi'
import { toApiError } from '~/types/api-errors'
import type { LoginCredentials, Me, TokenResponse } from '../types'

/**
 * Auth endpoints are called without the Bearer header and without the 401 retry of useApi:
 * they ARE the session lifecycle. Nitro keeps the refresh token in an httpOnly cookie.
 */
/** Session calls run under the cross-tab lock: a hanging one must not hold every tab. */
const SESSION_TIMEOUT_MS = 15_000

async function rawPost<T>(url: string, body?: Record<string, unknown>): Promise<T> {
  try {
    return await $fetch<T>(url, { method: 'POST', body, retry: 0, timeout: SESSION_TIMEOUT_MS })
  }
  catch (error) {
    const fetchError = error as FetchError
    throw toApiError(fetchError.response?.status ?? 0, fetchError.response?._data)
  }
}

export function login(credentials: LoginCredentials): Promise<TokenResponse> {
  return rawPost<TokenResponse>('/api/login', { username: credentials.email, password: credentials.password })
}

/** Resolves to null when there is no session to restore (204). */
export async function refresh(): Promise<TokenResponse | null> {
  const response = await rawPost<TokenResponse | null | undefined>('/api/token/refresh')
  return response?.token ? response : null
}

export async function logout(): Promise<void> {
  await rawPost('/api/logout')
}

export function fetchMe(api: ApiRequest): Promise<Me> {
  return api<Me>('/api/me')
}

/** 422 with a user-facing message on a wrong current password or a weak new one. */
export function changePassword(api: ApiRequest, currentPassword: string, newPassword: string): Promise<void> {
  return api('/api/me/password', { method: 'PUT', body: { currentPassword, newPassword } })
}
