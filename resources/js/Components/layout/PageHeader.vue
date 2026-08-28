<!-- ponytail: PageHeader — H1 uses display serif (DESIGN.md) -->
<script setup lang="ts">
defineProps<{
  title: string
  description?: string
  subtitle?: string
}>()
</script>

<template>
  <div class="flex flex-col gap-2 border-b border-border pb-4 sm:flex-row sm:items-center sm:justify-between">
    <div>
      <h1 class="font-serif text-2xl sm:text-[1.75rem] font-semibold tracking-tight text-ink-900">{{ title }}</h1>
      <p v-if="description || subtitle" class="mt-1 text-xs sm:text-sm text-ink-600">{{ description || subtitle }}</p>
    </div>
    <div class="mt-2 flex flex-wrap items-center gap-2 sm:mt-0 sm:gap-3">
      <slot name="actions" />
    </div>
  </div>
</template>
