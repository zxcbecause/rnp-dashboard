<template>
  <section class="flex flex-col gap-6">
    <UiPageHeader :subtitle="t('calls.subtitle')">
      <UiPillSelect
        v-if="callUnits.length > 1"
        :model-value="unitId"
        :options="callUnits.map(u => ({ label: u.name, value: u.id }))"
        :placeholder="t('calls.unit')"
        @update:model-value="go({ unit: $event ?? undefined })"
      />
      <UiPill
        :label="t('calls.filterMissed')"
        :active="!showAll"
        @click="go({ all: false })"
      />
      <UiPill
        :label="t('calls.filterAll')"
        :active="showAll"
        @click="go({ all: true })"
      />
      <div class="flex h-9 items-center gap-1 rounded-full bg-rnp-pill px-3 text-sm text-rnp-text">
        <i
          class="pi pi-calendar mr-1 text-xs text-rnp-muted"
          aria-hidden="true"
        />
        <input
          :value="data?.from ?? ''"
          type="date"
          class="rnp-date-input"
          :aria-label="t('calls.from')"
          @change="go({ from: ($event.target as HTMLInputElement).value })"
        >
        <span class="text-rnp-muted">—</span>
        <input
          :value="data?.to ?? ''"
          type="date"
          class="rnp-date-input"
          :aria-label="t('calls.to')"
          @change="go({ to: ($event.target as HTMLInputElement).value })"
        >
      </div>
    </UiPageHeader>

    <Message
      v-if="!unitId"
      severity="info"
    >
      {{ t('calls.noUnits') }}
    </Message>
    <Message
      v-else-if="error"
      :severity="error === 'not_found' ? 'warn' : 'error'"
    >
      {{ error === 'not_found' ? t('rnp.unitNotAvailable') : error }}
    </Message>

    <UiSkeleton
      v-if="!data && loading"
      class="h-80"
      rounded="rounded-[20px]"
    />

    <Transition
      name="rnp-fade"
      mode="out-in"
    >
      <div
        v-if="data"
        :key="`${data.orgUnit.id}-${data.from}-${data.to}-${showAll}`"
        class="flex flex-col gap-6 transition-opacity"
        :class="loading ? 'opacity-60' : ''"
      >
        <UiCard
          v-if="data.pending.length"
          :title="t('calls.pendingTitle', { n: data.pending.length })"
        >
          <p class="mb-3 text-sm text-rnp-muted">
            {{ data.canDecide ? t('calls.pendingHintHead') : t('calls.pendingHint') }}
          </p>
          <ul>
            <li
              v-for="item in data.pending"
              :key="item.id"
              class="flex flex-wrap items-start justify-between gap-3 border-b border-rnp-divider/70 py-3 first:pt-0 last:border-0 last:pb-0"
            >
              <div class="min-w-0 flex-1">
                <p class="text-sm font-semibold text-rnp-text">
                  {{ formatCallTime(item.callStartedAt) }}
                  <span
                    v-if="item.responsibleId !== null"
                    class="font-normal text-rnp-muted"
                  > · {{ t('calls.responsible', { id: item.responsibleId }) }}</span>
                </p>
                <p class="mt-1 whitespace-pre-line text-sm text-rnp-text/80">
                  <span class="font-medium">{{ t(`calls.category.${item.category}`) }}.</span> {{ item.reason }}
                </p>
                <p class="mt-1 text-xs text-rnp-muted">
                  {{ t('calls.requestedBy', { name: item.requestedBy?.name ?? '—' }) }}
                </p>
              </div>
              <div class="flex gap-2">
                <UiPill
                  v-if="item.hasImage"
                  icon="pi pi-image"
                  :label="t('calls.viewImage')"
                  @click="viewingId = item.id"
                />
                <UiPill
                  v-if="item.canWithdraw"
                  icon="pi pi-undo"
                  :label="t('calls.withdraw')"
                  :disabled="withdrawing"
                  @click="withdraw(item)"
                />
                <UiPill
                  v-if="data.canDecide"
                  icon="pi pi-check"
                  :label="t('calls.decide')"
                  active
                  @click="openDecision(item)"
                />
              </div>
            </li>
          </ul>
        </UiCard>

        <UiCard :title="t(showAll ? 'calls.listTitleAll' : 'calls.listTitle', { from: formatDay(data.from), to: formatDay(data.to) })">
          <p class="mb-3 text-sm text-rnp-muted">
            {{ t('calls.listHint', { date: formatDay(data.editableFrom) }) }}
          </p>
          <ul v-if="data.calls.length">
            <li
              v-for="call in data.calls"
              :key="call.callId"
              class="flex flex-wrap items-center justify-between gap-3 border-b border-rnp-divider/70 py-3 first:pt-0 last:border-0 last:pb-0"
            >
              <div class="min-w-0 flex-1">
                <p class="flex flex-wrap items-center gap-2 text-sm">
                  <span class="font-semibold tabular-nums text-rnp-text">{{ formatCallTime(call.startedAt) }}</span>
                  <span
                    v-if="call.responsibleId !== null"
                    class="text-rnp-muted"
                  >{{ t('calls.responsible', { id: call.responsibleId }) }}</span>
                  <a
                    v-if="call.crmUrl"
                    :href="call.crmUrl"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="inline-flex items-center gap-1 text-xs font-medium text-rnp-muted underline-offset-2 hover:text-rnp-text hover:underline"
                  >
                    <i
                      class="pi pi-external-link text-[10px]"
                      aria-hidden="true"
                    />{{ t('calls.openCrm') }}
                  </a>
                  <span
                    v-if="!call.missed"
                    class="rounded-full bg-rnp-pill px-2 py-0.5 text-xs text-rnp-muted"
                  >{{ t('calls.answered') }}</span>
                  <UiStatusBadge
                    v-if="call.exclusion"
                    :status="STATUS_TONE[call.exclusion.status]"
                    :label="t(`calls.status.${call.exclusion.status}`)"
                  />
                </p>
                <p
                  v-if="call.exclusion"
                  class="mt-1 text-xs text-rnp-muted"
                >
                  {{ t(`calls.category.${call.exclusion.category}`) }}. {{ call.exclusion.reason }}
                  <template v-if="call.exclusion.decisionNote">
                    — {{ call.exclusion.decidedBy?.name }}: {{ call.exclusion.decisionNote }}
                  </template>
                  <button
                    v-if="call.exclusion.hasImage"
                    type="button"
                    class="ml-1 inline-flex items-center gap-1 font-medium underline-offset-2 hover:text-rnp-text hover:underline"
                    @click="viewingId = call.exclusion.id"
                  >
                    <i
                      class="pi pi-image text-[10px]"
                      aria-hidden="true"
                    />{{ t('calls.viewImage') }}
                  </button>
                </p>
              </div>
              <UiPill
                v-if="call.canRequest"
                icon="pi pi-minus-circle"
                :label="t('calls.request')"
                @click="openRequest(call)"
              />
            </li>
          </ul>
          <p
            v-else
            class="py-8 text-center text-sm text-rnp-muted"
          >
            {{ showAll ? t('calls.emptyAll') : t('calls.empty') }}
          </p>
        </UiCard>
      </div>
    </Transition>

    <CallExclusionDialog
      v-if="data"
      v-model:visible="dialog"
      :org-unit-id="data.orgUnit.id"
      :call="requesting"
      :exclusion="deciding"
      :can-decide="data.canDecide"
      @saved="onSaved"
    />

    <Dialog
      :visible="viewingId !== null"
      modal
      :header="t('calls.imageTitle')"
      class="w-full max-w-3xl"
      @update:visible="viewingId = null"
    >
      <CallExclusionImage
        v-if="viewingId !== null"
        :key="viewingId"
        :exclusion-id="viewingId"
      />
    </Dialog>
  </section>
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import Dialog from 'primevue/dialog'
import Message from 'primevue/message'
import { useToast } from 'primevue/usetoast'
import { navigateTo, useRoute } from '#app'
import { useApi } from '~/composables/useApi'
import { useAsyncAction } from '~/composables/useAsyncAction'
import { createLatestRequestScope } from '~/composables/useLatestRequest'
import { useAuthStore } from '~/features/auth/stores/authStore'
import { fetchMissedCalls, withdrawCallExclusion } from '~/features/calls/api/calls'
import CallExclusionDialog from '~/features/calls/components/CallExclusionDialog.vue'
import CallExclusionImage from '~/features/calls/components/CallExclusionImage.vue'
import type { CallExclusion, CallExclusionStatus, MissedCall, MissedCalls } from '~/features/calls/types'
import { formatCallTime, formatDay } from '~/features/calls/utils/format'
import UiCard from '~/features/ui/components/UiCard.vue'
import UiPageHeader from '~/features/ui/components/UiPageHeader.vue'
import UiPill from '~/features/ui/components/UiPill.vue'
import UiPillSelect from '~/features/ui/components/UiPillSelect.vue'
import UiSkeleton from '~/features/ui/components/UiSkeleton.vue'
import UiStatusBadge from '~/features/ui/components/UiStatusBadge.vue'
import type { UiStatus } from '~/features/ui/status'
import { NotFoundError } from '~/types/api-errors'

const STATUS_TONE: Record<CallExclusionStatus, UiStatus> = { pending: 'risk', approved: 'done', rejected: 'missed' }

const { t } = useI18n()
const route = useRoute()
const toast = useToast()
const authStore = useAuthStore()

const data = ref<MissedCalls | null>(null)
const loading = ref(false)
const error = ref<string | null>(null)
const dialog = ref(false)
const requesting = ref<MissedCall | null>(null)
const deciding = ref<CallExclusion | null>(null)
/** request whose screenshot is open in the viewer */
const viewingId = ref<number | null>(null)
const scope = createLatestRequestScope()

const callUnits = computed(() => authStore.me?.callUnits ?? [])
const param = (name: string) => (typeof route.query[name] === 'string' ? route.query[name] as string : null)
const unitId = computed<number | null>(() => {
  const fromQuery = Number(route.query.unit)
  if (Number.isInteger(fromQuery) && fromQuery > 0) return fromQuery
  return callUnits.value[0]?.id ?? null
})

const showAll = computed(() => route.query.all === '1')

function go(change: { unit?: number, from?: string, to?: string, all?: boolean }) {
  const query = { unit: unitId.value ? String(unitId.value) : undefined, from: param('from') ?? undefined, to: param('to') ?? undefined, all: (change.all ?? showAll.value) ? '1' : undefined }
  if (change.unit) query.unit = String(change.unit)
  if (change.from !== undefined) query.from = change.from || undefined
  if (change.to !== undefined) query.to = change.to || undefined
  void navigateTo({ path: '/calls', query: Object.fromEntries(Object.entries(query).filter(([, v]) => v)) })
}

async function load() {
  if (!unitId.value) return
  const isLatest = scope.begin()
  loading.value = true
  error.value = null
  try {
    const result = await fetchMissedCalls(useApi(), unitId.value, param('from'), param('to'), showAll.value)
    if (!isLatest()) return
    data.value = result
  }
  catch (e) {
    if (!isLatest()) return
    data.value = null
    error.value = e instanceof NotFoundError ? 'not_found' : (e instanceof Error ? e.message : String(e))
  }
  finally {
    if (isLatest()) loading.value = false
  }
}

function openRequest(call: MissedCall) {
  requesting.value = call
  deciding.value = null
  dialog.value = true
}

function openDecision(item: CallExclusion) {
  requesting.value = null
  deciding.value = item
  dialog.value = true
}

function onSaved(recalculated: boolean) {
  if (!recalculated) toast.add({ severity: 'info', summary: t('calls.recalcLater'), life: 5000 })
  void load()
}

const { pending: withdrawing, run: withdraw } = useAsyncAction(async (item: CallExclusion) => {
  await withdrawCallExclusion(useApi(), item.id)
  await load()
}, { rethrow: false, onError: (e) => { toast.add({ severity: 'error', summary: e instanceof Error ? e.message : String(e), life: 5000 }) } })

watch([unitId, () => route.query.from, () => route.query.to, showAll], () => void load(), { immediate: true })
</script>
