<template>
  <section class="flex flex-col gap-6">
    <UiPageHeader
      :title="t('cabinet.overview')"
      :subtitle="subtitle"
    >
      <OrgUnitSelect
        :model-value="unitId"
        :tree="orgStore.tree"
        :loading="orgStore.loading"
        @update:model-value="go({ unit: $event, week: weekParam })"
      />
      <WeekNavigator
        :week="current"
        :loading="loading"
        @change="go({ unit: unitId, week: $event ?? isoDate() })"
      />
    </UiPageHeader>

    <Message
      v-if="error"
      :severity="error === 'not_found' ? 'warn' : 'error'"
    >
      {{ error === 'not_found' ? t('rnp.unitNotAvailable') : error }}
    </Message>
    <p
      v-else-if="!unitId && !orgStore.loading"
      class="text-sm text-rnp-muted"
    >
      {{ t('cabinet.noUnits') }}
    </p>

    <!-- First load: skeletons in the shape of the page -->
    <div
      v-if="!current && loading"
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
      <div class="grid gap-4 lg:grid-cols-3">
        <UiSkeleton
          class="h-96 lg:col-span-2"
          rounded="rounded-[20px]"
        />
        <UiSkeleton
          class="h-96"
          rounded="rounded-[20px]"
        />
      </div>
    </div>

    <Transition
      name="rnp-fade"
      mode="out-in"
    >
      <div
        v-if="current"
        :key="`${current.orgUnit.id}-${current.weekStart}`"
        class="flex flex-col gap-6"
      >
        <!-- KPI: the head's main metrics -->
        <div
          v-if="kpis.length"
          class="grid grid-cols-2 gap-4 xl:grid-cols-4"
        >
          <UiKpiCard
            v-for="(row, index) in kpis"
            :key="row.metricId"
            :class="rise"
            :style="{ '--i': index }"
            :label="row.name"
            :icon="KPI_ICONS[index % KPI_ICONS.length]!"
            :color="KPI_COLORS[index % KPI_COLORS.length]!"
            :value="mainCell(row).fact"
            :format="raw => formatMetricValue(row.unit, raw)"
            :suffix="mainCell(row).plan !== null ? formatMetricValue(row.unit, mainCell(row).plan) : null"
            :trend="kpiTrend(row)"
            :no-trend-text="row.week ? t('cabinet.noTrend') : t('rnp.monthlyOnly')"
            class="cursor-pointer"
            @click="openMetric(row.metricId)"
          />
        </div>

        <!-- AI review of the shown week -->
        <AiWeeklyReviewCard
          :class="rise"
          :style="{ '--i': 4 }"
          :org-unit-id="current.orgUnit.id"
          :week="current.weekStart"
        />

        <div class="grid gap-4 lg:grid-cols-3">
          <!-- Branch metrics -->
          <UiCard
            :title="t('cabinet.branchMetrics')"
            class="min-w-0 lg:col-span-2"
            :class="rise"
            :style="{ '--i': 4 }"
          >
            <template #actions>
              <UiPillSelect
                v-model="ownerFilter"
                :options="ownerOptions"
                :placeholder="t('cabinet.owner')"
                :all-label="t('cabinet.allOwners')"
              />
              <UiPillSelect
                v-model="statusFilter"
                :options="statusOptions"
                :placeholder="t('cabinet.status')"
                :all-label="t('cabinet.allStatuses')"
              />
            </template>

            <!-- Phones: metric, fact / plan and the ring (its colour is the status); owner and status columns hide. -->
            <div class="-mx-1 overflow-x-auto">
              <table class="w-full text-sm md:min-w-[560px]">
                <thead>
                  <tr class="border-b border-rnp-divider text-left text-rnp-text">
                    <th class="px-1 pb-3 font-semibold">
                      {{ t('rnp.metric') }}
                    </th>
                    <th class="px-2 pb-3 font-semibold max-md:hidden">
                      {{ t('cabinet.owner') }}
                    </th>
                    <th class="px-2 pb-3 text-right font-semibold">
                      {{ t('cabinet.factPlan') }}
                    </th>
                    <th class="px-2 pb-3 font-semibold max-md:hidden">
                      {{ t('cabinet.status') }}
                    </th>
                    <th class="px-1 pb-3 text-center font-semibold">
                      {{ t('cabinet.progress') }}
                    </th>
                  </tr>
                </thead>
                <tbody>
                  <tr
                    v-for="(row, index) in filteredRows"
                    :key="row.metricId"
                    class="cursor-pointer border-b border-rnp-divider/70 transition-colors last:border-0 hover:bg-rnp-accent-soft/60"
                    :class="rise"
                    :style="{ '--i': index < 7 ? 5 + index : 0 }"
                    @click="openMetric(row.metricId)"
                  >
                    <td class="px-1 py-3">
                      <div class="font-medium text-rnp-text">
                        {{ row.name }}
                      </div>
                      <div
                        v-if="!row.week"
                        class="text-xs text-rnp-muted"
                      >
                        {{ t('rnp.monthlyOnly') }}
                      </div>
                    </td>
                    <td class="px-2 py-3 text-rnp-text/80 max-md:hidden">
                      {{ owners[row.metricId]?.name ?? '—' }}
                    </td>
                    <td class="px-2 py-3 text-right tabular-nums md:whitespace-nowrap">
                      <span class="font-semibold text-rnp-text">{{ formatMetricValue(row.unit, mainCell(row).fact) || '—' }}</span>
                      <span class="text-rnp-muted"> / {{ formatMetricValue(row.unit, mainCell(row).plan) || '—' }}</span>
                    </td>
                    <td class="px-2 py-3 max-md:hidden">
                      <UiStatusBadge :status="rowStatus(row)" />
                    </td>
                    <td class="px-1 py-2 text-center">
                      <UiProgressRing
                        :value="pctOf(mainCell(row).completionPct)"
                        :status="rowStatus(row)"
                        :size="38"
                      />
                    </td>
                  </tr>
                  <tr v-if="filteredRows.length === 0">
                    <td
                      colspan="5"
                      class="py-8 text-center text-rnp-muted"
                    >
                      {{ current.rows.length ? t('cabinet.nothingFiltered') : t('rnp.noMetrics') }}
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </UiCard>

          <!-- Overall completion -->
          <UiCard
            :title="t('cabinet.overall')"
            :class="rise"
            :style="{ '--i': 5 }"
          >
            <UiGauge
              :segments="gaugeSegments"
              :center="summary.donePct"
              :caption="t('cabinet.overallCaption')"
              :label="t('cabinet.overall')"
            />
            <dl class="mt-2 grid grid-cols-4 gap-2 text-center">
              <div
                v-for="item in legend"
                :key="item.key"
              >
                <dd
                  class="text-2xl font-bold tabular-nums"
                  :style="{ color: item.color }"
                >
                  {{ item.value }}
                </dd>
                <dt class="mt-0.5 text-xs text-rnp-muted">
                  {{ item.label }}
                </dt>
              </div>
            </dl>
          </UiCard>
        </div>

        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
          <!-- Run rate -->
          <UiCard
            :title="t('cabinet.runRate')"
            :class="rise"
            :style="{ '--i': 6 }"
          >
            <ul
              v-if="runRateRows.length"
              class="flex flex-col"
            >
              <li
                v-for="row in runRateRows"
                :key="row.metricId"
                class="flex items-center gap-3 border-b border-rnp-divider/70 py-2.5 last:border-0"
              >
                <div class="min-w-0 flex-1">
                  <div class="truncate text-sm font-medium text-rnp-text">
                    {{ row.name }}
                  </div>
                  <div class="text-xs text-rnp-muted tabular-nums">
                    {{ t('cabinet.forecast') }} {{ formatMetricValue(row.unit, row.runRate) }}
                    <template v-if="row.month.plan">
                      / {{ formatMetricValue(row.unit, row.month.plan) }}
                    </template>
                  </div>
                  <PaceHint :row="row" />
                </div>
                <UiProgressRing
                  :value="pctOf(row.runRateCompletionPct)"
                  :status="statusOf(row.runRateColor)"
                  :size="38"
                />
              </li>
            </ul>
            <p
              v-else
              class="text-sm text-rnp-muted"
            >
              {{ t('cabinet.noRunRate') }}
            </p>
          </UiCard>

          <!-- Execution rate -->
          <UiCard
            :title="t('cabinet.execution')"
            :class="rise"
            :style="{ '--i': 7 }"
          >
            <div
              v-if="actions"
              class="flex flex-col gap-4"
            >
              <div
                v-for="item in executionItems"
                :key="item.key"
                class="flex items-center gap-4"
              >
                <UiProgressRing
                  :value="pctOf(item.rate.pct)"
                  :status="executionStatus(item.rate.pct)"
                  :size="56"
                />
                <div class="min-w-0">
                  <div class="text-sm font-semibold text-rnp-text">
                    {{ item.label }} · {{ item.rate.done }} / {{ item.rate.planned }}
                  </div>
                  <div class="text-xs text-rnp-muted">
                    {{ t('cabinet.executionParts', {
                      bf: `${item.rate.baseFunctionsDone}/${item.rate.baseFunctionsPlanned}`,
                      rm: `${item.rate.roadmapDone}/${item.rate.roadmapPlanned}`,
                      sp: `${item.rate.sprintDone}/${item.rate.sprintPlanned}`,
                    }) }}
                  </div>
                </div>
              </div>
            </div>
            <p
              v-else
              class="text-sm text-rnp-muted"
            >
              —
            </p>
          </UiCard>

          <!-- Missed actions -->
          <UiCard
            :title="t('cabinet.missed')"
            :class="rise"
            :style="{ '--i': 8 }"
          >
            <template #actions>
              <UiStatusBadge
                v-if="actions?.missed.length"
                status="missed"
              >
                {{ actions.missed.length }}
              </UiStatusBadge>
              <NuxtLink
                :to="{ path: '/actions', query: unitId ? { unit: String(unitId) } : {} }"
                class="text-xs font-semibold text-rnp-text underline-offset-2 hover:underline"
              >
                {{ t('cabinet.allActions') }}
              </NuxtLink>
            </template>
            <ul
              v-if="actions?.missed.length"
              class="flex flex-col"
            >
              <li
                v-for="item in actions.missed.slice(0, 5)"
                :key="`${item.kind}-${item.id}-${item.periodStart}`"
                class="flex items-start justify-between gap-3 border-b border-rnp-divider/70 py-2.5 last:border-0"
              >
                <div class="min-w-0">
                  <div class="line-clamp-2 text-sm text-rnp-text">
                    {{ item.title }}
                  </div>
                  <div class="text-xs text-rnp-muted">
                    {{ item.owner?.name ?? '—' }}
                  </div>
                </div>
                <span class="shrink-0 text-xs font-medium text-rnp-red">{{ shortDate(item.due) }}</span>
              </li>
            </ul>
            <p
              v-else
              class="text-sm text-rnp-muted"
            >
              {{ t('cabinet.noMissed') }}
            </p>
          </UiCard>
        </div>
      </div>
    </Transition>
  </section>
</template>

<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import Message from 'primevue/message'
import { navigateTo, useRoute } from '#app'
import type { ExecutionRate } from '~/features/actions/types'
import AiWeeklyReviewCard from '~/features/ai/components/AiWeeklyReviewCard.vue'
import { useAuthStore } from '~/features/auth/stores/authStore'
import { useCabinetOverview } from '~/features/cabinet/composables/useCabinetOverview'
import { comparesPerDay, elapsedDays, isoDate, lastFinishedWeekDate, mainCell, pickKpis, rowStatus, summarize, trendOf } from '~/features/cabinet/utils/overview'
import OrgUnitSelect from '~/features/org/components/OrgUnitSelect.vue'
import { useOrgStore } from '~/features/org/stores/orgStore'
import PaceHint from '~/features/rnp/components/PaceHint.vue'
import WeekNavigator from '~/features/rnp/components/WeekNavigator.vue'
import type { RnpRow } from '~/features/rnp/types'
import UiCard from '~/features/ui/components/UiCard.vue'
import UiGauge from '~/features/ui/components/UiGauge.vue'
import UiKpiCard from '~/features/ui/components/UiKpiCard.vue'
import type { KpiTrend } from '~/features/ui/components/UiKpiCard.vue'
import UiPageHeader from '~/features/ui/components/UiPageHeader.vue'
import UiPillSelect from '~/features/ui/components/UiPillSelect.vue'
import UiProgressRing from '~/features/ui/components/UiProgressRing.vue'
import UiSkeleton from '~/features/ui/components/UiSkeleton.vue'
import UiStatusBadge from '~/features/ui/components/UiStatusBadge.vue'
import { statusOf } from '~/features/ui/status'
import type { UiStatus } from '~/features/ui/status'
import { formatMetricValue } from '~/utils/metricFormat'

const KPI_ICONS = ['pi pi-chart-bar', 'pi pi-wallet', 'pi pi-users', 'pi pi-bolt']
const KPI_COLORS = ['var(--rnp-kpi-lilac)', 'var(--rnp-kpi-orange)', 'var(--rnp-kpi-blue)', 'var(--rnp-kpi-yellow)']

const { t, locale } = useI18n()
const route = useRoute()
const authStore = useAuthStore()
const orgStore = useOrgStore()

const weekParam = computed(() => (typeof route.query.week === 'string' ? route.query.week : null))
const unitId = computed<number | null>(() => {
  const fromQuery = Number(route.query.unit)
  if (Number.isInteger(fromQuery) && fromQuery > 0) return fromQuery
  return authStore.me?.rootUnitIds[0] ?? orgStore.tree[0]?.id ?? null
})

/**
 * Without an explicit week the overview shows the last finished week: a running week compares a day or two
 * against a full one ("−100 %" on Monday). "Current week" in the navigator opens the running one by date.
 */
const shownWeek = computed(() => weekParam.value ?? lastFinishedWeekDate())
const { current, previous, actions, owners, loading, error } = useCabinetOverview(unitId, shownWeek)

/** The cascade plays once per visit; later reloads only crossfade. */
const played = ref(false)
const rise = computed(() => (played.value ? '' : 'rnp-rise'))
watch(current, (value) => {
  if (value && !played.value) setTimeout(() => (played.value = true), 900)
})

function go(query: { unit: number | null, week: string | null }) {
  void navigateTo({
    path: '/cabinet',
    query: {
      ...(query.unit ? { unit: String(query.unit) } : {}),
      ...(query.week ? { week: query.week } : {}),
    },
  })
}

function openMetric(id: number) {
  void navigateTo(`/metrics/${id}`)
}

const subtitle = computed(() => {
  const parts = [current.value?.orgUnit.name, t('cabinet.subtitle'), weekParam.value ? null : t('cabinet.lastFinished')]
  return parts.filter(Boolean).join(' · ')
})

const kpis = computed(() => pickKpis(current.value?.rows ?? []))

function kpiTrend(row: RnpRow): KpiTrend | null {
  if (!row.week) return null
  const prevWeek = previous.value
  const now = current.value
  if (!prevWeek || !now) return null
  const prev = prevWeek.rows.find(r => r.metricId === row.metricId)?.week?.fact ?? null
  const perDay = comparesPerDay(row)
  const days = perDay
    ? { current: elapsedDays(now.weekStart, now.weekEnd), previous: elapsedDays(prevWeek.weekStart, prevWeek.weekEnd) }
    : undefined
  const trend = trendOf(row.week.fact, prev, row.direction, days)
  return trend
    ? { up: trend.up, good: trend.good, text: t(perDay ? 'cabinet.trendWeekPerDay' : 'cabinet.trendWeek', { pct: trend.pct }) }
    : null
}

// Filters of the branch table.
const ownerFilter = ref<number | null>(null)
const statusFilter = ref<UiStatus | null>(null)
watch(unitId, () => {
  ownerFilter.value = null
  statusFilter.value = null
})

const ownerOptions = computed(() => {
  const seen = new Map<number, string>()
  for (const owner of Object.values(owners.value)) {
    if (owner) seen.set(owner.id, owner.name)
  }
  return [...seen].map(([value, label]) => ({ value, label })).sort((a, b) => a.label.localeCompare(b.label))
})

const statusOptions = computed(() => (['done', 'risk', 'missed', 'none'] as UiStatus[]).map(value => ({ value, label: t(`ui.status.${value}`) })))

const filteredRows = computed(() => (current.value?.rows ?? []).filter(row =>
  (ownerFilter.value === null || owners.value[row.metricId]?.id === ownerFilter.value)
  && (statusFilter.value === null || rowStatus(row) === statusFilter.value),
))

// Overall completion gauge.
const summary = computed(() => summarize(current.value?.rows ?? []))
const gaugeSegments = computed(() => {
  const planned = summary.value.done + summary.value.risk + summary.value.missed
  const share = (n: number) => (planned ? (n / planned) * 100 : 0)
  return [
    { key: 'done', value: share(summary.value.done), color: 'var(--rnp-green)' },
    { key: 'risk', value: share(summary.value.risk), color: 'var(--rnp-kpi-yellow)' },
    { key: 'missed', value: share(summary.value.missed), color: 'var(--rnp-red)' },
  ]
})
const legend = computed(() => [
  { key: 'total', value: summary.value.total, label: t('cabinet.total'), color: 'var(--rnp-text)' },
  { key: 'done', value: summary.value.done, label: t('ui.status.done'), color: 'var(--rnp-green)' },
  { key: 'risk', value: summary.value.risk, label: t('ui.status.risk'), color: 'var(--rnp-yellow)' },
  { key: 'missed', value: summary.value.missed, label: t('ui.status.missed'), color: 'var(--rnp-red)' },
])

const runRateRows = computed(() => (current.value?.rows ?? []).filter(row => row.runRate !== null).slice(0, 5))

const executionItems = computed(() => (actions.value
  ? [
      { key: 'week', label: t('cabinet.executionWeek'), rate: actions.value.weekExecution },
      { key: 'month', label: t('cabinet.executionMonth'), rate: actions.value.monthExecution },
    ]
  : []) as { key: string, label: string, rate: ExecutionRate }[])

/** Execution rate has no thresholds of its own: the default RNP ones (≥ 100 done, < 80 missed). */
function executionStatus(pct: string | null): UiStatus {
  if (pct === null) return 'none'
  const value = Number(pct)
  return value >= 100 ? 'done' : value < 80 ? 'missed' : 'risk'
}

function pctOf(value: string | null): number | null {
  return value === null ? null : Number(value)
}

function shortDate(date: string): string {
  return new Intl.DateTimeFormat(locale.value, { day: '2-digit', month: '2-digit' }).format(new Date(`${date}T12:00:00`))
}

onMounted(() => {
  if (!orgStore.units.length) void orgStore.load()
})
</script>
