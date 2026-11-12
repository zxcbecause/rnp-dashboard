import { computed, onBeforeUnmount, onMounted, ref } from 'vue'

const STORAGE_KEY = 'rnp.sidebar.collapsed'
/** Below this width the sidebar always shows icons only. */
const NARROW_QUERY = '(max-width: 1023px)'
/** Phones: no rail at all, the menu is a drawer opened from the header. */
const MOBILE_QUERY = '(max-width: 767px)'

const matches = (query: string) => typeof window !== 'undefined' && window.matchMedia(query).matches

// Module state: one sidebar per app, shared by the sidebar, the header and the layout. Read synchronously
// (the app is a SPA), so a phone never flashes the desktop rail on load.
const userCollapsed = ref(false)
const narrow = ref(matches(NARROW_QUERY))
const mobile = ref(matches(MOBILE_QUERY))
const drawerOpen = ref(false)
let initialized = false

function readStored(): boolean {
  try {
    return localStorage.getItem(STORAGE_KEY) === '1'
  }
  catch {
    return false
  }
}

/**
 * Sidebar state: collapsed (the user's choice, remembered in localStorage, forced on narrow screens) and,
 * on phones, a drawer that is open or closed.
 */
export function useSidebar() {
  const listeners: [MediaQueryList, (e: MediaQueryListEvent) => void][] = []

  onMounted(() => {
    if (!initialized) {
      userCollapsed.value = readStored()
      initialized = true
    }
    for (const [query, target] of [[NARROW_QUERY, narrow], [MOBILE_QUERY, mobile]] as const) {
      const media = window.matchMedia(query)
      target.value = media.matches
      const listener = (e: MediaQueryListEvent) => {
        target.value = e.matches
        if (!mobile.value) drawerOpen.value = false
      }
      media.addEventListener('change', listener)
      listeners.push([media, listener])
    }
  })

  onBeforeUnmount(() => listeners.forEach(([media, listener]) => media.removeEventListener('change', listener)))

  /** The drawer always shows full labels. */
  const collapsed = computed(() => !mobile.value && (narrow.value || userCollapsed.value))

  function toggle() {
    userCollapsed.value = !collapsed.value
    narrow.value = false
    try {
      localStorage.setItem(STORAGE_KEY, userCollapsed.value ? '1' : '0')
    }
    catch {
      // Storage may be unavailable (private mode): the choice lasts for this tab only.
    }
  }

  const openDrawer = () => {
    drawerOpen.value = true
  }
  const closeDrawer = () => {
    drawerOpen.value = false
  }

  return { collapsed, toggle, mobile, drawerOpen, openDrawer, closeDrawer }
}
