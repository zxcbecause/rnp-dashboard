/**
 * Session coordination between tabs of one browser.
 *
 * The refresh token is a single-use httpOnly cookie shared by every tab. Two tabs refreshing at once
 * (several tabs restored on browser start, or JWTs expiring together) used to send the same token
 * twice: the second request got 401 and the Nitro handler cleared the cookie, signing every tab out.
 *
 * - Refreshes are serialised with the Web Locks API: the browser sends the cookie at request time,
 *   so the tab that waited uses the token the previous holder has just rotated. No token is shared
 *   through JavaScript (the refresh token is unreadable there anyway).
 * - A sign-out is broadcast (BroadcastChannel), so the other tabs leave at once instead of failing
 *   on their next request.
 * Without these APIs everything stays per tab, as before.
 */

const LOCK_NAME = 'rnp-auth-refresh'
const CHANNEL_NAME = 'rnp-auth'

type Message = { type: 'signed_out' }

interface Environment {
  locks: Pick<LockManager, 'request'> | null
  createChannel: (() => Pick<BroadcastChannel, 'postMessage' | 'addEventListener' | 'close'>) | null
}

function browserEnvironment(): Environment {
  const locks = typeof navigator !== 'undefined' && navigator.locks && typeof navigator.locks.request === 'function'
    ? navigator.locks
    : null
  const createChannel = typeof BroadcastChannel !== 'undefined' ? () => new BroadcastChannel(CHANNEL_NAME) : null
  return { locks, createChannel }
}

let environment: Environment | null = null
const env = (): Environment => (environment ??= browserEnvironment())

/** Runs a refresh while holding the cross-tab lock (or directly when the browser has no Web Locks). */
export async function withRefreshLock<T>(refresh: () => Promise<T>): Promise<T> {
  const locks = env().locks
  if (!locks) return refresh()
  return await locks.request(LOCK_NAME, () => refresh()) as T
}

/** Tells the other tabs that the session is over. */
export function broadcastSignOut(): void {
  const create = env().createChannel
  if (!create) return
  try {
    const channel = create()
    channel.postMessage({ type: 'signed_out' } satisfies Message)
    channel.close()
  }
  catch {
    // no channel: the other tabs find out on their next request
  }
}

/** Calls the listener when another tab signs out; returns the unsubscribe function. */
export function onSignedOutElsewhere(listener: () => void): () => void {
  const create = env().createChannel
  if (!create) return () => {}
  const channel = create()
  channel.addEventListener('message', (event: MessageEvent<Message>) => {
    if (event.data?.type === 'signed_out') listener()
  })
  return () => channel.close()
}

/** Tests only: replaces the browser APIs (null restores the real ones on next use). */
export function setCrossTabEnvironment(next: Environment | null): void {
  environment = next
}
