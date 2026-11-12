<template>
  <button
    type="button"
    class="inline-flex h-7 min-w-20 items-center justify-center gap-1 rounded-full border px-2.5 text-xs font-semibold transition-colors disabled:cursor-default"
    :class="classes"
    :disabled="!clickable || pending"
    :title="title"
    @click="run"
  >
    <i
      v-if="pending"
      class="pi pi-spin pi-spinner text-[10px]"
      aria-hidden="true"
    />
    <i
      v-else
      :class="icon"
      class="text-[10px]"
      aria-hidden="true"
    />
    {{ label }}
  </button>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
import { useApi } from '~/composables/useApi'
import { useAsyncAction } from '~/composables/useAsyncAction'
import { saveCheck } from '../api/actions'
import { nextCheckValue, slotLabel } from '../utils/slots'
import type { ActionSlot, Periodicity } from '../types'

const props = defineProps<{
  functionId: number
  periodicity: Periodicity
  entry: ActionSlot
  canCheck: boolean
}>()
const emit = defineEmits<{ saved: [] }>()

const { t } = useI18n()
const toast = useToast()

const label = computed(() => slotLabel(props.periodicity, props.entry))
const clickable = computed(() => props.canCheck && props.entry.state !== 'future')

const classes = computed(() => ({
  done: 'border-transparent bg-rnp-green-soft text-rnp-green',
  not_done: 'border-transparent bg-rnp-red-soft text-rnp-red',
  missed: 'border-rnp-red/40 bg-rnp-pill text-rnp-red hover:bg-rnp-red-soft',
  pending: 'border-transparent bg-rnp-pill text-rnp-text hover:bg-rnp-accent-soft',
  future: 'border-dashed border-rnp-divider bg-transparent text-rnp-muted/60',
}[props.entry.state]))

const icon = computed(() => ({
  done: 'pi pi-check',
  not_done: 'pi pi-times',
  missed: 'pi pi-exclamation-circle',
  pending: 'pi pi-circle',
  future: 'pi pi-clock',
}[props.entry.state]))

const title = computed(() => {
  const parts = [t(`actions.slot.${props.entry.state}`)]
  if (props.entry.checkedBy) parts.push(props.entry.checkedBy.name)
  if (props.entry.note) parts.push(props.entry.note)
  if (clickable.value) parts.push(t('actions.slot.clickHint'))
  return parts.join(' · ')
})

const { pending, run } = useAsyncAction(async () => {
  await saveCheck(useApi(), props.functionId, props.entry.periodStart, nextCheckValue(props.entry.done))
  emit('saved')
}, {
  rethrow: false,
  canRun: () => clickable.value,
  onError: e => toast.add({ severity: 'error', summary: e instanceof Error ? e.message : String(e), life: 5000 }),
})
</script>
