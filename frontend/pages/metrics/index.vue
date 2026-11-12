<template>
  <section class="flex flex-col gap-6">
    <UiPageHeader :subtitle="t('metrics.subtitle')" />
    <UiCard class="rnp-rise">
      <p class="text-sm text-rnp-muted">
        {{ t('metrics.chooseFromRnp') }}
      </p>
      <NuxtLink
        to="/rnp-week"
        class="mt-4 inline-flex h-9 items-center gap-2 rounded-full bg-rnp-accent px-4 text-sm font-semibold text-rnp-text transition-colors hover:bg-rnp-accent-hover"
      >
        {{ t('nav.rnpWeek') }}
        <i
          class="pi pi-arrow-right text-xs"
          aria-hidden="true"
        />
      </NuxtLink>
    </UiCard>
  </section>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import UiCard from '~/features/ui/components/UiCard.vue'
import UiPageHeader from '~/features/ui/components/UiPageHeader.vue'

const { t } = useI18n()
</script>
