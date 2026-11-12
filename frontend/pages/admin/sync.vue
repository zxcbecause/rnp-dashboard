<template>
  <section class="flex flex-col gap-6">
    <UiPageHeader :subtitle="t('sync.subtitle')">
      <UiPill
        icon="pi pi-refresh"
        :label="t('sync.refresh')"
        :disabled="loading"
        @click="load"
      />
    </UiPageHeader>

    <Message
      v-if="!isAdmin"
      severity="warn"
    >
      {{ t('tokens.adminOnly') }}
    </Message>
    <Message
      v-else-if="error"
      severity="error"
    >
      {{ error }}
    </Message>

    <div
      v-if="isAdmin && !status && loading"
      class="grid gap-4 lg:grid-cols-2"
    >
      <UiSkeleton
        v-for="n in 2"
        :key="n"
        class="h-72"
        rounded="rounded-[20px]"
      />
    </div>

    <Transition
      name="rnp-fade"
      mode="out-in"
    >
      <div
        v-if="status"
        :key="status.checkedAt"
        class="grid gap-4 lg:grid-cols-2"
      >
        <UiCard
          v-for="(source, index) in status.sources"
          :key="source.source"
          :title="t(`dataStatus.source.${source.source}`)"
          :class="played ? '' : 'rnp-rise'"
          :style="{ '--i': index }"
        >
          <template #actions>
            <UiStatusBadge :status="healthStatus(source)">
              {{ t(`sync.health.${sourceHealth(source)}`) }}
            </UiStatusBadge>
          </template>
          <p class="mb-4 text-sm text-rnp-muted">
            {{ source.dataAsOf ? t('sync.dataAsOf', { time: formatTime(source.dataAsOf) }) : t('dataStatus.never') }}
          </p>
          <table class="w-full text-sm">
            <thead>
              <tr class="border-b border-rnp-divider text-left">
                <th class="pb-2 font-semibold">
                  {{ t('sync.resource') }}
                </th>
                <th class="pb-2 font-semibold">
                  {{ t('sync.lastRun') }}
                </th>
                <th class="pb-2 font-semibold">
                  {{ t('sync.lastSuccess') }}
                </th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="resource in source.resources"
                :key="resource.resource"
                class="border-b border-rnp-divider/70 align-top last:border-0"
              >
                <td class="py-2.5 font-mono text-xs">
                  {{ resource.resource }}
                </td>
                <td class="py-2.5">
                  <UiStatusBadge :status="runStatus(resource.lastStatus)">
                    {{ resource.lastRunAt ? formatTime(resource.lastRunAt) : '—' }}
                  </UiStatusBadge>
                  <p
                    v-if="resource.error"
                    class="mt-1 max-w-xs break-words text-xs text-rnp-red"
                  >
                    {{ resource.error }}
                  </p>
                </td>
                <td class="py-2.5 tabular-nums text-rnp-text/80">
                  {{ resource.lastSuccessAt ? formatTime(resource.lastSuccessAt) : '—' }}
                </td>
              </tr>
            </tbody>
          </table>
        </UiCard>
      </div>
    </Transition>
  </section>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import Message from 'primevue/message'
import { useApi } from '~/composables/useApi'
import { useAuthStore } from '~/features/auth/stores/authStore'
import { fetchDataStatus } from '~/features/sync/api/dataStatus'
import type { DataStatus, ResourceStatus, SourceStatus } from '~/features/sync/types'
import { sourceHealth } from '~/features/sync/utils/health'
import UiCard from '~/features/ui/components/UiCard.vue'
import UiPageHeader from '~/features/ui/components/UiPageHeader.vue'
import UiPill from '~/features/ui/components/UiPill.vue'
import UiSkeleton from '~/features/ui/components/UiSkeleton.vue'
import UiStatusBadge from '~/features/ui/components/UiStatusBadge.vue'
import type { UiStatus } from '~/features/ui/status'

const { t, locale } = useI18n()
const authStore = useAuthStore()
const isAdmin = computed(() => authStore.me?.isAdmin === true)

const status = ref<DataStatus | null>(null)
const loading = ref(false)
const error = ref<string | null>(null)
const played = ref(false)

async function load() {
  loading.value = true
  error.value = null
  try {
    status.value = await fetchDataStatus(useApi())
    setTimeout(() => (played.value = true), 600)
  }
  catch (e) {
    error.value = e instanceof Error ? e.message : String(e)
  }
  finally {
    loading.value = false
  }
}

function healthStatus(source: SourceStatus): UiStatus {
  return { ok: 'done', failing: 'risk', stale: 'missed', off: 'none' }[sourceHealth(source)] as UiStatus
}

function runStatus(status: ResourceStatus['lastStatus']): UiStatus {
  return status === 'success' ? 'done' : status === 'failed' ? 'missed' : status === 'running' ? 'risk' : 'none'
}

function formatTime(iso: string): string {
  return new Intl.DateTimeFormat(locale.value, {
    day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit', timeZone: 'Europe/Madrid',
  }).format(new Date(iso))
}

onMounted(() => {
  if (isAdmin.value) void load()
})
</script>
