import { describe, expect, it } from 'vitest'
import { ApiError, AuthError, NotFoundError, RateLimitError, ValidationError, toApiError } from '../api-errors'

describe('toApiError', () => {
  it('maps statuses to typed errors', () => {
    expect(toApiError(401, { code: 401, message: 'Invalid JWT Token' })).toBeInstanceOf(AuthError)
    expect(toApiError(404, { error: 'Not found.' })).toBeInstanceOf(NotFoundError)
    expect(toApiError(429, {})).toBeInstanceOf(RateLimitError)
    expect(toApiError(500, undefined)).toBeInstanceOf(ApiError)
  })

  it('keeps backend messages and violations', () => {
    const error = toApiError(422, { error: 'Validation failed.', violations: { value: ['Must be a number.'] } })

    expect(error).toBeInstanceOf(ValidationError)
    expect(error.message).toBe('Validation failed.')
    expect((error as ValidationError).violations).toEqual({ value: ['Must be a number.'] })
  })

  it('treats status 0 as a network error', () => {
    const error = toApiError(0, undefined)

    expect(error.statusCode).toBe(0)
    expect(error.message).toBe('Network error')
  })
})
