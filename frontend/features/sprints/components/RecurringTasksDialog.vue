<template>
  <Dialog
    :visible="visible"
    modal
    :header="t('sprints.recurring.title')"
    class="w-full max-w-lg"
    @update:visible="emit('update:visible', $event)"
  >
    <p class="mb-3 text-sm text-rnp-muted">
      {{ t('sprints.recurring.hint') }}
    </p>
    <ul class="mb-4 flex flex-col gap-1">
      <li
        v-for="task in tasks"
        :key="task.id"
        class="flex items-center gap-2 rounded-lg border border-rnp-divider px-2 py-1.5 text-sm"
      >
        <ToggleSwitch
          :model-value="task.isActive"
          :disabled="!canManage || pending"
          @update:model-value="toggle(task, $event)"
        />
        <span
          class="flex-1"
          :class="task.isActive ? 'text-rnp-text' : 'text-rnp-muted/60'"
        >{{ task.title }}</span>
        <span class="text-xs text-rnp-muted">{{ t(`actions.periodicity.${task.periodicity}`) }}</span>
      </li>
      <li
        v-if="!tasks.length"
        class="text-sm text-rnp-muted"
      >
        {{ t('sprints.recurring.empty') }}
      </li>
    </ul>
    <form
      v-if="canManage"
      class="flex gap-2"
      @submit.prevent="add"
    >
      <InputText
        v-model="title"
        :placeholder="t('sprints.recurring.new')"
        class="flex-1"
      />
      <Select
        v-model="periodicity"
        :options="periodicities"
        option-label="label"
        option-value="value"
        class="w-44"
      />
      <Button
        type="submit"
        icon="pi pi-plus"
        :loading="pending"
        :disabled="pending || !title.trim()"
        :aria-label="t('sprints.recurring.add')"
      />
    </form>
    <Message
      v-if="error"
      severity="error"
      size="small"
      class="mt-3"
    >
      {{ error }}
    </Message>
  </Dialog>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import Dialog from 'primevue/dialog'
import InputText from 'primevue/inputtext'
import Message from 'primevue/message'
import Select from 'primevue/select'
import ToggleSwitch from 'primevue/toggleswitch'
import { useApi } from '~/composables/useApi'
import { useAsyncAction } from '~/composables/useAsyncAction'
import type { Periodicity } from '~/features/actions/types'
import { createRecurringTask, updateRecurringTask } from '../api/sprints'
import type { RecurringTask } from '../types'

const props = defineProps<{
  visible: boolean
  orgUnitId: number
  tasks: RecurringTask[]
  canManage: boolean
}>()
const emit = defineEmits<{ 'update:visible': [boolean], 'saved': [] }>()
const { t } = useI18n()

const title = ref('')
const periodicity = ref<Periodicity>('weekly')
const error = ref<string | null>(null)
const periodicities = computed(() => (['weekly', 'monthly', 'quarterly'] as const).map(value => ({ value, label: t(`actions.periodicity.${value}`) })))
const onError = (e: unknown) => {
  error.value = e instanceof Error ? e.message : String(e)
}

const { pending: adding, run: add } = useAsyncAction(async () => {
  error.value = null
  await createRecurringTask(useApi(), props.orgUnitId, { title: title.value.trim(), periodicity: periodicity.value, isActive: true })
  title.value = ''
  emit('saved')
}, { rethrow: false, onError })

const { pending: toggling, run: toggle } = useAsyncAction(async (task: RecurringTask, isActive: boolean) => {
  error.value = null
  await updateRecurringTask(useApi(), task.id, { title: task.title, periodicity: task.periodicity, isActive })
  emit('saved')
}, { rethrow: false, onError })

const pending = computed(() => adding.value || toggling.value)
</script>
