<template>
  <Dialog
    :visible="visible"
    modal
    :header="t('auth.changePassword.title')"
    class="w-full max-w-md"
    @update:visible="emit('update:visible', $event)"
    @show="reset"
  >
    <form
      class="flex flex-col gap-4"
      @submit.prevent="run"
    >
      <div class="flex flex-col gap-1.5">
        <label
          for="current-password"
          class="text-sm font-medium text-gray-700"
        >{{ t('auth.changePassword.current') }}</label>
        <Password
          v-model="current"
          input-id="current-password"
          :feedback="false"
          toggle-mask
          fluid
          autocomplete="current-password"
          autofocus
        />
      </div>
      <div class="flex flex-col gap-1.5">
        <label
          for="new-password"
          class="text-sm font-medium text-gray-700"
        >{{ t('auth.changePassword.new') }}</label>
        <Password
          v-model="next"
          input-id="new-password"
          :feedback="false"
          toggle-mask
          fluid
          autocomplete="new-password"
        />
        <small class="text-xs text-gray-500">{{ t('auth.changePassword.rule', { n: MIN_LENGTH }) }}</small>
      </div>
      <div class="flex flex-col gap-1.5">
        <label
          for="repeat-password"
          class="text-sm font-medium text-gray-700"
        >{{ t('auth.changePassword.repeat') }}</label>
        <Password
          v-model="repeat"
          input-id="repeat-password"
          :feedback="false"
          toggle-mask
          fluid
          autocomplete="new-password"
        />
      </div>

      <Message
        v-if="error"
        severity="error"
        size="small"
      >
        {{ error }}
      </Message>
      <Message
        v-if="done"
        severity="success"
        size="small"
      >
        {{ t('auth.changePassword.done') }}
      </Message>

      <div class="flex justify-end gap-2">
        <Button
          type="button"
          :label="t('common.cancel')"
          severity="secondary"
          text
          @click="emit('update:visible', false)"
        />
        <Button
          type="submit"
          :label="t('auth.changePassword.submit')"
          :loading="pending"
          :disabled="pending || done"
        />
      </div>
    </form>
  </Dialog>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import Dialog from 'primevue/dialog'
import Message from 'primevue/message'
import Password from 'primevue/password'
import { useApi } from '~/composables/useApi'
import { useAsyncAction } from '~/composables/useAsyncAction'
import { navigateTo } from '#app'
import { ApiError } from '~/types/api-errors'
import { changePassword } from '../api/auth'
import { useAuthStore } from '../stores/authStore'

/** Same rule as the backend (UserAccountManager::MIN_PASSWORD_LENGTH). */
const MIN_LENGTH = 10
/** The backend revokes every session on a password change: show the result, then sign in again. */
const SIGN_OUT_DELAY_MS = 2000

defineProps<{ visible: boolean }>()
const emit = defineEmits<{ 'update:visible': [value: boolean] }>()

const { t } = useI18n()
const current = ref('')
const next = ref('')
const repeat = ref('')
const error = ref<string | null>(null)
const done = ref(false)

function reset() {
  current.value = ''
  next.value = ''
  repeat.value = ''
  error.value = null
  done.value = false
}

function validate(): string | null {
  if (!current.value || !next.value) return t('auth.changePassword.required')
  if (next.value.length < MIN_LENGTH) return t('auth.changePassword.rule', { n: MIN_LENGTH })
  if (next.value !== repeat.value) return t('auth.changePassword.mismatch')
  return null
}

const { pending, run } = useAsyncAction(
  async () => {
    await changePassword(useApi(), current.value, next.value)
    done.value = true
    current.value = ''
    next.value = ''
    repeat.value = ''
    await new Promise(resolve => setTimeout(resolve, SIGN_OUT_DELAY_MS))
    await useAuthStore().logout()
    await navigateTo('/login')
  },
  {
    canRun: () => {
      error.value = validate()
      return error.value === null
    },
    onError: (e) => {
      // 422: wrong current password / weak new one; 429: too many attempts. Both carry a user-facing text.
      error.value = e instanceof ApiError && [422, 429].includes(e.statusCode) && e.message ? e.message : t('errors.unknown')
    },
    rethrow: false,
  },
)
</script>
