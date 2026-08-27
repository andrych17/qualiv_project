<!-- ponytail: PageHeader — H1 uses display serif (DESIGN.md) -->
<script setup lang="ts">
defineProps<{
  title: string
  description?: string
  subtitle?: string
}>()
</script>

<template>
  <div class="flex flex-col gap-1 border-b border-border pb-5 sm:flex-row sm:items-center sm:justify-between">
    <div>
      <h1 class="font-serif text-[1.75rem] font-semibold tracking-tight text-ink-900">{{ title }}</h1>
      <p v-if="description || subtitle" class="mt-1 text-sm text-ink-600">{{ description || subtitle }}</p>
    </div>
    <div class="mt-4 flex items-center gap-3 sm:mt-0">
      <slot name="actions" />
    </div>
  </div>
</template>
