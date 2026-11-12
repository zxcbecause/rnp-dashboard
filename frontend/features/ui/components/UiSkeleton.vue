<template>
  <div
    class="rnp-skeleton"
    :class="rounded"
    aria-hidden="true"
  />
</template>

<script setup lang="ts">
/** Shimmering placeholder in palette tones; size it with classes (h-*, w-*). */
withDefaults(defineProps<{
  rounded?: string
}>(), { rounded: 'rounded-xl' })
</script>
