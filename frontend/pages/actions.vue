<template>
  <section class="flex flex-col gap-6">
    <UiPageHeader :subtitle="t('actions.subtitle')">
      <OrgUnitSelect
        :model-value="unitId"
        :tree="orgStore.tree"
        :loading="orgStore.loading"
        @update:model-value="go({ unit: $event, week: weekParam })"
      />
      <WeekNavigator
        :week="navWeek"
        :loading="store.loading"
        @change="go({ unit: unitId, week: $event })"
      />
    </UiPageHeader>

    <Message
      v-if="store.error"
      :severity="store.error === 'not_found' ? 'warn' : 'error'"
    >
      {{ store.error === 'not_found' ? t('rnp.unitNotAvailable') : store.error }}
    </Message>

    <!-- First load: skeletons in the shape of the page -->
    <div
      v-if="!store.week && store.loading"
      class="flex flex-col gap-6"
    >
      <div class="grid gap-4 md:grid-cols-2">
        <UiSkeleton
          v-for="n in 2"
          :key="n"
          class="h-32"
          rounded="rounded-[20px]"
        />
      </div>
      <div class="grid gap-4 xl:grid-cols-[1fr_22rem]">
        <UiSkeleton
          class="h-96"
          rounded="rounded-[20px]"
        />
        <UiSkeleton
          class="h-96"
          rounded="rounded-[20px]"
        />
      </div>
    </div>

    <Transition
      name="rnp-fade"
      mode="out-in"
    >
      <div
        v-if="store.week"
        :key="`${store.week.orgUnitId}-${store.week.weekStart}`"
        class="flex flex-col gap-6 transition-opacity"
        :class="store.loading ? 'opacity-60' : ''"
      >
        <div class="grid gap-4 md:grid-cols-2">
          <ExecutionRateCard
            :class="rise"
            :style="{ '--i': 0 }"
            :label="t('actions.rate.week')"
            :rate="store.week.weekExecution"
          />
          <ExecutionRateCard
            :class="rise"
            :style="{ '--i': 1 }"
            :label="t('actions.rate.month')"
            :rate="store.week.monthExecution"
          />
        </div>
        <div class="grid items-start gap-4 xl:grid-cols-[1fr_22rem]">
          <div class="flex min-w-0 flex-col gap-4">
            <BaseFunctionsTable
              :class="rise"
              :style="{ '--i': 2 }"
              :week="store.week"
              @changed="store.reload()"
            />
            <RoadmapSection
              :class="rise"
              :style="{ '--i': 3 }"
              :week="store.week"
              @changed="store.reload()"
            />
          </div>
          <MissedList
            :class="rise"
            :style="{ '--i': 4 }"
            :items="store.week.missed"
          />
        </div>
        <p class="text-xs text-rnp-muted">
          {{ t('actions.legend') }}
        </p>
      </div>
    </Transition>
  </section>
</template>

<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import Message from 'primevue/message'
import { navigateTo, useRoute } from '#app'
import BaseFunctionsTable from '~/features/actions/components/BaseFunctionsTable.vue'
import ExecutionRateCard from '~/features/actions/components/ExecutionRateCard.vue'
import MissedList from '~/features/actions/components/MissedList.vue'
import RoadmapSection from '~/features/actions/components/RoadmapSection.vue'
import { useActionsStore } from '~/features/actions/stores/actionsStore'
import { useAuthStore } from '~/features/auth/stores/authStore'
import OrgUnitSelect from '~/features/org/components/OrgUnitSelect.vue'
import { useOrgStore } from '~/features/org/stores/orgStore'
import WeekNavigator from '~/features/rnp/components/WeekNavigator.vue'
import UiPageHeader from '~/features/ui/components/UiPageHeader.vue'
import UiSkeleton from '~/features/ui/components/UiSkeleton.vue'

const { t } = useI18n()
const route = useRoute()
const authStore = useAuthStore()
const orgStore = useOrgStore()
const store = useActionsStore()

const weekParam = computed(() => (typeof route.query.week === 'string' ? route.query.week : null))
const unitId = computed<number | null>(() => {
  const fromQuery = Number(route.query.unit)
  if (Number.isInteger(fromQuery) && fromQuery > 0) return fromQuery
  return authStore.me?.rootUnitIds[0] ?? orgStore.tree[0]?.id ?? null
})

/** The actions week has no segment number; "current" is derived from today's date. */
const navWeek = computed(() => {
  const week = store.week
  if (!week) return null
  const today = isoToday()
  return { ...week, isCurrentWeek: week.weekStart <= today && today <= week.weekEnd }
})

/** The cascade plays once per visit; later reloads only crossfade. */
const played = ref(false)
const rise = computed(() => (played.value ? '' : 'rnp-rise'))
watch(() => store.week, (value) => {
  if (value && !played.value) setTimeout(() => (played.value = true), 900)
})

function isoToday(): string {
  const now = new Date()
  return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`
}

function go(query: { unit: number | null, week: string | null }) {
  void navigateTo({
    path: '/actions',
    query: { ...(query.unit ? { unit: String(query.unit) } : {}), ...(query.week ? { week: query.week } : {}) },
  })
}

watch([unitId, weekParam], ([unit, week]) => {
  if (unit) void store.load(unit, week)
}, { immediate: true })

onMounted(() => {
  if (!orgStore.units.length) void orgStore.load()
})
</script>
