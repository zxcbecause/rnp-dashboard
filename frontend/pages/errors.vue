<template>
  <section class="flex flex-col gap-6">
    <UiPageHeader :subtitle="t('errorLog.subtitle')">
      <OrgUnitSelect
        :model-value="unitId"
        :tree="orgStore.tree"
        :loading="orgStore.loading"
        @update:model-value="go({ unit: $event })"
      />
      <!-- Period as one white pill with two native date inputs -->
      <div class="flex h-9 items-center gap-1 rounded-full bg-rnp-pill px-3 text-sm text-rnp-text">
        <i
          class="pi pi-calendar mr-1 text-xs text-rnp-muted"
          aria-hidden="true"
        />
        <input
          :value="log?.from ?? ''"
          type="date"
          class="rnp-date-input"
          :aria-label="t('errorLog.from')"
          @change="go({ from: ($event.target as HTMLInputElement).value })"
        >
        <span class="text-rnp-muted">—</span>
        <input
          :value="log?.to ?? ''"
          type="date"
          class="rnp-date-input"
          :aria-label="t('errorLog.to')"
          @change="go({ to: ($event.target as HTMLInputElement).value })"
        >
      </div>
      <UiPill
        v-if="log?.canReport"
        icon="pi pi-plus"
        :label="t('errorLog.create')"
        active
        @click="open(null)"
      />
    </UiPageHeader>

    <Message
      v-if="error"
      :severity="error === 'not_found' ? 'warn' : 'error'"
    >
      {{ error === 'not_found' ? t('rnp.unitNotAvailable') : error }}
    </Message>

    <!-- First load: skeletons in the shape of the page -->
    <div
      v-if="!log && loading"
      class="flex flex-col gap-6"
    >
      <div class="grid grid-cols-2 gap-4 xl:grid-cols-4">
        <UiSkeleton
          v-for="n in 4"
          :key="n"
          class="h-44"
          rounded="rounded-[20px]"
        />
      </div>
      <UiSkeleton
        class="h-80"
        rounded="rounded-[20px]"
      />
    </div>

    <Transition
      name="rnp-fade"
      mode="out-in"
    >
      <div
        v-if="log"
        :key="`${log.orgUnitId}-${log.from}-${log.to}`"
        class="flex flex-col gap-6 transition-opacity"
        :class="loading ? 'opacity-60' : ''"
      >
        <div class="grid grid-cols-2 gap-4 xl:grid-cols-4">
          <UiKpiCard
            :class="rise"
            :style="{ '--i': 0 }"
            :label="t('errorLog.total')"
            icon="pi pi-list"
            color="var(--rnp-kpi-lilac)"
            :value="String(log.total)"
            :format="formatCount"
            :no-trend-text="periodText"
          />
          <UiKpiCard
            :class="rise"
            :style="{ '--i': 1 }"
            :label="t('errorLog.openCount')"
            icon="pi pi-exclamation-circle"
            color="var(--rnp-kpi-orange)"
            :value="String(log.open)"
            :format="formatCount"
            :no-trend-text="log.open ? t('errorLog.openHint') : t('errorLog.allClosed')"
          />
          <UiKpiCard
            :class="rise"
            :style="{ '--i': 2 }"
            :label="t('errorLog.moneyTotal')"
            icon="pi pi-euro"
            color="var(--rnp-kpi-yellow)"
            :value="log.moneyTotal"
            :format="raw => formatMetricValue('eur', raw)"
            :no-trend-text="t('errorLog.moneyHint', { n: log.byImpact.money })"
          />
          <UiCard
            :class="rise"
            :style="{ '--i': 3 }"
          >
            <p class="mb-3 text-sm text-rnp-muted">
              {{ t('errorLog.byImpact') }}
            </p>
            <ul class="flex flex-col gap-2">
              <li
                v-for="impact in IMPACTS"
                :key="impact"
                class="flex items-center justify-between gap-3 text-sm"
              >
                <span class="flex items-center gap-2 text-rnp-text">
                  <span
                    class="h-2.5 w-2.5 rounded-full"
                    :style="{ backgroundColor: IMPACT_COLORS[impact].fg }"
                    aria-hidden="true"
                  />
                  {{ t(`errorLog.impact.${impact}`) }}
                </span>
                <span class="font-bold tabular-nums text-rnp-text">{{ log.byImpact[impact] }}</span>
              </li>
            </ul>
          </UiCard>
        </div>

        <UiCard
          :title="t('errorLog.entries')"
          :class="rise"
          :style="{ '--i': 4 }"
        >
          <ul v-if="log.entries.length">
            <li
              v-for="entry in log.entries"
              :key="entry.id"
              class="relative border-b border-rnp-divider/70 py-4 pl-4 first:pt-0 last:border-0 last:pb-0"
            >
              <span
                class="absolute bottom-4 left-0 top-0 w-1 rounded-full"
                :style="{ backgroundColor: IMPACT_COLORS[entry.impactType].fg }"
                aria-hidden="true"
              />
              <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0 flex-1">
                  <div class="flex flex-wrap items-center gap-2 text-xs text-rnp-muted">
                    <span class="font-medium">{{ formatDate(entry.occurredOn) }}</span>
                    <span
                      v-if="entry.orgUnitId !== log.orgUnitId"
                      class="inline-flex h-6 items-center gap-1 rounded-full bg-rnp-bg px-2.5 font-semibold text-rnp-text"
                    ><i
                      class="pi pi-sitemap text-[10px]"
                      aria-hidden="true"
                    />{{ entry.orgUnitName }}</span>
                    <span
                      class="inline-flex h-6 items-center rounded-full px-2.5 font-semibold"
                      :style="{ color: IMPACT_COLORS[entry.impactType].fg, backgroundColor: IMPACT_COLORS[entry.impactType].bg }"
                    >{{ t(`errorLog.impact.${entry.impactType}`) }}</span>
                    <span
                      v-if="entry.impactAmount"
                      class="font-bold text-rnp-red"
                    >{{ formatMetricValue('eur', entry.impactAmount) }}</span>
                    <UiStatusBadge
                      :status="STATUS_TONE[entry.status]"
                      :label="t(`errorLog.statuses.${entry.status}`)"
                    />
                  </div>
                  <p class="mt-2 font-medium text-rnp-text">
                    {{ entry.description }}
                  </p>
                  <dl
                    v-if="entry.cause || entry.prevention"
                    class="mt-2 grid gap-x-4 gap-y-1 text-sm sm:grid-cols-[9rem_1fr]"
                  >
                    <template v-if="entry.cause">
                      <dt class="text-rnp-muted">
                        {{ t('errorLog.cause') }}
                      </dt>
                      <dd class="text-rnp-text/80">
                        {{ entry.cause }}
                      </dd>
                    </template>
                    <template v-if="entry.prevention">
                      <dt class="text-rnp-muted">
                        {{ t('errorLog.prevention') }}
                      </dt>
                      <dd class="text-rnp-text/80">
                        {{ entry.prevention }}
                      </dd>
                    </template>
                  </dl>
                  <div class="mt-2 text-xs text-rnp-muted">
                    <span v-if="entry.reporter">{{ t('errorLog.reportedBy', { name: entry.reporter.name }) }}</span>
                    <span v-if="entry.owner"> · {{ t('errorLog.ownedBy', { name: entry.owner.name }) }}</span>
                  </div>
                </div>
                <Button
                  v-if="entry.canEdit"
                  icon="pi pi-pencil"
                  text
                  rounded
                  size="small"
                  severity="secondary"
                  :aria-label="t('errorLog.edit')"
                  @click="open(entry)"
                />
              </div>
            </li>
          </ul>
          <p
            v-else
            class="py-8 text-center text-sm text-rnp-muted"
          >
            {{ t('errorLog.empty') }}
          </p>
        </UiCard>
      </div>
    </Transition>

    <ErrorEntryDialog
      v-if="log"
      v-model:visible="dialog"
      :org-unit-id="log.orgUnitId"
      :entry="editing"
      :people="log.people"
      @saved="reload"
    />
  </section>
</template>

<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import Message from 'primevue/message'
import { navigateTo, useRoute } from '#app'
import { useApi } from '~/composables/useApi'
import { createLatestRequestScope } from '~/composables/useLatestRequest'
import { useAuthStore } from '~/features/auth/stores/authStore'
import { fetchErrorLog } from '~/features/errors/api/errors'
import ErrorEntryDialog from '~/features/errors/components/ErrorEntryDialog.vue'
import type { ErrorEntry, ErrorLog, ErrorStatus, ImpactType } from '~/features/errors/types'
import OrgUnitSelect from '~/features/org/components/OrgUnitSelect.vue'
import { useOrgStore } from '~/features/org/stores/orgStore'
import UiCard from '~/features/ui/components/UiCard.vue'
import UiKpiCard from '~/features/ui/components/UiKpiCard.vue'
import UiPageHeader from '~/features/ui/components/UiPageHeader.vue'
import UiPill from '~/features/ui/components/UiPill.vue'
import UiSkeleton from '~/features/ui/components/UiSkeleton.vue'
import UiStatusBadge from '~/features/ui/components/UiStatusBadge.vue'
import { STATUS_COLORS } from '~/features/ui/status'
import type { UiStatus } from '~/features/ui/status'
import { NotFoundError } from '~/types/api-errors'
import { formatMetricValue } from '~/utils/metricFormat'

const IMPACTS: ImpactType[] = ['money', 'blocked', 'client', 'other']

/** Impact colours: money red, blocked orange, client yellow, other grey. */
const IMPACT_COLORS: Record<ImpactType, { fg: string, bg: string }> = {
  money: STATUS_COLORS.missed,
  blocked: { fg: 'var(--rnp-kpi-orange)', bg: '#fdeedf' },
  client: STATUS_COLORS.risk,
  other: STATUS_COLORS.none,
}

const STATUS_TONE: Record<ErrorStatus, UiStatus> = { open: 'missed', in_progress: 'risk', resolved: 'done' }

const { t } = useI18n()
const route = useRoute()
const authStore = useAuthStore()
const orgStore = useOrgStore()

const log = ref<ErrorLog | null>(null)
const loading = ref(false)
const error = ref<string | null>(null)
const dialog = ref(false)
const editing = ref<ErrorEntry | null>(null)
const scope = createLatestRequestScope()

const param = (name: string) => (typeof route.query[name] === 'string' ? route.query[name] as string : null)
const unitId = computed<number | null>(() => {
  const fromQuery = Number(route.query.unit)
  if (Number.isInteger(fromQuery) && fromQuery > 0) return fromQuery
  return authStore.me?.rootUnitIds[0] ?? orgStore.tree[0]?.id ?? null
})

function go(change: { unit?: number, from?: string, to?: string }) {
  const query = { unit: unitId.value ? String(unitId.value) : undefined, from: param('from') ?? undefined, to: param('to') ?? undefined }
  if (change.unit) query.unit = String(change.unit)
  if (change.from !== undefined) query.from = change.from || undefined
  if (change.to !== undefined) query.to = change.to || undefined
  void navigateTo({ path: '/errors', query: Object.fromEntries(Object.entries(query).filter(([, v]) => v)) })
}

const periodText = computed(() => (log.value ? `${formatDate(log.value.from)} – ${formatDate(log.value.to)}` : ''))

/** The cascade plays once per visit; later reloads only crossfade. */
const played = ref(false)
const rise = computed(() => (played.value ? '' : 'rnp-rise'))
watch(log, (value) => {
  if (value && !played.value) setTimeout(() => (played.value = true), 900)
})

function formatCount(raw: string | null): string {
  return raw === null ? '' : String(Math.round(Number(raw)))
}

function formatDate(date: string): string {
  const [y, m, d] = date.split('-')
  return `${d}.${m}.${y}`
}

async function load() {
  if (!unitId.value) return
  const isLatest = scope.begin()
  loading.value = true
  error.value = null
  try {
    const data = await fetchErrorLog(useApi(), unitId.value, param('from'), param('to'))
    if (!isLatest()) return
    log.value = data
  }
  catch (e) {
    if (!isLatest()) return
    log.value = null
    error.value = e instanceof NotFoundError ? 'not_found' : (e instanceof Error ? e.message : String(e))
  }
  finally {
    if (isLatest()) loading.value = false
  }
}

const reload = () => load()

function open(entry: ErrorEntry | null) {
  editing.value = entry
  dialog.value = true
}

watch([unitId, () => route.query.from, () => route.query.to], () => void load(), { immediate: true })

onMounted(() => {
  if (!orgStore.units.length) void orgStore.load()
})
</script>
