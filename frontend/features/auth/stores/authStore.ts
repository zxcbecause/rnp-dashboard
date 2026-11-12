import { defineStore } from 'pinia'
import { useApi } from '~/composables/useApi'
import { AuthError } from '~/types/api-errors'
import { clearSessionHint, hasSessionHint } from '~/utils/sessionHint'
import * as authApi from '../api/auth'
import type { LoginCredentials, Me } from '../types'
import { broadcastSignOut, withRefreshLock } from '../utils/crossTab'

/** One refresh request at a time per tab: concurrent 401s share it (other tabs wait on the lock). */
let refreshInFlight: Promise<string> | null = null

export interface AuthState {
  /** Access JWT, memory only. Reloads restore it through the httpOnly refresh cookie. */
  token: string | null
  me: Me | null
  initialized: boolean
}

export const useAuthStore = defineStore('auth', {
  state: (): AuthState => ({
    token: null,
    me: null,
    initialized: false,
  }),

  getters: {
    isAuthenticated: state => state.token !== null,
  },

  actions: {
    /** Restores the session once per page load, if the hint cookie says there may be one. */
    async initialize(): Promise<void> {
      if (this.initialized) return
      this.initialized = true

      if (!hasSessionHint()) return

      try {
        await this.refreshAccessToken()
        await this.loadMe()
      }
      catch (error) {
        this.clearSession()
        if (!(error instanceof AuthError)) {
          console.warn('Session restore failed:', error)
        }
      }
    },

    async login(credentials: LoginCredentials): Promise<void> {
      const { token } = await authApi.login(credentials)
      this.token = token
      await this.loadMe()
    },

    async loadMe(): Promise<void> {
      this.me = await authApi.fetchMe(useApi())
    },

    refreshAccessToken(): Promise<string> {
      refreshInFlight ??= (async () => {
        try {
          // Serialised across tabs: the refresh token is a single-use cookie they all share.
          const response = await withRefreshLock(() => authApi.refresh())
          if (!response) {
            throw new AuthError('No session')
          }
          this.token = response.token
          return response.token
        }
        finally {
          refreshInFlight = null
        }
      })()

      return refreshInFlight
    },

    async logout(): Promise<void> {
      try {
        // Under the refresh lock: a refresh of another tab either finished (the logout deletes the token it
        // rotated) or starts after it (no cookie left) — it can never bring the session back.
        await withRefreshLock(() => authApi.logout())
      }
      finally {
        this.clearSession()
        broadcastSignOut()
      }
    },

    clearSession(): void {
      this.token = null
      this.me = null
      clearSessionHint()
    },
  },
})
