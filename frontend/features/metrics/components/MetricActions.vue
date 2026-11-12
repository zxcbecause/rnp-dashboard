<template>
  <UiCard
    v-if="week"
    :title="t('metrics.actions.title')"
  >
    <template #actions>
      <UiPill
        v-if="week.canManage"
        icon="pi pi-plus"
        :label="t('actions.rm.create')"
        active
        @click="open(null)"
      />
      <NuxtLink
        :to="{ path: '/actions', query: { unit: String(metric.orgUnitId) } }"
        class="inline-flex h-9 items-center gap-2 rounded-full bg-rnp-pill px-4 text-sm font-medium text-rnp-text transition-colors hover:bg-rnp-accent-soft"
      >
        {{ t('metrics.actions.all') }}
        <i
          class="pi pi-arrow-right text-xs"
          aria-hidden="true"
        />
      </NuxtLink>
    </template>

    <div class="grid gap-6 lg:grid-cols-2">
      <!-- Regular functions that keep the metric up -->
      <section>
        <h3 class="mb-3 text-sm font-semibold text-rnp-muted">
          {{ t('actions.bf.heading') }}
        </h3>
        <ul v-if="functions.length">
          <li
            v-for="fn in functions"
            :key="fn.id"
            class="border-b border-rnp-divider/70 py-3 first:pt-0 last:border-0"
          >
            <div class="font-medium text-rnp-text">
              {{ fn.title }}
            </div>
            <div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-rnp-muted">
              <span class="rounded-full bg-rnp-bg px-2 py-px">{{ t(`actions.periodicity.${fn.periodicity}`) }}<template v-if="fn.dueText"> · {{ fn.dueText }}</template></span>
              <span v-if="fn.owner">{{ fn.owner.name }}</span>
            </div>
            <div class="mt-2 flex flex-wrap gap-1.5">
              <CheckSlot
                v-for="slot in fn.slots"
                :key="slot.periodStart"
                :entry="slot"
                :function-id="fn.id"
                :periodicity="fn.periodicity"
                :can-check="fn.canCheck"
                @saved="load"
              />
            </div>
          </li>
        </ul>
        <p
          v-else
          class="text-sm text-rnp-muted"
        >
          {{ t('metrics.actions.noFunctions') }}
        </p>
      </section>

      <!-- Roadmap items aimed at the metric -->
      <section>
        <h3 class="mb-3 text-sm font-semibold text-rnp-muted">
          {{ t('actions.rm.heading') }}
        </h3>
        <ul v-if="roadmap.length">
          <RoadmapItemCard
            v-for="item in roadmap"
            :key="item.id"
            :item="item"
            @changed="load"
            @edit="open"
          />
        </ul>
        <p
          v-else
          class="text-sm text-rnp-muted"
        >
          {{ t('metrics.actions.noRoadmap') }}
        </p>
      </section>
    </div>

    <RoadmapDialog
      v-model:visible="dialog"
      :item="editing"
      :people="week.people"
      :metrics="[{ id: metric.id, name: metric.name }]"
      @saved="load"
    />
  </UiCard>
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useApi } from '~/composables/useApi'
import { createLatestRequestScope } from '~/composables/useLatestRequest'
import { fetchActionsWeek } from '~/features/actions/api/actions'
import CheckSlot from '~/features/actions/components/CheckSlot.vue'
import RoadmapDialog from '~/features/actions/components/RoadmapDialog.vue'
import RoadmapItemCard from '~/features/actions/components/RoadmapItemCard.vue'
import type { ActionsWeek, RoadmapItem } from '~/features/actions/types'
import UiCard from '~/features/ui/components/UiCard.vue'
import UiPill from '~/features/ui/components/UiPill.vue'
import type { Metric } from '../types'

/**
 * Actions around one metric: its base functions (with this week's checks) and roadmap items, taken from
 * the actions week of the metric's department. Hidden when that week is not available to the user.
 */
const props = defineProps<{ metric: Metric }>()
const { t } = useI18n()

const week = ref<ActionsWeek | null>(null)
const scope = createLatestRequestScope()
const dialog = ref(false)
const editing = ref<RoadmapItem | null>(null)

const functions = computed(() => (week.value?.baseFunctions ?? []).filter(fn => fn.metric?.id === props.metric.id))
const roadmap = computed(() => (week.value?.roadmap ?? []).filter(item => item.metric.id === props.metric.id))

async function load() {
  const isLatest = scope.begin()
  try {
    const data = await fetchActionsWeek(useApi(), props.metric.orgUnitId)
    if (isLatest()) week.value = data
  }
  catch {
    // A filler outside the department cannot read its actions: the card simply stays hidden.
    if (isLatest()) week.value = null
  }
}

function open(item: RoadmapItem | null) {
  editing.value = item
  dialog.value = true
}

watch(() => props.metric.id, load, { immediate: true })
</script>
