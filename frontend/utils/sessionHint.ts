/**
 * Must stay in sync with SESSION_HINT_COOKIE in server/utils/session.ts.
 * The refresh token is httpOnly, so the SPA reads this non-secret marker to decide
 * whether restoring a session is worth a request.
 */
export const SESSION_HINT_COOKIE = 'rnp_session'

function readCookie(name: string): string | null {
  if (typeof document === 'undefined' || !document.cookie) return null

  for (const chunk of document.cookie.split(';')) {
    const separator = chunk.indexOf('=')
    if (separator === -1) continue
    if (chunk.slice(0, separator).trim() === name) {
      return chunk.slice(separator + 1).trim()
    }
  }

  return null
}

export function hasSessionHint(): boolean {
  // An empty value counts as absent: a deletion may leave `name=` behind.
  const value = readCookie(SESSION_HINT_COOKIE)
  return value !== null && value !== ''
}

export function clearSessionHint(): void {
  if (typeof document === 'undefined') return
  document.cookie = `${SESSION_HINT_COOKIE}=; path=/; max-age=0; samesite=strict`
}
