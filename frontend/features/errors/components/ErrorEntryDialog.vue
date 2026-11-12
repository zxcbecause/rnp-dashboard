<template>
  <Dialog
    :visible="visible"
    modal
    :header="entry ? t('errorLog.edit') : t('errorLog.create')"
    class="w-full max-w-xl"
    @update:visible="emit('update:visible', $event)"
    @show="reset"
  >
    <form
      class="flex flex-col gap-4"
      @submit.prevent="save"
    >
      <p
        v-if="!entry"
        class="text-sm text-rnp-muted"
      >
        {{ t('errorLog.significantOnly') }}
      </p>
      <Field :label="t('errorLog.description')">
        <Textarea
          v-model="form.description"
          rows="3"
          auto-resize
          fluid
        />
      </Field>
      <div class="grid grid-cols-3 gap-3">
        <Field :label="t('errorLog.occurredOn')">
          <InputText
            v-model="form.occurredOn"
            type="date"
            :max="today"
            fluid
          />
        </Field>
        <Field :label="t('errorLog.impactType')">
          <Select
            v-model="form.impactType"
            :options="impacts"
            option-label="label"
            option-value="value"
            fluid
          />
        </Field>
        <Field :label="t('errorLog.impactAmount')">
          <InputText
            v-model="amount"
            :placeholder="form.impactType === 'money' ? t('errorLog.required') : ''"
            fluid
          />
        </Field>
      </div>
      <Field :label="t('errorLog.cause')">
        <Textarea
          v-model="cause"
          rows="2"
          auto-resize
          fluid
        />
      </Field>
      <Field :label="t('errorLog.prevention')">
        <Textarea
          v-model="prevention"
          rows="2"
          auto-resize
          fluid
        />
      </Field>
      <div class="grid grid-cols-2 gap-3">
        <Field :label="t('errorLog.owner')">
          <Select
            v-model="form.ownerUserId"
            :options="people"
            option-label="name"
            option-value="id"
            show-clear
            fluid
          />
        </Field>
        <Field :label="t('errorLog.status')">
          <Select
            v-model="form.status"
            :options="statuses"
            option-label="label"
            option-value="value"
            fluid
          />
        </Field>
      </div>
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
          :disabled="pending || !form.description.trim() || !form.impactType"
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
import Field from '~/features/actions/components/FormField.vue'
import type { UserRef } from '~/features/org/types'
import { fromInputValue } from '~/utils/metricFormat'
import { createErrorEntry, updateErrorEntry } from '../api/errors'
import type { ErrorEntry, ErrorEntryInput } from '../types'

const props = defineProps<{
  visible: boolean
  orgUnitId: number
  entry: ErrorEntry | null
  people: UserRef[]
}>()
const emit = defineEmits<{ 'update:visible': [boolean], 'saved': [] }>()
const { t } = useI18n()

const today = new Date().toISOString().slice(0, 10)
const empty = (): ErrorEntryInput => ({ occurredOn: today, description: '', impactType: '', impactAmount: null, cause: null, prevention: null, ownerUserId: null, status: 'open' })
const form = reactive<ErrorEntryInput>(empty())
const error = ref<string | null>(null)

const nullable = (key: 'impactAmount' | 'cause' | 'prevention') => computed({
  get: () => form[key] ?? '',
  set: (value: string) => {
    form[key] = value || null
  },
})
const amount = nullable('impactAmount')
const cause = nullable('cause')
const prevention = nullable('prevention')

const impacts = computed(() => (['money', 'blocked', 'client', 'other'] as const).map(value => ({ value, label: t(`errorLog.impact.${value}`) })))
const statuses = computed(() => (['open', 'in_progress', 'resolved'] as const).map(value => ({ value, label: t(`errorLog.statuses.${value}`) })))

function reset() {
  error.value = null
  Object.assign(form, empty(), props.entry
    ? { occurredOn: props.entry.occurredOn, description: props.entry.description, impactType: props.entry.impactType, impactAmount: props.entry.impactAmount, cause: props.entry.cause, prevention: props.entry.prevention, ownerUserId: props.entry.owner?.id ?? null, status: props.entry.status }
    : {})
}

const { pending, run: save } = useAsyncAction(async () => {
  error.value = null
  // "35 000,50" → "35000.50"; anything else goes as typed and the backend explains what is wrong.
  const body = { ...form, description: form.description.trim(), impactAmount: form.impactAmount ? fromInputValue('eur', form.impactAmount) : null }
  if (props.entry) await updateErrorEntry(useApi(), props.entry.id, body)
  else await createErrorEntry(useApi(), props.orgUnitId, body)
  emit('saved')
  emit('update:visible', false)
}, { rethrow: false, onError: (e) => { error.value = e instanceof Error ? e.message : String(e) } })
</script>
