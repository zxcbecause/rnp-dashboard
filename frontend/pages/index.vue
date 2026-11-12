<template>
  <div />
</template>

<script setup lang="ts">
import { navigateTo } from '#app'
import { HOME_ROUTE } from '~/features/layout/config/routeAccess'

await navigateTo(HOME_ROUTE, { replace: true })
</script>
