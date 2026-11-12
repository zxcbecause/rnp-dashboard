<template>
  <div class="relative shrink-0">
    <button
      type="button"
      class="flex h-11 w-11 items-center justify-center rounded-full bg-rnp-pill text-rnp-text transition-colors hover:bg-rnp-accent-soft"
      :aria-label="badge ? t('notifications.unread', { n: badge }) : t('layout.notifications')"
      @click="toggle"
    >
      <i
        class="pi pi-bell"
        aria-hidden="true"
      />
    </button>
    <span
      v-if="badge"
      class="pointer-events-none absolute -right-0.5 -top-0.5 flex h-5 min-w-5 items-center justify-center rounded-full bg-rnp-red px-1 text-[11px] font-bold text-white"
      aria-hidden="true"
    >{{ badge > 9 ? '9+' : badge }}</span>

    <Popover ref="panel">
      <div class="w-80 max-w-[calc(100vw-2rem)]">
        <p class="mb-2 text-sm font-semibold text-rnp-text">
          {{ t('layout.notifications') }}
        </p>
        <ul v-if="items.length">
          <li
            v-for="(item, index) in items"
            :key="`${item.type}-${item.orgUnitId}-${index}`"
            class="border-b border-rnp-divider/70 last:border-0"
          >
            <NuxtLink
              :to="notificationLink(item)"
              class="flex gap-3 rounded-xl px-2 py-2.5 text-sm transition-colors hover:bg-rnp-bg"
              @click="panel?.hide()"
            >
              <i
                class="pi mt-0.5"
                :class="icon(item)"
                aria-hidden="true"
              />
              <span class="min-w-0 flex-1">
                <span class="block text-rnp-text">{{ text(item) }}</span>
                <span
                  v-if="item.orgUnitName"
                  class="block text-xs text-rnp-muted"
                >{{ item.orgUnitName }}</span>
              </span>
            </NuxtLink>
            <div
              v-if="anomalyTargets(item, today).length"
              class="flex flex-wrap gap-1.5 pb-2.5 pl-9 pr-2"
            >
              <button
                v-for="target in anomalyTargets(item, today)"
                :key="target.metricId"
                type="button"
                class="inline-flex max-w-full items-center gap-1 rounded-full bg-rnp-pill px-2.5 py-1 text-xs font-semibold text-rnp-text transition-colors hover:bg-rnp-accent-soft"
                @click="openWhy(target)"
              >
                <i
                  class="pi pi-sparkles text-[11px] text-rnp-accent-hover"
                  aria-hidden="true"
                />
                <span class="truncate">{{ t('ai.anomaly.why', { name: target.metricName }) }}</span>
              </button>
            </div>
          </li>
        </ul>
        <p
          v-else
          class="py-4 text-center text-sm text-rnp-muted"
        >
          {{ t('notifications.empty') }}
        </p>
      </div>
    </Popover>
    <AiAnomalyDialog
      v-model:visible="whyVisible"
      :target="whyTarget"
    />
  </div>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import Popover from 'primevue/popover'
import { useRoute } from '#app'
import { useApi } from '~/composables/useApi'
import { createLatestRequestScope } from '~/composables/useLatestRequest'
import AiAnomalyDialog from '~/features/ai/components/AiAnomalyDialog.vue'
import type { AnomalyTarget } from '~/features/ai/types'
import { formatCallTime } from '~/features/calls/utils/format'
import type { AppNotification } from '../types'
import { anomalyTargets, notificationLink, shortDate, unreadCount } from '../utils/unread'

const REFRESH_MS = 5 * 60 * 1000
/** Navigation refreshes the bell at most this often: alerts are computed from whole RNP weeks. */
const NAV_REFRESH_MS = 30 * 1000
const SEEN_KEY = 'rnp-notifications-seen'

const { t } = useI18n()
const route = useRoute()
const panel = ref<InstanceType<typeof Popover> | null>(null)
const items = ref<AppNotification[]>([])
const lastSeen = ref<string | null>(readSeen())
const scope = createLatestRequestScope()
let timer: ReturnType<typeof setInterval> | undefined
let loadedAt = 0
const whyVisible = ref(false)
const whyTarget = ref<AnomalyTarget | null>(null)
/** Local Y-m-d. */
function localDate(): string {
  const now = new Date()
  return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`
}
/** The month forecast is explained as of today; refreshed with every load (a tab may stay open overnight). */
const today = ref(localDate())

function openWhy(target: AnomalyTarget) {
  panel.value?.hide()
  whyTarget.value = target
  whyVisible.value = true
}

const badge = computed(() => unreadCount(items.value, lastSeen.value))

async function load() {
  loadedAt = Date.now()
  today.value = localDate()
  const isLatest = scope.begin()
  try {
    const result = await useApi()<AppNotification[]>('/api/notifications')
    if (isLatest()) items.value = result
  }
  catch {
    // The bell is secondary: a failed refresh keeps the previous list.
  }
}

function toggle(event: Event) {
  panel.value?.toggle(event)
  lastSeen.value = new Date().toISOString()
  try {
    localStorage.setItem(SEEN_KEY, lastSeen.value)
  }
  catch {
    // Storage may be unavailable (private mode): the badge then resets on reload only.
  }
}

function readSeen(): string | null {
  try {
    return localStorage.getItem(SEEN_KEY)
  }
  catch {
    return null
  }
}

function icon(item: AppNotification): string {
  switch (item.type) {
    case 'call_exclusions_pending': return 'pi-phone text-rnp-yellow'
    case 'call_exclusion_decided': return item.status === 'approved' ? 'pi-check-circle text-rnp-green' : 'pi-times-circle text-rnp-red'
    case 'metric_red': return 'pi-chart-line text-rnp-red'
    case 'run_rate_behind': return 'pi-chart-bar text-rnp-yellow'
    case 'facts_missing': return 'pi-pencil text-rnp-yellow'
    case 'actions_missed': return 'pi-calendar-times text-rnp-red'
    case 'errors_new': return 'pi-exclamation-triangle text-rnp-red'
    case 'data_stale': return item.status === 'stale' ? 'pi-sync text-rnp-red' : 'pi-sync text-rnp-yellow'
  }
}

/** "A, B, C и ещё 2" */
function metricList(item: AppNotification): string {
  const names = item.metrics ?? []
  const more = (item.count ?? names.length) - names.length
  return more > 0 ? t('notifications.andMore', { list: names.join(', '), n: more }) : names.join(', ')
}

function text(item: AppNotification): string {
  switch (item.type) {
    case 'call_exclusions_pending':
      return t('notifications.pendingCalls', { n: item.count ?? 0 })
    case 'call_exclusion_decided':
      return t(item.status === 'approved' ? 'notifications.approved' : 'notifications.rejected', {
        at: formatCallTime(item.callStartedAt ?? ''),
        name: item.decidedBy ?? '—',
      })
    case 'metric_red':
      return t('notifications.metricRed', { week: shortDate(item.week), list: metricList(item) })
    case 'run_rate_behind':
      return t('notifications.runRateBehind', { list: metricList(item) })
    case 'facts_missing':
      return t('notifications.factsMissing', { week: shortDate(item.week), list: metricList(item) })
    case 'actions_missed':
      return t('notifications.actionsMissed', { n: item.count ?? 0 })
    case 'errors_new':
      return t('notifications.errorsNew', { n: item.count ?? 0 })
    case 'data_stale':
      return t(item.status === 'stale' ? 'notifications.dataStale' : 'notifications.dataFailing', {
        source: t(item.source === 'bitrix' ? 'notifications.sourceBitrix' : 'notifications.sourcePlatform'),
      })
  }
}

// Decisions and entered facts show up on the next navigation, without waiting for the 5-minute refresh.
watch(() => route.fullPath, () => {
  if (Date.now() - loadedAt >= NAV_REFRESH_MS) void load()
})

onMounted(() => {
  void load()
  timer = setInterval(() => void load(), REFRESH_MS)
})
onBeforeUnmount(() => clearInterval(timer))
</script>
