import { $fetch } from 'ofetch'
import type { FetchError, FetchOptions } from 'ofetch'
import { AuthError, toApiError } from '~/types/api-errors'
import { useAuthStore } from '~/features/auth/stores/authStore'

const REFRESH_PATH = '/api/token/refresh'

export type ApiRequest = <T>(url: string, options?: FetchOptions<'json'>) => Promise<T>

/**
 * Typed client for the Nitro proxy: attaches the in-memory JWT, refreshes it once on 401
 * (single flight, see authStore.refreshAccessToken) and maps failures to typed errors.
 */
export function useApi(): ApiRequest {
  const request = async <T>(url: string, options: FetchOptions<'json'> = {}): Promise<T> => {
    const authStore = useAuthStore()
    const headers = new Headers(options.headers as HeadersInit | undefined)
    if (authStore.token) {
      headers.set('Authorization', `Bearer ${authStore.token}`)
    }

    return await $fetch<T>(url, { ...options, headers, retry: 0 }) as T
  }

  return async <T>(url: string, options: FetchOptions<'json'> = {}): Promise<T> => {
    try {
      return await request<T>(url, options)
    }
    catch (error) {
      const status = (error as FetchError).response?.status ?? 0
      const data = (error as FetchError).response?._data

      if (status === 401 && !url.startsWith(REFRESH_PATH)) {
        const authStore = useAuthStore()
        if (authStore.token) {
          try {
            await authStore.refreshAccessToken()
          }
          catch {
            authStore.clearSession()
            throw new AuthError('Session expired')
          }

          try {
            return await request<T>(url, options)
          }
          catch (retryError) {
            const retry = retryError as FetchError
            throw toApiError(retry.response?.status ?? 0, retry.response?._data)
          }
        }
      }

      throw toApiError(status, data)
    }
  }
}
