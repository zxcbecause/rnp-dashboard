<template>
  <UiCard :title="t('metrics.chart.title')">
    <template #actions>
      <div
        class="flex h-9 items-center gap-0.5 rounded-full bg-rnp-pill p-1"
        role="group"
      >
        <button
          v-for="option in periodOptions"
          :key="option.value"
          type="button"
          class="h-7 rounded-full px-3 text-xs font-semibold transition-colors"
          :class="period === option.value ? 'bg-rnp-accent text-rnp-text' : 'text-rnp-muted hover:text-rnp-text'"
          :aria-pressed="period === option.value"
          @click="period = option.value"
        >
          {{ option.label }}
        </button>
      </div>
      <div
        class="flex h-9 items-center gap-0.5 rounded-full bg-rnp-pill p-1"
        role="group"
      >
        <button
          v-for="option in rangeOptions"
          :key="option.value"
          type="button"
          class="h-7 rounded-full px-3 text-xs font-semibold transition-colors"
          :class="months === option.value ? 'bg-rnp-accent text-rnp-text' : 'text-rnp-muted hover:text-rnp-text'"
          :aria-pressed="months === option.value"
          @click="months = option.value"
        >
          {{ option.label }}
        </button>
      </div>
      <UiPill
        icon="pi pi-history"
        :label="t('metrics.chart.prevYear')"
        :active="compareYear"
        :aria-pressed="compareYear"
        @click="compareYear = !compareYear"
      />
    </template>

    <Message
      v-if="error"
      severity="error"
      size="small"
    >
      {{ error }}
    </Message>
    <div
      v-else
      class="relative h-80 transition-opacity"
      :class="loading ? 'opacity-50' : ''"
    >
      <Chart
        type="bar"
        :data="chartData"
        :options="chartOptions"
        class="h-full"
      />
    </div>
  </UiCard>
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import Chart from 'primevue/chart'
import Message from 'primevue/message'
import { useApi } from '~/composables/useApi'
import { createLatestRequestScope } from '~/composables/useLatestRequest'
import UiCard from '~/features/ui/components/UiCard.vue'
import UiPill from '~/features/ui/components/UiPill.vue'
import { formatMetricValue, shiftDecimal } from '~/utils/metricFormat'
import { fetchSeries, lastMonthsRange } from '../api/metrics'
import type { Metric, SeriesPoint } from '../types'

const props = defineProps<{ metric: Metric }>()
/** Loaded points with their period, for the page header figures. */
const emit = defineEmits<{ loaded: [points: SeriesPoint[], period: 'week' | 'month'] }>()
const { t } = useI18n()

const period = ref<'week' | 'month'>(props.metric.granularity === 'month' ? 'month' : 'week')
const months = ref(3)
/** Overlay the same periods a year earlier */
const compareYear = ref(false)
const points = ref<SeriesPoint[]>([])
/** Period of the loaded points: labels must follow the data, not the toggle, while a reload is in flight. */
const pointsPeriod = ref<'week' | 'month'>(period.value)
const loading = ref(false)
const error = ref<string | null>(null)
const scope = createLatestRequestScope()

const periodOptions = computed(() => [
  ...(props.metric.granularity === 'month' ? [] : [{ label: t('metrics.chart.weeks'), value: 'week' as const }]),
  { label: t('metrics.chart.months'), value: 'month' as const },
])
const rangeOptions = computed(() => [3, 6, 12].map(value => ({ label: t('metrics.chart.range', { n: value }), value })))

async function load() {
  const isLatest = scope.begin()
  loading.value = true
  error.value = null
  try {
    const { from, to } = lastMonthsRange(months.value)
    const requested = period.value
    const data = await fetchSeries(useApi(), props.metric.id, requested, from, to, compareYear.value)
    if (!isLatest()) return
    points.value = data
    pointsPeriod.value = requested
    emit('loaded', data, requested)
  }
  catch (e) {
    if (!isLatest()) return
    error.value = e instanceof Error ? e.message : String(e)
  }
  finally {
    if (isLatest()) loading.value = false
  }
}

watch([period, months, compareYear, () => props.metric.id], load, { immediate: true })

/** Chart values: percent metrics in percent points, everything else as is. */
const toNumber = (value: string | null) => (value === null ? null : Number(props.metric.unit === 'percent' ? shiftDecimal(value, 2) : value))

const label = (p: SeriesPoint) => {
  const [y, m, d] = p.periodStart.split('-')
  return pointsPeriod.value === 'month' ? `${m}.${y}` : `${d}.${m}`
}

/** Palette of main.css (chart.js needs literal colours): status colours, brand yellow, dark plan, muted last year. */
const COLORS = { green: '#16a34a', red: '#dc2626', neutral: '#c99a06', none: '#eec52b', plan: '#1a1a1a', prevYear: '#8a857c', grid: '#ebe5d9', muted: '#8a857c' }
const FONT = { family: 'Manrope, sans-serif' }

const chartData = computed(() => ({
  labels: points.value.map(label),
  datasets: [
    {
      type: 'line' as const,
      label: t('rnp.plan'),
      data: points.value.map(p => toNumber(p.plan)),
      borderColor: COLORS.plan,
      backgroundColor: COLORS.plan,
      borderDash: [6, 4],
      borderWidth: 2,
      pointRadius: 2.5,
      spanGaps: true,
      tension: 0.2,
    },
    {
      type: 'bar' as const,
      label: t('rnp.fact'),
      data: points.value.map(p => toNumber(p.fact)),
      backgroundColor: points.value.map(p => (p.color ? COLORS[p.color] : COLORS.none)),
      borderRadius: 8,
      maxBarThickness: 36,
    },
    ...(compareYear.value
      ? [{
          type: 'line' as const,
          label: t('metrics.chart.prevYear'),
          data: points.value.map(p => toNumber(p.factPrevYear)),
          borderColor: COLORS.prevYear,
          backgroundColor: COLORS.prevYear,
          borderWidth: 2,
          pointRadius: 3,
          spanGaps: true,
          tension: 0.2,
        }]
      : []),
  ],
}))

/** Tooltip text of a dataset: 0 plan, 1 fact (with %), 2 last year. */
function tooltipLabel(p: SeriesPoint, datasetIndex: number): string {
  if (datasetIndex === 2) return `${t('metrics.chart.prevYear')}: ${formatMetricValue(props.metric.unit, p.factPrevYear) || '—'}`
  const value = datasetIndex === 0 ? p.plan : p.fact
  const text = `${datasetIndex === 0 ? t('rnp.plan') : t('rnp.fact')}: ${formatMetricValue(props.metric.unit, value) || '—'}`
  return datasetIndex === 1 && p.completionPct ? `${text} (${p.completionPct} %)` : text
}

const chartOptions = computed(() => ({
  maintainAspectRatio: false,
  animation: { duration: 600, easing: 'easeOutQuart' },
  plugins: {
    legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 8, color: COLORS.muted, font: FONT } },
    tooltip: {
      backgroundColor: COLORS.plan,
      cornerRadius: 12,
      padding: 10,
      titleFont: FONT,
      bodyFont: FONT,
      callbacks: {
        label: (ctx: { dataIndex: number, datasetIndex: number }) => {
          const p = points.value[ctx.dataIndex]
          return p ? tooltipLabel(p, ctx.datasetIndex) : ''
        },
      },
    },
  },
  scales: {
    x: { grid: { display: false }, border: { display: false }, ticks: { color: COLORS.muted, font: FONT } },
    y: { beginAtZero: true, grid: { color: COLORS.grid }, border: { display: false }, ticks: { color: COLORS.muted, font: FONT } },
  },
}))
</script>
