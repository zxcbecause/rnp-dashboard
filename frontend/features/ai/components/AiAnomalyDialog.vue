<template>
  <Dialog
    :visible="visible"
    modal
    :header="t('ai.anomaly.title', { name: target?.metricName ?? '' })"
    class="w-full max-w-xl"
    @update:visible="emit('update:visible', $event)"
  >
    <p
      v-if="target"
      class="mb-3 flex items-center gap-2 text-xs text-rnp-muted"
    >
      <i
        class="pi pi-sparkles text-rnp-accent-hover"
        aria-hidden="true"
      />
      {{ target.kind === 'week' ? t('ai.anomaly.kindWeek', { date: shortDate(target.date) }) : t('ai.anomaly.kindForecast') }}
    </p>

    <div
      v-if="loading"
      class="flex flex-col gap-2"
      role="status"
    >
      <p class="text-sm text-rnp-muted">
        {{ t('ai.anomaly.thinking') }}
      </p>
      <UiSkeleton class="h-4 w-11/12" />
      <UiSkeleton class="h-4 w-10/12" />
      <UiSkeleton class="h-4 w-8/12" />
    </div>
    <p
      v-else-if="error"
      class="text-sm text-rnp-red"
      role="alert"
    >
      {{ error }}
    </p>
    <template v-else-if="explanation">
      <!-- eslint-disable vue/no-v-html -- renderMarkdown escapes the model output before adding its own tags -->
      <div
        class="ai-markdown text-sm leading-relaxed text-rnp-text"
        v-html="html"
      />
      <!-- eslint-enable vue/no-v-html -->
      <p class="mt-4 text-xs text-rnp-muted">
        {{ t('ai.anomaly.generatedAt', { time: formatTime(explanation.createdAt), name: explanation.createdBy?.name ?? '—' }) }}
      </p>
    </template>

    <template #footer>
      <NuxtLink
        v-if="target"
        :to="`/metrics/${target.metricId}`"
        class="text-sm font-semibold text-rnp-text underline-offset-4 hover:underline"
        @click="emit('update:visible', false)"
      >
        {{ t('ai.anomaly.openMetric') }}
      </NuxtLink>
    </template>
  </Dialog>
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import Dialog from 'primevue/dialog'
import { useApi } from '~/composables/useApi'
import { createLatestRequestScope } from '~/composables/useLatestRequest'
import UiSkeleton from '~/features/ui/components/UiSkeleton.vue'
import { shortDate } from '~/features/notifications/utils/unread'
import { explainAnomaly } from '../api/ai'
import type { AnomalyExplanation, AnomalyTarget } from '../types'
import { renderMarkdown } from '../utils/markdown'

/** AI answer to «why?» for a red metric or a month forecast behind the plan (feature D). */
const props = defineProps<{ visible: boolean, target: AnomalyTarget | null }>()
const emit = defineEmits<{ 'update:visible': [value: boolean] }>()

const { t, locale } = useI18n()
const explanation = ref<AnomalyExplanation | null>(null)
const loading = ref(false)
const error = ref<string | null>(null)
const scope = createLatestRequestScope()

// Safe: renderMarkdown escapes all HTML of the model output before adding its own tags.
const html = computed(() => (explanation.value ? renderMarkdown(explanation.value.text) : ''))

async function load(target: AnomalyTarget) {
  const isLatest = scope.begin()
  explanation.value = null
  error.value = null
  loading.value = true
  try {
    const data = await explainAnomaly(useApi(), target.metricId, target.kind, target.date)
    if (isLatest()) explanation.value = data
  }
  catch (e) {
    if (isLatest()) error.value = e instanceof Error ? e.message : String(e)
  }
  finally {
    if (isLatest()) loading.value = false
  }
}

function formatTime(iso: string): string {
  return new Intl.DateTimeFormat(locale.value, { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit', timeZone: 'Europe/Madrid' }).format(new Date(iso))
}

watch(() => [props.visible, props.target] as const, ([visible, target]) => {
  if (visible && target) void load(target)
}, { immediate: true })
</script>
