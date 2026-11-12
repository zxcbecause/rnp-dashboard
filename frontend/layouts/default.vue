<template>
  <div class="flex h-screen bg-rnp-bg text-rnp-text">
    <AppSidebar />
    <Transition name="rnp-fade">
      <div
        v-if="mobile && drawerOpen"
        class="fixed inset-0 z-40 bg-black/40"
        aria-hidden="true"
        @click="closeDrawer"
      />
    </Transition>
    <div class="flex min-w-0 flex-1 flex-col overflow-hidden">
      <AppHeader />
      <!-- Only this area animates on navigation: sidebar and header stay put. -->
      <!-- Bottom padding keeps the floating "Ask AI" button off the last card. -->
      <main class="flex-1 overflow-y-auto overflow-x-hidden px-4 pb-24 pt-4 md:px-6 md:pt-6 lg:px-8">
        <slot />
      </main>
    </div>
    <AiAssistant />
  </div>
</template>

<script setup lang="ts">
import { onBeforeUnmount, onMounted } from 'vue'
import AiAssistant from '~/features/ai/components/AiAssistant.vue'
import AppHeader from '~/features/layout/components/AppHeader.vue'
import AppSidebar from '~/features/layout/components/AppSidebar.vue'
import { useSidebar } from '~/features/layout/composables/useSidebar'

const { mobile, drawerOpen, closeDrawer } = useSidebar()

// Esc closes the menu drawer on phones.
const onKey = (e: KeyboardEvent) => {
  if (e.key === 'Escape' && drawerOpen.value) closeDrawer()
}
onMounted(() => window.addEventListener('keydown', onKey))
onBeforeUnmount(() => window.removeEventListener('keydown', onKey))
</script>
