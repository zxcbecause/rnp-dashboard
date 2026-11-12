<template>
  <label class="flex flex-col gap-1">
    <span class="text-sm font-medium text-rnp-text">{{ label }}</span>
    <slot />
  </label>
</template>

<script setup lang="ts">
defineProps<{ label: string }>()
</script>
