<template>
  <aside
    class="flex shrink-0 flex-col bg-rnp-sidebar text-rnp-sidebar-text duration-300"
    :class="mobile
      ? ['fixed inset-y-0 left-0 z-50 w-[280px] max-w-[85vw] shadow-2xl transition-transform', drawerOpen ? 'translate-x-0' : '-translate-x-full']
      : ['relative transition-[width]', collapsed ? 'w-[72px]' : 'w-[248px]']"
    :style="{ transitionTimingFunction: 'var(--rnp-ease-out)' }"
    :aria-hidden="mobile && !drawerOpen ? 'true' : undefined"
    :inert="mobile && !drawerOpen"
  >
    <!-- Logo -->
    <NuxtLink
      to="/cabinet"
      class="flex h-20 items-center overflow-hidden"
      :class="collapsed ? 'justify-center px-0' : 'px-6'"
      :aria-label="t('app.title')"
    >
      <span
        v-if="collapsed"
        class="flex h-10 w-10 items-center justify-center rounded-full bg-rnp-accent text-base font-bold text-rnp-text"
      >W</span>
      <img
        v-else
        src="/logo-light.svg"
        alt="Acme"
        class="h-8 w-auto"
      >
    </NuxtLink>

    <button
      v-if="mobile"
      type="button"
      class="absolute right-4 top-6 flex h-8 w-8 items-center justify-center rounded-full text-rnp-sidebar-text hover:bg-white/[0.08] hover:text-white"
      :aria-label="t('layout.closeMenu')"
      @click="closeDrawer"
    >
      <i
        class="pi pi-times text-sm"
        aria-hidden="true"
      />
    </button>
    <button
      v-else
      type="button"
      class="absolute -right-3.5 top-7 z-10 flex h-7 w-7 items-center justify-center rounded-full border border-rnp-divider bg-rnp-pill text-rnp-text shadow-sm transition-transform hover:scale-105"
      :aria-label="collapsed ? t('layout.expand') : t('layout.collapse')"
      @click="toggle"
    >
      <i
        class="pi text-[10px]"
        :class="collapsed ? 'pi-chevron-right' : 'pi-chevron-left'"
        aria-hidden="true"
      />
    </button>

    <!-- Main action -->
    <div
      class="mb-6 mt-2"
      :class="collapsed ? 'px-3' : 'px-5'"
    >
      <NuxtLink
        to="/rnp-week"
        class="group flex items-center gap-3 rounded-full bg-rnp-pill py-1.5 text-sm font-semibold text-rnp-text transition-colors hover:bg-rnp-accent-soft"
        :class="collapsed ? 'justify-center px-1.5' : 'pl-1.5 pr-5'"
        :aria-label="t('layout.enterFact')"
      >
        <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-rnp-accent text-rnp-text transition-transform group-hover:rotate-90">
          <i
            class="pi pi-plus text-xs"
            aria-hidden="true"
          />
        </span>
        <span
          v-if="!collapsed"
          class="truncate"
        >{{ t('layout.enterFact') }}</span>
      </NuxtLink>
    </div>

    <!-- Menu with one sliding active pill -->
    <nav
      ref="navEl"
      class="relative flex flex-1 flex-col gap-1 overflow-y-auto overflow-x-hidden pb-6"
      :class="collapsed ? 'px-3' : 'px-4'"
    >
      <span
        v-show="indicator.visible"
        class="pointer-events-none absolute left-0 top-0 rounded-full bg-rnp-accent transition-[transform,height,width] duration-300"
        :style="{
          transform: `translate(${indicator.left}px, ${indicator.top}px)`,
          width: `${indicator.width}px`,
          height: `${indicator.height}px`,
          transitionTimingFunction: 'var(--rnp-ease-out)',
        }"
        aria-hidden="true"
      />
      <template
        v-for="(item, index) in items"
        :key="item.to"
      >
        <p
          v-if="item.adminOnly && !items[index - 1]?.adminOnly"
          class="mb-1 mt-6 text-[11px] font-semibold uppercase tracking-wider text-[#77736b]"
          :class="collapsed ? 'text-center' : 'px-4'"
        >
          {{ collapsed ? '•••' : t('layout.admin') }}
        </p>
        <NuxtLink
          :ref="(el: Element | ComponentPublicInstance | null) => setItemRef(item.to, el)"
          :to="item.to"
          class="relative z-[1] flex h-11 items-center gap-3 rounded-full text-sm font-medium transition-colors duration-200"
          :class="[
            collapsed ? 'justify-center px-0' : 'px-4',
            activeTo === item.to ? 'text-rnp-text' : 'text-rnp-sidebar-text hover:bg-white/[0.06] hover:text-white',
          ]"
          :aria-current="activeTo === item.to ? 'page' : undefined"
        >
          <i
            :class="item.icon"
            class="text-base"
            aria-hidden="true"
          />
          <span
            v-if="!collapsed"
            class="truncate"
          >{{ t(item.label) }}</span>
          <span
            v-else
            class="sr-only"
          >{{ t(item.label) }}</span>
        </NuxtLink>
      </template>
    </nav>
  </aside>
</template>

<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import type { ComponentPublicInstance } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from '#app'
import { useAuthStore } from '~/features/auth/stores/authStore'
import { useSidebar } from '../composables/useSidebar'
import { MENU_ITEMS, menuItemFor } from '../config/menuItems'

const { t } = useI18n()
const route = useRoute()
const authStore = useAuthStore()
const { collapsed, toggle, mobile, drawerOpen, closeDrawer } = useSidebar()

const items = computed(() => MENU_ITEMS.filter(item => (!item.adminOnly || authStore.me?.isAdmin === true)
  && (!item.callUnitsOnly || (authStore.me?.callUnits?.length ?? 0) > 0)))
const activeTo = computed(() => menuItemFor(route.path)?.to ?? null)

const navEl = ref<HTMLElement | null>(null)
const itemEls = new Map<string, HTMLElement>()
const indicator = reactive({ visible: false, top: 0, left: 0, width: 0, height: 0 })

function setItemRef(to: string, el: Element | ComponentPublicInstance | null) {
  const node = el && '$el' in el ? (el.$el as HTMLElement) : (el as HTMLElement | null)
  if (node) itemEls.set(to, node)
  else itemEls.delete(to)
}

/** Moves the active pill under the active item (it slides, thanks to the transform transition). */
function placeIndicator() {
  const el = activeTo.value ? itemEls.get(activeTo.value) : undefined
  if (!el) {
    indicator.visible = false
    return
  }
  indicator.top = el.offsetTop
  indicator.left = el.offsetLeft
  indicator.width = el.offsetWidth
  indicator.height = el.offsetHeight
  indicator.visible = true
}

watch([activeTo, collapsed, items], () => nextTick(placeIndicator))
// The drawer closes after a navigation; the pill is measured once it is visible.
watch(() => route.fullPath, closeDrawer)
watch(drawerOpen, open => open && setTimeout(placeIndicator, 50))
// The width animates: re-measure when the sidebar has finished resizing.
watch(collapsed, () => setTimeout(placeIndicator, 320))

let observer: ResizeObserver | null = null
onMounted(() => {
  void nextTick(placeIndicator)
  if (navEl.value && typeof ResizeObserver !== 'undefined') {
    observer = new ResizeObserver(() => placeIndicator())
    observer.observe(navEl.value)
  }
})
onBeforeUnmount(() => observer?.disconnect())
</script>
