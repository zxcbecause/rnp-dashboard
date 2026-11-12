<template>
  <div class="flex flex-wrap items-center gap-2">
    <div class="flex h-9 items-center rounded-full bg-rnp-pill pl-1 pr-1">
      <button
        type="button"
        class="flex h-7 w-7 items-center justify-center rounded-full text-rnp-text transition-colors hover:bg-rnp-accent-soft disabled:opacity-30"
        :disabled="!week?.prevWeekStart || loading"
        :aria-label="t('rnp.prevWeek')"
        @click="week?.prevWeekStart && emit('change', week.prevWeekStart)"
      >
        <i
          class="pi pi-chevron-left text-[11px]"
          aria-hidden="true"
        />
      </button>
      <span
        class="min-w-40 px-2 text-center text-sm font-medium text-rnp-text"
        :title="week ? monthLabel(week.monthStart) : undefined"
      >
        {{ week ? (week.weekIndex ? t('rnp.weekLabelShort', { n: week.weekIndex, from: short(week.weekStart), to: short(week.weekEnd) }) : `${short(week.weekStart)} – ${short(week.weekEnd)}`) : '…' }}
      </span>
      <button
        type="button"
        class="flex h-7 w-7 items-center justify-center rounded-full text-rnp-text transition-colors hover:bg-rnp-accent-soft disabled:opacity-30"
        :disabled="!week || loading"
        :aria-label="t('rnp.nextWeek')"
        @click="week && emit('change', week.nextWeekStart)"
      >
        <i
          class="pi pi-chevron-right text-[11px]"
          aria-hidden="true"
        />
      </button>
    </div>
    <UiPill
      v-if="week && !week.isCurrentWeek"
      icon="pi pi-calendar"
      :label="t('rnp.currentWeek')"
      :disabled="loading"
      @click="emit('change', null)"
    />
  </div>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import UiPill from '~/features/ui/components/UiPill.vue'

/** Week switcher as a white pill with arrows; the week is passed as any object with these fields. */
defineProps<{
  week: {
    /** Segment number in the month; without it the label shows the dates only. */
    weekIndex?: number
    weekStart: string
    weekEnd: string
    monthStart: string
    prevWeekStart: string | null
    nextWeekStart: string
    isCurrentWeek: boolean
  } | null
  loading: boolean
}>()

const emit = defineEmits<{ change: [week: string | null] }>()

const { t, locale } = useI18n()

function short(date: string): string {
  const [, m, d] = date.split('-')
  return `${d}.${m}`
}

function monthLabel(date: string): string {
  const label = new Intl.DateTimeFormat(locale.value, { month: 'long', year: 'numeric' }).format(new Date(`${date}T12:00:00`))
  return label.charAt(0).toUpperCase() + label.slice(1)
}
</script>
