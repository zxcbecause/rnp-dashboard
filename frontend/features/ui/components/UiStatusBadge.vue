<template>
  <span
    class="inline-flex h-6 items-center whitespace-nowrap rounded-full px-2.5 text-xs font-semibold"
    :style="{ color: colors.fg, backgroundColor: colors.bg }"
  >
    <slot>{{ label ?? t(`ui.status.${status}`) }}</slot>
  </span>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { STATUS_COLORS } from '../status'
import type { UiStatus } from '../status'

/** Pastel status pill: green done / yellow at risk / red missed / grey no plan. */
const props = defineProps<{
  status: UiStatus
  label?: string
}>()

const { t } = useI18n()
const colors = computed(() => STATUS_COLORS[props.status])
</script>
