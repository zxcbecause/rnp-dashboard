<template>
  <UiCard :title="t('actions.missed.heading')">
    <template #actions>
      <UiStatusBadge
        v-if="items.length"
        status="missed"
      >
        {{ items.length }}
      </UiStatusBadge>
    </template>
    <p class="-mt-3 mb-3 text-xs text-rnp-muted">
      {{ t('actions.missed.hint') }}
    </p>
    <ul
      v-if="items.length"
      class="-mr-2 max-h-[28rem] overflow-y-auto pr-2"
    >
      <li
        v-for="item in items"
        :key="`${item.kind}-${item.id}-${item.periodStart}`"
        class="flex items-start justify-between gap-3 border-b border-rnp-divider/70 py-2.5 text-sm last:border-0"
      >
        <div class="min-w-0">
          <div class="text-rnp-text">
            {{ item.title }}
          </div>
          <div class="text-xs text-rnp-muted">
            {{ item.kind === 'roadmap' ? t('actions.missed.roadmap') : t('actions.missed.baseFunction') }}<template v-if="item.owner">
              · {{ item.owner.name }}
            </template>
          </div>
        </div>
        <span class="shrink-0 whitespace-nowrap text-xs font-medium text-rnp-red">{{ t('actions.missed.due', { date: short(item.due) }) }}</span>
      </li>
    </ul>
    <p
      v-else
      class="py-4 text-center text-sm text-rnp-muted"
    >
      {{ t('actions.missed.empty') }}
    </p>
  </UiCard>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import UiCard from '~/features/ui/components/UiCard.vue'
import UiStatusBadge from '~/features/ui/components/UiStatusBadge.vue'
import type { MissedItem } from '../types'

defineProps<{ items: MissedItem[] }>()
const { t } = useI18n()

function short(date: string): string {
  const [, m, d] = date.split('-')
  return `${d}.${m}`
}
</script>
