<template>
  <div>
    <!-- Launcher -->
    <Transition name="rnp-fade">
      <button
        v-if="!store.open"
        type="button"
        class="ai-launcher fixed bottom-6 right-6 z-40 inline-flex h-12 items-center gap-2 rounded-full bg-rnp-sidebar pl-2 pr-5 text-sm font-semibold text-white shadow-[0_12px_32px_rgb(0_0_0/0.25)] transition-transform hover:-translate-y-0.5"
        :aria-label="t('ai.assistant.open')"
        @click="store.toggle(true)"
      >
        <span class="flex h-8 w-8 items-center justify-center rounded-full bg-rnp-accent text-rnp-text">
          <i
            class="pi pi-sparkles text-sm"
            aria-hidden="true"
          />
        </span>
        {{ t('ai.assistant.open') }}
      </button>
    </Transition>

    <!-- Panel -->
    <Transition name="ai-panel">
      <aside
        v-if="store.open"
        class="fixed inset-y-3 right-3 z-50 flex w-[min(440px,calc(100vw-1.5rem))] flex-col overflow-hidden rounded-[24px] bg-rnp-card shadow-[0_24px_64px_rgb(0_0_0/0.22)]"
        role="dialog"
        :aria-label="t('ai.assistant.title')"
        @keydown.esc="store.toggle(false)"
      >
        <header class="flex items-center gap-3 bg-rnp-sidebar px-5 py-4 text-white">
          <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-rnp-accent text-rnp-text">
            <i
              class="pi pi-sparkles"
              aria-hidden="true"
            />
          </span>
          <div class="min-w-0 flex-1">
            <div class="font-semibold">
              {{ t('ai.assistant.title') }}
            </div>
            <div class="truncate text-xs text-white/55">
              {{ t('ai.assistant.subtitle') }}
            </div>
          </div>
          <button
            v-if="store.messages.length"
            type="button"
            class="flex h-8 w-8 items-center justify-center rounded-full text-white/60 transition-colors hover:bg-white/10 hover:text-white"
            :title="t('ai.assistant.clear')"
            :aria-label="t('ai.assistant.clear')"
            :disabled="store.pending"
            @click="store.clear()"
          >
            <i
              class="pi pi-trash text-xs"
              aria-hidden="true"
            />
          </button>
          <button
            type="button"
            class="flex h-8 w-8 items-center justify-center rounded-full text-white/60 transition-colors hover:bg-white/10 hover:text-white"
            :aria-label="t('ai.assistant.close')"
            @click="store.toggle(false)"
          >
            <i
              class="pi pi-times text-xs"
              aria-hidden="true"
            />
          </button>
        </header>

        <div
          ref="scroller"
          class="flex-1 overflow-y-auto px-5 py-4"
        >
          <p
            v-if="store.available === false"
            class="rounded-2xl bg-rnp-bg p-4 text-sm text-rnp-muted"
          >
            {{ t('ai.notConfigured') }}
          </p>

          <!-- Empty state: what can be asked -->
          <div
            v-else-if="!store.messages.length"
            class="flex flex-col gap-3"
          >
            <p class="text-sm text-rnp-muted">
              {{ t('ai.assistant.intro') }}
            </p>
            <button
              v-for="example in examples"
              :key="example"
              type="button"
              class="rounded-2xl bg-rnp-pill px-4 py-3 text-left text-sm text-rnp-text transition-colors hover:bg-rnp-accent-soft"
              @click="send(example)"
            >
              {{ example }}
            </button>
          </div>

          <div
            v-else
            class="flex flex-col gap-4"
          >
            <div
              v-for="(message, index) in store.messages"
              :key="index"
              :class="message.role === 'user' ? 'self-end' : 'self-start'"
              class="max-w-[90%]"
            >
              <div
                v-if="message.role === 'user'"
                class="whitespace-pre-wrap rounded-2xl rounded-br-md bg-rnp-sidebar px-4 py-2.5 text-sm text-white"
              >
                {{ message.content }}
              </div>
              <template v-else>
                <!-- eslint-disable vue/no-v-html -- renderMarkdown escapes the model output before adding its own tags -->
                <div
                  class="ai-markdown rounded-2xl rounded-bl-md bg-rnp-pill px-4 py-3 text-sm leading-relaxed text-rnp-text"
                  v-html="renderMarkdown(message.content)"
                />
                <!-- eslint-enable vue/no-v-html -->
                <div
                  v-if="message.used?.length"
                  class="mt-1.5 flex flex-wrap items-center gap-1 text-[11px] text-rnp-muted"
                >
                  <i
                    class="pi pi-search text-[9px]"
                    aria-hidden="true"
                  />
                  {{ t('ai.assistant.used') }}
                  <span
                    v-for="item in message.used"
                    :key="item"
                    class="rounded-full bg-rnp-bg px-2 py-px"
                  >{{ item }}</span>
                </div>
              </template>
            </div>

            <div
              v-if="store.pending"
              class="flex items-center gap-2 self-start rounded-2xl bg-rnp-pill px-4 py-3 text-sm text-rnp-muted"
            >
              <span
                class="ai-dots"
                aria-hidden="true"
              ><i /><i /><i /></span>
              {{ t('ai.assistant.thinking') }}
            </div>
          </div>

          <p
            v-if="store.error"
            class="mt-3 text-sm text-rnp-red"
          >
            {{ store.error }}
          </p>
        </div>

        <form
          class="border-t border-rnp-divider p-3"
          @submit.prevent="send(draft)"
        >
          <div class="flex items-end gap-2 rounded-2xl bg-rnp-pill p-2 focus-within:ring-2 focus-within:ring-rnp-accent">
            <textarea
              ref="input"
              v-model="draft"
              rows="1"
              class="max-h-40 min-h-9 flex-1 resize-none bg-transparent px-2 py-2 text-sm text-rnp-text outline-none placeholder:text-rnp-muted"
              :placeholder="t('ai.assistant.placeholder')"
              :disabled="store.available === false"
              @keydown.enter.exact.prevent="send(draft)"
              @input="grow"
            />
            <button
              type="submit"
              class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-rnp-accent text-rnp-text transition-colors hover:bg-rnp-accent-hover disabled:opacity-40"
              :aria-label="t('ai.assistant.send')"
              :disabled="store.pending || !draft.trim() || store.available === false"
            >
              <i
                :class="store.pending ? 'pi pi-spin pi-spinner' : 'pi pi-arrow-up'"
                class="text-sm"
                aria-hidden="true"
              />
            </button>
          </div>
          <p class="mt-1.5 px-2 text-[11px] text-rnp-muted">
            {{ t('ai.assistant.hint') }}
          </p>
        </form>
      </aside>
    </Transition>
  </div>
</template>

<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from '#app'
import { useAuthStore } from '~/features/auth/stores/authStore'
import { useAssistantStore } from '../stores/assistantStore'
import { renderMarkdown } from '../utils/markdown'

/** Floating "Ask AI" button and the chat panel of the assistant (numbers + how the dashboard works). */
const { t, tm, rt } = useI18n()
const store = useAssistantStore()
const route = useRoute()
const draft = ref('')
const scroller = ref<HTMLElement | null>(null)
const input = ref<HTMLTextAreaElement | null>(null)

const examples = computed(() => (tm('ai.assistant.examples') as unknown[]).map(item => rt(item as Parameters<typeof rt>[0])))

async function send(text: string) {
  if (!text.trim() || store.pending) return
  draft.value = ''
  grow()
  try {
    await store.ask(text, route.fullPath)
  }
  catch {
    draft.value = text // keep the question for a retry
  }
}

function grow() {
  void nextTick(() => {
    const el = input.value
    if (!el) return
    el.style.height = 'auto'
    el.style.height = `${Math.min(el.scrollHeight, 160)}px`
  })
}

// Keep the latest message in view.
watch(() => [store.messages.length, store.pending], () => {
  void nextTick(() => scroller.value?.scrollTo({ top: scroller.value.scrollHeight, behavior: 'smooth' }))
})

watch(() => store.open, (open) => {
  if (open) void nextTick(() => input.value?.focus())
})

// Answers may contain data of the signed-in user: another account in the same tab starts clean.
const authStore = useAuthStore()
watch(() => authStore.me?.id, (id, previous) => {
  if (previous !== undefined && id !== previous) {
    store.clear()
    store.toggle(false)
  }
})
</script>

<style scoped>
.ai-panel-enter-active,
.ai-panel-leave-active {
  transition: opacity 220ms var(--rnp-ease-out), transform 260ms var(--rnp-ease-out);
}

.ai-panel-enter-from,
.ai-panel-leave-to {
  opacity: 0;
  transform: translateX(24px) scale(0.98);
}

/* Three dots while the assistant reads the data. */
.ai-dots {
  display: inline-flex;
  gap: 3px;
}

.ai-dots i {
  width: 5px;
  height: 5px;
  border-radius: 9999px;
  background: var(--rnp-accent-hover);
  animation: ai-dot 1s ease-in-out infinite;
}

.ai-dots i:nth-child(2) {
  animation-delay: 0.15s;
}

.ai-dots i:nth-child(3) {
  animation-delay: 0.3s;
}

@keyframes ai-dot {
  0%, 80%, 100% { opacity: 0.3; transform: translateY(0); }
  40% { opacity: 1; transform: translateY(-3px); }
}

.ai-markdown :deep(ul),
.ai-markdown :deep(ol) {
  display: flex;
  flex-direction: column;
  gap: 0.3rem;
  padding-left: 1.1rem;
  margin: 0 0 0.5rem;
}

.ai-markdown :deep(ul) {
  list-style: disc;
}

.ai-markdown :deep(ol) {
  list-style: decimal;
}

.ai-markdown :deep(li::marker) {
  color: var(--rnp-accent-hover);
}

.ai-markdown :deep(p) {
  margin: 0 0 0.5rem;
}

.ai-markdown :deep(p:last-child),
.ai-markdown :deep(ul:last-child),
.ai-markdown :deep(ol:last-child) {
  margin-bottom: 0;
}

.ai-markdown :deep(strong) {
  font-weight: 700;
}

.ai-markdown :deep(code) {
  border-radius: 6px;
  background: var(--rnp-bg);
  padding: 0 0.3rem;
}
</style>
