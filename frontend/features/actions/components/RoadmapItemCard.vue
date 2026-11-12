<template>
  <li class="border-b border-rnp-divider/70 py-3 first:pt-0 last:border-0 last:pb-0">
    <div class="flex flex-wrap items-start justify-between gap-3">
      <div class="min-w-64 flex-1">
        <div class="flex flex-wrap items-center gap-2">
          <span
            class="font-medium"
            :class="item.status === 'cancelled' ? 'text-rnp-muted line-through' : 'text-rnp-text'"
          >{{ item.title }}</span>
          <UiStatusBadge
            v-if="item.isOverdue"
            status="missed"
            :label="t('actions.rm.overdue')"
          />
          <UiStatusBadge
            v-if="item.effect"
            :status="item.effect === 'affected' ? 'done' : 'none'"
            :label="t(`actions.effect.${item.effect}`)"
          />
        </div>
        <div class="mt-1 flex flex-wrap gap-x-3 text-xs text-rnp-muted">
          <NuxtLink
            :to="`/metrics/${item.metric.id}`"
            class="font-medium text-rnp-text underline-offset-2 hover:underline"
          >{{ item.metric.name }}</NuxtLink>
          <span v-if="item.owner">{{ item.owner.name }}</span>
          <span v-if="item.dueDate">{{ t('actions.rm.due', { date: formatDate(item.dueDate) }) }}</span>
        </div>
        <p
          v-if="item.expectedEffect"
          class="mt-1.5 text-sm text-rnp-text/80"
        >
          {{ t('actions.rm.expected') }}: {{ item.expectedEffect }}
        </p>
      </div>

      <div class="flex items-center gap-1">
        <Select
          v-if="item.canChangeStatus"
          :model-value="item.status"
          :options="statuses"
          option-label="label"
          option-value="value"
          size="small"
          :disabled="statusPending"
          @update:model-value="changeStatus"
        />
        <UiStatusBadge
          v-else
          :status="statusTone"
          :label="t(`actions.status.${item.status}`)"
        />
        <Button
          v-if="item.canEdit && item.status === 'done'"
          :label="t('actions.rm.assess')"
          size="small"
          text
          @click="effectDialog = true"
        />
        <Button
          v-if="item.canEdit"
          icon="pi pi-pencil"
          text
          rounded
          size="small"
          severity="secondary"
          :aria-label="t('actions.rm.edit')"
          @click="emit('edit', item)"
        />
        <Button
          :icon="showHistory ? 'pi pi-chevron-up' : 'pi pi-history'"
          text
          rounded
          size="small"
          severity="secondary"
          :aria-label="t('actions.rm.history')"
          @click="showHistory = !showHistory"
        />
      </div>
    </div>

    <ol
      v-if="showHistory"
      class="mt-3 flex flex-col gap-1.5 border-l-2 border-rnp-accent pl-4 text-xs text-rnp-text/80"
    >
      <li
        v-for="event in item.events"
        :key="event.id"
      >
        <span class="text-rnp-muted">{{ formatDateTime(event.createdAt) }}</span>
        <span v-if="event.actor"> · {{ event.actor.name }}</span> — {{ describe(event) }}
      </li>
    </ol>

    <Dialog
      v-model:visible="effectDialog"
      modal
      :header="t('actions.rm.assessTitle')"
      class="w-full max-w-md"
    >
      <p class="mb-3 text-sm text-rnp-muted">
        {{ item.expectedEffect ? `${t('actions.rm.expected')}: ${item.expectedEffect}` : item.title }}
      </p>
      <Textarea
        v-model="effectNote"
        rows="2"
        auto-resize
        fluid
        :placeholder="t('actions.rm.effectNote')"
      />
      <div class="mt-4 flex justify-end gap-2">
        <Button
          :label="t('actions.effect.not_affected')"
          severity="secondary"
          outlined
          :loading="effectPending"
          :disabled="effectPending"
          @click="assess('not_affected')"
        />
        <Button
          :label="t('actions.effect.affected')"
          :loading="effectPending"
          :disabled="effectPending"
          @click="assess('affected')"
        />
      </div>
    </Dialog>
  </li>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import Dialog from 'primevue/dialog'
import Select from 'primevue/select'
import Textarea from 'primevue/textarea'
import { useToast } from 'primevue/usetoast'
import { useApi } from '~/composables/useApi'
import { useAsyncAction } from '~/composables/useAsyncAction'
import UiStatusBadge from '~/features/ui/components/UiStatusBadge.vue'
import type { UiStatus } from '~/features/ui/status'
import { assessEffect, updateRoadmapItem } from '../api/actions'
import { withStatus } from '../utils/slots'
import type { RoadmapEffect, RoadmapEvent, RoadmapItem, RoadmapStatus } from '../types'

const props = defineProps<{ item: RoadmapItem }>()
const emit = defineEmits<{ changed: [], edit: [item: RoadmapItem] }>()
const { t } = useI18n()
const toast = useToast()

const showHistory = ref(false)
/** Read-only status pill tone: done green, cancelled grey, open items yellow. */
const statusTone = computed<UiStatus>(() => (props.item.status === 'done' ? 'done' : props.item.status === 'cancelled' ? 'none' : 'risk'))
const effectDialog = ref(false)
const effectNote = ref('')
const statuses = computed(() => (['planned', 'in_progress', 'done', 'cancelled'] as const).map(value => ({ value, label: t(`actions.status.${value}`) })))
const onError = (e: unknown) => toast.add({ severity: 'error', summary: e instanceof Error ? e.message : String(e), life: 5000 })

const { pending: statusPending, run: changeStatus } = useAsyncAction(async (status: RoadmapStatus) => {
  if (status === props.item.status) return
  await updateRoadmapItem(useApi(), props.item.id, withStatus(props.item, status))
  emit('changed')
}, { rethrow: false, onError })

const { pending: effectPending, run: assess } = useAsyncAction(async (effect: RoadmapEffect) => {
  await assessEffect(useApi(), props.item.id, effect, effectNote.value.trim() || null)
  effectDialog.value = false
  effectNote.value = ''
  emit('changed')
}, { rethrow: false, onError })

function formatDate(date: string): string {
  const [y, m, d] = date.split('-')
  return `${d}.${m}.${y}`
}

function formatDateTime(iso: string): string {
  return new Intl.DateTimeFormat('ru-RU', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' }).format(new Date(iso))
}

function describe(event: RoadmapEvent): string {
  const value = (field: string | null, v: string | null) => {
    if (v === null || v === '') return '—'
    if (field === 'status') return t(`actions.status.${v}`)
    if (field === 'due_date') return formatDate(v)
    return v
  }

  switch (event.type) {
    case 'created':
      return t('actions.history.created')
    case 'status_changed':
      return t('actions.history.status', { from: value('status', event.oldValue), to: value('status', event.newValue) })
    case 'field_changed':
      return t('actions.history.field', { field: t(`actions.history.fields.${event.field}`), from: value(event.field, event.oldValue), to: value(event.field, event.newValue) })
    case 'effect_assessed':
      return `${t(`actions.effect.${event.effect}`)}${event.note ? ` — ${event.note}` : ''}`
  }
}
</script>
