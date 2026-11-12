<template>
  <div class="relative inline-block">
    <UiPill
      chevron
      :active="highlightWhenSet && modelValue !== null"
      :aria-label="placeholder"
      aria-haspopup="listbox"
      @click="menu?.toggle($event)"
    >
      <span class="max-w-48 truncate">{{ selectedLabel }}</span>
    </UiPill>
    <Menu
      ref="menu"
      :model="items"
      popup
    />
  </div>
</template>

<script setup lang="ts" generic="T extends string | number">
import { computed, ref } from 'vue'
import Menu from 'primevue/menu'
import UiPill from './UiPill.vue'

export interface PillOption<V> {
  label: string
  value: V
}

/** Filter pill with a drop-down list ("Project ⌄", "Status ⌄"); null = "all". */
const props = withDefaults(defineProps<{
  modelValue: T | null
  options: PillOption<T>[]
  placeholder: string
  /** Label of the null choice; omit to make a value required. */
  allLabel?: string
  highlightWhenSet?: boolean
}>(), { allLabel: undefined, highlightWhenSet: false })

const emit = defineEmits<{ 'update:modelValue': [value: T | null] }>()
const menu = ref<InstanceType<typeof Menu> | null>(null)

const selectedLabel = computed(() => props.options.find(o => o.value === props.modelValue)?.label ?? props.placeholder)

const items = computed(() => [
  ...(props.allLabel ? [{ label: props.allLabel, command: () => emit('update:modelValue', null) }] : []),
  ...props.options.map(option => ({
    label: option.label,
    class: option.value === props.modelValue ? 'font-semibold' : '',
    command: () => emit('update:modelValue', option.value),
  })),
])
</script>
