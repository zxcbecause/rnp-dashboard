export default defineEventHandler(async (event) => {
  const config = useRuntimeConfig()
  const refreshToken = getCookie(event, REFRESH_COOKIE)

  if (refreshToken) {
    // Revoke server-side; a failure must not keep the browser signed in.
    await $fetch.raw(`${config.apiBase}/logout`, {
      method: 'POST',
      body: { refreshToken },
      retry: 0,
      ignoreResponseError: true,
    }).catch(() => null)
  }

  clearSessionCookies(event)

  return { success: true }
})
