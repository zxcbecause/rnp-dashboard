<template>
  <Dialog
    :visible="visible"
    modal
    :header="exclusion ? t('calls.decideTitle') : t('calls.requestTitle')"
    class="w-full max-w-lg"
    @update:visible="emit('update:visible', $event)"
    @show="reset"
  >
    <form
      class="flex flex-col gap-4"
      @submit.prevent
    >
      <p class="text-sm text-rnp-text">
        {{ t('calls.callAt', { at: formatCallTime(exclusion?.callStartedAt ?? call?.startedAt ?? '') }) }}
        <span
          v-if="responsible !== null"
          class="text-rnp-muted"
        > · {{ t('calls.responsible', { id: responsible }) }}</span>
      </p>

      <template v-if="exclusion">
        <div class="rounded-2xl bg-rnp-bg px-4 py-3 text-sm">
          <p class="text-xs text-rnp-muted">
            {{ t('calls.reasonBy', { name: exclusion.requestedBy?.name ?? '—' }) }} · {{ t(`calls.category.${exclusion.category}`) }}
          </p>
          <p class="mt-1 whitespace-pre-line text-rnp-text">
            {{ exclusion.reason }}
          </p>
        </div>
        <CallExclusionImage
          v-if="exclusion.hasImage"
          :key="exclusion.id"
          :exclusion-id="exclusion.id"
        />
        <Field :label="t('calls.decisionNote')">
          <Textarea
            v-model="text"
            rows="2"
            auto-resize
            fluid
          />
        </Field>
      </template>
      <template v-else>
        <p class="text-sm text-rnp-muted">
          {{ canDecide ? t('calls.requestHintHead') : t('calls.requestHint') }}
        </p>
        <Field :label="t('calls.categoryLabel')">
          <div class="flex flex-wrap gap-2">
            <UiPill
              v-for="item in CALL_EXCLUSION_CATEGORIES"
              :key="item"
              :label="t(`calls.category.${item}`)"
              :active="category === item"
              @click="category = item"
            />
          </div>
        </Field>
        <Field :label="t('calls.reason')">
          <Textarea
            v-model="text"
            rows="3"
            auto-resize
            fluid
            :placeholder="t('calls.reasonPlaceholder')"
          />
        </Field>
        <Field :label="t('calls.image')">
          <div class="flex flex-wrap items-center gap-2">
            <input
              ref="fileInput"
              type="file"
              accept="image/png,image/jpeg,image/webp"
              class="hidden"
              @change="onFile"
            >
            <UiPill
              icon="pi pi-paperclip"
              :label="image ? image.name : t('calls.imageChoose')"
              @click="fileInput?.click()"
            />
            <UiPill
              v-if="image"
              icon="pi pi-times"
              :label="t('calls.imageRemove')"
              @click="clearImage"
            />
          </div>
          <p class="mt-1 text-xs text-rnp-muted">
            {{ t('calls.imageHint') }}
          </p>
        </Field>
      </template>

      <Message
        v-if="error"
        severity="error"
        size="small"
      >
        {{ error }}
      </Message>

      <div class="flex justify-end gap-2">
        <Button
          type="button"
          :label="t('common.cancel')"
          text
          severity="secondary"
          :disabled="pending"
          @click="emit('update:visible', false)"
        />
        <template v-if="exclusion">
          <Button
            type="button"
            :label="t('calls.reject')"
            severity="danger"
            outlined
            :loading="pending && choice === false"
            :disabled="pending"
            @click="submit(false)"
          />
          <Button
            type="button"
            :label="t('calls.approve')"
            :loading="pending && choice === true"
            :disabled="pending"
            @click="submit(true)"
          />
        </template>
        <Button
          v-else
          type="button"
          :label="canDecide ? t('calls.excludeNow') : t('calls.send')"
          :loading="pending"
          :disabled="pending || text.trim().length < MIN_REASON"
          @click="submit(true)"
        />
      </div>
    </form>
  </Dialog>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import Dialog from 'primevue/dialog'
import Message from 'primevue/message'
import Textarea from 'primevue/textarea'
import { useApi } from '~/composables/useApi'
import { useAsyncAction } from '~/composables/useAsyncAction'
import Field from '~/features/actions/components/FormField.vue'
import UiPill from '~/features/ui/components/UiPill.vue'
import { decideCallExclusion, requestCallExclusion } from '../api/calls'
import CallExclusionImage from './CallExclusionImage.vue'
import { CALL_EXCLUSION_CATEGORIES } from '../types'
import type { CallExclusion, CallExclusionCategory, MissedCall } from '../types'
import { formatCallTime } from '../utils/format'

/** Same minimum as the backend (CallExclusionRequest). */
const MIN_REASON = 10
/** Same limits as the backend (CallExclusionImage). */
const MAX_IMAGE_BYTES = 4 * 1024 * 1024
const IMAGE_TYPES = ['image/png', 'image/jpeg', 'image/webp']

const props = defineProps<{
  visible: boolean
  orgUnitId: number
  /** request mode: the call to exclude */
  call: MissedCall | null
  /** the user may decide: their own request is applied at once */
  canDecide: boolean
  /** decide mode: the pending request */
  exclusion: CallExclusion | null
}>()
const emit = defineEmits<{ 'update:visible': [boolean], 'saved': [recalculated: boolean] }>()
const { t } = useI18n()

const text = ref('')
const category = ref<CallExclusionCategory>('technical')
/** screenshot picked for the request: file name and base64 content */
const image = ref<{ name: string, data: string } | null>(null)
const fileInput = ref<HTMLInputElement | null>(null)
const error = ref<string | null>(null)
const choice = ref<boolean | null>(null)
const responsible = computed(() => props.exclusion?.responsibleId ?? props.call?.responsibleId ?? null)

function reset() {
  text.value = ''
  category.value = 'technical'
  clearImage()
  error.value = null
  choice.value = null
}

function clearImage() {
  image.value = null
  if (fileInput.value) fileInput.value.value = ''
}

function onFile(event: Event) {
  const file = (event.target as HTMLInputElement).files?.[0]
  error.value = null
  if (!file) return
  if (!IMAGE_TYPES.includes(file.type)) {
    clearImage()
    error.value = t('calls.imageWrongType')
    return
  }
  if (file.size > MAX_IMAGE_BYTES) {
    clearImage()
    error.value = t('calls.imageTooBig')
    return
  }
  const reader = new FileReader()
  reader.onload = () => {
    // "data:image/png;base64,...." → the base64 part only.
    const url = String(reader.result)
    image.value = { name: file.name, data: url.slice(url.indexOf(',') + 1) }
  }
  reader.onerror = () => {
    clearImage()
    error.value = t('calls.imageUnreadable')
  }
  reader.readAsDataURL(file)
}

const { pending, run } = useAsyncAction(async (approve: boolean) => {
  error.value = null
  choice.value = approve
  let recalculated = true
  if (props.exclusion) {
    const note = text.value.trim()
    recalculated = (await decideCallExclusion(useApi(), props.exclusion.id, approve, note || null)).recalculated
  }
  else if (props.call) {
    // null: the request waits for the head, nothing to recalculate yet.
    recalculated = (await requestCallExclusion(useApi(), props.orgUnitId, props.call.callId, text.value.trim(), category.value, image.value?.data ?? null)).recalculated ?? true
  }
  emit('saved', recalculated)
  emit('update:visible', false)
}, { rethrow: false, onError: (e) => { error.value = e instanceof Error ? e.message : String(e) } })

const submit = (approve: boolean) => void run(approve)
</script>
