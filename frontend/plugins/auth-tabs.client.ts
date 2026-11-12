import { defineNuxtPlugin, navigateTo } from '#app'
import { useAuthStore } from '~/features/auth/stores/authStore'
import { onSignedOutElsewhere } from '~/features/auth/utils/crossTab'

/** A sign-out in another tab ends the session here too (the shared refresh cookie is already gone). */
export default defineNuxtPlugin(() => {
  onSignedOutElsewhere(() => {
    const authStore = useAuthStore()
    if (!authStore.isAuthenticated) return
    authStore.clearSession()
    void navigateTo('/login')
  })
})
