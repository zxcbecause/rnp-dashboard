<template>
  <section class="flex flex-col gap-6">
    <UiPageHeader :subtitle="subtitle">
      <OrgUnitSelect
        :model-value="unitId"
        :tree="orgStore.tree"
        :loading="orgStore.loading"
        @update:model-value="go({ unit: $event, week: weekParam })"
      />
      <WeekNavigator
        :week="rnpStore.week"
        :loading="rnpStore.loading"
        @change="go({ unit: unitId, week: $event })"
      />
      <UiPill
        v-if="unitId && rnpStore.week"
        icon="pi pi-sliders-h"
        :label="t('rnp.view.open')"
        @click="viewDialog = true"
      />
    </UiPageHeader>

    <DataStatusBar class="-mt-3 self-start" />

    <MetricViewDialog
      v-if="unitId"
      v-model:visible="viewDialog"
      :org-unit-id="unitId"
      @saved="reload"
    />

    <DeviationNoteDialog
      v-if="rnpStore.week"
      v-model:visible="explainDialog"
      :row="explainRow"
      :org-unit-id="rnpStore.week.orgUnit.id"
      :week-start="rnpStore.week.weekStart"
      :week-end="rnpStore.week.weekEnd"
      :month-start="rnpStore.week.monthStart"
      @saved="reload"
    />

    <Message
      v-if="rnpStore.error"
      :severity="rnpStore.error === 'not_found' ? 'warn' : 'error'"
    >
      {{ rnpStore.error === 'not_found' ? t('rnp.unitNotAvailable') : rnpStore.error }}
    </Message>
    <p
      v-else-if="!unitId && !orgStore.loading"
      class="text-sm text-rnp-muted"
    >
      {{ t('cabinet.noUnits') }}
    </p>

    <!-- First load: a card with skeleton rows -->
    <div
      v-if="!rnpStore.week && rnpStore.loading"
      class="flex flex-col gap-3 rounded-[var(--rnp-radius-card)] bg-rnp-card p-6"
    >
      <UiSkeleton class="mb-3 h-7 w-64" />
      <UiSkeleton
        v-for="n in 8"
        :key="n"
        class="h-12"
      />
    </div>

    <Transition
      name="rnp-fade"
      mode="out-in"
    >
      <UiCard
        v-if="rnpStore.week"
        :key="`${rnpStore.week.orgUnit.id}-${rnpStore.week.weekStart}`"
        :title="`${t('rnp.weekLabel', { n: rnpStore.week.weekIndex, from: shortDate(rnpStore.week.weekStart), to: shortDate(rnpStore.week.weekEnd) })} · ${monthLabel(rnpStore.week.monthStart)}`"
        :class="[played ? '' : 'rnp-rise', rnpStore.loading ? 'opacity-60 transition-opacity' : 'transition-opacity']"
      >
        <template #actions>
          <button
            v-for="item in statusChips"
            :key="item.status"
            type="button"
            class="inline-flex h-8 items-center gap-2 rounded-full px-3 text-xs font-semibold transition-colors"
            :class="statusFilter === item.status ? 'bg-rnp-text text-rnp-pill' : 'bg-rnp-pill text-rnp-text hover:bg-rnp-accent-soft'"
            :aria-pressed="statusFilter === item.status"
            @click="toggleStatus(item.status)"
          >
            <span
              class="h-2 w-2 rounded-full"
              :style="{ backgroundColor: STATUS_COLORS[item.status].fg }"
              aria-hidden="true"
            />
            {{ t(`ui.status.${item.status}`) }}
            <span class="tabular-nums opacity-70">{{ item.count }}</span>
          </button>
        </template>

        <RnpTable
          :week="rnpStore.week"
          :visible-rows="filteredRows"
          :animate="!played"
          :empty-text="rnpStore.week.rows.length ? t('cabinet.nothingFiltered') : undefined"
          @changed="reload"
          @explain="openExplain"
        />

        <div class="mt-4 flex flex-col gap-1 text-xs text-rnp-muted">
          <p v-if="rnpStore.week.hiddenCount">
            {{ t('rnp.view.hiddenCount', { n: rnpStore.week.hiddenCount }) }}
            <button
              type="button"
              class="font-semibold text-rnp-text underline-offset-2 hover:underline"
              @click="viewDialog = true"
            >
              {{ t('rnp.view.change') }}
            </button>
          </p>
          <p>{{ t('rnp.legend') }}</p>
        </div>
      </UiCard>
    </Transition>
  </section>
</template>

<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import Message from 'primevue/message'
import { navigateTo, useRoute } from '#app'
import { useAuthStore } from '~/features/auth/stores/authStore'
import { rowStatus } from '~/features/cabinet/utils/overview'
import OrgUnitSelect from '~/features/org/components/OrgUnitSelect.vue'
import { useOrgStore } from '~/features/org/stores/orgStore'
import DeviationNoteDialog from '~/features/rnp/components/DeviationNoteDialog.vue'
import MetricViewDialog from '~/features/rnp/components/MetricViewDialog.vue'
import RnpTable from '~/features/rnp/components/RnpTable.vue'
import WeekNavigator from '~/features/rnp/components/WeekNavigator.vue'
import { useRnpStore } from '~/features/rnp/stores/rnpStore'
import type { RnpRow } from '~/features/rnp/types'
import DataStatusBar from '~/features/sync/components/DataStatusBar.vue'
import UiCard from '~/features/ui/components/UiCard.vue'
import UiPageHeader from '~/features/ui/components/UiPageHeader.vue'
import UiPill from '~/features/ui/components/UiPill.vue'
import UiSkeleton from '~/features/ui/components/UiSkeleton.vue'
import { STATUS_COLORS } from '~/features/ui/status'
import type { UiStatus } from '~/features/ui/status'

const STATUS_ORDER: UiStatus[] = ['done', 'risk', 'missed', 'none']

const { t, locale } = useI18n()
const route = useRoute()
const authStore = useAuthStore()
const orgStore = useOrgStore()
const rnpStore = useRnpStore()
const viewDialog = ref(false)

// Deviation explanation of one row.
const explainDialog = ref(false)
const explainRow = ref<RnpRow | null>(null)
function openExplain(row: RnpRow) {
  explainRow.value = row
  explainDialog.value = true
}

const weekParam = computed(() => (typeof route.query.week === 'string' ? route.query.week : null))
const unitId = computed<number | null>(() => {
  const fromQuery = Number(route.query.unit)
  if (Number.isInteger(fromQuery) && fromQuery > 0) return fromQuery
  return authStore.me?.rootUnitIds[0] ?? orgStore.tree[0]?.id ?? null
})

const subtitle = computed(() => (rnpStore.week ? `${rnpStore.week.orgUnit.name} · ${t('rnpWeek.subtitle')}` : t('rnpWeek.subtitle')))

/** The row cascade plays once per visit; later reloads (week, unit, saved value) only crossfade. */
const played = ref(false)
watch(() => rnpStore.week, (value) => {
  if (value && !played.value) setTimeout(() => (played.value = true), 900)
})

// Status chips: counts per status, click to show only that status.
const statusFilter = ref<UiStatus | null>(null)
watch(unitId, () => (statusFilter.value = null))

const statusChips = computed(() => {
  const counts: Record<UiStatus, number> = { done: 0, risk: 0, missed: 0, none: 0 }
  for (const row of rnpStore.week?.rows ?? []) counts[rowStatus(row)]++
  return STATUS_ORDER.filter(status => counts[status] > 0).map(status => ({ status, count: counts[status] }))
})

const filteredRows = computed(() => (rnpStore.week?.rows ?? []).filter(row =>
  statusFilter.value === null || rowStatus(row) === statusFilter.value,
))

function toggleStatus(status: UiStatus) {
  statusFilter.value = statusFilter.value === status ? null : status
}

function shortDate(date: string): string {
  const [, m, d] = date.split('-')
  return `${d}.${m}`
}

function monthLabel(date: string): string {
  const label = new Intl.DateTimeFormat(locale.value, { month: 'long', year: 'numeric' }).format(new Date(`${date}T12:00:00`))
  return label.charAt(0).toUpperCase() + label.slice(1)
}

function go(query: { unit: number | null, week: string | null }) {
  void navigateTo({
    path: '/rnp-week',
    query: {
      ...(query.unit ? { unit: String(query.unit) } : {}),
      ...(query.week ? { week: query.week } : {}),
    },
  })
}

function reload() {
  if (unitId.value) void rnpStore.load(unitId.value, rnpStore.week?.weekStart ?? weekParam.value)
}

watch([unitId, weekParam], ([unit, week]) => {
  if (unit) void rnpStore.load(unit, week)
}, { immediate: true })

onMounted(() => {
  if (!orgStore.units.length) void orgStore.load()
})
</script>
