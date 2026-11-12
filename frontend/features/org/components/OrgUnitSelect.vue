<template>
  <Select
    :model-value="modelValue"
    :options="options"
    option-label="label"
    option-value="id"
    :placeholder="t('org.selectUnit')"
    :loading="loading"
    class="rnp-pill-select min-w-56"
    @update:model-value="emit('update:modelValue', $event)"
  >
    <template #option="{ option }">
      <span :style="{ paddingLeft: `${option.depth * 1}rem` }">{{ option.name }}</span>
    </template>
  </Select>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import Select from 'primevue/select'
import type { OrgUnitNode } from '../types'

const props = defineProps<{
  modelValue: number | null
  tree: OrgUnitNode[]
  loading?: boolean
}>()

const emit = defineEmits<{ 'update:modelValue': [id: number] }>()
const { t } = useI18n()

const options = computed(() => {
  const result: { id: number, name: string, label: string, depth: number }[] = []
  const walk = (nodes: OrgUnitNode[], depth: number, path: string[]) => {
    for (const node of nodes) {
      const trail = [...path, node.name]
      result.push({ id: node.id, name: node.name, label: trail.join(' / '), depth })
      walk(node.children, depth + 1, trail)
    }
  }
  walk(props.tree, 0, [])
  return result
})
</script>
