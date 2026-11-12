/**
 * Route registry. Every top-level route must be listed here: an unregistered first path
 * segment is denied (redirected to the cabinet), so a forgotten page never becomes reachable
 * by accident. Data visibility itself is enforced by the backend (404 outside the branch).
 */
export const PUBLIC_ROUTES = ['/login']

export const HOME_ROUTE = '/cabinet'

const PROTECTED_SEGMENTS = new Set([
  'cabinet',
  'rnp-week',
  'actions',
  'metrics',
  'sprints',
  'errors',
  'calls',
  'help',
  // Administrator screens; the API answers 403 to everyone else.
  'admin',
])

export function isPublicPath(path: string): boolean {
  return PUBLIC_ROUTES.some(route => path === route || path.startsWith(`${route}/`))
}

/** true for registered protected routes; the root "/" only redirects home. */
export function isRegisteredPath(path: string): boolean {
  if (isPublicPath(path)) return true

  const first = path.split('/').filter(Boolean)[0]
  return first === undefined || PROTECTED_SEGMENTS.has(first)
}
