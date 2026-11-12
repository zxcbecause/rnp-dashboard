<template>
  <component
    :is="as"
    :type="as === 'button' ? 'button' : undefined"
    class="inline-flex h-9 items-center gap-2 whitespace-nowrap rounded-full px-4 text-sm font-medium transition-colors disabled:cursor-default disabled:opacity-50"
    :class="active ? 'bg-rnp-accent text-rnp-text' : 'bg-rnp-pill text-rnp-text hover:bg-rnp-accent-soft'"
  >
    <i
      v-if="icon"
      :class="icon"
      class="text-xs"
      aria-hidden="true"
    />
    <slot>{{ label }}</slot>
    <i
      v-if="chevron"
      class="pi pi-chevron-down text-[10px] text-rnp-muted"
      aria-hidden="true"
    />
  </component>
</template>

<script setup lang="ts">
/** White filter pill of the reference ("Last 30 days ⌄"); active = brand yellow with dark text. */
withDefaults(defineProps<{
  label?: string
  icon?: string
  chevron?: boolean
  active?: boolean
  as?: 'button' | 'span' | 'div'
}>(), { label: undefined, icon: undefined, chevron: false, active: false, as: 'button' })
</script>
