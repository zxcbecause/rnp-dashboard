export interface MenuItem {
  to: string
  /** i18n key */
  label: string
  icon: string
  /** Shown to global administrators only */
  adminOnly?: boolean
  /** Shown only to users who can see a unit with missed calls */
  callUnitsOnly?: boolean
}

export const MENU_ITEMS: MenuItem[] = [
  { to: '/cabinet', label: 'nav.cabinet', icon: 'pi pi-home' },
  { to: '/rnp-week', label: 'nav.rnpWeek', icon: 'pi pi-table' },
  { to: '/metrics', label: 'nav.metrics', icon: 'pi pi-chart-line' },
  { to: '/actions', label: 'nav.actions', icon: 'pi pi-check-square' },
  { to: '/sprints', label: 'nav.sprints', icon: 'pi pi-list-check' },
  { to: '/errors', label: 'nav.errors', icon: 'pi pi-exclamation-triangle' },
  { to: '/calls', label: 'nav.calls', icon: 'pi pi-phone', callUnitsOnly: true },
  { to: '/help', label: 'nav.help', icon: 'pi pi-question-circle' },
  { to: '/admin/users', label: 'nav.users', icon: 'pi pi-users', adminOnly: true },
  { to: '/admin/org', label: 'nav.org', icon: 'pi pi-sitemap', adminOnly: true },
  { to: '/admin/tokens', label: 'nav.tokens', icon: 'pi pi-key', adminOnly: true },
  { to: '/admin/sync', label: 'nav.sync', icon: 'pi pi-sync', adminOnly: true },
]

/** The menu item a path belongs to ("/metrics/12" → Метрики), or null. */
export function menuItemFor(path: string): MenuItem | null {
  return MENU_ITEMS
    .filter(item => path === item.to || path.startsWith(`${item.to}/`))
    .sort((a, b) => b.to.length - a.to.length)[0] ?? null
}
