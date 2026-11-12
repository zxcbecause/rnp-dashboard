import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { navigateTo } from '#app'
import { useAsyncAction } from '~/composables/useAsyncAction'
import { AuthError, RateLimitError } from '~/types/api-errors'
import { useAuthStore } from '../stores/authStore'
import type { LoginCredentials } from '../types'

export function useLogin() {
  const authStore = useAuthStore()
  const { t } = useI18n()
  const errorMessage = ref<string | null>(null)

  const { pending, run } = useAsyncAction(
    async (credentials: LoginCredentials) => {
      errorMessage.value = null
      await authStore.login(credentials)
      await navigateTo('/cabinet')
    },
    {
      rethrow: false,
      onError: (error) => {
        if (error instanceof AuthError) errorMessage.value = t('auth.invalidCredentials')
        else if (error instanceof RateLimitError) errorMessage.value = t('auth.tooManyAttempts')
        else errorMessage.value = t('errors.unknown')
      },
    },
  )

  return { pending, errorMessage, submit: run }
}
