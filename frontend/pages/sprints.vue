<template>
  <section class="flex flex-col gap-6">
    <UiPageHeader :subtitle="t('sprints.subtitle')">
      <OrgUnitSelect
        :model-value="unitId"
        :tree="orgStore.tree"
        :loading="orgStore.loading"
        @update:model-value="go($event, weekParam)"
      />
      <WeekNavigator
        :week="navWeek"
        :loading="loading"
        @change="go(unitId, $event)"
      />
    </UiPageHeader>

    <Message
      v-if="error"
      :severity="error === 'not_found' ? 'warn' : 'error'"
    >
      {{ error === 'not_found' ? t('rnp.unitNotAvailable') : error }}
    </Message>

    <!-- First load: a card with skeleton rows -->
    <div
      v-if="!week && loading"
      class="flex flex-col gap-3 rounded-[var(--rnp-radius-card)] bg-rnp-card p-6"
    >
      <UiSkeleton class="mb-3 h-7 w-64" />
      <UiSkeleton
        v-for="n in 6"
        :key="n"
        class="h-14"
      />
    </div>

    <Transition
      name="rnp-fade"
      mode="out-in"
    >
      <UiCard
        v-if="week"
        :key="`${week.orgUnitId}-${week.weekStart}`"
        :title="t('sprints.heading', { from: short(week.weekStart), to: short(week.weekEnd) })"
        class="transition-opacity"
        :class="[played ? '' : 'rnp-rise', loading ? 'opacity-60' : '']"
      >
        <template #actions>
          <UiPill
            icon="pi pi-replay"
            :label="t('sprints.recurring.open')"
            @click="recurringDialog = true"
          />
          <UiPill
            v-if="week.canEdit"
            :icon="pulling ? 'pi pi-spin pi-spinner' : 'pi pi-download'"
            :label="t('sprints.pull')"
            :disabled="pulling"
            @click="pull"
          />
          <UiPill
            v-if="week.canEdit && week.carryableCount"
            :icon="carrying ? 'pi pi-spin pi-spinner' : 'pi pi-arrow-right'"
            :label="t('sprints.carry', { n: week.carryableCount })"
            :disabled="carrying"
            @click="carry"
          />
          <UiPill
            v-if="week.canEdit"
            icon="pi pi-plus"
            :label="t('sprints.create')"
            active
            @click="open(null)"
          />
        </template>

        <!-- Week summary: share of finished tasks and the average progress -->
        <div
          v-if="week.tasks.length"
          class="mb-5 flex flex-wrap items-center gap-4"
        >
          <UiProgressRing
            :value="donePct"
            :status="donePct === 100 ? 'done' : 'risk'"
            :size="56"
          />
          <div>
            <div class="text-sm font-semibold text-rnp-text">
              {{ t('sprints.summary', { done: week.doneCount, total: week.tasks.length }) }}
            </div>
            <div
              v-if="week.avgProgressPct !== null"
              class="text-xs text-rnp-muted"
            >
              {{ t('sprints.avg', { pct: week.avgProgressPct }) }}
            </div>
          </div>
        </div>

        <!-- Phones: every row becomes a block (no horizontal scrolling inside the card). -->
        <div class="md:-mx-1 md:overflow-x-auto">
          <table class="w-full text-sm max-md:block md:min-w-[760px]">
            <thead class="max-md:hidden">
              <tr class="border-b border-rnp-divider text-left text-rnp-text">
                <th class="w-16 px-2 pb-3 font-semibold">
                  {{ t('sprints.priority') }}
                </th>
                <th class="px-2 pb-3 font-semibold">
                  {{ t('sprints.title') }}
                </th>
                <th class="w-52 px-2 pb-3 font-semibold">
                  {{ t('sprints.progress') }}
                </th>
                <th class="px-2 pb-3 font-semibold">
                  {{ t('sprints.resultNote') }}
                </th>
                <th class="px-2 pb-3 font-semibold">
                  {{ t('sprints.nextStep') }}
                </th>
              </tr>
            </thead>
            <tbody class="max-md:block">
              <tr
                v-for="(task, index) in week.tasks"
                :key="task.id"
                class="border-b border-rnp-divider/70 align-top transition-colors last:border-0 hover:bg-rnp-accent-soft/40 max-md:flex max-md:flex-col max-md:gap-2 max-md:py-3"
                :class="played ? '' : 'rnp-rise'"
                :style="{ '--i': index < 12 ? index + 1 : 0 }"
              >
                <td class="px-2 py-3 max-md:p-0">
                  <span
                    class="inline-flex h-6 items-center rounded-full px-2.5 text-xs font-bold"
                    :style="priorityStyle(task.priority)"
                  >{{ task.priority }}</span>
                </td>
                <td class="px-2 py-3 max-md:p-0">
                  <button
                    type="button"
                    class="text-left font-medium underline-offset-2 hover:underline disabled:no-underline"
                    :class="task.progressPct === 100 ? 'text-rnp-muted line-through decoration-rnp-muted/50' : 'text-rnp-text'"
                    :disabled="!week.canEdit"
                    @click="open(task)"
                  >
                    {{ task.title }}
                  </button>
                  <div class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-rnp-muted">
                    <span
                      v-if="task.tag"
                      class="rounded-full bg-rnp-bg px-2 py-px"
                    >{{ task.tag }}</span>
                    <NuxtLink
                      v-if="task.metric"
                      :to="`/metrics/${task.metric.id}`"
                      class="font-medium text-rnp-text underline-offset-2 hover:underline"
                    >{{ task.metric.name }}</NuxtLink>
                    <a
                      v-if="task.link"
                      :href="task.link"
                      target="_blank"
                      rel="noopener noreferrer"
                      class="inline-flex items-center gap-1 font-medium text-rnp-text underline-offset-2 hover:underline"
                    ><i
                      class="pi pi-external-link text-[10px]"
                      aria-hidden="true"
                    />{{ t('sprints.link') }}</a>
                    <span v-if="task.carriedFromWeek">{{ t('sprints.carriedFrom', { week: short(task.carriedFromWeek) }) }}</span>
                  </div>
                </td>
                <td class="px-2 py-3 max-md:p-0">
                  <div class="flex items-center gap-2">
                    <div
                      class="h-2 flex-1 overflow-hidden rounded-full bg-rnp-divider"
                      role="progressbar"
                      :aria-valuenow="task.progressPct"
                      aria-valuemin="0"
                      aria-valuemax="100"
                    >
                      <div
                        class="h-full rounded-full transition-[width] duration-500"
                        :class="task.progressPct === 100 ? 'bg-rnp-green' : 'bg-rnp-accent'"
                        :style="{ width: `${task.progressPct}%` }"
                      />
                    </div>
                    <span class="w-9 text-right text-xs font-semibold tabular-nums text-rnp-text">{{ task.progressPct }}%</span>
                  </div>
                  <div
                    v-if="week.canEdit"
                    class="mt-1.5 flex gap-1"
                  >
                    <button
                      v-for="step in [0, 50, 100]"
                      :key="step"
                      type="button"
                      class="h-6 rounded-full px-2 text-[11px] font-semibold transition-colors disabled:cursor-default"
                      :class="task.progressPct === step ? 'bg-rnp-text text-rnp-pill' : 'bg-rnp-pill text-rnp-muted hover:bg-rnp-accent-soft hover:text-rnp-text disabled:opacity-40'"
                      :disabled="task.progressPct === step || progressPending"
                      @click="setProgress(task, step)"
                    >
                      {{ step }}%
                    </button>
                  </div>
                </td>
                <td
                  class="px-2 py-3 text-rnp-text/80 max-md:p-0"
                  :class="task.resultNote ? '' : 'max-md:hidden'"
                >
                  <span class="text-xs text-rnp-muted md:hidden">{{ t('sprints.resultNote') }}: </span>{{ task.resultNote }}
                </td>
                <td
                  class="px-2 py-3 text-rnp-text/80 max-md:p-0"
                  :class="task.nextStep ? '' : 'max-md:hidden'"
                >
                  <span class="text-xs text-rnp-muted md:hidden">{{ t('sprints.nextStep') }}: </span>{{ task.nextStep }}
                </td>
              </tr>
              <tr
                v-if="!week.tasks.length"
                class="max-md:block"
              >
                <td
                  colspan="5"
                  class="px-2 py-10 text-center text-rnp-muted max-md:block"
                >
                  {{ t('sprints.empty') }}
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </UiCard>
    </Transition>

    <template v-if="week">
      <SprintTaskDialog
        v-model:visible="taskDialog"
        :org-unit-id="week.orgUnitId"
        :week-start="week.weekStart"
        :task="editing"
        :metrics="week.metrics"
        @saved="reload"
      />
      <RecurringTasksDialog
        v-model:visible="recurringDialog"
        :org-unit-id="week.orgUnitId"
        :tasks="week.recurring"
        :can-manage="week.canManageRecurring"
        @saved="reload"
      />
    </template>
  </section>
</template>

<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import Message from 'primevue/message'
import { useToast } from 'primevue/usetoast'
import { navigateTo, useRoute } from '#app'
import { useApi } from '~/composables/useApi'
import { useAsyncAction } from '~/composables/useAsyncAction'
import { createLatestRequestScope } from '~/composables/useLatestRequest'
import { useAuthStore } from '~/features/auth/stores/authStore'
import OrgUnitSelect from '~/features/org/components/OrgUnitSelect.vue'
import { useOrgStore } from '~/features/org/stores/orgStore'
import { carryOver, fetchSprintWeek, pullRecurring, updateSprintTask, withProgress } from '~/features/sprints/api/sprints'
import RecurringTasksDialog from '~/features/sprints/components/RecurringTasksDialog.vue'
import SprintTaskDialog from '~/features/sprints/components/SprintTaskDialog.vue'
import type { SprintPriority, SprintTask, SprintWeek } from '~/features/sprints/types'
import WeekNavigator from '~/features/rnp/components/WeekNavigator.vue'
import UiCard from '~/features/ui/components/UiCard.vue'
import UiPageHeader from '~/features/ui/components/UiPageHeader.vue'
import UiPill from '~/features/ui/components/UiPill.vue'
import UiProgressRing from '~/features/ui/components/UiProgressRing.vue'
import UiSkeleton from '~/features/ui/components/UiSkeleton.vue'
import { STATUS_COLORS } from '~/features/ui/status'
import { NotFoundError } from '~/types/api-errors'

const { t } = useI18n()
const toast = useToast()
const route = useRoute()
const authStore = useAuthStore()
const orgStore = useOrgStore()

const week = ref<SprintWeek | null>(null)
const loading = ref(false)
const error = ref<string | null>(null)
const taskDialog = ref(false)
const recurringDialog = ref(false)
const editing = ref<SprintTask | null>(null)
const scope = createLatestRequestScope()

const weekParam = computed(() => (typeof route.query.week === 'string' ? route.query.week : null))
const unitId = computed<number | null>(() => {
  const fromQuery = Number(route.query.unit)
  if (Number.isInteger(fromQuery) && fromQuery > 0) return fromQuery
  return authStore.me?.rootUnitIds[0] ?? orgStore.tree[0]?.id ?? null
})

function go(unit: number | null, weekStart: string | null) {
  void navigateTo({ path: '/sprints', query: { ...(unit ? { unit: String(unit) } : {}), ...(weekStart ? { week: weekStart } : {}) } })
}

/** Sprint weeks are Mon–Sun; "current" is derived from today's date. */
const navWeek = computed(() => {
  const w = week.value
  if (!w) return null
  const today = isoToday()
  return { ...w, monthStart: w.weekStart, isCurrentWeek: w.weekStart <= today && today <= w.weekEnd }
})

const donePct = computed(() => (week.value?.tasks.length ? (week.value.doneCount / week.value.tasks.length) * 100 : null))

/** The cascade plays once per visit; later reloads only crossfade. */
const played = ref(false)
watch(week, (value) => {
  if (value && !played.value) setTimeout(() => (played.value = true), 900)
})

/** P1 red, P2 yellow, the rest neutral. */
function priorityStyle(priority: SprintPriority): Record<string, string> {
  const colors = STATUS_COLORS[priority === 'P1' ? 'missed' : priority === 'P2' ? 'risk' : 'none']
  return { color: colors.fg, backgroundColor: colors.bg }
}

function isoToday(): string {
  const now = new Date()
  return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`
}

function short(date: string): string {
  const [, m, d] = date.split('-')
  return `${d}.${m}`
}

async function load(unit: number, weekStart: string | null) {
  const isLatest = scope.begin()
  loading.value = true
  error.value = null
  try {
    const data = await fetchSprintWeek(useApi(), unit, weekStart)
    if (!isLatest()) return
    week.value = data
  }
  catch (e) {
    if (!isLatest()) return
    week.value = null
    error.value = e instanceof NotFoundError ? 'not_found' : (e instanceof Error ? e.message : String(e))
  }
  finally {
    if (isLatest()) loading.value = false
  }
}

const reload = () => (unitId.value ? load(unitId.value, week.value?.weekStart ?? weekParam.value) : undefined)

function open(task: SprintTask | null) {
  editing.value = task
  taskDialog.value = true
}

const onError = (e: unknown) => toast.add({ severity: 'error', summary: e instanceof Error ? e.message : String(e), life: 5000 })
const notify = (key: string, count: number) => toast.add({ severity: count ? 'success' : 'info', summary: t(key, { n: count }), life: 3000 })

const { pending: progressPending, run: setProgress } = useAsyncAction(async (task: SprintTask, pct: number) => {
  await updateSprintTask(useApi(), task.id, withProgress(task, pct))
  await reload()
}, { rethrow: false, onError })

const { pending: carrying, run: carry } = useAsyncAction(async () => {
  if (!week.value) return
  const { count } = await carryOver(useApi(), week.value.orgUnitId, week.value.weekStart)
  notify('sprints.carried', count)
  await reload()
}, { rethrow: false, onError })

const { pending: pulling, run: pull } = useAsyncAction(async () => {
  if (!week.value) return
  const { count } = await pullRecurring(useApi(), week.value.orgUnitId, week.value.weekStart)
  notify('sprints.pulled', count)
  await reload()
}, { rethrow: false, onError })

watch([unitId, weekParam], ([unit, weekStart]) => {
  if (unit) void load(unit, weekStart)
}, { immediate: true })

onMounted(() => {
  if (!orgStore.units.length) void orgStore.load()
})
</script>
