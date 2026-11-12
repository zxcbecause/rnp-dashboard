<template>
  <Dialog
    :visible="visible"
    modal
    :header="task ? t('sprints.edit') : t('sprints.create')"
    class="w-full max-w-xl"
    @update:visible="emit('update:visible', $event)"
    @show="reset"
  >
    <form
      class="flex flex-col gap-4"
      @submit.prevent="save"
    >
      <Field :label="t('sprints.title')">
        <InputText
          v-model="fields.title"
          fluid
        />
      </Field>
      <div class="grid grid-cols-3 gap-3">
        <Field :label="t('sprints.priority')">
          <Select
            v-model="fields.priority"
            :options="priorities"
            fluid
          />
        </Field>
        <Field :label="t('sprints.tag')">
          <InputText
            v-model="tag"
            fluid
          />
        </Field>
        <Field :label="t('sprints.progress')">
          <InputNumber
            v-model="fields.progressPct"
            :min="0"
            :max="100"
            suffix=" %"
            fluid
          />
        </Field>
      </div>
      <Field :label="t('actions.bf.metric')">
        <Select
          v-model="fields.metricId"
          :options="metrics"
          option-label="name"
          option-value="id"
          show-clear
          :placeholder="t('actions.bf.noMetric')"
          fluid
        />
      </Field>
      <Field :label="t('sprints.resultNote')">
        <Textarea
          v-model="resultNote"
          rows="2"
          auto-resize
          fluid
        />
      </Field>
      <Field :label="t('sprints.nextStep')">
        <Textarea
          v-model="nextStep"
          rows="2"
          auto-resize
          fluid
        />
      </Field>
      <Field :label="t('sprints.link')">
        <InputText
          v-model="link"
          placeholder="https://…"
          fluid
        />
      </Field>
      <Message
        v-if="error"
        severity="error"
        size="small"
      >
        {{ error }}
      </Message>
      <div class="flex justify-between gap-2">
        <Button
          v-if="task"
          type="button"
          :label="t('sprints.delete')"
          text
          severity="danger"
          :disabled="pending"
          @click="remove"
        />
        <span v-else />
        <div class="flex gap-2">
          <Button
            type="button"
            :label="t('common.cancel')"
            text
            severity="secondary"
            :disabled="pending"
            @click="emit('update:visible', false)"
          />
          <Button
            type="submit"
            :label="t('common.save')"
            :loading="pending"
            :disabled="pending || !canSave"
          />
        </div>
      </div>
    </form>
  </Dialog>
</template>

<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import Dialog from 'primevue/dialog'
import InputNumber from 'primevue/inputnumber'
import InputText from 'primevue/inputtext'
import Message from 'primevue/message'
import Select from 'primevue/select'
import Textarea from 'primevue/textarea'
import { useApi } from '~/composables/useApi'
import { useAsyncAction } from '~/composables/useAsyncAction'
import Field from '~/features/actions/components/FormField.vue'
import type { MetricRef } from '~/features/actions/types'
import { createSprintTask, deleteSprintTask, updateSprintTask } from '../api/sprints'
import type { SprintTask, SprintTaskInput } from '../types'

const props = defineProps<{
  visible: boolean
  orgUnitId: number
  weekStart: string
  task: SprintTask | null
  metrics: MetricRef[]
}>()
const emit = defineEmits<{ 'update:visible': [boolean], 'saved': [] }>()
const { t } = useI18n()

const priorities = ['P1', 'P2', 'P3', 'P4', 'P5']
const empty = (): SprintTaskInput => ({ title: '', priority: 'P3', tag: null, metricId: null, progressPct: 0, resultNote: null, link: null, nextStep: null })
const fields = reactive<SprintTaskInput>(empty())
const error = ref<string | null>(null)
const canSave = computed(() => fields.title.trim() !== '')

const nullable = (key: 'tag' | 'resultNote' | 'link' | 'nextStep') => computed({
  get: () => fields[key] ?? '',
  set: (value: string) => {
    fields[key] = value || null
  },
})
const tag = nullable('tag')
const resultNote = nullable('resultNote')
const link = nullable('link')
const nextStep = nullable('nextStep')

function reset() {
  error.value = null
  Object.assign(fields, empty(), props.task
    ? { title: props.task.title, priority: props.task.priority, tag: props.task.tag, metricId: props.task.metric?.id ?? null, progressPct: props.task.progressPct, resultNote: props.task.resultNote, link: props.task.link, nextStep: props.task.nextStep }
    : {})
}

const done = () => {
  emit('saved')
  emit('update:visible', false)
}
const onError = (e: unknown) => {
  error.value = e instanceof Error ? e.message : String(e)
}

const { pending, run: save } = useAsyncAction(async () => {
  const body = { ...fields, title: fields.title.trim(), progressPct: fields.progressPct ?? 0 }
  if (props.task) await updateSprintTask(useApi(), props.task.id, body)
  else await createSprintTask(useApi(), props.orgUnitId, { ...body, weekStart: props.weekStart })
  done()
}, { rethrow: false, onError })

const { run: remove } = useAsyncAction(async () => {
  if (!props.task || !window.confirm(t('sprints.confirmDelete'))) return
  await deleteSprintTask(useApi(), props.task.id)
  done()
}, { rethrow: false, onError })
</script>
