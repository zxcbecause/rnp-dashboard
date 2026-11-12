<template>
  <!-- Phone layout of the RNP table: one card per metric, the same values and inputs. -->
  <ul class="flex flex-col gap-3">
    <li
      v-for="(row, index) in rows"
      :key="row.metricId"
      class="relative rounded-2xl bg-rnp-bg/60 py-3 pl-4 pr-3"
      :class="animate ? 'rnp-rise' : ''"
      :style="{ '--i': index < 12 ? index : 0 }"
    >
      <span
        class="absolute bottom-3 left-0 top-3 w-1 rounded-full"
        :style="{ backgroundColor: STATUS_COLORS[rowStatus(row)].fg }"
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
          class="rounded-full bg-rnp-pill px-2 py-px text-[11px] text-rnp-muted"
        >{{ t(tag) }}</span>
      </div>

      <div class="mt-3 grid grid-cols-2 gap-2 text-sm">
        <!-- Week -->
        <div class="rounded-xl bg-rnp-accent-soft/50 p-2.5">
          <p class="mb-1.5 text-[11px] font-semibold uppercase tracking-wide text-rnp-muted">
            {{ t('rnp.week') }}
          </p>
          <template v-if="row.week">
            <div class="flex items-baseline justify-between gap-2">
              <span class="text-xs text-rnp-muted">{{ t('rnp.fact') }}</span>
              <span class="font-semibold text-rnp-text">
                <EditableValue
                  :value="row.week.fact"
                  :editable="row.canEnterFact"
                  :target="{ metricId: row.metricId, unit: row.unit, kind: 'fact', periodType: 'week', periodStart: week.weekStart }"
                  @saved="emit('changed')"
                />
              </span>
            </div>
            <p
              v-if="row.dailyAverage?.week"
              class="text-right text-[11px] text-rnp-muted"
            >
              {{ t('rnp.perDay', { value: formatPerDay(row.dailyAverage.week) }) }}
            </p>
            <div class="flex items-baseline justify-between gap-2">
              <span class="text-xs text-rnp-muted">{{ t('rnp.plan') }}</span>
              <span class="text-rnp-muted">
                <EditableValue
                  :value="row.week.plan"
                  :editable="row.canSetPlan"
                  :target="{ metricId: row.metricId, unit: row.unit, kind: 'plan', periodType: 'week', periodStart: week.weekStart }"
                  @saved="emit('changed')"
                />
              </span>
            </div>
            <div class="mt-1.5">
              <CompletionBadge
                :pct="row.week.completionPct"
                :color="row.week.color"
              />
            </div>
          </template>
          <p
            v-else
            class="text-xs text-rnp-muted"
          >
            {{ t('rnp.monthlyOnly') }}
          </p>
        </div>

        <!-- Month -->
        <div class="rounded-xl bg-rnp-pill p-2.5">
          <p class="mb-1.5 text-[11px] font-semibold uppercase tracking-wide text-rnp-muted">
            {{ t('rnp.month') }}
          </p>
          <div class="flex items-baseline justify-between gap-2">
            <span class="text-xs text-rnp-muted">{{ t('rnp.fact') }}</span>
            <span
              class="text-rnp-text"
              :class="row.week ? '' : 'font-semibold'"
            >
              <EditableValue
                :value="row.month.fact"
                :editable="!row.week && row.canEnterFact"
                :target="{ metricId: row.metricId, unit: row.unit, kind: 'fact', periodType: 'month', periodStart: week.monthStart }"
                @saved="emit('changed')"
              />
            </span>
          </div>
          <p
            v-if="row.dailyAverage?.month"
            class="text-right text-[11px] text-rnp-muted"
          >
            {{ t('rnp.perDay', { value: formatPerDay(row.dailyAverage.month) }) }}
          </p>
          <div class="flex items-baseline justify-between gap-2">
            <span class="text-xs text-rnp-muted">{{ t('rnp.plan') }}</span>
            <span class="text-rnp-muted">
              <EditableValue
                :value="row.month.plan"
                :editable="row.canSetPlan"
                :target="{ metricId: row.metricId, unit: row.unit, kind: 'plan', periodType: 'month', periodStart: week.monthStart }"
                @saved="emit('changed')"
              />
            </span>
          </div>
          <div class="mt-1.5">
            <CompletionBadge
              :pct="row.month.completionPct"
              :color="row.month.color"
            />
          </div>
        </div>
      </div>

      <div
        v-if="row.runRate !== null"
        class="mt-2 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-rnp-muted"
      >
        <span>{{ t('rnp.runRate') }}:</span>
        <span class="tabular-nums font-medium text-rnp-text">{{ formatMetricValue(row.unit, row.runRate) }}</span>
        <CompletionBadge
          v-if="row.runRateCompletionPct !== null"
          :pct="row.runRateCompletionPct"
          :color="row.runRateColor"
        />
        <PaceHint :row="row" />
      </div>

      <button
        v-if="row.note"
        type="button"
        class="mt-2 flex w-full items-start gap-1.5 rounded-lg bg-rnp-accent-soft/70 px-2 py-1.5 text-left text-xs text-rnp-text"
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
        class="mt-2 inline-flex items-center gap-1 text-xs font-medium text-rnp-muted underline-offset-2"
        @click="emit('explain', row)"
      >
        <i
          class="pi pi-comment text-[10px]"
          aria-hidden="true"
        />
        {{ t('deviation.explain') }}
      </button>
    </li>
    <li
      v-if="!rows.length"
      class="px-4 py-10 text-center text-sm text-rnp-muted"
    >
      {{ emptyText ?? t('rnp.noMetrics') }}
    </li>
  </ul>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { rowStatus } from '~/features/cabinet/utils/overview'
import { STATUS_COLORS } from '~/features/ui/status'
import { formatMetricValue } from '~/utils/metricFormat'
import type { RnpRow, RnpWeek } from '../types'
import { rowTagKeys } from '../utils/tags'
import CompletionBadge from './CompletionBadge.vue'
import EditableValue from './EditableValue.vue'
import PaceHint from './PaceHint.vue'
import { formatPerDay } from '../utils/dailyAverage'

defineProps<{
  week: RnpWeek
  rows: RnpRow[]
  animate?: boolean
  emptyText?: string
}>()
const emit = defineEmits<{ changed: [], explain: [row: RnpRow] }>()
const { t } = useI18n()
</script>
