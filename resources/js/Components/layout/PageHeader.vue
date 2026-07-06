<!-- ponytail: Simple PageHeader component with slots for action items -->
<script setup lang="ts">
defineProps<{
  title: string
  description?: string
}>()
</script>

<template>
  <div class="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between border-b border-gray-200 pb-5">
    <div>
      <h1 class="text-2xl font-bold tracking-tight text-gray-900">{{ title }}</h1>
      <p v-if="description" class="text-sm text-gray-500 mt-1">{{ description }}</p>
    </div>
    <div class="flex items-center gap-3 mt-4 sm:mt-0">
      <slot name="actions" />
    </div>
  </div>
</template>
