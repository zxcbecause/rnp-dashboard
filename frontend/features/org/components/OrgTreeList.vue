<template>
  <ul class="flex flex-col gap-1">
    <li
      v-for="node in nodes"
      :key="node.id"
    >
      <div
        class="flex items-center justify-between rounded-lg px-3 py-2 hover:bg-gray-50"
        :style="{ paddingLeft: `${0.75 + depth * 1.25}rem` }"
      >
        <span class="flex items-center gap-2 text-sm font-medium text-gray-900">
          <i
            :class="depth === 0 ? 'pi pi-building' : 'pi pi-angle-right'"
            class="text-gray-400"
            aria-hidden="true"
          />
          {{ node.name }}
        </span>
        <span class="flex items-center gap-3">
          <span
            v-if="node.headUser"
            class="text-xs text-gray-500"
          >{{ node.headUser.name }}</span>
          <NuxtLink
            :to="{ path: '/rnp-week', query: { unit: String(node.id) } }"
            class="text-xs font-medium text-primary-700 hover:underline"
          >РНП →</NuxtLink>
        </span>
      </div>
      <OrgTreeList
        v-if="node.children.length"
        :nodes="node.children"
        :depth="depth + 1"
      />
    </li>
  </ul>
</template>

<script setup lang="ts">
import type { OrgUnitNode } from '../types'

withDefaults(defineProps<{
  nodes: OrgUnitNode[]
  depth?: number
}>(), { depth: 0 })
</script>
