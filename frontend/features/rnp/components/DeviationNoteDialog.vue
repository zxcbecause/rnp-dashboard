<template>
  <Dialog
    :visible="visible"
    modal
    :header="t('deviation.title')"
    class="w-full max-w-xl"
    @update:visible="emit('update:visible', $event)"
    @show="reset"
  >
    <div
      v-if="row"
      class="flex flex-col gap-4"
    >
      <div class="flex flex-wrap items-center justify-between gap-2">
        <div>
          <div class="font-semibold text-rnp-text">
            {{ row.name }}
          </div>
          <div class="text-xs text-rnp-muted">
            {{ periodLabel }}<template v-if="cell?.completionPct">
              · {{ t('deviation.completion', { pct: formatPct(cell.completionPct) }) }}
            </template>
          </div>
        </div>
        <UiPill
          :icon="drafting ? 'pi pi-spin pi-spinner' : 'pi pi-sparkles'"
          :label="t('deviation.aiDraft')"
          :disabled="drafting || saving"
          @click="draft"
        />
      </div>

      <p
        v-if="drafted"
        class="rounded-xl bg-rnp-accent-soft px-3 py-2 text-xs text-rnp-text"
      >
        {{ t('deviation.draftHint') }}
      </p>

      <Field :label="t('deviation.reason')">
        <Textarea
          v-model="reason"
          rows="3"
          auto-resize
          fluid
          :placeholder="t('deviation.reasonPlaceholder')"
        />
      </Field>
      <Field :label="t('deviation.action')">
        <Textarea
          v-model="action"
          rows="2"
          auto-resize
          fluid
          :placeholder="t('deviation.actionPlaceholder')"
        />
      </Field>

      <!-- Turn the action into a tracked task right away -->
      <div class="flex flex-wrap items-center gap-2 text-xs text-rnp-muted">
        <span>{{ t('deviation.toTask') }}</span>
        <UiPill
          icon="pi pi-map"
          :label="t('deviation.toRoadmap')"
          :disabled="!action.trim() || creating"
          @click="toRoadmap"
        />
        <UiPill
          icon="pi pi-list-check"
          :label="t('deviation.toSprint')"
          :disabled="!action.trim() || creating"
          @click="toSprint"
        />
      </div>

      <Message
        v-if="error"
        severity="error"
        size="small"
      >
        {{ error }}
      </Message>

      <div class="flex flex-wrap justify-between gap-2">
        <Button
          v-if="row.note"
          :label="t('deviation.remove')"
          text
          severity="danger"
          :disabled="saving"
          @click="remove"
        />
        <span v-else />
        <div class="flex gap-2">
          <Button
            :label="t('common.cancel')"
            text
            severity="secondary"
            :disabled="saving"
            @click="emit('update:visible', false)"
          />
          <Button
            :label="t('common.save')"
            :loading="saving"
            :disabled="saving || !reason.trim()"
            @click="save"
          />
        </div>
      </div>
    </div>
  </Dialog>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import Dialog from 'primevue/dialog'
import Message from 'primevue/message'
import Textarea from 'primevue/textarea'
import { useToast } from 'primevue/usetoast'
import { useApi } from '~/composables/useApi'
import { useAsyncAction } from '~/composables/useAsyncAction'
import { createRoadmapItem } from '~/features/actions/api/actions'
import Field from '~/features/actions/components/FormField.vue'
import { createSprintTask } from '~/features/sprints/api/sprints'
import UiPill from '~/features/ui/components/UiPill.vue'
import { formatPct } from '~/utils/metricFormat'
import { draftDeviationNote, saveDeviationNote } from '../api/rnp'
import type { RnpRow } from '../types'

/** Why a metric deviated and what we do — with an AI draft and one-click tasks from the action. */
const props = defineProps<{
  visible: boolean
  row: RnpRow | null
  orgUnitId: number
  weekStart: string
  weekEnd: string
  monthStart: string
}>()
const emit = defineEmits<{ 'update:visible': [boolean], 'saved': [] }>()

const { t, locale } = useI18n()
const toast = useToast()
const reason = ref('')
const action = ref('')
const drafted = ref(false)
const error = ref<string | null>(null)

const monthly = computed(() => props.row?.granularity === 'month')
const period = computed(() => (monthly.value
  ? { periodType: 'month' as const, periodStart: props.monthStart }
  : { periodType: 'week' as const, periodStart: props.weekStart }))
const cell = computed(() => (props.row ? (props.row.week ?? props.row.month) : null))

const periodLabel = computed(() => {
  if (monthly.value) {
    const label = new Intl.DateTimeFormat(locale.value, { month: 'long', year: 'numeric' }).format(new Date(`${props.monthStart}T12:00:00`))
    return label.charAt(0).toUpperCase() + label.slice(1)
  }
  return t('deviation.week', { from: short(props.weekStart), to: short(props.weekEnd) })
})

function short(date: string): string {
  const [, m, d] = date.split('-')
  return `${d}.${m}`
}

function reset() {
  reason.value = props.row?.note?.reason ?? ''
  action.value = props.row?.note?.action ?? ''
  drafted.value = false
  error.value = null
}

const message = (e: unknown) => (e instanceof Error ? e.message : String(e))

const { pending: drafting, run: draft } = useAsyncAction(async () => {
  if (!props.row) return
  error.value = null
  const result = await draftDeviationNote(useApi(), props.row.metricId, period.value)
  reason.value = result.reason
  action.value = result.action
  drafted.value = true
}, { rethrow: false, onError: (e) => { error.value = message(e) } })

const { pending: saving, run: persist } = useAsyncAction(async (text: string, next: string) => {
  if (!props.row) return
  error.value = null
  await saveDeviationNote(useApi(), props.row.metricId, { ...period.value, reason: text, action: next })
  emit('saved')
  emit('update:visible', false)
}, { rethrow: false, onError: (e) => { error.value = message(e) } })

const save = () => persist(reason.value, action.value)
const remove = () => persist('', '')

/** Monday of the current week: sprint weeks are Mon–Sun. */
function currentMonday(): string {
  const now = new Date()
  const monday = new Date(now.getFullYear(), now.getMonth(), now.getDate() - ((now.getDay() + 6) % 7))
  return `${monday.getFullYear()}-${String(monday.getMonth() + 1).padStart(2, '0')}-${String(monday.getDate()).padStart(2, '0')}`
}

function monthEnd(): string {
  const [y, m] = props.monthStart.split('-').map(Number) as [number, number]
  const last = new Date(y, m, 0).getDate()
  return `${props.monthStart.slice(0, 8)}${String(last).padStart(2, '0')}`
}

const { pending: creating, run: createTask } = useAsyncAction(async (kind: 'roadmap' | 'sprint') => {
  if (!props.row) return
  error.value = null
  const title = action.value.trim().slice(0, 250)
  if (kind === 'roadmap') {
    await createRoadmapItem(useApi(), props.row.metricId, { title, ownerUserId: null, dueDate: monthEnd(), status: 'planned', expectedEffect: null })
  }
  else {
    await createSprintTask(useApi(), props.orgUnitId, {
      title, priority: 'P2', tag: t('deviation.sprintTag'), metricId: props.row.metricId, progressPct: 0,
      resultNote: null, link: null, nextStep: null, weekStart: currentMonday(),
    })
  }
  toast.add({ severity: 'success', summary: t(kind === 'roadmap' ? 'deviation.addedRoadmap' : 'deviation.addedSprint'), life: 3000 })
}, { rethrow: false, onError: (e) => { error.value = message(e) } })

const toRoadmap = () => createTask('roadmap')
const toSprint = () => createTask('sprint')
</script>
