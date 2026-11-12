<template>
  <Dialog
    :visible="visible"
    modal
    :header="item ? t('actions.rm.edit') : t('actions.rm.create')"
    class="w-full max-w-lg"
    @update:visible="emit('update:visible', $event)"
    @show="reset"
  >
    <form
      class="flex flex-col gap-4"
      @submit.prevent="save"
    >
      <Field
        v-if="!item"
        :label="t('actions.rm.metric')"
      >
        <Select
          v-model="metricId"
          :options="metrics"
          option-label="name"
          option-value="id"
          fluid
        />
      </Field>
      <Field :label="t('actions.rm.title')">
        <InputText
          v-model="form.title"
          fluid
        />
      </Field>
      <div class="grid grid-cols-2 gap-3">
        <Field :label="t('actions.owner')">
          <Select
            v-model="form.ownerUserId"
            :options="people"
            option-label="name"
            option-value="id"
            show-clear
            fluid
          />
        </Field>
        <Field :label="t('actions.rm.dueDate')">
          <InputText
            v-model="dueDate"
            type="date"
            fluid
          />
        </Field>
      </div>
      <Field :label="t('actions.rm.status')">
        <Select
          v-model="form.status"
          :options="statuses"
          option-label="label"
          option-value="value"
          fluid
        />
      </Field>
      <Field :label="t('actions.rm.expectedEffect')">
        <Textarea
          v-model="expectedEffect"
          rows="2"
          auto-resize
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
      <div class="flex justify-end gap-2">
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
          :disabled="pending || !form.title.trim() || (!item && !metricId)"
        />
      </div>
    </form>
  </Dialog>
</template>

<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import Dialog from 'primevue/dialog'
import InputText from 'primevue/inputtext'
import Message from 'primevue/message'
import Select from 'primevue/select'
import Textarea from 'primevue/textarea'
import { useApi } from '~/composables/useApi'
import { useAsyncAction } from '~/composables/useAsyncAction'
import type { UserRef } from '~/features/org/types'
import { createRoadmapItem, updateRoadmapItem } from '../api/actions'
import type { MetricRef, RoadmapItem, RoadmapItemInput } from '../types'
import Field from './FormField.vue'

const props = defineProps<{
  visible: boolean
  item: RoadmapItem | null
  people: UserRef[]
  metrics: MetricRef[]
}>()
const emit = defineEmits<{ 'update:visible': [boolean], 'saved': [] }>()
const { t } = useI18n()

const form = reactive<RoadmapItemInput>({ title: '', ownerUserId: null, dueDate: null, status: 'planned', expectedEffect: null })
const metricId = ref<number | null>(null)
const error = ref<string | null>(null)
const dueDate = computed({
  get: () => form.dueDate ?? '',
  set: (v: string) => {
    form.dueDate = v || null
  },
})
const expectedEffect = computed({
  get: () => form.expectedEffect ?? '',
  set: (v: string) => {
    form.expectedEffect = v || null
  },
})
const statuses = computed(() => (['planned', 'in_progress', 'done', 'cancelled'] as const).map(value => ({ value, label: t(`actions.status.${value}`) })))

function reset() {
  error.value = null
  metricId.value = props.metrics[0]?.id ?? null
  Object.assign(form, props.item
    ? { title: props.item.title, ownerUserId: props.item.owner?.id ?? null, dueDate: props.item.dueDate, status: props.item.status, expectedEffect: props.item.expectedEffect }
    : { title: '', ownerUserId: null, dueDate: null, status: 'planned', expectedEffect: null })
}

const { pending, run: save } = useAsyncAction(async () => {
  error.value = null
  const body = { ...form, title: form.title.trim() }
  if (props.item) await updateRoadmapItem(useApi(), props.item.id, body)
  else if (metricId.value) await createRoadmapItem(useApi(), metricId.value, body)
  emit('saved')
  emit('update:visible', false)
}, { rethrow: false, onError: (e) => { error.value = e instanceof Error ? e.message : String(e) } })
</script>
