import { afterEach, describe, expect, it } from 'vitest'
import { SESSION_HINT_COOKIE, clearSessionHint, hasSessionHint } from '../sessionHint'

describe('sessionHint', () => {
  afterEach(() => {
    clearSessionHint()
  })

  it('detects the hint cookie', () => {
    expect(hasSessionHint()).toBe(false)
    document.cookie = `${SESSION_HINT_COOKIE}=1; path=/`
    expect(hasSessionHint()).toBe(true)
  })

  it('treats a cleared cookie as absent', () => {
    document.cookie = `${SESSION_HINT_COOKIE}=1; path=/`
    clearSessionHint()
    expect(hasSessionHint()).toBe(false)
  })
})
