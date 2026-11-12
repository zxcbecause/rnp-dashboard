<template>
  <section class="flex flex-col gap-6">
    <NuxtLink
      :to="backLink"
      class="inline-flex h-9 w-fit items-center gap-2 rounded-full bg-rnp-pill px-4 text-sm font-medium text-rnp-text transition-colors hover:bg-rnp-accent-soft"
    >
      <i
        class="pi pi-arrow-left text-xs"
        aria-hidden="true"
      />
      {{ t('metrics.backToRnp') }}
    </NuxtLink>

    <Message
      v-if="notFound"
      severity="warn"
    >
      {{ t('metrics.notFound') }}
    </Message>
    <Message
      v-else-if="error"
      severity="error"
    >
      {{ error }}
    </Message>

    <!-- First load: skeletons in the shape of the page -->
    <div
      v-else-if="!metric"
      class="flex flex-col gap-6"
    >
      <UiSkeleton
        class="h-36"
        rounded="rounded-[20px]"
      />
      <UiSkeleton
        class="h-96"
        rounded="rounded-[20px]"
      />
    </div>

    <template v-else>
      <!-- Header card: name, tags and the latest figures -->
      <UiCard
        class="rnp-rise"
        :style="{ '--i': 0 }"
      >
        <div class="flex flex-wrap items-start justify-between gap-6">
          <div class="min-w-0">
            <h2 class="text-2xl font-bold text-rnp-text">
              {{ metric.name }}
            </h2>
            <p class="mt-1 font-mono text-xs text-rnp-muted">
              {{ metric.code }}
            </p>
            <div class="mt-3 flex flex-wrap gap-1.5">
              <span
                v-for="tag in tags"
                :key="tag"
                class="rounded-full bg-rnp-bg px-2.5 py-0.5 text-xs text-rnp-muted"
              >{{ tag }}</span>
            </div>
          </div>

          <div
            v-if="latest"
            class="flex items-center gap-5"
          >
            <div class="text-right">
              <div class="text-xs text-rnp-muted">
                {{ latestLabel }}
              </div>
              <div class="mt-1 whitespace-nowrap tabular-nums">
                <span class="text-3xl font-bold text-rnp-text">{{ formatMetricValue(metric.unit, latest.fact) }}</span>
                <span
                  v-if="latest.plan !== null"
                  class="text-sm text-rnp-muted"
                > / {{ formatMetricValue(metric.unit, latest.plan) }}</span>
              </div>
              <div class="mt-2 flex flex-wrap items-center justify-end gap-2 text-xs">
                <span
                  v-if="trend"
                  class="inline-flex items-center gap-1 font-semibold"
                  :class="trend.good ? 'text-rnp-green' : 'text-rnp-red'"
                >
                  <i
                    class="pi text-[10px]"
                    :class="trend.up ? 'pi-arrow-up-right' : 'pi-arrow-down-right'"
                    aria-hidden="true"
                  />
                  {{ trend.text }}
                </span>
                <UiStatusBadge :status="latestStatus" />
              </div>
            </div>
            <UiProgressRing
              :value="latest.completionPct === null ? null : Number(latest.completionPct)"
              :status="latestStatus"
              :size="72"
            />
          </div>
          <p
            v-else-if="pointsPeriod"
            class="text-sm text-rnp-muted"
          >
            {{ t('metrics.hero.noFacts') }}
          </p>
        </div>
      </UiCard>

      <MetricChart
        class="rnp-rise"
        :style="{ '--i': 1 }"
        :metric="metric"
        @loaded="onLoaded"
      />
      <MetricCard
        class="rnp-rise"
        :style="{ '--i': 2 }"
        :metric="metric"
      />
      <MetricActions
        class="rnp-rise"
        :style="{ '--i': 3 }"
        :metric="metric"
      />
    </template>
  </section>
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import Message from 'primevue/message'
import { useRoute } from '#app'
import { useApi } from '~/composables/useApi'
import { NotFoundError } from '~/types/api-errors'
import { elapsedDays, trendOf } from '~/features/cabinet/utils/overview'
import MetricActions from '~/features/metrics/components/MetricActions.vue'
import MetricCard from '~/features/metrics/components/MetricCard.vue'
import MetricChart from '~/features/metrics/components/MetricChart.vue'
import { fetchMetric } from '~/features/metrics/api/metrics'
import type { Metric, SeriesPoint } from '~/features/metrics/types'
import UiCard from '~/features/ui/components/UiCard.vue'
import UiProgressRing from '~/features/ui/components/UiProgressRing.vue'
import UiSkeleton from '~/features/ui/components/UiSkeleton.vue'
import UiStatusBadge from '~/features/ui/components/UiStatusBadge.vue'
import { statusOf } from '~/features/ui/status'
import { formatMetricValue } from '~/utils/metricFormat'

const { t, locale } = useI18n()
const route = useRoute()

const metric = ref<Metric | null>(null)
const notFound = ref(false)
const error = ref<string | null>(null)

const backLink = computed(() => (metric.value ? { path: '/rnp-week', query: { unit: String(metric.value.orgUnitId) } } : '/rnp-week'))

const tags = computed(() => {
  const m = metric.value
  if (!m) return []
  return [
    m.formula ? t('rnp.tags.formula') : t(`rnp.tags.${m.sourceType}`),
    t(`metrics.granularity.${m.granularity}`),
    ...(m.direction === 'lower_better' ? [t('rnp.tags.lowerBetter')] : []),
  ]
})

// Latest figures for the header: taken from the points the chart loaded.
const points = ref<SeriesPoint[]>([])
const pointsPeriod = ref<'week' | 'month' | null>(null)

function onLoaded(data: SeriesPoint[], period: 'week' | 'month') {
  points.value = data
  pointsPeriod.value = period
}

/** The latest finished period with a fact (a running week would compare a day or two against a full week). */
const latestIndex = computed(() => {
  const today = isoToday()
  const finished = points.value.findLastIndex(p => p.fact !== null && p.periodEnd < today)
  return finished >= 0 ? finished : points.value.findLastIndex(p => p.fact !== null)
})
const latest = computed(() => points.value[latestIndex.value] ?? null)
const previous = computed(() => (latestIndex.value > 0 ? points.value[latestIndex.value - 1] ?? null : null))
const latestStatus = computed(() => statusOf(latest.value?.color))

const latestLabel = computed(() => {
  const p = latest.value
  if (!p) return ''
  if (pointsPeriod.value === 'month') return monthLabel(p.periodStart)
  return t('metrics.hero.week', { from: shortDate(p.periodStart), to: shortDate(p.periodEnd) })
})

/** Change against the previous period; a running period of a sum metric is compared per day. */
const trend = computed(() => {
  const m = metric.value
  const now = latest.value
  const before = previous.value
  if (!m || !now || !before) return null
  const perDay = m.timeAgg === 'sum' && !m.formula
  const days = perDay
    ? { current: elapsedDays(now.periodStart, now.periodEnd), previous: elapsedDays(before.periodStart, before.periodEnd) }
    : undefined
  const result = trendOf(now.fact, before.fact, m.direction, days)
  if (!result) return null
  return { up: result.up, good: result.good, text: t(perDay ? 'metrics.hero.trendPerDay' : 'metrics.hero.trend', { pct: result.pct }) }
})

function isoToday(): string {
  const now = new Date()
  return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`
}

function shortDate(date: string): string {
  const [, m, d] = date.split('-')
  return `${d}.${m}`
}

function monthLabel(date: string): string {
  const label = new Intl.DateTimeFormat(locale.value, { month: 'long', year: 'numeric' }).format(new Date(`${date}T12:00:00`))
  return label.charAt(0).toUpperCase() + label.slice(1)
}

watch(() => route.params.id, async (id) => {
  metric.value = null
  notFound.value = false
  error.value = null
  points.value = []
  pointsPeriod.value = null
  const numericId = Number(id)
  if (!Number.isInteger(numericId) || numericId <= 0) {
    notFound.value = true
    return
  }
  try {
    metric.value = await fetchMetric(useApi(), numericId)
  }
  catch (e) {
    if (e instanceof NotFoundError) notFound.value = true
    else error.value = e instanceof Error ? e.message : String(e)
  }
}, { immediate: true })
</script>
