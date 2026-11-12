<template>
  <div>
    <UiSkeleton
      v-if="loading"
      class="h-40"
      rounded="rounded-2xl"
    />
    <Message
      v-else-if="error"
      severity="error"
      size="small"
    >
      {{ error }}
    </Message>
    <a
      v-else-if="src"
      :href="src"
      target="_blank"
      rel="noopener noreferrer"
      :title="t('calls.imageOpen')"
    >
      <img
        :src="src"
        :alt="t('calls.imageTitle')"
        class="max-h-[60vh] w-auto max-w-full rounded-2xl border border-rnp-divider"
      >
    </a>
  </div>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import Message from 'primevue/message'
import { useApi } from '~/composables/useApi'
import UiSkeleton from '~/features/ui/components/UiSkeleton.vue'
import { fetchCallExclusionImage } from '../api/calls'

/** Screenshot of a call exclusion request; remount with a :key to show another request. */
const props = defineProps<{ exclusionId: number }>()
const { t } = useI18n()

const src = ref<string | null>(null)
const loading = ref(true)
const error = ref<string | null>(null)

onMounted(async () => {
  try {
    const image = await fetchCallExclusionImage(useApi(), props.exclusionId)
    src.value = `data:${image.contentType};base64,${image.data}`
  }
  catch (e) {
    error.value = e instanceof Error ? e.message : String(e)
  }
  finally {
    loading.value = false
  }
})
</script>
