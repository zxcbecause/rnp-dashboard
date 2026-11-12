type AuthResponse = { token?: string, refreshToken?: string, refreshTokenExpiration?: number }

export default defineEventHandler(async (event) => {
  const config = useRuntimeConfig()
  const body = await readBody(event)

  const response = await $fetch.raw<AuthResponse>(`${config.apiBase}/login`, {
    method: 'POST',
    body,
    headers: clientAddressHeaders(event),
    retry: 0,
    ignoreResponseError: true,
  })

  const data = response._data
  if (response.ok && data?.token && data.refreshToken) {
    setSessionCookies(event, data.refreshToken, data.refreshTokenExpiration)
  }

  setResponseStatus(event, response.status)

  return stripRefreshToken(maskServerError(response.status, data))
})
