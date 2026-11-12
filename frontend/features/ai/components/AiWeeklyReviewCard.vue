<template>
  <UiCard class="ai-card relative overflow-hidden">
    <header class="mb-4 flex flex-wrap items-center justify-between gap-3">
      <div class="flex items-center gap-3">
        <span class="flex h-10 w-10 items-center justify-center rounded-2xl bg-rnp-sidebar text-rnp-accent">
          <i
            class="pi pi-sparkles"
            aria-hidden="true"
          />
        </span>
        <div>
          <h2 class="text-lg font-semibold text-rnp-text">
            {{ t('ai.review.title') }}
          </h2>
          <p
            v-if="review?.text && review.createdAt"
            class="text-xs text-rnp-muted"
          >
            {{ t('ai.review.generatedAt', { time: formatTime(review.createdAt), name: review.createdBy?.name ?? '—' }) }}
          </p>
        </div>
      </div>
      <UiPill
        v-if="review?.available"
        :icon="pending ? 'pi pi-spin pi-spinner' : (review.text ? 'pi pi-refresh' : 'pi pi-sparkles')"
        :label="review.text ? t('ai.review.regenerate') : t('ai.review.generate')"
        :active="!review.text"
        :disabled="pending"
        @click="generate"
      />
    </header>

    <div v-if="pending">
      <p class="mb-3 text-sm text-rnp-muted">
        {{ t('ai.review.thinking') }}
      </p>
      <div class="flex flex-col gap-2">
        <UiSkeleton
          v-for="n in 4"
          :key="n"
          class="h-4"
          :style="{ width: `${92 - n * 9}%` }"
          rounded="rounded-full"
        />
      </div>
    </div>
    <!-- eslint-disable vue/no-v-html -- renderMarkdown escapes the model output before adding its own tags -->
    <div
      v-else-if="review?.text"
      class="ai-markdown text-sm leading-relaxed text-rnp-text"
      v-html="html"
    />
    <!-- eslint-enable vue/no-v-html -->
    <p
      v-else-if="review && !review.available"
      class="text-sm text-rnp-muted"
    >
      {{ t('ai.notConfigured') }}
    </p>
    <p
      v-else-if="review"
      class="text-sm text-rnp-muted"
    >
      {{ t('ai.review.empty') }}
    </p>
    <UiSkeleton
      v-else
      class="h-16"
    />

    <p
      v-if="error"
      class="mt-3 text-sm text-rnp-red"
    >
      {{ error }}
    </p>
  </UiCard>
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useApi } from '~/composables/useApi'
import { useAsyncAction } from '~/composables/useAsyncAction'
import { createLatestRequestScope } from '~/composables/useLatestRequest'
import UiCard from '~/features/ui/components/UiCard.vue'
import UiPill from '~/features/ui/components/UiPill.vue'
import UiSkeleton from '~/features/ui/components/UiSkeleton.vue'
import { fetchWeeklyReview, generateWeeklyReview } from '../api/ai'
import type { AiWeeklyReview } from '../types'
import { renderMarkdown } from '../utils/markdown'

/** AI review of the unit's week (stored on the server; generated on request). */
const props = defineProps<{ orgUnitId: number, week: string }>()

const { t, locale } = useI18n()
const review = ref<AiWeeklyReview | null>(null)
const error = ref<string | null>(null)
const scope = createLatestRequestScope()

// Safe: renderMarkdown escapes all HTML of the model output before adding its own tags.
const html = computed(() => (review.value?.text ? renderMarkdown(review.value.text) : ''))

async function load() {
  const isLatest = scope.begin()
  error.value = null
  try {
    const data = await fetchWeeklyReview(useApi(), props.orgUnitId, props.week)
    if (isLatest()) review.value = data
  }
  catch (e) {
    if (isLatest()) error.value = e instanceof Error ? e.message : String(e)
  }
}

const { pending, run: generate } = useAsyncAction(async () => {
  error.value = null
  const unit = props.orgUnitId
  const week = props.week
  const data = await generateWeeklyReview(useApi(), unit, week)
  if (unit === props.orgUnitId && week === props.week) review.value = data
}, {
  rethrow: false,
  onError: (e) => { error.value = e instanceof Error ? e.message : String(e) },
})

function formatTime(iso: string): string {
  return new Intl.DateTimeFormat(locale.value, { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit', timeZone: 'Europe/Madrid' }).format(new Date(iso))
}

watch(() => [props.orgUnitId, props.week], () => {
  review.value = null
  void load()
}, { immediate: true })
</script>

<style scoped>
/* A dark corner glow marks AI content without breaking the card palette. */
.ai-card::before {
  content: '';
  position: absolute;
  top: -40%;
  right: -10%;
  width: 45%;
  height: 120%;
  background: radial-gradient(circle, rgb(238 197 43 / 0.12), transparent 65%);
  pointer-events: none;
}
</style>
