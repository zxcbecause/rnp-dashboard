<template>
  <div v-if="authStore.me">
    <button
      type="button"
      class="flex h-11 items-center gap-3 rounded-full bg-rnp-pill py-1 pl-1 pr-4 text-left transition-colors hover:bg-rnp-accent-soft"
      aria-haspopup="true"
      :aria-label="t('layout.userMenu')"
      @click="menu?.toggle($event)"
    >
      <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-rnp-accent text-sm font-bold text-rnp-text">
        {{ initials }}
      </span>
      <span class="hidden min-w-0 flex-col leading-tight md:flex">
        <span class="truncate text-sm font-semibold text-rnp-text">{{ authStore.me.name }}</span>
        <span class="truncate text-xs text-rnp-muted">{{ roleLine }}</span>
      </span>
      <i
        class="pi pi-chevron-down hidden text-xs text-rnp-muted md:block"
        aria-hidden="true"
      />
    </button>

    <Menu
      ref="menu"
      :model="items"
      popup
    />
    <ChangePasswordDialog v-model:visible="passwordDialog" />
  </div>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import Menu from 'primevue/menu'
import { navigateTo } from '#app'
import ChangePasswordDialog from '~/features/auth/components/ChangePasswordDialog.vue'
import { useAuthStore } from '~/features/auth/stores/authStore'

const { t, locale, setLocale } = useI18n() as ReturnType<typeof useI18n> & { setLocale: (code: string) => Promise<void> }
const authStore = useAuthStore()
const menu = ref<InstanceType<typeof Menu> | null>(null)
const passwordDialog = ref(false)

/** Avatar: initials of the name (no photos). */
const initials = computed(() => {
  const words = (authStore.me?.name ?? '').trim().split(/\s+/).filter(Boolean)
  return (words.length > 1 ? words[0]![0]! + words[1]![0]! : (words[0] ?? '?').slice(0, 2)).toUpperCase()
})

/** "Руководитель · Revenue", or "Администратор". */
const roleLine = computed(() => {
  const me = authStore.me
  if (!me) return ''
  if (me.isAdmin) return t('roles.admin')
  const main = me.memberships.find(m => m.role === 'head') ?? me.memberships[0]
  return main ? `${t(`roles.${main.role}`)} · ${main.orgUnitName}` : ''
})

const isRussian = computed(() => String(locale.value).startsWith('ru'))

const items = computed(() => [
  { label: t('auth.changePassword.title'), icon: 'pi pi-key', command: () => { passwordDialog.value = true } },
  {
    label: isRussian.value ? 'English' : 'Русский',
    icon: 'pi pi-globe',
    command: () => void setLocale(isRussian.value ? 'en' : 'ru'),
  },
  { separator: true },
  {
    label: t('auth.signOut'),
    icon: 'pi pi-sign-out',
    command: async () => {
      await authStore.logout()
      await navigateTo('/login')
    },
  },
])
</script>
