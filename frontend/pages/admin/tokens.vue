<template>
  <section class="flex flex-col gap-6">
    <UiPageHeader :subtitle="t('tokens.subtitle')">
      <UiPill
        v-if="isAdmin"
        icon="pi pi-plus"
        :label="t('tokens.create')"
        active
        @click="openCreate"
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

    <!-- A new token is shown once: a highlighted card with copy -->
    <Transition name="rnp-fade">
      <div
        v-if="created"
        class="flex flex-col gap-3 rounded-[var(--rnp-radius-card)] bg-rnp-accent-soft p-5"
      >
        <div class="flex items-center gap-2 text-sm font-semibold text-rnp-text">
          <i
            class="pi pi-key text-xs"
            aria-hidden="true"
          />
          {{ t('tokens.createdOnce', { name: created.item.name }) }}
        </div>
        <div class="flex flex-wrap items-center gap-2">
          <code class="break-all rounded-xl bg-rnp-pill px-3 py-2 font-mono text-xs text-rnp-text">{{ created.token }}</code>
          <UiPill
            :icon="copied ? 'pi pi-check' : 'pi pi-copy'"
            :label="copied ? t('tokens.copied') : t('tokens.copy')"
            @click="copy"
          />
          <UiPill
            :label="t('tokens.done')"
            @click="created = null"
          />
        </div>
      </div>
    </Transition>

    <UiCard
      v-if="isAdmin"
      class="rnp-rise"
    >
      <div class="-mx-1 overflow-x-auto">
        <table class="w-full min-w-[720px] text-sm">
          <thead>
            <tr class="border-b border-rnp-divider text-left text-rnp-text">
              <th class="px-2 pb-3 font-semibold">
                {{ t('tokens.name') }}
              </th>
              <th class="px-2 pb-3 font-semibold">
                {{ t('tokens.unit') }}
              </th>
              <th class="px-2 pb-3 font-semibold">
                {{ t('tokens.hint') }}
              </th>
              <th class="px-2 pb-3 font-semibold">
                {{ t('tokens.lastUsed') }}
              </th>
              <th class="px-2 pb-3 font-semibold">
                {{ t('tokens.status') }}
              </th>
              <th class="px-2 pb-3" />
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="token in tokens"
              :key="token.id"
              class="border-b border-rnp-divider/70 transition-colors last:border-0 hover:bg-rnp-accent-soft/40"
              :class="token.revokedAt ? 'text-rnp-muted' : 'text-rnp-text'"
            >
              <td class="px-2 py-3 font-mono text-xs font-semibold">
                {{ token.name }}
              </td>
              <td class="px-2 py-3">
                {{ token.orgUnitName }}
              </td>
              <td class="px-2 py-3">
                <code class="rounded-lg bg-rnp-bg px-2 py-0.5 font-mono text-xs">{{ token.hint }}</code>
              </td>
              <td class="px-2 py-3 text-rnp-muted">
                {{ token.lastUsedAt ? formatDateTime(token.lastUsedAt) : t('tokens.never') }}
              </td>
              <td class="px-2 py-3">
                <UiStatusBadge
                  :status="token.revokedAt ? 'none' : 'done'"
                  :label="token.revokedAt ? t('tokens.revokedAt', { date: formatDateTime(token.revokedAt) }) : t('tokens.active')"
                />
              </td>
              <td class="px-2 py-3 text-right">
                <button
                  v-if="!token.revokedAt"
                  type="button"
                  class="inline-flex h-8 items-center gap-1.5 rounded-full px-3 text-xs font-semibold text-rnp-red transition-colors hover:bg-rnp-red-soft disabled:opacity-50"
                  :disabled="revoking !== null"
                  @click="revoke(token)"
                >
                  <i
                    :class="revoking === token.id ? 'pi pi-spin pi-spinner' : 'pi pi-ban'"
                    class="text-[10px]"
                    aria-hidden="true"
                  />
                  {{ t('tokens.revoke') }}
                </button>
              </td>
            </tr>
            <tr v-if="!loading && tokens.length === 0">
              <td
                colspan="6"
                class="px-2 py-8 text-center text-rnp-muted"
              >
                {{ t('tokens.empty') }}
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <p class="mt-4 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-rnp-muted">
        {{ t('tokens.usage') }}
        <code class="rounded-lg bg-rnp-bg px-2 py-0.5 font-mono text-rnp-text">POST /api/ingest/metric-values</code>
        <code class="rounded-lg bg-rnp-bg px-2 py-0.5 font-mono text-rnp-text">Authorization: Bearer rnp_…</code>
      </p>
    </UiCard>

    <Dialog
      v-model:visible="createOpen"
      modal
      :header="t('tokens.create')"
      class="w-full max-w-md"
    >
      <form
        class="flex flex-col gap-4"
        @submit.prevent="create"
      >
        <div class="flex flex-col gap-1.5">
          <label
            for="token-name"
            class="text-sm font-medium text-rnp-text"
          >{{ t('tokens.name') }}</label>
          <InputText
            id="token-name"
            v-model="form.name"
            placeholder="cs-nps-script"
            fluid
            autofocus
          />
          <small class="text-xs text-rnp-muted">{{ t('tokens.nameRule') }}</small>
        </div>
        <div class="flex flex-col gap-1.5">
          <span class="text-sm font-medium text-rnp-text">{{ t('tokens.unit') }}</span>
          <OrgUnitSelect
            :model-value="form.orgUnitId"
            :tree="orgStore.tree"
            :loading="orgStore.loading"
            @update:model-value="form.orgUnitId = $event"
          />
          <small class="text-xs text-rnp-muted">{{ t('tokens.unitRule') }}</small>
        </div>
        <Message
          v-if="formError"
          severity="error"
          size="small"
        >
          {{ formError }}
        </Message>
        <div class="flex justify-end gap-2">
          <Button
            type="button"
            :label="t('common.cancel')"
            severity="secondary"
            text
            @click="createOpen = false"
          />
          <Button
            type="submit"
            :label="t('tokens.issue')"
            :loading="creating"
            :disabled="creating"
          />
        </div>
      </form>
    </Dialog>
  </section>
</template>

<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import Dialog from 'primevue/dialog'
import InputText from 'primevue/inputtext'
import Message from 'primevue/message'
import { useApi } from '~/composables/useApi'
import { useAsyncAction } from '~/composables/useAsyncAction'
import { createIngestToken, fetchIngestTokens, revokeIngestToken } from '~/features/admin/api/ingestTokens'
import type { CreatedIngestToken, IngestToken } from '~/features/admin/types'
import { useAuthStore } from '~/features/auth/stores/authStore'
import OrgUnitSelect from '~/features/org/components/OrgUnitSelect.vue'
import { useOrgStore } from '~/features/org/stores/orgStore'
import UiCard from '~/features/ui/components/UiCard.vue'
import UiPageHeader from '~/features/ui/components/UiPageHeader.vue'
import UiPill from '~/features/ui/components/UiPill.vue'
import UiStatusBadge from '~/features/ui/components/UiStatusBadge.vue'
import { ApiError } from '~/types/api-errors'

const { t, locale } = useI18n()
const authStore = useAuthStore()
const orgStore = useOrgStore()
const isAdmin = computed(() => authStore.me?.isAdmin === true)

const tokens = ref<IngestToken[]>([])
const loading = ref(false)
const error = ref<string | null>(null)
const created = ref<CreatedIngestToken | null>(null)
const copied = ref(false)
const revoking = ref<number | null>(null)

const createOpen = ref(false)
const form = reactive({ name: '', orgUnitId: null as number | null })
const formError = ref<string | null>(null)

async function load() {
  loading.value = true
  error.value = null
  try {
    tokens.value = await fetchIngestTokens(useApi())
  }
  catch (e) {
    error.value = e instanceof Error ? e.message : String(e)
  }
  finally {
    loading.value = false
  }
}

function openCreate() {
  form.name = ''
  form.orgUnitId = null
  formError.value = null
  createOpen.value = true
}

const { pending: creating, run: create } = useAsyncAction(
  async () => {
    const result = await createIngestToken(useApi(), form.name.trim(), form.orgUnitId ?? 0)
    created.value = result
    copied.value = false
    createOpen.value = false
    await load()
  },
  {
    canRun: () => {
      formError.value = form.name.trim() && form.orgUnitId ? null : t('tokens.fillBoth')
      return formError.value === null
    },
    onError: (e) => {
      formError.value = e instanceof ApiError && e.statusCode === 422 && e.message ? e.message : t('errors.unknown')
    },
    rethrow: false,
  },
)

const { run: runRevoke } = useAsyncAction(
  async (token: IngestToken) => {
    revoking.value = token.id
    try {
      await revokeIngestToken(useApi(), token.id)
      await load()
    }
    finally {
      revoking.value = null
    }
  },
  { onError: (e) => { error.value = e instanceof Error ? e.message : String(e) }, rethrow: false },
)

function revoke(token: IngestToken) {
  if (window.confirm(t('tokens.revokeConfirm', { name: token.name }))) void runRevoke(token)
}

async function copy() {
  if (!created.value) return
  try {
    await navigator.clipboard.writeText(created.value.token)
    copied.value = true
  }
  catch {
    // Clipboard may be unavailable (non-secure context): the token stays visible for manual copy.
  }
}

function formatDateTime(iso: string): string {
  return new Intl.DateTimeFormat(locale.value, {
    day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit', timeZone: 'Europe/Madrid',
  }).format(new Date(iso))
}

onMounted(() => {
  if (!isAdmin.value) return
  void load()
  if (!orgStore.units.length) void orgStore.load()
})
</script>
