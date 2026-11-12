<template>
  <RnpCards
    v-if="mobile"
    :week="week"
    :rows="rows"
    :animate="animate"
    :empty-text="emptyText"
    @changed="emit('changed')"
    @explain="emit('explain', $event)"
  />
  <div
    v-else
    class="-mx-1 overflow-x-auto"
  >
    <table class="w-full min-w-[840px] border-separate border-spacing-0 text-sm">
      <thead>
        <tr class="text-xs text-rnp-muted">
          <th
            rowspan="2"
            class="border-b border-rnp-divider px-2 xl:px-3 pb-3 text-left align-bottom text-sm font-semibold text-rnp-text"
          >
            {{ t('rnp.metric') }}
          </th>
          <th
            colspan="3"
            class="rounded-t-2xl bg-rnp-accent-soft/70 px-2 xl:px-3 pt-3 pb-1 text-center font-semibold uppercase tracking-wide"
          >
            {{ t('rnp.week') }}
          </th>
          <th class="w-2 xl:w-3" />
          <th
            colspan="4"
            class="rounded-t-2xl bg-rnp-bg/70 px-2 xl:px-3 pt-3 pb-1 text-center font-semibold uppercase tracking-wide"
          >
            {{ t('rnp.month') }}
          </th>
        </tr>
        <tr class="text-left text-rnp-text">
          <th class="border-b border-rnp-divider bg-rnp-accent-soft/70 px-2 xl:px-3 pb-3 pt-1 text-right font-semibold">
            {{ t('rnp.plan') }}
          </th>
          <th class="border-b border-rnp-divider bg-rnp-accent-soft/70 px-2 xl:px-3 pb-3 pt-1 text-right font-semibold">
            {{ t('rnp.fact') }}
          </th>
          <th class="border-b border-rnp-divider bg-rnp-accent-soft/70 px-2 xl:px-3 pb-3 pt-1 text-center font-semibold">
            %
          </th>
          <th class="border-b border-rnp-divider" />
          <th class="border-b border-rnp-divider bg-rnp-bg/70 px-2 xl:px-3 pb-3 pt-1 text-right font-semibold">
            {{ t('rnp.plan') }}
          </th>
          <th class="border-b border-rnp-divider bg-rnp-bg/70 px-2 xl:px-3 pb-3 pt-1 text-right font-semibold">
            {{ t('rnp.factToDate') }}
          </th>
          <th class="border-b border-rnp-divider bg-rnp-bg/70 px-2 xl:px-3 pb-3 pt-1 text-center font-semibold">
            %
          </th>
          <th
            class="border-b border-rnp-divider bg-rnp-bg/70 px-2 xl:px-3 pb-3 pt-1 text-right font-semibold"
            :title="t('rnp.runRateHint')"
          >
            {{ t('rnp.runRate') }}
            <i
              class="pi pi-info-circle ml-0.5 text-[10px] text-rnp-muted"
              aria-hidden="true"
            />
          </th>
        </tr>
      </thead>
      <tbody>
        <tr
          v-for="(row, index) in rows"
          :key="row.metricId"
          class="group"
          :class="animate ? 'rnp-rise' : ''"
          :style="{ '--i': index < 12 ? index : 0 }"
        >
          <td
            class="relative min-w-48 border-b border-rnp-divider/70 py-3 pl-4 pr-2 xl:pr-3 align-top transition-colors group-hover:bg-rnp-accent-soft/40"
          >
            <span
              class="absolute left-0 top-3 bottom-3 w-1 rounded-full"
              :style="{ backgroundColor: STATUS_COLORS[rowStatus(row)].fg }"
              :title="t(`ui.status.${rowStatus(row)}`)"
              aria-hidden="true"
            />
            <NuxtLink
              :to="`/metrics/${row.metricId}`"
              class="font-medium text-rnp-text underline-offset-2 hover:underline"
            >
              {{ row.name }}
            </NuxtLink>
            <div class="mt-1 flex flex-wrap gap-1">
              <span
                v-for="tag in rowTagKeys(row)"
                :key="tag"
                class="rounded-full bg-rnp-bg px-2 py-px text-[11px] text-rnp-muted"
              >{{ t(tag) }}</span>
            </div>
            <!-- Deviation explanation: the note itself, or an invitation where the plan is missed -->
            <button
              v-if="row.note"
              type="button"
              class="mt-1.5 flex max-w-72 items-start gap-1.5 rounded-lg bg-rnp-accent-soft/70 px-2 py-1 text-left text-xs text-rnp-text transition-colors hover:bg-rnp-accent-soft"
              :title="row.note.action ? `${row.note.reason}\n→ ${row.note.action}` : row.note.reason"
              @click="emit('explain', row)"
            >
              <i
                class="pi pi-comment mt-0.5 text-[10px] text-rnp-accent-hover"
                aria-hidden="true"
              />
              <span class="line-clamp-2">{{ row.note.reason }}</span>
            </button>
            <button
              v-else-if="row.canExplain && ['missed', 'risk'].includes(rowStatus(row))"
              type="button"
              class="mt-1.5 inline-flex items-center gap-1 text-xs font-medium text-rnp-muted underline-offset-2 transition-colors hover:text-rnp-text hover:underline"
              @click="emit('explain', row)"
            >
              <i
                class="pi pi-comment text-[10px]"
                aria-hidden="true"
              />
              {{ t('deviation.explain') }}
            </button>
          </td>

          <template v-if="row.week">
            <td class="border-b border-rnp-divider/70 bg-rnp-accent-soft/40 px-2 xl:px-3 py-3 text-right align-top text-rnp-muted transition-colors group-hover:bg-rnp-accent-soft/80">
              <EditableValue
                :value="row.week.plan"
                :editable="row.canSetPlan"
                :target="{ metricId: row.metricId, unit: row.unit, kind: 'plan', periodType: 'week', periodStart: week.weekStart }"
                @saved="emit('changed')"
              />
            </td>
            <td class="border-b border-rnp-divider/70 bg-rnp-accent-soft/40 px-2 xl:px-3 py-3 text-right align-top font-semibold text-rnp-text transition-colors group-hover:bg-rnp-accent-soft/80">
              <EditableValue
                :value="row.week.fact"
                :editable="row.canEnterFact"
                :target="{ metricId: row.metricId, unit: row.unit, kind: 'fact', periodType: 'week', periodStart: week.weekStart }"
                @saved="emit('changed')"
              />
              <p
                v-if="row.dailyAverage?.week"
                class="mt-0.5 text-[11px] font-normal text-rnp-muted"
              >
                {{ t('rnp.perDay', { value: formatPerDay(row.dailyAverage.week) }) }}
              </p>
            </td>
            <td class="border-b border-rnp-divider/70 bg-rnp-accent-soft/40 px-2 xl:px-3 py-3 text-center align-top transition-colors group-hover:bg-rnp-accent-soft/80">
              <CompletionBadge
                :pct="row.week.completionPct"
                :color="row.week.color"
              />
            </td>
          </template>
          <td
            v-else
            colspan="3"
            class="border-b border-rnp-divider/70 bg-rnp-accent-soft/40 px-2 xl:px-3 py-3 text-center align-top text-xs text-rnp-muted transition-colors group-hover:bg-rnp-accent-soft/80"
          >
            {{ t('rnp.monthlyOnly') }}
          </td>

          <td class="border-b border-rnp-divider/70 transition-colors group-hover:bg-rnp-accent-soft/40" />

          <td class="border-b border-rnp-divider/70 bg-rnp-bg/50 px-2 xl:px-3 py-3 text-right align-top text-rnp-muted transition-colors group-hover:bg-rnp-accent-soft/60">
            <EditableValue
              :value="row.month.plan"
              :editable="row.canSetPlan"
              :target="{ metricId: row.metricId, unit: row.unit, kind: 'plan', periodType: 'month', periodStart: week.monthStart }"
              @saved="emit('changed')"
            />
          </td>
          <td
            class="border-b border-rnp-divider/70 bg-rnp-bg/50 px-2 xl:px-3 py-3 text-right align-top text-rnp-text transition-colors group-hover:bg-rnp-accent-soft/60"
            :class="row.week ? '' : 'font-semibold'"
          >
            <EditableValue
              :value="row.month.fact"
              :editable="!row.week && row.canEnterFact"
              :target="{ metricId: row.metricId, unit: row.unit, kind: 'fact', periodType: 'month', periodStart: week.monthStart }"
              @saved="emit('changed')"
            />
            <p
              v-if="row.dailyAverage?.month"
              class="mt-0.5 text-[11px] font-normal text-rnp-muted"
            >
              {{ t('rnp.perDay', { value: formatPerDay(row.dailyAverage.month) }) }}
            </p>
          </td>
          <td class="border-b border-rnp-divider/70 bg-rnp-bg/50 px-2 xl:px-3 py-3 text-center align-top transition-colors group-hover:bg-rnp-accent-soft/60">
            <CompletionBadge
              :pct="row.month.completionPct"
              :color="row.month.color"
            />
          </td>
          <td class="border-b border-rnp-divider/70 bg-rnp-bg/50 px-2 xl:px-3 py-3 text-right align-top transition-colors group-hover:bg-rnp-accent-soft/60">
            <div
              v-if="row.runRate !== null"
              class="flex flex-col items-end gap-1"
            >
              <span class="tabular-nums text-rnp-text">{{ formatMetricValue(row.unit, row.runRate) }}</span>
              <CompletionBadge
                v-if="row.runRateCompletionPct !== null"
                :pct="row.runRateCompletionPct"
                :color="row.runRateColor"
                :title="t('rnp.runRateHint')"
              />
              <PaceHint :row="row" />
            </div>
            <span
              v-else
              class="text-rnp-muted/50"
            >—</span>
          </td>
        </tr>
        <tr v-if="!rows.length">
          <td
            colspan="9"
            class="px-4 py-10 text-center text-sm text-rnp-muted"
          >
            {{ emptyText ?? t('rnp.noMetrics') }}
          </td>
        </tr>
      </tbody>
    </table>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { rowStatus } from '~/features/cabinet/utils/overview'
import { STATUS_COLORS } from '~/features/ui/status'
import { useSidebar } from '~/features/layout/composables/useSidebar'
import { formatMetricValue } from '~/utils/metricFormat'
import type { RnpRow, RnpWeek } from '../types'
import { rowTagKeys } from '../utils/tags'
import CompletionBadge from './CompletionBadge.vue'
import EditableValue from './EditableValue.vue'
import PaceHint from './PaceHint.vue'
import { formatPerDay } from '../utils/dailyAverage'
import RnpCards from './RnpCards.vue'

const props = withDefaults(defineProps<{
  week: RnpWeek
  /** Rows to show (e.g. filtered by status); defaults to all rows of the week. */
  visibleRows?: RnpRow[]
  /** Play the row cascade (first show only). */
  animate?: boolean
  emptyText?: string
}>(), { visibleRows: undefined, animate: false, emptyText: undefined })
const emit = defineEmits<{ changed: [], explain: [row: RnpRow] }>()

const { t } = useI18n()
/** Phones get cards instead of the wide table (one of the two is rendered, never both). */
const { mobile } = useSidebar()

const rows = computed(() => props.visibleRows ?? props.week.rows)
</script>
