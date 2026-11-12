type AuthResponse = { token?: string, refreshToken?: string, refreshTokenExpiration?: number }

export default defineEventHandler(async (event) => {
  const config = useRuntimeConfig()
  const refreshToken = getCookie(event, REFRESH_COOKIE)

  // "Nothing to restore" is a normal signed-out state, not an error.
  if (!refreshToken) {
    clearSessionCookies(event)
    setResponseStatus(event, 204)
    return null
  }

  const response = await $fetch.raw<AuthResponse>(`${config.apiBase}/token/refresh`, {
    method: 'POST',
    body: { refreshToken },
    headers: clientAddressHeaders(event),
    retry: 0,
    timeout: 10_000,
    ignoreResponseError: true,
  })

  const data = response._data
  if (response.ok && data?.token) {
    // Refresh tokens are single use: always store the rotated one.
    setSessionCookies(event, data.refreshToken ?? refreshToken, data.refreshTokenExpiration)
  }
  else if (response.status === 401 || response.status === 403) {
    clearSessionCookies(event)
  }

  setResponseStatus(event, response.status)

  return stripRefreshToken(maskServerError(response.status, data))
})
