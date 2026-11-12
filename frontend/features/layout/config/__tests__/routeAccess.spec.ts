import { describe, expect, it } from 'vitest'
import { isPublicPath, isRegisteredPath } from '../routeAccess'

describe('routeAccess', () => {
  it('treats only the login route as public', () => {
    expect(isPublicPath('/login')).toBe(true)
    expect(isPublicPath('/cabinet')).toBe(false)
    expect(isPublicPath('/loginx')).toBe(false)
  })

  it('accepts every page of the skeleton', () => {
    for (const path of ['/', '/cabinet', '/rnp-week', '/actions', '/metrics', '/sprints', '/errors', '/calls', '/metrics/12', '/admin/tokens']) {
      expect(isRegisteredPath(path), path).toBe(true)
    }
  })

  it('denies unregistered top-level segments', () => {
    expect(isRegisteredPath('/settings')).toBe(false)
    expect(isRegisteredPath('/users/1')).toBe(false)
  })
})
