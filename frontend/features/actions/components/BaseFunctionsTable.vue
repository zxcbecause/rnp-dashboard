<template>
  <UiCard :title="t('actions.bf.heading')">
    <template
      v-if="week.canManage"
      #actions
    >
      <UiPill
        icon="pi pi-plus"
        :label="t('actions.bf.create')"
        active
        @click="open(null)"
      />
    </template>

    <ul v-if="week.baseFunctions.length">
      <li
        v-for="fn in week.baseFunctions"
        :key="fn.id"
        class="flex flex-wrap items-start gap-x-6 gap-y-2 border-b border-rnp-divider/70 py-3 first:pt-0 last:border-0 last:pb-0"
      >
        <div class="min-w-64 flex-1">
          <div class="flex items-center gap-2">
            <span class="font-medium text-rnp-text">{{ fn.title }}</span>
            <Button
              v-if="week.canManage"
              icon="pi pi-pencil"
              text
              rounded
              size="small"
              severity="secondary"
              :aria-label="t('actions.bf.edit')"
              @click="open(fn)"
            />
          </div>
          <div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-rnp-muted">
            <span class="rounded-full bg-rnp-bg px-2 py-px">{{ t(`actions.periodicity.${fn.periodicity}`) }}<template v-if="fn.dueText"> · {{ fn.dueText }}</template></span>
            <span v-if="fn.owner"><i
              class="pi pi-user text-[10px]"
              aria-hidden="true"
            /> {{ fn.owner.name }}</span>
            <NuxtLink
              v-if="fn.metric"
              :to="`/metrics/${fn.metric.id}`"
              class="font-medium text-rnp-text underline-offset-2 hover:underline"
            >{{ fn.metric.name }}</NuxtLink>
          </div>
        </div>
        <div class="flex flex-wrap gap-1.5">
          <CheckSlot
            v-for="slot in fn.slots"
            :key="slot.periodStart"
            :entry="slot"
            :function-id="fn.id"
            :periodicity="fn.periodicity"
            :can-check="fn.canCheck"
            @saved="emit('changed')"
          />
        </div>
      </li>
    </ul>
    <p
      v-else
      class="py-6 text-center text-sm text-rnp-muted"
    >
      {{ t('actions.bf.empty') }}
    </p>

    <BaseFunctionDialog
      v-model:visible="dialog"
      :org-unit-id="week.orgUnitId"
      :fn="editing"
      :people="week.people"
      :metrics="week.metrics"
      @saved="emit('changed')"
    />
  </UiCard>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import UiCard from '~/features/ui/components/UiCard.vue'
import UiPill from '~/features/ui/components/UiPill.vue'
import type { ActionsWeek, BaseFunction } from '../types'
import BaseFunctionDialog from './BaseFunctionDialog.vue'
import CheckSlot from './CheckSlot.vue'

defineProps<{ week: ActionsWeek }>()
const emit = defineEmits<{ changed: [] }>()
const { t } = useI18n()

const dialog = ref(false)
const editing = ref<BaseFunction | null>(null)

function open(fn: BaseFunction | null) {
  editing.value = fn
  dialog.value = true
}
</script>
