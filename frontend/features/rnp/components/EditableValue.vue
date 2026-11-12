<template>
  <div class="flex flex-col items-end">
    <form
      v-if="editing"
      class="flex items-center gap-1"
      @submit.prevent="submit"
    >
      <InputText
        ref="input"
        v-model="draft"
        size="small"
        class="w-28 text-right tabular-nums"
        :placeholder="inputHint(target.unit)"
        :disabled="pending"
        :invalid="!!error"
        @keydown.esc.prevent="cancel"
      />
      <Button
        type="submit"
        icon="pi pi-check"
        size="small"
        text
        :loading="pending"
        :disabled="pending"
        :aria-label="t('common.save')"
      />
      <Button
        type="button"
        icon="pi pi-times"
        size="small"
        text
        severity="secondary"
        :disabled="pending"
        :aria-label="t('common.cancel')"
        @click="cancel"
      />
    </form>
    <button
      v-else-if="editable"
      type="button"
      class="group inline-flex min-h-7 min-w-16 items-center justify-end gap-1 rounded-full px-2 text-right tabular-nums transition-colors hover:bg-rnp-accent-soft focus-visible:bg-rnp-accent-soft focus-visible:outline-none"
      :title="t('rnp.clickToEdit')"
      @click="start"
    >
      <span v-if="display">{{ display }}</span>
      <span
        v-else
        class="text-xs text-rnp-muted/70"
      >{{ t('rnp.enter') }}</span>
      <i
        class="pi pi-pencil text-[10px] text-rnp-muted/40 transition-colors group-hover:text-rnp-text"
        aria-hidden="true"
      />
    </button>
    <span
      v-else
      class="tabular-nums"
      :class="display ? '' : 'text-rnp-muted/50'"
    >{{ display || '—' }}</span>
    <small
      v-if="editing && error"
      class="mt-1 max-w-56 text-right text-xs text-rnp-red"
    >{{ error }}</small>
  </div>
</template>

<script setup lang="ts">
import { computed, nextTick, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import { formatMetricValue, inputHint, toInputValue } from '~/utils/metricFormat'
import { usePeriodValueEditor } from '../composables/usePeriodValueEditor'
import type { EditTarget } from '../composables/usePeriodValueEditor'

const props = defineProps<{
  value: string | null
  target: EditTarget
  editable: boolean
}>()

const emit = defineEmits<{ saved: [] }>()

const { t } = useI18n()
const editing = ref(false)
const draft = ref('')
const input = ref<{ $el: HTMLInputElement } | null>(null)
const display = computed(() => formatMetricValue(props.target.unit, props.value))

const { pending, error, save } = usePeriodValueEditor(() => emit('saved'))

async function start() {
  draft.value = toInputValue(props.target.unit, props.value)
  error.value = null
  editing.value = true
  await nextTick()
  input.value?.$el.focus()
}

function cancel() {
  editing.value = false
  error.value = null
}

async function submit() {
  const ok = await save(props.target, draft.value)
  if (ok) editing.value = false
}
</script>
