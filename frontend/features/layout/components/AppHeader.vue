<template>
  <header class="mx-4 flex h-16 shrink-0 items-center gap-2 border-b border-rnp-divider md:mx-6 md:h-20 md:gap-4 lg:mx-8">
    <button
      v-if="mobile"
      type="button"
      class="-ml-1 flex h-10 w-10 shrink-0 items-center justify-center rounded-full text-rnp-text transition-colors hover:bg-rnp-pill"
      :aria-label="t('layout.openMenu')"
      :aria-expanded="drawerOpen"
      @click="openDrawer"
    >
      <i
        class="pi pi-bars text-lg"
        aria-hidden="true"
      />
    </button>
    <h1 class="min-w-0 flex-1 truncate text-xl font-semibold tracking-tight text-rnp-text md:text-[28px]">
      {{ title }}
    </h1>

    <HeaderSearch class="hidden md:block" />

    <NotificationBell />

    <UserPill />
  </header>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from '#app'
import NotificationBell from '~/features/notifications/components/NotificationBell.vue'
import { useSidebar } from '../composables/useSidebar'
import { menuItemFor } from '../config/menuItems'
import HeaderSearch from './HeaderSearch.vue'
import UserPill from './UserPill.vue'

const { t } = useI18n()
const route = useRoute()
const { mobile, drawerOpen, openDrawer } = useSidebar()

/** The section title lives in the header (the reference's big "Dashboard"); pages show their own subtitle. */
const title = computed(() => {
  const item = menuItemFor(route.path)
  return item ? t(item.label) : t('app.title')
})
</script>
