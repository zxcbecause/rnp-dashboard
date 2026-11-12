import { defineStore } from 'pinia'
import { useApi } from '~/composables/useApi'
import { askAssistant, fetchAiStatus } from '../api/ai'
import type { AssistantMessage } from '../types'

/**
 * Conversation with the AI assistant. Lives in memory for the browser tab (cleared on sign-out/reload):
 * the server keeps nothing, every question carries the visible conversation.
 */
export const useAssistantStore = defineStore('assistant', {
  state: () => ({
    open: false,
    messages: [] as AssistantMessage[],
    pending: false,
    error: null as string | null,
    /** null until checked; false = no API key on the server */
    available: null as boolean | null,
  }),

  actions: {
    async toggle(open?: boolean): Promise<void> {
      this.open = open ?? !this.open
      if (this.open && this.available === null) {
        try {
          this.available = (await fetchAiStatus(useApi())).available
        }
        catch {
          this.available = true // let the question itself report what is wrong
        }
      }
    },

    /** Single flight: a second question waits for the first answer. */
    async ask(question: string, page: string): Promise<void> {
      const text = question.trim()
      if (!text || this.pending) return

      this.error = null
      this.messages.push({ role: 'user', content: text })
      this.pending = true
      try {
        // The server uses the last 20 turns; older ones would only grow the request.
        const history = this.messages.slice(-20).map(({ role, content }) => ({ role, content }))
        const reply = await askAssistant(useApi(), history, page)
        this.messages.push({ role: 'assistant', content: reply.answer, used: reply.used })
      }
      catch (e) {
        // The question stays visible; drop it from the history so a retry does not send it twice.
        this.messages.pop()
        this.error = e instanceof Error ? e.message : String(e)
        throw e
      }
      finally {
        this.pending = false
      }
    },

    clear(): void {
      this.messages = []
      this.error = null
    },
  },
})
