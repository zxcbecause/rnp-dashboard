<template>
  <form
    class="flex flex-col gap-5"
    novalidate
    @submit.prevent="onSubmit"
  >
    <div class="flex flex-col gap-1">
      <label
        for="email"
        class="text-sm font-medium text-rnp-text"
      >{{ t('auth.email') }}</label>
      <InputText
        id="email"
        v-model="email"
        type="email"
        autocomplete="username"
        :invalid="submitted && !email"
        fluid
      />
    </div>

    <div class="flex flex-col gap-1">
      <label
        for="password"
        class="text-sm font-medium text-rnp-text"
      >{{ t('auth.password') }}</label>
      <Password
        v-model="password"
        input-id="password"
        :feedback="false"
        toggle-mask
        autocomplete="current-password"
        :invalid="submitted && !password"
        fluid
      />
    </div>

    <Message
      v-if="errorMessage"
      severity="error"
      size="small"
    >
      {{ errorMessage }}
    </Message>

    <Button
      type="submit"
      :label="t('auth.signIn')"
      :loading="pending"
      :disabled="pending"
      fluid
    />
  </form>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import Message from 'primevue/message'
import Password from 'primevue/password'
import { useLogin } from '../composables/useLogin'

const { t } = useI18n()
const { pending, errorMessage, submit } = useLogin()

const email = ref('')
const password = ref('')
const submitted = ref(false)

function onSubmit() {
  submitted.value = true
  if (!email.value || !password.value) return
  void submit({ email: email.value.trim(), password: password.value })
}
</script>
