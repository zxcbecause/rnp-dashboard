<template>
  <UiCard :title="t('actions.rm.heading')">
    <template #actions>
      <div
        class="flex h-9 items-center gap-0.5 rounded-full bg-rnp-pill p-1"
        role="group"
      >
        <button
          v-for="option in filters"
          :key="option.value"
          type="button"
          class="h-7 rounded-full px-3 text-xs font-semibold transition-colors"
          :class="filter === option.value ? 'bg-rnp-text text-rnp-pill' : 'text-rnp-muted hover:text-rnp-text'"
          :aria-pressed="filter === option.value"
          @click="filter = option.value"
        >
          {{ option.label }}
        </button>
      </div>
      <UiPill
        v-if="week.canManage && week.metrics.length"
        icon="pi pi-plus"
        :label="t('actions.rm.create')"
        active
        @click="open(null)"
      />
    </template>

    <ul v-if="items.length">
      <RoadmapItemCard
        v-for="item in items"
        :key="item.id"
        :item="item"
        @changed="emit('changed')"
        @edit="open"
      />
    </ul>
    <p
      v-else
      class="py-6 text-center text-sm text-rnp-muted"
    >
      {{ t('actions.rm.empty') }}
    </p>

    <RoadmapDialog
      v-model:visible="dialog"
      :item="editing"
      :people="week.people"
      :metrics="week.metrics"
      @saved="emit('changed')"
    />
  </UiCard>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import UiCard from '~/features/ui/components/UiCard.vue'
import UiPill from '~/features/ui/components/UiPill.vue'
import type { ActionsWeek, RoadmapItem } from '../types'
import RoadmapDialog from './RoadmapDialog.vue'
import RoadmapItemCard from './RoadmapItemCard.vue'

const props = defineProps<{ week: ActionsWeek }>()
const emit = defineEmits<{ changed: [] }>()
const { t } = useI18n()

const filter = ref<'open' | 'all'>('open')
const filters = computed(() => [
  { value: 'open' as const, label: t('actions.rm.filterOpen') },
  { value: 'all' as const, label: t('actions.rm.filterAll') },
])
const items = computed(() => (filter.value === 'all'
  ? props.week.roadmap
  : props.week.roadmap.filter(item => item.status === 'planned' || item.status === 'in_progress')))

const dialog = ref(false)
const editing = ref<RoadmapItem | null>(null)

function open(item: RoadmapItem | null) {
  editing.value = item
  dialog.value = true
}
</script>
