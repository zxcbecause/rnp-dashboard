<template>
  <section class="flex flex-col gap-6">
    <UiPageHeader :subtitle="t('help.subtitle')" />

    <nav
      class="flex flex-wrap gap-2"
      :aria-label="t('help.contents')"
    >
      <a
        v-for="section in sections"
        :key="section.id"
        :href="`#${section.id}`"
        class="inline-flex h-9 items-center gap-2 rounded-full bg-rnp-pill px-4 text-sm font-medium text-rnp-text transition-colors hover:bg-rnp-accent-soft"
      >
        <i
          :class="section.icon"
          class="text-xs text-rnp-muted"
          aria-hidden="true"
        />
        {{ section.title }}
      </a>
    </nav>

    <div class="grid gap-6 xl:grid-cols-2">
      <UiCard
        v-for="(section, index) in sections"
        :id="section.id"
        :key="section.id"
        class="rnp-rise scroll-mt-24"
        :style="{ '--i': index }"
      >
        <h2 class="mb-1 flex items-center gap-2 text-lg font-semibold text-rnp-text">
          <span class="flex h-8 w-8 items-center justify-center rounded-xl bg-rnp-accent-soft">
            <i
              :class="section.icon"
              class="text-sm text-rnp-text"
              aria-hidden="true"
            />
          </span>
          {{ section.title }}
        </h2>
        <p
          v-if="section.intro"
          class="mb-2 text-sm text-rnp-muted"
        >
          {{ section.intro }}
        </p>
        <dl class="mt-3 flex flex-col gap-3">
          <div
            v-for="(item, i) in section.items"
            :key="i"
            class="text-sm leading-relaxed text-rnp-text/85"
          >
            <dt
              v-if="item.term"
              class="inline font-semibold text-rnp-text"
            >
              {{ item.term }}.
            </dt>
            <dd class="inline">
              {{ item.text }}
            </dd>
          </div>
        </dl>
      </UiCard>
    </div>
  </section>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { helpSections } from '~/features/help/content'
import UiCard from '~/features/ui/components/UiCard.vue'
import UiPageHeader from '~/features/ui/components/UiPageHeader.vue'

const { t, locale } = useI18n()
const sections = computed(() => helpSections(String(locale.value)))
</script>
