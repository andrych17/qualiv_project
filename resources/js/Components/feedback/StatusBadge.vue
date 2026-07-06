<!-- ponytail: StatusBadge mapping status string to colors -->
<script setup lang="ts">
import { computed } from 'vue'

const props = defineProps<{
  status: string
  label?: string
}>()

const normalizedStatus = computed(() => props.status?.toLowerCase() ?? 'unknown')

const displayLabel = computed(() => {
  if (props.label) return props.label
  return normalizedStatus.value
    .replace(/_/g, ' ')
    .replace(/\b\w/g, char => char.toUpperCase())
})

const badgeClass = computed(() => {
  const map: Record<string, string> = {
    active: 'bg-green-100 text-green-700 border-green-200',
    approved: 'bg-green-100 text-green-700 border-green-200',
    inactive: 'bg-gray-100 text-gray-700 border-gray-200',
    archived: 'bg-red-100 text-red-700 border-red-200',
    rejected: 'bg-red-100 text-red-700 border-red-200',
    pending: 'bg-yellow-100 text-yellow-700 border-yellow-200',
  }

  return map[normalizedStatus.value] ?? 'bg-gray-100 text-gray-700 border-gray-200'
})
</script>

<template>
  <span
    class="inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-medium"
    :class="badgeClass"
  >
    {{ displayLabel }}
  </span>
</template>
