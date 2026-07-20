<!-- ponytail: Panel / card composite — DESIGN.md surface + border -->
<script setup lang="ts">
defineProps<{
  title?: string
  subtitle?: string
}>()
</script>

<template>
  <div class="rounded-md border border-border bg-surface-0 p-6 shadow-sm">
    <div
      v-if="title || $slots.actions"
      class="mb-4 flex items-center justify-between gap-3 border-b border-border pb-4"
    >
      <div>
        <h2 v-if="title" class="font-serif text-lg font-semibold text-ink-900">{{ title }}</h2>
        <p v-if="subtitle" class="mt-0.5 text-xs text-ink-600">{{ subtitle }}</p>
      </div>
      <div v-if="$slots.actions" class="flex items-center gap-2">
        <slot name="actions" />
      </div>
    </div>
    <slot />
  </div>
</template>
