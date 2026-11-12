import { defineNuxtRouteMiddleware, navigateTo } from '#app'
import { useAuthStore } from '~/features/auth/stores/authStore'
import { HOME_ROUTE, isPublicPath, isRegisteredPath } from '~/features/layout/config/routeAccess'

export default defineNuxtRouteMiddleware(async (to) => {
  const authStore = useAuthStore()

  if (!authStore.isAuthenticated) {
    await authStore.initialize()
  }

  const isPublic = isPublicPath(to.path)

  if (!authStore.isAuthenticated) {
    return isPublic ? undefined : navigateTo('/login')
  }

  if (isPublic || !isRegisteredPath(to.path)) {
    return navigateTo(HOME_ROUTE, { replace: true })
  }
})
