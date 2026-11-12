<template>
  <section
    class="rounded-[var(--rnp-radius-card)] bg-rnp-card"
    :class="[padded ? 'p-5 lg:p-6' : '', hover ? 'rnp-card-hover' : '']"
  >
    <header
      v-if="title || $slots.actions"
      class="mb-4 flex flex-wrap items-center justify-between gap-3"
    >
      <h2
        v-if="title"
        class="text-lg font-semibold text-rnp-text"
      >
        {{ title }}
      </h2>
      <div
        v-if="$slots.actions"
        class="flex flex-wrap items-center gap-2"
      >
        <slot name="actions" />
      </div>
    </header>
    <slot />
  </section>
</template>

<script setup lang="ts">
/** Reference card: a slightly different tone than the page, no border, no shadow, large radius. */
withDefaults(defineProps<{
  title?: string
  padded?: boolean
  hover?: boolean
}>(), { title: undefined, padded: true, hover: false })
</script>
