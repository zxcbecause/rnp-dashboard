import type { H3Event } from 'h3'

/**
 * Must stay in sync with SESSION_HINT_COOKIE in utils/sessionHint.ts.
 * The hint is readable from JS on purpose: it carries no secret, it only tells the SPA
 * that a refresh cookie may exist, so anonymous loads skip POST /api/token/refresh.
 */
export const SESSION_HINT_COOKIE = 'rnp_session'
export const REFRESH_COOKIE = 'rnp_refresh'

const DEFAULT_MAX_AGE = 30 * 60

function cookieBase() {
  return {
    sameSite: 'strict' as const,
    path: '/',
    // Local stack is plain http; switch on once the service is served over https.
    secure: useRuntimeConfig().cookieSecure === true,
  }
}

/**
 * The refresh token lives only in an httpOnly cookie; the browser never sees it in a body.
 */
export function setSessionCookies(event: H3Event, refreshToken: string, expiresAt?: number): void {
  const maxAge = expiresAt ? Math.max(expiresAt - Math.floor(Date.now() / 1000), 0) : DEFAULT_MAX_AGE

  setCookie(event, REFRESH_COOKIE, refreshToken, { ...cookieBase(), httpOnly: true, maxAge })
  setCookie(event, SESSION_HINT_COOKIE, '1', { ...cookieBase(), httpOnly: false, maxAge })
}

export function clearSessionCookies(event: H3Event): void {
  deleteCookie(event, REFRESH_COOKIE, { path: '/' })
  deleteCookie(event, SESSION_HINT_COOKIE, { path: '/' })
}

export function stripRefreshToken<T>(data: T): T {
  if (data && typeof data === 'object') {
    const record = data as Record<string, unknown>
    delete record.refreshToken
    delete record.refreshTokenExpiration
  }
  return data
}

/** 5xx bodies are hidden, except 503: the backend writes those for users ("AI is unavailable, try later"). */
export function maskServerError(status: number, data: unknown): unknown {
  return status >= 500 && status !== 503 ? { error: 'Internal server error.' } : data
}

/**
 * Real client address for the backend login throttling. Locally Nitro is the only hop; on the server a reverse
 * proxy sits in front, so its X-Forwarded-For is used (trustProxy) - otherwise every user would share one address.
 */
export function clientAddressHeaders(event: H3Event): Record<string, string> {
  const address = getRequestIP(event, { xForwardedFor: useRuntimeConfig().trustProxy === true })
  return address ? { 'X-Forwarded-For': address } : {}
}
