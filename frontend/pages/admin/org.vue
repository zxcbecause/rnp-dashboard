<template>
  <section class="flex flex-col gap-6">
    <UiPageHeader :subtitle="t('admin.org.subtitle')">
      <UiPill
        v-if="isAdmin"
        icon="pi pi-plus"
        :label="t('admin.org.createUnit')"
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

    <UiCard
      v-if="isAdmin"
      class="rnp-rise"
    >
      <UiSkeleton
        v-if="loading && !units.length"
        class="h-64"
      />
      <ul v-else>
        <li
          v-for="row in rows"
          :key="row.unit.id"
          class="flex flex-wrap items-center gap-3 border-b border-rnp-divider/70 py-3 last:border-0"
          :style="{ paddingLeft: `${row.depth * 1.5}rem` }"
          :data-testid="`unit-${row.unit.name}`"
        >
          <i
            :class="row.depth === 0 ? 'pi pi-building' : 'pi pi-angle-right'"
            class="text-xs text-rnp-muted"
            aria-hidden="true"
          />
          <div class="min-w-0 flex-1">
            <p class="font-semibold text-rnp-text">
              {{ row.unit.name }}
            </p>
            <p class="text-xs text-rnp-muted">
              {{ headName(row.unit) ?? t('admin.org.noHead') }}
              · {{ t('admin.org.members') }}: {{ row.unit.members.length }}
              · {{ t('admin.org.metrics', { n: row.unit.activeMetrics }) }}
            </p>
          </div>
          <UiPill
            icon="pi pi-cog"
            :label="t('admin.org.edit')"
            @click="openEdit(row.unit.id)"
          />
        </li>
      </ul>
    </UiCard>

    <!-- Create -->
    <Dialog
      v-model:visible="createOpen"
      modal
      :header="t('admin.org.createUnit')"
      class="w-full max-w-md"
    >
      <form
        class="flex flex-col gap-4"
        @submit.prevent="create"
      >
        <label class="flex flex-col gap-1.5">
          <span class="text-sm font-medium text-rnp-text">{{ t('admin.org.name') }}</span>
          <InputText
            v-model="createForm.name"
            autocomplete="off"
            fluid
            autofocus
          />
        </label>
        <div class="flex flex-col gap-1.5">
          <span class="text-sm font-medium text-rnp-text">{{ t('admin.org.parent') }}</span>
          <Select
            v-model="createForm.parentId"
            :options="unitOptions(rows)"
            option-label="label"
            option-value="value"
            :placeholder="t('admin.org.selectUnit')"
            fluid
          />
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
            :disabled="creating"
            @click="createOpen = false"
          />
          <Button
            type="submit"
            :label="t('admin.org.create')"
            :loading="creating"
            :disabled="creating"
          />
        </div>
      </form>
    </Dialog>

    <!-- Configure a unit: name and place, head, members -->
    <Dialog
      v-model:visible="editOpen"
      modal
      :header="editing ? t('admin.org.editTitle', { name: editing.name }) : ''"
      class="w-full max-w-2xl"
    >
      <div
        v-if="editing"
        class="flex flex-col gap-6"
      >
        <form
          class="flex flex-col gap-3"
          @submit.prevent="saveUnit"
        >
          <div class="grid gap-3 sm:grid-cols-2">
            <label class="flex flex-col gap-1.5">
              <span class="text-sm font-medium text-rnp-text">{{ t('admin.org.name') }}</span>
              <InputText
                v-model="unitForm.name"
                autocomplete="off"
                fluid
              />
            </label>
            <div class="flex flex-col gap-1.5">
              <span class="text-sm font-medium text-rnp-text">{{ t('admin.org.parent') }}</span>
              <Select
                v-if="editing.parentId !== null"
                v-model="unitForm.parentId"
                :options="unitOptions(parentOptions(units, editing.id))"
                option-label="label"
                option-value="value"
                fluid
              />
              <p
                v-else
                class="flex h-10 items-center text-sm text-rnp-muted"
              >
                {{ t('admin.org.rootHint') }}
              </p>
            </div>
          </div>
          <div class="flex flex-wrap items-center justify-between gap-2">
            <small class="text-xs text-rnp-muted">{{ editing.parentId !== null ? t('admin.org.moveHint') : '' }}</small>
            <Button
              type="submit"
              class="ml-auto"
              :label="t('admin.org.save')"
              size="small"
              :loading="savingUnit"
              :disabled="busy || !unitChanged"
            />
          </div>
        </form>

        <div class="flex flex-col gap-1.5">
          <span class="text-sm font-medium text-rnp-text">{{ t('admin.org.head') }}</span>
          <Select
            :key="`head-${editing.id}-${selectReset}`"
            :model-value="editing.headUserId"
            :options="headOptions"
            option-label="label"
            option-value="value"
            filter
            :disabled="busy"
            :aria-label="t('admin.org.head')"
            fluid
            @update:model-value="changeHead($event)"
          />
          <small class="text-xs text-rnp-muted">{{ t('admin.org.headHint') }}</small>
        </div>

        <div class="flex flex-col gap-2">
          <span class="text-sm font-medium text-rnp-text">{{ t('admin.org.members') }}</span>
          <ul
            v-if="editing.members.length"
            class="rounded-2xl bg-rnp-bg px-3"
          >
            <li
              v-for="member in editing.members"
              :key="member.userId"
              class="flex flex-wrap items-center gap-2 border-b border-rnp-divider/70 py-2 last:border-0"
            >
              <div class="min-w-0 flex-1 text-sm">
                <p
                  class="font-medium"
                  :class="member.isActive ? 'text-rnp-text' : 'text-rnp-muted line-through'"
                >
                  {{ member.name }}
                </p>
                <p class="text-xs text-rnp-muted">
                  {{ member.email }}
                </p>
              </div>
              <Select
                v-if="member.role === 'member' || member.role === 'viewer'"
                :key="`role-${member.userId}-${selectReset}`"
                :model-value="member.role"
                :options="roleOptions"
                option-label="label"
                option-value="value"
                size="small"
                :disabled="busy"
                :aria-label="t('admin.org.role')"
                @update:model-value="changeRole(member.userId, $event)"
              />
              <span
                v-else
                class="rounded-full bg-rnp-pill px-2.5 py-1 text-xs font-semibold"
              >{{ t(`roles.${member.role}`) }}</span>
              <Button
                icon="pi pi-times"
                severity="secondary"
                text
                rounded
                size="small"
                :aria-label="t('admin.org.remove')"
                :disabled="busy"
                @click="removeMember(member.userId, member.name)"
              />
            </li>
          </ul>
          <p
            v-else
            class="text-sm text-rnp-muted"
          >
            {{ t('admin.org.noMembers') }}
          </p>
          <div class="flex flex-wrap items-center gap-2">
            <Select
              v-model="newMember.userId"
              :options="candidateOptions"
              option-label="label"
              option-value="value"
              filter
              :placeholder="t('admin.org.selectUser')"
              class="min-w-64 flex-1"
            />
            <Select
              v-model="newMember.role"
              :options="roleOptions"
              option-label="label"
              option-value="value"
              :aria-label="t('admin.org.role')"
            />
            <Button
              icon="pi pi-plus"
              :label="t('admin.org.addMember')"
              size="small"
              :disabled="busy || newMember.userId === null"
              @click="addMember"
            />
          </div>
        </div>

        <Message
          v-if="formError"
          severity="error"
          size="small"
        >
          {{ formError }}
        </Message>
      </div>
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
import Select from 'primevue/select'
import { useApi } from '~/composables/useApi'
import { useAsyncAction } from '~/composables/useAsyncAction'
import {
  createAdminOrgUnit,
  fetchAdminOrgUnits,
  removeAdminOrgUnitMember,
  setAdminOrgUnitHead,
  setAdminOrgUnitMember,
  updateAdminOrgUnit,
} from '~/features/admin/api/orgUnits'
import { fetchAdminUsers } from '~/features/admin/api/users'
import type { AdminOrgUnit, AdminUser } from '~/features/admin/types'
import { orgRows, parentOptions } from '~/features/admin/utils/orgTree'
import type { OrgRow } from '~/features/admin/utils/orgTree'
import { useAuthStore } from '~/features/auth/stores/authStore'
import { useOrgStore } from '~/features/org/stores/orgStore'
import UiCard from '~/features/ui/components/UiCard.vue'
import UiPageHeader from '~/features/ui/components/UiPageHeader.vue'
import UiPill from '~/features/ui/components/UiPill.vue'
import UiSkeleton from '~/features/ui/components/UiSkeleton.vue'
import { ApiError } from '~/types/api-errors'

type MemberRole = 'member' | 'viewer'

const { t } = useI18n()
const authStore = useAuthStore()
const orgStore = useOrgStore()
const isAdmin = computed(() => authStore.me?.isAdmin === true)

const units = ref<AdminOrgUnit[]>([])
const users = ref<AdminUser[]>([])
const loading = ref(false)
const error = ref<string | null>(null)
const formError = ref<string | null>(null)

const rows = computed(() => orgRows(units.value))
const createOpen = ref(false)
const createForm = reactive({ name: '', parentId: null as number | null })
const editOpen = ref(false)
const editingId = ref<number | null>(null)
const editing = computed(() => units.value.find(unit => unit.id === editingId.value) ?? null)
const unitForm = reactive({ name: '', parentId: null as number | null })
const newMember = reactive({ userId: null as number | null, role: 'member' as MemberRole })
/** Bumped after a refused change: the selects are rebuilt and show the stored value again, not the refused one. */
const selectReset = ref(0)

const unitChanged = computed(() => editing.value !== null
  && (unitForm.name.trim() !== editing.value.name || unitForm.parentId !== editing.value.parentId))
const activeUsers = computed(() => users.value.filter(user => user.isActive))
const userOption = (user: AdminUser) => ({ label: `${user.name} · ${user.email}`, value: user.id })
const headOptions = computed(() => {
  const options = [{ label: t('admin.org.noHead'), value: null as number | null }, ...activeUsers.value.map(userOption)]
  // A deactivated head stays the head until replaced: keep it visible instead of an empty select.
  const head = users.value.find(user => user.id === editing.value?.headUserId)
  if (head && !head.isActive) options.push({ label: `${head.name} · ${t('admin.users.inactive')}`, value: head.id })
  return options
})
const candidateOptions = computed(() => {
  const inUnit = new Set(editing.value?.members.map(member => member.userId))
  return activeUsers.value.filter(user => !inUnit.has(user.id)).map(userOption)
})
const roleOptions = computed(() => (['member', 'viewer'] as const).map(role => ({ label: t(`roles.${role}`), value: role })))

const unitOptions = (list: OrgRow[]) => list.map(row => ({ label: row.path, value: row.unit.id }))
const headName = (unit: AdminOrgUnit) => unit.members.find(member => member.userId === unit.headUserId)?.name ?? null
const message = (e: unknown) => (e instanceof ApiError && e.statusCode === 422 && e.message ? e.message : t('errors.unknown'))

async function load() {
  loading.value = true
  try {
    const api = useApi()
    const [unitList, userList] = await Promise.all([fetchAdminOrgUnits(api), fetchAdminUsers(api)])
    units.value = unitList
    users.value = userList
  }
  catch (e) {
    error.value = e instanceof Error ? e.message : String(e)
  }
  finally {
    loading.value = false
  }
}

/** Puts the changed unit in place; the rest of the app (unit pickers, own access) reloads its view. */
async function applied(unit: AdminOrgUnit, reloadTree = false) {
  if (reloadTree) {
    units.value = await fetchAdminOrgUnits(useApi())
  }
  else {
    units.value = units.value.map(item => (item.id === unit.id ? unit : item))
  }
  formError.value = null
  void orgStore.load()
  void authStore.loadMe()
}

function openCreate() {
  Object.assign(createForm, { name: '', parentId: rows.value[0]?.unit.id ?? null })
  formError.value = null
  createOpen.value = true
}

function openEdit(id: number) {
  editingId.value = id
  const unit = editing.value
  Object.assign(unitForm, { name: unit?.name ?? '', parentId: unit?.parentId ?? null })
  Object.assign(newMember, { userId: null, role: 'member' })
  formError.value = null
  editOpen.value = true
}

const { pending: creating, run: create } = useAsyncAction(
  async () => {
    const unit = await createAdminOrgUnit(useApi(), createForm.name.trim(), createForm.parentId ?? 0)
    createOpen.value = false
    await applied(unit, true)
  },
  {
    canRun: () => {
      formError.value = createForm.name.trim() && createForm.parentId ? null : t('admin.org.fillUnit')
      return formError.value === null
    },
    onError: (e) => { formError.value = message(e) },
    rethrow: false,
  },
)

const { pending: savingUnit, run: saveUnit } = useAsyncAction(
  async () => {
    if (!editing.value) return
    const unit = await updateAdminOrgUnit(useApi(), editing.value.id, unitForm.name.trim(), editing.value.parentId === null ? null : unitForm.parentId)
    await applied(unit, true)
  },
  { onError: (e) => { formError.value = message(e) }, rethrow: false },
)

const { pending: changing, run: change } = useAsyncAction(
  async (request: () => Promise<AdminOrgUnit>) => {
    await applied(await request())
  },
  {
    onError: (e) => {
      formError.value = message(e)
      selectReset.value++
    },
    rethrow: false,
  },
)

const busy = computed(() => savingUnit.value || changing.value)

function changeHead(userId: number | null) {
  const id = editing.value?.id
  if (id !== undefined) void change(() => setAdminOrgUnitHead(useApi(), id, userId))
}

function changeRole(userId: number, role: MemberRole) {
  const id = editing.value?.id
  if (id !== undefined) void change(() => setAdminOrgUnitMember(useApi(), id, userId, role))
}

function addMember() {
  const id = editing.value?.id
  const userId = newMember.userId
  if (id === undefined || userId === null) return
  void change(() => setAdminOrgUnitMember(useApi(), id, userId, newMember.role)).then(() => {
    if (!formError.value) newMember.userId = null
  })
}

function removeMember(userId: number, name: string) {
  const unit = editing.value
  if (unit && window.confirm(t('admin.org.removeConfirm', { name, unit: unit.name }))) {
    void change(() => removeAdminOrgUnitMember(useApi(), unit.id, userId))
  }
}

onMounted(() => {
  if (isAdmin.value) void load()
})
</script>
