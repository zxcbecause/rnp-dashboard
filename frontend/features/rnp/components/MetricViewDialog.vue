<template>
  <Dialog
    :visible="visible"
    modal
    :header="t('rnp.view.title')"
    class="w-full max-w-lg"
    @update:visible="emit('update:visible', $event)"
    @show="load"
  >
    <p class="mb-3 text-sm text-gray-500">
      {{ t('rnp.view.hint') }}
    </p>

    <ProgressSpinner
      v-if="loading"
      style="width: 2rem; height: 2rem"
    />
    <Message
      v-else-if="loadError"
      severity="error"
      size="small"
    >
      {{ loadError }}
    </Message>
    <ul
      v-else
      class="flex max-h-[60vh] flex-col gap-1 overflow-y-auto"
    >
      <li
        v-for="(entry, index) in entries"
        :key="entry.metricId"
        class="flex items-center gap-2 rounded-lg border border-gray-100 px-2 py-1.5"
        :class="entry.visible ? 'bg-white' : 'bg-gray-50'"
      >
        <Checkbox
          v-model="entry.visible"
          binary
          :input-id="`view-${entry.metricId}`"
        />
        <label
          :for="`view-${entry.metricId}`"
          class="flex-1 cursor-pointer text-sm"
          :class="entry.visible ? 'text-gray-900' : 'text-gray-400 line-through'"
        >{{ entry.name }}</label>
        <Button
          icon="pi pi-arrow-up"
          text
          rounded
          size="small"
          severity="secondary"
          :disabled="index === 0"
          :aria-label="t('rnp.view.up')"
          @click="entries = moveEntry(entries, index, -1)"
        />
        <Button
          icon="pi pi-arrow-down"
          text
          rounded
          size="small"
          severity="secondary"
          :disabled="index === entries.length - 1"
          :aria-label="t('rnp.view.down')"
          @click="entries = moveEntry(entries, index, 1)"
        />
      </li>
    </ul>

    <Message
      v-if="!loading && entries.length && !canSaveView(entries)"
      severity="warn"
      size="small"
      class="mt-3"
    >
      {{ t('rnp.view.keepOne') }}
    </Message>
    <Message
      v-if="saveError"
      severity="error"
      size="small"
      class="mt-3"
    >
      {{ saveError }}
    </Message>

    <template #footer>
      <Button
        :label="t('rnp.view.reset')"
        text
        severity="secondary"
        :loading="resetting"
        :disabled="busy"
        @click="reset"
      />
      <Button
        :label="t('common.cancel')"
        text
        severity="secondary"
        :disabled="busy"
        @click="emit('update:visible', false)"
      />
      <Button
        :label="t('common.save')"
        :loading="saving"
        :disabled="busy || !canSaveView(entries)"
        @click="save"
      />
    </template>
  </Dialog>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import Checkbox from 'primevue/checkbox'
import Dialog from 'primevue/dialog'
import Message from 'primevue/message'
import ProgressSpinner from 'primevue/progressspinner'
import { useApi } from '~/composables/useApi'
import { useAsyncAction } from '~/composables/useAsyncAction'
import { fetchRnpWeek, resetMetricView, saveMetricView } from '../api/rnp'
import { canSaveView, moveEntry } from '../utils/viewOrder'
import type { ViewEntry } from '../utils/viewOrder'

const props = defineProps<{
  visible: boolean
  orgUnitId: number
}>()

const emit = defineEmits<{
  'update:visible': [value: boolean]
  'saved': []
}>()

const { t } = useI18n()
const entries = ref<ViewEntry[]>([])
const loading = ref(false)
const loadError = ref<string | null>(null)
const saveError = ref<string | null>(null)

async function load() {
  loading.value = true
  loadError.value = null
  saveError.value = null
  try {
    // All metrics (hidden included) in the user's current order.
    const week = await fetchRnpWeek(useApi(), props.orgUnitId, null, true)
    entries.value = week.rows.map(row => ({ metricId: row.metricId, name: row.name, visible: !row.hidden }))
  }
  catch (e) {
    loadError.value = e instanceof Error ? e.message : String(e)
  }
  finally {
    loading.value = false
  }
}

const finish = () => {
  emit('saved')
  emit('update:visible', false)
}
const onError = (e: unknown) => {
  saveError.value = e instanceof Error ? e.message : String(e)
}

const { pending: saving, run: save } = useAsyncAction(async () => {
  saveError.value = null
  await saveMetricView(useApi(), props.orgUnitId, entries.value.map(({ metricId, visible }) => ({ metricId, visible })))
  finish()
}, { rethrow: false, onError, canRun: () => canSaveView(entries.value) })

const { pending: resetting, run: reset } = useAsyncAction(async () => {
  saveError.value = null
  await resetMetricView(useApi(), props.orgUnitId)
  finish()
}, { rethrow: false, onError })

const busy = computed(() => saving.value || resetting.value || loading.value)
</script>
