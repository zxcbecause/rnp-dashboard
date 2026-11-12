<template>
  <section class="flex flex-col gap-6">
    <UiPageHeader :subtitle="t('admin.users.subtitle')">
      <UiPill
        v-if="isAdmin"
        icon="pi pi-plus"
        :label="t('admin.users.create')"
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
      closable
      @close="error = null"
    >
      {{ error }}
    </Message>

    <!-- A generated password is shown once: a highlighted card with copy -->
    <Transition name="rnp-fade">
      <div
        v-if="issued"
        class="flex flex-col gap-3 rounded-[var(--rnp-radius-card)] bg-rnp-accent-soft p-5"
      >
        <div class="flex items-center gap-2 text-sm font-semibold text-rnp-text">
          <i
            class="pi pi-lock text-xs"
            aria-hidden="true"
          />
          {{ t('admin.users.passwordOnce', { email: issued.email }) }}
        </div>
        <div class="flex flex-wrap items-center gap-2">
          <code
            class="rounded-xl bg-rnp-pill px-3 py-2 font-mono text-sm text-rnp-text"
            data-testid="issued-password"
          >{{ issued.password }}</code>
          <UiPill
            :icon="copied ? 'pi pi-check' : 'pi pi-copy'"
            :label="copied ? t('admin.users.copied') : t('admin.users.copy')"
            @click="copy"
          />
          <UiPill
            :label="t('admin.users.done')"
            @click="issued = null"
          />
        </div>
      </div>
    </Transition>

    <UiCard
      v-if="isAdmin"
      class="rnp-rise"
    >
      <div class="mb-4 flex h-9 max-w-sm items-center gap-2 rounded-full bg-rnp-bg px-3 text-sm">
        <i
          class="pi pi-search text-xs text-rnp-muted"
          aria-hidden="true"
        />
        <input
          v-model="search"
          type="search"
          class="w-full bg-transparent text-rnp-text outline-none placeholder:text-rnp-muted"
          :placeholder="t('admin.users.search')"
          :aria-label="t('admin.users.search')"
        >
      </div>

      <UiSkeleton
        v-if="loading && !users.length"
        class="h-64"
      />
      <div
        v-else
        class="-mx-1 overflow-x-auto"
      >
        <table class="w-full text-sm md:min-w-[760px]">
          <thead>
            <tr class="border-b border-rnp-divider text-left text-rnp-text">
              <th class="px-2 pb-3 font-semibold">
                {{ t('admin.users.name') }}
              </th>
              <th class="px-2 pb-3 font-semibold max-md:hidden">
                {{ t('admin.users.units') }}
              </th>
              <th class="px-2 pb-3 font-semibold">
                {{ t('admin.users.status') }}
              </th>
              <th class="px-2 pb-3" />
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="user in filtered"
              :key="user.id"
              class="border-b border-rnp-divider/70 align-top transition-colors last:border-0 hover:bg-rnp-accent-soft/40"
              :class="user.isActive ? 'text-rnp-text' : 'text-rnp-muted'"
            >
              <td class="px-2 py-3">
                <p class="flex flex-wrap items-center gap-2 font-semibold">
                  {{ user.name }}
                  <span
                    v-if="user.isAdmin"
                    class="rounded-full bg-rnp-text px-2 py-0.5 text-[11px] font-semibold text-white"
                  >{{ t('admin.users.admin') }}</span>
                  <span
                    v-if="user.id === authStore.me?.id"
                    class="text-xs font-normal text-rnp-muted"
                  >· {{ t('admin.users.you') }}</span>
                </p>
                <p class="text-xs text-rnp-muted">
                  {{ user.email }}
                </p>
              </td>
              <td class="px-2 py-3 max-md:hidden">
                <div
                  v-if="user.memberships.length"
                  class="flex flex-wrap gap-1.5"
                >
                  <span
                    v-for="m in user.memberships"
                    :key="m.orgUnitId"
                    class="rounded-full bg-rnp-pill px-2.5 py-0.5 text-xs"
                  >{{ m.orgUnitName }} · {{ t(`roles.${m.role}`).toLowerCase() }}</span>
                </div>
                <span
                  v-else
                  class="text-xs text-rnp-muted"
                >{{ t('admin.users.noUnits') }}</span>
              </td>
              <td class="px-2 py-3">
                <UiStatusBadge
                  :status="user.isActive ? 'done' : 'none'"
                  :label="user.isActive ? t('admin.users.active') : t('admin.users.inactive')"
                />
              </td>
              <td class="px-2 py-3 text-right">
                <UiPill
                  icon="pi pi-pencil"
                  :label="t('admin.users.edit')"
                  @click="openEdit(user)"
                />
              </td>
            </tr>
            <tr v-if="!loading && filtered.length === 0">
              <td
                colspan="4"
                class="px-2 py-8 text-center text-rnp-muted"
              >
                {{ t('admin.users.empty') }}
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </UiCard>

    <!-- Create -->
    <Dialog
      v-model:visible="createOpen"
      modal
      :header="t('admin.users.create')"
      class="w-full max-w-md"
    >
      <form
        class="flex flex-col gap-4"
        @submit.prevent="create"
      >
        <label class="flex flex-col gap-1.5">
          <span class="text-sm font-medium text-rnp-text">{{ t('admin.users.email') }}</span>
          <InputText
            v-model="createForm.email"
            type="email"
            autocomplete="off"
            fluid
            autofocus
          />
        </label>
        <label class="flex flex-col gap-1.5">
          <span class="text-sm font-medium text-rnp-text">{{ t('admin.users.name') }}</span>
          <InputText
            v-model="createForm.name"
            autocomplete="off"
            fluid
          />
        </label>
        <label class="flex items-start gap-2 text-sm text-rnp-text">
          <Checkbox
            v-model="createForm.isAdmin"
            binary
          />
          {{ t('admin.users.adminLabel') }}
        </label>
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
            :disabled="creating"
            @click="createOpen = false"
          />
          <Button
            type="submit"
            :label="t('admin.users.add')"
            :loading="creating"
            :disabled="creating"
          />
        </div>
      </form>
    </Dialog>

    <!-- Edit -->
    <Dialog
      v-model:visible="editOpen"
      modal
      :header="editing ? t('admin.users.editTitle', { email: editing.email }) : ''"
      class="w-full max-w-md"
    >
      <form
        v-if="editing"
        class="flex flex-col gap-4"
        @submit.prevent="save"
      >
        <label class="flex flex-col gap-1.5">
          <span class="text-sm font-medium text-rnp-text">{{ t('admin.users.name') }}</span>
          <InputText
            v-model="editForm.name"
            autocomplete="off"
            fluid
          />
        </label>
        <label class="flex items-start gap-2 text-sm text-rnp-text">
          <Checkbox
            v-model="editForm.isActive"
            binary
            :disabled="isSelf"
          />
          {{ t('admin.users.activeLabel') }}
        </label>
        <label class="flex items-start gap-2 text-sm text-rnp-text">
          <Checkbox
            v-model="editForm.isAdmin"
            binary
            :disabled="isSelf"
          />
          {{ t('admin.users.adminLabel') }}
        </label>
        <Message
          v-if="formError"
          severity="error"
          size="small"
        >
          {{ formError }}
        </Message>
        <div class="flex flex-wrap items-center justify-between gap-2">
          <div class="flex gap-1">
            <Button
              type="button"
              icon="pi pi-refresh"
              :label="t('admin.users.resetPassword')"
              severity="secondary"
              text
              size="small"
              :disabled="busy"
              :loading="resetting"
              @click="reset"
            />
            <Button
              v-if="!isSelf"
              type="button"
              icon="pi pi-trash"
              :label="t('admin.users.delete')"
              severity="danger"
              text
              size="small"
              :disabled="busy"
              :loading="deleting"
              @click="remove"
            />
          </div>
          <div class="flex gap-2">
            <Button
              type="button"
              :label="t('common.cancel')"
              severity="secondary"
              text
              :disabled="busy"
              @click="editOpen = false"
            />
            <Button
              type="submit"
              :label="t('common.save')"
              :loading="saving"
              :disabled="busy"
            />
          </div>
        </div>
      </form>
    </Dialog>
  </section>
</template>

<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import Checkbox from 'primevue/checkbox'
import Dialog from 'primevue/dialog'
import InputText from 'primevue/inputtext'
import Message from 'primevue/message'
import { useApi } from '~/composables/useApi'
import { useAsyncAction } from '~/composables/useAsyncAction'
import { createAdminUser, deleteAdminUser, fetchAdminUsers, resetAdminUserPassword, updateAdminUser } from '~/features/admin/api/users'
import type { AdminUser } from '~/features/admin/types'
import { useAuthStore } from '~/features/auth/stores/authStore'
import UiCard from '~/features/ui/components/UiCard.vue'
import UiPageHeader from '~/features/ui/components/UiPageHeader.vue'
import UiPill from '~/features/ui/components/UiPill.vue'
import UiSkeleton from '~/features/ui/components/UiSkeleton.vue'
import UiStatusBadge from '~/features/ui/components/UiStatusBadge.vue'
import { ApiError } from '~/types/api-errors'

const { t } = useI18n()
const authStore = useAuthStore()
const isAdmin = computed(() => authStore.me?.isAdmin === true)

const users = ref<AdminUser[]>([])
const loading = ref(false)
const error = ref<string | null>(null)
const search = ref('')
const issued = ref<{ email: string, password: string } | null>(null)
const copied = ref(false)
const formError = ref<string | null>(null)

const createOpen = ref(false)
const createForm = reactive({ email: '', name: '', isAdmin: false })
const editOpen = ref(false)
const editing = ref<AdminUser | null>(null)
const editForm = reactive({ name: '', isAdmin: false, isActive: true })
const isSelf = computed(() => editing.value?.id === authStore.me?.id)

const filtered = computed(() => {
  const query = search.value.trim().toLowerCase()
  if (!query) return users.value
  return users.value.filter(user => user.name.toLowerCase().includes(query) || user.email.includes(query))
})

/** User-facing text of a refused change (422 carries it), anything else is "unknown error". */
const message = (e: unknown) => (e instanceof ApiError && e.statusCode === 422 && e.message ? e.message : t('errors.unknown'))

async function load() {
  loading.value = true
  try {
    users.value = await fetchAdminUsers(useApi())
  }
  catch (e) {
    error.value = e instanceof Error ? e.message : String(e)
  }
  finally {
    loading.value = false
  }
}

function showPassword(email: string, password: string) {
  issued.value = { email, password }
  copied.value = false
}

function openCreate() {
  Object.assign(createForm, { email: '', name: '', isAdmin: false })
  formError.value = null
  createOpen.value = true
}

function openEdit(user: AdminUser) {
  editing.value = user
  Object.assign(editForm, { name: user.name, isAdmin: user.isAdmin, isActive: user.isActive })
  formError.value = null
  editOpen.value = true
}

const { pending: creating, run: create } = useAsyncAction(
  async () => {
    const result = await createAdminUser(useApi(), { email: createForm.email.trim(), name: createForm.name.trim(), isAdmin: createForm.isAdmin })
    createOpen.value = false
    showPassword(result.item.email, result.password)
    await load()
  },
  {
    canRun: () => {
      formError.value = createForm.email.trim() && createForm.name.trim() ? null : t('admin.users.fill')
      return formError.value === null
    },
    onError: (e) => { formError.value = message(e) },
    rethrow: false,
  },
)

const { pending: saving, run: save } = useAsyncAction(
  async () => {
    if (!editing.value) return
    await updateAdminUser(useApi(), editing.value.id, { name: editForm.name.trim(), isAdmin: editForm.isAdmin, isActive: editForm.isActive })
    editOpen.value = false
    await load()
  },
  { onError: (e) => { formError.value = message(e) }, rethrow: false },
)

const { pending: resetting, run: runReset } = useAsyncAction(
  async (user: AdminUser) => {
    const { password } = await resetAdminUserPassword(useApi(), user.id)
    editOpen.value = false
    showPassword(user.email, password)
  },
  { onError: (e) => { formError.value = message(e) }, rethrow: false },
)

const { pending: deleting, run: runDelete } = useAsyncAction(
  async (user: AdminUser) => {
    await deleteAdminUser(useApi(), user.id)
    editOpen.value = false
    await load()
  },
  { onError: (e) => { formError.value = message(e) }, rethrow: false },
)

const busy = computed(() => saving.value || resetting.value || deleting.value)

function reset() {
  const user = editing.value
  if (user && window.confirm(t('admin.users.resetConfirm', { email: user.email }))) void runReset(user)
}

function remove() {
  const user = editing.value
  if (user && window.confirm(t('admin.users.deleteConfirm', { email: user.email }))) void runDelete(user)
}

async function copy() {
  if (!issued.value) return
  try {
    await navigator.clipboard.writeText(issued.value.password)
    copied.value = true
  }
  catch {
    // Clipboard may be unavailable: the password stays visible for manual copy.
  }
}

onMounted(() => {
  if (isAdmin.value) void load()
})
</script>
