export class ApiError extends Error {
  constructor(
    message: string,
    public statusCode: number,
    public data?: unknown,
  ) {
    super(message)
    this.name = 'ApiError'
  }
}

export class AuthError extends ApiError {
  constructor(message: string = 'Unauthorized') {
    super(message, 401)
    this.name = 'AuthError'
  }
}

/** Missing or outside the user's branch: the backend deliberately does not tell them apart. */
export class NotFoundError extends ApiError {
  constructor(message: string = 'Not found') {
    super(message, 404)
    this.name = 'NotFoundError'
  }
}

export class ValidationError extends ApiError {
  constructor(
    public violations: Record<string, string[]>,
    message: string = 'Validation failed',
  ) {
    super(message, 422, violations)
    this.name = 'ValidationError'
  }
}

export class RateLimitError extends ApiError {
  constructor(message: string = 'Too many requests') {
    super(message, 429)
    this.name = 'RateLimitError'
  }
}

type ErrorBody = { error?: string, message?: string, violations?: Record<string, string[]> } | undefined

/** Maps an HTTP status + backend body to a typed error. Status 0 means a network failure. */
export function toApiError(status: number, body: unknown): ApiError {
  const data = (body && typeof body === 'object' ? body : undefined) as ErrorBody
  const message = data?.error || data?.message || ''

  switch (status) {
    case 401:
      return new AuthError(message || undefined)
    case 404:
      return new NotFoundError(message || undefined)
    case 422:
      return new ValidationError(data?.violations ?? {}, message || undefined)
    case 429:
      return new RateLimitError(message || undefined)
    default:
      return new ApiError(message || (status === 0 ? 'Network error' : `HTTP ${status}`), status, data)
  }
}
