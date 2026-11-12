<template>
  <div class="flex flex-wrap items-center justify-between gap-3">
    <div class="min-w-0">
      <h2
        v-if="title"
        class="text-xl font-semibold text-rnp-text"
      >
        {{ title }}
      </h2>
      <p
        v-if="subtitle"
        class="text-sm text-rnp-muted"
        :class="title ? 'mt-0.5' : ''"
      >
        {{ subtitle }}
      </p>
    </div>
    <div
      v-if="$slots.default"
      class="flex flex-wrap items-center gap-2"
    >
      <slot />
    </div>
  </div>
</template>

<script setup lang="ts">
/**
 * Section heading under the app header ("Overview" in the reference): the page title itself is in
 * the app header; here go an optional section title, a subtitle and the filter pills on the right.
 */
defineProps<{
  title?: string
  subtitle?: string
}>()
</script>
