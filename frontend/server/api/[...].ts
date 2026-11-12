/**
 * Generic proxy: /api/* → backend. Auth endpoints that touch cookies have their own handlers
 * (login, logout, token/refresh) and take precedence over this catch-all route.
 */
export default defineEventHandler(async (event) => {
  const config = useRuntimeConfig()
  const url = getRequestURL(event)

  let pathname: string
  try {
    pathname = decodeURIComponent(url.pathname)
  }
  catch {
    throw createError({ statusCode: 400, message: 'Invalid path' })
  }

  const segments = pathname.slice('/api'.length).split('/').filter(Boolean)
  if (segments.some(segment => segment === '.' || segment === '..' || /[^\w\-.~]/.test(segment))) {
    throw createError({ statusCode: 400, message: 'Invalid path' })
  }

  // The API documentation is for developers: a production build (shown to others, e.g. through a tunnel)
  // does not proxy it. The app itself never calls it.
  if (!import.meta.dev && segments[0]?.startsWith('doc')) {
    throw createError({ statusCode: 404, message: 'Not found' })
  }

  const headers: Record<string, string> = {
    'Content-Type': 'application/json',
    'Accept': 'application/json',
    ...clientAddressHeaders(event),
  }
  const authorization = getRequestHeader(event, 'authorization')
  if (authorization) {
    headers.Authorization = authorization
  }

  const response = await $fetch.raw(`${config.apiBase}/${segments.join('/')}`, {
    method: event.method,
    headers,
    body: ['GET', 'HEAD'].includes(event.method) ? undefined : await readBody(event),
    query: getQuery(event),
    retry: 0,
    ignoreResponseError: true,
  })

  setResponseStatus(event, response.status)

  return stripRefreshToken(maskServerError(response.status, response._data))
})
