<template>
  <Dialog
    :visible="visible"
    modal
    :header="fn ? t('actions.bf.edit') : t('actions.bf.create')"
    class="w-full max-w-lg"
    @update:visible="emit('update:visible', $event)"
    @show="reset"
  >
    <form
      class="flex flex-col gap-4"
      @submit.prevent="save"
    >
      <Field :label="t('actions.bf.title')">
        <InputText
          v-model="form.title"
          fluid
          autofocus
        />
      </Field>
      <div class="grid grid-cols-2 gap-3">
        <Field :label="t('actions.bf.periodicity')">
          <Select
            v-model="form.periodicity"
            :options="periodicities"
            option-label="label"
            option-value="value"
            fluid
          />
        </Field>
        <Field :label="t('actions.bf.dueText')">
          <InputText
            v-model="dueText"
            :placeholder="t('actions.bf.dueTextHint')"
            fluid
          />
        </Field>
      </div>
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
      <Field :label="t('actions.bf.metric')">
        <Select
          v-model="form.metricId"
          :options="metrics"
          option-label="name"
          option-value="id"
          show-clear
          :placeholder="t('actions.bf.noMetric')"
          fluid
        />
      </Field>
      <label
        v-if="fn"
        class="flex items-center gap-2 text-sm"
      >
        <ToggleSwitch v-model="form.isActive" />
        {{ t('actions.bf.active') }}
      </label>
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
          :disabled="pending || !form.title.trim()"
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
import ToggleSwitch from 'primevue/toggleswitch'
import { useApi } from '~/composables/useApi'
import { useAsyncAction } from '~/composables/useAsyncAction'
import type { UserRef } from '~/features/org/types'
import { createBaseFunction, updateBaseFunction } from '../api/actions'
import type { BaseFunction, BaseFunctionInput, MetricRef } from '../types'
import Field from './FormField.vue'

const props = defineProps<{
  visible: boolean
  orgUnitId: number
  fn: BaseFunction | null
  people: UserRef[]
  metrics: MetricRef[]
}>()
const emit = defineEmits<{ 'update:visible': [boolean], 'saved': [] }>()
const { t } = useI18n()

const form = reactive<BaseFunctionInput>({ title: '', periodicity: 'weekly', metricId: null, ownerUserId: null, dueText: null, isActive: true })
const error = ref<string | null>(null)
const dueText = computed({
  get: () => form.dueText ?? '',
  set: (v: string) => {
    form.dueText = v || null
  },
})
const periodicities = computed(() => (['daily', 'weekly', 'monthly', 'quarterly'] as const).map(value => ({ value, label: t(`actions.periodicity.${value}`) })))

function reset() {
  error.value = null
  Object.assign(form, props.fn
    ? { title: props.fn.title, periodicity: props.fn.periodicity, metricId: props.fn.metric?.id ?? null, ownerUserId: props.fn.owner?.id ?? null, dueText: props.fn.dueText, isActive: props.fn.isActive }
    : { title: '', periodicity: 'weekly', metricId: null, ownerUserId: null, dueText: null, isActive: true })
}

const { pending, run: save } = useAsyncAction(async () => {
  error.value = null
  const body = { ...form, title: form.title.trim() }
  if (props.fn) await updateBaseFunction(useApi(), props.fn.id, body)
  else await createBaseFunction(useApi(), props.orgUnitId, body)
  emit('saved')
  emit('update:visible', false)
}, { rethrow: false, onError: (e) => { error.value = e instanceof Error ? e.message : String(e) } })
</script>
