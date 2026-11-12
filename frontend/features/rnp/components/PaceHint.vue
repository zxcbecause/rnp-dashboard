<template>
  <span
    v-if="view"
    class="inline-flex items-center gap-1 whitespace-nowrap text-[11px] font-medium tabular-nums"
    :class="view.tone === 'good' ? 'text-rnp-green' : 'text-rnp-red'"
    :title="t('pace.hint', { days: row.pace?.remainingDays ?? 0 })"
  >
    <i
      class="pi text-[9px]"
      :class="icon"
      aria-hidden="true"
    />
    {{ text }}
  </span>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type { RnpRow } from '../types'
import { paceView } from '../utils/pace'

/** "до плана 11,9/день · сейчас 6,6" — what each remaining day of the month has to bring. */
const props = defineProps<{ row: RnpRow }>()
const { t } = useI18n()

const view = computed(() => paceView(props.row))
const text = computed(() => (view.value ? t(`pace.${view.value.kind}`, { required: view.value.required, current: view.value.current }) : ''))
const icon = computed(() => (view.value?.kind === 'reached' ? 'pi-check' : view.value?.tone === 'good' ? 'pi-arrow-right' : 'pi-exclamation-circle'))
</script>
